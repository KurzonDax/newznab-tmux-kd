<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Models\Release;
use App\Models\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NzbCreationCandidateQuery
{
    public const string CLAIMED_AT_COLUMN = 'nzb_creation_claimed_at';

    public const string CLAIM_TOKEN_COLUMN = 'nzb_creation_claim_token';

    private static ?bool $supportsClaims = null;

    private static ?bool $supportsFailureState = null;

    /**
     * @return Builder<Release>
     */
    public static function baseBuilder(int|string|null $groupID = null, bool $includeClaimed = false): Builder
    {
        $query = Release::query()
            ->from('releases as r')
            ->where('r.nzbstatus', NzbService::NZB_NONE);

        if ($groupID !== null && $groupID !== '' && $groupID !== 0 && $groupID !== '0') {
            $query->where('r.groups_id', $groupID);
        }

        if (! $includeClaimed) {
            self::applyClaimWindow($query);
        }

        return $query;
    }

    /**
     * @param  list<string>  $columns
     * @return EloquentCollection<int, Release>
     */
    public static function claimBatch(
        int|string|null $groupID,
        int $limit,
        string $token,
        array $columns = ['*'],
    ): EloquentCollection {
        $effectiveLimit = max(1, $limit);

        return DB::transaction(function () use ($groupID, $effectiveLimit, $token, $columns): EloquentCollection {
            $supportsClaims = self::supportsClaims();
            $query = self::baseBuilder($groupID)
                ->select('r.id')
                ->orderByDesc('r.postdate')
                ->orderBy('r.id')
                ->limit($effectiveLimit);

            $ids = $query
                ->pluck('r.id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if ($ids === []) {
                return (new Release)->newCollection();
            }

            if ($supportsClaims) {
                $stampIds = $ids;
                sort($stampIds, SORT_NUMERIC);

                Release::query()
                    ->whereIn('id', $stampIds)
                    ->where(function (Builder $claimQuery): void {
                        $claimQuery
                            ->whereNull(self::CLAIMED_AT_COLUMN)
                            ->orWhere(self::CLAIMED_AT_COLUMN, '<', now()->subSeconds(self::claimTtlSeconds()));
                    })
                    ->update([
                        self::CLAIMED_AT_COLUMN => now(),
                        self::CLAIM_TOKEN_COLUMN => $token,
                    ]);
            }

            $releaseQuery = Release::query()
                ->whereIn('id', $ids)
                ->with('category.parent')
                ->select(self::selectableColumns($columns, $supportsClaims))
                ->orderByRaw(self::idOrderExpression($ids));

            if ($supportsClaims) {
                $releaseQuery->where(self::CLAIM_TOKEN_COLUMN, $token);
            }

            if (self::supportsFailureState()) {
                $releaseQuery->with('nzbCreationFailure');
            }

            return $releaseQuery->get();
        }, 3);
    }

    public static function clearClaim(int $releaseId, ?string $token = null): void
    {
        if (! self::supportsClaims()) {
            return;
        }

        $query = Release::query()->where('id', $releaseId);
        if ($token !== null && $token !== '') {
            $query->where(self::CLAIM_TOKEN_COLUMN, $token);
        }

        $query->update([
            self::CLAIMED_AT_COLUMN => null,
            self::CLAIM_TOKEN_COLUMN => null,
        ]);
    }

    /**
     * @return Builder<Release>
     */
    public static function ownedPendingBuilder(int $releaseId, ?string $token): Builder
    {
        $query = Release::query()
            ->where('id', $releaseId)
            ->where('nzbstatus', NzbService::NZB_NONE);

        if (! self::supportsClaims()) {
            return $query;
        }

        if ($token === null) {
            return $query->whereNull(self::CLAIM_TOKEN_COLUMN);
        }

        return $query->where(self::CLAIM_TOKEN_COLUMN, $token);
    }

    public static function refreshClaim(int $releaseId, string $token): bool
    {
        if (! self::supportsClaims()) {
            return self::ownedPendingBuilder($releaseId, null)->exists();
        }

        return self::ownedPendingBuilder($releaseId, $token)->update([
            self::CLAIMED_AT_COLUMN => now(),
        ]) === 1;
    }

    public static function supportsClaims(): bool
    {
        if (self::$supportsClaims !== null) {
            return self::$supportsClaims;
        }

        if (! Schema::hasTable('releases')) {
            return self::$supportsClaims = false;
        }

        return self::$supportsClaims = Schema::hasColumn('releases', self::CLAIMED_AT_COLUMN)
            && Schema::hasColumn('releases', self::CLAIM_TOKEN_COLUMN);
    }

    public static function supportsFailureState(): bool
    {
        return self::$supportsFailureState ??= Schema::hasTable('release_nzb_creation_failures');
    }

    /**
     * Discard the memoized schema capability flags. Only needed when the schema
     * changes inside a single process, such as between tests.
     */
    public static function flushCapabilityCache(): void
    {
        self::$supportsClaims = null;
        self::$supportsFailureState = null;
    }

    /**
     * @param  Builder<Release>  $query
     */
    private static function applyClaimWindow(Builder $query): void
    {
        if (! self::supportsClaims()) {
            return;
        }

        $staleBefore = now()->subSeconds(self::claimTtlSeconds());

        $query->where(function (Builder $claimQuery) use ($staleBefore): void {
            $claimQuery
                ->whereNull('r.'.self::CLAIMED_AT_COLUMN)
                ->orWhere('r.'.self::CLAIMED_AT_COLUMN, '<', $staleBefore);
        });
    }

    private static function claimTtlSeconds(): int
    {
        $timeout = (int) (Settings::settingValue('releaseprocessingtimeout') ?: 120);

        return max(300, $timeout * 2);
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
                self::CLAIMED_AT_COLUMN,
                self::CLAIM_TOKEN_COLUMN,
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
