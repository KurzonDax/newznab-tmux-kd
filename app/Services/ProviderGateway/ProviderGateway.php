<?php

declare(strict_types=1);

namespace App\Services\ProviderGateway;

use App\Services\ObfuscationRecovery\RecoveryCatalog;
use Carbon\CarbonInterface;
use Closure;
use DateInterval;
use DateTimeInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * The one way external metadata providers are asked (TMDB, TVMaze, Trakt, TVDB, IMDb, OMDb,
 * Fanart.tv, MusicBrainz, AcoustID, Cover Art Archive, SRRDB, the decision model, IGDB,
 * AniList and the book services). For each provider entry it owns pacing, window budgets,
 * retries with Retry-After, circuit breaking, the outcome of each call and day counters.
 *
 * The core is one Guzzle middleware that runs every attempt of a request through the
 * breaker, the budgets and the pacer, then the recovery ledger and the transport. It is
 * reached three ways:
 *
 * - {@see self::http()} (or {@see self::through()} for a pending request built elsewhere, such as a
 *   pool's) for Laravel's HTTP client;
 * - {@see self::guzzle()} for libraries that take a Guzzle client;
 * - {@see self::call()} for transports the gateway cannot see, which get everything but retries.
 *
 * When the gateway declines to send, the request fails as a connection error
 * ({@see ShortCircuit}) and the call is Unavailable.
 */
final class ProviderGateway
{
    /** Marks a request the gateway handles, so the global ledger middleware leaves it alone. */
    public const string OPTION = 'provider_gateway';

    /** @var array<string, ProviderOutcome> */
    private array $lastOutcomes = [];

    /** @var array<string, ProviderOutcome> The last HTTP leg per provider, until a caller reports its real outcome. */
    private array $unreportedLegs = [];

    /** @var array<string, bool> Whether that last HTTP leg was a breaker's trial. */
    private array $unreportedTrials = [];

    /** @var array<string, int> */
    private array $unavailableCalls = [];

    /** @var array<string, callable> */
    private array $handlers = [];

    public function __construct(
        private readonly ProviderPolicies $policies,
        private readonly ProviderPacer $pacer,
        private readonly ProviderBreaker $breaker,
        private readonly ProviderCounters $counters,
    ) {}

    /**
     * A pending request to the provider through the gateway.
     *
     * @param  object|null  $owner  The instance an instance-scoped breaker belongs to.
     * @param  (Closure(): (bool|void))|null  $budget  A caller's own cap, asked before every attempt; false refuses the attempt.
     * @param  (Closure(ResponseInterface): ?ProviderOutcome)|null  $classify  Reads a response the status alone cannot classify.
     */
    public function http(string $provider, ?object $owner = null, ?Closure $budget = null, ?Closure $classify = null): PendingRequest
    {
        return $this->through(Http::withOptions([]), $provider, $owner, $budget, $classify);
    }

    /**
     * Sends a pending request built elsewhere (a pool's `$pool->as($key)`) through the gateway.
     *
     * @param  (Closure(): (bool|void))|null  $budget
     * @param  (Closure(ResponseInterface): ?ProviderOutcome)|null  $classify
     */
    public function through(PendingRequest $request, string $provider, ?object $owner = null, ?Closure $budget = null, ?Closure $classify = null): PendingRequest
    {
        $this->policies->for($provider);

        return $request
            ->withOptions([self::OPTION => $provider])
            ->withMiddleware($this->middleware($provider, $owner, $budget, $classify, laravel: true));
    }

    /**
     * A Guzzle client whose every request goes through the gateway and the recovery ledger.
     *
     * @param  array<string, mixed>  $options  Guzzle client options; any `handler` is replaced.
     * @param  callable|Client|null  $handler  The transport (a MockHandler in tests), or a client whose handler to use;
     *                                         defaults to the one set by {@see useHandler()}, then Guzzle's.
     * @param  (Closure(ResponseInterface): ?ProviderOutcome)|null  $classify
     */
    public function guzzle(string $provider, array $options = [], callable|Client|null $handler = null, ?object $owner = null, ?Closure $classify = null): Client
    {
        $this->policies->for($provider);
        if ($handler instanceof Client) {
            $handler = $handler->getConfig('handler');
            $handler = is_callable($handler) ? $handler : null;
        }
        $stack = HandlerStack::create($handler ?? $this->handlers[$provider] ?? null);
        $stack->push($this->middleware($provider, $owner, null, $classify, laravel: false), 'provider_gateway');

        return new Client([...$options, 'handler' => $stack]);
    }

    /**
     * Runs an operation on a transport the gateway cannot see. It is paced, counted and
     * breaker-guarded like a request, but never retried: an exception is Unavailable, a
     * `false` or `null` result NotFound, anything else Found.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return ProviderResult<T|null>
     */
    public function call(string $provider, callable $operation, ?object $owner = null): ProviderResult
    {
        $call = new GatewayCall($provider, $this->policies->for($provider), $owner, null, null, laravel: true);
        $ledger = RecoveryCatalog::begin($call->policy->ledgerHost ?? $provider, 'legacy_http');

        $admission = $this->admit($call, null);
        if ($admission instanceof ShortCircuitReason) {
            RecoveryCatalog::finish($ledger, 'transport_failure');
            $this->settle($call, ProviderOutcome::Unavailable);

            return ProviderResult::unavailable(new ShortCircuitConnectionException($provider, $admission));
        }

        $this->counters->increment($provider, 'requests');
        try {
            $value = $operation();
        } catch (Throwable $exception) {
            RecoveryCatalog::finish($ledger, 'transport_failure');
            $this->counters->increment($provider, 'transport_failures');
            $this->breaker->record($call->policy, $owner, answered: false, trial: $admission->trial);
            $this->settle($call, ProviderOutcome::Unavailable);

            return ProviderResult::unavailable($exception);
        }

        $empty = $value === false || $value === null;
        RecoveryCatalog::finish($ledger, $empty ? 'unavailable' : 'response_received');
        $this->breaker->record($call->policy, $owner, answered: true, trial: $admission->trial);
        $this->settle($call, $empty ? ProviderOutcome::NotFound : ProviderOutcome::Found);

        return $empty ? new ProviderResult(ProviderOutcome::NotFound, $value) : ProviderResult::found($value);
    }

    /**
     * Caches a provider's Found and NotFound answers under its existing key, each for its own
     * time, and never a Rejected or Unavailable one. A NotFound is stored as `false`; the
     * shapes stored before the gateway (`false` or `[]` for not found, anything else found)
     * read back the same way.
     *
     * @template T
     *
     * @param  Closure(): ProviderResult<T>  $callback
     * @return ProviderResult<T|mixed>
     */
    public function remember(
        string $provider,
        string $key,
        DateTimeInterface|DateInterval|int $foundTtl,
        DateTimeInterface|DateInterval|int $notFoundTtl,
        Closure $callback,
    ): ProviderResult {
        $cached = Cache::get($key);
        if ($cached !== null) {
            $this->counters->increment($provider, 'cache_hits');

            return $cached === false || $cached === [] ? ProviderResult::notFound() : ProviderResult::found($cached);
        }

        $result = $callback();
        if ($result->outcome === ProviderOutcome::Found && $result->value !== null) {
            Cache::put($key, $result->value, $foundTtl);
        } elseif ($result->outcome === ProviderOutcome::NotFound) {
            Cache::put($key, false, $notFoundTtl);
        }

        return $result;
    }

    /**
     * Records what a call really came to when the HTTP status could not say: an empty 200 is
     * NotFound, a 200 whose body is unusable Unavailable. An Unavailable report counts as a
     * failed attempt for the breaker.
     */
    public function report(string $provider, ProviderOutcome $outcome, ?object $owner = null): void
    {
        $policy = $this->policies->for($provider);
        $leg = $this->unreportedLegs[$provider] ?? null;
        $trial = $this->unreportedTrials[$provider] ?? false;
        unset($this->unreportedLegs[$provider], $this->unreportedTrials[$provider]);
        if ($leg === $outcome) {
            return;
        }

        if ($leg !== null) {
            $this->countOutcome($provider, $leg, -1);
        }
        $this->countOutcome($provider, $outcome, 1);
        $this->lastOutcomes[$provider] = $outcome;
        if ($outcome === ProviderOutcome::Unavailable) {
            $this->unavailableCalls[$provider] = ($this->unavailableCalls[$provider] ?? 0) + 1;
            // A trial whose answer turns out unusable is a failed trial after all.
            $this->breaker->record($policy, $owner, answered: false, trial: $trial);
        }
    }

    /** The outcome of the provider's last call in this process, as far as the gateway could tell. */
    public function lastOutcome(string $provider): ?ProviderOutcome
    {
        return $this->lastOutcomes[$provider] ?? null;
    }

    /** How many calls to these providers ended Unavailable in this process. */
    public function unavailableCount(string ...$providers): int
    {
        return array_sum(array_map(fn (string $provider): int => $this->unavailableCalls[$provider] ?? 0, $providers));
    }

    /** Whether the provider's breaker would refuse a request now. */
    public function isOpen(string $provider, ?object $owner = null): bool
    {
        return $this->breaker->isOpen($this->policies->for($provider), $owner);
    }

    /**
     * A provider's counters for one day (today by default).
     *
     * @return array<string, int>
     */
    public function counters(string $provider, ?CarbonInterface $day = null): array
    {
        $this->policies->for($provider);

        return $this->counters->read($provider, $day);
    }

    /**
     * Waits for the provider's next request slot outside a request (a delegate's own pacing).
     *
     * @param  string|null  $host  The host the request goes to, for a provider that paces only some hosts.
     * @return int|ShortCircuitReason the milliseconds waited, or why no request may be sent
     */
    public function pace(string $provider, ?string $host = null): int|ShortCircuitReason
    {
        $policy = $this->policies->for($provider);
        $waited = $this->pacer->pace($policy, $host);
        $this->countPacing($provider, $waited);

        return $waited;
    }

    /** Holds the provider's pacer, in every worker sharing it, for $milliseconds. */
    public function hold(string $provider, int $milliseconds): void
    {
        $this->pacer->hold($this->policies->for($provider), $milliseconds);
    }

    /** When the provider's current hold ends, if one is in force. */
    public function heldUntil(string $provider): ?Carbon
    {
        $until = $this->pacer->holdUntil($this->policies->for($provider));

        return $until === null ? null : Carbon::createFromTimestampMs($until);
    }

    /**
     * Runs one lookup at a time across every worker for a `serial` provider (Cover Art Archive).
     *
     * @template T
     *
     * @param  Closure(): T  $lookup
     * @return T
     *
     * @throws LockTimeoutException when another lookup holds the lock past `lock_wait_seconds`
     */
    public function serial(string $provider, Closure $lookup): mixed
    {
        return $this->pacer->serial($this->policies->for($provider), $lookup);
    }

    /** Sends the provider's Guzzle clients built from now on through $handler (a MockHandler in tests). */
    public function useHandler(string $provider, callable $handler): void
    {
        $this->policies->for($provider);
        $this->handlers[$provider] = $handler;
    }

    /**
     * @param  (Closure(): (bool|void))|null  $budget
     * @param  (Closure(ResponseInterface): ?ProviderOutcome)|null  $classify
     */
    private function middleware(string $provider, ?object $owner, ?Closure $budget, ?Closure $classify, bool $laravel): Closure
    {
        return fn (callable $handler): Closure => function (RequestInterface $request, array $options) use ($handler, $provider, $owner, $budget, $classify, $laravel): PromiseInterface {
            unset($options[self::OPTION]);
            $call = new GatewayCall($provider, $this->policies->for($provider), $owner, $budget, $classify, $laravel);

            return $this->attempt($handler, $request, $options, $call, 1);
        };
    }

    /** @param array<string, mixed> $options */
    private function attempt(callable $handler, RequestInterface $request, array $options, GatewayCall $call, int $attempt): PromiseInterface
    {
        $permit = null;
        $gate = function (RequestInterface $request, array $options) use ($handler, $call, &$permit): PromiseInterface {
            $admission = $this->admit($call, $request->getUri()->getHost());
            if ($admission instanceof ShortCircuitReason) {
                return Create::rejectionFor($call->laravel
                    ? new ShortCircuitConnectionException($call->provider, $admission)
                    : new ShortCircuitConnectException($call->provider, $admission, $request));
            }
            $permit = $admission;
            $this->counters->increment($call->provider, 'requests');

            return $handler($request, $options);
        };

        try {
            // The ledger records each attempt, a declined one as a transport failure.
            $promise = RecoveryCatalog::middleware()($gate)($request, $options);
        } catch (Throwable $exception) {
            $promise = Create::rejectionFor($exception);
        }

        return $promise->then(
            function (ResponseInterface $response) use ($handler, $request, $options, $call, $attempt, &$permit): ResponseInterface|PromiseInterface {
                return $this->afterResponse($handler, $request, $options, $call, $attempt, $permit, $response);
            },
            function (mixed $reason) use ($handler, $request, $options, $call, $attempt, &$permit): PromiseInterface {
                return $this->afterFailure($handler, $request, $options, $call, $attempt, $permit, $reason);
            },
        );
    }

    /** @param array<string, mixed> $options */
    private function afterResponse(
        callable $handler,
        RequestInterface $request,
        array $options,
        GatewayCall $call,
        int $attempt,
        ?BreakerPermit $permit,
        ResponseInterface $response,
    ): ResponseInterface|PromiseInterface {
        $policy = $call->policy;
        $status = $response->getStatusCode();
        $this->countStatus($call->provider, $status);
        $byCaller = $call->classify !== null ? ($call->classify)($response) : null;
        $outcome = $byCaller ?? $this->classify($policy, $status);
        $trial = $permit !== null && $permit->trial;
        $this->breaker->record($policy, $call->owner, $outcome->answered(), $trial);

        if ($outcome === ProviderOutcome::Unavailable) {
            $retryAfter = $this->retryAfterMilliseconds($response, $policy);
            $retry = $attempt < $policy->attempts
                && $this->retryable($policy, $status, $byCaller !== null)
                && ($retryAfter === null || $retryAfter <= $policy->retryAfterMaxSeconds * 1_000);
            $hold = $retryAfter;
            if ($status === 429 && ! $retry && $policy->holdOn429Seconds > 0) {
                $hold = max($hold ?? 0, $policy->holdOn429Seconds * 1_000);
            }
            if ($hold !== null && $hold > 0) {
                $this->pacer->hold($policy, $hold);
            }
            if ($retry) {
                return $this->retry($handler, $request, $options, $call, $attempt);
            }
        }

        $this->settle($call, $outcome, $trial);

        return $response;
    }

    /** @param array<string, mixed> $options */
    private function afterFailure(
        callable $handler,
        RequestInterface $request,
        array $options,
        GatewayCall $call,
        int $attempt,
        ?BreakerPermit $permit,
        mixed $reason,
    ): PromiseInterface {
        if ($reason instanceof ShortCircuit) {
            $this->settle($call, ProviderOutcome::Unavailable);

            return Create::rejectionFor($reason);
        }

        if ($reason instanceof RequestException && $reason->hasResponse()) {
            $response = $reason->getResponse();
            $this->countStatus($call->provider, $response->getStatusCode());
            $outcome = $this->classify($call->policy, $response->getStatusCode());
            $this->breaker->record($call->policy, $call->owner, $outcome->answered(), $permit !== null && $permit->trial);
            $this->settle($call, $outcome);

            return Create::rejectionFor($reason);
        }

        if (! $this->isTransportFailure($reason)) {
            if ($permit !== null) {
                $this->breaker->release($call->policy, $permit);
            }

            return Create::rejectionFor($reason);
        }

        $this->counters->increment($call->provider, 'transport_failures');
        $this->breaker->record($call->policy, $call->owner, answered: false, trial: $permit !== null && $permit->trial);
        if ($call->policy->retryTransport && $attempt < $call->policy->attempts) {
            return $this->retry($handler, $request, $options, $call, $attempt);
        }

        $this->settle($call, ProviderOutcome::Unavailable);

        return Create::rejectionFor($reason);
    }

    /** @param array<string, mixed> $options */
    private function retry(callable $handler, RequestInterface $request, array $options, GatewayCall $call, int $attempt): PromiseInterface
    {
        $this->counters->increment($call->provider, 'retries');
        $backoff = $call->policy->backoffMilliseconds($attempt);
        if ($backoff > 0) {
            Sleep::usleep($backoff * 1_000);
            $this->counters->increment($call->provider, 'waited_ms', $backoff);
        }

        return $this->attempt($handler, $request, $options, $call, $attempt + 1);
    }

    /** Asks the breaker, the budgets and the pacer for one attempt. */
    private function admit(GatewayCall $call, ?string $host): BreakerPermit|ShortCircuitReason
    {
        $policy = $call->policy;
        $permit = $this->breaker->admit($policy, $call->owner);
        if ($permit instanceof ShortCircuitReason) {
            return $permit;
        }

        try {
            $refusal = $this->refusal($call, $host);
        } catch (Throwable $exception) {
            $this->breaker->release($policy, $permit);

            throw $exception;
        }

        if ($refusal !== null) {
            $this->breaker->release($policy, $permit);

            return $refusal;
        }

        return $permit;
    }

    private function refusal(GatewayCall $call, ?string $host): ?ShortCircuitReason
    {
        $policy = $call->policy;
        if ($policy->budgetMax !== null) {
            if (RateLimiter::tooManyAttempts($policy->budgetKey, $policy->budgetMax)) {
                $this->counters->increment($call->provider, 'budget_refusals');

                return ShortCircuitReason::BudgetRefused;
            }
            RateLimiter::hit($policy->budgetKey, $policy->budgetWindowSeconds);
        }

        if ($call->budget !== null && ($call->budget)() === false) {
            $this->counters->increment($call->provider, 'budget_refusals');

            return ShortCircuitReason::BudgetRefused;
        }

        $waited = $this->pacer->pace($policy, $host);
        $this->countPacing($call->provider, $waited);

        return $waited instanceof ShortCircuitReason ? $waited : null;
    }

    private function countPacing(string $provider, int|ShortCircuitReason $waited): void
    {
        if ($waited instanceof ShortCircuitReason) {
            if ($waited === ShortCircuitReason::PacerSkip) {
                $this->counters->increment($provider, 'pacer_skips');
            }

            return;
        }

        $this->counters->increment($provider, 'waited_ms', $waited);
    }

    private function classify(ProviderPolicy $policy, int $status): ProviderOutcome
    {
        return match (true) {
            $status === 429, $status >= 500, in_array($status, $policy->unavailableStatuses, true) => ProviderOutcome::Unavailable,
            $status === 404 => ProviderOutcome::NotFound,
            $status >= 400 => ProviderOutcome::Rejected,
            default => ProviderOutcome::Found,
        };
    }

    private function retryable(ProviderPolicy $policy, int $status, bool $classifiedByCaller): bool
    {
        if (in_array($status, $policy->retryStatuses, true)) {
            return true;
        }

        return ($classifiedByCaller || $status >= 500) && $policy->retryServerErrors;
    }

    private function retryAfterMilliseconds(ResponseInterface $response, ProviderPolicy $policy): ?int
    {
        $retryAfter = trim($response->getHeaderLine('Retry-After'));
        if ($retryAfter === '') {
            return $response->getStatusCode() === 429 && $policy->defaultRetryAfterSeconds !== null
                ? $policy->defaultRetryAfterSeconds * 1_000
                : null;
        }
        if (ctype_digit($retryAfter)) {
            return (int) $retryAfter * 1_000;
        }

        try {
            return max(0, (int) now()->diffInMilliseconds(Carbon::parse($retryAfter), false));
        } catch (Throwable) {
            return null;
        }
    }

    private function isTransportFailure(mixed $reason): bool
    {
        return $reason instanceof ConnectException
            || $reason instanceof ConnectionException
            || ($reason instanceof RequestException && ! $reason->hasResponse());
    }

    private function countStatus(string $provider, int $status): void
    {
        $bucket = match (true) {
            $status === 429 => 'status_429',
            $status >= 500 => 'status_5xx',
            $status >= 400 => 'status_4xx',
            default => null,
        };
        if ($bucket !== null) {
            $this->counters->increment($provider, $bucket);
        }
    }

    /** Records how a call ended; a caller may still {@see report()} the real outcome of an HTTP leg. */
    private function settle(GatewayCall $call, ProviderOutcome $outcome, bool $trial = false): void
    {
        $this->lastOutcomes[$call->provider] = $outcome;
        $this->unreportedLegs[$call->provider] = $outcome;
        $this->unreportedTrials[$call->provider] = $trial;
        $this->countOutcome($call->provider, $outcome, 1);
        if ($outcome === ProviderOutcome::Unavailable) {
            $this->unavailableCalls[$call->provider] = ($this->unavailableCalls[$call->provider] ?? 0) + 1;
        }
    }

    private function countOutcome(string $provider, ProviderOutcome $outcome, int $by): void
    {
        if ($outcome !== ProviderOutcome::Unavailable) {
            $this->counters->increment($provider, $outcome->value, $by);
        }
    }
}
