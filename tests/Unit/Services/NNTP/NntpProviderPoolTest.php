<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NNTP;

use App\Services\NNTP\Contracts\ProviderClient;
use App\Services\NNTP\NntpProvider;
use App\Services\NNTP\NntpProviderPool;
use App\Services\NNTP\NNTPService;
use App\Services\NNTP\ProviderCircuitBreaker;
use DariusIII\NetNntp\Error as NntpError;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pool behaviour against fake providers: ordering, per-article failover, breaker
 * trip/cooldown, STAT stopping at the first EXISTS, and config parsing.
 */
final class NntpProviderPoolTest extends TestCase
{
    private float $now = 1_000.0;

    protected function tearDown(): void
    {
        NntpProviderPool::forgetConfiguredProviders();
        parent::tearDown();
    }

    #[Test]
    public function it_serves_articles_from_the_first_provider_in_config_order(): void
    {
        $clients = [
            'one' => $this->client(['<a>' => 'FROM-ONE']),
            'two' => $this->client(['<a>' => 'FROM-TWO']),
        ];

        $pool = $this->pool([$this->provider(1, 'one'), $this->provider(2, 'two')], $clients);

        $this->assertSame('FROM-ONE', $pool->fetchArticleBody('<a>'));
        $this->assertSame(0, $clients['two']->calls, 'Provider 2 must not be touched while provider 1 answers.');
    }

    #[Test]
    public function it_fails_over_per_article_to_the_next_provider(): void
    {
        $clients = [
            'one' => $this->client(['<a>' => 'FROM-ONE']),                 // has <a>, not <b>
            'two' => $this->client(['<a>' => 'FROM-TWO', '<b>' => 'B-TWO']),
        ];

        $pool = $this->pool([$this->provider(1, 'one'), $this->provider(2, 'two')], $clients);

        $this->assertSame('FROM-ONE', $pool->fetchArticleBody('<a>'));
        $this->assertSame('B-TWO', $pool->fetchArticleBody('<b>'), 'Missing on provider 1 must be served by provider 2.');
    }

    #[Test]
    public function an_article_fails_only_when_every_enabled_provider_fails(): void
    {
        $pool = $this->pool(
            [$this->provider(1, 'one'), $this->provider(2, 'two')],
            ['one' => $this->client([]), 'two' => $this->client([])],
        );

        $result = $pool->fetchArticleBody('<gone>');

        $this->assertTrue(NNTPService::isError($result));
    }

    #[Test]
    public function disabled_providers_are_excluded_from_article_operations(): void
    {
        $clients = [
            'one' => $this->client([]),
            'two' => $this->client(['<a>' => 'FROM-TWO']),
        ];

        $pool = $this->pool(
            [$this->provider(1, 'one'), $this->provider(2, 'two', enabled: false)],
            $clients,
        );

        $this->assertTrue(NNTPService::isError($pool->fetchArticleBody('<a>')));
        $this->assertSame(0, $clients['two']->calls, 'A disabled provider must never be contacted.');
    }

    #[Test]
    public function stat_stops_at_the_first_provider_that_has_the_article(): void
    {
        $clients = [
            'one' => $this->client(['<a>' => 'BODY']),
            'two' => $this->client(['<a>' => 'BODY']),
        ];

        $pool = $this->pool([$this->provider(1, 'one'), $this->provider(2, 'two')], $clients);

        $this->assertTrue($pool->articleExists('<a>'));
        $this->assertSame(0, $clients['two']->calls, 'STAT must stop at the first EXISTS.');
    }

    #[Test]
    public function stat_walks_on_when_a_provider_does_not_carry_the_article(): void
    {
        $pool = $this->pool(
            [$this->provider(1, 'one'), $this->provider(2, 'two')],
            ['one' => $this->client([]), 'two' => $this->client(['<a>' => 'BODY'])],
        );

        $this->assertTrue($pool->articleExists('<a>'));
    }

    #[Test]
    public function a_clean_miss_does_not_trip_the_breaker(): void
    {
        $provider = $this->provider(1, 'one');
        $breaker = new ProviderCircuitBreaker(5, 60, fn (): float => $this->now);
        $pool = $this->pool([$provider], ['one' => $this->client([])], $breaker);

        for ($i = 0; $i < 10; $i++) {
            $pool->articleExists('<missing>');
        }

        $this->assertSame(0, $breaker->consecutiveFailures($provider));
        $this->assertFalse($breaker->isOpen($provider));
    }

