<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Models\Release;
use App\Models\Settings;
use App\Services\AdditionalProcessing\Config\PasswordInspectionMode;
use App\Services\AudioProcessing\AudioCandidateQuery;
use App\Services\AudioProcessing\AudioRouting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Everything the two candidate queries agree about.
 *
 * {@see AdditionalCandidateQuery} and {@see AudioCandidateQuery}
 * differ only in which releases they consider — the audio one drops the minimum
 * size, and {@see AudioRouting} splits the set
 * between them. What "pending" means, how a worker takes ownership, when a claim
 * goes stale, and how the bucket backlog is counted are identical, so they live
 * here and neither query may restate them.
 *
 * That rule is not stylistic. The two queries inside the *additional* path were
 * once maintained independently, and a mismatch on size filters and nzbstatus
 * had the bucket query advertising releases the per-worker fetch then rejected,
 * accumulating forever in the pending backlog. Two paths make that failure twice
 * as easy to reintroduce.
 */
final class ReleaseClaimant
{
    /**
     * Hard cap on the bucket fan-out. `leftguid` is the first character of a hex
     * GUID, so there are at most 16 distinct values (0-9, a-f), and no reason to
     * dispatch more buckets than that per scheduler cycle.
     */
    public const int BUCKET_LIMIT = 16;

    public const string CLAIMED_AT_COLUMN = 'additional_pp_claimed_at';

    public const string CLAIM_TOKEN_COLUMN = 'additional_pp_claim_token';

    private static ?bool $supportsClaims = null;

    /**
     * The predicates that make a release pending for *either* path: waiting on a
     * password verdict, owed a preview, and holding a usable NZB.
     *
     * @param  Builder<Release>  $query  Aliased `r`.
     * @return Builder<Release>
     */
    public static function applyPendingPredicates(
        Builder $query,
        int|string $groupID = '',
        string $guidChar = '',
        int $minSizeBytes = 0,
        int $maxSizeBytes = 0,
    ): Builder {
        $query
            ->where('r.passwordstatus', PasswordInspectionMode::pendingReleaseStatus())
            ->where('r.haspreview', -1)
            ->where('r.nzbstatus', 1);

        if (Schema::hasColumn('releases', 'pp_timeout_count')) {
            $query->where('r.pp_timeout_count', '<', self::maxPpTimeoutCount());
        }

        if ($minSizeBytes > 0) {
            $query->where('r.size', '>', $minSizeBytes);
        }
        if ($maxSizeBytes > 0) {
            $query->where('r.size', '<', $maxSizeBytes);
        }
        if ($groupID !== '' && $groupID !== 0 && $groupID !== '0') {
            $query->where('r.groups_id', $groupID);
        }
        if ($guidChar !== '') {
            $query->where('r.leftguid', $guidChar);
        }

        return $query;
    }

    public static function maxPpTimeoutCount(): int
    {
        return max(1, (int) (Settings::settingValue('maxpptimeoutcount') ?: 3));
    }

    /**
     * Exclude releases another worker is currently holding.
     *
     * @param  Builder<Release>  $query
     */
    public static function applyClaimWindow(Builder $query): void
    {
        if (! self::supportsClaims()) {
            return;
        }

        $staleBefore = self::claimStaleBefore();

        $query->where(function (Builder $claimQuery) use ($staleBefore): void {
            $claimQuery
                ->whereNull('r.'.self::CLAIMED_AT_COLUMN)
                ->orWhere('r.'.self::CLAIMED_AT_COLUMN, '<', $staleBefore);
        });
    }

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
            $supportsClaims = self::supportsClaims();
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
                        self::CLAIMED_AT_COLUMN => now(),
                        self::CLAIM_TOKEN_COLUMN => $token,
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
     * Total and currently claimable candidates for every GUID bucket.
     *
     * @param  callable(): Builder<Release>  $builder  Produces a fresh claimed-inclusive base.
     * @return list<array{bucket: string, total: int, available: int}>
     */
    public static function bucketBacklog(callable $builder): array
    {
        $query = $builder()
            ->select('r.leftguid')
            ->selectRaw('COUNT(*) AS total_count')
            ->groupBy('r.leftguid')
            ->orderBy('r.leftguid');

        self::selectAvailableCount($query);

        $backlog = [];
        foreach ($query->get() as $row) {
            $bucket = strtolower(substr((string) ($row->leftguid ?? ''), 0, 1));
            if ($bucket === '') {
                continue;
            }

            $backlog[] = [
                'bucket' => $bucket,
                'total' => (int) ($row->total_count ?? 0),
                'available' => (int) ($row->available_count ?? 0),
            ];
        }

        return $backlog;
    }

    /**
     * @param  callable(): Builder<Release>  $builder  Produces a fresh claimed-inclusive base.
     * @return array{total: int, available: int}
     */
    public static function backlogCounts(callable $builder): array
    {
        $query = $builder()->selectRaw('COUNT(*) AS total_count');
        self::selectAvailableCount($query);

        /** @var object{total_count: int|string|null, available_count: int|string|null}|null $counts */
        $counts = $query->toBase()->first();

        return [
            'total' => (int) ($counts->total_count ?? 0),
            'available' => (int) ($counts->available_count ?? 0),
        ];
    }

    /**
     * Buckets holding at least one claimable candidate, capped at the fan-out.
     *
     * @param  list<array{bucket: string, total: int, available: int}>  $backlog
     * @return list<array{bucket: string, count: int}>
     */
    public static function availableBucketCounts(array $backlog): array
    {
        $counts = [];

        foreach ($backlog as $bucket) {
            if ($bucket['available'] > 0) {
                $counts[] = ['bucket' => $bucket['bucket'], 'count' => $bucket['available']];
            }
        }

        return array_slice($counts, 0, self::BUCKET_LIMIT);
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
     * @return array<string, null>
     */
    public static function claimResetValues(): array
    {
        if (! self::supportsClaims()) {
            return [];
        }

        return [
            self::CLAIMED_AT_COLUMN => null,
            self::CLAIM_TOKEN_COLUMN => null,
        ];
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

    public static function claimTtlSeconds(): int
    {
        $timeout = (int) (Settings::settingValue('releaseprocessingtimeout') ?: 120);

        return max(300, $timeout * 2);
    }

    public static function claimStaleBefore(): Carbon
    {
        return now()->subSeconds(self::claimTtlSeconds());
    }

    /**
     * @param  Builder<Release>  $query
     */
    private static function selectAvailableCount(Builder $query): void
    {
        if (! self::supportsClaims()) {
            $query->selectRaw('COUNT(*) AS available_count');

            return;
        }

        $claimedAt = 'r.'.self::CLAIMED_AT_COLUMN;
        $query->selectRaw(
            'SUM(CASE WHEN '.$claimedAt.' IS NULL OR '.$claimedAt.' < ? THEN 1 ELSE 0 END) AS available_count',
            [self::claimStaleBefore()],
        );
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
