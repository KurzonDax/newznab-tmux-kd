<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use App\Models\Category;
use App\Models\Release;
use App\Models\Settings;
use App\Services\Categorization\CategorizationService;
use App\Services\Releases\CollectionQuietPredicate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use UnexpectedValueException;

final class PendingReconciler
{
    /** @param null|\Closure(): float $clock */
    public function __construct(private readonly PendingInventory $inventory, private readonly PostingEvidence $evidence, private readonly ?\Closure $clock = null) {}

    /** @return array{processed: int, stopped_early: bool} */
    public function run(?int $groupId, int $quietHours): array
    {
        $limit = max(1, (int) config('collection-reconciliation.candidate_limit', 100));
        $budget = new ReconciliationRunBudget($limit,
            max(0.001, (float) config('collection-reconciliation.cycle_seconds', 30)), $this->clock);
        if (! Schema::hasTable('reconciliation_claims')) {
            return $budget->report();
        }
        $phaseKey = 'collection-reconciliation:resume-first:'.($groupId ?? 'all');
        $resumeFirst = (bool) Cache::get($phaseKey, true);
        Cache::forever($phaseKey, ! $resumeFirst);
        if ($resumeFirst) {
            app(LatePostingReconciler::class)->resume($groupId, $budget);
        }
        $this->runCandidates($groupId, $quietHours, $limit, $budget);
        if (! $resumeFirst) {
            app(LatePostingReconciler::class)->resume($groupId, $budget);
        }
        $report = $budget->report();
        if ($report['stopped_early']) {
            Log::info('Collection reconciliation cycle yielded', $report + ['group_id' => $groupId]);
        }

        return $report;
    }

    private function runCandidates(?int $groupId, int $quietHours, int $limit, ReconciliationRunBudget $budget): void
    {
        $stoppedEarly = false;
        $cursorKey = 'collection-reconciliation:pending-cursor:'.($groupId ?? 'all');
        $lastId = (int) Cache::get($cursorKey, 0);
        $quiet = CollectionQuietPredicate::build($quietHours, DB::getTablePrefix().'collections');
        DB::table('collections')->where('id', '>', $lastId)->whereIn('filecheck', [0, 1, 2, 3, 10, 15, 16])
            ->where('declaredfiles', '>', 1)->whereRaw($quiet['sql'], $quiet['bindings'])
            ->when($groupId !== null, static fn ($query) => $query->where('groups_id', $groupId))
            ->whereRaw('(SELECT COUNT(*) FROM binaries b WHERE b.collections_id = collections.id) < collections.declaredfiles')
            ->orderBy('id')->limit($limit + 1)->chunkById(min(100, $limit + 1), function ($rows) use ($quietHours, $budget, &$lastId, &$stoppedEarly): bool {
                foreach ($rows as $row) {
                    if (! $budget->take()) {
                        $stoppedEarly = true;

                        return false;
                    }
                    $this->reconcile((int) $row->id, $quietHours, microtime(true) + $budget->remainingSeconds());
                    $lastId = (int) $row->id;
                }

                return true;
            });
        Cache::forever($cursorKey, $stoppedEarly ? $lastId : 0);
    }

    public function reconcile(int $collectionId, int $quietHours, ?float $cycleDeadline = null): string
    {
        $reason = $this->attempt($collectionId, $quietHours, $cycleDeadline);
        Log::info('Collection reconciliation decision', ['collection_id' => $collectionId, 'reason' => $reason]);

        return $reason;
    }

