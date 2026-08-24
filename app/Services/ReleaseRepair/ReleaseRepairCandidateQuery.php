<?php

declare(strict_types=1);

namespace App\Services\ReleaseRepair;

use App\Enums\ReleaseRepairOutcome;
use App\Models\Release;
use App\Services\Nzb\NzbService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Picks the releases one repair invocation works on.
 *
 * Two populations, in priority order:
 *
 * 1. **Due retries** -- releases whose first pass fell short and whose retry window has now
 *    passed. These are time-critical in a way the rest are not: it is their last pass, and every
 *    invocation they miss is one where the reaper is still waiting on them.
 * 2. **Never attempted** -- newest first, matching how additional processing claims work, so
 *    fresh releases keep priority and the legacy backlog drains from the tail.
 *
 * The batch is bounded because the repaired releases feed straight back into additional
 * processing, whose capacity is `postthreads x maxaddprocessed` per cycle. The reaper needs no
 * such throttle: it only ever sees final outcomes, which appear at this drip rate by construction.
 */
final class ReleaseRepairCandidateQuery
{
    /**
     * @return Collection<int, Release>
     */
    public static function batch(int $limit, float $targetCompletion, int $retryAfterHours): Collection
    {
        // 0 means "no wait" -- an operator forcing the final pass, not a value to correct.
        $retryCutoff = Carbon::now()->subHours(max(0, $retryAfterHours));

        $dueRetries = self::measuredBelow($targetCompletion)
            ->where('repair_outcome', ReleaseRepairOutcome::RetryPending->value)
            ->where('repair_attempted_at', '<', $retryCutoff)
            ->orderBy('repair_attempted_at')
            ->limit($limit)
            ->get();

        if ($dueRetries->count() >= $limit) {
            return $dueRetries;
        }

        $neverAttempted = self::measuredBelow($targetCompletion)
            ->whereNull('repair_outcome')
            ->orderByDesc('postdate')
            ->limit($limit - $dueRetries->count())
            ->get();

        return $dueRetries->concat($neverAttempted);
    }

    /**
     * Releases with a real measurement below the target.
     *
     * `completion = 0` is the "never measured" sentinel, not 0%: nothing declared a part total,
     * so there is no denominator and nothing to repair against. Those wait for the backfill.
     *
     * @return Builder<Release>
     */
    private static function measuredBelow(float $targetCompletion): Builder
    {
        return RecoveryLease::applyAvailable(Release::query())
            ->where('nzbstatus', NzbService::NZB_ADDED)
            ->where('completion', '>', 0)
            ->where('completion', '<', $targetCompletion)
            ->select(['id', 'guid', 'completion', 'haspreview', 'repair_outcome', 'repair_attempted_at', 'postdate']);
    }
}
