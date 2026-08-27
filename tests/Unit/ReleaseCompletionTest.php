<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ReleaseRepairOutcome;
use App\Support\ReleaseCompletion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReleaseCompletionTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function percentProvider(): array
    {
        return [
            'complete' => [100, 100],
            'almost complete never rounds up' => [99.7, 99],
            'green boundary' => [95, 95],
            'just below green' => [94.99, 94],
            'yellow boundary' => [80, 80],
            'just below yellow' => [79.99, 79],
            'sentinel' => [0, 0],
            'string value' => ['87.5', 87],
            'null' => [null, 0],
        ];
    }

    #[DataProvider('percentProvider')]
    public function test_it_floors_the_displayed_percent(mixed $completion, int $expected): void
    {
        $this->assertSame($expected, ReleaseCompletion::percent($completion));
    }

    public function test_zero_means_never_measured(): void
    {
        $this->assertFalse(ReleaseCompletion::isMeasured(0));
        $this->assertFalse(ReleaseCompletion::isMeasured(null));
        $this->assertFalse(ReleaseCompletion::isMeasured('not a number'));
        $this->assertTrue(ReleaseCompletion::isMeasured(0.5));
        $this->assertTrue(ReleaseCompletion::isMeasured(100));
    }

    public function test_only_a_measured_incomplete_release_has_a_repair_state(): void
    {
        $this->assertFalse(ReleaseCompletion::isIncomplete(0));
        $this->assertFalse(ReleaseCompletion::isIncomplete(100));
        $this->assertTrue(ReleaseCompletion::isIncomplete(99.7));
        $this->assertTrue(ReleaseCompletion::isIncomplete(5));
    }

    public function test_repair_is_complete_only_when_both_machines_are_final(): void
    {
        $this->assertSame(
            ReleaseCompletion::PENDING_LABEL,
            ReleaseCompletion::repairLabel(null, null),
            'No verdict yet is pending.'
        );
        $this->assertSame(
            ReleaseCompletion::PENDING_LABEL,
            ReleaseCompletion::repairLabel(ReleaseRepairOutcome::Failed->value, null),
            'Only one machine final is pending.'
        );
        $this->assertSame(
            ReleaseCompletion::PENDING_LABEL,
            ReleaseCompletion::repairLabel(ReleaseRepairOutcome::RetryPending->value, ReleaseRepairOutcome::Failed->value),
            'A retry still owed is pending.'
        );
        $this->assertSame(
            ReleaseCompletion::PENDING_LABEL,
            ReleaseCompletion::repairLabel(ReleaseRepairOutcome::Repaired->value, ReleaseRepairOutcome::Failed->value),
            'A successful repair is not an exhausted one.'
        );
        $this->assertSame(
            ReleaseCompletion::COMPLETE_LABEL,
            ReleaseCompletion::repairLabel(ReleaseRepairOutcome::Failed->value, ReleaseRepairOutcome::SkippedBudget->value)
        );
        $this->assertSame(
            ReleaseCompletion::COMPLETE_LABEL,
            ReleaseCompletion::repairLabel(ReleaseRepairOutcome::SkippedFloor, ReleaseRepairOutcome::Failed)
        );
    }

    public function test_unknown_outcome_values_are_never_treated_as_final(): void
    {
        $this->assertFalse(ReleaseCompletion::repairAttemptsExhausted('not-an-outcome', ReleaseRepairOutcome::Failed->value));
        $this->assertFalse(ReleaseCompletion::repairAttemptsExhausted('', ''));
    }

    public function test_thresholds_outside_the_menu_fall_back_to_all_releases(): void
    {
        $this->assertSame(0, ReleaseCompletion::normalizeThreshold(null));
        $this->assertSame(0, ReleaseCompletion::normalizeThreshold('nonsense'));
        $this->assertSame(0, ReleaseCompletion::normalizeThreshold(42));
        $this->assertSame(0, ReleaseCompletion::normalizeThreshold(-95));
        $this->assertSame(80, ReleaseCompletion::normalizeThreshold('80'));
        $this->assertSame(95, ReleaseCompletion::normalizeThreshold(95));
        $this->assertSame(100, ReleaseCompletion::normalizeThreshold(100));
    }
}