    private function attempt(int $collectionId, int $quietHours, ?float $cycleDeadline): string
    {
        $owner = (string) Str::uuid();
        $ids = [];
        try {
            $source = DB::table('collections')->where('id', $collectionId)->first();
            if ($source === null || (int) $source->filecheck >= 4 && (int) $source->filecheck < 10) {
                return 'not_pending';
            }
            $quiet = CollectionQuietPredicate::build($quietHours, DB::getTablePrefix().'collections');
            if (! DB::table('collections')->where('id', $collectionId)->whereRaw($quiet['sql'], $quiet['bindings'])->exists()) {
                return 'not_quiet';
            }
            $own = $this->inventory->load([$collectionId]);
            if ($own === [] || count($own) >= (int) $source->declaredfiles
                || array_any($own, static fn (PostingFile $file): bool => ! $file->hasCompleteSegments())) {
                return 'not_fragment';
            }
            $late = app(LatePostingReconciler::class)->reconcile($collectionId, $quietHours, $cycleDeadline);
            if ($late !== null) {
                return $late;
            }
            $queries = new PopulationQuery;
            $window = $queries->sourceWindow($source);
            if ($window === null) {
                return 'source_population';
            }
            $population = $queries->readWindow($window);
            $nearby = $population['rows'];
            if (! $population['complete'] || $nearby->count() < 2) {
                return 'source_population';
            }
            $populationIds = $nearby->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $ids = app(CollectionAdmission::class)->eligibleIds($populationIds, $quietHours);
            if ($ids === null) {
                return 'file_population';
            }
            if (! in_array($collectionId, $ids, true)) {
                return 'ineligible_source';
            }
            $files = $this->inventory->load($ids);
            if (! array_any($files, static fn (PostingFile $file): bool => $file->isBasePar2())) {
                return 'missing_or_competing_base';
            }
            app(CollectionAdmission::class)->screen($ids, $quietHours);
            if (app(CollectionAdmission::class)->expired([$collectionId])) {
                return 'admission_expired';
            }
            $revision = PendingInventory::digest($files);
            $deadline = app(CollectionClaims::class)->claim($ids, $owner, $revision, $quietHours);
            if ($deadline === null) {
                return 'unavailable_claim';
            }
            $decisions = (new PostingHypotheses)->resolve($files, function (array $hypothesis) use ($cycleDeadline, $deadline): PostingDecision {
                $base = array_values(array_filter($hypothesis, static fn (PostingFile $file): bool => $file->isBasePar2()))[0];

                return $this->evidence->resolve($hypothesis, app(CollectionAdmission::class)->decisionId((int) $base->sourceId, $base->firstArticle()),
                    min($cycleDeadline ?? INF, microtime(true) + max(0, $deadline - now()->timestamp)));
            });
            foreach ($decisions as $candidate) {
                $base = array_values(array_filter($files, static fn (PostingFile $file): bool => $file->isBasePar2()
                    && isset($candidate->evidence[$file->fileId]) && substr($file->filename, 0, -5) === $candidate->label))[0] ?? null;
                if ($base === null) {
                    continue;
                }
                $disproved = [];
                foreach ($files as $file) {
                    if (in_array($candidate->unresolved[$file->fileId] ?? null, ['unlisted_filename', 'contradictory_payload', 'different_poster', 'incompatible_inventory'], true)) {
                        $disproved[] = (int) $file->sourceId;
                    }
                }
                if ($disproved !== []) {
                    DB::transaction(function () use ($ids, $revision, $disproved, $base): void {
                        DB::table('collections')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                        if (PendingInventory::digest($this->inventory->load($ids)) === $revision) {
                            $admission = app(CollectionAdmission::class);
                            $admission->disprove($disproved, $admission->decisionId((int) $base->sourceId, $base->firstArticle()));
                        }
                    }, 3);
                }
            }
            $decision = null;
            foreach ($decisions as $candidate) {
                if (in_array((string) $collectionId, $candidate->sources(), true)) {
                    $decision = $candidate;
                    break;
                }
            }
            if ($decision === null) {
                $reason = array_any($decisions, static fn (PostingDecision $decision): bool => $decision->reason === 'competing_hypothesis')
                    ? 'competing_hypothesis' : ($decisions[0]->reason ?? 'no_verified_union');
                app(CollectionClaims::class)->settle($owner, $reason);

                return $reason;
            }
            $unsupported = array_values(array_diff($populationIds, $ids));
            foreach (DB::table('binaries')->whereIn('collections_id', $unsupported)->limit(1025)->pluck('name') as $subject) {
                if (preg_match('/"([^"\r\n]+)"/', $subject, $name) !== 1 || preg_match('/\.par2$/iD', $name[1]) === 1
                    || in_array($name[1], array_column($decision->accepted, 'filename'), true)
                    || (preg_match('/\[([0-9]+)\/[0-9]+\]/', $subject, $ordinal) === 1
                        && in_array((int) $ordinal[1], array_column($decision->accepted, 'ordinal'), true))) {
                    app(CollectionClaims::class)->settle($owner, 'unsupported_competitor');

                    return 'unsupported_competitor';
                }
            }
            if ($decision->accepted === []) {
                app(CollectionClaims::class)->settle($owner, $decision->reason);

                return $decision->reason;
            }

            if (! app(CollectionAdmission::class)->admitProven($decision, $quietHours)) {
                app(CollectionClaims::class)->settle($owner, 'changed_inventory');

                return 'changed_inventory';
            }
            if (! $decision->complete()) {
                app(CollectionClaims::class)->settle($owner, 'verified_incomplete');

                return 'verified_incomplete';
            }

            return $this->publishAssociation($ids, $owner, $revision, $decision, $window, $populationIds);
        } catch (UnexpectedValueException $e) {
            app(CollectionClaims::class)->settle($owner, 'invalid_evidence');

            return $e->getMessage();
        } catch (RuntimeException $e) {
            app(CollectionClaims::class)->retry($owner, $cycleDeadline !== null && microtime(true) >= $cycleDeadline ? 'cycle_yield' : $e->getMessage());

            return $e->getMessage();
        }
    }

