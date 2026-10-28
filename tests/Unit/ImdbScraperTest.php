<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ImdbScraper;
use App\Services\ProviderGateway\ProviderGateway;
use App\Services\ProviderGateway\ProviderPolicies;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;

class ImdbScraperTest extends ImdbScraperTestCase
{
    public function test_search_parses_suggestion_json_results(): void
    {
        [$scraper] = $this->makeScraperWithResponses([
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
        [$scraper] = $this->makeScraperWithResponses([new Response(200, [], '{"d":[{"id":"tt8694364","l":"Years and Years","y":2019,"qid":"tvMiniSeries"}]}')]);
        $this->assertSame('tvMiniSeries', $scraper->search('Years and Years')[0]['type']);
    }

    public function test_search_empty_returns_empty_array(): void
    {
        $scraper = new ImdbScraper;
        $this->assertSame([], $scraper->search(''));
    }

    public function test_an_empty_suggestion_answer_sends_no_second_request(): void
    {
        [$scraper, $mock] = $this->makeScraperWithResponses([
            new Response(200, [], '{"d":[]}'),
            $this->spareResponse(),
        ]);

        $this->assertSame([], $scraper->search('Missing film'));
        $this->assertFalse($scraper->wasSearchUnavailable());
        $this->assertSame(1, $mock->count(), 'only the suggestion search was requested');
    }

    public function test_a_waf_suggestion_answer_sends_no_second_request(): void
    {
        [$scraper, $mock] = $this->makeScraperWithResponses([
            $this->wafResponse(),
            $this->spareResponse(),
        ]);

        $this->assertSame([], $scraper->search('Missing film'));
        $this->assertTrue($scraper->wasSearchUnavailable());
        $this->assertSame(1, $mock->count(), 'only the suggestion search was requested');
    }

    public function test_an_unavailable_suggestion_answer_sends_no_second_request(): void
    {
        [$scraper, $mock] = $this->makeScraperWithResponses([
            new Response(503, [], 'unavailable'),
            $this->spareResponse(),
        ]);

        $this->assertSame([], $scraper->search('Missing film'));
        $this->assertTrue($scraper->wasSearchUnavailable());
        $this->assertSame(1, $mock->count(), 'only the suggestion search was requested');
    }

    public function test_unavailable_searches_are_not_cached_as_empty_successes(): void
    {
        [$scraper, $mock] = $this->makeScraperWithResponses([
            $this->wafResponse(),
            new Response(200, [], '{"d":[]}'),
            $this->spareResponse(),
        ]);

        $this->assertSame([], $scraper->search('Missing film'));
        $this->assertTrue($scraper->wasSearchUnavailable());
        $this->assertSame([], $scraper->search('Missing film'));
        $this->assertFalse($scraper->wasSearchUnavailable());
        $this->assertSame([], $scraper->search('Missing film'));
        $this->assertFalse($scraper->wasSearchUnavailable());
        $this->assertSame(1, $mock->count(), 'the third search was answered from the cached empty answer');
    }

    public function test_waf_blocked_searches_open_the_imdb_search_breaker(): void
    {
        [$scraper, $mock] = $this->makeScraperWithResponses([
            $this->wafResponse(),
            $this->wafResponse(),
            $this->wafResponse(),
            $this->spareResponse(),
        ]);
        $gateway = app(ProviderGateway::class);

        foreach (['First missing film', 'Second missing film', 'Third missing film'] as $query) {
            $this->assertSame([], $scraper->search($query));
            $this->assertTrue($scraper->wasSearchUnavailable());
        }
        $this->assertTrue($gateway->isOpen('imdb_search'));
        $this->assertSame(3, $gateway->counters('imdb_search')['requests']);

        $this->assertSame([], $scraper->search('Fourth missing film'));
        $this->assertTrue($scraper->wasSearchUnavailable());
        $this->assertSame(1, $mock->count(), 'the open breaker sent no request');
        $this->assertSame(3, $gateway->counters('imdb_search')['requests']);
    }

    public function test_the_imdb_gateway_entry_is_gone(): void
    {
        $policies = app(ProviderPolicies::class);

        $this->assertSame('imdb_search', $policies->for('imdb_search')->provider);

        $this->expectException(InvalidArgumentException::class);
        $policies->for('imdb');
    }

    private function wafResponse(): Response
    {
        return new Response(202, [], '<script>window.gokuProps={}</script>');
    }

    /** A spare answer stays queued unless something sends a second request. */
    private function spareResponse(): Response
    {
        return new Response(200, [], '<p>No results found</p>');
    }

    /**
     * @param  array<int, Response>  $responses
     * @return array{ImdbScraper, MockHandler}
     */
    private function makeScraperWithResponses(array $responses): array
    {
        $mock = new MockHandler($responses);
        $client = new Client([
            'handler' => HandlerStack::create($mock),
            'http_errors' => false,
        ]);

        return [new ImdbScraper($client), $mock];
    }
}
