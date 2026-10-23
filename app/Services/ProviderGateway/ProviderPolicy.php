<?php

declare(strict_types=1);

namespace App\Services\ProviderGateway;

/**
 * One provider's pacing, budget, retry and breaker settings, resolved for one call by
 * {@see ProviderPolicies}.
 */
final readonly class ProviderPolicy
{
    /**
     * @param  list<string>|null  $paceHosts
     * @param  list<int>  $retryStatuses
     * @param  list<int>  $unavailableStatuses
     */
    public function __construct(
        public string $provider,
        public int $minIntervalMs = 0,
        public bool $sharedPace = true,
        public bool $skipWhenBusy = false,
        public ?array $paceHosts = null,
        public bool $serial = false,
        public string $serialLock = '',
        public int $serialLockSeconds = 0,
        public int $lockWaitSeconds = 10,
        public int $holdOn429Seconds = 0,
        public ?int $budgetMax = null,
        public int $budgetWindowSeconds = 60,
        public string $budgetKey = '',
        public int $attempts = 1,
        public int $backoffMs = 0,
        public float $multiplier = 1.0,
        public bool $retryTransport = true,
        public bool $retryServerErrors = true,
        public array $retryStatuses = [429],
        public int $retryAfterMaxSeconds = 30,
        public ?int $defaultRetryAfterSeconds = null,
        public int $failureThreshold = 3,
        public ?int $openSeconds = 60,
        public string $breakerScope = 'shared',
        public bool $escalate = true,
        public array $unavailableStatuses = [],
        public ?string $ledgerHost = null,
    ) {}

    /** Whether requests to this host are paced. */
    public function pacesHost(?string $host): bool
    {
        return $this->paceHosts === null
            || ($host !== null && in_array(strtolower($host), $this->paceHosts, true));
    }

    /** The wait before retry attempt $attempt + 1, before any Retry-After. */
    public function backoffMilliseconds(int $attempt): int
    {
        return max(0, (int) round($this->backoffMs * ($this->multiplier ** ($attempt - 1))));
    }

    /** Whether a breaker counts failures at all (an open time of 0 turns it off). */
    public function hasBreaker(): bool
    {
        return $this->openSeconds === null || $this->openSeconds > 0;
    }
}
