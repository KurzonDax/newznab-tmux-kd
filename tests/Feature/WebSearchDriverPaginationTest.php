<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\Drivers\ElasticSearchDriver;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Manticoresearch\Client as ManticoreClient;
use Manticoresearch\Response as ManticoreResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\TestCase;

final class WebSearchDriverPaginationTest extends TestCase
{
    /** @var list<array{ReflectionProperty, mixed}> */
    private array $originals = [];

    /** @var list<array<string, mixed>> */
    private array $requests = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->originals) as [$property, $value]) {
            $property->setValue(null, $value);
        }
        Cache::flush();
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function drivers(): iterable
    {
        yield 'Manticore' => ['manticore'];
        yield 'Elasticsearch' => ['elasticsearch'];
    }

    #[DataProvider('drivers')]
    public function test_web_release_pages_advance_by_id_with_one_fixed_fuzzy_mode(string $name): void
    {
        $driver = $this->driver($name);
        foreach ([false, true] as $fuzzy) {
            $page = $driver->searchReleasesFiltered([
                'phrases' => ['searchname' => 'Dune'], 'try_fuzzy' => false,
                'web_after_id' => 500, 'web_force_fuzzy' => $fuzzy, 'sort_field' => 'id', 'sort_dir' => 'asc',
            ], 500);
            $this->assertSame([501], $page['ids']);
            $this->assertSame($fuzzy, $page['fuzzy']);
        }
        $this->assertCount(2, $this->requests);
        foreach ($this->requests as $body) {
            $this->assertSame($name === 'manticore' ? [['id' => 'asc']] : [['id' => ['order' => 'asc']]], $body['sort']);
            $this->assertStringContainsString('"id":{"gt":500}', json_encode($body['query'], JSON_THROW_ON_ERROR));
        }
        $this->assertStringNotContainsString('fuzziness', json_encode($this->requests[0], JSON_THROW_ON_ERROR));
        if ($name === 'manticore') {
            $this->assertTrue($this->requests[1]['options']['fuzzy']);
        } else {
            $this->assertStringContainsString('fuzziness', json_encode($this->requests[1], JSON_THROW_ON_ERROR));
        }
    }

    #[DataProvider('drivers')]
    public function test_movie_pages_use_id_boundaries_without_changing_default_queries(string $name): void
    {
        $driver = $this->driver($name);
        $page = $driver->searchMoviesByFields(['actors' => 'Emily Blunt'], 500, 500);
        $this->assertSame([501], $page['movieinfo_ids']);
        $this->assertSame(['0123456'], $page['imdbids']);
        $this->assertSame($name === 'manticore' ? [['id' => 'asc']] : [['id' => ['order' => 'asc']]], $this->requests[0]['sort']);
        $this->assertStringContainsString('"id":{"gt":500}', json_encode($this->requests[0]['query'], JSON_THROW_ON_ERROR));
        $driver->searchMoviesByFields(['actors' => 'Emily Blunt'], 500);
        $this->assertStringNotContainsString('"id":{"gt":', json_encode($this->requests[1]['query'], JSON_THROW_ON_ERROR));
        if ($name === 'manticore') {
            $this->assertSame([['id' => 'desc']], $this->requests[1]['sort']);
        } else {
            $this->assertArrayNotHasKey('sort', $this->requests[1]);
        }
    }

    private function driver(string $name): SearchDriverInterface
    {
        Cache::flush();
        $class = $name === 'manticore' ? ManticoreSearchDriver::class : ElasticSearchDriver::class;
        $this->replaceStatic($class, 'availabilityCache', true);
        $this->replaceStatic($class, 'availabilityCacheTime', time());
        $hit = ['_id' => '501', '_source' => ['id' => 501, 'imdbid' => '0123456']];
        if ($name === 'manticore') {
            $client = $this->createMock(ManticoreClient::class);
            $client->method('search')->willReturnCallback(function (array $parameters) use ($hit): ManticoreResponse {
                $this->requests[] = $parameters['body'];

                return new ManticoreResponse(['hits' => ['total' => 1, 'hits' => [$hit]]]);
            });
            $driver = new ManticoreSearchDriver(['host' => 'localhost', 'port' => 9308, 'fuzzy' => ['enabled' => true], 'indexes' => ['movies' => 'movies_rt', 'releases' => 'releases_rt']]);
            $driver->manticoreSearch = $client;

            return $driver;
        }
        $response = new Response(200, ['Content-Type' => 'application/json', 'X-Elastic-Product' => 'Elasticsearch'], json_encode(['hits' => ['total' => ['value' => 1], 'hits' => [$hit]]], JSON_THROW_ON_ERROR));
        $stack = HandlerStack::create(new MockHandler([$response, $response]));
        $stack->push(Middleware::mapRequest(function ($request) {
            $this->requests[] = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);

            return $request;
        }));
        $client = ClientBuilder::create()->setHosts(['http://localhost:9200'])->setHttpClient(new HttpClient(['handler' => $stack]))->build();
        $this->replaceStatic($class, 'client', $client);

        return new ElasticSearchDriver(['fuzzy' => ['enabled' => true], 'indexes' => ['movies' => 'movies', 'releases' => 'releases']]);
    }

    private function replaceStatic(string $class, string $name, mixed $value): void
    {
        $property = new ReflectionProperty($class, $name);
        $this->originals[] = [$property, $property->getValue()];
        $property->setValue(null, $value);
    }
}
