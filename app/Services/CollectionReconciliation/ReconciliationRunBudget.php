<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use Closure;

/** A cooperative cycle bound, shared by publication resumes and pending candidates. */
final class ReconciliationRunBudget
{
    private int $processed = 0;

    private bool $stoppedEarly = false;

    private readonly float $deadline;

    private readonly Closure $clock;

    /** @param null|Closure(): float $clock */
    public function __construct(private readonly int $limit, float $seconds, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->deadline = ($this->clock)() + $seconds;
    }

    public function remainingSeconds(): float
    {
        return max(0.0, $this->deadline - ($this->clock)());
    }

    public function take(): bool
    {
        if ($this->processed >= $this->limit || ($this->clock)() >= $this->deadline) {
            $this->stoppedEarly = true;

            return false;
        }
        $this->processed++;

        return true;
    }

    /** @return array{processed: int, stopped_early: bool} */
    public function report(): array
    {
        return ['processed' => $this->processed, 'stopped_early' => $this->stoppedEarly];
    }
}
