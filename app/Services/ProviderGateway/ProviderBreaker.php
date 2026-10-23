<?php

declare(strict_types=1);

namespace App\Services\ProviderGateway;

use Illuminate\Support\Facades\Cache;
use WeakMap;

/**
 * Circuit breakers for every provider, in one of three scopes:
 *
 * - shared: in the cache store, so every worker sees it. When the open period ends, one
 *   trial request is let through, claimed with `Cache::add()`; until it reports, every
 *   other caller is refused. A failed trial re-opens the breaker for twice the previous
 *   time, never more than an hour; a success closes it and resets the doubling.
 * - process: in this process only, open for its open time.
 * - instance: per owning object, open for its open time, or for the owner's lifetime when
 *   the open time is null.
 *
 * Only an answer the provider gave or failed to give counts: a request the gateway itself
 * declined to send never moves a breaker.
 */
final class ProviderBreaker
{
    public const int MAXIMUM_OPEN_SECONDS = 3_600;

    private const int TRIAL_SECONDS = 120;

    /** @var array<string, array{failures: int, open: bool, open_until: float|null}> */
    private array $process = [];

    /** @var WeakMap<object, LocalBreakerStates> */
    private WeakMap $instances;

    public function __construct(private readonly ProviderCounters $counters)
    {
        $this->instances = new WeakMap;
    }

    /** Whether a request would be refused right now, without claiming a trial. */
    public function isOpen(ProviderPolicy $policy, ?object $owner = null): bool
    {
        if (! $policy->hasBreaker()) {
            return false;
        }
        if ($policy->breakerScope !== 'shared') {
            $state = $this->localState($policy, $owner);

            return $state['open'] && ($state['open_until'] === null || $this->now() < $state['open_until']);
        }

        $openUntil = Cache::get($this->key($policy, 'open_until'));
        if ($openUntil === null) {
            return false;
        }

        return $this->now() < (float) $openUntil || ($policy->escalate && Cache::has($this->key($policy, 'trial')));
    }

    /**
     * Lets a request through, as a trial when a shared breaker's open period has just ended,
     * or says why it is refused.
     */
    public function admit(ProviderPolicy $policy, ?object $owner): BreakerPermit|ShortCircuitReason
    {
        if (! $policy->hasBreaker()) {
            return new BreakerPermit(trial: false);
        }
        if ($policy->breakerScope !== 'shared') {
            return $this->admitLocal($policy, $owner);
        }

        $openUntil = Cache::get($this->key($policy, 'open_until'));
        if ($openUntil === null) {
            return new BreakerPermit(trial: false);
        }
        if ($this->now() < (float) $openUntil) {
            return ShortCircuitReason::BreakerOpen;
        }
        if (! $policy->escalate) {
            $this->close($policy);

            return new BreakerPermit(trial: false);
        }

        return Cache::add($this->key($policy, 'trial'), true, self::TRIAL_SECONDS)
            ? new BreakerPermit(trial: true)
            : ShortCircuitReason::TrialClaimed;
    }

    /** Gives back a trial that was claimed for a request the gateway then declined to send. */
    public function release(ProviderPolicy $policy, BreakerPermit $permit): void
    {
        if ($permit->trial) {
            Cache::forget($this->key($policy, 'trial'));
        }
    }

    /** Counts one attempt: an answer of any kind closes the count, no answer adds to it. */
    public function record(ProviderPolicy $policy, ?object $owner, bool $answered, bool $trial = false): void
    {
        if (! $policy->hasBreaker()) {
            return;
        }
        if ($policy->breakerScope !== 'shared') {
            $this->recordLocal($policy, $owner, $answered);

            return;
        }

        if ($answered) {
            Cache::forget($this->key($policy, 'failures'));
            if ($trial) {
                // Kept briefly in case the caller reports the trial's answer unusable after all.
                Cache::put($this->key($policy, 'closed_step'), (int) Cache::get($this->key($policy, 'step'), 0), self::TRIAL_SECONDS);
                $this->close($policy);
            }

            return;
        }

        if ($trial) {
            $step = (int) (Cache::get($this->key($policy, 'step')) ?? Cache::get($this->key($policy, 'closed_step'), 0)) + 1;
            Cache::forget($this->key($policy, 'trial'));
            $this->open($policy, $step);

            return;
        }

        $failuresKey = $this->key($policy, 'failures');
        Cache::add($failuresKey, 0, 86_400);
        if ((int) Cache::increment($failuresKey) >= $policy->failureThreshold) {
            $this->open($policy, (int) Cache::get($this->key($policy, 'step'), 0));
        }
    }