    /**
     * @param  list<int>  $ids
     * @param  list<int>  $observedIds
     * @param  array{group: int, poster: string, total: int, from: string, until: string}  $population
     */
    private function publishAssociation(array $ids, string $owner, string $revision, PostingDecision $decision, array $population, array $observedIds): string
    {
        return DB::transaction(function () use ($ids, $owner, $revision, $decision, $population, $observedIds): string {
            if (Schema::hasTable('reconciled_artifacts')) {
                $lockedPopulation = app(ArtifactPublication::class)->lockSources($observedIds, $population);
                if ($lockedPopulation === null) {
                    app(CollectionClaims::class)->settle($owner, 'source_population_incomplete');

                    return 'source_population_incomplete';
                }
                if (array_diff($lockedPopulation, $observedIds) !== [] || array_diff($observedIds, $lockedPopulation) !== []) {
                    app(CollectionClaims::class)->settle($owner, 'changed_inventory');

                    return 'changed_inventory';
                }
            }
            $collections = DB::table('collections')->whereIn('id', $observedIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $claims = DB::table('reconciliation_claims')->whereIn('collection_id', $ids)->where('owner', $owner)
                ->where('revision', $revision)->where('lease_until', '>', now())->where('deadline', '>', now())->lockForUpdate()->get();
            $acceptedIds = array_map('intval', $decision->sources());
            $available = DB::table('collections')->whereIn('id', $acceptedIds)
                ->tap(static fn ($query) => CollectionOwnership::excludeArtifactSources($query))->lockForUpdate()->count();
            if ($available !== count($acceptedIds) || $claims->count() !== count($ids) || PendingInventory::digest($this->inventory->load($ids)) !== $revision) {
                app(CollectionClaims::class)->settle($owner, 'changed_inventory');

                return 'changed_inventory';
            }
            if (app(CollectionAdmission::class)->expired(array_map('intval', $decision->sources()))) {
                app(CollectionClaims::class)->settle($owner, 'admission_expired');

                return 'admission_expired';
            }
            $source = $collections->get((int) $decision->accepted[0]->sourceId);
            $size = array_sum(array_map(static fn (PostingFile $file): int => array_sum(array_column($file->segments, 'bytes')), $decision->accepted));
            $group = DB::table('usenet_groups')->where('id', $source->groups_id)->first();
            $minSize = max((int) Settings::settingValue('minsizetoformrelease'), (int) ($group->minsizetoformrelease ?? 0));
            $minFiles = max((int) Settings::settingValue('minfilestoformrelease'), (int) ($group->minfilestoformrelease ?? 0));
            $maxSize = (int) Settings::settingValue('maxsizetoformrelease');
            if ($size < $minSize || count($decision->accepted) < $minFiles || ($maxSize > 0 && $size > $maxSize)) {
                app(CollectionClaims::class)->settle($owner, 'formation_floor');

                return 'formation_floor';
            }
            $category = $decision->independentVideos() ? Category::OTHER_MISC
                : (new CategorizationService)->determineCategory((int) $source->groups_id, $decision->label, $source->fromname)['categories_id'];
            $releaseId = (int) Release::insertRelease(['name' => $decision->label, ...Release::searchNameValues($decision->label),
                'totalpart' => count($decision->accepted), 'declaredfiles' => $decision->declaredTotal,
                'groups_id' => $source->groups_id, 'guid' => (string) Str::uuid(), 'postdate' => $source->date,
                'fromname' => $source->fromname, 'size' => $size, 'categories_id' => $category,
                'isrenamed' => 0, 'is_trusted_name' => false, 'predb_id' => 0, 'nzbstatus' => 0,
                'completion' => $decision->completion()]);
            $postingId = DB::table('reconciled_postings')->insertGetId(['release_id' => $releaseId,
                'digest' => PendingInventory::digest($decision->accepted), 'state' => 'created',
                'budget_id' => app(CollectionAdmission::class)->decisionId((int) array_values(array_filter($decision->accepted, static fn (PostingFile $file): bool => $file->isBasePar2()))[0]->sourceId, array_values(array_filter($decision->accepted, static fn (PostingFile $file): bool => $file->isBasePar2()))[0]->firstArticle()),
                'source_digest' => PendingInventory::digest($this->inventory->load(array_map('intval', $decision->sources()))),
                'independent_videos' => $decision->independentVideos(), 'inventory' => PendingInventory::encode($decision->accepted),
                'decision' => json_encode($decision, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            foreach ($decision->sources() as $id) {
                $collection = $collections->get((int) $id);
                DB::table('reconciled_sources')->insert(['posting_id' => $postingId, 'collection_hash' => bin2hex($collection->collectionhash),
                    'group_id' => $collection->groups_id, 'postdate' => $collection->date, 'source_id' => $id]);
                DB::table('collections')->where('id', $id)->update(['releases_id' => $releaseId, 'filecheck' => 4]);
                DB::table('reconciliation_claims')->where('collection_id', $id)->update(['release_id' => $releaseId]);
            }
            $groups = DB::table('usenet_groups')->whereIn('name', array_values(array_unique(array_merge(...array_map(static fn (PostingFile $file): array => $file->groups, $decision->accepted)))))->pluck('id')->all();
            foreach ($groups as $groupId) {
                DB::table('releases_groups')->insertOrIgnore(['releases_id' => $releaseId, 'groups_id' => $groupId]);
            }
            if (Schema::hasTable('reconciled_artifacts')) {
                app(ArtifactPublication::class)->writePosting(Release::query()->findOrFail($releaseId), (new PostingNzb)->render($decision->accepted), population: $population, observedSourceIds: $observedIds);
                if (! DB::table('reconciled_artifacts')->where('release_id', $releaseId)->whereNotNull('pending_operation')->exists()) {
                    throw new RuntimeException('initial_receipt_not_prepared');
                }
            }
            app(CollectionClaims::class)->settle($owner, 'associated');
            Log::info('Collection reconciliation associated', ['release_id' => $releaseId, 'sources' => $decision->sources(), 'completion' => $decision->completion()]);

            return 'associated';
        }, 3);
    }
}
