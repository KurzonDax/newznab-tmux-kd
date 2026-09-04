<?php

declare(strict_types=1);

namespace App\Support;

use Closure;

/**
 * Paces the per-release external metadata lookups the book and console passes make.
 *
 * The `amazonsleep` setting caps how often a worker may call out: a window opens as a release
 * is picked up, and a release that reached an external provider waits out whatever is left of
 * it. Elapsed time is read from the monotonic clock in float seconds, so a sub-second window
 * such as 250ms paces accurately instead of quantising to whole seconds -- a whole-second
 * measurement alternates between the full window and no wait at all as the second ticks over.
 */
final class LookupThrottle
{
    private ?float $windowOpenedAt = null;

    /** @var Closure(int): void */
    private readonly Closure $sleep;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /**
     * @param  (Closure(int): void)|null  $sleep
     * @param  (Closure(): float)|null  $clock
     */
    public function __construct(
        private readonly int $windowMilliseconds,
        ?Closure $sleep = null,
        ?Closure $clock = null,
    ) {
        $this->sleep = $sleep ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
    }

    /**
     * Open the window for the release about to be processed.
     */
    public function openWindow(): void
    {
        $this->windowOpenedAt = ($this->clock)();
    }

    /**
     * Wait out whatever the open window still owes.
     */
    public function waitOutWindow(): void
    {
        $remaining = $this->remainingMicroseconds();

        if ($remaining > 0) {
            ($this->sleep)($remaining);
        }
    }

    /**
     * Microseconds the open window still owes; zero once it is spent, or with no window open.
     */
    public function remainingMicroseconds(): int
    {
        if ($this->windowOpenedAt === null) {
            return 0;
        }

        $elapsedMicroseconds = (int) round((($this->clock)() - $this->windowOpenedAt) * 1_000_000);

        return max(0, $this->windowMilliseconds * 1_000 - $elapsedMicroseconds);
    }
}