    #[Test]
    public function five_consecutive_failures_trip_the_breaker_and_it_reopens_after_the_cooldown(): void
    {
        $provider = $this->provider(1, 'one');
        $breaker = new ProviderCircuitBreaker(5, 60, fn (): float => $this->now);
        $client = $this->client([], alwaysFail: true);
        $pool = $this->pool([$provider], ['one' => $client], $breaker);

        for ($i = 0; $i < 5; $i++) {
            $pool->fetchArticleBody('<a>');
        }

        $this->assertTrue($breaker->isOpen($provider));

        $callsBefore = $client->calls;
        $pool->fetchArticleBody('<a>');
        $this->assertSame($callsBefore, $client->calls, 'A tripped provider must be skipped for article ops.');

        $this->now += 60;

        $pool->fetchArticleBody('<a>');
        $this->assertGreaterThan($callsBefore, $client->calls, 'The provider must be retried once the cooldown expires.');
    }

    #[Test]
    public function a_success_clears_the_failure_streak(): void
    {
        $provider = $this->provider(1, 'one');
        $breaker = new ProviderCircuitBreaker(5, 60, fn (): float => $this->now);
        $client = $this->client(['<good>' => 'DATA'], failWith: new NntpError('400 service unavailable', 400));
        $pool = $this->pool([$provider], ['one' => $client], $breaker);

        $pool->fetchArticleBody('<bad>');
        $pool->fetchArticleBody('<bad>');
        $this->assertSame(2, $breaker->consecutiveFailures($provider));

        $pool->fetchArticleBody('<good>');
        $this->assertSame(0, $breaker->consecutiveFailures($provider));
    }

    #[Test]
    public function a_caller_supplied_client_is_reused_for_its_own_provider(): void
    {
        // The caller already holds a connection to provider 1. Opening a second one to the same
        // backbone to run the same walk would burn a connection out of a shared budget.
        $callerClient = $this->client(['<a>' => 'FROM-CALLER'], provider: $this->provider(1, 'one'));
        $poolOwnedPrimary = $this->client(['<a>' => 'FROM-POOL']);

        $pool = $this->pool(
            [$this->provider(1, 'one'), $this->provider(2, 'two')],
            ['one' => $poolOwnedPrimary, 'two' => $this->client([])],
        );

        $this->assertSame('FROM-CALLER', $pool->fetchArticleBody('<a>', callerClient: $callerClient));
        $this->assertSame(0, $poolOwnedPrimary->calls);
    }

    #[Test]
    public function an_article_a_provider_does_not_carry_is_not_held_against_it(): void
    {
        // Cross-backbone reach exists because articles missing on one provider live on another,
        // so a run of misses on the fallback must not evict the fallback.
        $provider = $this->provider(1, 'one');
        $breaker = new ProviderCircuitBreaker(5, 60, fn (): float => $this->now);
        $pool = $this->pool([$provider], ['one' => $this->client([])], $breaker);

        for ($i = 0; $i < 10; $i++) {
            $pool->fetchArticleBody('<missing'.$i.'>');
        }

        $this->assertSame(0, $breaker->consecutiveFailures($provider));
        $this->assertFalse($breaker->isOpen($provider));
    }

    #[Test]
    public function fetching_a_list_concatenates_the_bodies_in_order(): void
    {
        $pool = $this->pool(
            [$this->provider(1, 'one'), $this->provider(2, 'two')],
            [
                'one' => $this->client(['<a>' => 'AAA', '<c>' => 'CCC']),
                'two' => $this->client(['<b>' => 'BBB']),
            ],
        );

        $this->assertSame('AAABBBCCC', $pool->fetchArticleBodies(['<a>', '<b>', '<c>']));
    }

    #[Test]
    public function a_list_returns_what_it_collected_when_a_later_article_fails_everywhere(): void
    {
        $pool = $this->pool(
            [$this->provider(1, 'one')],
            ['one' => $this->client(['<a>' => 'AAA'])],
        );

        $this->assertSame('AAA', $pool->fetchArticleBodies(['<a>', '<gone>']));
    }

    #[Test]
    public function a_list_surfaces_the_error_when_the_very_first_article_fails_everywhere(): void
    {
        $pool = $this->pool([$this->provider(1, 'one')], ['one' => $this->client([])]);

        $this->assertTrue(NNTPService::isError($pool->fetchArticleBodies(['<gone>', '<also-gone>'])));
    }

