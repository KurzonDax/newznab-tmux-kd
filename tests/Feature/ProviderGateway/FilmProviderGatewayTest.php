<?php

declare(strict_types=1);

namespace Tests\Feature\ProviderGateway;

use App\Services\ImdbScraper;
use App\Services\MovieService;
use App\Services\ProviderGateway\ProviderGateway;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\Unit\ImdbScraperTestCase;

/**
 * A film lookup that got no answer is never remembered as not found; a real not-found keeps
 * the time and key it had before the gateway.
 */
final class FilmProviderGatewayTest extends ImdbScraperTestCase
{
    private const string IMDB_ID = '0137523';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-23 12:00:00');
        Sleep::fake(syncWithCarbon: true);
        Http::preventStrayRequests();
        config([
            'nntmux.echocli' => false,
            'tmdb.api_key' => 'tmdb-key',
            'tmdb.retry_delay' => 0,
            'nntmux_api.omdb_api_key' => '',
            'nntmux_api.trakttv_api_key' => '',
            'nntmux_api.fanarttv_api_key' => '',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function a_tmdb_timeout_is_not_cached_and_a_tmdb_not_found_is_kept_seven_days(): void
    {
        config(['tmdb.retry_times' => 1]);
        $timedOut = true;
        Http::fake(['api.themoviedb.org/3/find/*' => static function () use (&$timedOut) {
            if ($timedOut) {
                throw new ConnectionException('timed out');
            }

            return Http::response(['movie_results' => []]);
        }]);

        $this->assertFalse((new MovieService)->fetchTMDBProperties(self::IMDB_ID));
        $this->assertFalse(Cache::has(MovieService::tmdbCacheKey('tt'.self::IMDB_ID)));

        $timedOut = false;
        $this->assertFalse((new MovieService)->fetchTMDBProperties(self::IMDB_ID));
        $this->assertFalse(Cache::get(MovieService::tmdbCacheKey('tt'.self::IMDB_ID)));
        $this->travel(7)->days();
        $this->travel(-1)->minutes();
        $this->assertTrue(Cache::has(MovieService::tmdbCacheKey('tt'.self::IMDB_ID)));
        $this->travel(2)->minutes();
        $this->assertFalse(Cache::has(MovieService::tmdbCacheKey('tt'.self::IMDB_ID)));
    }

    #[Test]
    public function an_imdb_waf_challenge_with_no_fallback_answer_is_not_cached_under_either_key(): void
    {
        $this->useImdbResponses([
            new Response(202, [], '<script>window.gokuProps={}</script>'),
            new ConnectException('Could not resolve host: api.imdbapi.dev', new PsrRequest('GET', 'https://api.imdbapi.dev/titles/tt'.self::IMDB_ID)),
        ]);

        $this->assertFalse((new MovieService)->fetchIMDBProperties(self::IMDB_ID));

        $this->assertFalse(Cache::has('imdb_scrape_id_'.self::IMDB_ID));
        $this->assertFalse(Cache::has('imdb_movie_'.md5(self::IMDB_ID)));
    }

    #[Test]
    public function an_imdb_waf_challenge_whose_fallback_is_skipped_is_not_cached(): void
    {
        // The previous fallback was moments ago: this one is skipped, not sent.
        app(ProviderGateway::class)->pace('imdbapi_dev');
        $this->useImdbResponses([new Response(202, [], '<script>window.gokuProps={}</script>')]);

        $this->assertFalse((new MovieService)->fetchIMDBProperties(self::IMDB_ID));

        $this->assertFalse(Cache::has('imdb_scrape_id_'.self::IMDB_ID));
        $this->assertFalse(Cache::has('imdb_movie_'.md5(self::IMDB_ID)));
        $this->assertSame(1, app(ProviderGateway::class)->counters('imdbapi_dev')['pacer_skips']);
    }

    #[Test]
    public function an_imdb_not_found_is_kept_six_hours_under_both_keys(): void
    {
        $this->useImdbResponses([
            new Response(404, [], 'Not found'),
            new Response(404, [], '{"code":5,"message":"NOT_FOUND"}'),
        ]);

        $this->assertFalse((new MovieService)->fetchIMDBProperties(self::IMDB_ID));

        $this->assertFalse(Cache::get('imdb_scrape_id_'.self::IMDB_ID));
        $this->assertFalse(Cache::get('imdb_movie_'.md5(self::IMDB_ID)));
        $this->travel(6)->hours();
        $this->travel(1)->seconds();
        $this->assertFalse(Cache::has('imdb_scrape_id_'.self::IMDB_ID));
        $this->assertFalse(Cache::has('imdb_movie_'.md5(self::IMDB_ID)));
    }

    #[Test]
    public function an_omdb_quota_401_or_failure_is_not_cached_and_its_not_found_is_kept_six_hours(): void
    {
        config(['nntmux_api.omdb_api_key' => 'omdb-key']);
        app(ProviderGateway::class)->useHandler('omdb', new MockHandler([
            new Response(401, [], '{"Response":"False","Error":"Request limit reached!"}'),
            new Response(503, [], 'down'),
            new Response(200, [], '{"Response":"False","Error":"Incorrect IMDb ID."}'),
        ]));
        $service = new MovieService;

        $this->assertFalse($service->fetchOmdbAPIProperties(self::IMDB_ID));
        $this->assertFalse(Cache::has('omdb_movie_'.md5(self::IMDB_ID)), 'a spent quota is no answer');
        $this->assertFalse($service->fetchOmdbAPIProperties(self::IMDB_ID));
        $this->assertFalse(Cache::has('omdb_movie_'.md5(self::IMDB_ID)), 'a failure is no answer');

        $this->assertFalse($service->fetchOmdbAPIProperties(self::IMDB_ID));
        $this->assertFalse(Cache::get('omdb_movie_'.md5(self::IMDB_ID)));
        $this->travel(6)->hours();
        $this->travel(1)->seconds();
        $this->assertFalse(Cache::has('omdb_movie_'.md5(self::IMDB_ID)));
    }

    #[Test]
    public function a_trakt_film_exception_is_not_cached(): void
    {
        config(['nntmux_api.trakttv_api_key' => 'trakt-key', 'nntmux_api.trakttv_retry_times' => 1]);
        Http::fake(['api.trakt.tv/*' => static fn () => throw new ConnectionException('timed out')]);

        $this->assertFalse((new MovieService)->fetchTraktTVProperties(self::IMDB_ID));

        $this->assertFalse(Cache::has('trakt_movie_'.md5(self::IMDB_ID)));
    }

    /** @param list<Response|ConnectException> $responses */
    private function useImdbResponses(array $responses): void
    {
        $client = new Client(['handler' => HandlerStack::create(new MockHandler($responses)), 'http_errors' => false]);
        $this->app->bind(ImdbScraper::class, static fn (): ImdbScraper => new ImdbScraper($client));
    }
}
