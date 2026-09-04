<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles missed parts tracking and repair during header processing.
 */
final class MissedPartHandler
{
    private int $partRepairLimit;

    private int $partRepairMaxTries;

    private int $chunkSize;

    public function __construct(int $partRepairLimit = 15000, int $partRepairMaxTries = 3, int $chunkSize = 500)
    {
        $this->partRepairLimit = $partRepairLimit;
        $this->partRepairMaxTries = $partRepairMaxTries;
        $this->chunkSize = max(50, min(1000, $chunkSize));
    }

    /**
     * Add missing article numbers to the repair queue.
     *
     * @param  array<int, int|string>  $numbers
     */
    public function addMissingParts(array $numbers, int $groupId): void
    {
        if (empty($numbers)) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->addMissingPartsSqlite($numbers, $groupId);

            return;
        }

        $this->addMissingPartsMysql($numbers, $groupId);
    }

    /**
     * @param  array<int, int|string>  $numbers
     */
    private function addMissingPartsSqlite(array $numbers, int $groupId): void
    {
        foreach (array_chunk(array_unique($numbers), $this->chunkSize) as $chunk) {
            [$values, $bindings] = $this->valuesForNewQueueEntries($chunk, $groupId);

            DB::statement(
                'INSERT INTO missed_parts (numberid, groups_id, attempts) VALUES '.$values.' ON CONFLICT(numberid, groups_id) DO UPDATE SET attempts = attempts + 1',
                $bindings
            );
        }
    }

    /**
     * @param  array<int, int|string>  $numbers
     */
    private function addMissingPartsMysql(array $numbers, int $groupId): void
    {
        foreach (array_chunk(array_unique($numbers), $this->chunkSize) as $chunk) {
            [$values, $bindings] = $this->valuesForNewQueueEntries($chunk, $groupId);

            DB::insert(
                'INSERT INTO missed_parts (numberid, groups_id, attempts) VALUES '.$values.' ON DUPLICATE KEY UPDATE attempts = attempts + 1',
                $bindings
            );
        }
    }

    /**
     * Build the VALUES tuples and bindings both insert paths share, so the entry baseline
     * cannot drift between database drivers.
     *
     * `attempts` counts completed repair attempts, so a part enters the queue at zero:
     * getMissingParts() offers rows below the configured maximum and
     * cleanupExhaustedParts() deletes rows that reached it, so entering at one spent a
     * try nobody made -- a stored maximum of 3 delivered two real attempts, and a stored
     * 1 delivered none at all. Re-detection still bumps the counter, so the relative
     * aging of queued parts is unchanged; the whole scale simply shifts down by one.
     *
     * @param  array<int, int|string>  $numbers
     * @return array{string, list<int|string>}
     */
    private function valuesForNewQueueEntries(array $numbers, int $groupId): array
    {
        $placeholders = [];
        $bindings = [];

        foreach ($numbers as $number) {
            $placeholders[] = '(?, ?, 0)';
            $bindings[] = $number;
            $bindings[] = $groupId;
        }

        return [implode(',', $placeholders), $bindings];
    }

    /**
     * Remove successfully repaired parts from the queue.
     *
     * @param  array<int, int|string>  $numbers
     */
    public function removeRepairedParts(array $numbers, int $groupId): void
    {
        if (empty($numbers)) {
            return;
        }

        try {
            // Single DELETE — InnoDB autocommits one statement atomically, so
            // an explicit transaction here would just add round-trips.
            DB::table('missed_parts')
                ->where('groups_id', $groupId)
                ->whereIn('numberid', $numbers)
                ->delete();
        } catch (\Throwable $e) {
            if (config('app.debug') === true) {
                Log::warning('removeRepairedParts failed: '.$e->getMessage());
            }
        }
    }

    /**
     * Get parts that need repair for a group.
     *
     * @return array<int, \stdClass> Array of missed parts
     */
    public function getMissingParts(int $groupId): array
    {
        try {
            return DB::table('missed_parts')
                ->where('groups_id', $groupId)
                ->where('attempts', '<', $this->partRepairMaxTries)
                ->orderBy('numberid')
                ->limit($this->partRepairLimit)
                ->get()
                ->all();
        } catch (\PDOException $e) {
            if ($e->getMessage() === 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction') {
                Log::notice('Deadlock occurred while fetching missed parts');
            }

            return [];
        }
    }

    /**
     * Increment attempts for parts that weren't repaired.
     */
    public function incrementAttempts(int $groupId, int $maxNumberId): void
    {
        DB::table('missed_parts')
            ->where('groups_id', $groupId)
            ->where('numberid', '<=', $maxNumberId)
            ->increment('attempts');
    }

    /**
     * Get count of remaining missed parts.
     */
    public function getCount(int $groupId, int $maxNumberId): int
    {
        return DB::table('missed_parts')
            ->where('groups_id', $groupId)
            ->where('numberid', '<=', $maxNumberId)
            ->count('id');
    }

    /**
     * Remove parts that exceeded max tries.
     */
    public function cleanupExhaustedParts(int $groupId): void
    {
        // Single DELETE — InnoDB autocommits atomically; the explicit
        // transaction wrapper would just add round-trips.
        DB::table('missed_parts')
            ->where('groups_id', $groupId)
            ->where('attempts', '>=', $this->partRepairMaxTries)
            ->delete();
    }
}
