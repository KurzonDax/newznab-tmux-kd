<?php

declare(strict_types=1);

namespace App\Services\ReleaseRepair;

use App\Support\SettingNumber;

/**
 * The knobs one header re-scan invocation works to.
 *
 * All four budgets are seeded `settings` rows edited on the admin Usenet Settings section, for
 * the same reason the repair engine's are: this is a recurring batch job, and an operator retunes
 * one from the admin UI rather than by editing however it is invoked. CLI flags override them for
 * one run only.
 *
 * The retry window and the completion target are deliberately *not* separate knobs. A release
 * either has been given up on or has not, and having two different definitions of "give up" for
 * the two passes that guard the same sweep would be a second thing to reason about for no gain.
 */
final readonly class MissingFileRescanOptions
{
    /** Releases one invocation works on. */
    public const int DEFAULT_LIMIT = 100;

    /** How far either side of the known article span to look, in posting time. */
    public const int DEFAULT_WINDOW_MINUTES = 30;

    /**
     * Widest article range worth reading for one release.
     *
     * A busy group over half an hour can span millions of articles for a single release. Reading
     * that costs far more than the release is worth, and it competes with live header scanning
     * for the primary provider's connections.
     */
    public const int DEFAULT_MAX_ARTICLES_PER_RELEASE = 500000;

    /** Overview lines one invocation may read before it stops, whatever is left in the batch. */
    public const int DEFAULT_MAX_ARTICLES_PER_RUN = 5000000;

    /** Articles per XOVER command, matching the header scan's own batching. */
    public const int DEFAULT_OVERVIEW_BATCH = 20000;

    public int $overviewBatchSize;

    public function __construct(
        public float $targetCompletion = ReleaseRepairOptions::DEFAULT_TARGET_COMPLETION,
        public int $retryAfterHours = ReleaseRepairOptions::RETRY_AFTER_HOURS,
        public int $windowMinutes = self::DEFAULT_WINDOW_MINUTES,
        public int $maxArticlesPerRelease = self::DEFAULT_MAX_ARTICLES_PER_RELEASE,
        public int $maxArticlesPerRun = self::DEFAULT_MAX_ARTICLES_PER_RUN,
        int $overviewBatchSize = self::DEFAULT_OVERVIEW_BATCH,
        public bool $dryRun = false,
    ) {
        $this->overviewBatchSize = $overviewBatchSize >= 1
            ? $overviewBatchSize
            : self::DEFAULT_OVERVIEW_BATCH;
    }

    /**
     * Build a run's options from site settings, with per-run overrides winning where given.
     */
    public static function fromSettings(
        ?float $targetCompletion = null,
        ?int $windowMinutes = null,
        ?int $maxArticlesPerRelease = null,
        ?int $maxArticlesPerRun = null,
        bool $dryRun = false,
    ): self {
        return new self(
            targetCompletion: $targetCompletion ?? ReleaseRepairOptions::targetFromSettings(),
            retryAfterHours: SettingNumber::int('repair_retry_after_hours', ReleaseRepairOptions::RETRY_AFTER_HOURS),
            windowMinutes: $windowMinutes ?? SettingNumber::int('rescan_window_minutes', self::DEFAULT_WINDOW_MINUTES),
            maxArticlesPerRelease: $maxArticlesPerRelease
                ?? SettingNumber::int('rescan_max_articles_per_release', self::DEFAULT_MAX_ARTICLES_PER_RELEASE),
            maxArticlesPerRun: $maxArticlesPerRun
                ?? SettingNumber::int('rescan_max_articles_per_run', self::DEFAULT_MAX_ARTICLES_PER_RUN),
            overviewBatchSize: SettingNumber::int('maxmssgs', self::DEFAULT_OVERVIEW_BATCH),
            dryRun: $dryRun,
        );
    }

    public static function limitFromSettings(?int $override = null): int
    {
        return max(1, $override ?? SettingNumber::int('rescan_limit', self::DEFAULT_LIMIT));
    }
}
