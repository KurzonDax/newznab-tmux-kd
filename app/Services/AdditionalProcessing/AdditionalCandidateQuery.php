<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Models\Release;
use App\Models\Settings;
use App\Services\AudioProcessing\AudioRouting;
use App\Services\Runners\PostProcessRunner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;

/**
 * Single source of truth for "needs additional postprocessing" release selection.
 *
 * Both the bucket-fanout query in
 * {@see PostProcessRunner::processAdditional()} and the
 * per-worker release fetch in
 * {@see AdditionalProcessingOrchestrator::fetchReleases()}
 * MUST go through this class so their predicates can never drift apart.
 *
 * History: the two queries were maintained independently, and a mismatch on
 * size filters / nzbstatus caused releases to be advertised by the bucket
 * query but rejected by the orchestrator, accumulating forever in the
 * pending-password-status / haspreview=-1 backlog.
 */
final class AdditionalCandidateQuery
{
    /** Default size lower bound when the setting is empty/unset (bytes, 1 MB). */
    public const int DEFAULT_MIN_SIZE_BYTES = 1048576;

    /** Default size upper bound when the setting is empty/unset (bytes, 100 GB). */
    public const int DEFAULT_MAX_SIZE_BYTES = 107374182400;

    /**
     * Aliases of {@see ReleaseClaimant}'s, kept because callers across the
     * codebase already reach for them here. Per-cycle concurrency is governed by
     * the `postthreads` setting inside {@see PostProcessRunner::runPostProcess()},
     * so no separate fan-out setting is needed.
     */
    public const int BUCKET_LIMIT = ReleaseClaimant::BUCKET_LIMIT;

    public const string CLAIMED_AT_COLUMN = ReleaseClaimant::CLAIMED_AT_COLUMN;

    public const string CLAIM_TOKEN_COLUMN = ReleaseClaimant::CLAIM_TOKEN_COLUMN;

    /**
     * Resolve the minimum-size filter (bytes). Returns 0 when disabled.
     *
     * An explicit '0' setting means "no minimum size filter". An empty/null
     * setting falls back to {@see self::DEFAULT_MIN_SIZE_BYTES}.
     */
    public static function minSizeBytes(): int
    {
        $value = Settings::settingValue('minsizetopostprocess');
        if ($value === '' || $value === null) {
            return self::DEFAULT_MIN_SIZE_BYTES;
        }

        return max(0, (int) $value);
    }

    /**
     * Resolve the maximum-size filter (bytes). Returns 0 when disabled.
     *
     * An explicit '0' setting means "no maximum size filter". An empty/null
     * setting falls back to {@see self::DEFAULT_MAX_SIZE_BYTES}.
     */
    public static function maxSizeBytes(): int
    {
        $value = Settings::settingValue('maxsizetopostprocess');
        if ($value === '' || $value === null) {
            return self::DEFAULT_MAX_SIZE_BYTES;
        }

        return max(0, (int) $value);
    }

    /**
     * Apply the candidate-selection predicates to an Eloquent builder.
     *
     * The builder MUST already be aliased as `r` for releases. Optional
     * group / GUID-character constraints can be applied on top.
     *
     * @param  Builder<Release>  $query
     * @return Builder<Release>
     */
    public static function applyPredicates(
        Builder $query,
        int|string $groupID = '',
        string $guidChar = '',
        ?int $minSizeBytes = null,
        ?int $maxSizeBytes = null,
        bool $includeClaimed = false,
        bool $includePasswordStatuses = true,
    ): Builder {
        ReleaseClaimant::applyPendingPredicates(
            $query,
            $groupID,
            $guidChar,
            $minSizeBytes ?? self::minSizeBytes(),
            $maxSizeBytes ?? self::maxSizeBytes(),
            $includePasswordStatuses,
        );

        if (! $includeClaimed) {
            ReleaseClaimant::applyClaimWindow($query);
        }

        // The audio worker owns music-routed releases; this query owns the rest,
        // plus anything the audio worker probed and handed back.
        AudioRouting::applyVideoPath($query);

        return $query;
    }

    /**
     * Return a fresh Eloquent builder, aliased and predicate-applied, ready
     * for the orchestrator to add selects / order / limit.
     *
     * @return Builder<Release>
     */
    public static function baseBuilder(
        int|string $groupID = '',
        string $guidChar = '',
        ?int $minSizeBytes = null,
        ?int $maxSizeBytes = null,
        bool $includeClaimed = false,
        bool $includePasswordStatuses = true,
    ): Builder {
        $query = Release::query()->from('releases as r');

        return self::applyPredicates(
            $query,
            $groupID,
            $guidChar,
            $minSizeBytes,
            $maxSizeBytes,
            $includeClaimed,
            $includePasswordStatuses,
        );
    }

    /**
     * Return up to {@see self::BUCKET_LIMIT} distinct GUID first-characters
     * that have at least one release matching the candidate predicates.
     *
     * The fan-out is capped at 16 because `leftguid` is a single hex digit.
     * Worker concurrency is then capped further by the `postthreads` setting
     * in {@see PostProcessRunner::runPostProcess()}.
     *
     * @return array<int, string>
     */
    public static function bucketChars(?int $limit = null): array
    {
        $effectiveLimit = $limit !== null && $limit > 0
            ? min($limit, self::BUCKET_LIMIT)
            : self::BUCKET_LIMIT;

        return array_slice(
            array_column(self::availableBucketCounts(), 'bucket'),
            0,
            $effectiveLimit,
        );
    }

    /**
     * Return available candidate counts keyed by GUID bucket.
     *
     * @return list<array{bucket: string, count: int}>
     */
    public static function availableBucketCounts(): array
    {
        return ReleaseClaimant::availableBucketCounts(self::bucketBacklog());
    }

    /**
     * Return total and currently claimable candidates for every GUID bucket.
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
     * True when there is at least one candidate release anywhere (any char).
     * Used by the drain command to know when to stop looping.
     */
    public static function hasAnyCandidate(): bool
    {
        return self::baseBuilder()->limit(1)->exists();
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
        ?int $minSizeBytes = null,
        ?int $maxSizeBytes = null,
        array $columns = ['*'],
        array $excludedReleaseIds = [],
    ): EloquentCollection {
        return ReleaseClaimant::claim(
            self::baseBuilder(
                $groupID,
                $guidChar,
                $minSizeBytes,
                $maxSizeBytes,
                includePasswordStatuses: false,
            ),
            $token,
            $limit,
            $columns,
            $excludedReleaseIds,
        );
    }

    public static function clearClaim(int $releaseId, ?string $token = null): void
    {
        ReleaseClaimant::clearClaim($releaseId, $token);
    }

    /**
     * @return array<string, null>
     */
    public static function claimResetValues(): array
    {
        return ReleaseClaimant::claimResetValues();
    }

    public static function supportsClaims(): bool
    {
        return ReleaseClaimant::supportsClaims();
    }

    public static function claimTtlSeconds(): int
    {
        return ReleaseClaimant::claimTtlSeconds();
    }

    public static function claimStaleBefore(): Carbon
    {
        return ReleaseClaimant::claimStaleBefore();
    }
}
