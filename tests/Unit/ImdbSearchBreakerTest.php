<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ImdbScraper;
use App\Services\ProviderGateway\ProviderGateway;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/**
 * IMDb's suggestion search has its own gateway breaker (`imdb_search`), so WAF-blocked title
 * pages (`imdb`) don't stop it, and the reverse.
 */
class ImdbSearchBreakerTest extends ImdbScraperTestCase
{
    public function test_title_page_blocks_do_not_stop_the_search(): void
    {
        [$scraper] = $this->makeScraperWithResponses([
            ...$this->wafResponses(3),
            $this->searchFixtureResponse(),
        ]);

        $this->blockThreeTitlePages($scraper);
        $this->assertTrue($this->gateway()->isOpen('imdb'));

        $results = $scraper->search('Inception');

        $this->assertNotEmpty($results);
        $this->assertSame('1375666', $results[0]['imdbid']);
        $this->assertFalse($this->gateway()->isOpen('imdb_search'));
    }

    public function test_search_blocks_do_not_stop_title_pages(): void
    {
        $responses = [];
        for ($search = 0; $search < 3; $search++) {
            $responses[] = $this->wafResponse();
            $responses[] = new Response(200, [], '<p>No results found</p>');
        }
        $responses[] = $this->titleFixtureResponse();
        [$scraper] = $this->makeScraperWithResponses($responses);

        foreach (['First missing film', 'Second missing film', 'Third missing film'] as $query) {
            $scraper->search($query);
        }
        $this->assertTrue($this->gateway()->isOpen('imdb_search'));
        $this->assertFalse($this->gateway()->isOpen('imdb'));

        $data = $scraper->fetchById('1234567');

        $this->assertIsArray($data);
        $this->assertSame('Example Movie', $data['title']);
    }

    public function test_an_empty_search_answer_is_still_not_found_while_imdb_is_open(): void
    {
        // A spare response stays queued unless the refused HTML fallback is sent after all.
        [$scraper, $mock] = $this->makeScraperWithResponses([
            ...$this->wafResponses(3),
            new Response(200, [], '{"d":[]}'),
            new Response(200, [], '<p>No results found</p>'),
        ]);
        $this->blockThreeTitlePages($scraper);
        $this->assertTrue($this->gateway()->isOpen('imdb'));

        $this->assertSame([], $scraper->search('Missing film'));

        $this->assertFalse($scraper->wasSearchUnavailable());
        $this->assertSame(1, $mock->count(), 'the HTML fallback sent no request');
    }

    public function test_each_request_is_counted_under_its_own_name(): void
    {
        [$scraper] = $this->makeScraperWithResponses([
            $this->searchFixtureResponse(),
            $this->titleFixtureResponse(),
        ]);

        $scraper->search('Inception');

        $this->assertSame(1, $this->gateway()->counters('imdb_search')['requests']);
        $this->assertSame(0, $this->gateway()->counters('imdb')['requests']);

        $scraper->fetchById('1234567');

        $this->assertSame(1, $this->gateway()->counters('imdb')['requests']);
    }

    private function blockThreeTitlePages(ImdbScraper $scraper): void
    {
        foreach (['1111111', '2222222', '3333333'] as $imdbId) {
            $this->assertFalse($scraper->fetchById($imdbId));
        }
    }

    private function wafResponse(): Response
    {
        return new Response(202, [], '<script>window.gokuProps={}</script>');
    }

    /**
     * @return list<Response>
     */
    private function wafResponses(int $count): array
    {
        return array_map(fn (): Response => $this->wafResponse(), range(1, $count));
    }

    private function titleFixtureResponse(): Response
    {
        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], file_get_contents(base_path('tests/Fixtures/imdb/title_jsonld.html')) ?: '');
    }

    private function searchFixtureResponse(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json; charset=UTF-8'], file_get_contents(base_path('tests/Fixtures/imdb/search_inception.json')) ?: '{}');
    }

    private function gateway(): ProviderGateway
    {
        return app(ProviderGateway::class);
    }

    /**
     * Both gateway clients wrap the one injected client, so they share this queue.
     *
     * @param  list<Response>  $responses
     * @return array{ImdbScraper, MockHandler}
     */
    private function makeScraperWithResponses(array $responses): array
    {
        $mock = new MockHandler($responses);

        return [new ImdbScraper(new Client(['handler' => HandlerStack::create($mock), 'http_errors' => false])), $mock];
    }
}
