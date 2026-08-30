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
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PDO;

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

    /** @var list<int> */
    private const array PENDING_PASSWORD_STATUSES = [-1, 0];

    private static ?bool $supportsClaims = null;

    private static ?PDO $supportsClaimsPdo = null;

    private static ?string $supportsClaimsDatabase = null;

    /**
     * The predicates that make a release pending for *either* path: waiting on a
     * password verdict, owed a preview, and holding a usable NZB.
     *
     * @param  Builder<Release>  $query  Aliased `r`.
     * @param  bool  $includePasswordStatuses  Claim selection disables this so each index dive can add one equality.
     * @return Builder<Release>
     */
    public static function applyPendingPredicates(
        Builder $query,
        int|string $groupID = '',
        string $guidChar = '',
        int $minSizeBytes = 0,
        int $maxSizeBytes = 0,
        bool $includePasswordStatuses = true,
    ): Builder {
        if ($includePasswordStatuses) {
            $query->whereIn('r.passwordstatus', self::PENDING_PASSWORD_STATUSES);
        }

        $query
            ->where('r.haspreview', -1)
            ->where('r.nzbstatus', 1);

        if ($minSizeBytes > 0) {
            if (self::supportsClaims()) {
                $query->where(function (Builder $minimumSizeQuery) use ($minSizeBytes): void {
                    $minimumSizeQuery
                        ->where('r.size', '>', $minSizeBytes)
                        ->orWhere('r.'.self::CLAIM_TOKEN_COLUMN, AudioRouting::DECLINED_TOKEN);
                });
            } else {
                $query->where('r.size', '>', $minSizeBytes);
            }
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
     * Select up to $limit rows from $base with non-locking per-status reads,
     * stamp still-available rows, and return only the token's winners.
     *
     * @param  Builder<Release>  $base  Predicate-applied, aliased `r`, unordered, and without a password status predicate.
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
        if (self::hasPasswordStatusPredicate($base->getQuery())) {
            throw new LogicException('Claim base builders must not include a passwordstatus predicate.');
        }

        $effectiveLimit = max(1, $limit);

        return DB::transaction(function () use ($base, $token, $effectiveLimit, $columns, $excludedReleaseIds): EloquentCollection {
            $supportsClaims = self::supportsClaims();
            $candidates = collect();

            foreach (self::PENDING_PASSWORD_STATUSES as $passwordStatus) {
                $query = (clone $base)
                    ->select(['r.id', 'r.postdate'])
                    ->where('r.passwordstatus', $passwordStatus)
                    ->orderByDesc('r.postdate')
                    ->orderBy('r.id')
                    ->limit($effectiveLimit);

                if ($excludedReleaseIds !== []) {
                    $query->whereNotIn('r.id', $excludedReleaseIds);
                }

                $candidates->push(...$query->get());
            }

            $ids = $candidates
                ->sort(static function (Release $left, Release $right): int {
                    $postdateComparison = strcmp((string) ($right->postdate ?? ''), (string) ($left->postdate ?? ''));

                    return $postdateComparison !== 0
                        ? $postdateComparison
                        : (int) $left->id <=> (int) $right->id;
                })
                ->take($effectiveLimit)
                ->pluck('id')
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
                            ->orWhere(self::CLAIMED_AT_COLUMN, '<', self::claimStaleBefore());
                    })
                    ->update([
                        self::CLAIMED_AT_COLUMN => now(),
                        self::CLAIM_TOKEN_COLUMN => $token,
                    ]);
            }

            $winners = Release::query()->whereIn('id', $ids);
            if ($supportsClaims) {
                $winners->where(self::CLAIM_TOKEN_COLUMN, $token);
            }

            return $winners
                ->select(self::selectableColumns($columns, $supportsClaims))
                ->orderByRaw(self::idOrderExpression($ids))
                ->get();
        }, 3);
    }

    private static function hasPasswordStatusPredicate(QueryBuilder $query): bool
    {
        foreach ($query->wheres as $where) {
            $column = $where['column'] ?? null;
            if (is_string($column) && ($column === 'passwordstatus' || str_ends_with($column, '.passwordstatus'))) {
                return true;
            }

            $nestedQuery = $where['query'] ?? null;
            if ($nestedQuery instanceof QueryBuilder && self::hasPasswordStatusPredicate($nestedQuery)) {
                return true;
            }
        }

        return false;
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

    /**
     * Values shared by every writer that returns a release to pending.
     *
     * @return array<string, int|null>
     */
    public static function rependValues(): array
    {
        return array_merge([
            'passwordstatus' => PasswordInspectionMode::pendingReleaseStatus(),
            'haspreview' => -1,
        ], self::claimResetValues(), ['pp_timeout_count' => 0]);
    }

    /**
     * Values shared by both paths when processing reaches a terminal state.
     *
     * @return array<string, int|null>
     */
    public static function settlementValues(): array
    {
        return array_merge(self::claimResetValues(), ['pp_timeout_count' => 0]);
    }

    public static function supportsClaims(): bool
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $database = $connection->getName().':'.$connection->getDatabaseName();

        if (self::$supportsClaims !== null && self::$supportsClaimsPdo === $pdo && self::$supportsClaimsDatabase === $database) {
            return self::$supportsClaims;
        }

        self::$supportsClaimsPdo = $pdo;
        self::$supportsClaimsDatabase = $database;

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
