<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CollectionDeletionReason;
use App\Services\Binaries\BinariesConfig;
use App\Services\CollectionReconciliation\CollectionAdmission;
use App\Services\CollectionReconciliation\CollectionOwnership;
use App\Services\ObfuscationRecovery\RecoveryCollectionOwnership;
use App\Services\Releases\CollectionDeletionSelection;
use App\Services\Releases\CollectionSweep;
use App\Services\Releases\CollectionSweepLease;
use App\Services\Releases\CollectionSweepLeaseLost;
use App\Services\Releases\CollectionSweepResult;
use App\Support\SettingNumber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CollectionCleanupService
{
    /**
     * Maximum number of retries for a lock-related DB error before giving up.
     */
    private const LOCK_RETRY_MAX = 5;

    /**
     * SQLSTATE returned by InnoDB on deadlock (1213).
     */
    private const SQLSTATE_DEADLOCK = '40001';

    /**
     * MySQL/MariaDB driver error codes we treat as transient lock contention
     * and therefore safe to retry: 1213 = deadlock, 1205 = lock wait timeout.
     *
     * @var int[]
     */
    private const LOCK_DRIVER_CODES = [1213, 1205];

    /**
     * Hours of collection retention applied when the stored setting says nothing
     * usable. Matches the seeded `partretentionhours` value.
     */
    private const DEFAULT_PART_RETENTION_HOURS = 72;

    private ?bool $cascadeDeleteReady = null;

    private readonly BinariesConfig $binariesConfig;

    public function __construct(?BinariesConfig $binariesConfig = null)
    {
        $this->binariesConfig = $binariesConfig ?? BinariesConfig::fromSettings();
    }

    /**
     * Deletes finished/old collections, cleans orphans, and removes collections missed after NZB creation.
     * Mirrors the previous ProcessReleases::deleteCollections logic.
     *
     * @return int total deleted rows across operations (approximate)
     */
    public function deleteFinishedAndOrphans(bool $echoCLI): int
    {
        $startTime = now()->toImmutable();
        $deletedCount = 0;
        $retentionHours = $this->effectiveRetentionHours();

        if ($echoCLI) {
            echo cli()->header('Process Releases -> Delete finished collections.'.PHP_EOL).
                cli()->primary(sprintf(
                    'Deleting collections/binaries/parts older than %d hours.',
                    $retentionHours
                ), true);
        }

        $batchDeleted = $this->runMaintenance(new CollectionDeletionSelection(CollectionDeletionReason::Retention, $retentionHours), null, $echoCLI)->deleted;

        $deletedCount += $batchDeleted;

        if ($echoCLI) {
            $elapsed = now()->diffInSeconds($startTime, true);
            cli()->primary(
                'Finished deleting '.$batchDeleted.' old collections/binaries/parts in '.
                $elapsed.Str::plural(' second', (int) $elapsed),
                true
            );
        }

        // Prune orphaned collections (no binaries) every run, but bounded so a large
        // backlog cannot stall the cycle or exhaust memory. Subsequent runs will keep
        // chipping away until the backlog is gone.
        if ($echoCLI) {
            echo cli()->header('Process Releases -> Remove CBP orphans.'.PHP_EOL).
                cli()->primary('Deleting orphaned collections.', true);
        }

        $orphanDeleted = $this->deleteOrphanCollections($echoCLI);
        $deletedCount += $orphanDeleted;

        if ($echoCLI) {
            $totalTime = now()->diffInSeconds($startTime, true);
            cli()->primary(
                'Finished deleting '.$orphanDeleted.' orphaned collections in '.
                $totalTime.Str::plural(' second', (int) $totalTime),
                true
            );
        }

        // Collections whose release has already been NZB'd are dead weight; drop them
        // in bounded batches via a non-locking SELECT-then-single-table-DELETE so we
        // never materialise the full id set in PHP, never issue per-row DELETEs, and
        // never form a cross-table lock cycle with NzbService::writeNzbForReleaseId().
        if ($echoCLI) {
            cli()->primary('Deleting collections that were missed after NZB creation.', true);
        }

        $missedDeleted = $this->deleteCollectionsMissedAfterNzb($echoCLI);
        $deletedCount += $missedDeleted;

        $totalTime = now()->diffInSeconds($startTime, true);

        if ($echoCLI) {
            cli()->primary(
                'Finished deleting '.$missedDeleted.' collections missed after NZB creation in '.($totalTime).Str::plural(' second', (int) $totalTime).
                PHP_EOL.'Removed '.number_format($deletedCount).' collections (with related binaries/parts) in '.$totalTime.Str::plural(' second', (int) $totalTime),
                true
            );
        }

        return $deletedCount;
    }

    /**
     * Hours of retention this sweep actually applies.
     *
     * The setting is free text: the row can be missing on an install that predates the
     * seeder, blank because the admin field was cleared, or zero/negative/non-numeric
     * because somebody typed into the wrong box. Unguarded, those put the cutoff at *now* --
     * deleting every collection still being assembled, however slowly it posts -- or threw
     * out of `subHours('')` and took the cleanup step down with it. Only a positive number
     * of hours is honored; everything else is the seeded default.
     */
    private function effectiveRetentionHours(): int
    {
        $hours = SettingNumber::int('partretentionhours', self::DEFAULT_PART_RETENTION_HOURS);

        return $hours >= 1 ? $hours : self::DEFAULT_PART_RETENTION_HOURS;
    }

    /**
     * Delete collections that have no binaries (CBP orphans), in bounded batches.
     *
     * Uses the same two-phase pattern as deleteCollectionsMissedAfterNzb():
     * a plain NOT EXISTS SELECT against `binaries` (no row locks) followed
     * by a single-table DELETE FROM collections WHERE id IN (...). This
     * avoids cross-table lock acquisition between `collections` and
     * `binaries`, which can deadlock against concurrent BinaryHandler writes.
     */
    private function deleteOrphanCollections(bool $echoCLI): int
    {
        return $this->runMaintenance(new CollectionDeletionSelection(CollectionDeletionReason::Orphan), null, $echoCLI)->deleted;
    }

    /**
     * Delete collections whose release was already turned into an NZB
     * (releases.nzbstatus = 1). Batched in two phases per iteration:
     *
     *   1. Non-locking SELECT (autocommit MVCC snapshot) to gather a small
     *      list of `collections.id` values whose joined release row has
     *      nzbstatus = 1. No row locks are taken on `releases`.
     *   2. Single-table DELETE FROM collections WHERE id IN (...). The DELETE
     *      never references `releases`, so the lock graph reduces to one
     *      table and concurrent NzbService transactions (which lock
     *      releases -> collections) cannot form a cross-table cycle.
     *
     * This intentionally replaces the previous single DELETE-with-JOIN
     * subselect, which caused recurring `1213 Deadlock found` errors when
     * multiple `multiprocessing:releases` workers ran in parallel against
     * `NzbService::writeNzbForReleaseId()` on the same DB.
     */
    private function deleteCollectionsMissedAfterNzb(bool $echoCLI): int
    {
        return $this->runMaintenance(new CollectionDeletionSelection(CollectionDeletionReason::MissedNzb), null, $echoCLI)->deleted;
    }

    public function runMaintenance(CollectionDeletionSelection $selection, ?int $groupId = null, bool $echoCLI = false): CollectionSweepResult
    {
        $selection = new CollectionDeletionSelection($selection->reason, $selection->hours, $selection->expectedLinks, $groupId);

        $result = (new CollectionSweep)->run($selection->reason->value, $groupId,
            function (array $ids, CollectionSweepLease $lease) use ($selection, $echoCLI): int {
                $rows = $selection->query($ids)->get(['id', 'releases_id']);
                $eligible = $rows->pluck('id')->map(static fn ($id): int => (int) $id)->all();
                if ($selection->reason === CollectionDeletionReason::MissedNzb) {
                    $selection = new CollectionDeletionSelection($selection->reason, $selection->hours, $rows->pluck('releases_id', 'id')->all(), $selection->groupId);
                }

                return $this->deleteCollectionsAndDescendants($eligible, $selection->reason->value, $echoCLI,
                    selection: $selection, lease: $lease);
            });
        if ($echoCLI) {
            cli()->primary(sprintf('%s maintenance: %s; examined %d, deleted %d.',
                $selection->reason->value, $result->outcome->value, $result->examined, $result->deleted), true);
        }

        return $result;
    }

    /**
     * Explicitly delete parts, binaries, then collections for the given IDs.
     * This path does not rely on DB-level cascade constraints.
     *
     * @param  list<int>  $collectionIds
     */
    public function deleteCollectionsAndDescendants(
        array $collectionIds,
        string $label = 'CBP cleanup',
        bool $echoCLI = false,
        ?int $expectedReleaseId = null,
        bool $screenAdmission = true,
        ?CollectionDeletionSelection $selection = null,
        ?CollectionSweepLease $lease = null,
    ): int {
        if ($collectionIds === []) {
            return 0;
        }

        $deletedCollections = 0;

        try {
            foreach (array_chunk($collectionIds, min(($screenAdmission || $lease !== null || $selection !== null) ? CollectionAdmission::MUTATION_BATCH_SIZE : 500, $this->sqlChunkSize())) as $chunk) {
                $lease?->renew();
                if ($screenAdmission && ! app(CollectionAdmission::class)->screen(array_map('intval', $chunk))) {
                    continue;
                }
                $links = $selection?->reason === CollectionDeletionReason::MissedNzb
                    ? ($selection->expectedLinks === null ? DB::table('collections')->whereIn('id', $chunk)->pluck('releases_id', 'id')->all()
                        : array_intersect_key($selection->expectedLinks, array_flip($chunk))) : [];
                $deletedCollections += $this->retryOnLockError(
                    fn (): int => DB::transaction(
                        function () use ($chunk, $expectedReleaseId, $screenAdmission, $selection, $lease, $links): int {
                            $lease?->assertOwned();
                            if ($links !== [] || $expectedReleaseId !== null) {
                                DB::table('releases')->whereIn('id', $expectedReleaseId === null ? array_values($links) : [$expectedReleaseId])->orderBy('id')->lockForUpdate()->get(['id']);
                            }
                            if ($screenAdmission && ! app(CollectionAdmission::class)->lockAndScreen(array_map('intval', $chunk))) {
                                return 0;
                            }
                            $locked = DB::table('collections')->whereIn('id', $chunk)->orderBy('id')->lockForUpdate()->pluck('id');
                            $query = $selection?->query($locked->map(static fn ($id): int => (int) $id)->all(), true, $links)
                                ?? DB::table('collections')->whereIn('id', $locked)->lockForUpdate();
                            if ($expectedReleaseId !== null) {
                                $query->where('releases_id', $expectedReleaseId)->where('filecheck', 4);
                            }
                            if ($selection === null) {
                                RecoveryCollectionOwnership::exclude($query, currentRead: true);
                                CollectionOwnership::exclude($query, currentRead: true);
                            }
                            $chunk = $query->pluck('id')->all();
                            $lease?->assertOwned();
                            if ($chunk === []) {
                                return 0;
                            }
                            if ($this->cascadeDeleteReady()) {
                                $lease?->assertOwned();
                                $deleted = DB::table('collections')->whereIn('id', $chunk)->delete();
                                $lease?->assertOwned();

                                return $deleted;
                            }

                            $lease?->assertOwned();
                            DB::table('parts')->whereIn('binaries_id', DB::table('binaries')->whereIn('collections_id', $chunk)->select('id'))->delete();
                            $lease?->assertOwned();
                            DB::table('binaries')->whereIn('collections_id', $chunk)->delete();
                            $lease?->assertOwned();
                            $deleted = DB::table('collections')->whereIn('id', $chunk)->delete();
                            $lease?->assertOwned();

                            return $deleted;
                        }
                    ),
                    $label,
                    $echoCLI,
                    $lease !== null,
                );
            }

        } catch (CollectionSweepLeaseLost $exception) {
            $exception->deleted += $deletedCollections;
            throw $exception;
        }

        return $deletedCollections;
    }

    public function deleteCollectionsForGroup(int $groupId, bool $echoCLI = false): int
    {
        $deleted = 0;

        do {
            $ids = DB::table('collections')
                ->tap(static fn ($query) => RecoveryCollectionOwnership::exclude($query))->tap(static fn ($query) => CollectionOwnership::exclude($query))
                ->where('groups_id', $groupId)
                ->orderBy('id')
                ->limit($this->sqlChunkSize())
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if ($ids === []) {
                break;
            }

            $affected = $this->deleteCollectionsAndDescendants($ids, 'Group purge', $echoCLI);
            $deleted += $affected;

            if ($affected < count($ids)) {
                break;
            }
        } while (true);

        return $deleted;
    }

    private function sqlChunkSize(): int
    {
        return $this->binariesConfig->sqlChunkSize;
    }

    /**
     * Production deletes can safely target only the parent table when both
     * descendant foreign keys cascade. SQLite fixtures and incomplete legacy
     * schemas deliberately retain the explicit descendant-delete fallback.
     */
    private function cascadeDeleteReady(): bool
    {
        if ($this->cascadeDeleteReady !== null) {
            return $this->cascadeDeleteReady;
        }
        if (DB::getDriverName() === 'sqlite') {
            return $this->cascadeDeleteReady = false;
        }

        try {
            $rows = DB::select(
                'SELECT TABLE_NAME, DELETE_RULE
                 FROM information_schema.REFERENTIAL_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND TABLE_NAME IN (?, ?)',
                [DB::getTablePrefix().'binaries', DB::getTablePrefix().'parts']
            );
            $cascades = [];
            foreach ($rows as $row) {
                if (strtoupper((string) $row->DELETE_RULE) === 'CASCADE') {
                    $cascades[(string) $row->TABLE_NAME] = true;
                }
            }

            return $this->cascadeDeleteReady = isset($cascades[DB::getTablePrefix().'binaries'], $cascades[DB::getTablePrefix().'parts']);
        } catch (\Throwable) {
            return $this->cascadeDeleteReady = false;
        }
    }

    /**
     * Run a DB write inside a bounded retry loop that only swallows transient
     * InnoDB lock errors (deadlock 1213, lock wait timeout 1205). Any other
     * exception is re-thrown so real failures (constraint violations, schema
     * issues, connection drops, etc.) are not silently retried.
     *
     * Backoff is `min(500ms, 20ms * attempt) + 0..25ms jitter` so concurrent
     * cleanup workers stop colliding on the exact same retry cadence.
     *
     * @param  callable():int  $op  Returns the number of rows affected by the write.
     * @param  string  $label  Human-readable label used in the CLI error message.
     * @param  bool  $echoCLI  Whether to echo a final error after exhausting retries.
     * @return int Rows affected on success, or 0 if all retries exhausted.
     */
    private function retryOnLockError(callable $op, string $label, bool $echoCLI, bool $propagateFailure = false): int
    {
        $attempt = 0;

        while (true) {
            try {
                return (int) $op();
            } catch (\Throwable $e) {
                if (! $this->isLockError($e)) {
                    throw $e;
                }

                $attempt++;
                if ($attempt >= self::LOCK_RETRY_MAX) {
                    if ($propagateFailure) {
                        throw $e;
                    }
                    if ($echoCLI) {
                        cli()->error($label.' delete failed after retries: '.$e->getMessage());
                    }

                    return 0;
                }

                $sleepMs = min(500, 20 * $attempt) + random_int(0, 25);
                usleep($sleepMs * 1000);
            }
        }
    }

    /**
     * Determine whether the given throwable represents a transient InnoDB
     * lock error (deadlock or lock wait timeout) that is safe to retry.
     */
    private function isLockError(\Throwable $e): bool
    {
        if ($e instanceof QueryException) {
            $sqlState = (string) $e->getCode();
            $driverCode = (int) ($e->errorInfo[1] ?? 0);

            if ($sqlState === self::SQLSTATE_DEADLOCK) {
                return true;
            }

            if (in_array($driverCode, self::LOCK_DRIVER_CODES, true)) {
                return true;
            }
        }

        // Some drivers surface PDOException directly; fall back to the message.
        $message = $e->getMessage();
        if (str_contains($message, 'Deadlock found')) {
            return true;
        }

        if (str_contains($message, 'Lock wait timeout exceeded')) {
            return true;
        }

        return false;
    }
}
