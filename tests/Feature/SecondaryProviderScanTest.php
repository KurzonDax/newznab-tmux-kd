<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\HeaderScanDirection;
use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinariesService;
use App\Services\NNTP\NntpProvider;
use App\Services\NNTP\NNTPService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

final class SecondaryProviderScanTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->registerSqliteFunction('regexp', static fn (string $pattern, string $value): int => (int) preg_match('/'.$pattern.'/', $value));

        $tables = ProductionTables::fromAuthority();
        $tables->create('usenet_groups', ['id', 'name']);
        foreach (['collections', 'collection_groups', 'binaries', 'parts', 'missed_parts', 'collection_regexes', 'binaryblacklist'] as $table) {
            $tables->create($table);
        }
        (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
        (require database_path('migrations/2026_09_13_002751_add_recovery_frontier_evidence.php'))->up();
        (require database_path('migrations/2026_09_13_155226_add_recovery_frontier_repair_allowances.php'))->up();
        (require database_path('migrations/2026_09_13_190549_add_recovery_frontier_request_attribution.php'))->up();
        (require database_path('migrations/2026_09_14_110835_add_recovery_handoff_and_process_identity.php'))->up();
        (require database_path('migrations/2026_09_18_120000_bucket_obfuscation_recovery_dirty_marks.php'))->up();
        DB::table('settings')->updateOrInsert(['name' => 'obfuscation_recovery_enabled'], ['value' => '1']);
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.fixture', 'obfuscation_recovery_profile' => 'both']);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_both_providers_headers_for_one_post_merge_into_one_set_of_rows(): void
    {
        $this->scanner(1, [$this->header(101, 1), $this->header(102, 2), $this->header(103, 3)])
            ->scan($this->group(), 101, 103, HeaderScanDirection::Head);
        $this->scanner(2, [$this->header(9001, 2), $this->header(9002, 3), $this->header(9003, 4)])
            ->scan($this->group(), 9001, 9003, HeaderScanDirection::Head);

        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(1, DB::table('binaries')->count());
        $this->assertSame(4, (int) DB::table('binaries')->value('currentparts'));
        $this->assertSame(
            [1 => 101, 2 => 102, 3 => 103, 4 => 0],
            DB::table('parts')->orderBy('partnumber')->pluck('number', 'partnumber')
                ->mapWithKeys(fn (mixed $number, mixed $part): array => [(int) $part => (int) $number])->all(),
        );
    }

    public function test_a_secondary_scan_records_no_missed_parts_and_captures_nothing_for_recovery(): void
    {
        // The recovery migrations seed a few rows of their own.
        $seeded = $this->recoveryRowCounts();

        $returned = $this->scanner(2, [$this->header(9001, 1), $this->header(9002, 2)])
            ->scan($this->group(), 9001, 9010, HeaderScanDirection::Head);

        $this->assertSame(9001, $returned['firstArticleNumber']);
        $this->assertSame(2, DB::table('parts')->count());
        $this->assertSame(0, DB::table('missed_parts')->count());
        $this->assertSame($seeded, $this->recoveryRowCounts());

        // The same scan on provider 1 queues the gap and captures for recovery.
        $this->scanner(1, [$this->header(101, 1), $this->header(102, 2)])
            ->scan($this->group(), 101, 110, HeaderScanDirection::Head);

        $this->assertGreaterThan(0, DB::table('missed_parts')->count());
        $this->assertNotSame($seeded, $this->recoveryRowCounts());
    }

    public function test_a_provider_1_scan_skips_a_segment_0_header_without_queueing_part_repair(): void
    {
        $scanner = $this->scanner(1, $this->postWithSegment0(103));

        $returned = $scanner->scan($this->group(), 101, 104, HeaderScanDirection::Head);

        $this->assertFalse($scanner->lastScanWasRejected());
        $this->assertNotSame([], $returned);
        $this->assertSame([1, 2, 4], $this->storedPartNumbers());
        $this->assertSame(0, DB::table('missed_parts')->count());
    }

    public function test_a_secondary_range_holding_a_segment_0_header_completes(): void
    {
        $scanner = $this->scanner(2, $this->postWithSegment0(103));

        $scanner->scan($this->group(), 101, 104, HeaderScanDirection::Head);

        $this->assertFalse($scanner->lastScanWasRejected());
        $this->assertSame([1, 2, 4], $this->storedPartNumbers());
    }

    public function test_part_repair_keeps_the_numbers_of_a_chunk_that_rolled_back(): void
    {
        foreach ([101, 102, 103, 104] as $number) {
            DB::table('missed_parts')->insert(['numberid' => $number, 'groups_id' => 1, 'attempts' => 0]);
        }
        DB::connection()->beforeExecuting(static function (string $sql, array $bindings): void {
            if (str_contains($sql, 'INTO parts') && \in_array('<part3-1700000000000@nyuu>', $bindings, true)) {
                throw new QueryException('sqlite', $sql, $bindings, new \PDOException('forced'));
            }
        });

        $this->scanner(1, [$this->header(101, 1), $this->header(102, 2), $this->header(103, 3), $this->header(104, 4)], headerChunkSize: 2)
            ->scan($this->group(), 101, 104, HeaderScanDirection::Repair, 'partrepair', [101, 102, 103, 104]);

        $this->assertSame([103, 104], DB::table('missed_parts')->orderBy('numberid')->pluck('numberid')->map(fn (mixed $n): int => (int) $n)->all());
    }

    public function test_a_scan_logs_its_skipped_headers_once_with_the_first_real_article_number(): void
    {
        Log::spy();
        $headers = $this->postWithSegment0(103);
        $headers[1] = $this->header(102, 0);

        $this->scanner(2, $headers)->scan($this->group(), 101, 104, HeaderScanDirection::Head);

        Log::shouldHaveReceived('warning')->with('Skipped headers that cannot be stored.', \Mockery::any())->once();
        Log::shouldHaveReceived('warning')->with('Skipped headers that cannot be stored.', \Mockery::on(
            static fn (array $context): bool => $context['count'] === 2 && $context['first_article'] === 102,
        ))->once();
    }

    /** @param  'partrepair'|'update'  $type */
    #[DataProvider('refusedScans')]
    public function test_a_secondary_connection_refuses_repair_and_tail_scans(string $type, HeaderScanDirection $direction): void
    {
        DB::table('missed_parts')->insert(['numberid' => 9001, 'groups_id' => 1, 'attempts' => 0]);

        try {
            $this->scanner(2, [$this->header(9001, 1)])->scan($this->group(), 9001, 9001, $direction, $type, [9001]);
            $this->fail('A secondary connection ran a '.$type.' '.$direction->name.' scan.');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame([9001], DB::table('missed_parts')->pluck('numberid')->map(fn (mixed $n): int => (int) $n)->all());
        $this->assertSame(0, DB::table('parts')->count());
    }

    /** @return array<string, array{string, HeaderScanDirection}> */
    public static function refusedScans(): array
    {
        return [
            'part repair' => ['partrepair', HeaderScanDirection::Repair],
            'tail' => ['update', HeaderScanDirection::Tail],
        ];
    }

    /** @return array<string, int> Non-empty obfuscation_recovery_* tables and their row counts. */
    private function recoveryRowCounts(): array
    {
        $counts = [];
        foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'obfuscation\\_recovery\\_%' ESCAPE '\\'") as $table) {
            $count = DB::table($table->name)->count();
            if ($count > 0) {
                $counts[$table->name] = $count;
            }
        }

        return $counts;
    }

    /** @param list<array<string, mixed>> $headers */
    private function scanner(int $position, array $headers, int $headerChunkSize = 500): BinariesService
    {
        return new BinariesService(config: new BinariesConfig(messageBuffer: 20000, compressedHeaders: false,
            partRepair: true, echoCli: false, headerChunkSize: $headerChunkSize), nntp: new ProviderOverviewFixture($position, $headers));
    }

    /** @return list<array<string, mixed>> Articles 101-104 carrying parts 1-4, with $article posted as segment 0. */
    private function postWithSegment0(int $article): array
    {
        $headers = [];
        foreach ([101 => 1, 102 => 2, 103 => 3, 104 => 4] as $number => $part) {
            $headers[] = $this->header($number, $number === $article ? 0 : $part);
        }

        return $headers;
    }

    /** @return list<int> */
    private function storedPartNumbers(): array
    {
        return DB::table('parts')->orderBy('partnumber')->pluck('partnumber')->map(fn (mixed $n): int => (int) $n)->all();
    }

    /** @return array{id: int, name: string} */
    private function group(): array
    {
        return ['id' => 1, 'name' => 'alt.binaries.fixture'];
    }

    /** @return array<string, mixed> */
    private function header(int $article, int $part): array
    {
        return ['Number' => (string) $article, 'Subject' => 'Example.Post.Name yEnc ('.$part.'/4)', 'From' => 'fixture@example.invalid',
            'Message-ID' => '<part'.$part.'-1700000000000@nyuu>', 'Date' => 'Tue, 14 Nov 2023 22:13:20 +0000', 'Bytes' => 740000, 'Xref' => ''];
    }
}

final class ProviderOverviewFixture extends NNTPService
{
    /** @param list<array<string, mixed>> $headers */
    public function __construct(private readonly int $position, private readonly array $headers) {}

    public function provider(): NntpProvider
    {
        return NntpProvider::fromConfig(['position' => $this->position, 'name' => 'fixture-'.$this->position, 'host' => 'fixture'.$this->position.'.invalid']);
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
