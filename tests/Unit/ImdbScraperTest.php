<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ImdbScraper;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;

class ImdbScraperTest extends ImdbScraperTestCase
{
    public function test_fetch_by_id_parses_title_page_from_fixture(): void
    {
        $scraper = $this->makeScraperWithResponses([
            new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], file_get_contents(base_path('tests/Fixtures/imdb/title_jsonld.html')) ?: ''),
        ]);

        $data = $scraper->fetchById('1234567');

        $this->assertIsArray($data);
        $this->assertSame('1234567', $data['imdbid']);
        $this->assertSame('Example Movie', $data['title']);
        $this->assertSame('2024', $data['year']);
        $this->assertSame('An example plot goes here.', $data['plot']);
        $this->assertSame('7.3', $data['rating']);
        $this->assertSame('https://example.com/poster_from_jsonld.jpg', $data['cover']);
        $this->assertSame(['Action', 'Adventure'], $data['genre']);
        $this->assertSame(['Famous Director'], $data['director']);
        $this->assertSame(['First Actor', 'Second Actor'], $data['actors']);
        $this->assertSame('English, Spanish', $data['language']);
        $this->assertSame('movie', $data['type']);
    }

    public function test_fetch_by_id_detects_waf_challenge_and_marks_temporary_block(): void
    {
        // A spare response stays queued unless something sends a second request.
        $mock = new MockHandler([
            new Response(202, ['Content-Type' => 'text/html; charset=UTF-8'], '<html><script>window.awsWafCookieDomainList=[];window.gokuProps={};</script></html>'),
            new Response(404, [], 'Not found'),
        ]);
        $scraper = new ImdbScraper(new Client(['handler' => HandlerStack::create($mock), 'http_errors' => false]));

        $data = $scraper->fetchById('1234567');

        $this->assertFalse($data);
        $this->assertTrue($scraper->wasBlockedByWaf());
        $this->assertSame('waf_block', $scraper->getLastFailureReason());
        $this->assertNull($scraper->getLastFetchSource());
        $this->assertSame(1, $mock->count(), 'only the IMDb title page was requested');
        $this->assertFalse(Cache::has('imdb_scrape_id_1234567'), 'a WAF challenge is no answer and is not cached');
    }

    public function test_search_parses_suggestion_json_results(): void
    {
        $scraper = $this->makeScraperWithResponses([
            new Response(200, ['Content-Type' => 'application/json; charset=UTF-8'], file_get_contents(base_path('tests/Fixtures/imdb/search_inception.json')) ?: '{}'),
        ]);

        $results = $scraper->search('Inception');
        $this->assertSame(['movie', 'short'], array_column($results, 'type'));

        $this->assertNotEmpty($results);
        $this->assertSame('1375666', $results[0]['imdbid']);
        $this->assertSame('Inception', $results[0]['title']);
        $this->assertSame('2010', $results[0]['year']);
    }

    public function test_search_keeps_miniseries_type(): void
    {
        $scraper = $this->makeScraperWithResponses([new Response(200, [], '{"d":[{"id":"tt8694364","l":"Years and Years","y":2019,"qid":"tvMiniSeries"}]}')]);
        $this->assertSame('tvMiniSeries', $scraper->search('Years and Years')[0]['type']);
    }

    public function test_html_search_types_are_scoped_to_each_result_row(): void
    {
        $scraper = $this->makeScraperWithResponses([
            new Response(200, [], '{}'),
            new Response(200, [], '<ul><li><a href="/title/tt1234567/?ref_=fn">Con Air</a><span>1997</span></li><li><a href="/title/tt8694364/?ref_=fn">Years and Years</a><span>2019 TV Mini Series</span></li></ul>'),
        ]);
        $results = $scraper->search('Years and Years');
        $this->assertSame('', $results[0]['type']);
        $this->assertSame('1997', $results[0]['year']);
        $this->assertSame('tvMiniSeries', $results[1]['type']);
    }

    public function test_unavailable_searches_are_not_cached_as_empty_successes(): void
    {
        $scraper = $this->makeScraperWithResponses([
            new Response(202, [], '<script>window.gokuProps={}</script>'),
            new Response(503, [], 'unavailable'),
            new Response(200, [], '{"d":[]}'),
            new Response(200, [], '<p>No results found</p>'),
        ]);
        $this->assertSame([], $scraper->search('Missing film'));
        $this->assertTrue($scraper->wasBlockedByWaf());
        $this->assertTrue($scraper->wasSearchUnavailable());
        $this->assertSame([], $scraper->search('Missing film'));
        $this->assertFalse($scraper->wasSearchUnavailable());
        $this->assertSame([], $scraper->search('Missing film'));
        $this->assertFalse($scraper->wasSearchUnavailable());
    }

    public function test_search_empty_returns_empty_array(): void
    {
        $scraper = new ImdbScraper;
        $this->assertSame([], $scraper->search(''));
    }

    /**
     * @param  array<int, Response>  $responses
     */
    private function makeScraperWithResponses(array $responses): ImdbScraper
    {
        $mock = new MockHandler($responses);
        $client = new Client([
            'handler' => HandlerStack::create($mock),
            'http_errors' => false,
        ]);

        return new ImdbScraper($client);
    }
}
