<?php

declare(strict_types=1);

namespace App\Services\NNTP;

use Closure;

/**
 * Per-process circuit breaker for article operations.
 *
 * After {@see $failureThreshold} consecutive failures a provider is skipped for article
 * operations until {@see $cooldownSeconds} have elapsed. State is deliberately per-process:
 * a worker that trips a provider does not punish its siblings, and there is no shared store
 * to keep consistent.
 */
final class ProviderCircuitBreaker
{
    /** @var array<string, int> */
    private array $consecutiveFailures = [];

    /** @var array<string, float> */
    private array $openedAt = [];

    /** @var Closure(): float */
    private Closure $clock;

    /**
     * @param  (Closure(): float)|null  $clock  Monotonic-ish seconds source; injected in tests.
     */
    public function __construct(
        private readonly int $failureThreshold = 5,
        private readonly int $cooldownSeconds = 60,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public static function fromConfig(): self
    {
        return new self(
            failureThreshold: max(1, (int) config('nntmux_nntp.breaker.failure_threshold', 5)),
            cooldownSeconds: max(1, (int) config('nntmux_nntp.breaker.cooldown_seconds', 60)),
        );
    }

    /**
     * Is this provider currently being skipped for article operations?
     */
    public function isOpen(NntpProvider $provider): bool
    {
        $openedAt = $this->openedAt[$provider->name] ?? null;

        if ($openedAt === null) {
            return false;
        }

        if (($this->clock)() - $openedAt >= $this->cooldownSeconds) {
            // Cooldown expired: let the next attempt through. A failure re-trips immediately
            // because the failure counter is still at the threshold.
            unset($this->openedAt[$provider->name]);

            return false;
        }

        return true;
    }

    public function recordSuccess(NntpProvider $provider): void
    {
        unset($this->consecutiveFailures[$provider->name], $this->openedAt[$provider->name]);
    }

    public function recordFailure(NntpProvider $provider): void
    {
        $failures = ($this->consecutiveFailures[$provider->name] ?? 0) + 1;
        $this->consecutiveFailures[$provider->name] = $failures;

        if ($failures >= $this->failureThreshold) {
            $this->openedAt[$provider->name] = ($this->clock)();
        }
    }

    public function consecutiveFailures(NntpProvider $provider): int
    {
        return $this->consecutiveFailures[$provider->name] ?? 0;
    }

    public function reset(): void
    {
        $this->consecutiveFailures = [];
        $this->openedAt = [];
    }
}
