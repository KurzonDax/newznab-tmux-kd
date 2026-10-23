<?php

declare(strict_types=1);

namespace Tests\Feature\ProviderGateway;

use App\Services\ProviderGateway\ProviderGateway;
use App\Services\ProviderGateway\ProviderOutcome;
use App\Services\TmdbClient;
use App\Services\TvProcessing\Providers\TraktProvider;
use App\Services\TvProcessing\Providers\TvdbProvider;
use App\Services\TvProcessing\Providers\TvMazeProvider;
use DariusIII\TVMaze\TVMaze;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

final class TvProviderGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Carbon::setTestNow('2026-10-23 12:00:00');
        Sleep::fake(syncWithCarbon: true);
        Http::preventStrayRequests();
        config(['nntmux.echocli' => false, 'tmdb.api_key' => 'tmdb-key', 'tmdb.retry_delay' => 0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function an_episode_lookup_asks_a_tmdb_show_once_when_every_id_leads_to_it(): void
    {
        Http::fake([
            'api.themoviedb.org/3/tv/100/season/1/episode/2*' => Http::response(['status_message' => 'not found'], 404),
            'api.themoviedb.org/3/find/*' => Http::response(['tv_results' => [['id' => 100]]]),
        ]);

        $episode = (new TmdbClient)->getTvEpisodeWithFallback(['tmdb' => 100, 'tvdb' => 123, 'imdb' => '0123456'], 1, 2);

        $this->assertNull($episode);
        Http::assertSentCount(3);
        $this->assertCount(1, Http::recorded(static fn (Request $request): bool => str_contains($request->url(), '/tv/100/season/1/episode/2')));
    }

    #[Test]
    public function tmdb_requests_from_tv_and_films_are_a_second_apart(): void
    {
        $sentAt = [];
        Http::fake(function () use (&$sentAt) {
            $sentAt[] = now()->getPreciseTimestamp(3);

            return Http::response(['id' => 1, 'title' => 'A Film', 'name' => 'A Show']);
        });
        $client = new TmdbClient;

        $client->getTvShow(1);
        $client->getMovie(1);
        $client->getTvShow(2);

        $this->assertCount(3, $sentAt);
        $this->assertGreaterThanOrEqual(1_000, $sentAt[1] - $sentAt[0]);
        $this->assertGreaterThanOrEqual(1_000, $sentAt[2] - $sentAt[1]);
    }

    #[Test]
    public function the_tv_providers_and_show_details_never_sleep_on_their_own(): void
    {
        foreach ([
            'app/Services/TvProcessing/Providers/TmdbProvider.php',
            'app/Services/TvProcessing/Providers/TraktProvider.php',
            'app/Services/TvProcessing/Providers/TvMazeProvider.php',
            'app/Services/TvProcessing/Providers/TvdbProvider.php',
            'app/Services/TvProcessing/TvShowDetails.php',
        ] as $path) {
            $this->assertDoesNotMatchRegularExpression('/\bu?sleep\s*\(|Sleep::/i', (string) file_get_contents(base_path($path)), $path);
        }
    }

    #[Test]
    public function a_trakt_show_search_answered_from_its_cache_sends_nothing_and_waits_nothing(): void
    {
        config(['nntmux_api.trakttv_api_key' => 'trakt-key']);
        // A result that does not match, so the lookup stops at the search.
        Http::fake(['api.trakt.tv/*' => Http::response([['show' => ['title' => 'Unrelated Programme', 'year' => 2020, 'ids' => ['trakt' => 7]]]])]);
        $provider = new TraktProvider;
        $provider->client->searchShows('Example Show');
        Http::assertSentCount(1);
        $before = now()->getPreciseTimestamp(3);

        $this->assertFalse($provider->getShowInfo('Example Show'));

        Http::assertSentCount(1);
        $this->assertSame($before, now()->getPreciseTimestamp(3));
    }

    #[Test]
    public function a_tvmaze_false_is_a_miss_and_only_exceptions_open_its_breaker(): void
    {
        $provider = new TvMazeProvider;
        $client = Mockery::mock(TVMaze::class);
        $client->shouldReceive('singleSearchAkas')->times(3)->andReturnFalse();
        $client->shouldReceive('search')->times(3)->andReturnFalse();
        $provider->client = $client;

        foreach (range(1, 3) as $lookup) {
            $this->assertFalse($provider->getShowInfo('Example Show'));
        }
        $this->assertFalse($this->gateway()->isOpen('tvmaze'));
        $this->assertSame(ProviderOutcome::NotFound, $this->gateway()->lastOutcome('tvmaze'));

        $failing = Mockery::mock(TVMaze::class);
        // Two lookups: the third failed attempt opens the breaker, so the fourth is never made.
        $failing->shouldReceive('singleSearchAkas')->times(2)->andThrow(new RuntimeException('curl failed'));
        $failing->shouldReceive('search')->once()->andThrow(new RuntimeException('curl failed'));
        $provider->client = $failing;
        $provider->getShowInfo('Example Show');
        $provider->getShowInfo('Example Show');

        $this->assertTrue($this->gateway()->isOpen('tvmaze'));
        $this->assertFalse($provider->getShowInfo('Example Show'), 'an open breaker asks nothing');
    }

    #[Test]
    public function the_tvdb_client_verifies_tls(): void
    {
        config(['tvdb.api_key' => null, 'tvdb.user_pin' => null]);

        $provider = new TvdbProvider;

        $httpClient = (new ReflectionProperty($provider->client, 'httpClient'))->getValue($provider->client);
        $this->assertInstanceOf(Client::class, $httpClient);
        $this->assertNotFalse($httpClient->getConfig('verify'));
    }

    #[Test]
    public function with_its_breaker_open_tvdb_finds_nothing_and_raises_nothing(): void
    {
        config(['tvdb.api_key' => 'tvdb-key', 'tvdb.user_pin' => 'pin']);
        $this->openBreaker('tvdb');
        $transport = new MockHandler;
        $this->gateway()->useHandler('tvdb', $transport);

        $provider = new TvdbProvider;

        $this->assertFalse($provider->getShowInfo('Example Show'));
        $this->assertFalse($provider->getEpisodeInfo(1, 1, 2));
        $this->assertSame(0, $this->gateway()->counters('tvdb')['requests'], 'nothing was sent, the login included');
    }

    private function openBreaker(string $provider): void
    {
        foreach (range(1, 3) as $failure) {
            $this->gateway()->report($provider, ProviderOutcome::Unavailable);
        }
        $this->assertTrue($this->gateway()->isOpen($provider));
    }

    private function gateway(): ProviderGateway
    {
        return app(ProviderGateway::class);
    }
}
