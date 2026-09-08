<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Facades\Search;
use App\Models\Release;
use App\Services\Nzb\NzbService;
use App\Services\ObfuscationRecovery\RecoveryPublications;
use App\Services\ReleaseImageService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Service for managing releases (delete, update, export).
 */
class ReleaseManagementService
{
    public function __construct() {}

    /**
     * @param  array<string, mixed>  $list
     *
     * @throws \Exception
     */
    public function deleteMultiple(int|array|string $list): void
    {
        $list = (array) $list;

        $nzb = app(NzbService::class);
        $releaseImage = new ReleaseImageService;

        foreach ($list as $identifier) {
            $this->deleteSingleWithService(['g' => $identifier, 'i' => false], $nzb, $releaseImage);
        }
    }

    /**
     * Deletes a single release by GUID, and all the corresponding files.
     *
     * @param  array<string, mixed>  $identifiers  ['g' => Release GUID(mandatory), 'id => ReleaseID(optional, pass
     *                                             false)]
     *
     * @throws \Exception
     */
    public function deleteSingle(array $identifiers, NzbService $nzb, ReleaseImageService $releaseImage): void
    {
        DB::transaction(function () use ($identifiers): void {
            $release = Release::query()->where('guid', $identifiers['g'])->lockForUpdate()->first(['id', 'guid']);
            if ($release !== null) {
                RecoveryPublications::tombstoneRelease((int) $release->id, $release->guid);
            }
        }, 3);

        // Delete NZB from disk.
        $nzbPath = $nzb->nzbPath($identifiers['g']);
        if (! empty($nzbPath)) {
            File::delete($nzbPath);
        }

        // Delete images.
        $releaseImage->delete($identifiers['g']);

        // Get release ID if not provided
        if ($identifiers['i'] === false) {
            $release = Release::query()->where('guid', $identifiers['g'])->first(['id']);
            if ($release !== null) {
                $identifiers['i'] = $release->id;
            }
        }

        // Delete from search index
        if (! empty($identifiers['i'])) {
            Search::deleteRelease((int) $identifiers['i']);
        }

        // Delete from DB.
        Release::whereGuid($identifiers['g'])->delete();
    }

    /**
     * Alias for deleteSingle for backwards compatibility.
     *
     * @param  array<string, mixed>  $identifiers  ['g' => Release GUID(mandatory), 'i => ReleaseID(optional, pass false)]
     *
     * @throws \Exception
     */
    public function deleteSingleWithService(array $identifiers, NzbService $nzb, ReleaseImageService $releaseImage): void
    {
        $this->deleteSingle($identifiers, $nzb, $releaseImage);
    }

