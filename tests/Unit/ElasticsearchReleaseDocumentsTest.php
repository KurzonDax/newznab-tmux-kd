<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Search\Drivers\ElasticSearchDriver;
use App\Support\ReleaseSearchIndexDocument;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ElasticsearchReleaseDocumentsTest extends TestCase
{
    protected function tearDown(): void
    {
        (new ElasticSearchDriver(['indexes' => ['releases' => 'releases']]))->resetConnection();

        parent::tearDown();
    }

    #[Test]
    public function it_returns_the_bounded_canonical_projection_after_the_cursor(): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(200, [
                'Content-Type' => 'application/json',
                'X-Elastic-Product' => 'Elasticsearch',
            ], json_encode([
                'hits' => [
                    'hits' => [[
                        '_id' => '101',
                        '_source' => [
                            'searchname' => 'First.Release',
                            'totalpart' => '10',
                            'passwordstatus' => '-1',
                        ],
                    ]],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]));
        $handler->push(Middleware::history($history));
        $client = ClientBuilder::create()
            ->setHosts(['http://localhost:9200'])
            ->setHttpClient(new GuzzleClient(['handler' => $handler]))
            ->build();

        $reflection = new ReflectionClass(ElasticSearchDriver::class);
        $reflection->getProperty('client')->setValue(null, $client);

        $driver = new ElasticSearchDriver(['indexes' => ['releases' => 'releases']]);
        $documents = $driver->releaseDocumentsAfterId(100, 2);

        $this->assertSame([101], array_keys($documents));
        $this->assertSame('First.Release', $documents[101]['searchname']);
        $this->assertSame(10, $documents[101]['totalpart']);
        $this->assertSame(-1, $documents[101]['passwordstatus']);
        $this->assertCount(1, $history);

        $request = $history[0]['request'];
        $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('/releases/_search', $request->getUri()->getPath());
        $this->assertSame(['range' => ['id' => ['gt' => 100]]], $body['query']);
        $this->assertSame([['id' => ['order' => 'asc']]], $body['sort']);
        $this->assertSame(2, $body['size']);
        $this->assertSame(ReleaseSearchIndexDocument::fields(), $body['_source']);
    }
}
