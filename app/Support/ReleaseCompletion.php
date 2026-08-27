<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ReleaseRepairOutcome;

/**
 * How a release's stored completion percentage is read on user-facing pages.
 *
 * `releases.completion` is the share of the release's articles the indexer has
 * actually seen, and `0` is its "never measured" sentinel rather than an empty
 * release. A release stuck at 5% looks identical to a healthy one everywhere in
 * the UI unless the number is shown, so the chips, the details rows, and the
 * threshold filter all read it through this one place.
 *
 * The repair state answers a narrower question than the repair engine's own
 * enum: has the release run out of recovery attempts? That is true only when
 * both machines — segment repair and header rescan — have reached a final
 * outcome, the same conjunction the deletion sweep's safe-to-delete invariant
 * uses.
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

    public const string PENDING_LABEL = 'Repair Attempt(s) Pending';

    public const string COMPLETE_LABEL = 'Repair Attempts Complete';

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
     * Have both recovery machines reached a final outcome?
     *
     * Anything else — no verdict yet, a retry still owed, or only one machine
     * finished — is still pending.
     */
    public static function repairAttemptsExhausted(mixed $repairOutcome, mixed $rescanOutcome): bool
    {
        return self::isFinalOutcome($repairOutcome) && self::isFinalOutcome($rescanOutcome);
    }

    public static function repairLabel(mixed $repairOutcome, mixed $rescanOutcome): string
    {
        return self::repairAttemptsExhausted($repairOutcome, $rescanOutcome)
            ? self::COMPLETE_LABEL
            : self::PENDING_LABEL;
    }

    /**
     * Clamp a requested minimum-completion threshold onto the offered menu.
     */
    public static function normalizeThreshold(mixed $threshold): int
    {
        $value = is_numeric($threshold) ? (int) $threshold : 0;

        return \array_key_exists($value, self::THRESHOLDS) ? $value : 0;
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

    private static function value(mixed $completion): float
    {
        return is_numeric($completion) ? (float) $completion : 0.0;
    }
}
