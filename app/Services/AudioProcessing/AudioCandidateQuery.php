<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing;

use App\Models\Release;
use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use App\Services\AdditionalProcessing\Config\PasswordInspectionMode;
use App\Services\AdditionalProcessing\ReleaseClaimant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Single source of truth for "needs audio postprocessing" release selection.
 *
 * The audio twin of {@see AdditionalCandidateQuery}: same claim columns, same
 * stale-claim window, same bucket fan-out, and the same rule that the scheduler
 * query and the per-worker fetch must both come through here so they cannot
 * drift apart.
 *
 * What differs is deliberate:
 *
 * - **No minimum size.** `minsizetopostprocess` defaults to 300 MB, which
 *   stranded 179 of the 180 pending releases in the three genuine music groups
 *   at `haspreview = -1` forever. A five megabyte single is a legitimate audio
 *   release. The global maximum still applies.
 * - **Audio routing.** {@see AudioRouting} decides which half of the pending set
 *   this query owns, and the video query applies the same rule inverted.
 */
final class AudioCandidateQuery
{
    /**
     * Hard cap on the bucket fan-out: `leftguid` is a single hex digit, so there
     * are at most 16 distinct buckets to dispatch per cycle.
     */
    public const int BUCKET_LIMIT = 16;

    /**
     * Apply the candidate-selection predicates to an Eloquent builder.
     *
     * The builder MUST already be aliased as `r` for releases.
     *
     * @param  Builder<Release>  $query
     * @return Builder<Release>
     */
    public static function applyPredicates(
        Builder $query,
        int|string $groupID = '',
        string $guidChar = '',
        ?int $maxSizeBytes = null,
        bool $includeClaimed = false,
    ): Builder {
        $max = $maxSizeBytes ?? AdditionalCandidateQuery::maxSizeBytes();

        $query
            ->where('r.passwordstatus', PasswordInspectionMode::pendingReleaseStatus())
            ->where('r.haspreview', -1)
            ->where('r.nzbstatus', 1);

        if ($max > 0) {
            $query->where('r.size', '<', $max);
        }
        if ($groupID !== '' && $groupID !== 0 && $groupID !== '0') {
            $query->where('r.groups_id', $groupID);
        }
        if ($guidChar !== '') {
            $query->where('r.leftguid', $guidChar);
        }
        if (! $includeClaimed) {
            self::applyClaimWindow($query);
        }

        AudioRouting::applyAudioPath($query);

        return $query;
    }

    /**
     * @return Builder<Release>
     */
    public static function baseBuilder(
        int|string $groupID = '',
        string $guidChar = '',
        ?int $maxSizeBytes = null,
        bool $includeClaimed = false,
    ): Builder {
        return self::applyPredicates(
            Release::query()->from('releases as r'),
            $groupID,
            $guidChar,
            $maxSizeBytes,
            $includeClaimed,
        );
    }

    /**
     * Available candidate counts keyed by GUID bucket, buckets with none omitted.
     *
     * @return list<array{bucket: string, count: int}>
     */
    public static function availableBucketCounts(): array
    {
        $counts = [];

        foreach (self::bucketBacklog() as $backlog) {
            if ($backlog['available'] > 0) {
                $counts[] = ['bucket' => $backlog['bucket'], 'count' => $backlog['available']];
            }
        }

        return array_slice($counts, 0, self::BUCKET_LIMIT);
    }

    /**
     * Total and currently claimable candidates for every GUID bucket.
     *
     * @return list<array{bucket: string, total: int, available: int}>
     */
    public static function bucketBacklog(): array
    {
        $query = self::baseBuilder(includeClaimed: true)
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
     * @return array{total: int, available: int}
     */
    public static function backlogCounts(): array
    {
        $query = self::baseBuilder(includeClaimed: true)->selectRaw('COUNT(*) AS total_count');
        self::selectAvailableCount($query);

        /** @var object{total_count: int|string|null, available_count: int|string|null}|null $counts */
        $counts = $query->toBase()->first();

        return [
            'total' => (int) ($counts->total_count ?? 0),
            'available' => (int) ($counts->available_count ?? 0),
        ];
    }

    /**
     * Claim a bounded batch of release rows for one worker.
     *
     * @param  list<string>  $columns
     * @param  list<int>  $excludedReleaseIds
     * @return EloquentCollection<int, Release>
     */
    public static function claimBatch(
        string $guidChar,
        int $limit,
        string $token,
        int|string $groupID = '',
        ?int $maxSizeBytes = null,
        array $columns = ['*'],
        array $excludedReleaseIds = [],
    ): EloquentCollection {
        return ReleaseClaimant::claim(
            self::baseBuilder($groupID, $guidChar, $maxSizeBytes),
            $token,
            $limit,
            $columns,
            $excludedReleaseIds,
        );
    }

    /**
     * Hand a release to the video path and make this query stop offering it.
     *
     * Called when the article-1 probe finds video, or finds no audio stream at
     * all. See {@see AudioRouting::DECLINED_TOKEN} for why this is a token rather
     * than a column.
     */
    public static function declineToVideoPath(int $releaseId): void
    {
        if (! AdditionalCandidateQuery::supportsClaims()) {
            return;
        }

        Release::query()->where('id', $releaseId)->update([
            AdditionalCandidateQuery::CLAIMED_AT_COLUMN => null,
            AdditionalCandidateQuery::CLAIM_TOKEN_COLUMN => AudioRouting::DECLINED_TOKEN,
        ]);
    }

    /**
     * Release a claim without declining the release, so the next cycle can retry.
     */
    public static function clearClaim(int $releaseId, ?string $token = null): void
    {
        AdditionalCandidateQuery::clearClaim($releaseId, $token);
    }

    /**
     * @param  Builder<Release>  $query
     */
    private static function selectAvailableCount(Builder $query): void
    {
        if (! AdditionalCandidateQuery::supportsClaims()) {
            $query->selectRaw('COUNT(*) AS available_count');

            return;
        }

        $claimedAt = 'r.'.AdditionalCandidateQuery::CLAIMED_AT_COLUMN;
        $query->selectRaw(
            'SUM(CASE WHEN '.$claimedAt.' IS NULL OR '.$claimedAt.' < ? THEN 1 ELSE 0 END) AS available_count',
            [AdditionalCandidateQuery::claimStaleBefore()],
        );
    }

    /**
     * @param  Builder<Release>  $query
     */
    private static function applyClaimWindow(Builder $query): void
    {
        if (! AdditionalCandidateQuery::supportsClaims()) {
            return;
        }

        $staleBefore = AdditionalCandidateQuery::claimStaleBefore();

        $query->where(function (Builder $claimQuery) use ($staleBefore): void {
            $claimQuery
                ->whereNull('r.'.AdditionalCandidateQuery::CLAIMED_AT_COLUMN)
                ->orWhere('r.'.AdditionalCandidateQuery::CLAIMED_AT_COLUMN, '<', $staleBefore);
        });
    }
}
