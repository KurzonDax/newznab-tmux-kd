<?php

declare(strict_types=1);

namespace App\Services\NNTP;

use App\Services\NNTP\Contracts\ProviderClient;
use App\Services\NNTP\DTO\ArticleDownloadResult;
use Closure;
use DariusIII\NetNntp\Error;
use DariusIII\NetNntp\Error as NntpError;
use DariusIII\NetNntp\Protocol\ResponseCode;
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
        .'NNTP_PROVIDER_1_PORT, NNTP_PROVIDER_1_USERNAME and NNTP_PROVIDER_1_PASSWORD in .env '
        .'(these replaced the flat NNTP_SERVER/NNTP_PORT keys), then run '
        .'"php artisan nntp:pool-status" to verify the result.';

    /**
     * Guard against a body growing past what PHP can hold: measure one body, multiply by the
     * loop count, and bail before 1.7GB. Without this the string growth is a fatal error.
     */
    private const int MAX_CONCATENATED_BYTES = 1_700_000_000;

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
        $this->breaker = $breaker ?? new ProviderCircuitBreaker;
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

        // NAME is the pool's identity for a provider: it keys the client map, the breaker state
        // and the monitor's rows. Two providers sharing one would silently share all three.
        $names = array_map(static fn (NntpProvider $p): string => $p->name, $providers);
        $duplicates = array_unique(array_diff_assoc($names, array_unique($names)));

        if ($duplicates !== []) {
            throw new RuntimeException(
                'Duplicate NNTP provider NAME(s): '.implode(', ', $duplicates).'. '
                .'Each provider needs its own label -- it identifies the provider in logs and '
                .'keys its connections and circuit-breaker state.'
            );
        }

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
        return self::tryPrimaryProvider() ?? throw new RuntimeException(self::MISSING_CONFIG_MESSAGE);
    }

    /**
     * The primary, or null when nothing is configured.
     *
     * For callers that merely want to *describe* the primary (an admin screen, a status line)
     * and must not blow up on a box that has not been configured yet.
     */
    public static function tryPrimaryProvider(): ?NntpProvider
    {
        return self::configuredProviders()[0] ?? null;
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
            // so it neither trips the breaker nor stops the walk.
        }

        return false;
    }

    /**
     * Concatenated bodies for a list of message-IDs, each article walking the pool on its own.
     *
     * Partial success is preserved: once anything has been fetched, an article no provider can
     * serve ends the walk and returns what we have, rather than discarding it for an error.
     *
     * @param  list<mixed>  $messageIds
     * @param  ProviderClient|null  $callerClient  A client the caller already has connected;
     *                                             reused for its own provider instead of opening
     *                                             a second connection to the same backbone.
     * @return mixed string on success, Error when the first article fails everywhere.
     *
     * @throws \Exception
     */
    public function fetchArticleBodies(array $messageIds, ?ProviderClient $callerClient = null): mixed
    {
        return $this->fetchArticleBodiesWithCrcStatus($messageIds, $callerClient)->data;
    }

    /**
     * Concatenate article bodies while carrying CRC failures across provider failover.
     *
     * @param  list<mixed>  $messageIds
     */
    public function fetchArticleBodiesWithCrcStatus(
        array $messageIds,
        ?ProviderClient $callerClient = null,
    ): ArticleDownloadResult {
        $body = '';
        $messageSize = 0;
        $loops = 0;
        $crcFailedMessageIds = [];

        foreach ($messageIds as $messageId) {
            if ((++$loops * $messageSize) >= self::MAX_CONCATENATED_BYTES) {
                break;
            }

            $part = $this->fetchArticleBodyWithCrcStatus((string) $messageId, $callerClient);
            $crcFailedMessageIds = $this->mergeCrcFailedMessageIds(
                $crcFailedMessageIds,
                $part->crcFailedMessageIds,
            );

            if ($part->damaged) {
                return new ArticleDownloadResult($body, $crcFailedMessageIds, true);
            }

            if (NNTPService::isError($part->data)) {
                return new ArticleDownloadResult($body !== '' ? $body : $part->data, $crcFailedMessageIds);
            }

            if (! is_string($part->data)) {
                return new ArticleDownloadResult($body, $crcFailedMessageIds);
            }

            $body .= $part->data;

            if ($messageSize === 0) {
                $messageSize = \strlen($part->data);
            }
        }

        return new ArticleDownloadResult($body, $crcFailedMessageIds);
    }

    /**
     * Fetch one article body, walking every enabled provider in order.
     *
     * @return mixed string body on success, Error object when no provider could serve it.
     *
     * @throws \Exception
     */
    public function fetchArticleBody(string $messageId, ?ProviderClient $callerClient = null): mixed
    {
        return $this->fetchArticleBodyWithCrcStatus($messageId, $callerClient)->data;
    }

    /**
     * Fetch one article, retrying a CRC-damaged provider copy on later providers.
     */
    public function fetchArticleBodyWithCrcStatus(
        string $messageId,
        ?ProviderClient $callerClient = null,
    ): ArticleDownloadResult {
        $lastError = null;
        $lastDamagedData = null;
        $crcFailedMessageIds = [];

        foreach ($this->availableProviders() as $provider) {
            $result = $this->attempt(
                $provider,
                'BODY',
                $messageId,
                static function (ProviderClient $client) use ($messageId): mixed {
                    if (method_exists($client, 'fetchArticleBodyWithCrcStatus')) {
                        return $client->fetchArticleBodyWithCrcStatus($messageId);
                    }

                    $body = $client->fetchArticleBody($messageId);

                    return NNTPService::isError($body) ? $body : new ArticleDownloadResult($body);
                },
                $callerClient,
            );

            if ($result instanceof ArticleDownloadResult) {
                $crcFailedMessageIds = $this->mergeCrcFailedMessageIds(
                    $crcFailedMessageIds,
                    $result->crcFailedMessageIds,
                );
                if ($result->damaged) {
                    $lastDamagedData = $result->data;

                    continue;
                }

                if (is_string($result->data) && $result->data !== '') {
                    return new ArticleDownloadResult($result->data, $crcFailedMessageIds);
                }
            }

            if (NNTPService::isError($result)) {
                $lastError = $result;
            }
        }

        if ($crcFailedMessageIds !== []) {
            return new ArticleDownloadResult($lastDamagedData, $crcFailedMessageIds, true);
        }

        return new ArticleDownloadResult($lastError ?? $this->noProviderError($messageId));
    }

    /**
     * @param  list<string>  $current
     * @param  list<string>  $additional
     * @return list<string>
     */
    private function mergeCrcFailedMessageIds(array $current, array $additional): array
    {
        return array_values(array_unique([...$current, ...$additional]));
    }

    /**
     * Connect to one provider and report what happened.
     *
     * Shared by the `/status` probe and `nntp:pool-status`, which ask the same question and
     * used to answer it with two copies of the same routine.
     */
    public function probe(NntpProvider $provider): ProviderProbeResult
    {
        $client = $this->clientFor($provider);
        $start = hrtime(true);

        try {
            $result = $client->doConnect(compression: false);
        } catch (\Throwable $e) {
            return ProviderProbeResult::failed($provider, $e->getMessage(), self::elapsedMs($start));
        }

        $elapsed = self::elapsedMs($start);

        if ($result === true) {
            return ProviderProbeResult::connected($provider, $elapsed);
        }

        return ProviderProbeResult::failed(
            $provider,
            NNTPService::isError($result) ? (string) $result->getMessage() : 'unknown connection result',
            $elapsed,
        );
    }

    private static function elapsedMs(float|int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
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
     * Run one provider operation, classifying and recording its failure against that provider.
     *
     * The distinction that matters: "I do not carry that article" is an *answer*, not a fault.
     * Cross-backbone reach exists precisely because articles missing on one provider live on
     * another, so a run of misses on the fallback must not evict it. Only transport, auth and
     * protocol breakage -- or a thrown exception, which is the same thing -- trips the breaker.
     *
     * @param  Closure(ProviderClient): mixed  $run
     */
    private function attempt(
        NntpProvider $provider,
        string $operation,
        string $messageId,
        Closure $run,
        ?ProviderClient $callerClient = null,
    ): mixed {
        try {
            $result = $run($this->resolveClient($provider, $callerClient));
        } catch (\Throwable $e) {
            $this->breaker->recordFailure($provider);
            $this->logFailure($provider, $operation, $messageId, $e->getMessage());

            return new NntpError($e->getMessage());
        }

        if (! NNTPService::isError($result)) {
            $this->breaker->recordSuccess($provider);

            return $result;
        }

        if (self::isArticleAbsence($result)) {
            // The provider answered. It is healthy; it just does not have this one.
            $this->breaker->recordSuccess($provider);
            $this->logFailure($provider, $operation, $messageId, (string) $result->getMessage());

            return $result;
        }

        $this->breaker->recordFailure($provider);
        $this->logFailure($provider, $operation, $messageId, (string) $result->getMessage());

        return $result;
    }

    /**
     * Is this error the server saying it does not carry the article, rather than a fault?
     *
     * 430 answers a message-ID lookup; 423 answers an article number.
     */
    private static function isArticleAbsence(object $error): bool
    {
        return \in_array((int) $error->getCode(), [
            ResponseCode::NoSuchArticleId->value,
            ResponseCode::NoSuchArticleNumber->value,
        ], true);
    }

    /**
     * The client to talk to this provider with -- the caller's own, when it is already
     * connected to exactly this backbone, otherwise one of ours.
     */
    private function resolveClient(NntpProvider $provider, ?ProviderClient $callerClient): ProviderClient
    {
        if ($callerClient !== null && $callerClient->provider()->name === $provider->name) {
            return $callerClient;
        }

        return $this->clientFor($provider);
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
