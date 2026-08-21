<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\ReleaseProcessingService;

/**
 * Where a release stands in the recovery process that guards the completion sweep.
 *
 * The sweep in {@see ReleaseProcessingService::deleteIncompleteReleases()} may
 * only delete a release whose outcome is *final*. A release with no outcome at all has never
 * been offered to the repair engine and is not deletable either: measured-but-unrepaired is
 * not the same as unrecoverable.
 *
 * Two passes share these values, in two columns. `repair_outcome` tracks the segment repair
 * engine, which rebuilds files with at least one seen segment; `rescan_outcome` tracks the
 * header re-scan, which recovers files with none. Both must be final before the sweep may act.
 */
enum ReleaseRepairOutcome: string
{
    /** Repaired once without reaching the target; one more attempt is owed after the retry window. */
    case RetryPending = 'retry-pending';

    /** Repair brought the release up to the completion target. It lives. */
    case Repaired = 'repaired';

    /** Both attempts ran and neither reached the target. Reaper eligible. */
    case Failed = 'failed';

    /**
     * Looked at and dismissed without touching the network. Reaper eligible.
     *
     * For repair that means measured below the floor -- a release holding under a tenth of its
     * articles is dreck, not a header-scan miss. For the re-scan it means the release declares
     * no more files than it holds, so there is nothing to go looking for.
     */
    case SkippedFloor = 'skipped-floor';

    /**
     * The article window was wider than the re-scan is allowed to read. Reaper eligible.
     *
     * Final rather than retryable: the window is derived from the release's own article span and
     * the group's rate, neither of which will shrink on a later pass. Raising the ceiling in site
     * settings is what changes this answer, and doing so is a deliberate operator decision.
     */
    case SkippedBudget = 'skipped-budget';

    /**
     * Is this the end of the road for the release?
     *
     * Only final outcomes are deletable. `retry-pending` and `repaired` are not: one is still
     * owed an attempt, the other succeeded.
     */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Failed, self::SkippedFloor, self::SkippedBudget => true,
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
            self::SkippedFloor => 'Skipped (nothing to recover)',
            self::SkippedBudget => 'Skipped (window too wide)',
        };
    }
}
