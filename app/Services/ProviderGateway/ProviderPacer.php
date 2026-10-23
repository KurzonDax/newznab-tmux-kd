<?php

declare(strict_types=1);

namespace App\Services\ProviderGateway;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Spaces each provider's requests and applies the hold-offs providers ask for.
 *
 * A request reserves the next free slot: at least `min_interval_ms` after the previous
 * reserved start and never before a Retry-After hold ends. A shared pacer reserves under
 * a short cache lock so every worker shares one sequence; the worker then sleeps until its
 * slot outside the lock. A wait longer than `retry_after_max_seconds`, or any wait for a
 * `skip` pacer, is refused instead of slept. If the lock backend itself fails, the request
 * is paced in this process and goes ahead.
 */
final class ProviderPacer
{
    private const int LOCK_SECONDS = 5;

    /** @var array<string, float> */
    private array $lastSlot = [];

    /** @var array<string, float> */
    private array $holdUntil = [];

    /** @var array<string, float> */
    private array $lastSerialEnd = [];

    private bool $reportedLockFailure = false;

    /** @return int|ShortCircuitReason the milliseconds slept, or why the request may not be sent */
    public function pace(ProviderPolicy $policy, ?string $host): int|ShortCircuitReason
    {
        if (! $policy->pacesHost($host)) {
            return 0;
        }

        // A serial provider is spaced by serial(); here it only honours holds.
        $interval = $policy->serial ? 0 : $policy->minIntervalMs;

        $slot = $policy->sharedPace ? $this->reserveShared($policy, $interval) : null;
        $slot ??= $this->reserve($policy, $interval, $this->lastSlot[$policy->provider] ?? 0.0, $this->holdUntil[$policy->provider] ?? 0.0);
        if ($slot instanceof ShortCircuitReason) {
            return $slot;
        }
        $this->lastSlot[$policy->provider] = max($this->lastSlot[$policy->provider] ?? 0.0, $slot);

        $wait = (int) ceil($slot - $this->now());
        if ($wait > 0) {
            Sleep::usleep($wait * 1_000);

            return $wait;
        }

        return 0;
    }

    /** Holds every request to the provider, in every worker sharing the pacer, for $milliseconds. */
    public function hold(ProviderPolicy $policy, int $milliseconds): void
    {
        $until = $this->now() + max(0, $milliseconds);
        $this->holdUntil[$policy->provider] = max($this->holdUntil[$policy->provider] ?? 0.0, $until);
        if (! $policy->sharedPace) {
            return;
        }

        try {
            $this->locked($policy, function () use ($policy, $until, $milliseconds): void {
                if ($until > (float) Cache::get($this->key($policy, 'hold'), 0.0)) {
                    Cache::put($this->key($policy, 'hold'), $until, (int) ceil($milliseconds / 1_000) + 60);
                }
            });
        } catch (Throwable $exception) {
            $this->reportLockFailure($policy, $exception);
        }
    }

    /** When the provider's current hold ends (a timestamp in milliseconds), if one is in force. */
    public function holdUntil(ProviderPolicy $policy): ?float
    {
        $until = $this->holdUntil[$policy->provider] ?? 0.0;
        if ($policy->sharedPace) {
            try {
                $until = max($until, (float) Cache::get($this->key($policy, 'hold'), 0.0));
            } catch (Throwable) {
                // The process's own record stands in for an unreadable store.
            }
        }

        return $until > $this->now() ? $until : null;
    }

