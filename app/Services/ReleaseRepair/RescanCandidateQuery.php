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
 * Picks the releases one header re-scan invocation works on.
 *
 * The same two populations the repair engine uses -- due retries first, then never-attempted --
 * for the same reason: a retry is a release's last pass, and every invocation it misses is one
 * where the reaper is still waiting on it.
 *
 * Two things differ. Never-attempted releases are ordered by **smallest shortfall first** rather
 * than newest: a release missing two files of forty is both the likeliest to be recovered and the
 * cheapest to try, while one missing seven hundred is a posting session that never arrived.
 * Postdate breaks the tie, so fresh releases still lead among equals.
 *
 * And the batch admits releases whose `declaredfiles` is still null. Those are legacy rows whose
 * declared count has not been derived yet; the derivation reads the stored NZB, which is far too
 * expensive to do in SQL, so it happens on first visit and persists. Having no known shortfall,
 * they queue behind the rows that do have one. A row that turns out to have nothing to re-scan is
 * stamped final on that visit and never selected again.
 */
final class RescanCandidateQuery
{
    /**
     * @return Collection<int, Release>
     */
    public static function batch(int $limit, float $targetCompletion, int $retryAfterHours): Collection
    {
        // 0 means "no wait" -- an operator forcing the final pass, not a value to correct.
        $retryCutoff = Carbon::now()->subHours(max(0, $retryAfterHours));

        $dueRetries = self::measuredBelow($targetCompletion)
            ->where('rescan_outcome', ReleaseRepairOutcome::RetryPending->value)
            ->where('rescan_attempted_at', '<', $retryCutoff)
            ->orderBy('rescan_attempted_at')
            ->limit($limit)
            ->get();

        if ($dueRetries->count() >= $limit) {
            return $dueRetries;
        }

        $reopened = self::measuredBelow($targetCompletion)
            ->where('rescan_outcome', ReleaseRepairOutcome::Repaired->value)
            ->where(function (Builder $query) use ($targetCompletion): void {
                $query->where('rescan_evaluated_target_completion', '<', $targetCompletion)
                    ->orWhere(function (Builder $query) use ($targetCompletion): void {
                        $query->whereNull('rescan_evaluated_target_completion')
                            ->where('rescan_target_completion', '<', $targetCompletion);
                    });
            })
            ->orderByRaw('declaredfiles - totalpart')
            ->orderByDesc('postdate')
            ->limit($limit - $dueRetries->count())
            ->get();

        if ($dueRetries->count() + $reopened->count() >= $limit) {
            return $dueRetries->concat($reopened);
        }

        $neverAttempted = self::measuredBelow($targetCompletion)
            ->whereNull('rescan_outcome')
            ->where(function (Builder $query): void {
                $query->whereNull('declaredfiles')
                    ->orWhereColumn('declaredfiles', '>', 'totalpart');
            })
            // Unresolved rows sort *after* the ones whose shortfall is known: `NULL - totalpart`
            // is not a shortfall, and letting it stand in for one would put the whole legacy
            // backlog at the front of every batch, largest release first -- the exact opposite of
            // cheapest-first. Among themselves they take the same newest-first order the repair
            // engine uses, so fresh releases keep priority and the backlog drains from the tail.
            ->orderByRaw('CASE WHEN declaredfiles IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN declaredfiles IS NULL THEN 0 ELSE declaredfiles - totalpart END')
            ->orderByDesc('postdate')
            ->limit($limit - $dueRetries->count() - $reopened->count())
            ->get();

        return $dueRetries->concat($reopened)->concat($neverAttempted);
    }

    /**
     * Releases with a real measurement below the target.
     *
     * `completion = 0` is the "never measured" sentinel, not 0%: nothing declared a part total,
     * so there is no denominator and nothing to measure a recovery against.
     *
     * @return Builder<Release>
     */
    private static function measuredBelow(float $targetCompletion): Builder
    {
        return RecoveryLease::applyAvailable(Release::query())
            ->where('nzbstatus', NzbService::NZB_ADDED)
            ->where('completion', '>', 0)
            ->where('completion', '<', $targetCompletion)
            ->select([
                'id', 'guid', 'groups_id', 'completion', 'postdate', 'totalpart',
                'declaredfiles', 'firstarticle', 'lastarticle',
                'repair_outcome', 'repair_target_completion',
                'rescan_outcome', 'rescan_attempted_at', 'rescan_target_completion',
                'rescan_evaluated_target_completion',
            ]);
    }
}
