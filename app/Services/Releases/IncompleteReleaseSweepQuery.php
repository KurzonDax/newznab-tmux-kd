<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Enums\ReleaseRepairOutcome;
use App\Models\Release;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single definition of "which incomplete releases may be deleted".
 *
 * Both halves of the predicate are load-bearing and easy to get subtly wrong, so they live in
 * one place rather than being restated at each call site:
 *
 * - `completion = 0` is the "never measured" sentinel, not a real 0%. There was no denominator
 *   to measure against, so the release is exempt.
 * - `repair_outcome` must be *final*. A release the repair engine has never seen, or still owes
 *   an attempt to, is not garbage yet -- its missing articles may still be on the provider.
 *
 * The sweep does no timestamp arithmetic of its own: the repair state machine owns time and
 * hands the reaper only releases it has given up on.
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
            ->whereIn('repair_outcome', ReleaseRepairOutcome::deletableValues());
    }
}
