<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ReleaseRepairOutcome;
use App\Services\Nzb\NzbService;
use App\Services\Releases\IncompleteReleaseSweepQuery;
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
 * The repair state answers one question: can anything still add to this
 * release? {@see self::stillRepairing()} is the only place the chips read it
 * from, and it says yes only while a recovery engine would still select the
 * release, or a secondary provider may still be reading its post.
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

    /** The legacy chip's words while a release is still repairing; it shows no chip otherwise. */
    public const string PENDING_LABEL = 'Repair Attempt(s) Pending';

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
     * Is a recovery engine, or a secondary provider's late-header merge, still able to add to
     * this release? The chip reads "still repairing" exactly while this is true.
     *
     * Both engines select only releases with an NZB (`nzbstatus = 1`) measured above 0 and
     * strictly below the repair target, and they are done with one once segment repair is final
     * and the header re-scan is final or has nothing to look for: the deletion sweep's own
     * definition of finished ({@see IncompleteReleaseSweepQuery}). A
     * release at or above the target is never offered to either engine, so its outcomes stay
     * null forever and must not read as pending. Independently of both, a secondary provider's
     * late headers are merged into any incomplete release whose post its position has not yet
     * passed ({@see LateHeaderMerger}).
     *
     * The target, `delaytime` and the secondary positions come from the request-scoped
     * {@see ReleaseRepairingContext}; this class keeps no state of its own.
     *
     * @param  array<string, mixed>|object  $release  carrying `completion`, `repair_outcome`,
     *                                                `rescan_outcome`, `declaredfiles`, `totalpart`,
     *                                                `nzbstatus`, `groups_id` and `postdate`
     */
    public static function stillRepairing(array|object $release): bool
    {
        $completion = self::value(self::column($release, 'completion'));
        if ((int) self::column($release, 'nzbstatus') !== NzbService::NZB_ADDED || $completion <= 0.0 || $completion >= 100.0) {
            return false;
        }
        $context = app(ReleaseRepairingContext::class);
        if ($completion < $context->target() && ! self::recoveryExhausted($release)) {
            return true;
        }

        return $context->secondaryStillReading(self::column($release, 'groups_id'), self::column($release, 'postdate'));
    }

    /**
     * Clamp a requested minimum-completion threshold onto the offered menu.
     */
    public static function normalizeThreshold(mixed $threshold): int
    {
        $value = is_numeric($threshold) ? (int) $threshold : 0;

        return \array_key_exists($value, self::THRESHOLDS) ? $value : 0;
    }

    /**
     * The sweep's "finished" predicate: segment repair final, and the header re-scan final or
     * with nothing to look for. "Nothing to re-scan" is a derived `declaredfiles` saying so,
     * zero or no greater than the files held; null means the count was never derived and the
     * release is still owed a re-scan visit.
     *
     * @param  array<string, mixed>|object  $release
     */
    private static function recoveryExhausted(array|object $release): bool
    {
        if (! self::isFinalOutcome(self::column($release, 'repair_outcome'))) {
            return false;
        }
        if (self::isFinalOutcome(self::column($release, 'rescan_outcome'))) {
            return true;
        }
        $declared = self::column($release, 'declaredfiles');

        return $declared !== null && ((int) $declared <= 0 || (int) $declared <= (int) self::column($release, 'totalpart'));
    }

    private static function isFinalOutcome(mixed $outcome): bool
    {
        if ($outcome instanceof ReleaseRepairOutcome) {
            return $outcome->isFinal();
        }

        if (! is_string($outcome) || $outcome === '') {
            return false;
        }

        return ReleaseRepairOutcome::tryFrom($outcome)?->isFinal() ?? false;
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
