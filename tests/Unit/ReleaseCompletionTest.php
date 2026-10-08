<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ReleaseCompletion;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\ReleaseRepairingContextTest;
use Tests\TestCase;

/**
 * The rule the completion chip reads: a release is pending only while a secondary provider's
 * late-header merge can still add to it. The provider
 * half of the rule has its own test ({@see ReleaseRepairingContextTest}); here
 * the test environment configures provider 1 alone, so no cursor is ever read.
 */
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

    public function test_without_a_secondary_provider_no_release_is_pending(): void
    {
        $this->assertFalse(ReleaseCompletion::stillRepairing($this->row(['completion' => 99])), 'At or above the default target of 95.');
        $this->assertFalse(ReleaseCompletion::stillRepairing($this->row(['completion' => 95])), 'Exactly the target.');
        $this->assertFalse(ReleaseCompletion::stillRepairing($this->row(['completion' => 94.99])));
        $this->assertFalse(ReleaseCompletion::stillRepairing($this->row(['completion' => 100])));
        $this->assertFalse(ReleaseCompletion::stillRepairing($this->row(['completion' => 0])), 'Never measured.');
        $this->assertFalse(ReleaseCompletion::stillRepairing($this->row(['nzbstatus' => 0])), 'No NZB yet.');
        $this->assertFalse(ReleaseCompletion::stillRepairing($this->row(['nzbstatus' => null])));
    }

    public function test_the_rule_reads_an_array_row_as_it_reads_an_object(): void
    {
        $this->assertFalse(ReleaseCompletion::stillRepairing((array) $this->row()));
        $this->assertFalse(ReleaseCompletion::stillRepairing((array) $this->row(['completion' => 99])));
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

    /** An 80% release with an NZB. */
    private function row(array $overrides = []): object
    {
        return (object) [
            'completion' => 80,
            'nzbstatus' => 1, 'groups_id' => 1, 'postdate' => '2026-10-01 10:00:00', ...$overrides,
        ];
    }
}
