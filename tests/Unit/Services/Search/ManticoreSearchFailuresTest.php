<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Search;

use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Services\Search\ManticoreSearchFailures;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Manticoresearch\Client;
use Manticoresearch\Exceptions\ResponseException;
use Manticoresearch\Request;
use Manticoresearch\Response;
use Tests\TestCase;

class ManticoreSearchFailuresTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Log::spy();
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    public function test_it_counts_every_failure_and_rate_limits_the_warning(): void
    {
        ManticoreSearchFailures::record('response', 'releases_rt', 'Example', 'first failure');
        ManticoreSearchFailures::record('response', 'releases_rt', 'Example', 'second failure');

        $this->assertSame(2, (int) Cache::get('search:failures:manticore', 0));
        Log::shouldHaveReceived('warning')->once()->with(
            'ManticoreSearch query failed.',
            \Mockery::on(static fn (array $context): bool => $context === [
                'failure_type' => 'response',
                'index' => 'releases_rt',
                'search' => 'Example',
                'error_sample' => 'first failure',
                'failures_total' => 1,
            ]),
        );
    }

    public function test_search_indexes_routes_response_errors_through_the_failure_reporter(): void
    {
        $responseException = new ResponseException(
            new Request,
            new Response(['error' => 'table releases_rt: unexpected TOK_FIELDLIMIT']),
        );
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('search')
            ->willThrowException($responseException);
        $driver = new ManticoreSearchDriver([
            'host' => '127.0.0.1',
            'port' => 9308,
            'indexes' => ['releases' => 'releases_rt'],
        ]);
        $driver->manticoreSearch = $client;

        $result = $driver->searchIndexes(
            'releases_rt',
            null,
            [],
            ['name' => 'Example', 'searchname' => 'Example'],
            21,
        );

        $this->assertSame([], $result);
        $this->assertSame(1, (int) Cache::get('search:failures:manticore', 0));
        Log::shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message, array $context): bool => $message === 'ManticoreSearch query failed.'
                && $context['failure_type'] === 'response'
                && $context['index'] === 'releases_rt'
                && str_contains($context['error_sample'], 'TOK_FIELDLIMIT'),
        );
        Log::shouldNotHaveReceived('error');
    }

    public function test_non_duplicate_search_errors_keep_the_existing_error_log(): void
    {
        $responseException = new ResponseException(
            new Request,
            new Response(['error' => 'table predb_rt: query error']),
        );
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('search')
            ->willThrowException($responseException);
        $driver = new ManticoreSearchDriver([
            'host' => '127.0.0.1',
            'port' => 9308,
            'indexes' => ['predb' => 'predb_rt'],
        ]);
        $driver->manticoreSearch = $client;

        $result = $driver->searchIndexes('predb_rt', 'Example', ['title']);

        $this->assertSame([], $result);
        $this->assertSame(0, (int) Cache::get('search:failures:manticore', 0));
        Log::shouldHaveReceived('error')->once()->withArgs(
            static fn (string $message, array $context): bool => str_starts_with(
                $message,
                'ManticoreSearch searchIndexes ResponseException:',
            ) && $context === [
                'index' => 'predb_rt',
                'search' => 'Example',
            ],
        );
    }
}
