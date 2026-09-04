<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Paces the per-release external metadata lookups the book and console passes make.
 *
 * The `amazonsleep` setting caps how often a worker may call out: after a release that hit an
 * external provider, the worker waits out whatever is left of the configured window. Elapsed
 * time is read from the monotonic clock in float seconds, so a sub-second window such as
 * 250ms paces accurately instead of quantising to whole seconds -- a whole-second measurement
 * alternates between the full window and no wait at all as the wall-clock second ticks over.
 */
final class LookupThrottle
{
    public function __construct(private readonly int $intervalMilliseconds) {}

    /**
     * Monotonic mark, in float seconds, to measure a lookup against.
     */
    public function mark(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    /**
     * Microseconds still owed on the window opened at $startedAt; zero once it is spent.
     */
    public function remainingMicroseconds(float $startedAt, ?float $now = null): int
    {
        $elapsedMicroseconds = (int) round((($now ?? $this->mark()) - $startedAt) * 1_000_000);

        return max(0, $this->intervalMilliseconds * 1_000 - $elapsedMicroseconds);
    }

    /**
     * Wait out the remainder of the window opened at $startedAt.
     */
    public function sleepSince(float $startedAt): void
    {
        $remaining = $this->remainingMicroseconds($startedAt);

        if ($remaining > 0) {
            usleep($remaining);
        }
    }
}
