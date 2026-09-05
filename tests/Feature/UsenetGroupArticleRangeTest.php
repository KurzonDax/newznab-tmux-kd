<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UsenetGroup;
use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinariesService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        $service = new class(new BinariesConfig(partRepair: false, echoCli: false)) extends BinariesService
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
        ], 900);

        $group = DB::table('usenet_groups')->find(1);

        $this->assertSame(500, $group->first_record);
        $this->assertSame('2026-08-10 00:00:00', $group->first_record_postdate);
        $this->assertSame(1_000, $group->last_record);
        $this->assertSame('2026-08-16 00:00:00', $group->last_record_postdate);
    }
}
