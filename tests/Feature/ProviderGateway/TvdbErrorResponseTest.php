<?php

declare(strict_types=1);

namespace Tests\Feature\ProviderGateway;

use App\Services\ProviderGateway\ProviderGateway;
use App\Services\TvProcessing\Providers\TvdbProvider;
use CanIHaveSomeCoffee\TheTVDbAPI\Route\RouteFactory;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * TVDB error responses (any status but 200, 401 and 404, or a 200 that is not JSON) are a failed
 * lookup or login, never an exception that ends the TV run.
 */
final class TvdbErrorResponseTest extends TestCase
{
    private MockHandler $transport;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();

        ProductionTables::fromAuthority()->create('videos', [
            'id', 'type', 'title', 'started', 'source', 'tvdb', 'tvmaze', 'tmdb', 'trakt',
        ]);
        ProductionTables::fromAuthority()->create('videos_aliases', ['videos_id', 'title']);
        ProductionTables::fromAuthority()->create('tv_episodes', [
            'id', 'videos_id', 'series', 'episode', 'se_complete', 'title', 'firstaired', 'summary',
        ]);
        ProductionTables::fromAuthority()->create('tv_info', ['videos_id', 'status']);

        $this->resetTvdbRoutes();
        Cache::flush();
        Carbon::setTestNow('2026-11-20 12:00:00');
        Sleep::fake();
        Http::preventStrayRequests();
        Log::spy();
        config([
            'nntmux.echocli' => false,
            'tvdb.api_key' => 'tvdb-key',
            'tvdb.user_pin' => 'pin',
            'tmdb.api_key' => null,
            'nntmux_api.trakttv_api_key' => null,
            'nntmux_api.fanarttv_api_key' => null,
        ]);
        $this->transport = new MockHandler;
        app(ProviderGateway::class)->useHandler('tvdb', $this->transport);
    }

    protected function tearDown(): void
    {
        $this->resetTvdbRoutes();
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function a_400_on_search_is_a_failed_lookup(): void
    {
        $provider = $this->providerAnswering(new Response(400));

        $this->assertFalse($provider->getShowInfo('Example Show'));
        $this->assertAllAnswersServed();
        $this->assertWarned('Got status code 400 from service at path search', 'Example Show');
    }

    #[Test]
    public function a_503_on_search_is_a_failed_lookup(): void
    {
        $provider = $this->providerAnswering(new Response(503));

        $this->assertFalse($provider->getShowInfo('Example Show'));
        $this->assertAllAnswersServed();
        $this->assertWarned('Got status code 503 from service at path search', 'Example Show');
    }

    #[Test]
    public function a_search_answer_that_is_not_json_is_a_failed_lookup(): void
    {
        $provider = $this->providerAnswering(new Response(200, [], 'Service temporarily unavailable'));

        $this->assertFalse($provider->getShowInfo('Example Show'));
        $this->assertAllAnswersServed();
        $this->assertWarned('Could not decode JSON data', 'Example Show');
    }

    #[Test]
    public function a_503_on_the_episode_list_is_a_failed_lookup_that_stores_nothing(): void
    {
        $provider = $this->providerAnswering(new Response(503));

        $this->assertFalse($provider->getEpisodeInfo(396947, -1, -1, 12));
        $this->assertAllAnswersServed();
        $this->assertSame(0, DB::table('tv_episodes')->count());
        $this->assertWarned('Got status code 503 from service at path series/396947/episodes/default/eng');
    }

    #[Test]
    public function a_503_on_the_single_episode_lookup_is_a_failed_lookup(): void
    {
        $provider = $this->providerAnswering(new Response(503));

        $this->assertFalse($provider->getEpisodeInfo(396947, 1, 2));
        $this->assertAllAnswersServed();
        $this->assertWarned('Got status code 503 from service at path series/396947/episodes/default');
    }

    #[Test]
    public function a_503_on_the_artworks_clears_a_poster_left_from_an_earlier_show(): void
    {
        $provider = $this->providerAnswering(new Response(503), new Response(404));
        $provider->posterUrl = 'https://artworks.thetvdb.com/earlier-show.jpg';

        $show = $provider->formatShowInfo((object) [
            'tvdb_id' => 396947,
            'name' => 'Example Show',
            'overview' => 'Overview',
            'first_air_time' => '2021-01-01',
        ]);

        $this->assertSame('Example Show', $show['title']);
        $this->assertSame('', $show['poster']);
        $this->assertAllAnswersServed();
        $this->assertWarned('Got status code 503 from service at path series/396947/artworks');
    }

    #[Test]
    public function a_503_on_login_leaves_the_provider_online_without_a_token(): void
    {
        $this->transport->append(new Response(503), new Response(404));

        $provider = new TvdbProvider;

        $this->assertSame('', $provider->token);
        $this->assertFalse($provider->getEpisodeInfo(396947, 1, 2), 'the provider still asks TVDB');
        $this->assertAllAnswersServed();
        $this->assertWarned('Got status code 503 from service at path login');
    }

    #[Test]
    public function a_503_on_a_later_providers_login_does_not_break_its_next_lookup(): void
    {
        $this->transport->append($this->loginAnswer('first-token'));
        new TvdbProvider;
        $this->transport->append(new Response(503), new Response(200, [], (string) json_encode(['data' => []])));

        $provider = new TvdbProvider;

        $this->assertFalse($provider->getShowInfo('Example Show'));
        $this->assertAllAnswersServed();
        $this->assertWarned('Got status code 503 from service at path login');
    }

    #[Test]
    public function a_503_on_the_login_after_a_401_does_not_escape_the_lookup(): void
    {
        $provider = $this->providerAnswering(new Response(401), new Response(503));

        $this->assertFalse($provider->getShowInfo('Example Show'));
        $this->assertAllAnswersServed();
        $this->assertWarned('Got status code 503 from service at path login');
    }

    #[Test]
    public function a_400_on_one_releases_search_still_processes_the_next_release(): void
    {
        ProductionTables::fromAuthority()->create('releases', [
            'id', 'searchname', 'videos_id', 'tv_episodes_id', 'postdate',
        ]);
        DB::table('releases')->insert([
            ['id' => 1, 'searchname' => 'Alpha.Example.S01E02.1080p.WEB-DL', 'videos_id' => 0, 'tv_episodes_id' => 0, 'postdate' => now()->subDay()],
            ['id' => 2, 'searchname' => 'Beta.Example.S01E02.1080p.WEB-DL', 'videos_id' => 0, 'tv_episodes_id' => 0, 'postdate' => now()->subDay()],
        ]);
        $this->transport->append(
            $this->loginAnswer('token'),
            new Response(400),
            new Response(200, [], (string) json_encode(['data' => []])),
        );
        $provider = new class extends TvdbProvider
        {
            public function getTvReleases(string $groupID = '', string $guidChar = '', int $lookupSetting = 1, int $status = 0): array|EloquentCollection|int|Collection
            {
                return DB::table('releases')->orderBy('id')->get(['id', 'searchname', 'videos_id'])
                    ->map(fn (object $row): array => (array) $row);
            }
        };

        $provider->processSite('', '', 1);

        $this->assertAllAnswersServed();
        $episodeIds = DB::table('releases')->orderBy('id')->pluck('tv_episodes_id')->map(fn (mixed $id): int => (int) $id)->all();
        $this->assertSame([-1, -1], $episodeIds, 'both releases were handed on to TVMaze');
        $this->assertWarned('Got status code 400 from service at path search', 'Alpha Example');
    }

    #[Test]
    public function a_401_on_search_still_logs_in_again_and_the_next_request_carries_the_new_token(): void
    {
        $provider = $this->providerAnswering(
            new Response(401),
            $this->loginAnswer('second-token'),
            new Response(200, [], (string) json_encode(['data' => []])),
        );

        $this->assertFalse($provider->getShowInfo('Example Show'));
        $this->assertFalse($provider->getShowInfo('Example Show'));

        $this->assertAllAnswersServed();
        $this->assertSame('second-token', $provider->token);
        $this->assertSame('Bearer second-token', $this->transport->getLastRequest()?->getHeaderLine('Authorization'));
    }

    #[Test]
    public function a_401_on_login_still_puts_the_provider_in_local_mode(): void
    {
        $this->transport->append(new Response(401));

        $provider = new TvdbProvider;

        $this->assertFalse($provider->getEpisodeInfo(396947, 1, 2));
        $this->assertSame(1, app(ProviderGateway::class)->counters('tvdb')['requests'], 'only the login was sent');
    }

    #[Test]
    public function a_404_on_search_is_still_a_miss(): void
    {
        $provider = $this->providerAnswering(new Response(404));

        $this->assertFalse($provider->getShowInfo('Example Show'));
        $this->assertAllAnswersServed();
    }

    /** The TVDB library keeps its route objects, and with them the first client, in a static. */
    private function resetTvdbRoutes(): void
    {
        (new \ReflectionProperty(RouteFactory::class, 'routeInstances'))->setValue(null, []);
    }

    /** Builds a provider that logs in, then answers its requests with these responses in turn. */
    private function providerAnswering(Response ...$responses): TvdbProvider
    {
        $this->transport->append($this->loginAnswer('first-token'), ...$responses);

        return new TvdbProvider;
    }

    private function loginAnswer(string $token): Response
    {
        return new Response(200, [], (string) json_encode(['data' => ['token' => $token]]));
    }

    private function assertAllAnswersServed(): void
    {
        $this->assertSame(0, $this->transport->count(), 'every queued TVDB answer was requested');
    }

    private function assertWarned(string $libraryMessage, ?string $title = null): void
    {
        Log::shouldHaveReceived('warning')->withArgs(
            fn (mixed $message): bool => \is_string($message)
                && str_contains($message, 'TVDB')
                && str_contains($message, $libraryMessage)
                && ($title === null || str_contains($message, $title)),
        )->once();
    }
}
