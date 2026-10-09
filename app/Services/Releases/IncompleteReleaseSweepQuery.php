<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Models\Release;
use App\Support\ReleaseRepairingContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The single definition of "which incomplete releases may be deleted".
 *
 * - `completion = 0` is the "never measured" sentinel, not a real 0%, so it is exempt.
 * - Late headers can still complete a release after it forms ({@see LateHeaderMerger}), and they
 *   land well after formation. A release is deletable only once it has been in the index for the
 *   `incomplete_release_grace_hours` setting, and never while a stored late collection shares its
 *   `collectionhash` and is waiting for the merge. That hold is applied at selection by
 *   {@see self::builder()} and again under the row lock by {@see self::lateCollectionWaiting()}.
 * - A release is also kept while an enabled secondary provider may still be reading its post. The
 *   query cannot see provider positions, so the sweep applies {@see self::lateHeadersPending()}
 *   to every selected row.
 */
final class IncompleteReleaseSweepQuery
{
    /**
     * Hours after `adddate` before a sub-threshold release may be deleted. Of the late merges that
     * raised a sub-threshold release between 2026-10-02 and 2026-10-07, 97.2% landed within 72
     * hours of `adddate`; the rest came more than 1,000 hours later. The operator's value comes from
     * the `incomplete_release_grace_hours` setting.
     */
    public const int DEFAULT_LATE_HEADER_GRACE_HOURS = 72;

    /**
     * @param  float  $completionThreshold  The `completionpercent` setting.
     * @param  int  $graceHours  The `incomplete_release_grace_hours` setting, already resolved.
     * @return Builder<Release>
     */
    public static function builder(float $completionThreshold, int $graceHours): Builder
    {
        $query = Release::query()
            ->where('completion', '<', $completionThreshold)
            ->where('completion', '>', 0)
            ->where('adddate', '<', now()->subHours($graceHours));

        if (Schema::hasTable('collections')) {
            $query->whereNotExists(static function (\Illuminate\Database\Query\Builder $late): void {
                $late->selectRaw('1')->from('collections')
                    ->whereColumn('collections.collectionhash', 'releases.collectionhash');
            });
        }

        return ReleaseDeletionProtection::apply($query);
    }

    /** Is a stored late collection waiting to merge into this release? Such a row is kept. */
    public static function lateCollectionWaiting(Release $release): bool
    {
        if ($release->collectionhash === null || ! Schema::hasTable('collections')) {
            return false;
        }

        return DB::table('collections')->where('collectionhash', $release->collectionhash)->exists();
    }

    /** May a secondary provider still add late headers to this selected release? Such a row is kept. */
    public static function lateHeadersPending(Release $release, ReleaseRepairingContext $context): bool
    {
        return $context->secondaryStillReading($release->groups_id, $release->postdate);
    }
}