    /**
     * Delete a bounded set of releases while batching search and database work.
     *
     * @param  iterable<int, object|array<string, mixed>>  $releases
     */
    public function deleteBatch(iterable $releases, NzbService $nzb, ReleaseImageService $releaseImage): int
    {
        $rows = $this->normalizeReleaseRows($releases);

        if ($rows->isEmpty()) {
            return 0;
        }

        foreach ($rows as $release) {
            DB::transaction(function () use ($release): void {
                $current = Release::query()->whereKey($release['id'])->where('guid', $release['guid'])->lockForUpdate()->first();
                if ($current !== null) {
                    RecoveryPublications::tombstoneRelease((int) $current->id, $current->guid);
                }
            }, 3);
            try {
                $nzb->deleteNzb($release['guid']);
                $releaseImage->delete($release['guid']);
            } catch (Throwable $e) {
                Log::error('Release batch filesystem cleanup failed', [
                    'release_id' => $release['id'],
                    'guid' => $release['guid'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $ids = $rows->pluck('id')->all();

        try {
            Search::deleteReleases($ids);
        } catch (Throwable $e) {
            Log::error('Release batch search cleanup failed', [
                'release_ids' => $ids,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            return Release::query()->whereIn('id', $ids)->delete();
        } catch (Throwable $e) {
            Log::error('Release batch database cleanup failed', [
                'release_ids' => $ids,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Delete an automated-sweep candidate only if no worker claimed it after selection.
     *
     * @param  array{g: string, i: int}  $identifiers
     */
    public function deleteSingleIfUnclaimed(
        array $identifiers,
        NzbService $nzb,
        ReleaseImageService $releaseImage,
        string $reason = 'routine_cleanup',
        ?Closure $evidence = null,
    ): bool {
        return $this->deleteBatchIfUnclaimed([
            ['id' => $identifiers['i'], 'guid' => $identifiers['g']],
        ], $nzb, $releaseImage, $reason, $evidence) === 1;
    }

    /**
     * Lock and recheck automated-sweep candidates at the destructive boundary.
     *
     * Database rows are deleted before artifact cleanup. A concurrent AP or recovery claimant
     * therefore either commits first and excludes the row, or waits for the lock and finds no row.
     *
     * @param  iterable<int, object|array<string, mixed>>  $releases
     */
    public function deleteBatchIfUnclaimed(
        iterable $releases,
        NzbService $nzb,
        ReleaseImageService $releaseImage,
        string $reason = 'routine_cleanup',
        ?Closure $evidence = null,
        bool $dryRun = false,
    ): int {
        $candidates = $this->normalizeReleaseRows($releases);
        // An enclosing transaction may already have an obsolete consistent-read snapshot.
        if (DB::transactionLevel() > 0) {
            Log::channel('daily')->info('release_cleanup_batch', [
                'reason' => $reason,
                'dry_run' => $dryRun,
                'eligible' => 0,
                'protected_or_deferred' => $candidates->count(),
                'deferred_reasons' => ['enclosing_transaction' => $candidates->count()],
            ]);

            return 0;
        }
        $committed = collect();
        try {
            return $this->deleteUnclaimedRows($candidates, $reason, $evidence, $dryRun, $committed)->count();
        } finally {
            if (! $dryRun) {
                DB::afterCommit(fn () => $this->cleanupDeletedRows($committed, $nzb, $releaseImage));
            }
        }
    }

    /**
     * @param  iterable<int, object|array<string, mixed>>  $releases
     * @return Collection<int, array{id: int, guid: string}>
     */
    private function normalizeReleaseRows(iterable $releases): Collection
    {
        /** @var Collection<int, array{id: int, guid: string}> $rows */
        $rows = collect($releases)
            ->map(static fn (object|array $release): array => [
                'id' => (int) data_get($release, 'id'),
                'guid' => (string) data_get($release, 'guid'),
            ])
            ->filter(static fn (array $release): bool => $release['id'] > 0 && $release['guid'] !== '')
            ->unique('id')
            ->values();

        return $rows;
    }

    /**
     * @param  Collection<int, array{id: int, guid: string}>  $candidates
     * @param  Collection<int, array{id: int, guid: string}>  $committed
     * @return Collection<int, array{id: int, guid: string}>
     */
    private function deleteUnclaimedRows(Collection $candidates, string $reason, ?Closure $evidence, bool $dryRun, Collection $committed): Collection
    {
        $accepted = collect();
        $deferredReasons = [];
        foreach ($candidates->sortBy('id') as $candidate) {
            $row = DB::transaction(function () use ($candidate, $reason, $evidence, $dryRun, $committed, &$deferredReasons): ?array {
                $release = Release::query()->whereKey($candidate['id'])->lockForUpdate()->first();
                if ($release === null || $release->guid !== $candidate['guid']) {
                    $deferredReasons['lifecycle'] = ($deferredReasons['lifecycle'] ?? 0) + 1;

                    return null;
                }
                if ($evidence !== null && Schema::hasTable('release_files')) {
                    $fileRows = DB::table('release_files')->where('releases_id', $release->id)
                        ->limit(10001)->lockForUpdate()->get(['releases_id']);
                    if ($fileRows->count() > 10000) {
                        $deferredReasons['file_evidence_limit'] = ($deferredReasons['file_evidence_limit'] ?? 0) + 1;

                        return null;
                    }
                }
                // Establish the consistent-read snapshot only after all evidence locks are held.
                if (! ReleaseDeletionProtection::apply(Release::query())->whereKey($release->id)->exists()) {
                    $deferredReasons['lifecycle'] = ($deferredReasons['lifecycle'] ?? 0) + 1;

                    return null;
                }
                $summary = $evidence === null ? ['eligible' => true] : $evidence($release);
                if (! ($summary['eligible'] ?? false)) {
                    $deferredReason = (string) ($summary['reason'] ?? 'evidence_changed');
                    $deferredReasons[$deferredReason] = ($deferredReasons[$deferredReason] ?? 0) + 1;

                    return null;
                }
                if (! $dryRun) {
                    RecoveryPublications::tombstoneRelease((int) $release->id, $release->guid);
                    if (Release::query()->whereKey($release->id)->delete() !== 1) {
                        throw new RuntimeException('Protected release deletion affected an unexpected row count.');
                    }
                    DB::afterCommit(static function () use ($committed, $candidate): void {
                        $committed->push($candidate);
                    });
                    DB::afterCommit(static fn () => Log::channel('daily')->info('release_deleted', [
                        'release_id' => (int) $release->id,
                        'guid' => (string) $release->guid,
                        'reason' => $summary['predicate'] ?? $reason,
                        'nzbstatus' => (int) $release->nzbstatus,
                        'linked_collections' => false,
                        'claims' => [
                            'nzb_creation_claimed_at' => $release->getRawOriginal('nzb_creation_claimed_at'),
                            'additional_pp_claimed_at' => $release->getRawOriginal('additional_pp_claimed_at'),
                            'recovery_claimed_at' => $release->getRawOriginal('recovery_claimed_at'),
                        ],
                        'evidence' => $summary,
                        'timestamp' => now()->toIso8601String(),
                    ]));
                }

                return $candidate;
            }, 3);
            if ($row !== null) {
                $accepted->push($row);
            }
        }
        Log::channel('daily')->info('release_cleanup_batch', [
            'reason' => $reason,
            'dry_run' => $dryRun,
            'eligible' => $accepted->count(),
            'protected_or_deferred' => $candidates->count() - $accepted->count(),
            'deferred_reasons' => $deferredReasons,
        ]);

        return $accepted;
    }

    /**
     * @param  Collection<int, array{id: int, guid: string}>  $deleted
     */
    private function cleanupDeletedRows(
        Collection $deleted,
        NzbService $nzb,
        ReleaseImageService $releaseImage,
    ): void {
        foreach ($deleted as $release) {
            try {
                $nzb->deleteNzb($release['guid']);
                $releaseImage->delete($release['guid']);
            } catch (Throwable $e) {
                Log::error('Protected release filesystem cleanup failed', [
                    'release_id' => $release['id'],
                    'guid' => $release['guid'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $ids = $deleted->pluck('id')->all();
        if ($ids !== []) {
            try {
                Search::deleteReleases($ids);
            } catch (Throwable $e) {
                Log::error('Protected release search cleanup failed', [
                    'release_ids' => $ids,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  list<string>  $guids
     */
    public function bulkUpdateCategory(array $guids, int $categoryId): int
    {
        $guids = array_values(array_filter($guids));
        if ($guids === [] || $categoryId <= 0) {
            return 0;
        }

        $updated = 0;

        DB::transaction(function () use ($guids, $categoryId, &$updated): void {
            $releaseIds = Release::query()
                ->whereIn('guid', $guids)
                ->pluck('id');

            $updated = Release::query()
                ->whereIn('guid', $guids)
                ->update(['categories_id' => $categoryId, 'iscategorized' => 1]);

            if ($updated > 0) {
                (new PreviewGenerationPolicy)->restoreOwedPreviews($releaseIds);
                $this->syncReleasesToSearchIndex($releaseIds);
                Release::clearAdminReleasesRangeCache();
            }
        });

        return $updated;
    }

    /**
     * Re-index releases after query-builder updates that bypass {@see ReleaseObserver}.
     *
     * @param  Collection<int, int|string>|iterable<int|string>  $releaseIds
     */
    private function syncReleasesToSearchIndex(iterable $releaseIds): void
    {
        foreach ($releaseIds as $releaseId) {
            $intId = (int) $releaseId;
            if ($intId <= 0) {
                continue;
            }

            try {
                Search::updateRelease($intId);
            } catch (Throwable $e) {
                Log::error('ReleaseManagementService: Failed to sync release to search index after category change', [
                    'release_id' => $intId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return Release[]|Builder[]|\Illuminate\Database\Eloquent\Collection<int, mixed>|\Illuminate\Database\Query\Builder[]|Collection<int, mixed>
     */
    public function getForExport(string $postFrom = '', string $postTo = '', string $groupID = '') // @phpstan-ignore missingType.generics
    {
        $query = Release::query()
            ->select(['r.searchname', 'r.guid', 'g.name as gname', DB::raw("CONCAT(cp.title,'_',c.title) AS catName")])
            ->from('releases as r')
            ->leftJoin('categories as c', 'c.id', '=', 'r.categories_id')
            ->leftJoin('root_categories as cp', 'cp.id', '=', 'c.root_categories_id')
            ->leftJoin('usenet_groups as g', 'g.id', '=', 'r.groups_id');

        if ($groupID !== '') {
            $query->where('r.groups_id', $groupID);
        }

        if ($postFrom !== '') {
            $dateParts = explode('/', $postFrom);
            if (\count($dateParts) === 3) {
                $query->where('r.postdate', '>', $dateParts[2].'-'.$dateParts[1].'-'.$dateParts[0].'00:00:00');
            }
        }

        if ($postTo !== '') {
            $dateParts = explode('/', $postTo);
            if (\count($dateParts) === 3) {
                $query->where('r.postdate', '<', $dateParts[2].'-'.$dateParts[1].'-'.$dateParts[0].'23:59:59');
            }
        }

        return $query->get();
    }

    /**
     * @return mixed|string
     */
    public function getEarliestUsenetPostDate(): mixed
    {
        $row = Release::query()->selectRaw("DATE_FORMAT(min(postdate), '%d/%m/%Y') AS postdate")->first();

        return $row === null ? '01/01/2014' : $row['postdate'];
    }

    /**
     * @return mixed|string
     */
    public function getLatestUsenetPostDate(): mixed
    {
        $row = Release::query()->selectRaw("DATE_FORMAT(max(postdate), '%d/%m/%Y') AS postdate")->first();

        return $row === null ? '01/01/2014' : $row['postdate'];
    }

    /**
     * @return array<string, mixed>
     */
    public function getReleasedGroupsForSelect(bool $blnIncludeAll = true): array
    {
        $groups = Release::query()
            ->selectRaw('DISTINCT g.id, g.name')
            ->leftJoin('usenet_groups as g', 'g.id', '=', 'releases.groups_id')
            ->get();
        $temp_array = [];

        if ($blnIncludeAll) {
            $temp_array[-1] = '--All Groups--';
        }

        foreach ($groups as $group) {
            $temp_array[$group['id']] = $group['name'];
        }

        return $temp_array;
    }
}
