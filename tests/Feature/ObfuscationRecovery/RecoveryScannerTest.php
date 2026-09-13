<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Enums\HeaderScanDirection;
use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinariesService;
use App\Services\NNTP\NntpProvider;
use App\Services\NNTP\NNTPService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class RecoveryScannerTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->registerSqliteFunction('regexp', static fn (string $pattern, string $value): int => (int) preg_match('/'.$pattern.'/', $value));
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('binaryblacklist', function (Blueprint $table): void {
            $table->id();
            $table->string('groupname');
            $table->string('regex');
            $table->string('description')->default('');
            $table->integer('msgcol')->default(1);
            $table->integer('optype')->default(1);
            $table->integer('status')->default(1);
            $table->timestamp('last_activity')->nullable();
        });
        Schema::create('missed_parts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('numberid');
            $table->unsignedInteger('groups_id');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unique(['numberid', 'groups_id']);
        });
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
        DB::statement('CREATE TABLE collection_regexes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_regex VARCHAR(255),
            regex VARCHAR(255),
            status INT DEFAULT 1,
            ordinal INT DEFAULT 0,
            description VARCHAR(1000) DEFAULT ""
        )');
        (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
        (require database_path('migrations/2026_09_13_002751_add_recovery_frontier_evidence.php'))->up();
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.fixture', 'obfuscation_recovery_profile' => 'both']);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_scan_tees_before_storage_and_applies_raw_blacklist_policy(): void
    {
        DB::table('binaryblacklist')->insert(['groupname' => '.*', 'regex' => '^blocked', 'msgcol' => 2]);
        $headers = [$this->header(1, '[a] - '.str_repeat('a', 32).' yEnc (1/4)'),
            $this->header(2, '0123456789abcdefghij'),
            [...$this->header(3, 'abcdefghijklmnopqrst'), 'From' => 'blocked@example.invalid']];
        $capturedBeforeStorage = false;
        DB::listen(function (QueryExecuted $event) use (&$capturedBeforeStorage): void {
            if (preg_match('/^insert\s+(?:or ignore\s+)?into\s+["`]?parts["`]?\s/i', $event->sql) === 1) {
                $capturedBeforeStorage = DB::table('obfuscation_recovery_headers')->count() === 1;
            }
        });
        $scanner = $this->scanner($headers);
        $summary = $scanner->scan(['id' => 1, 'name' => 'alt.binaries.fixture'], 4000000001, 4000000003, HeaderScanDirection::Head);
        $this->assertFalse($scanner->lastScanWasRejected());
        $this->assertTrue($capturedBeforeStorage);
        $this->assertSame($headers[0]['Message-ID'], DB::table('parts')->value('messageid'));
        $this->assertSame(4000000003, $summary['lastArticleNumber']);
        $this->assertSame(2, DB::table('obfuscation_recovery_headers')->count());
        $this->assertSame(3, DB::table('obfuscation_recovery_scans')->where('complete', true)->count());
        $this->assertNotNull(DB::table('binaryblacklist')->value('last_activity'));
        $this->assertSame(0, DB::table('missed_parts')->count());
    }

    public function test_capture_failure_preserves_normal_repair_acknowledgement(): void
    {
        Schema::drop('obfuscation_recovery_headers');
        DB::table('missed_parts')->insert(['groups_id' => 1, 'numberid' => 4000000001]);
        $scanner = $this->scanner([$this->header(1, '0123456789abcdefghij')]);
        $summary = $scanner->scan(['id' => 1, 'name' => 'alt.binaries.fixture'], 4000000001, 4000000001,
            HeaderScanDirection::Repair, 'partrepair', [4000000001]);
        $this->assertFalse($scanner->lastScanWasRejected());
        $this->assertNotEmpty($summary);
        $this->assertSame(0, DB::table('missed_parts')->count());
        $this->assertSame(0, DB::table('obfuscation_recovery_scans')->count());
    }

    public function test_ordinary_storage_failure_keeps_capture_and_queues_repair(): void
    {
        Schema::drop('parts');
        $scanner = $this->scanner([$this->header(1, '[a] - '.str_repeat('a', 32).' yEnc (1/4)')]);
        $summary = $scanner->scan(['id' => 1, 'name' => 'alt.binaries.fixture'], 4000000001, 4000000001, HeaderScanDirection::Head);
        $this->assertFalse($scanner->lastScanWasRejected());
        $this->assertSame(4000000001, $summary['lastArticleNumber']);
        $this->assertSame(1, DB::table('obfuscation_recovery_headers')->count());
        $this->assertSame(1, DB::table('missed_parts')->where('numberid', 4000000001)->count());
    }

    public function test_successful_empty_overview_records_explicit_absence(): void
    {
        $scanner = $this->scanner([]);
        $scanner->scan(['id' => 1, 'name' => 'alt.binaries.fixture'], 4000000001, 4000000100, HeaderScanDirection::Head);
        $this->assertFalse($scanner->lastScanWasRejected());
        $row = DB::table('obfuscation_recovery_scans')->first();
        $this->assertTrue((bool) $row->complete);
        $this->assertSame([[4000000001, 4000000100]], json_decode($row->missing_ranges, true));
    }

    /** @param list<array<string,mixed>> $headers */
    private function scanner(array $headers): BinariesService
    {
        return new BinariesService(config: new BinariesConfig(messageBuffer: 20000, compressedHeaders: false,
            partRepair: true, echoCli: false, headerChunkSize: 1), nntp: new RecoveryOverviewFixture($headers));
    }

    /** @return array<string,mixed> */
    private function header(int $ordinal, string $subject): array
    {
        return ['Number' => (string) (4000000000 + $ordinal), 'Subject' => $subject, 'From' => 'fixture@example.invalid',
            'Message-ID' => 'm'.$ordinal.'-1700000000000@nyuu', 'Date' => 'Tue, 14 Nov 2023 22:13:20 +0000', 'Bytes' => 740000, 'Xref' => ''];
    }
}

final class RecoveryOverviewFixture extends NNTPService
{
    /** @param list<array<string,mixed>> $headers */
    public function __construct(private readonly array $headers) {}

    public function provider(): NntpProvider
    {
        return NntpProvider::fromConfig(['position' => 1, 'name' => 'fixture', 'host' => 'fixture.invalid']);
    }

    public function getXOVER(string $range): mixed
    {
        return $this->headers;
    }

    public function getOverview(mixed $range = null, bool $names = true, bool $forceNames = true): mixed
    {
        return $this->headers;
    }

    public function __destruct() {}
}
