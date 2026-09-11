<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use App\Models\Release;
use App\Services\CollectionCleanupService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseRepair\RecoveryLease;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/** Replays use immutable article identities; a source hash alone never redirects ingestion. */
final class LatePostingReconciler
{
    public function __construct(private readonly PostingEvidence $evidence) {}

    public function resume(?int $groupId, ?ReconciliationRunBudget $budget = null): void
    {
        $budget ??= new ReconciliationRunBudget((int) config('collection-reconciliation.candidate_limit', 100), (float) config('collection-reconciliation.cycle_seconds', 30));
        $phaseKey = 'artifact-resume-first:'.($groupId ?? 'all');
        $artifactsFirst = (bool) Cache::get($phaseKey, true);
        Cache::forever($phaseKey, ! $artifactsFirst);
        if ($artifactsFirst) {
            app(ArtifactPublication::class)->resume($groupId, $budget);
        }
        $cursorKey = 'collection-reconciliation:resume-cursor:'.($groupId ?? 'all');
        $lastId = (int) Cache::get($cursorKey, 0);
        $stoppedEarly = false;
        DB::table('reconciled_postings')->where('id', '>', $lastId)->where('state', '!=', 'published')->whereNull('review_digest')
            ->when($groupId !== null, static fn ($query) => $query->whereIn('release_id',
                DB::table('releases')->where('groups_id', $groupId)->select('id')))
            ->whereNotNull('original_nzb')->orderBy('id')->chunkById(100, function ($journals) use ($groupId, $budget, &$lastId, &$stoppedEarly): bool {
                foreach ($journals as $journal) {
                    if (! $budget->take()) {
                        $stoppedEarly = true;

                        return false;
                    }
                    $lastId = (int) $journal->id;
                    $release = Release::query()->whereKey($journal->release_id)
                        ->when($groupId !== null, static fn ($query) => $query->where('groups_id', $groupId))->first();
                    $lease = $release === null ? null : $this->claimAnchor($release);
                    if ($lease === null) {
                        continue;
                    }
                    try {
                        $result = app(PostingPublication::class)->write($release, app(NzbService::class), $lease);
                        Log::info('Late posting publication resumed', ['release_id' => $release->id, 'result' => $result->reason]);
                    } finally {
                        $lease->release();
                    }
                }

                return true;
            });
        Cache::forever($cursorKey, $stoppedEarly ? $lastId : 0);
        if (! $artifactsFirst) {
            app(ArtifactPublication::class)->resume($groupId, $budget);
        }
    }

    private function claimAnchor(Release $release): ?RecoveryLease
    {
        return DB::transaction(static function () use ($release): ?RecoveryLease {
            $locked = Release::query()->whereKey($release->id)->lockForUpdate()->first();

            return $locked === null || HistoricalReconciliation::active($locked) ? null : RecoveryLease::acquire($locked);
        }, 3);
    }

