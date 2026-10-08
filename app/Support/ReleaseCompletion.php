<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Nzb\NzbService;
use App\Services\Releases\LateHeaderMerger;

/**
 * How a release's stored completion percentage is read on user-facing pages.
 *
 * `releases.completion` is the share of the release's articles the indexer has
 * actually seen, and `0` is its "never measured" sentinel rather than an empty
 * release. A release stuck at 5% looks identical to a healthy one everywhere in
 * the UI unless the number is shown, so the chips, the details rows, and the
 * threshold filter all read it through this one place.
 *
 * The pending state answers one question: can late headers still add to this release?
 * {@see self::stillRepairing()} is the only place the chips read it from, and it says yes only
 * while a secondary provider may still be reading the post.
 */
final class ReleaseCompletion
{
    /** Query parameter the browse/search toolbar carries the chosen threshold in. */
    public const string REQUEST_KEY = 'minc';

    /** Menu of minimum-completion thresholds offered in the browse/search toolbar. */
    public const array THRESHOLDS = [
        0 => 'All releases',
        80 => 'At least 80%',
        95 => 'At least 95%',
        100 => '100% only',
    ];

    /** The legacy chip's words while late headers may still arrive; it shows no chip otherwise. */
    public const string PENDING_LABEL = 'Late Headers Pending';

    /** `0` means the release was never measured, so no chip is shown for it. */
    public static function isMeasured(mixed $completion): bool
    {
        return self::value($completion) > 0.0;
    }

    /**
     * The displayed percent, floored so a release short of complete never reads "100%".
     */
    public static function percent(mixed $completion): int
    {
        return (int) floor(max(0.0, min(100.0, self::value($completion))));
    }

    /**
     * Is the release measured but not complete? Only then is its repair state meaningful.
     */
    public static function isIncomplete(mixed $completion): bool
    {
        $value = self::value($completion);

        return $value > 0.0 && $value < 100.0;
    }

    /**
     * May a secondary provider's late-header merge still add to this release
     * ({@see LateHeaderMerger})? The chip reads "Late Headers Pending" exactly while this is true:
     * an NZB exists, the release is measured above 0 and below 100, and an enabled secondary
     * provider's position has not passed the post plus `delaytime`. The positions come from the
     * request-scoped {@see ReleaseRepairingContext}; this class keeps no state of its own.
     *
     * @param  array<string, mixed>|object  $release  carrying `completion`, `nzbstatus`, `groups_id` and `postdate`
     */
    public static function stillRepairing(array|object $release): bool
    {
        $completion = self::value(self::column($release, 'completion'));
        if ((int) self::column($release, 'nzbstatus') !== NzbService::NZB_ADDED || $completion <= 0.0 || $completion >= 100.0) {
            return false;
        }

        return app(ReleaseRepairingContext::class)->secondaryStillReading(self::column($release, 'groups_id'), self::column($release, 'postdate'));
    }

    /**
     * Clamp a requested minimum-completion threshold onto the offered menu.
     */
    public static function normalizeThreshold(mixed $threshold): int
    {
        $value = is_numeric($threshold) ? (int) $threshold : 0;

        return \array_key_exists($value, self::THRESHOLDS) ? $value : 0;
    }

    /** @param array<string, mixed>|object $release */
    private static function column(array|object $release, string $name): mixed
    {
        return is_array($release) ? ($release[$name] ?? null) : ($release->{$name} ?? null);
    }

    private static function value(mixed $completion): float
    {
        return is_numeric($completion) ? (float) $completion : 0.0;
    }
}
