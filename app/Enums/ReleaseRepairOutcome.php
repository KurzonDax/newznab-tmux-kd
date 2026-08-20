<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\ReleaseProcessingService;

/**
 * Where a release stands in the repair process that guards the completion sweep.
 *
 * The sweep in {@see ReleaseProcessingService::deleteIncompleteReleases()} may
 * only delete a release whose outcome is *final*. A release with no outcome at all has never
 * been offered to the repair engine and is not deletable either: measured-but-unrepaired is
 * not the same as unrecoverable.
 */
enum ReleaseRepairOutcome: string
{
    /** Repaired once without reaching the target; one more attempt is owed after the retry window. */
    case RetryPending = 'retry-pending';

    /** Repair brought the release up to the completion target. It lives. */
    case Repaired = 'repaired';

    /** Both attempts ran and neither reached the target. Reaper eligible. */
    case Failed = 'failed';

    /** Measured below the repair floor, so no articles were ever probed. Reaper eligible. */
    case SkippedFloor = 'skipped-floor';

    /**
     * Is this the end of the road for the release?
     *
     * Only final outcomes are deletable. `retry-pending` and `repaired` are not: one is still
     * owed an attempt, the other succeeded.
     */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Failed, self::SkippedFloor => true,
            self::RetryPending, self::Repaired => false,
        };
    }

    /**
     * The stored values the completion sweep is allowed to delete.
     *
     * @return list<string>
     */
    public static function deletableValues(): array
    {
        return array_values(array_map(
            static fn (self $case): string => $case->value,
            array_filter(self::cases(), static fn (self $case): bool => $case->isFinal()),
        ));
    }

    public function label(): string
    {
        return match ($this) {
            self::RetryPending => 'Retry pending',
            self::Repaired => 'Repaired',
            self::Failed => 'Failed',
            self::SkippedFloor => 'Skipped (below floor)',
        };
    }
}
