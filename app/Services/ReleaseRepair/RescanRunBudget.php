<?php

declare(strict_types=1);

namespace App\Services\ReleaseRepair;

/**
 * Overview lines one invocation may read, across every release in its batch.
 *
 * XOVER over a window is far more expensive than the repair engine's STAT probes, and it runs on
 * the primary provider -- the same connections live header scanning needs. The per-release
 * ceiling stops one pathological release; this stops a batch of merely large ones.
 *
 * Exhausting it ends the *fetching*, not the run: the releases already read are still written and
 * stamped, and the ones not reached keep their state and lead the next invocation's batch.
 */
final class RescanRunBudget
{
    private int $spent = 0;

    public function __construct(private readonly int $ceiling) {}

    public function spend(int $lines): void
    {
        $this->spent += max(0, $lines);
    }

    public function spent(): int
    {
        return $this->spent;
    }

    public function isExhausted(): bool
    {
        return $this->spent >= $this->ceiling;
    }
}