    #[Test]
    public function duplicate_provider_names_are_rejected(): void
    {
        // NAME keys the client map, the breaker state and the monitor's rows. Sharing one would
        // silently share all three.
        config()->set('nntmux_nntp.providers', [
            ['position' => 1, 'name' => 'backbone', 'host' => 'a.example.org'],
            ['position' => 2, 'name' => 'backbone', 'host' => 'b.example.org'],
        ]);
        NntpProviderPool::forgetConfiguredProviders();

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Duplicate NNTP provider NAME');

            NntpProviderPool::configuredProviders();
        } finally {
            NntpProviderPool::forgetConfiguredProviders();
        }
    }

    #[Test]
    public function config_parsing_orders_by_position_and_keeps_disabled_providers_visible(): void
    {
        $pool = $this->pool(
            [$this->provider(2, 'two', enabled: false), $this->provider(1, 'one')],
            ['one' => $this->client([]), 'two' => $this->client([])],
        );

        $this->assertSame(['two', 'one'], array_map(fn ($p) => $p->name, $pool->providers()));
        $this->assertSame(['one'], array_map(fn ($p) => $p->name, $pool->enabledProviders()));
    }

    #[Test]
    public function a_provider_without_a_name_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('NNTP_PROVIDER_2_NAME');

        NntpProvider::fromConfig(['position' => 2, 'name' => '', 'host' => 'news.example.org']);
    }

    #[Test]
    public function every_provider_label_carries_the_name(): void
    {
        $provider = NntpProvider::fromConfig([
            'position' => 2,
            'name' => 'eu-backbone',
            'host' => 'news.example.org',
            'port' => 563,
        ]);

        $this->assertStringContainsString('eu-backbone', $provider->label());
        $this->assertStringContainsString('news.example.org:563', $provider->label());
    }

    /**
     * @param  list<NntpProvider>  $providers
     * @param  array<string, ProviderClient>  $clients
     */
    private function pool(array $providers, array $clients, ?ProviderCircuitBreaker $breaker = null): NntpProviderPool
    {
        return new NntpProviderPool(
            $providers,
            $breaker ?? new ProviderCircuitBreaker(5, 60, fn (): float => $this->now),
            static fn (NntpProvider $provider): ProviderClient => $clients[$provider->name],
        );
    }

    private function provider(int $position, string $name, bool $enabled = true): NntpProvider
    {
        return new NntpProvider(
            position: $position,
            name: $name,
            host: $name.'.example.org',
            port: 119,
            ssl: false,
            username: 'u',
            password: 'p',
            connections: 10,
            timeout: 120,
            enabled: $enabled,
        );
    }

    /**
     * A stand-in for one provider's NNTPService: it holds a fixed set of articles and
     * counts how often the pool actually talked to it.
     *
     * @param  array<string, string>  $articles
     */
    private function client(
        array $articles,
        bool $alwaysFail = false,
        ?NntpError $failWith = null,
        ?NntpProvider $provider = null,
    ): ProviderClient {
        return new class($articles, $alwaysFail, $failWith, $provider ?? $this->provider(1, 'fake')) implements ProviderClient
        {
            public int $calls = 0;

            /** @param array<string, string> $articles */
            public function __construct(
                private array $articles,
                private bool $alwaysFail,
                private ?NntpError $failWith,
                private NntpProvider $provider,
            ) {}

            public function fetchArticleBody(string $messageId): mixed
            {
                $this->calls++;

                if ($this->alwaysFail) {
                    return new NntpError('400 service unavailable', 400);
                }

                // 430 is "I do not carry it" -- the provider is answering, not broken.
                return $this->articles[$messageId]
                    ?? $this->failWith
                    ?? new NntpError('430 no such article', 430);
            }

            public function statArticle(string $messageId): mixed
            {
                $this->calls++;

                if ($this->alwaysFail) {
                    return new NntpError('400 service unavailable', 400);
                }

                return isset($this->articles[$messageId]);
            }

            public function doConnect(bool $compression = true): mixed
            {
                return true;
            }

            public function doQuit(bool $force = false): mixed
            {
                return true;
            }

            public function provider(): NntpProvider
            {
                return $this->provider;
            }
        };
    }
}