    private function open(ProviderPolicy $policy, int $step): void
    {
        $seconds = (int) ($policy->openSeconds ?? self::MAXIMUM_OPEN_SECONDS);
        if ($policy->escalate) {
            $seconds = (int) min(self::MAXIMUM_OPEN_SECONDS, $seconds * (2 ** $step));
        }

        // An escalating breaker remembers it was open after the period ends, so the next
        // caller knows to send a trial; the step outlives it so a failed trial can double it.
        $keep = $policy->escalate ? $seconds + 86_400 : $seconds;
        Cache::put($this->key($policy, 'open_until'), $this->now() + $seconds * 1_000, $keep);
        Cache::put($this->key($policy, 'step'), $step, $seconds + 86_400);
        Cache::forget($this->key($policy, 'failures'));
        $this->counters->increment($policy->provider, 'breaker_opens');
    }

    private function close(ProviderPolicy $policy): void
    {
        foreach (['open_until', 'step', 'trial', 'failures'] as $suffix) {
            Cache::forget($this->key($policy, $suffix));
        }
    }

    private function admitLocal(ProviderPolicy $policy, ?object $owner): BreakerPermit|ShortCircuitReason
    {
        $state = $this->localState($policy, $owner);
        if ($state['open']) {
            if ($state['open_until'] === null || $this->now() < $state['open_until']) {
                return ShortCircuitReason::BreakerOpen;
            }
            $this->storeLocal($policy, $owner, ['failures' => 0, 'open' => false, 'open_until' => null]);
        }

        return new BreakerPermit(trial: false);
    }

    private function recordLocal(ProviderPolicy $policy, ?object $owner, bool $answered): void
    {
        $state = $this->localState($policy, $owner);
        if ($answered) {
            $state['failures'] = 0;
        } elseif (++$state['failures'] >= $policy->failureThreshold) {
            $state = [
                'failures' => 0,
                'open' => true,
                'open_until' => $policy->openSeconds === null ? null : $this->now() + $policy->openSeconds * 1_000,
            ];
            $this->counters->increment($policy->provider, 'breaker_opens');
        }

        $this->storeLocal($policy, $owner, $state);
    }

    /** @return array{failures: int, open: bool, open_until: float|null} */
    private function localState(ProviderPolicy $policy, ?object $owner): array
    {
        $states = $policy->breakerScope === 'instance' && $owner !== null
            ? ($this->instances[$owner] ?? new LocalBreakerStates)->states
            : $this->process;

        return $states[$policy->provider] ?? ['failures' => 0, 'open' => false, 'open_until' => null];
    }

    /** @param array{failures: int, open: bool, open_until: float|null} $state */
    private function storeLocal(ProviderPolicy $policy, ?object $owner, array $state): void
    {
        if ($policy->breakerScope === 'instance' && $owner !== null) {
            $states = $this->instances[$owner] ?? new LocalBreakerStates;
            $states->states[$policy->provider] = $state;
            $this->instances[$owner] = $states;

            return;
        }

        $this->process[$policy->provider] = $state;
    }

    private function key(ProviderPolicy $policy, string $suffix): string
    {
        return 'provider-gateway:'.$policy->provider.':breaker:'.$suffix;
    }

    private function now(): float
    {
        return now()->getPreciseTimestamp(3);
    }
}
