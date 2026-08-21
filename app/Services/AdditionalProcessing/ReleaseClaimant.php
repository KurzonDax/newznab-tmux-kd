<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Models\Release;
use App\Services\AudioProcessing\AudioCandidateQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Claims a bounded batch of pending releases for one worker.
 *
 * The candidate queries differ only in which releases they consider; how a
 * worker takes ownership of them is identical, so it lives here rather than
 * once per query. {@see AdditionalCandidateQuery} and
 * {@see AudioCandidateQuery} both route through
 * this, which is what keeps their claim/stale semantics from drifting apart.
 */
final class ReleaseClaimant
{
    /**
     * Select up to $limit rows from $base, stamp the claim columns, and return
     * the rows in the order they were selected.
     *
     * @param  Builder<Release>  $base  Predicate-applied, aliased `r`, unordered.
     * @param  list<string>  $columns
     * @param  list<int>  $excludedReleaseIds
     * @return EloquentCollection<int, Release>
     */
    public static function claim(
        Builder $base,
        string $token,
        int $limit,
        array $columns = ['*'],
        array $excludedReleaseIds = [],
    ): EloquentCollection {
        $effectiveLimit = max(1, $limit);

        return DB::transaction(function () use ($base, $token, $effectiveLimit, $columns, $excludedReleaseIds): EloquentCollection {
            $supportsClaims = AdditionalCandidateQuery::supportsClaims();
            $query = $base
                ->select('r.id')
                ->orderByDesc('r.postdate')
                ->orderBy('r.id')
                ->limit($effectiveLimit);

            if ($excludedReleaseIds !== []) {
                $query->whereNotIn('r.id', $excludedReleaseIds);
            }

            if (DB::getDriverName() !== 'sqlite') {
                $query->lockForUpdate();
            }

            $ids = $query
                ->pluck('r.id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if ($ids === []) {
                return (new Release)->newCollection();
            }

            if ($supportsClaims) {
                Release::query()
                    ->whereIn('id', $ids)
                    ->update([
                        AdditionalCandidateQuery::CLAIMED_AT_COLUMN => now(),
                        AdditionalCandidateQuery::CLAIM_TOKEN_COLUMN => $token,
                    ]);
            }

            return Release::query()
                ->whereIn('id', $ids)
                ->select(self::selectableColumns($columns, $supportsClaims))
                ->orderByRaw(self::idOrderExpression($ids))
                ->get();
        }, 3);
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private static function selectableColumns(array $columns, bool $supportsClaims): array
    {
        if ($supportsClaims || $columns === ['*']) {
            return $columns;
        }

        return array_values(array_filter(
            $columns,
            static fn (string $column): bool => ! in_array($column, [
                AdditionalCandidateQuery::CLAIMED_AT_COLUMN,
                AdditionalCandidateQuery::CLAIM_TOKEN_COLUMN,
            ], true),
        ));
    }

    /**
     * @param  list<int>  $ids
     */
    private static function idOrderExpression(array $ids): string
    {
        if (DB::getDriverName() !== 'sqlite') {
            return 'FIELD(id, '.implode(',', $ids).')';
        }

        $cases = [];
        foreach ($ids as $position => $id) {
            $cases[] = 'WHEN '.(int) $id.' THEN '.(int) $position;
        }

        return 'CASE id '.implode(' ', $cases).' ELSE '.count($ids).' END';
    }
}