    public function reconcile(int $collectionId, int $quietHours, ?float $cycleDeadline = null): ?string
    {
        $current = app(CurrentPostingReconciler::class)->reconcile($collectionId, $quietHours, $cycleDeadline);
        if ($current !== null) {
            return $current;
        }
        $source = DB::table('collections')->where('id', $collectionId)->first();
        if ($source === null) {
            return null;
        }
        $window = (new PopulationQuery)->sourceWindow($source, 1800);
        if ($window === null) {
            return null;
        }
        $matches = DB::table('reconciled_sources')->tap(static function ($query): void {
            if (Schema::hasTable('reconciled_artifacts')) {
                $query->whereNotExists(static fn ($artifact) => $artifact->selectRaw('1')->from('reconciled_artifacts as a')
                    ->join('reconciled_postings as p', 'p.release_id', '=', 'a.release_id')->whereColumn('p.id', 'reconciled_sources.posting_id'));
            }
        })->where('collection_hash', bin2hex($source->collectionhash))
            ->where('group_id', $source->groups_id)->whereBetween('postdate', [$window['from'], $window['until']])
            ->limit(257)->get();
        if ($matches->isEmpty()) {
            $matches = DB::table('reconciled_sources')->tap(static function ($query): void {
                if (Schema::hasTable('reconciled_artifacts')) {
                    $query->whereNotExists(static fn ($artifact) => $artifact->selectRaw('1')->from('reconciled_artifacts as a')
                        ->join('reconciled_postings as p', 'p.release_id', '=', 'a.release_id')->whereColumn('p.id', 'reconciled_sources.posting_id'));
                }
            })->where('group_id', $source->groups_id)
                ->whereBetween('postdate', [$window['from'], $window['until']])
                ->limit(257)->get();
        }
        $postingIds = $matches->pluck('posting_id')->unique()->all();
        if (count($postingIds) !== 1 || $matches->count() > 256) {
            return null;
        }
        $posting = DB::table('reconciled_postings')->where('id', $postingIds[0])->where('state', 'published')->first();
        $release = $posting === null ? null : Release::query()->find($posting->release_id);
        if ($release === null || (int) $release->declaredfiles !== (int) $source->declaredfiles) {
            return null;
        }
        $pending = (new PendingInventory)->load([$collectionId]);
        $snapshot = PendingInventory::digest($pending);
        $owner = (string) Str::uuid();
        $claims = app(CollectionClaims::class);
        $deadline = $claims->claim([$collectionId], $owner, $snapshot, $quietHours);
        if ($deadline === null) {
            return 'late_claim_unavailable';
        }
        $retrying = false;
        $lease = $this->claimAnchor($release);
        if ($lease === null) {
            $claims->retry($owner, 'late_anchor_busy');

            return 'late_anchor_busy';
        }
        try {
            $pending = (new PendingInventory)->load([$collectionId]);
            $snapshot = PendingInventory::digest($pending);
            $stored = PendingInventory::decode($posting->inventory);
            $byArticle = [];
            foreach ($stored as $file) {
                $byArticle[$file->firstArticle()] = $file;
            }
            $new = [];
            foreach ($pending as $file) {
                $old = $byArticle[$file->firstArticle()] ?? null;
                if ($old === null) {
                    $new[] = $file;

                    continue;
                }
                if ($old->filename !== $file->filename || $old->ordinal !== $file->ordinal || $old->total !== $file->total
                    || $old->group !== $file->group || $old->declaredParts !== $file->declaredParts) {
                    return 'late_identity_conflict';
                }
                $segments = array_column($old->segments, null, 'number');
                foreach ($file->segments as $segment) {
                    $held = $segments[$segment['number']] ?? null;
                    if ($held === null || trim($held['messageid'], '<>') !== trim($segment['messageid'], '<>') || $held['bytes'] !== $segment['bytes']) {
                        return 'late_segment_conflict';
                    }
                }
            }
            $decision = null;
            if ($new !== []) {
                $decision = $this->evidence->resolve([...$stored, ...$new], 'late:'.$posting->id, microtime(true) + min(60, max(0, $deadline - now()->timestamp)));
                if ($decision->independentVideos() && PostingPublication::hasTrustedIdentity($release)) {
                    return 'late_trusted_identity_conflict';
                }
                if (count($decision->accepted) !== count($stored) + count($new)) {
                    return 'late_unverified';
                }
            }
            $nzbs = app(NzbService::class);
            $original = $nzbs->readNzbContents($release->guid);
            if ($original === false || hash('sha256', $original) !== $posting->artifact_digest) {
                return 'late_artifact_changed';
            }
            DB::transaction(function () use ($collectionId, $snapshot, $posting, $lease, $release, $decision, $original, $owner): void {
                DB::table('collections')->where('id', $collectionId)->lockForUpdate()->first();
                Release::query()->whereKey($release->id)->lockForUpdate()->first();
                $journal = DB::table('reconciled_postings')->where('id', $posting->id)->lockForUpdate()->first();
                if (! DB::table('reconciliation_claims')->where('collection_id', $collectionId)->where('owner', $owner)->where('lease_until', '>', now())->exists()
                    || ! $lease->owns((int) $release->id) || $journal->digest !== $posting->digest
                    || PendingInventory::digest((new PendingInventory)->load([$collectionId])) !== $snapshot) {
                    throw new RuntimeException('late_stale_inventory');
                }
                if ($decision !== null) {
                    $prior = (array) $journal;
                    unset($prior['id'], $prior['previous_journal']);
                    DB::table('reconciled_postings')->where('id', $posting->id)->update([
                        'inventory' => PendingInventory::encode($decision->accepted), 'digest' => PendingInventory::digest($decision->accepted),
                        'previous_journal' => json_encode(['journal' => $prior, 'source_ids' => DB::table('reconciled_sources')->where('posting_id', $posting->id)->pluck('id')->all()], JSON_THROW_ON_ERROR),
                        'source_digest' => $snapshot, 'budget_id' => 'late:'.$posting->id, 'decision' => json_encode($decision, JSON_THROW_ON_ERROR), 'original_nzb' => base64_encode($original), 'state' => 'created',
                        'independent_videos' => $decision->independentVideos(), 'updated_at' => now(),
                    ]);
                }
                $source = DB::table('collections')->where('id', $collectionId)->first();
                if (! DB::table('reconciled_sources')->where('posting_id', $posting->id)->where('collection_hash', bin2hex($source->collectionhash))
                    ->where('group_id', $source->groups_id)->where('postdate', $source->date)->exists()) {
                    DB::table('reconciled_sources')->insert(['posting_id' => $posting->id, 'collection_hash' => bin2hex($source->collectionhash),
                        'group_id' => $source->groups_id, 'postdate' => $source->date, 'source_id' => (string) $collectionId]);
                }
                DB::table('collections')->where('id', $collectionId)->update(['releases_id' => $release->id, 'filecheck' => 4]);
                DB::table('reconciliation_claims')->where('collection_id', $collectionId)->where('owner', $owner)->update(['reason' => 'associated', 'release_id' => $release->id]);
            }, 3);
            if ($decision !== null) {
                $result = app(PostingPublication::class)->write($release, $nzbs, $lease);

                return $result->success ? 'late_added' : $result->reason;
            }
            app(CollectionCleanupService::class)->deleteCollectionsAndDescendants([$collectionId], 'Verified posting replay', expectedReleaseId: (int) $release->id);

            return 'replayed';
        } catch (\UnexpectedValueException) {
            return 'late_invalid_evidence';
        } catch (RuntimeException $e) {
            $retrying = true;
            $claims->retry($owner, $e->getMessage());

            return 'late_unavailable';
        } finally {
            if (! $retrying) {
                $claims->settle($owner, 'late_checked');
            }
            $lease->release();
        }
    }
}