    /**
     * Runs one lookup at a time across every worker, starting at least `min_interval_ms` after
     * the previous lookup ended. The lock is held for the whole lookup.
     *
     * @template T
     *
     * @param  Closure(): T  $lookup
     * @return T
     *
     * @throws LockTimeoutException when another lookup holds the lock past `lock_wait_seconds`
     */
    public function serial(ProviderPolicy $policy, Closure $lookup): mixed
    {
        try {
            $lock = Cache::lock($policy->serialLock, $policy->serialLockSeconds);
            $lock->block($policy->lockWaitSeconds);
        } catch (LockTimeoutException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->reportLockFailure($policy, $exception);

            return $this->spacedFromLastEnd($policy, $lookup, shared: false);
        }

        try {
            return $this->spacedFromLastEnd($policy, $lookup, shared: true);
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // The lock expires on its own.
            }
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $lookup
     * @return T
     */
    private function spacedFromLastEnd(ProviderPolicy $policy, Closure $lookup, bool $shared): mixed
    {
        $lastEnd = $this->lastSerialEnd[$policy->provider] ?? 0.0;
        if ($shared) {
            $lastEnd = max($lastEnd, (float) Cache::get($this->key($policy, 'last_end'), 0.0));
        }
        $remaining = $lastEnd + $policy->minIntervalMs - $this->now();
        if ($remaining > 0) {
            Sleep::usleep((int) ceil($remaining * 1_000));
        }

        try {
            return $lookup();
        } finally {
            $this->lastSerialEnd[$policy->provider] = $this->now();
            if ($shared) {
                Cache::put($this->key($policy, 'last_end'), $this->now(), 3_600);
            }
        }
    }

    /** The reserved slot, a refusal, or null when the shared store failed and the process paces instead. */
    private function reserveShared(ProviderPolicy $policy, int $interval): float|ShortCircuitReason|null
    {
        try {
            // Without spacing there is nothing to reserve: only a hold can delay the request.
            if ($interval === 0 && ! $policy->skipWhenBusy) {
                return $this->reserve($policy, 0, 0.0, max(
                    $this->holdUntil[$policy->provider] ?? 0.0,
                    (float) Cache::get($this->key($policy, 'hold'), 0.0),
                ));
            }

            return $this->locked($policy, function () use ($policy, $interval): float|ShortCircuitReason {
                $slot = $this->reserve(
                    $policy,
                    $interval,
                    (float) Cache::get($this->key($policy, 'last'), 0.0),
                    max($this->holdUntil[$policy->provider] ?? 0.0, (float) Cache::get($this->key($policy, 'hold'), 0.0)),
                );
                if (! $slot instanceof ShortCircuitReason) {
                    Cache::put($this->key($policy, 'last'), $slot, max(60, (int) ceil($interval / 1_000) * 2));
                }

                return $slot;
            });
        } catch (LockTimeoutException) {
            return ShortCircuitReason::LockTimeout;
        } catch (Throwable $exception) {
            $this->reportLockFailure($policy, $exception);

            return null;
        }
    }

    private function reserve(ProviderPolicy $policy, int $interval, float $last, float $hold): float|ShortCircuitReason
    {
        $now = $this->now();
        $slot = max($now, $last > 0 ? $last + $interval : 0.0, $hold);
        $wait = $slot - $now;
        if ($wait > 0 && ($policy->skipWhenBusy || $wait > $policy->retryAfterMaxSeconds * 1_000)) {
            return ShortCircuitReason::PacerSkip;
        }

        return $slot;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function locked(ProviderPolicy $policy, Closure $callback): mixed
    {
        return Cache::lock($this->key($policy, 'lock'), self::LOCK_SECONDS)->block($policy->lockWaitSeconds, $callback);
    }

    private function reportLockFailure(ProviderPolicy $policy, Throwable $exception): void
    {
        if ($this->reportedLockFailure) {
            return;
        }
        $this->reportedLockFailure = true;
        Log::warning('Provider gateway could not use the shared lock; pacing in this process instead.', [
            'provider' => $policy->provider,
            'error' => $exception->getMessage(),
        ]);
    }

    private function key(ProviderPolicy $policy, string $suffix): string
    {
        return 'provider-gateway:'.$policy->provider.':pace:'.$suffix;
    }

    private function now(): float
    {
        return now()->getPreciseTimestamp(3);
    }
}
