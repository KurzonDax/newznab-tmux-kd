<?php

namespace Tests\Feature;

use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinaryHandler;
use App\Services\Binaries\CollectionHandler;
use App\Services\Binaries\HeaderFailureReason;
use App\Services\Binaries\HeaderParser;
use App\Services\Binaries\HeaderStorageService;
use App\Services\Binaries\HeaderStorageTransaction;
use App\Services\Binaries\PartHandler;
use App\Services\BlacklistService;
use App\Services\CollectionsCleaningService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BinariesStorageInternalsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
    }

    public function test_header_parser_excludes_usenet_index_posts_and_returns_received_numbers(): void
    {
        $parser = new HeaderParser(new class extends BlacklistService
        {
            public function isBlackListed(array $msg, string $groupName): bool
            {
                return false;
            }
        });

        $result = $parser->parse([
            $this->rawHeader(101, 'Example.Release (1/2)'),
            $this->rawHeader(102, 'Usenet Index Post Example.Release (1/1)'),
            ['Subject' => 'Missing number (1/1)'],
        ], 'alt.test');

        $this->assertSame([101, 102], $result['received']);
        $this->assertCount(1, $result['headers']);
        $this->assertSame(1, $result['notYEnc']);
    }

    public function test_part_handler_ignored_duplicate_is_not_reported_failed(): void
    {
        DB::statement('CREATE TABLE parts (
            binaries_id INT,
            number INT,
            messageid VARCHAR(255),
            partnumber INT,
            size INT,
            UNIQUE(binaries_id, partnumber)
        )');

        $handler = new PartHandler(100);
        $this->assertTrue($handler->addPart(1, $this->parsedHeader(201, 1)));
        $this->assertTrue($handler->flush());
        $this->assertSame([201], $handler->getInsertedNumbers());
        $this->assertSame([], $handler->getFailedNumbers());

        $this->assertTrue($handler->addPart(1, $this->parsedHeader(201, 1)));
        $this->assertTrue($handler->flush());
        $this->assertSame([], $handler->getFailedNumbers());
        $this->assertSame(1, DB::table('parts')->count());
    }

    public function test_binary_handler_flushes_cached_article_aggregate_updates(): void
    {
        DB::statement('CREATE TABLE binaries (
            id INTEGER PRIMARY KEY,
            binaryhash BLOB,
            name VARCHAR(255),
            collections_id INT,
            totalparts INT,
            currentparts INT,
            filenumber INT,
            partsize INT,
            partcheck INT DEFAULT 0,
            UNIQUE(binaryhash, collections_id)
        )');
        DB::statement('CREATE TABLE parts (
            binaries_id INT,
            number INT,
            messageid VARCHAR(255),
            partnumber INT,
            size INT,
            UNIQUE(binaries_id, partnumber)
        )');

        $handler = new BinaryHandler;
        $first = $this->parsedHeader(251, 1, 'Aggregate.Release', 100);
        $second = $this->parsedHeader(252, 2, 'Aggregate.Release', 50);

        $binaryId = $handler->getOrCreateBinary($first, 1, 1, 0);
        $this->assertNotNull($binaryId);
        $this->assertSame($binaryId, $handler->getOrCreateBinary($second, 1, 1, 0));
        DB::table('parts')->insert([
            ['binaries_id' => $binaryId, 'number' => 251, 'messageid' => '<251@example>', 'partnumber' => 1, 'size' => 100],
            ['binaries_id' => $binaryId, 'number' => 252, 'messageid' => '<252@example>', 'partnumber' => 2, 'size' => 50],
        ]);
        $this->assertTrue($handler->refreshAggregates([$binaryId]));

        $binary = DB::table('binaries')->where('id', $binaryId)->first();
        $this->assertSame(2, (int) $binary->currentparts);
        $this->assertSame(150, (int) $binary->partsize);
    }

    public function test_sqlite_rollback_cleanup_keeps_unrelated_parts_with_same_article_number(): void
    {
        DB::statement('CREATE TABLE collections (id INTEGER PRIMARY KEY, collectionhash VARCHAR(40), noise VARCHAR(64))');
        DB::statement('CREATE TABLE binaries (id INTEGER PRIMARY KEY, collections_id INT)');
        DB::statement('CREATE TABLE parts (binaries_id INT, number INT, messageid VARCHAR(255), UNIQUE(binaries_id, number))');

        DB::table('collections')->insert(['id' => 1, 'collectionhash' => 'keep', 'noise' => '']);
        DB::table('binaries')->insert(['id' => 1, 'collections_id' => 1]);
        DB::table('parts')->insert(['binaries_id' => 1, 'number' => 777, 'messageid' => '<keep@example>']);

        $collectionHandler = new CollectionHandler;
        $binaryHandler = new BinaryHandler;
        $partHandler = new PartHandler;
        $transaction = new HeaderStorageTransaction($collectionHandler, $binaryHandler, $partHandler);

        $transaction->begin();
        DB::table('collections')->insert(['id' => 2, 'collectionhash' => 'rollback', 'noise' => $transaction->getBatchNoise()]);
        DB::table('binaries')->insert(['id' => 2, 'collections_id' => 2]);
        DB::table('parts')->insert(['binaries_id' => 2, 'number' => 777, 'messageid' => '<rollback@example>']);
        $this->setPrivateProperty($collectionHandler, 'insertedCollectionIds', [2 => true]);
        $this->setPrivateProperty($binaryHandler, 'insertedBinaryIds', [2 => true]);
        $transaction->markError();
        $this->assertFalse($transaction->finish());

        $this->assertSame(1, DB::table('parts')->where('binaries_id', 1)->where('number', 777)->count());
        $this->assertSame(0, DB::table('parts')->where('binaries_id', 2)->count());
    }

    public function test_header_storage_commits_successful_chunks_and_reports_failed_chunk_numbers(): void
    {
        $this->createHeaderStorageTables('CHECK(size < 500)');

        $service = new HeaderStorageService($this->deterministicCollectionHandler(), config: new BinariesConfig(sqlChunkSize: 2, headerChunkSize: 2));
        $failed = $service->store([
            $this->parsedHeader(301, 1, 'Chunk.One', 100),
            $this->parsedHeader(302, 2, 'Chunk.One', 100),
            $this->parsedHeader(303, 1, 'Chunk.Two', 999),
            $this->parsedHeader(304, 2, 'Chunk.Two', 999),
        ], ['id' => 1, 'name' => 'alt.test'], true)->uniqueFailedNumbers();

        sort($failed);

        $this->assertSame([303, 304], $failed);
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(1, DB::table('binaries')->count());
        $this->assertSame(2, DB::table('parts')->count());
        $this->assertSame([301, 302], DB::table('parts')->orderBy('number')->pluck('number')->all());
    }

    public function test_header_storage_batch_reuses_collection_and_binary_for_parts(): void
    {
        $this->createHeaderStorageTables();

        $service = new HeaderStorageService($this->deterministicCollectionHandler(), config: new BinariesConfig(sqlChunkSize: 10));
        $failed = $service->store([
            $this->parsedHeader(401, 1, 'Batch.Release', 150),
            $this->parsedHeader(402, 2, 'Batch.Release', 175),
        ], ['id' => 1, 'name' => 'alt.test'], true)->uniqueFailedNumbers();

        $binary = DB::table('binaries')->first();

        $this->assertSame([], $failed);
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(1, DB::table('binaries')->count());
        $this->assertSame(2, DB::table('parts')->count());
        $this->assertSame(2, (int) $binary->currentparts);
        $this->assertSame(325, (int) $binary->partsize);
    }

    public function test_header_storage_batch_updates_binary_that_exists_before_chunk(): void
    {
        $this->createHeaderStorageTables();

        $service = new HeaderStorageService($this->deterministicCollectionHandler(), config: new BinariesConfig(sqlChunkSize: 10));
        $this->assertSame([], $service->store([
            $this->parsedHeader(501, 1, 'Existing.Batch.Release', 100),
        ], ['id' => 1, 'name' => 'alt.test'], true)->uniqueFailedNumbers());

        $this->assertSame([], $service->store([
            $this->parsedHeader(502, 2, 'Existing.Batch.Release', 150),
        ], ['id' => 1, 'name' => 'alt.test'], true)->uniqueFailedNumbers());

        $binary = DB::table('binaries')->first();

        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(1, DB::table('binaries')->count());
        $this->assertSame(2, DB::table('parts')->count());
        $this->assertSame(2, (int) $binary->currentparts);
        $this->assertSame(250, (int) $binary->partsize);
    }

    public function test_three_identical_ingestions_leave_authoritative_counts_unchanged(): void
    {
        $this->createHeaderStorageTables();

        $service = new HeaderStorageService($this->deterministicCollectionHandler(), config: new BinariesConfig(sqlChunkSize: 10));
        $headers = [
            $this->parsedHeader(551, 1, 'Idempotent.Release', 125),
            $this->parsedHeader(552, 2, 'Idempotent.Release', 175),
        ];

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->assertSame([], $service->store($headers, ['id' => 1, 'name' => 'alt.test'], true)->uniqueFailedNumbers());
        }

        $binary = DB::table('binaries')->first();
        $collection = DB::table('collections')->first();
        $this->assertSame(1, DB::table('binaries')->count());
        $this->assertSame(2, DB::table('parts')->count());
        $this->assertSame(2, (int) $binary->currentparts);
        $this->assertSame(300, (int) $binary->partsize);
        $this->assertSame(300, (int) $collection->filesize);
    }

    public function test_duplicate_partnumber_prefers_non_empty_message_then_size_then_article_number(): void
    {
        DB::statement('CREATE TABLE parts (
            binaries_id INT,
            number INT,
            messageid VARCHAR(255),
            partnumber INT,
            size INT,
            UNIQUE(binaries_id, partnumber)
        )');

        $handler = new PartHandler(10);
        $small = $this->parsedHeader(900, 1, 'Preference.Release', 100);
        $large = $this->parsedHeader(901, 1, 'Preference.Release', 200);
        $this->assertTrue($handler->addPart(7, $small));
        $this->assertTrue($handler->addPart(7, $large));
        $this->assertTrue($handler->flush());

        $part = DB::table('parts')->first();
        $this->assertSame(901, (int) $part->number);
        $this->assertSame(200, (int) $part->size);
    }

    public function test_invalid_message_id_rolls_back_the_header_chunk(): void
    {
        $this->createHeaderStorageTables();
        $service = new HeaderStorageService($this->deterministicCollectionHandler(), config: new BinariesConfig(sqlChunkSize: 10));
        $header = $this->parsedHeader(910, 1, 'Invalid.Message.Release', 100);
        $header['Message-ID'] = "<invalid\u{00E9}@example>";

        $this->assertSame([910], $service->store([$header], ['id' => 1, 'name' => 'alt.test'], true)->uniqueFailedNumbers());
        $this->assertSame(0, DB::table('collections')->count());
        $this->assertSame(0, DB::table('binaries')->count());
        $this->assertSame(0, DB::table('parts')->count());
    }

    public function test_zero_file_number_uses_subject_and_poster_identity(): void
    {
        $this->createHeaderStorageTables();
        $service = new HeaderStorageService($this->deterministicCollectionHandler(), config: new BinariesConfig(sqlChunkSize: 10));
        $first = $this->parsedHeader(920, 1, 'Unknown.File.Release', 100);
        $second = $this->parsedHeader(921, 1, 'Unknown.File.Release', 100);
        $second['From'] = 'another-poster@example.com';

        $this->assertSame([], $service->store([$first, $second], ['id' => 1, 'name' => 'alt.test'], true)->uniqueFailedNumbers());
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(2, DB::table('binaries')->count());
        $this->assertSame(2, DB::table('parts')->count());
    }

    public function test_cross_post_groups_are_normalized_and_deduplicated(): void
    {
        $this->createHeaderStorageTables();
        $service = new HeaderStorageService($this->deterministicCollectionHandler(), config: new BinariesConfig(sqlChunkSize: 10));
        $header = $this->parsedHeader(930, 1, 'Cross.Post.Release', 100);
        $header['Xref'] = 'news.example alt.binaries.one:930 alt.binaries.two:931 alt.binaries.one:930';

        $this->assertSame([], $service->store([$header], ['id' => 1, 'name' => 'alt.binaries.one'], true)->uniqueFailedNumbers());
        $this->assertSame(
            ['alt.binaries.one', 'alt.binaries.two'],
            DB::table('collection_groups')->orderBy('group_name')->pluck('group_name')->all()
        );
    }

    public function test_collection_bulk_inserts_are_sorted_by_hash_across_sql_chunks(): void
    {
        $this->createHeaderStorageTables();
        $subjects = ['Alpha.Release', 'Bravo.Release', 'Charlie.Release', 'Delta.Release', 'Echo.Release'];
        usort(
            $subjects,
            static fn (string $left, string $right): int => strcmp(sha1($right.'1', true), sha1($left.'1', true)),
        );
        $headers = [];
        $totalFilesByIndex = [];
        foreach ($subjects as $index => $subject) {
            $headers[$index] = $this->parsedHeaderWithTotal(940 + $index, 1, 1, $subject);
            $totalFilesByIndex[$index] = 1;
        }

        $insertedHashes = [];
        DB::listen(static function (QueryExecuted $query) use (&$insertedHashes): void {
            if (! str_starts_with($query->sql, 'insert or ignore into "collections"')) {
                return;
            }

            for ($offset = 1; $offset < count($query->bindings); $offset += 11) {
                $insertedHashes[] = $query->bindings[$offset];
            }
        });

        $resolved = $this->deterministicCollectionHandler(sqlChunkSize: 2)->getOrCreateCollections(
            $headers,
            1,
            'alt.test',
            $totalFilesByIndex,
            'batch-noise',
        );

        $expectedHashes = array_map(static fn (string $subject): string => sha1($subject.'1', true), $subjects);
        usort($expectedHashes, strcmp(...));

        $this->assertCount(5, $resolved);
        $this->assertSame(array_map(bin2hex(...), $expectedHashes), array_map(bin2hex(...), $insertedHashes));
    }

    public function test_header_storage_does_not_merge_same_subject_across_different_collections(): void
    {
        $this->createHeaderStorageTables();

        $handler = new BinaryHandler;
        $header = $this->parsedHeader(601, 1, 'Same.Subject', 100);
        $firstId = $handler->getOrCreateBinary($header, 10, 1, 0);
        $secondId = $handler->getOrCreateBinary($header, 20, 1, 0);

        $this->assertNotNull($firstId);
        $this->assertNotNull($secondId);
        $this->assertNotSame($firstId, $secondId);
        $this->assertSame(2, DB::table('binaries')->count());
    }

    private function rawHeader(int $number, string $subject): array
    {
        return [
            'Number' => $number,
            'Subject' => $subject,
            'From' => 'poster@example.com',
            'Date' => time(),
            'Bytes' => 100,
            'Message-ID' => '<msg'.$number.'@example.com>',
            'Xref' => 'news.example.com group:'.$number,
        ];
    }

    private function parsedHeader(int $number, int $partNumber, string $subjectBase = 'Example.Release', int $bytes = 100): array
    {
        return $this->parsedHeaderWithTotal($number, $partNumber, 2, $subjectBase, $bytes);
    }

    private function parsedHeaderWithTotal(int $number, int $partNumber, int $totalParts, string $subjectBase, int $bytes = 100): array
    {
        $header = $this->rawHeader($number, $subjectBase.' ('.$partNumber.'/'.$totalParts.')');
        $header['Bytes'] = $bytes;
        $header['matches'] = [
            0 => $header['Subject'],
            1 => $subjectBase,
            2 => $partNumber,
            3 => $totalParts,
        ];

        return $header;
    }

    private function createHeaderStorageTables(string $partSizeConstraint = ''): void
    {
        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY,
            subject VARCHAR(255),
            fromname VARCHAR(255),
            date DATETIME NULL,
            xref TEXT DEFAULT \'\',
            groups_id INT,
            totalfiles INT,
            declaredfiles INT NOT NULL DEFAULT 0,
            firstarticle INT NULL,
            lastarticle INT NULL,
            collectionhash VARCHAR(40) UNIQUE,
            collection_regexes_id INT,
            dateadded DATETIME NULL,
            last_seen_at DATETIME NULL,
            filecheck INT DEFAULT 0,
            filesize INT DEFAULT 0,
            noise VARCHAR(64) DEFAULT \'\'
        )');

        DB::statement('CREATE TABLE collection_groups (
            collections_id INT,
            group_name VARCHAR(255),
            UNIQUE(collections_id, group_name)
        )');

        DB::statement('CREATE TABLE binaries (
            id INTEGER PRIMARY KEY,
            binaryhash BLOB,
            name VARCHAR(255),
            collections_id INT,
            totalparts INT,
            currentparts INT,
            filenumber INT,
            partsize INT,
            partcheck INT DEFAULT 0,
            UNIQUE(binaryhash, collections_id)
        )');

        DB::statement('CREATE TABLE parts (
            binaries_id INT,
            number INT,
            messageid VARCHAR(255),
            partnumber INT,
            size INT '.$partSizeConstraint.',
            UNIQUE(binaries_id, partnumber)
        )');

        DB::statement('CREATE TABLE collection_regexes (
            id INTEGER PRIMARY KEY,
            group_regex VARCHAR(255),
            regex VARCHAR(255),
            status INT DEFAULT 1,
            ordinal INT DEFAULT 0
        )');
    }

    public function test_record_changed_since_last_read_is_treated_as_transient(): void
    {
        $service = new HeaderStorageService($this->deterministicCollectionHandler(), config: new BinariesConfig(sqlChunkSize: 10));
        $method = new \ReflectionMethod($service, 'isTransientLockError');

        $pdo1020 = new \PDOException("SQLSTATE[HY000]: General error: 1020 Record has changed since last read in table 'collections'; try restarting transaction");
        $pdo1020->errorInfo = ['HY000', 1020, "Record has changed since last read in table 'collections'"];
        $checkRead = new QueryException('mariadb', 'INSERT INTO collections ...', [], $pdo1020);

        $this->assertTrue($method->invoke($service, $checkRead));

        $pdo1062 = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
        $pdo1062->errorInfo = ['23000', 1062, 'Duplicate entry'];
        $duplicateKey = new QueryException('mariadb', 'INSERT INTO collections ...', [], $pdo1062);

        $this->assertFalse($method->invoke($service, $duplicateKey));
        $this->assertFalse($method->invoke($service, null));
    }

    private function deterministicCollectionHandler(int $sqlChunkSize = 500): CollectionHandler
    {
        return new CollectionHandler(new class extends CollectionsCleaningService
        {
            public function __construct()
            {
                parent::__construct();
            }

            public function collectionsCleaner(string $subject, string $groupName = ''): array
            {
                return ['id' => 0, 'name' => $subject];
            }
        }, sqlChunkSize: $sqlChunkSize);
    }

    public function test_silent_collection_race_is_retried_and_the_chunk_stores(): void
    {
        $this->createHeaderStorageTables();
        $attempts = $this->simulateUnresolvedCollectionRace(1);

        $service = new HeaderStorageService($this->deterministicCollectionHandler(), config: new BinariesConfig(sqlChunkSize: 10));
        $report = $service->store([
            $this->parsedHeader(601, 1, 'Raced.Release', 100),
            $this->parsedHeader(602, 2, 'Raced.Release', 150),
        ], ['id' => 1, 'name' => 'alt.test'], true);

        $this->assertSame([], $report->uniqueFailedNumbers());
        $this->assertSame(0, $report->rolledBackChunks);
        $this->assertSame(0, $report->unresolvedHeaders);
        $this->assertSame(1, $report->recoveredChunks);
        $this->assertSame(2, $attempts->count);
        $this->assertSame(2, DB::table('parts')->count());
        $this->assertSame('1 chunk stored on retry.', $report->describe());
    }

    public function test_permanently_unresolved_headers_are_reported_as_one_rolled_back_chunk(): void
    {
        $this->createHeaderStorageTables();
        $attempts = $this->simulateUnresolvedCollectionRace(PHP_INT_MAX);

        $service = new HeaderStorageService($this->deterministicCollectionHandler(), config: new BinariesConfig(sqlChunkSize: 10));
        $report = $service->store([
            $this->parsedHeader(701, 1, 'Unresolvable.Release', 100),
            $this->parsedHeader(702, 2, 'Unresolvable.Release', 150),
        ], ['id' => 1, 'name' => 'alt.test'], true);

        $failed = $report->uniqueFailedNumbers();
        sort($failed);

        $this->assertSame([701, 702], $failed);
        $this->assertSame(1, $report->rolledBackChunks);
        $this->assertSame(2, $report->unresolvedHeaders);
        $this->assertSame(0, $report->rejectedHeaders);
        $this->assertSame(0, $report->recoveredChunks);
        $this->assertSame(5, $attempts->count);
        $this->assertSame(
            '2 articles queued for part repair (2 headers unresolved, 1 chunk rolled back).',
            $report->describe()
        );
        $this->assertSame(0, DB::table('parts')->count());
    }

    public function test_rejected_header_is_not_retried_and_is_reported_as_rejected(): void
    {
        $this->createHeaderStorageTables();
        $attempts = $this->simulateUnresolvedCollectionRace(0);

        $service = new HeaderStorageService($this->deterministicCollectionHandler(), config: new BinariesConfig(sqlChunkSize: 10));
        $header = $this->parsedHeader(910, 1, 'Invalid.Message.Release', 100);
        $header['Message-ID'] = "<invalid\u{00E9}@example>";

        $report = $service->store([$header], ['id' => 1, 'name' => 'alt.test'], true);

        $this->assertSame([910], $report->uniqueFailedNumbers());
        $this->assertSame(1, $report->rejectedHeaders);
        $this->assertSame(0, $report->unresolvedHeaders);
        $this->assertSame(1, $report->rolledBackChunks);
        $this->assertSame(1, $attempts->count, 'A header rejected on its own merits must not be retried.');
        $this->assertSame(
            '1 article queued for part repair (1 header rejected, 1 chunk rolled back).',
            $report->describe()
        );
    }

    public function test_chunk_retry_policy_covers_transient_locks_and_silent_races_only(): void
    {
        $service = new HeaderStorageService($this->deterministicCollectionHandler(), config: new BinariesConfig(sqlChunkSize: 10));
        $isRetryable = new \ReflectionMethod($service, 'isRetryableChunkFailure');

        $pdoDeadlock = new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found');
        $pdoDeadlock->errorInfo = ['40001', 1213, 'Deadlock found'];
        $deadlock = new QueryException('mariadb', 'INSERT INTO collections ...', [], $pdoDeadlock);

        $this->setPrivateProperty($service, 'lastStorageException', $deadlock);
        $this->setPrivateProperty($service, 'attemptFailures', []);
        $this->assertTrue($isRetryable->invoke($service), 'Transient lock failures still retry.');

        $this->setPrivateProperty($service, 'lastStorageException', null);
        $this->setPrivateProperty($service, 'attemptFailures', [HeaderFailureReason::UnresolvedCollection, HeaderFailureReason::UnresolvedBinary]);
        $this->assertTrue($isRetryable->invoke($service), 'Silent unresolved ids retry.');

        $this->setPrivateProperty($service, 'attemptFailures', [HeaderFailureReason::UnresolvedCollection, HeaderFailureReason::RejectedPart]);
        $this->assertFalse($isRetryable->invoke($service), 'A rejected header makes the chunk permanently bad.');

        $this->setPrivateProperty($service, 'attemptFailures', []);
        $this->assertFalse($isRetryable->invoke($service), 'A silent failure with no unresolved id is not retryable.');

        $pdoDuplicate = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
        $pdoDuplicate->errorInfo = ['23000', 1062, 'Duplicate entry'];
        $this->setPrivateProperty($service, 'lastStorageException', new QueryException('mariadb', 'INSERT INTO collections ...', [], $pdoDuplicate));
        $this->setPrivateProperty($service, 'attemptFailures', [HeaderFailureReason::UnresolvedCollection]);
        $this->assertFalse($isRetryable->invoke($service), 'A real exception is never treated as the silent race.');
    }

    /**
     * Reproduce the production race: the collection insert lands harmlessly on a peer
     * worker's committed row, but this transaction's snapshot cannot see it, so the
     * resolving select comes back empty and no exception is raised.
     *
     * @param  int  $sabotageAttempts  How many storage attempts should hit the race
     * @return object{count: int} Collection insert attempts observed
     */
    private function simulateUnresolvedCollectionRace(int $sabotageAttempts): object
    {
        $attempts = new class
        {
            public int $count = 0;
        };

        DB::listen(static function (QueryExecuted $query) use ($attempts, $sabotageAttempts): void {
            if (! str_starts_with($query->sql, 'insert or ignore into "collections"')) {
                return;
            }

            $attempts->count++;
            if ($attempts->count > $sabotageAttempts) {
                return;
            }

            DB::table('collections')->delete();
        });

        return $attempts;
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setValue($object, $value);
    }
}
