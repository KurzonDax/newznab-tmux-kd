<?php

declare(strict_types=1);

namespace App\Services\ReleaseRepair;

use App\Models\Settings;

/**
 * The knobs one repair run works to.
 */
final readonly class ReleaseRepairOptions
{
    /**
     * Completion a release must reach to count as repaired, when `completionpercent` is
     * disabled. The sweep is deliberately disarmed (`completionpercent = 0`) until the gate has
     * been proven in production, and a target of 0 would call every release repaired on sight.
     */
    public const float DEFAULT_TARGET_COMPLETION = 95.0;

    /**
     * Below this, a release skips network repair entirely.
     *
     * A release holding under a tenth of its articles is not a header-scan miss, it is dreck,
     * and it is not worth spending STATs to confirm that.
     */
    public const float REPAIR_FLOOR_COMPLETION = 10.0;

    /**
     * How long after a failed first attempt the final attempt may run.
     *
     * Fresh releases are stale-promoted at 8 hours and repaired within hours, while their
     * articles may still be propagating across the provider farm: a first attempt at hour 10 can
     * fail where a recheck at hour 82 succeeds. For legacy releases the second pass costs a few
     * STATs to confirm nothing changed, and one uniform rule beats branching on age.
     */
    public const int RETRY_AFTER_HOURS = 72;

    /** Synthesized IDs spot-checked per file. The ends of a range are the informative samples. */
    public const int STAT_SAMPLE_PER_FILE = 2;

    /** Hard ceiling on STAT probes for one release, however many files it has. */
    public const int MAX_STAT_PROBES = 20;

    public function __construct(
        public float $targetCompletion = self::DEFAULT_TARGET_COMPLETION,
        public float $floorCompletion = self::REPAIR_FLOOR_COMPLETION,
        public int $retryAfterHours = self::RETRY_AFTER_HOURS,
        public int $statSamplePerFile = self::STAT_SAMPLE_PER_FILE,
        public int $maxStatProbes = self::MAX_STAT_PROBES,
        public bool $dryRun = false,
    ) {}

    /**
     * The configured completion threshold, falling back to the default when the sweep is off.
     */
    public static function targetFromSettings(): float
    {
        $configured = (float) Settings::settingValue('completionpercent');

        return $configured > 0 ? $configured : self::DEFAULT_TARGET_COMPLETION;
    }
}
