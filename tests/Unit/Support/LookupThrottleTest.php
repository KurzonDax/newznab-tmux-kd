<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\LookupThrottle;
use PHPUnit\Framework\TestCase;

class LookupThrottleTest extends TestCase
{
    public function test_a_lookup_that_took_no_time_owes_the_whole_window(): void
    {
        $throttle = new LookupThrottle(1000);

        $this->assertSame(1_000_000, $throttle->remainingMicroseconds(10.0, 10.0));
    }

    public function test_a_sub_second_lookup_is_subtracted_from_the_window(): void
    {
        $throttle = new LookupThrottle(1000);

        $this->assertSame(700_000, $throttle->remainingMicroseconds(10.0, 10.3));
    }

    public function test_a_lookup_longer_than_the_window_owes_nothing(): void
    {
        $throttle = new LookupThrottle(1000);

        $this->assertSame(0, $throttle->remainingMicroseconds(10.0, 11.5));
    }

    public function test_a_sub_second_window_paces_every_quick_lookup(): void
    {
        $throttle = new LookupThrottle(250);

        // Consecutive lookups of 30ms, 60ms and 90ms, each starting on a different offset
        // within the wall-clock second. A whole-second elapsed measurement would alternate
        // between the full window and no wait at all as the second ticks over.
        $this->assertSame(220_000, $throttle->remainingMicroseconds(10.940, 10.970));
        $this->assertSame(190_000, $throttle->remainingMicroseconds(11.190, 11.250));
        $this->assertSame(160_000, $throttle->remainingMicroseconds(11.440, 11.530));
    }

    public function test_a_zero_window_never_waits(): void
    {
        $throttle = new LookupThrottle(0);

        $this->assertSame(0, $throttle->remainingMicroseconds(10.0, 10.0));
    }

    public function test_a_negative_window_never_waits(): void
    {
        $throttle = new LookupThrottle(-500);

        $this->assertSame(0, $throttle->remainingMicroseconds(10.0, 10.0));
    }

    public function test_marks_advance_monotonically_in_seconds(): void
    {
        $throttle = new LookupThrottle(1000);

        $first = $throttle->mark();
        usleep(20_000);
        $second = $throttle->mark();

        $this->assertGreaterThanOrEqual(0.019, $second - $first);
        $this->assertLessThan(1.0, $second - $first);
    }

    public function test_sleeping_out_the_window_paces_a_sub_second_interval(): void
    {
        $throttle = new LookupThrottle(200);
        $cycles = [];

        for ($cycle = 0; $cycle < 3; $cycle++) {
            $startedAt = $throttle->mark();
            usleep(150_000); // Stand in for the external lookup.
            $throttle->sleepSince($startedAt);
            $cycles[] = $throttle->mark() - $startedAt;
        }

        // Each cycle should cost the configured window, not the lookup plus a full window
        // (whole-second elapsed, second unchanged) or just the lookup (second ticked over).
        // The median absorbs a single scheduling stall without loosening the bounds.
        sort($cycles);

        $this->assertGreaterThanOrEqual(0.19, $cycles[1]);
        $this->assertLessThan(0.30, $cycles[1]);
    }

    public function test_no_waiting_happens_once_the_window_is_spent(): void
    {
        $throttle = new LookupThrottle(50);

        $startedAt = $throttle->mark() - 5.0;
        $before = $throttle->mark();
        $throttle->sleepSince($startedAt);

        $this->assertLessThan(0.05, $throttle->mark() - $before);
    }
}
