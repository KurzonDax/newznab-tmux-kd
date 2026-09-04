<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\LookupThrottle;
use PHPUnit\Framework\TestCase;

class LookupThrottleTest extends TestCase
{
    /** @var list<int> */
    private array $delays = [];

    /** @var list<float> */
    private array $clockValues = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->delays = [];
        $this->clockValues = [];
    }

    public function test_a_lookup_that_took_no_time_owes_the_whole_window(): void
    {
        $throttle = $this->throttle(1000, [10.0, 10.0]);
        $throttle->openWindow();

        $this->assertSame(1_000_000, $throttle->remainingMicroseconds());
    }

    public function test_a_sub_second_lookup_is_subtracted_from_the_window(): void
    {
        $throttle = $this->throttle(1000, [10.0, 10.3]);
        $throttle->openWindow();

        $this->assertSame(700_000, $throttle->remainingMicroseconds());
    }

    public function test_a_lookup_longer_than_the_window_owes_nothing(): void
    {
        $throttle = $this->throttle(1000, [10.0, 11.5]);
        $throttle->openWindow();

        $this->assertSame(0, $throttle->remainingMicroseconds());
    }

    public function test_a_sub_second_window_paces_every_quick_lookup(): void
    {
        // Lookups of 30ms, 60ms and 90ms, each opening on a different offset within the
        // wall-clock second. Measuring elapsed in whole seconds would alternate between the
        // full window and no wait at all as the second ticks over.
        $throttle = $this->throttle(250, [
            10.940, 10.970,
            11.190, 11.250,
            11.440, 11.530,
        ]);

        for ($lookup = 0; $lookup < 3; $lookup++) {
            $throttle->openWindow();
            $throttle->waitOutWindow();
        }

        $this->assertSame([220_000, 190_000, 160_000], $this->delays);
    }

    public function test_a_spent_window_is_not_waited_out(): void
    {
        $throttle = $this->throttle(250, [10.0, 12.0]);

        $throttle->openWindow();
        $throttle->waitOutWindow();

        $this->assertSame([], $this->delays);
    }

    public function test_a_window_that_was_never_opened_owes_nothing(): void
    {
        $throttle = $this->throttle(1000, [10.0]);

        $throttle->waitOutWindow();

        $this->assertSame(0, $throttle->remainingMicroseconds());
        $this->assertSame([], $this->delays);
    }

    public function test_a_zero_window_never_waits(): void
    {
        $throttle = $this->throttle(0, [10.0, 10.0]);

        $throttle->openWindow();
        $throttle->waitOutWindow();

        $this->assertSame([], $this->delays);
    }

    public function test_a_negative_window_never_waits(): void
    {
        $throttle = $this->throttle(-500, [10.0, 10.0]);

        $throttle->openWindow();
        $throttle->waitOutWindow();

        $this->assertSame([], $this->delays);
    }

    public function test_the_default_clock_and_sleep_pace_a_sub_second_window(): void
    {
        $throttle = new LookupThrottle(200);
        $cycles = [];

        for ($cycle = 0; $cycle < 3; $cycle++) {
            $startedAt = hrtime(true);
            $throttle->openWindow();
            usleep(150_000); // Stand in for the external lookup.
            $throttle->waitOutWindow();
            $cycles[] = (hrtime(true) - $startedAt) / 1_000_000_000;
        }

        // Each cycle costs the window, not the lookup plus a full window (whole-second
        // elapsed, second unchanged) or just the lookup (second ticked over). The median
        // absorbs a single scheduling stall without loosening the bounds.
        sort($cycles);

        $this->assertGreaterThanOrEqual(0.19, $cycles[1]);
        $this->assertLessThan(0.30, $cycles[1]);
    }

    /**
     * @param  list<float>  $clockValues  Readings the throttle's clock returns, in order.
     */
    private function throttle(int $windowMilliseconds, array $clockValues): LookupThrottle
    {
        $this->clockValues = $clockValues;

        return new LookupThrottle(
            $windowMilliseconds,
            function (int $microseconds): void {
                $this->delays[] = $microseconds;
            },
            function (): float {
                return array_shift($this->clockValues) ?? 0.0;
            },
        );
    }
}
