<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing;

use App\Models\Release;
use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
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
        bool $includePasswordStatuses = true,
    ): Builder {
        ReleaseClaimant::applyPendingPredicates(
            $query,
            $groupID,
            $guidChar,
            // No minimum: see the class docblock. The global maximum still applies.
            minSizeBytes: 0,
            maxSizeBytes: $maxSizeBytes ?? AdditionalCandidateQuery::maxSizeBytes(),
            includePasswordStatuses: $includePasswordStatuses,
        );

        if (! $includeClaimed) {
            ReleaseClaimant::applyClaimWindow($query);
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
        bool $includePasswordStatuses = true,
    ): Builder {
        return self::applyPredicates(
            Release::query()->from('releases as r'),
            $groupID,
            $guidChar,
            $maxSizeBytes,
            $includeClaimed,
            $includePasswordStatuses,
        );
    }

    /**
     * Available candidate counts keyed by GUID bucket, buckets with none omitted.
     *
     * @return list<array{bucket: string, count: int}>
     */
    public static function availableBucketCounts(): array
    {
        return ReleaseClaimant::availableBucketCounts(self::bucketBacklog());
    }

    /**
     * Total and currently claimable candidates for every GUID bucket.
     *
     * @return list<array{bucket: string, total: int, available: int}>
     */
    public static function bucketBacklog(): array
    {
        return ReleaseClaimant::bucketBacklog(static fn () => self::baseBuilder(includeClaimed: true));
    }

    /**
     * @return array{total: int, available: int}
     */
    public static function backlogCounts(): array
    {
        return ReleaseClaimant::backlogCounts(static fn () => self::baseBuilder(includeClaimed: true));
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
        return ReleaseClaimant::forAudioClaim($groupID, $guidChar, $maxSizeBytes)
            ->claim($token, $limit, $columns, $excludedReleaseIds);
    }

    /**
     * Hand a release to the video path and make this query stop offering it.
     *
     * Called when the article-1 probe finds video, or finds no audio stream at
     * all. See {@see AudioRouting::DECLINED_TOKEN} for why this is a token rather
     * than a column.
     *
     * @return bool False on an install predating the claim columns, where there
     *              is nowhere to record the decision. The caller must then settle
     *              the release itself rather than leave it to be probed again on
     *              every cycle forever.
     */
    public static function declineToVideoPath(int $releaseId): bool
    {
        if (! ReleaseClaimant::supportsClaims()) {
            return false;
        }

        Release::query()->where('id', $releaseId)->update([
            ReleaseClaimant::CLAIMED_AT_COLUMN => null,
            ReleaseClaimant::CLAIM_TOKEN_COLUMN => AudioRouting::DECLINED_TOKEN,
        ]);

        return true;
    }

    /**
     * Release a claim without declining the release, so the next cycle can retry.
     */
    public static function clearClaim(int $releaseId, ?string $token = null): void
    {
        ReleaseClaimant::clearClaim($releaseId, $token);
    }
}
