<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Enums\HeaderScanDirection;
use App\Services\ObfuscationRecovery\RecoveryCapture;
use App\Services\ObfuscationRecovery\RecoveryCaptureBatch;
use App\Services\ObfuscationRecovery\RecoveryConfig;
use App\Services\ObfuscationRecovery\RecoveryFrontiers;
use App\Services\ObfuscationRecovery\RecoveryPositiveCoverage;
use App\Services\ObfuscationRecovery\RecoveryScanContext;
use App\Services\ObfuscationRecovery\RecoverySettlement;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\NeverBlacklistedService;
use Tests\TestCase;

final class RecoverySettlementTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
        });
        (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
        (require database_path('migrations/2026_09_13_002751_add_recovery_frontier_evidence.php'))->up();
        DB::table('usenet_groups')->insert(['id' => 1, 'obfuscation_recovery_profile' => 'both']);
        $this->travelTo(now()->setDate(2026, 9, 7)->setTime(15, 0));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_wall_clock_alone_cannot_settle_and_reversed_ranges_cannot_hide_a_gap(): void
    {
        $this->captureRange(101, 200, '2026-09-07 12:00:00');
        $this->assertSame('unknown_left_edge', $this->assess());
        $this->captureRange(1, 100, '2026-09-07 09:50:00');
        $this->assertSame('waiting_head_frontier', $this->assess());
        $this->captureRange(301, 400, '2026-09-07 14:10:00');
        $this->assertSame('waiting_head_frontier', $this->assess());
        $this->captureRange(201, 300, '2026-09-07 13:00:00', HeaderScanDirection::Repair);
        $this->assertSame('ready', $this->assess());
    }

    public function test_unrelated_one_second_date_reversal_does_not_prevent_settlement(): void
    {
        DB::transaction(fn () => (new RecoveryPositiveCoverage)->record(DB::connection(),
            new RecoveryScanContext(1, 'alt.binaries.fixture', 'epoch', 1, 1, 300, HeaderScanDirection::Head, (string) Str::uuid())));
        $this->captureRange(1, 1, '2026-09-07 09:50:00');
        $this->captureRange(300, 300, '2026-09-07 14:10:00');
        $context = new RecoveryScanContext(1, 'alt.binaries.fixture', 'epoch', 1, 110, 190,
            HeaderScanDirection::Head, (string) Str::uuid());
        $batch = new RecoveryCaptureBatch([
            ['Number' => 110, 'Subject' => 'ordinary marker', 'Date' => '2026-09-07 12:00:00 +0000'],
            ['Number' => 111, 'Subject' => 'unrelated marker', 'Date' => '2026-09-07 11:59:59 +0000'],
            ['Number' => 190, 'Subject' => 'ordinary marker', 'Date' => '2026-09-07 12:00:00 +0000'],
        ], []);
        $report = (new RecoveryCapture(RecoveryConfig::fromValues(['obfuscation_recovery_enabled' => 1]),
            new NeverBlacklistedService))->capture($batch, $context);
        $this->assertTrue($report->coverageComplete);
        $this->assertSame('ready', $this->assess());
    }

    public function test_fresh_membership_and_tail_only_frontiers_remain_unsettled(): void
    {
        $this->captureRange(1, 100, '2026-09-07 09:50:00');
        $this->captureRange(101, 200, '2026-09-07 12:00:00');
        $this->captureRange(201, 300, '2026-09-07 14:10:00', HeaderScanDirection::Tail);
        $this->assertSame('waiting_head_frontier', $this->assess());
        $this->captureRange(201, 300, '2026-09-07 14:10:00');
        $this->assertSame('waiting_quiet_interval', $this->assess('2026-09-07 14:59:00'));
        $this->assertSame('ready', $this->assess());
    }

    public function test_invalid_unrelated_dates_do_not_discard_usable_head_witnesses(): void
    {
        $context = new RecoveryScanContext(1, 'alt.binaries.fixture', 'epoch', 1, 1, 300,
            HeaderScanDirection::Head, (string) Str::uuid());
        $headers = [];
        foreach ([1 => '2026-09-07 09:50:00 +0000', 110 => '2026-09-07 12:00:00 +0000',
            111 => 'invalid', 112 => '2026-09-10 12:00:00 +0000', 190 => '2026-09-07 12:00:00 +0000',
            300 => '2026-09-07 14:10:00 +0000'] as $number => $date) {
            $headers[] = ['Number' => $number, 'Subject' => 'ordinary marker', 'Date' => $date];
        }
        $report = (new RecoveryCapture(RecoveryConfig::fromValues(['obfuscation_recovery_enabled' => 1]),
            new NeverBlacklistedService))->capture(new RecoveryCaptureBatch($headers, []), $context);
        $this->assertTrue($report->coverageComplete);
        $this->assertSame('ready', $this->assess());
    }

    public function test_a_later_island_can_settle_with_its_own_explicit_context(): void
    {
        $this->captureRange(1, 5, '2026-09-06 09:00:00');
        $this->captureRange(80, 100, '2026-09-07 09:50:00');
        $this->captureRange(101, 200, '2026-09-07 12:00:00');
        $this->captureRange(201, 300, '2026-09-07 14:10:00');
        $this->assertSame('ready', $this->assess());
        DB::table('obfuscation_recovery_scans')->where('requested_first', 101)->update(['complete' => false]);
        DB::transaction(fn () => (new RecoveryPositiveCoverage)->expire(DB::connection(), 'epoch', 1, 1, 150));
        $this->assertSame('unknown_capture_gap', $this->assess());
    }

    public function test_bad_dates_outside_a_candidates_complete_context_do_not_poison_later_work(): void
    {
        $this->captureRange(1, 20, '2026-09-07 07:00:00');
        $this->recordBadDates(1);
        $this->captureRange(21, 100, '2026-09-07 09:50:00');
        $this->captureRange(101, 200, '2026-09-07 12:00:00');
        $this->captureRange(201, 300, '2026-09-07 14:10:00');
        $this->captureRange(301, 400, '2026-09-07 16:00:00');
        $this->recordBadDates(301);
        $this->assertSame('ready', $this->assess());
        $this->recordBadDates(101);
        $this->assertSame('frontier_rebuild_required', $this->assess());
    }

    public function test_different_article_dates_can_decrease_across_chunks(): void
    {
        $this->captureRange(1, 100, '2026-09-07 09:50:00');
        $this->captureRange(101, 150, '2026-09-07 12:00:00');
        $this->captureRange(151, 200, '2026-09-07 11:30:00');
        $this->captureRange(201, 300, '2026-09-07 14:10:00');
        $this->assertSame('ready', $this->assess());
    }

    public function test_repeated_positive_scans_share_compact_intervals_without_losing_frontier_conflicts(): void
    {
        $this->captureRange(1, 100, '2026-09-07 09:50:00');
        $this->captureRange(101, 200, '2026-09-07 12:00:00');
        $this->captureRange(201, 300, '2026-09-07 14:10:00');
        $row = (array) DB::table('obfuscation_recovery_scans')->where('requested_first', 101)->first();
        unset($row['id']);
        for ($page = 0; $page < 11; $page++) {
            $rows = [];
            for ($i = 0; $i < 1000; $i++) {
                $rows[] = [...$row, 'scan_id' => (string) Str::uuid()];
            }
            DB::table('obfuscation_recovery_scans')->insert($rows);
        }
        $this->assertSame(1, DB::table('obfuscation_recovery_coverage')->where('kind', 'captured')->count());
        $this->assertSame('ready', $this->assess());
        $this->captureRange(110, 110, '2026-09-07 12:00:00');
        $this->captureRange(110, 110, '2026-09-07 12:00:00');
        $this->captureRange(110, 110, '2026-09-07 11:00:00');
        $this->assertSame('conflicting_posting_frontier', $this->assess());
        DB::transaction(fn () => (new RecoveryPositiveCoverage)->expire(DB::connection(), 'epoch', 1, 1, 150));
        $this->assertSame([[1, 149], [151, 300]], DB::table('obfuscation_recovery_coverage')->where('kind', 'captured')
            ->orderBy('first_article')->get()->map(fn ($row) => [(int) $row->first_article, (int) $row->last_article])->all());
        $this->assertSame(1, DB::table('obfuscation_recovery_coverage')->where('kind', 'retained')->count());
    }

    public function test_partial_raw_expiry_preserves_date_conflicts_inside_remaining_coverage(): void
    {
        $this->captureRange(1, 100, '2026-09-07 09:50:00');
        $this->captureRange(101, 150, '2026-09-07 12:00:00');
        $this->captureRange(151, 200, '2026-09-07 11:30:00');
        $this->captureRange(201, 300, '2026-09-07 14:10:00');
        $this->captureRange(110, 110, '2026-09-07 12:00:00');
        $this->captureRange(110, 110, '2026-09-07 11:00:00');
        DB::transaction(fn () => (new RecoveryPositiveCoverage)->expire(DB::connection(), 'epoch', 1, 1, 101));
        DB::table('obfuscation_recovery_scans')->where('requested_first', 101)
            ->update(['complete' => false, 'capture_outcome' => 'raw_expired']);
        $this->assertSame('conflicting_posting_frontier', $this->assess());
    }

    public function test_distinct_adjacent_scans_settle_without_an_audit_row_limit(): void
    {
        $this->captureRange(1, 100, '2026-09-07 09:50:00');
        $this->captureRange(11101, 11200, '2026-09-07 14:10:00');
        $template = DB::table('obfuscation_recovery_scans')->where('requested_first', 1)->first();
        DB::transaction(function () use ($template): void {
            for ($article = 101; $article <= 11100; $article++) {
                $context = new RecoveryScanContext(1, 'alt.binaries.fixture', 'epoch', 1, $article, $article,
                    HeaderScanDirection::Head, (string) Str::uuid());
                (new RecoveryPositiveCoverage)->record(DB::connection(), $context);
                $scan = clone $template;
                $scan->earliest_date_article = $scan->latest_date_article = $article;
                $scan->first_postdate = $scan->last_postdate = '2026-09-07 12:00:00';
                $scan->date_points = json_encode([[$article, $scan->first_postdate]], JSON_THROW_ON_ERROR);
                (new RecoveryFrontiers)->record(DB::connection(), $scan);
            }
        });
        $this->assertSame(11004, DB::table('obfuscation_recovery_frontiers')->count());
        $this->assertSame(1, DB::table('obfuscation_recovery_coverage')->where('kind', 'captured')->count());
        $this->assertSame('ready', (new RecoverySettlement)->assess('epoch', 1, 1, 110, 11090,
            '2026-09-07 12:00:00', '2026-09-07 12:00:00', '2026-09-07 12:00:00'));
    }

    public function test_overlapping_scans_allow_unrelated_interior_date_reversals(): void
    {
        $context = new RecoveryScanContext(1, 'alt.binaries.fixture', 'epoch', 1, 1, 300, HeaderScanDirection::Head, (string) Str::uuid());
        $batch = new RecoveryCaptureBatch([
            ['Number' => 1, 'Subject' => 'ordinary marker', 'Date' => '2026-09-07 09:00:00 +0000'],
            ['Number' => 100, 'Subject' => 'ordinary marker', 'Date' => '2026-09-07 12:00:00 +0000'],
            ['Number' => 300, 'Subject' => 'ordinary marker', 'Date' => '2026-09-07 15:00:00 +0000'],
        ], []);
        $capture = new RecoveryCapture(RecoveryConfig::fromValues(['obfuscation_recovery_enabled' => 1]), new NeverBlacklistedService);
        $this->assertTrue($capture->capture($batch, $context)->coverageComplete);
        $context = new RecoveryScanContext(1, 'alt.binaries.fixture', 'epoch', 1, 150, 200, HeaderScanDirection::Head, (string) Str::uuid());
        $batch = new RecoveryCaptureBatch([
            ['Number' => 150, 'Subject' => 'ordinary marker', 'Date' => '2026-09-07 11:00:00 +0000'],
            ['Number' => 200, 'Subject' => 'ordinary marker', 'Date' => '2026-09-07 14:00:00 +0000'],
        ], []);
        $this->assertTrue($capture->capture($batch, $context)->coverageComplete);
        $this->assertSame('ready', (new RecoverySettlement)->assess('epoch', 1, 1, 100, 100,
            '2026-09-07 12:00:00', '2026-09-07 12:00:00', '2026-09-07 12:00:00'));
    }

    private function recordBadDates(int $first): void
    {
        $scan = DB::table('obfuscation_recovery_scans')->where('requested_first', $first)->first();
        DB::table('obfuscation_recovery_frontier_conflicts')->insert([
            'identity' => hash('sha256', 'legacy:'.$first), 'scope_digest' => RecoveryPositiveCoverage::scope('epoch', 1, 1),
            'kind' => 'unknown', 'first_article' => $scan->requested_first, 'last_article' => $scan->requested_last,
        ]);
    }

    public function test_complete_reobservation_replaces_only_the_covered_legacy_conflict(): void
    {
        $this->captureRange(1, 100, '2026-09-07 09:50:00');
        $this->captureRange(101, 200, '2026-09-07 12:00:00');
        $this->captureRange(201, 300, '2026-09-07 14:10:00');
        DB::table('obfuscation_recovery_frontier_ranges')->delete();
        DB::table('obfuscation_recovery_scans')->update(['evidence_version' => 1, 'date_order_consistent' => false, 'date_points' => null]);
        DB::table('obfuscation_recovery_frontier_conflicts')->insert([
            'identity' => hash('sha256', 'legacy'), 'scope_digest' => RecoveryPositiveCoverage::scope('epoch', 1, 1),
            'kind' => 'unknown', 'first_article' => 101, 'last_article' => 200,
        ]);
        $this->assertNotSame('ready', $this->assess());
        $this->captureRange(101, 150, '2026-09-07 12:00:00');
        $this->assertNotSame('ready', $this->assess());
        $this->captureRange(151, 200, '2026-09-07 12:00:00');
        $this->assertSame('ready', $this->assess());
        $this->assertSame(0, DB::table('obfuscation_recovery_frontier_conflicts')->where('kind', 'unknown')->count());
    }

    private function assess(string $changed = '2026-09-07 12:00:00'): string
    {
        return (new RecoverySettlement)->assess('epoch', 1, 1, 110, 190,
            '2026-09-07 12:00:00', '2026-09-07 12:00:00', $changed);
    }

    public function test_a_conflicting_witness_uses_an_alternative_and_future_reobservation_cannot_restore_it(): void
    {
        $this->captureRange(1, 100, '2026-09-07 09:50:00');
        $this->captureRange(101, 200, '2026-09-07 12:00:00');
        $this->captureRange(201, 300, '2026-09-07 14:10:00');
        $this->captureRange(1, 1, '2026-09-07 11:00:00');
        $this->assertSame('ready', $this->assess());
        $this->captureRange(100, 100, '2026-09-10 09:50:00');
        $this->assertSame('conflicting_boundary_witness', $this->assess());
    }

    public function test_same_article_contradictions_on_unrelated_interior_headers_do_not_block(): void
    {
        $this->captureRange(1, 100, '2026-09-07 09:50:00');
        $this->captureRange(101, 200, '2026-09-07 12:00:00');
        $this->captureRange(201, 300, '2026-09-07 14:10:00');
        $this->captureRange(111, 111, '2026-09-07 12:00:00');
        $this->captureRange(111, 111, '2026-09-07 11:00:00');
        $this->assertSame('ready', $this->assess());
    }

    private function captureRange(int $first, int $last, string $date, HeaderScanDirection $direction = HeaderScanDirection::Head): void
    {
        $context = new RecoveryScanContext(1, 'alt.binaries.fixture', 'epoch', 1, $first, $last, $direction, (string) Str::uuid());
        $batch = new RecoveryCaptureBatch([['Number' => $first, 'Subject' => 'ordinary marker', 'Date' => $date.' +0000'],
            ['Number' => $last, 'Subject' => 'ordinary marker', 'Date' => $date.' +0000']], []);
        $report = (new RecoveryCapture(RecoveryConfig::fromValues(['obfuscation_recovery_enabled' => 1]), new NeverBlacklistedService))->capture($batch, $context);
        $this->assertTrue($report->coverageComplete);
    }
}
