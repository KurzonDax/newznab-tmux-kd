<?php

declare(strict_types=1);

namespace App\Services\NNTP;

use App\Services\NNTP\Contracts\ProviderClient;
use Closure;
use DariusIII\NetNntp\Error;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The front door for article operations across every configured NNTP backbone.
 *
 * Article operations (BODY / STAT by message-ID) are served in strict configuration order:
 * provider 1 first, then each further enabled provider. Failover is per-article -- an article
 * fails only once every enabled provider has failed it.
 *
 * The pool deliberately exposes no header operations. Article *numbers* are per-server, so
 * group scanning, backfill, part repair and header re-scans are only meaningful against
 * provider 1's numbering; keeping them off this API makes "headers are primary-pinned" true
 * by construction rather than by convention.
 */
class NntpProviderPool
{
    private const string MISSING_CONFIG_MESSAGE =
        'No NNTP providers are configured. Set NNTP_PROVIDER_1_NAME, NNTP_PROVIDER_1_HOST, '
        .'NNTP_PROVIDER_1_PORT, NNTP_PROVIDER_1_USERNAME and NNTP_PROVIDER_1_PASSWORD in .env. '
        .'These replaced the flat NNTP_SERVER/NNTP_PORT keys and USE_ALTERNATE_NNTP_SERVER; '
        .'run "php artisan nntp:pool-status" to verify the result.';

    /** @var list<NntpProvider>|null */
    private static ?array $configuredProviders = null;

    /** @var list<NntpProvider> */
    private array $providers;

    /** @var array<string, ProviderClient> */
    private array $clients = [];

    private ProviderCircuitBreaker $breaker;

    /** @var Closure(NntpProvider): ProviderClient */
    private Closure $clientFactory;

    /**
     * @param  list<NntpProvider>|null  $providers  Defaults to the configured providers.
     * @param  (Closure(NntpProvider): ProviderClient)|null  $clientFactory  Injected in tests.
     */
    public function __construct(
        ?array $providers = null,
        ?ProviderCircuitBreaker $breaker = null,
        ?Closure $clientFactory = null,
    ) {
        $this->providers = $providers ?? self::configuredProviders();
        $this->breaker = $breaker ?? ProviderCircuitBreaker::fromConfig();
        $this->clientFactory = $clientFactory ?? static function (NntpProvider $provider): ProviderClient {
            $client = new NNTPService;
            // Pool-owned clients stay single-provider: a failed fetch must not re-enter the pool.
            $client->useProvider($provider, poolFailover: false);

            return $client;
        };
    }

    /**
     * Every configured provider, enabled or not, in position order.
     *
     * @return list<NntpProvider>
     */
    public static function configuredProviders(): array
    {
        if (self::$configuredProviders !== null) {
            return self::$configuredProviders;
        }

        $providers = [];

        /** @var array<int, array<string, mixed>> $rows */
        $rows = (array) config('nntmux_nntp.providers', []);

        foreach ($rows as $row) {
            $providers[] = NntpProvider::fromConfig((array) $row);
        }

        usort($providers, static fn (NntpProvider $a, NntpProvider $b): int => $a->position <=> $b->position);

        return self::$configuredProviders = $providers;
    }

    /**
     * Forget the parsed provider list (config changed under us, or a test rebound it).
     */
    public static function forgetConfiguredProviders(): void
    {
        self::$configuredProviders = null;
    }

    /**
     * The provider that owns all header traffic and leads article-operation order.
     *
     * Throws rather than returning null: every NNTP operation needs a primary, and a silent
     * no-op here would look like "usenet is quiet today" instead of "nothing is configured".
     */
    public static function primaryProvider(): NntpProvider
    {
        return self::configuredProviders()[0] ?? throw new RuntimeException(self::MISSING_CONFIG_MESSAGE);
    }

    /**
     * @return list<NntpProvider>
     */
    public function providers(): array
    {
        return $this->providers;
    }

    /**
     * @return list<NntpProvider>
     */
    public function enabledProviders(): array
    {
        return array_values(array_filter($this->providers, static fn (NntpProvider $p): bool => $p->enabled));
    }

    public function primary(): NntpProvider
    {
        return $this->providers[0] ?? throw new RuntimeException(self::MISSING_CONFIG_MESSAGE);
    }

    public function breaker(): ProviderCircuitBreaker
    {
        return $this->breaker;
    }

