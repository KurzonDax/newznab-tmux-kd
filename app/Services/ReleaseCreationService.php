<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CollectionFileCheckStatus;
use App\Enums\DuplicateAbsorbOutcome;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Predb;
use App\Models\Release;
use App\Models\ReleaseRegex;
use App\Models\UsenetGroup;
use App\Services\Categorization\CategorizationService;
use App\Services\CollectionReconciliation\CollectionAdmission;
use App\Services\CollectionReconciliation\CollectionOwnership;
use App\Services\Nzb\NzbService;
use App\Services\ObfuscationRecovery\RecoveryAdmission;
use App\Services\ObfuscationRecovery\RecoveryAlgorithm;
use App\Services\ObfuscationRecovery\RecoveryCollectionOwnership;
use App\Services\ObfuscationRecovery\RecoveryCreationContext;
use App\Services\ObfuscationRecovery\RecoveryFormationPolicy;
use App\Services\ObfuscationRecovery\RecoveryOwnership;
use App\Services\ObfuscationRecovery\RecoveryStage;
use App\Services\ObfuscationRecovery\RecoverySurvivor;
use App\Services\ObfuscationRecovery\RecoveryWorkClaim;
use App\Services\Releases\CollectionArticleRangeMeasurer;
use App\Services\Releases\CollectionCompletionMeasurer;
use App\Services\Releases\ReleaseDuplicateAbsorber;
use App\Services\Releases\ReleaseDuplicateFinder;
use App\Support\Utf8;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ReleaseCreationService
{
    public function __construct(
        private readonly ReleaseCleaningService $releaseCleaning,
        private readonly CollectionCleanupService $collectionCleanupService,
        private readonly ReleaseDuplicateFinder $releaseDuplicateFinder,
        private readonly CollectionCompletionMeasurer $completionMeasurer,
        private readonly ReleaseDuplicateAbsorber $releaseDuplicateAbsorber,
        private readonly CollectionArticleRangeMeasurer $articleRangeMeasurer = new CollectionArticleRangeMeasurer,
    ) {}

    /**
     * Create releases from complete collections.
     *
     * @return array{added:int,dupes:int}
     *
     * @throws \Throwable
     */
    public function createReleases(int|string|null $groupID, int $limit, bool $echoCLI): array
    {
        return $this->createFromCollections($groupID, $limit, $echoCLI);
    }

    /**
     * @param  list<int>  $collectionIds
     * @return array{added:int,dupes:int}
     */
    public function createSelectedCollections(int $groupId, array $collectionIds, bool $echoCLI): array
    {
        return $this->createFromCollections($groupId, count($collectionIds), $echoCLI, collectionIds: $collectionIds);
    }

    public function createRecovered(RecoveryWorkClaim $claim, int $publicationId): string
    {
        return DB::transaction(function () use ($claim, $publicationId): string {
            if ($claim->stage !== RecoveryStage::Publish || (new RecoveryOwnership)->locked($claim) === null) {
                return 'obsolete';
            }
            $publication = DB::table('obfuscation_recovery_publications')->where('id', $publicationId)->lockForUpdate()->first();
            if ($publication === null || $publication->deleted_at !== null) {
                return 'tombstoned';
            }
            if (! in_array($publication->state, ['materialized', 'policy_blocked', 'created'], true)) {
                return $publication->state;
            }
            $plan = json_decode($publication->sealed_plan, true, flags: JSON_THROW_ON_ERROR);
            if ($plan['bundle_id'] !== $claim->bundleId || $plan['revision'] !== $claim->revision) {
                return 'obsolete';
            }
            $collection = DB::table('collections')->where('id', $publication->collections_id)->lockForUpdate()->first();
            $expected = $publication->releases_id === null ? CollectionFileCheckStatus::Sized : CollectionFileCheckStatus::Inserted;
            if ($collection === null || (int) $collection->filecheck !== $expected->value) {
                return 'collection_not_ready';
            }
            if (! RecoveryAdmission::allows((int) $collection->groups_id,
                RecoveryAlgorithm::from($publication->profile))) {
                return 'admission_pending';
            }
            $blocked = (new RecoveryFormationPolicy)->blockedReason($collection);
            if ($blocked !== null) {
                DB::table('obfuscation_recovery_publications')->where('id', $publicationId)
                    ->update(['state' => 'policy_blocked', 'reason' => $blocked, 'updated_at' => now()]);

                return 'policy_blocked';
            }
            if ($publication->releases_id !== null) {
                $release = Release::query()->whereKey($publication->releases_id)->where('guid', $publication->guid)->lockForUpdate()->first();
                if ($release === null || $release->collectionhash !== $publication->collection_projection
                    || (int) $release->groups_id !== (int) $collection->groups_id || (int) $release->nzbstatus !== NzbService::NZB_NONE) {
                    return 'release_association_conflict';
                }
                $range = $this->articleRangeMeasurer->measure([(int) $collection->id])[(int) $collection->id] ?? null;
                Release::query()->whereKey($release->id)->update(['firstarticle' => $range['first'] ?? null, 'lastarticle' => $range['last'] ?? null]);
                DB::table('obfuscation_recovery_publications')->where('id', $publicationId)->update(['state' => 'created', 'reason' => null, 'updated_at' => now()]);

                return 'created';
            }
            DB::table('obfuscation_recovery_publications')->where('id', $publicationId)
                ->update(['state' => 'materialized', 'reason' => null, 'updated_at' => now()]);
            $this->createFromCollections((int) $collection->groups_id, 1, false,
                new RecoveryCreationContext($publicationId, (int) $collection->id, $publication->guid));

            return DB::table('obfuscation_recovery_publications')->where('id', $publicationId)->value('state');
        }, 1);
    }

    /**
     * @param  list<int>|null  $collectionIds
     * @return array{added:int,dupes:int}
     */
    private function createFromCollections(int|string|null $groupID, int $limit, bool $echoCLI, ?RecoveryCreationContext $recovery = null, ?array $collectionIds = null): array
    {
        $startTime = now()->toImmutable();
        $categorize = new CategorizationService;
        $returnCount = 0;
        $duplicate = 0;

        if ($echoCLI) {
            cli()->header('Process Releases -> Create releases from complete collections.');
        }

        $collectionsQuery = Collection::query()
            ->where('collections.filecheck', CollectionFileCheckStatus::Sized->value)
            ->where('collections.filesize', '>', 0)
            ->when($collectionIds !== null, static fn ($query) => $query->whereIn('collections.id', $collectionIds))
            ->when($collectionIds !== null && in_array(DB::getDriverName(), ['mysql', 'mariadb'], true),
                static fn ($query) => $query->forceIndex('PRIMARY'));
        if ($recovery === null) {
            RecoveryCollectionOwnership::exclude($collectionsQuery);
            CollectionOwnership::exclude($collectionsQuery);
        } else {
            $collectionsQuery->where('collections.id', $recovery->collectionId);
        }
        if (! empty($groupID)) {
            $collectionsQuery->where('collections.groups_id', $groupID);
        }
        $collectionsQuery->select(['collections.*', 'usenet_groups.name as gname'])
            ->join('usenet_groups', 'usenet_groups.id', '=', 'collections.groups_id')
            ->orderBy('collections.id')
            ->limit(min(500, $limit));
        $collections = $collectionsQuery->get();
        $preflight = $collectionIds === null || count($collectionIds) > CollectionAdmission::MUTATION_BATCH_SIZE;
        if ($recovery === null && $preflight && ! app(CollectionAdmission::class)->screen($collections->pluck('id')->map(static fn ($id): int => (int) $id)->all())) {
            return ['added' => 0, 'dupes' => 0];
        }
        $releaseGroupIds = $this->loadReleaseGroupIds($collections);
        // Measured now, while the collections/binaries/parts rows are still there: NZB creation
        // deletes them, and it may not run for a while -- or at all, if it keeps failing.
        // `declaredfiles`, not `totalfiles`: stale promotion rewrites the latter to the files
        // actually seen, which would make an incomplete release measure as a complete one.
        $completionSignals = $this->completionMeasurer->measure(
            $collections->mapWithKeys(static fn ($collection): array => [
                (int) $collection->id => (int) $collection->declaredfiles,
            ])->all()
        );

        // The last moment the article numbers exist: NZB creation deletes the parts rows.
        $articleRanges = $this->articleRangeMeasurer->measure(
            $collections->pluck('id')->map(static fn ($id): int => (int) $id)->all()
        );

        if ($echoCLI && $collections->count() > 0) {
            cli()->primary(\count($collections).' Collections ready to be converted to releases.', true);
        }

        foreach ($collections as $collection) {
            DB::transaction(function () use ($collection, $recovery, $categorize, $releaseGroupIds, $completionSignals, $articleRanges, $echoCLI, &$returnCount, &$duplicate): void {
                if ($recovery === null && ! app(CollectionAdmission::class)->lockAndScreen([(int) $collection->id])) {
                    return;
                }
                $locked = DB::table('collections')->where('id', $collection->id)->lockForUpdate()->first();
                if ($locked === null || (int) $locked->filecheck !== CollectionFileCheckStatus::Sized->value
                    || ($recovery === null && CollectionOwnership::protects((int) $collection->id))) {
                    return;
                }
                $cleanRelName = Utf8::clean(str_replace(['#', '@', '$', '%', '^', '§', '¨', '©', 'Ö'], '', $collection->subject));
                $fromName = Utf8::clean(trim($collection->fromname, "'"));

                $cleanedMeta = $recovery !== null ? ['properlynamed' => false, 'predb' => false, 'cleansubject' => $collection->subject] : $this->releaseCleaning->releaseCleaner(
                    $collection->subject,
                    $collection->fromname,
                    $collection->gname
                );

                $namingRegexId = 0;
                if (\is_array($cleanedMeta)) {
                    $namingRegexId = isset($cleanedMeta['id']) ? (int) $cleanedMeta['id'] : 0;
                }

                if (\is_array($cleanedMeta)) {
                    $properName = $cleanedMeta['properlynamed'] ?? false;
                    $preID = $cleanedMeta['predb'] ?? false;
                    $cleanedName = $cleanedMeta['cleansubject'] ?? $cleanRelName;
                } else {
                    $properName = true;
                    $preID = false;
                    $cleanedName = $cleanRelName;
                }

                if ($recovery === null && $preID === false && $cleanedName !== '') {
                    $preMatch = Predb::matchPre($cleanedName);
                    if ($preMatch !== false) {
                        $cleanedName = $preMatch['title'];
                        $preID = $preMatch['predb_id'];
                        $properName = true;
                    }
                }

                $searchName = ! empty($cleanedName) ? Utf8::clean($cleanedName) : $cleanRelName;
                $predbIdInt = $preID === false ? 0 : (int) $preID;

                $collectionHash = ($collection->collectionhash ?? '') === '' ? null : (string) $collection->collectionhash;

                [$dupeCheck, $dupeReason] = $this->releaseDuplicateFinder->findDuplicate(
                    $cleanRelName,
                    $searchName,
                    $predbIdInt,
                    (int) $collection->filesize
                );

                $articleRange = $articleRanges[(int) $collection->id] ?? null;

                $releaseID = null;
                if ($dupeCheck === null) {
                    $determinedCategory = $recovery !== null ? ['categories_id' => Category::OTHER_MISC] : $categorize->determineCategory(
                        $collection->groups_id,
                        $cleanedName,
                        $fromName,
                        associatedGroupIds: $releaseGroupIds[(int) $collection->id] ?? [],
                    );

                    try {
                        $releaseID = Release::insertRelease([
                            'name' => $cleanRelName,
                            'searchname' => $searchName,
                            'totalpart' => $collection->totalfiles,
                            'declaredfiles' => (int) $collection->declaredfiles,
                            'firstarticle' => $articleRange['first'] ?? null,
                            'lastarticle' => $articleRange['last'] ?? null,
                            'groups_id' => $collection->groups_id,
                            'guid' => $recovery->guid ?? Str::uuid()->toString(),
                            'postdate' => $collection->date,
                            'fromname' => $fromName,
                            'size' => $collection->filesize,
                            'categories_id' => $determinedCategory['categories_id'] ?? Category::OTHER_MISC,
                            'isrenamed' => $properName === true ? 1 : 0,
                            'is_trusted_name' => $properName === true || $predbIdInt > 0,
                            'predb_id' => $predbIdInt,
                            'nzbstatus' => NzbService::NZB_NONE,
                            'completion' => ($completionSignals[(int) $collection->id] ?? null)?->percentage() ?? 0.0,
                            'collectionhash' => $collectionHash,
                        ]);
                    } catch (UniqueConstraintViolationException $exception) {
                        [$dupeCheck, $dupeReason] = $this->recoverCollectionHashConflict($collectionHash, $exception);
                    }

                    if ($releaseID !== null) {
                        if ($recovery !== null) {
                            DB::table('obfuscation_recovery_publications')->where('id', $recovery->publicationId)->update([
                                'releases_id' => $releaseID, 'guid' => $recovery->guid, 'state' => 'created',
                                'initialization_state' => 'pending', 'updated_at' => now(),
                            ]);
                        }
                        DB::transaction(static function () use ($collection, $releaseID, $articleRange) {
                            Collection::query()->where('id', $collection->id)->update([
                                'filecheck' => CollectionFileCheckStatus::Inserted->value,
                                'releases_id' => $releaseID,
                                'firstarticle' => $articleRange['first'] ?? null,
                                'lastarticle' => $articleRange['last'] ?? null,
                            ]);
                        }, 10);

                        ReleaseRegex::insertOrIgnore([
                            'releases_id' => $releaseID,
                            'collection_regex_id' => $collection->collection_regexes_id,
                            'naming_regex_id' => $namingRegexId,
                        ]);

                        $groupRows = [];
                        foreach ($releaseGroupIds[(int) $collection->id] ?? [] as $groupId) {
                            $groupRows[] = ['releases_id' => $releaseID, 'groups_id' => $groupId];
                        }
                        if ($groupRows !== []) {
                            DB::table('releases_groups')->insertOrIgnore($groupRows);
                        }

                        $returnCount++;
                        if ($echoCLI) {
                            echo "Added $returnCount releases.\r";
                        }
                    }
                }

                if ($dupeCheck !== null) {
                    $absorbed = false;
                    if ($this->releaseDuplicateAbsorber->supportsReason($dupeReason)) {
                        try {
                            $absorbResult = $this->releaseDuplicateAbsorber->absorbCollection(
                                $dupeCheck,
                                $collection,
                                ($completionSignals[(int) $collection->id] ?? null)?->percentage() ?? 0.0,
                            );
                        } catch (\Throwable $exception) {
                            // Backstop for errors outside the absorber's outcome
                            // contract (e.g. the attempt-counter write failing).
                            Log::error('A better duplicate collection hit an unexpected error while absorbing; preserving it for retry.', [
                                'matched_release_id' => $dupeCheck->id,
                                'collection_id' => $collection->id,
                                'exception' => $exception,
                            ]);

                            return;
                        }

                        if ($absorbResult->outcome === DuplicateAbsorbOutcome::Deferred) {
                            // Expected state while the anchor's NZB creation
                            // catches up: preserve the collection silently and let
                            // a later cycle absorb it.
                            Log::debug('Duplicate absorb deferred: the anchor has no stored NZB yet.', [
                                'matched_release_id' => $dupeCheck->id,
                                'collection_id' => $collection->id,
                            ]);

                            return;
                        }

                        if ($absorbResult->outcome === DuplicateAbsorbOutcome::Failed) {
                            if ($absorbResult->attempts < ReleaseDuplicateAbsorber::MAX_ABSORB_ATTEMPTS) {
                                Log::error('A better duplicate collection could not be absorbed; preserving it for retry.', [
                                    'matched_release_id' => $dupeCheck->id,
                                    'collection_id' => $collection->id,
                                    'reason' => $absorbResult->reason,
                                    'attempts' => $absorbResult->attempts,
                                ]);

                                return;
                            }

                            // The backstop: a collection whose absorb keeps
                            // failing settles as an ordinary duplicate instead of
                            // retrying every cycle forever.
                            Log::warning('A better duplicate collection kept failing to absorb; settling it as an ordinary duplicate.', [
                                'matched_release_id' => $dupeCheck->id,
                                'collection_id' => $collection->id,
                                'reason' => $absorbResult->reason,
                                'attempts' => $absorbResult->attempts,
                            ]);
                        }

                        $absorbed = $absorbResult->wasAbsorbed();
                    }

                    Log::info('Release import skipped as duplicate', [
                        'reason' => $dupeReason,
                        'matched_release_id' => $dupeCheck->id,
                        'absorbed' => $absorbed,
                        'new_searchname' => $searchName,
                        'existing_searchname' => $dupeCheck->searchname,
                        'new_size' => (int) $collection->filesize,
                        'existing_size' => (int) $dupeCheck->size,
                        'new_fromname' => $fromName,
                        'existing_fromname' => $dupeCheck->fromname,
                        'new_name' => $cleanRelName,
                        'existing_name' => $dupeCheck->name,
                    ]);

                    if ($recovery !== null) {
                        DB::table('obfuscation_recovery_publications')->where('id', $recovery->publicationId)->update([
                            'releases_id' => $dupeCheck->id, 'guid' => DB::table('releases')->where('id', $dupeCheck->id)->value('guid'),
                            'state' => $absorbed ? 'absorbed' : 'duplicate_policy_discarded', 'reason' => $dupeReason,
                            'initialization_state' => 'not_applicable', 'updated_at' => now(),
                        ]);
                        app(RecoverySurvivor::class)->inspect($recovery->publicationId);
                    }
                    $this->collectionCleanupService->deleteCollectionsAndDescendants(
                        [$collection->id],
                        'Duplicate cleanup',
                        $echoCLI
                    );
                    DB::table('collections')->where('id', $collection->id)
                        ->where('filecheck', CollectionFileCheckStatus::Sized->value)
                        ->update(['filecheck' => CollectionFileCheckStatus::Delete->value]);

                    $duplicate++;
                }
            }, 3);
        }

        $totalTime = now()->diffInSeconds($startTime, true);
        if ($echoCLI) {
            cli()->primary(
                PHP_EOL.
                number_format($returnCount).
                ' Releases added and '.
                number_format($duplicate).
                ' duplicate collections deleted in '.
                $totalTime.Str::plural(' second', (int) $totalTime),
                true
            );
        }

        return ['added' => $returnCount, 'dupes' => $duplicate];
    }

    /**
     * The unique index on releases.collectionhash is the deterministic dedupe
     * guarantee: a violation means this collection's release already exists
     * (created by a previous scan and since renamed/resized past what the
     * heuristic finder matches, or by a concurrent creator). Resolve the
     * existing release so the collection takes the normal duplicate path.
     *
     * @return array{0: Release, 1: string}
     */
    private function recoverCollectionHashConflict(
        ?string $collectionHash,
        UniqueConstraintViolationException $exception
    ): array {
        $existing = $collectionHash === null
            ? null
            : Release::query()
                ->where('collectionhash', $collectionHash)
                ->first(['id', 'predb_id', 'searchname', 'fromname', 'size', 'name']);

        if ($existing === null) {
            throw $exception;
        }

        return [$existing, 'collectionhash_match'];
    }

    /**
     * Resolve all normalized cross-post groups for the selected collection
     * batch up front, avoiding release/group membership N+1 queries.
     *
     * @param  \Illuminate\Support\Collection<int, Collection>  $collections
     * @return array<int, list<int>>
     */
    private function loadReleaseGroupIds(\Illuminate\Support\Collection $collections): array
    {
        $namesByCollection = [];
        $collectionIds = $collections->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        if (Schema::hasTable('collection_groups')) {
            foreach (array_chunk($collectionIds, 500) as $chunk) {
                foreach (DB::table('collection_groups')->whereIn('collections_id', $chunk)->get() as $row) {
                    $namesByCollection[(int) $row->collections_id][(string) $row->group_name] = true;
                }
            }
        }

        foreach ($collections as $collection) {
            $collectionId = (int) $collection->id;
            if (($namesByCollection[$collectionId] ?? []) === []) {
                $fallback = (new XrefService)->extractGroupNames((string) $collection->xref);
                if ($fallback === []) {
                    $fallback = [(string) $collection->gname];
                }
                foreach ($fallback as $name) {
                    $namesByCollection[$collectionId][$name] = true;
                }
            }
        }

        $allNames = [];
        foreach ($namesByCollection as $names) {
            $allNames += $names;
        }
        $idsByName = UsenetGroup::query()
            ->whereIn('name', array_keys($allNames))
            ->pluck('id', 'name')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        foreach (array_keys($allNames) as $name) {
            $validName = UsenetGroup::isValidGroup($name);
            if ($validName === false || isset($idsByName[$validName])) {
                continue;
            }
            $idsByName[$validName] = (int) UsenetGroup::addGroup([
                'name' => $validName,
                'description' => 'Added by Release processing',
                'backfill_target' => 1,
                'first_record' => 0,
                'last_record' => 0,
                'active' => 0,
                'backfill' => 0,
                'minfilestoformrelease' => '',
                'minsizetoformrelease' => '',
            ]);
        }

        $resolved = [];
        foreach ($namesByCollection as $collectionId => $names) {
            foreach (array_keys($names) as $name) {
                if (isset($idsByName[$name])) {
                    $resolved[$collectionId][] = $idsByName[$name];
                }
            }
        }

        return $resolved;
    }
}
