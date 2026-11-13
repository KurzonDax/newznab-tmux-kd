<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Search;

use App\Services\Search\Drivers\ManticoreSearchDriver;
use ArrayObject;
use Illuminate\Support\Facades\Cache;
use Manticoresearch\Client;
use Manticoresearch\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * PreDB lookups through a stubbed Manticore client (#1240): a name-fixing
 * lookup searches the name as literal text, a typed admin query keeps its
 * operators, and every hit carries its PreDB id.
 */
class ManticorePredbSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    /** @return array<string, array{bool, string}> */
    public static function searchPaths(): array
    {
        return [
            'literal name' => [true, '@@relaxed @(title,filename) (Show S01E01 \| Extras)'],
            'typed query' => [false, '@@relaxed @(title,filename) (Show S01E01 | Extras)'],
        ];
    }

    #[DataProvider('searchPaths')]
    public function test_each_path_sends_its_expression_and_returns_hits_with_their_predb_id(bool $literal, string $expression): void
    {
        $sent = new ArrayObject;
        $driver = $this->driverReturning([$this->hit(42, 'Show.S01E01.Extras-GRP')], $sent);

        $hits = $driver->searchPredb('Show S01E01 | Extras', $literal);

        $this->assertSame([$expression], $sent->getArrayCopy());
        $this->assertSame([[
            'title' => 'Show.S01E01.Extras-GRP',
            'filename' => 'show.s01e01.extras-grp',
            'source' => 'srrdb',
            'title_len' => 22,
            'filename_len' => 22,
            'id' => 42,
        ]], $hits);
    }

    public function test_a_name_that_escapes_to_nothing_sends_no_search(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('search');
        $driver = $this->driver($client);

        $this->assertSame([], $driver->searchPredb('*', true));
    }

    public function test_a_literal_and_a_typed_search_for_the_same_text_do_not_share_a_cache_entry(): void
    {
        $sent = new ArrayObject;
        $driver = $this->driverReturning([$this->hit(7, 'Example-GRP')], $sent);

        $driver->searchPredb('Example', true);
        $driver->searchPredb('Example', false);
        $driver->searchPredb('Example', true);
        $driver->searchPredb('Example', false);

        $this->assertSame(
            ['@@relaxed @(title,filename) (Example)', '@@relaxed @(title,filename) (Example)'],
            $sent->getArrayCopy(),
            'Each path searches once and then reads its own cache entry.',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $hits
     * @param  ArrayObject<int, string>  $sent
     */
    private function driverReturning(array $hits, ArrayObject $sent): ManticoreSearchDriver
    {
        /** @var Client&MockObject $client */
        $client = $this->createMock(Client::class);
        $client->method('search')->willReturnCallback(static function (array $params) use ($hits, $sent): Response {
            $sent[] = (string) ($params['body']['query']['bool']['must'][0]['query_string'] ?? '');

            return new Response(['hits' => ['total' => count($hits), 'hits' => $hits]]);
        });

        return $this->driver($client);
    }

    private function driver(Client $client): ManticoreSearchDriver
    {
        $driver = new ManticoreSearchDriver([
            'host' => '127.0.0.1',
            'port' => 9308,
            'indexes' => ['predb' => 'predb_rt'],
        ]);
        $driver->manticoreSearch = $client;

        return $driver;
    }

    /** @return array<string, mixed> */
    private function hit(int $id, string $title): array
    {
        $filename = strtolower($title);

        return [
            '_id' => $id,
            '_score' => 1,
            '_source' => [
                'title' => $title,
                'filename' => $filename,
                'source' => 'srrdb',
                'title_len' => strlen($title),
                'filename_len' => strlen($filename),
            ],
        ];
    }
}
