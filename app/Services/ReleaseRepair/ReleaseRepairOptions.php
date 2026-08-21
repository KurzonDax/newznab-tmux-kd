<?php

declare(strict_types=1);

namespace App\Services\ReleaseRepair;

use App\Models\Settings;
use App\Support\SettingNumber;

/**
 * The knobs one repair run works to.
 *
 * Every value has a seeded `settings` row behind it, edited on the admin Usenet Settings section
 * next to `completionpercent`, `delaytime` and the part-repair knobs it works alongside. An
 * operator tuning a scheduled job edits site settings; they do not edit the scheduler entry.
 * The constants below stay as the fallback for a row that is missing or non-numeric, and CLI
 * flags stay as explicit per-run overrides that beat both.
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

    public int $statSamplePerFile;

    public int $maxStatProbes;

    public function __construct(
        public float $targetCompletion = self::DEFAULT_TARGET_COMPLETION,
        public float $floorCompletion = self::REPAIR_FLOOR_COMPLETION,
        public int $retryAfterHours = self::RETRY_AFTER_HOURS,
        int $statSamplePerFile = self::STAT_SAMPLE_PER_FILE,
        int $maxStatProbes = self::MAX_STAT_PROBES,
        public bool $dryRun = false,
    ) {
        $this->statSamplePerFile = max(1, $statSamplePerFile);

        // A per-release ceiling below the per-file sample would leave every file under-sampled,
        // and verification only means anything at full sample size.
        $this->maxStatProbes = max($this->statSamplePerFile, $maxStatProbes);
    }

    /** Releases one invocation works on. */
    public const int DEFAULT_LIMIT = 250;

    /**
     * The configured completion threshold, falling back to the default when the sweep is off.
     */
    public static function targetFromSettings(): float
    {
        $configured = (float) Settings::settingValue('completionpercent');

        return $configured > 0 ? $configured : self::DEFAULT_TARGET_COMPLETION;
    }

    /**
     * Build a run's options from site settings, with per-run overrides winning where given.
     *
     * The overrides are the CLI flags: `null` means "the flag was not passed", not "zero".
     */
    public static function fromSettings(
        ?float $targetCompletion = null,
        ?float $floorCompletion = null,
        ?int $retryAfterHours = null,
        ?int $statSamplePerFile = null,
        ?int $maxStatProbes = null,
        bool $dryRun = false,
    ): self {
        return new self(
            targetCompletion: $targetCompletion ?? self::targetFromSettings(),
            floorCompletion: $floorCompletion ?? SettingNumber::float('repair_floor_completion', self::REPAIR_FLOOR_COMPLETION),
            retryAfterHours: $retryAfterHours ?? SettingNumber::int('repair_retry_after_hours', self::RETRY_AFTER_HOURS),
            statSamplePerFile: $statSamplePerFile ?? SettingNumber::int('repair_stat_sample_per_file', self::STAT_SAMPLE_PER_FILE),
            maxStatProbes: $maxStatProbes ?? SettingNumber::int('repair_max_stat_probes', self::MAX_STAT_PROBES),
            dryRun: $dryRun,
        );
    }

    /**
     * Releases one invocation works on, from settings unless the CLI overrode it.
     *
     * Not part of the options object: the batch size belongs to the candidate query, not to what
     * a single release's repair pass works to.
     */
    public static function limitFromSettings(?int $override = null): int
    {
        return max(1, $override ?? SettingNumber::int('repair_limit', self::DEFAULT_LIMIT));
    }
}
