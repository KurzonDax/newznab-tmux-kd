<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Enums\ReleaseRepairOutcome;
use App\Models\Release;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single definition of "which incomplete releases may be deleted".
 *
 * Every part of the predicate is load-bearing and easy to get subtly wrong, so they live in
 * one place rather than being restated at each call site:
 *
 * - `completion = 0` is the "never measured" sentinel, not a real 0%. There was no denominator
 *   to measure against, so the release is exempt.
 * - `repair_outcome` must be *final*. A release the segment repair engine has never seen, or
 *   still owes an attempt to, is not garbage yet -- its missing articles may still be on the
 *   provider.
 * - `rescan_outcome` must be final *too*, unless the release has nothing for the header re-scan
 *   to look for. The two passes recover different things -- derivable segments, and files with no
 *   segment at all -- so a release that has exhausted one may still be owed the other.
 *
 * "Nothing to re-scan" is `declaredfiles` saying so: null (never derived, and derivation needs
 * the stored NZB rather than SQL), zero (derived, and the NZB declares no usable count), or no
 * greater than the files the release holds. Those releases would be stamped final on sight, so
 * waiting for the stamp would only delay the reaper by a batch.
 *
 * The sweep does no timestamp arithmetic of its own: the state machines own time and hand the
 * reaper only releases they have given up on.
 */
final class IncompleteReleaseSweepQuery
{
    /**
     * @param  float  $completionThreshold  The `completionpercent` setting.
     * @return Builder<Release>
     */
    public static function builder(float $completionThreshold): Builder
    {
        return Release::query()
            ->where('completion', '<', $completionThreshold)
            ->where('completion', '>', 0)
            ->whereIn('repair_outcome', ReleaseRepairOutcome::deletableValues())
            ->where(static function (Builder $query): void {
                $query->whereIn('rescan_outcome', ReleaseRepairOutcome::deletableValues())
                    ->orWhereNull('declaredfiles')
                    ->orWhere('declaredfiles', '<=', 0)
                    ->orWhereColumn('declaredfiles', '<=', 'totalpart');
            });
    }
}
