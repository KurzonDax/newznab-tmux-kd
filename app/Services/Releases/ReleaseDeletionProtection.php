<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Models\Release;
use App\Services\AdditionalProcessing\ReleaseClaimant;
use App\Services\ReleaseRepair\RecoveryLease;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps destructive automated sweeps away from releases being actively processed.
 *
 * Manual operator deletion remains an explicit override. Scheduled cleanup paths call this gate
 * so AP and recovery can finish writing guid-keyed artifacts before any release is removed.
 */
final class ReleaseDeletionProtection
{
    /**
     * @param  Builder<Release>  $query
     * @return Builder<Release>
     */
    public static function apply(Builder $query, string $table = 'releases'): Builder
    {
        if (Schema::hasTable('releases') && Schema::hasColumn('releases', ReleaseClaimant::CLAIMED_AT_COLUMN)) {
            $column = $table.'.'.ReleaseClaimant::CLAIMED_AT_COLUMN;

            $query->where(static function (Builder $claimQuery) use ($column): void {
                $claimQuery
                    ->whereNull($column)
                    ->orWhere($column, '<', ReleaseClaimant::claimStaleBefore());
            });
        }

        return RecoveryLease::applyAvailable($query, $table);
    }

    /**
     * Filter raw-query candidates through the same gate immediately before batch deletion.
     *
     * @param  Collection<array-key, object>  $candidates
     * @return Collection<int, object>
     */
    public static function filterCandidates(Collection $candidates): Collection
    {
        if ($candidates->isEmpty()) {
            return $candidates->values();
        }

        $ids = $candidates
            ->map(static fn (object $candidate): int => (int) $candidate->id)
            ->all();
        $eligible = self::apply(Release::query())
            ->whereKey($ids)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->flip();

        return $candidates
            ->filter(static fn (object $candidate): bool => $eligible->has((int) $candidate->id))
            ->values();
    }
}
