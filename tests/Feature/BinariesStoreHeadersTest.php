<?php

namespace Tests\Feature;

use App\Enums\CollectionFileCheckStatus;
use App\Enums\HeaderScanDirection;
use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinariesService;
use App\Services\Binaries\HeaderParser;
use App\Services\NNTP\NNTPService;
use Database\Seeders\CollectionRegexesTableSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NeverBlacklistedService;
use Tests\Support\TestBinariesHarness;
use Tests\TestCase;

class BinariesStoreHeadersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        $this->registerSqliteFunction(
            'regexp',
            static fn (?string $pattern, ?string $value): int => preg_match('/'.$pattern.'/i', (string) $value) === 1 ? 1 : 0,
            2,
        );

        // Minimal tables.
        DB::statement('CREATE TABLE settings (
            section TEXT NULL,
            subsection TEXT NULL,
            name TEXT PRIMARY KEY,
            value TEXT NULL,
            hint TEXT NULL,
            setting TEXT NULL
        )');
        // Seed the settings queried in Binaries constructor.
        $defaults = [
            'maxmssgs' => '20000',
            'partrepair' => '1',
            'newgroupscanmethod' => '0',
            'newgroupmsgstoscan' => '50000',
            'newgroupdaystoscan' => '3',
            'maxpartrepair' => '15000',
            'partrepairmaxtries' => '3',
        ];
        foreach ($defaults as $k => $v) {
            DB::table('settings')->insert(['name' => $k, 'value' => $v]);
        }

        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subject VARCHAR(255),
            fromname VARCHAR(255),
            date DATETIME NULL,
            xref TEXT DEFAULT "",
            groups_id INT,
            totalfiles INT,
            declaredfiles INT NOT NULL DEFAULT 0,
            firstarticle INT NULL,
            lastarticle INT NULL,
            collectionhash VARCHAR(40) UNIQUE,
            collection_regexes_id INT,
            dateadded DATETIME NULL,
            last_seen_at DATETIME NULL,
            last_seen_head_postdate DATETIME NULL,
            last_seen_tail_postdate DATETIME NULL,
            filecheck INT DEFAULT 0,
            filesize INT DEFAULT 0,
            noise VARCHAR(64) DEFAULT ""
        )');

        DB::statement('CREATE TABLE collection_groups (
            collections_id INT,
            group_name VARCHAR(255),
            UNIQUE(collections_id, group_name)
        )');

        DB::statement('CREATE TABLE binaries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
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
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            binaries_id INT,
            number INT,
            messageid VARCHAR(255),
            partnumber INT,
            size INT,
            UNIQUE(binaries_id, partnumber)
        )');

        DB::statement('CREATE TABLE missed_parts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            numberid INT,
            groups_id INT,
            attempts INT DEFAULT 0,
            UNIQUE(numberid, groups_id)
        )');

        DB::statement('CREATE TABLE collection_regexes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_regex VARCHAR(255),
            regex VARCHAR(255),
            status INT DEFAULT 1,
            ordinal INT DEFAULT 0,
            description VARCHAR(1000) DEFAULT ""
        )');
    }

    private function makeHeader(int $articleNumber, int $partNumber, int $totalParts, int $bytes = 100): array
    {
        $subjectBase = 'Example.File.Name';

        return [
            'Number' => $articleNumber,
            'Subject' => $subjectBase.' ('.$partNumber.'/'.$totalParts.')',
            'From' => 'poster@example.com',
            'Date' => time(),
            'Bytes' => $bytes,
            'Message-ID' => '<msg'.$articleNumber.'@example.com>',
            'Xref' => 'news.example.com group:'.$articleNumber,
            'matches' => [
                0 => $subjectBase.' ('.$partNumber.'/'.$totalParts.')',
                1 => $subjectBase,
                2 => $partNumber,
                3 => $totalParts,
            ],
        ];
    }

    #[DataProvider('rangeDirections')]
    public function test_range_command_stamps_direction_from_mode(string $mode, int $safePartRepair): void
    {
        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name TEXT, first_record INTEGER, last_record INTEGER,
            first_record_postdate DATETIME, last_record_postdate DATETIME, backfill_settled_at DATETIME, last_updated DATETIME)');
        DB::table('usenet_groups')->insert([
            'id' => 1, 'name' => 'alt.test', 'first_record' => 9000, 'last_record' => 8000,
            'first_record_postdate' => '2026-08-01 00:00:00', 'last_record_postdate' => '2026-08-01 00:00:00',
        ]);
        (require database_path('migrations/2026_09_05_213352_create_usenet_group_ingested_ranges_table.php'))->up();
        DB::table('settings')->insert(['name' => 'safepartrepair', 'value' => (string) $safePartRepair]);
        $header = $this->makeHeader(8001, 1, 2);
        $header['Number'] = '8001';
        $header['Subject'] = 'Example.File.Name yEnc (1/2)';
        $header['Date'] = '2026-08-01 12:00:00';
        $nntp = \Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('doConnect')->andReturn(true);
        $nntp->shouldReceive('selectGroup')->andReturn(['group' => 'alt.test', 'first' => 1, 'last' => 10000]);
        $nntp->shouldReceive('getXOVER')->with('8001-8001')->andReturn([$header]);
        $this->app->instance(NNTPService::class, $nntp);
        $this->app->instance(BinariesService::class, new BinariesService(
            config: new BinariesConfig(echoCli: false),
            headerParser: new HeaderParser(new NeverBlacklistedService),
        ));

        $this->artisan('articles:get-range', ['mode' => $mode, 'group' => 'alt.test', 'first' => 8001, 'last' => 8001])->assertSuccessful();

        $column = $mode === 'binaries' ? 'last_seen_head_postdate' : 'last_seen_tail_postdate';
        $this->assertSame('2026-08-01 12:00:00', DB::table('collections')->value($column));
    }

    /** @return array<string, array{string, int}> */
    public static function rangeDirections(): array
    {
        return ['head safe' => ['binaries', 1], 'head unsafe' => ['binaries', 0], 'tail safe' => ['backfill', 1], 'tail unsafe' => ['backfill', 0]];
    }

    public function test_ingestion_stamps_are_monotone_and_repair_uses_current_head(): void
    {
        $harness = new TestBinariesHarness;
        $header = $this->makeHeader(8001, 1, 2, 100);
        $header['Date'] = '2026-08-01 12:00:00';
        $group = ['id' => 1, 'name' => 'alt.test', 'last_record_postdate' => '2026-08-02 12:00:00'];
        $harness->simulateScan([$header], $group, direction: HeaderScanDirection::Head);
        $this->assertSame('2026-08-01 12:00:00', DB::table('collections')->value('last_seen_head_postdate'));
        $header['Date'] = '2026-08-01 10:00:00';
        $harness->simulateScan([$header], $group, direction: HeaderScanDirection::Head);
        $this->assertSame('2026-08-01 12:00:00', DB::table('collections')->value('last_seen_head_postdate'));
        $header['Date'] = '2026-08-01 13:00:00';
        $harness->simulateScan([$header], $group, direction: HeaderScanDirection::Head);
        $this->assertSame('2026-08-01 13:00:00', DB::table('collections')->value('last_seen_head_postdate'));
        $header['Date'] = '2026-08-01 10:00:00';
        $harness->simulateScan([$header], $group, direction: HeaderScanDirection::Tail);
        $header['Date'] = '2026-08-01 11:00:00';
        $harness->simulateScan([$header], $group, direction: HeaderScanDirection::Tail);
        $this->assertSame('2026-08-01 10:00:00', DB::table('collections')->value('last_seen_tail_postdate'));
        $harness->simulateScan([$header], $group, direction: HeaderScanDirection::Repair);
        $this->assertSame('2026-08-02 12:00:00', DB::table('collections')->value('last_seen_head_postdate'));
        $this->assertNotNull(DB::table('collections')->value('last_seen_at'));
    }

    public function test_named_set_headers_form_one_collection_with_declared_total(): void
    {
        $this->seed(CollectionRegexesTableSeeder::class);
        Cache::flush();

        $harness = new TestBinariesHarness(headerChunkSize: 1);
        $subjects = [
            '"1787977202_nicovideo_jp_watch_sm23010895.tar.zst.par2" yEnc (1/1) 760',
            '[1/4] - "1787977202_nicovideo_jp_watch_sm23010895.tar.zst" yEnc (1/1) 11643018',
            '[2/4] - "1787977202_nicovideo_jp_watch_sm23010895.tar.zst.vol00+01.par2" yEnc (1/1) 800828',
            '[3/4] - "1787977202_nicovideo_jp_watch_sm23010895.tar.zst.vol01+02.par2" yEnc (1/1) 1601656',
            '[4/4] - "1787977202_nicovideo_jp_watch_sm23010895.tar.zst.vol03+04.par2" yEnc (1/1) 3203312',
        ];
        $headers = [];

        foreach ($subjects as $index => $subject) {
            $articleNumber = 5001 + $index;
            $headers[] = [
                'Number' => $articleNumber,
                'Subject' => $subject,
                'From' => 'poster@example.com',
                'Date' => time(),
                'Bytes' => 100,
                'Message-ID' => '<msg'.$articleNumber.'@example.com>',
                'Xref' => 'news.example.com alt.binaries.boneless:'.$articleNumber,
            ];
        }

        $harness->simulateScan($headers, ['id' => 1, 'name' => 'alt.binaries.boneless']);

        $declaredCollection = DB::table('collections')->where('collection_regexes_id', 113)->sole();
        $barePar2Collection = DB::table('collections')->where('collection_regexes_id', 111)->sole();

        $this->assertSame(2, DB::table('collections')->count());
        $this->assertSame(4, (int) $declaredCollection->totalfiles);
        $this->assertSame(4, (int) $declaredCollection->declaredfiles);
        $this->assertSame(CollectionFileCheckStatus::Sized->value, (int) $declaredCollection->filecheck);
        $this->assertSame(
            4,
            DB::table('binaries')->where('collections_id', $declaredCollection->id)->count(),
        );
        $this->assertSame(0, (int) $barePar2Collection->totalfiles);
        $this->assertSame(
            1,
            DB::table('binaries')->where('collections_id', $barePar2Collection->id)->count(),
        );
    }

    public function test_duplicate_collection_and_binary_reuse(): void
    {
        // Skip this test when using SQLite as it doesn't support REGEXP function
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('This test requires MySQL REGEXP function which is not available in SQLite.');
        }

        $harness = new TestBinariesHarness;

        $headers = [
            $this->makeHeader(1001, 1, 2, 150),
            $this->makeHeader(1002, 2, 2, 175),
        ];

        $harness->publicStoreHeaders($headers);

        $collections = DB::table('collections')->count();
        $binaries = DB::table('binaries')->count();
        $parts = DB::table('parts')->count();
        $binary = DB::table('binaries')->first();

        $this->assertEquals(1, $collections, 'Should have reused collection');
        $this->assertEquals(1, $binaries, 'Should have reused binary');
        $this->assertEquals(2, $parts, 'Two part rows expected');
        $this->assertEquals(325, $binary->partsize, 'Binary partsize should be sum of part sizes');
        $this->assertEquals(2, $binary->currentparts, 'Binary currentparts should reflect both parts');
    }

    public function test_raw_message_id_stored_unmodified(): void
    {
        // Skip this test when using SQLite as it doesn't support REGEXP function
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('This test requires MySQL REGEXP function which is not available in SQLite.');
        }

        $harness = new TestBinariesHarness;
        $headers = [$this->makeHeader(3001, 1, 1, 123)];
        $harness->publicStoreHeaders($headers);
        $stored = DB::table('parts')->where('number', 3001)->value('messageid');
        $this->assertSame('<msg3001@example.com>', $stored, 'Message-ID should be stored with angle brackets intact');
    }

    public function test_rollback_on_parts_insert_failure_and_part_repair_queue(): void
    {
        $harness = new TestBinariesHarness;
        $group = ['id' => 2, 'name' => 'alt.rollback'];

        config(['nntmux.cbp.sql_chunk_size' => 2]); // force flush earlier

        $headers = [
            $this->makeHeader(2001, 1, 3, 111),
            $this->makeHeader(2002, 2, 3, 222),
            $this->makeHeader(2003, 3, 3, 333),
        ];

        $harness->failPartsInsert = true; // Force flushPartsChunk failure
        $harness->simulateScan($headers, $group, true);

        $this->assertEquals(0, DB::table('collections')->count(), 'Collections should rollback');
        $this->assertEquals(0, DB::table('binaries')->count(), 'Binaries should rollback');
        $this->assertEquals(0, DB::table('parts')->count(), 'Parts should rollback');

        $missed = DB::table('missed_parts')->pluck('numberid')->toArray();
        sort($missed);
        $this->assertEquals([2001, 2002, 2003], $missed, 'All headers should be marked missed after rollback');
    }

    public function test_mixed_success_then_failure_rolls_back_everything(): void
    {
        $harness = new TestBinariesHarness;
        $group = ['id' => 3, 'name' => 'alt.mixed'];

        // Chunk of 2: first flush succeeds, second flush fails
        config(['nntmux.cbp.sql_chunk_size' => 2]);

        $headers = [
            $this->makeHeader(4001, 1, 5, 101),
            $this->makeHeader(4002, 2, 5, 102), // triggers first successful flush
            $this->makeHeader(4003, 3, 5, 103),
            $this->makeHeader(4004, 4, 5, 104), // triggers failing flush
            $this->makeHeader(4005, 5, 5, 105), // buffered but not flushed
        ];

        $harness->failPartsInsert = true;
        $harness->failAfterFlushCount = 1; // fail on second flush
        $harness->simulateScan($headers, $group, true);

        $this->assertEquals(0, DB::table('collections')->count(), 'Collections should rollback after mixed flush');
        $this->assertEquals(0, DB::table('binaries')->count(), 'Binaries should rollback after mixed flush');
        $this->assertEquals(0, DB::table('parts')->count(), 'Parts should rollback after mixed flush');

        $missed = DB::table('missed_parts')->pluck('numberid')->toArray();
        sort($missed);
        $this->assertEquals([4001, 4002, 4003, 4004, 4005], $missed, 'All headers should be marked missed after mixed rollback');
    }
}
