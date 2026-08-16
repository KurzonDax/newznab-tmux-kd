<?php

declare(strict_types=1);

namespace App\Services\NameFixing\Srrdb;

use Closure;

final class SrrdbRequestBudget
{
    private int $used = 0;

    private ?float $lastStartedAt = null;

    /** @var Closure(int): void */
    private readonly Closure $sleep;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /**
     * @param  (Closure(int): void)|null  $sleep
     * @param  (Closure(): float)|null  $clock
     */
    public function __construct(
        private readonly int $maximumRequests,
        private readonly float $requestsPerSecond,
        ?Closure $sleep = null,
        ?Closure $clock = null,
    ) {
        $this->sleep = $sleep ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function acquire(): bool
    {
        if ($this->used >= max(0, $this->maximumRequests)) {
            return false;
        }

        $now = ($this->clock)();
        if ($this->requestsPerSecond > 0 && $this->lastStartedAt !== null) {
            $minimumInterval = 1 / $this->requestsPerSecond;
            $remaining = $minimumInterval - ($now - $this->lastStartedAt);
            if ($remaining > 0) {
                ($this->sleep)((int) ceil($remaining * 1_000_000));
                $now = ($this->clock)();
            }
        }

        $this->lastStartedAt = $now;
        $this->used++;

        return true;
    }

    public function used(): int
    {
        return $this->used;
    }
}
