<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Search;

use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Support\ReleaseSearchIndexDocument;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Manticoresearch\Client;
use Manticoresearch\Exceptions\ResponseException;
use Manticoresearch\Request;
use Manticoresearch\Response;
use Manticoresearch\Table;
use ReflectionMethod;
use Tests\TestCase;

final class ManticoreInsertRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('search_index_failures');
        Schema::create('search_index_failures', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('release_id')->unique();
            $table->string('operation', 32)->default('upsert');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    private function makeResponseException(string $message = 'err'): ResponseException
    {
        $request = $this->createMock(Request::class);
        $response = $this->createMock(Response::class);
        $response->method('getError')->willReturn($message);

        return new ResponseException($request, $response);
    }

    /**
     * @return array<string, mixed>
     */
    private function releaseRow(int $id): array
    {
        return [
            'id' => $id,
            'name' => 'n',
            'searchname' => 's',
            'fromname' => 'f',
            'categories_id' => 1,
            'filename' => '',
            'imdbid' => '',
            'tmdbid' => 0,
            'traktid' => 0,
            'tvdb' => 0,
            'tvmaze' => 0,
            'tvrage' => 0,
            'videos_id' => 0,
            'movieinfo_id' => 0,
            'size' => 100,
            'postdate' => '2020-01-01 00:00:00',
            'adddate' => '2020-01-01 00:00:00',
            'totalpart' => 1,
            'grabs' => 0,
            'passwordstatus' => 0,
            'groups_id' => 1,
            'nzbstatus' => 0,
            'haspreview' => 0,
        ];
    }

    public function test_replace_release_document_retries_once_on_response_exception(): void
    {
        $config = [
            'host' => '127.0.0.1',
            'port' => 9308,
            'indexes' => [
                'releases' => 'releases_rt',
                'predb' => 'predb_rt',
            ],
        ];

        $ex = $this->makeResponseException('transient');

        $table = $this->createMock(Table::class);
        $table->expects($this->exactly(2))
            ->method('replaceDocument')
            ->willReturnCallback(static function () use ($ex): void {
                static $calls = 0;
                $calls++;
                if ($calls === 1) {
                    throw $ex;
                }
            });

        $client = $this->createMock(Client::class);
        $client->expects($this->exactly(2))
            ->method('table')
            ->with('releases_rt')
            ->willReturn($table);

        $driver = new ManticoreSearchDriver($config);
        $prop = new \ReflectionProperty(ManticoreSearchDriver::class, 'manticoreSearch');
        $prop->setAccessible(true);
        $prop->setValue($driver, $client);

        $refP = new ReflectionMethod(ManticoreSearchDriver::class, 'replaceReleaseDocumentWithRetry');
        $refP->setAccessible(true);

        $ok = $refP->invoke($driver, $this->releaseRow(42));
        $this->assertTrue($ok);
    }

    public function test_replace_release_document_returns_false_after_two_response_exceptions(): void
    {
        $config = [
            'host' => '127.0.0.1',
            'port' => 9308,
            'indexes' => ['releases' => 'releases_rt', 'predb' => 'predb_rt'],
        ];

        $ex = $this->makeResponseException('fail');

        $table = $this->createMock(Table::class);
        $table->expects($this->exactly(2))
            ->method('replaceDocument')
            ->willThrowException($ex);

        $client = $this->createMock(Client::class);
        $client->expects($this->exactly(2))
            ->method('table')
            ->with('releases_rt')
            ->willReturn($table);

        $driver = new ManticoreSearchDriver($config);
        $prop = new \ReflectionProperty(ManticoreSearchDriver::class, 'manticoreSearch');
        $prop->setAccessible(true);
        $prop->setValue($driver, $client);

        $refP = new ReflectionMethod(ManticoreSearchDriver::class, 'replaceReleaseDocumentWithRetry');
        $refP->setAccessible(true);

        $ok = $refP->invoke($driver, $this->releaseRow(7));
        $this->assertFalse($ok);
    }

    public function test_replace_release_document_preserves_pre_normalized_timestamps(): void
    {
        $config = [
            'host' => '127.0.0.1',
            'port' => 9308,
            'retry_attempts' => 1,
            'indexes' => ['releases' => 'releases_rt', 'predb' => 'predb_rt'],
        ];
        $document = ReleaseSearchIndexDocument::normalize($this->releaseRow(42));

        $table = $this->createMock(Table::class);
        $table->expects($this->once())
            ->method('replaceDocument')
            ->with(
                $this->callback(static fn (array $indexed): bool => $indexed['postdate_ts'] === $document['postdate_ts']
                    && $indexed['adddate_ts'] === $document['adddate_ts']
                ),
                42
            );

        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('table')
            ->with('releases_rt')
            ->willReturn($table);

        $driver = new ManticoreSearchDriver($config);
        $prop = new \ReflectionProperty(ManticoreSearchDriver::class, 'manticoreSearch');
        $prop->setValue($driver, $client);

        $method = new ReflectionMethod(ManticoreSearchDriver::class, 'replaceReleaseDocumentWithRetry');

        $this->assertTrue($method->invoke($driver, $document));
    }

    public function test_insert_release_records_one_failure_without_dispatching_an_inline_retry(): void
    {
        config(['queue.default' => 'sync']);
        Bus::fake();

        $exception = $this->makeResponseException('permanent failure');
        $table = $this->createMock(Table::class);
        $table->expects($this->exactly(3))
            ->method('replaceDocument')
            ->willThrowException($exception);

        $client = $this->createMock(Client::class);
        $client->expects($this->exactly(3))
            ->method('table')
            ->with('releases_rt')
            ->willReturn($table);

        $driver = new ManticoreSearchDriver([
            'host' => '127.0.0.1',
            'port' => 9308,
            'retry_attempts' => 3,
            'retry_delay_ms' => 0,
            'indexes' => ['releases' => 'releases_rt', 'predb' => 'predb_rt'],
        ]);
        $driver->manticoreSearch = $client;

        $driver->insertRelease($this->releaseRow(42));

        $failure = DB::table('search_index_failures')->where('release_id', 42)->first();
        $this->assertNotNull($failure);
        $this->assertSame(1, (int) $failure->attempts);
        Bus::assertNothingDispatched();
    }

    public function test_insert_release_rejects_non_encodable_input_before_calling_manticore(): void
    {
        Bus::fake();
        Log::spy();

        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('table');

        $driver = new ManticoreSearchDriver([
            'host' => '127.0.0.1',
            'port' => 9308,
            'retry_attempts' => 3,
            'retry_delay_ms' => 0,
            'indexes' => ['releases' => 'releases_rt', 'predb' => 'predb_rt'],
        ]);
        $driver->manticoreSearch = $client;
        $row = $this->releaseRow(99);
        $row['filename'] = "Example\xED\xBD\xBF.mkv";

        $driver->insertRelease($row);

        $failure = DB::table('search_index_failures')->where('release_id', 99)->first();
        $this->assertNotNull($failure);
        $this->assertSame(1, (int) $failure->attempts);
        Log::shouldHaveReceived('error')->once()->with(
            'ManticoreSearch insertRelease: document is not valid JSON/UTF-8',
            \Mockery::on(static fn (array $context): bool => $context['release_id'] === 99
                && $context['fields'] === ['filename']
                && $context['error'] === 'Malformed UTF-8 characters, possibly incorrectly encoded')
        );
        Bus::assertNothingDispatched();
    }
}
