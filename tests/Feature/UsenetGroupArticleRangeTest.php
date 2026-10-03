<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UsenetGroup;
use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinariesService;
use App\Services\Binaries\HeaderParser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NeverBlacklistedService;
use Tests\TestCase;

class UsenetGroupArticleRangeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-17 14:30:00');
        config(['app.timezone' => 'UTC']);

        Schema::dropIfExists('usenet_groups');
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('first_record');
            $table->dateTime('first_record_postdate')->nullable();
            $table->dateTime('backfill_settled_at')->nullable();
            $table->unsignedBigInteger('last_record');
            $table->dateTime('last_record_postdate')->nullable();
            $table->dateTime('last_updated')->nullable();
        });

        Schema::dropIfExists('usenet_group_ingested_ranges');
        Schema::create('usenet_group_ingested_ranges', function (Blueprint $table): void {
            $table->unsignedInteger('usenet_groups_id');
            $table->unsignedBigInteger('first_record');
            $table->unsignedBigInteger('last_record');
            $table->dateTime('last_record_postdate')->nullable();
            $table->primary(['usenet_groups_id', 'first_record']);
        });

        DB::table('usenet_groups')->insert([
            'id' => 1,
            'first_record' => 500,
            'first_record_postdate' => '2026-08-10 00:00:00',
            'last_record' => 1_000,
            'last_record_postdate' => '2026-08-16 00:00:00',
            'last_updated' => null,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Schema::dropIfExists('usenet_group_ingested_ranges');
        Schema::dropIfExists('usenet_groups');

        parent::tearDown();
    }

    public function test_overlapping_retries_coalesce_without_moving_the_head_backwards(): void
    {
        UsenetGroup::advanceLastRecordContiguously(1, 1101, 1200, (int) strtotime('2026-08-17 12:00:00'));
        UsenetGroup::advanceLastRecordContiguously(1, 1001, 1150, (int) strtotime('2026-08-17 11:30:00'));
        UsenetGroup::advanceLastRecordContiguously(1, 1001, 1100, (int) strtotime('2026-08-17 11:00:00'));
        $this->assertDatabaseHas('usenet_groups', ['id' => 1, 'last_record' => 1200, 'last_record_postdate' => '2026-08-17 12:00:00']);
        $this->assertSame(0, DB::table('usenet_group_ingested_ranges')->count());
    }

    public function test_new_group_can_start_at_its_configured_scan_window(): void
    {
        DB::table('usenet_groups')->where('id', 1)->update(['last_record' => 0]);
        UsenetGroup::advanceLastRecordContiguously(1, 5001, 5100, (int) strtotime('2026-08-17 12:00:00'));
        $this->assertDatabaseHas('usenet_groups', ['id' => 1, 'last_record' => 5100]);
    }

    public function test_frontier_migration_is_additive_idempotent_and_leaves_legacy_rows_unstamped(): void
    {
        Schema::create('collections', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('last_seen_at')->nullable();
        });
        DB::table('collections')->insert(['id' => 1, 'last_seen_at' => '2026-08-01 12:00:00']);
        $migration = require database_path('migrations/2026_09_05_212852_add_collection_ingestion_frontiers.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseHas('collections', [
            'id' => 1, 'last_seen_at' => '2026-08-01 12:00:00',
            'last_seen_head_postdate' => null, 'last_seen_tail_postdate' => null,
        ]);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('collections', 'last_seen_head_postdate'));
        $this->assertFalse(Schema::hasColumn('collections', 'last_seen_tail_postdate'));
        $this->assertFalse(Schema::hasColumn('usenet_groups', 'backfill_settled_at'));
    }

    public function test_head_waits_for_missing_chunk_then_coalesces_completed_ranges(): void
    {
        UsenetGroup::advanceLastRecordContiguously(1, 1101, 1200, (int) strtotime('2026-08-17 12:00:00'));
        $this->assertSame(1000, DB::table('usenet_groups')->value('last_record'));
        $this->assertDatabaseHas('usenet_group_ingested_ranges', ['first_record' => 1101, 'last_record' => 1200]);

        UsenetGroup::advanceLastRecordContiguously(1, 1001, 1100, (int) strtotime('2026-08-17 11:00:00'));
        $this->assertDatabaseHas('usenet_groups', ['id' => 1, 'last_record' => 1200, 'last_record_postdate' => '2026-08-17 12:00:00']);
        $this->assertSame(0, DB::table('usenet_group_ingested_ranges')->count());

        UsenetGroup::advanceLastRecordContiguously(1, 1201, 1300, null);
        $this->assertDatabaseHas('usenet_groups', ['id' => 1, 'last_record' => 1300, 'last_record_postdate' => '2026-08-17 12:00:00']);
    }

    /**
     * @param  list<array{int, int, ?string}>  $parked
     * @param  array{int, int, ?string}  $incoming
     */
    #[DataProvider('frontierDates')]
    public function test_the_frontier_date_only_moves_forward_and_never_past_now(?string $stored, array $parked, array $incoming, ?string $expected, int $expectedLast): void
    {
        Carbon::setTestNow('2026-08-17 14:31:00');
        DB::table('usenet_groups')->where('id', 1)->update(['last_record_postdate' => $stored]);
        foreach ($parked as [$first, $last, $date]) {
            $this->assertSame(0, UsenetGroup::advanceLastRecordContiguously(1, $first, $last, self::timestamp($date)));
        }

        [$first, $last, $date] = $incoming;
        $this->assertSame(1, UsenetGroup::advanceLastRecordContiguously(1, $first, $last, self::timestamp($date)));

        $group = DB::table('usenet_groups')->find(1);
        $this->assertSame($expectedLast, (int) $group->last_record);
        $this->assertSame($expected, $group->last_record_postdate);
        $this->assertSame(0, DB::table('usenet_group_ingested_ranges')->count());
    }

    /**
     * Shared with the secondary cursor test. The position starts at 1000 and `now()` is
     * 2026-08-17 14:31:00. Each case: stored date, parked ranges, incoming range, expected
     * date, expected position.
     *
     * @return array<string, array{?string, list<array{int, int, ?string}>, array{int, int, ?string}, ?string, int}>
     */
    public static function frontierDates(): array
    {
        return [
            'an older range moves the position, not the date' => ['2026-08-17 12:00:00', [], [1001, 1100, '2026-08-17 10:00:00'], '2026-08-17 12:00:00', 1100],
            'a newer range moves the date forward' => ['2026-08-17 12:00:00', [], [1001, 1100, '2026-08-17 13:00:00'], '2026-08-17 13:00:00', 1100],
            'an absorbed parked range older than the incoming one' => ['2026-08-17 10:00:00', [[1101, 1200, '2026-08-17 09:00:00']], [1001, 1100, '2026-08-17 11:00:00'], '2026-08-17 11:00:00', 1200],
            'an absorbed parked range and an incoming range both older than stored' => ['2026-08-17 10:00:00', [[1101, 1200, '2026-08-17 09:00:00']], [1001, 1100, '2026-08-17 08:00:00'], '2026-08-17 10:00:00', 1200],
            'the newest of two absorbed parked ranges wins' => ['2026-08-17 10:00:00', [[1101, 1200, '2026-08-17 13:00:00'], [1201, 1300, '2026-08-17 09:00:00']], [1001, 1100, '2026-08-17 08:00:00'], '2026-08-17 13:00:00', 1300],
            'a future date is capped at now' => ['2026-08-17 12:00:00', [], [1001, 1100, '2026-08-18 00:00:00'], '2026-08-17 14:31:00', 1100],
            'a stored date later than now is kept' => ['2026-08-17 15:00:00', [], [1001, 1100, '2026-08-17 14:00:00'], '2026-08-17 15:00:00', 1100],
            'a NULL stored date takes the incoming date' => [null, [], [1001, 1100, '2026-08-17 11:00:00'], '2026-08-17 11:00:00', 1100],
            'a NULL incoming date keeps the stored date' => ['2026-08-17 12:00:00', [], [1001, 1100, null], '2026-08-17 12:00:00', 1100],
            'the date stays NULL when every input is NULL' => [null, [], [1001, 1100, null], null, 1100],
        ];
    }

    public function test_the_range_date_is_the_newest_valid_posting_date_in_the_range(): void
    {
        DB::table('usenet_groups')->where('id', 1)->update(['last_record_postdate' => '2026-08-17 09:00:00']);
        $headers = [
            ['Number' => '1001', 'Date' => '2026-08-17 09:30:00'],
            ['Number' => '1002', 'Date' => '2026-08-17 13:00:00'],
            ['Number' => '1003', 'Date' => 'not a date'],
            ['Number' => '9999', 'Date' => '2026-08-17 14:00:00'],
            ['Number' => '1004', 'Date' => '2026-08-17 10:00:00'],
        ];
        $summary = (new HeaderParser(new NeverBlacklistedService))->getArticleRange($headers, 'alt.test', 1001, 1004);

        $this->assertSame('2026-08-17 10:00:00', $summary['lastArticleDate']);
        $this->assertSame('2026-08-17 13:00:00', $summary['newestArticleDate']);

        $group = ['id' => 1, 'name' => 'alt.test', 'first_record' => 500, 'first_record_postdate' => '2026-08-10 00:00:00', 'last_record' => 1000];
        $this->scanProgressService()->persistScanProgress($group, [], $summary, 1004);

        $this->assertDatabaseHas('usenet_groups', ['id' => 1, 'last_record' => 1004, 'last_record_postdate' => '2026-08-17 13:00:00']);
    }

    private static function timestamp(?string $date): ?int
    {
        return $date === null ? null : (int) strtotime($date);
    }

    public function test_every_tail_rewind_clears_the_settled_marker(): void
    {
        foreach (['recordBackfillProgress', 'rewindFirstRecord', 'initializeOrRewindFirstRecord'] as $method) {
            DB::table('usenet_groups')->where('id', 1)->update([
                'first_record' => 500, 'backfill_settled_at' => '2026-08-17 00:00:00',
            ]);
            UsenetGroup::$method(1, 400, (int) strtotime('2026-08-09 08:15:00'));
            $this->assertNull(DB::table('usenet_groups')->value('backfill_settled_at'));
        }
    }

    public function test_backfill_progress_uses_the_php_clock_for_last_updated(): void
    {
        UsenetGroup::recordBackfillProgress(1, 400, (int) strtotime('2026-08-09 08:15:00'));

        $group = DB::table('usenet_groups')->find(1);

        $this->assertSame(400, $group->first_record);
        $this->assertSame('2026-08-09 08:15:00', $group->first_record_postdate);
        $this->assertSame('2026-08-17 14:30:00', $group->last_updated);
    }

    public function test_article_range_updates_bind_the_php_clock_and_keep_monotonic_boundaries(): void
    {
        $this->assertSame(1, UsenetGroup::advanceLastRecord(1, 1_100, (int) strtotime('2026-08-17 10:00:00')));
        $this->assertSame(0, UsenetGroup::advanceLastRecord(1, 1_050, (int) strtotime('2026-08-17 09:00:00')));

        Carbon::setTestNow('2026-08-17 14:31:00');

        $this->assertSame(1, UsenetGroup::rewindFirstRecord(1, 300, (int) strtotime('2026-08-08 07:00:00')));
        $this->assertSame(0, UsenetGroup::rewindFirstRecord(1, 350, (int) strtotime('2026-08-08 08:00:00')));

        $group = DB::table('usenet_groups')->find(1);

        $this->assertSame(300, $group->first_record);
        $this->assertSame('2026-08-08 07:00:00', $group->first_record_postdate);
        $this->assertSame(1_100, $group->last_record);
        $this->assertSame('2026-08-17 10:00:00', $group->last_record_postdate);
        $this->assertSame('2026-08-17 14:31:00', $group->last_updated);
    }

    public function test_stale_header_scan_cannot_move_group_boundaries_in_the_wrong_direction(): void
    {
        $service = $this->scanProgressService();
        $staleGroup = [
            'id' => 1,
            'name' => 'alt.test',
            'first_record' => 0,
            'first_record_postdate' => null,
            'last_record' => 0,
        ];

        $service->persistScanProgress($staleGroup, [], [
            'firstArticleNumber' => 700,
            'firstArticleDate' => '2026-08-12 00:00:00',
            'lastArticleNumber' => 900,
            'lastArticleDate' => '2026-08-15 00:00:00',
            'newestArticleDate' => '2026-08-15 00:00:00',
        ], 900);

        $group = DB::table('usenet_groups')->find(1);

        $this->assertSame(500, $group->first_record);
        $this->assertSame('2026-08-10 00:00:00', $group->first_record_postdate);
        $this->assertSame(1_000, $group->last_record);
        $this->assertSame('2026-08-16 00:00:00', $group->last_record_postdate);
    }

    private function scanProgressService(): BinariesService
    {
        return new class(new BinariesConfig(partRepair: false, echoCli: false)) extends BinariesService
        {
            /**
             * @param  array<string, mixed>  $groupMySQL
             * @param  array<string, mixed>  $groupNNTP
             * @param  array<string, mixed>  $scanSummary
             */
            public function persistScanProgress(array &$groupMySQL, array $groupNNTP, array $scanSummary, int $last): void
            {
                $this->updateGroupAfterScan($groupMySQL, $groupNNTP, $scanSummary, $last);
            }
        };
    }
}