    /**
     * Is the article retrievable from any enabled provider?
     *
     * Stops at the first provider that says EXISTS: the caller only needs to know the article
     * is reachable somewhere, and a subsequent fetch walks the same pool anyway.
     *
     * @throws \Exception
     */
    public function articleExists(string $messageId): bool
    {
        if (trim($messageId) === '') {
            return false;
        }

        foreach ($this->availableProviders() as $provider) {
            $result = $this->attempt($provider, 'STAT', $messageId,
                static fn (ProviderClient $client): mixed => $client->statArticle($messageId));

            if ($result === true) {
                return true;
            }

            // `false` is the provider answering "I do not carry it" -- an answer, not a fault,
            // so it neither trips the breaker nor stops the walk. attempt() has already
            // recorded the failure for the Error case.
        }

        return false;
    }

    /**
     * Fetch one article body, walking every enabled provider in order.
     *
     * @return mixed string body on success, Error object when no provider could serve it.
     *
     * @throws \Exception
     */
    public function fetchArticleBody(string $messageId, ?NntpProvider $skip = null): mixed
    {
        $lastError = null;

        foreach ($this->availableProviders() as $provider) {
            if ($skip !== null && $provider->name === $skip->name) {
                continue;
            }

            $body = $this->attempt($provider, 'BODY', $messageId,
                static fn (ProviderClient $client): mixed => $client->fetchArticleBody($messageId));

            if (\is_string($body) && $body !== '') {
                return $body;
            }

            if (NNTPService::isError($body)) {
                $lastError = $body;
            }
        }

        return $lastError ?? $this->noProviderError($messageId);
    }

    /**
     * Continue a pool walk after one provider already failed the article.
     *
     * Called by {@see NNTPService::failoverArticleBody()} so the client that already holds a
     * connection to the failing provider is not asked to try it a second time.
     *
     * @param  mixed  $originalError  The error the failing provider returned.
     *
     * @throws \Exception
     */
    public function failoverArticleBody(string $messageId, NntpProvider $failedProvider, mixed $originalError): mixed
    {
        $this->breaker->recordFailure($failedProvider);
        $this->logFailure(
            $failedProvider,
            'BODY',
            $messageId,
            NNTPService::isError($originalError) ? (string) $originalError->getMessage() : 'unknown error',
        );

        $result = $this->fetchArticleBody($messageId, skip: $failedProvider);

        return NNTPService::isError($result) ? $originalError : $result;
    }

    /**
     * Get (and lazily create) the client for a provider.
     */
    public function clientFor(NntpProvider $provider): ProviderClient
    {
        return $this->clients[$provider->name] ??= ($this->clientFactory)($provider);
    }

    /**
     * Disconnect every client this pool opened.
     */
    public function quit(): void
    {
        foreach ($this->clients as $client) {
            $client->doQuit();
        }

        $this->clients = [];
    }

    /**
     * Enabled providers the breaker is not currently skipping.
     *
     * @return list<NntpProvider>
     */
    private function availableProviders(): array
    {
        $available = [];

        foreach ($this->enabledProviders() as $provider) {
            if ($this->breaker->isOpen($provider)) {
                Log::debug('NNTP provider '.$provider->label().' skipped for article ops: circuit breaker open.');

                continue;
            }

            $available[] = $provider;
        }

        return $available;
    }

    /**
     * Run one provider operation, recording and logging any failure against that provider.
     *
     * A returned Error and a thrown exception are the same thing here: the provider did not
     * answer. Both trip the breaker; a clean negative answer (`false`) does not.
     *
     * @param  Closure(ProviderClient): mixed  $run
     */
    private function attempt(NntpProvider $provider, string $operation, string $messageId, Closure $run): mixed
    {
        try {
            $result = $run($this->clientFor($provider));
        } catch (\Throwable $e) {
            $this->breaker->recordFailure($provider);
            $this->logFailure($provider, $operation, $messageId, $e->getMessage());

            return null;
        }

        if (NNTPService::isError($result)) {
            $this->breaker->recordFailure($provider);
            $this->logFailure($provider, $operation, $messageId, (string) $result->getMessage());

            return $result;
        }

        $this->breaker->recordSuccess($provider);

        return $result;
    }

    private function logFailure(NntpProvider $provider, string $operation, string $messageId, string $reason): void
    {
        Log::debug(sprintf(
            'NNTP provider %s failed %s for %s: %s',
            $provider->label(),
            $operation,
            $messageId,
            $reason,
        ));
    }

    private function noProviderError(string $messageId): object
    {
        $names = implode(', ', array_map(
            static fn (NntpProvider $p): string => $p->name,
            $this->enabledProviders(),
        ));

        return new Error(
            sprintf('No NNTP provider could serve article %s (tried: %s).', $messageId, $names === '' ? 'none' : $names)
        );
    }
}
