<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\UpdateReleasesIndexSchema;
use App\Facades\Elasticsearch;
use App\Services\Search\Support\ManticoreIndexRegistry;
use App\Support\ReleaseSearchIndexDocument;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\OutputStyle;
use Manticoresearch\Client as ManticoreClient;
use Manticoresearch\Table;
use Mockery;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * The imported flag in the releases search index (issue #1050 section 5): the document carries
 * `is_imported` as a number both engines can filter on, and the two existing schema commands add
 * the column to an index made before it existed.
 */
final class ReleaseImportedSearchIndexTest extends TestCase
{
    public function test_the_release_document_carries_the_imported_flag_as_a_number(): void
    {
        $this->assertContains('is_imported', ReleaseSearchIndexDocument::fields());
        $this->assertSame(1, ReleaseSearchIndexDocument::normalize(['is_imported' => '1'])['is_imported']);
        $this->assertSame(0, ReleaseSearchIndexDocument::normalize(['is_imported' => 0])['is_imported']);
        $this->assertSame(0, ReleaseSearchIndexDocument::normalize([])['is_imported']);
        $this->assertSame(1, ReleaseSearchIndexDocument::normalizeForBulk(ReleaseSearchIndexDocument::normalize(['id' => 7, 'is_imported' => true]))['is_imported']);
    }

    public function test_a_new_manticore_releases_table_declares_the_flag_as_an_integer_attribute(): void
    {
        $this->assertSame(['type' => 'integer'], ManticoreIndexRegistry::definitions()['releases']['columns']['is_imported'] ?? null);
    }

    public function test_the_manticore_schema_command_adds_the_flag_to_a_table_that_lacks_it_and_leaves_one_that_has_it(): void
    {
        $this->assertContains(['add', 'is_imported', 'int'], $this->manticoreAlterations(['id' => ['Type' => 'bigint'], 'name' => ['Type' => 'text']]));
        $this->assertNotContains(['add', 'is_imported', 'int'], $this->manticoreAlterations(['id' => ['Type' => 'bigint'], 'is_imported' => ['Type' => 'uint']]));
    }

    public function test_the_elasticsearch_schema_command_adds_the_flag_to_a_mapping_that_lacks_it_and_leaves_one_that_has_it(): void
    {
        $this->assertSame(['type' => 'integer'], $this->elasticsearchAddedProperties(['name' => ['type' => 'text']])['is_imported'] ?? null);
        $this->assertArrayNotHasKey('is_imported', $this->elasticsearchAddedProperties(['is_imported' => ['type' => 'integer']]));
    }

    public function test_a_new_elasticsearch_releases_index_maps_the_flag_as_an_integer(): void
    {
        config(['search.default' => 'elasticsearch', 'search.drivers.elasticsearch.indexes.releases' => 'releases']);
        $requests = $this->bindElasticsearch(static fn (RequestInterface $request): Response => $request->getMethod() === 'HEAD' ? self::elastic([], 404) : self::elastic(['acknowledged' => true]));

        $this->artisan('nntmux:create-es-indexes')->run();

        $created = array_values(array_filter($requests->getArrayCopy(), static fn (array $request): bool => $request['method'] === 'PUT' && $request['path'] === '/releases'));
        $this->assertCount(1, $created);
        $this->assertSame(['type' => 'integer'], $created[0]['body']['mappings']['properties']['is_imported'] ?? null);
    }

    /**
     * The columns `nntmux:update-releases-index --add-fields` adds to a table with the given columns.
     *
     * @param  array<string, array{Type: string}>  $columns
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function manticoreAlterations(array $columns): array
    {
        $alterations = [];
        $table = Mockery::mock(Table::class);
        $table->shouldReceive('describe')->andReturn($columns);
        $table->shouldReceive('alter')->andReturnUsing(function (string $operation, string $column, string $type) use (&$alterations): array {
            $alterations[] = [$operation, $column, $type];

            return [];
        });
        $client = Mockery::mock(ManticoreClient::class);
        $client->shouldReceive('table')->with('releases_rt')->andReturn($table);

        $command = $this->app->make(UpdateReleasesIndexSchema::class);
        $command->setLaravel($this->app);
        $input = new ArrayInput(['--add-fields' => true], $command->getDefinition());
        $input->setInteractive(false);
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, new BufferedOutput));
        (new ReflectionProperty($command, 'client'))->setValue($command, $client);
        $this->assertSame(0, (new ReflectionMethod($command, 'addNewFields'))->invoke($command));

        return $alterations;
    }

    /**
     * The properties `nntmux:update-releases-index-es --add-fields` puts on an index with the given mapping.
     *
     * @param  array<string, array<string, string>>  $existing
     * @return array<string, array<string, string>>
     */
    private function elasticsearchAddedProperties(array $existing): array
    {
        config(['search.default' => 'elasticsearch', 'search.drivers.elasticsearch.indexes.releases' => 'releases']);
        $requests = $this->bindElasticsearch(static fn (RequestInterface $request): Response => match (true) {
            str_contains($request->getUri()->getPath(), '_cluster') => self::elastic(['status' => 'green']),
            $request->getMethod() === 'GET' => self::elastic(['releases' => ['mappings' => ['properties' => $existing]]]),
            default => self::elastic(['acknowledged' => true]),
        });

        $this->artisan('nntmux:update-releases-index-es', ['--add-fields' => true])
            ->expectsConfirmation('Do you want to proceed with adding these fields?', 'yes')
            ->assertSuccessful();

        $puts = array_values(array_filter($requests->getArrayCopy(), static fn (array $request): bool => $request['method'] === 'PUT'));
        $this->assertCount(1, $puts);

        return $puts[0]['body']['properties'];
    }

    /**
     * Binds an Elasticsearch client that answers from the given function and records what it is sent.
     *
     * @param  \Closure(RequestInterface): Response  $answer
     * @return \ArrayObject<int, array{method: string, path: string, body: mixed}>
     */
    private function bindElasticsearch(\Closure $answer): \ArrayObject
    {
        /** @var \ArrayObject<int, array{method: string, path: string, body: mixed}> $requests */
        $requests = new \ArrayObject;
        $stack = HandlerStack::create(static function (RequestInterface $request) use ($requests, $answer): PromiseInterface {
            $requests->append(['method' => $request->getMethod(), 'path' => $request->getUri()->getPath(), 'body' => json_decode((string) $request->getBody(), true)]);

            return Create::promiseFor($answer($request));
        });
        $this->app->instance('elasticsearch', ClientBuilder::create()->setHosts(['http://localhost:9200'])->setHttpClient(new HttpClient(['handler' => $stack]))->build());
        Elasticsearch::clearResolvedInstance('elasticsearch');

        return $requests;
    }

    /** @param array<string, mixed> $body */
    private static function elastic(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json', 'X-Elastic-Product' => 'Elasticsearch'], json_encode($body, JSON_THROW_ON_ERROR));
    }
}
