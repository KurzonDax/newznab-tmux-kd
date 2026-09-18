<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Models\Settings;
use App\Services\ObfuscationRecovery\RecoveryAlgorithm;
use App\Services\ObfuscationRecovery\RecoveryCoverage;
use App\Services\ObfuscationRecovery\RecoveryGapPlanner;
use App\Services\ObfuscationRecovery\RecoveryPositiveCoverage;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class RecoveryGapPlannerTest extends TestCase
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
        (require database_path('migrations/2026_09_13_155226_add_recovery_frontier_repair_allowances.php'))->up();
        (require database_path('migrations/2026_09_13_190549_add_recovery_frontier_request_attribution.php'))->up();
        $this->travelTo(now()->setDate(2026, 9, 16)->setTime(18, 0));
        Settings::settingsUpsert(['obfuscation_recovery_enabled' => '1']);
        DB::table('usenet_groups')->insert(['id' => 1, 'obfuscation_recovery_profile' => 'both']);
        foreach (['primary', 'group:1'] as $scope) {
            DB::table('obfuscation_recovery_controls')->insert([
                'scope' => $scope, 'fingerprint' => str_repeat('a', 64), 'epoch' => 'epoch', 'generation' => 1, 'updated_at' => now(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_coverage_keeps_overlapping_older_chunks_after_a_buffer_reduction(): void
    {
        Settings::settingsUpsert(['maxmssgs' => '10000']);
        $scope = (object) ['groups_id' => 1, 'source_epoch' => 'epoch', 'capture_generation' => 1];
        $this->scan(71000, 100999);
        $this->scan(102000, 102999);
        $planner = new RecoveryGapPlanner;

        $positive = $planner->positive($scope, 100000, 104999);
        $this->assertSame([[100000, 100999], [102000, 102999]], $positive);
        $this->assertSame([[101000, 101999], [103000, 104999]], RecoveryCoverage::holes(100000, 104999, $positive));

        $this->scan(30000, 59999);
        $this->assertSame($positive, $planner->positive($scope, 100000, 104999));
    }

    public function test_needed_witness_window_precedes_an_overdue_unneeded_window(): void
    {
        $this->window(1, 100, 120);
        $this->window(301, 400, 5);
        $this->candidate(150, 200);
        $this->witness(101, '2026-09-16 09:00:00');
        $this->witness(400, '2026-09-16 15:00:00');

        $this->assertSame(1, (new RecoveryGapPlanner)->step(1));
        $this->assertSame(301, (int) DB::table('obfuscation_recovery_gaps')->sole()->requested_first);
    }

    public function test_batch_rotates_candidates_and_respects_its_limit(): void
    {
        $this->window(1, 100, 20);
        $this->window(101, 200, 15);
        $this->window(201, 300, 10);
        $this->window(301, 400, 5);
        $this->candidate(210, 290, ageMinutes: 0);
        $this->candidate(10, 90, ageMinutes: 60);
        $this->candidate(110, 190, ageMinutes: 30);
        $this->candidate(310, 390, ageMinutes: 90);

        $this->assertSame(3, (new RecoveryGapPlanner)->step(3, hrtime(true) + 5000000000));
        $this->assertSame([301, 101, 1], DB::table('obfuscation_recovery_gaps')->orderBy('id')->pluck('requested_first')->all());
        $this->assertSame(3, DB::table('obfuscation_recovery_work')->where('purpose', 'gap')->where('status', 'pending')->count());
    }

    public function test_older_candidate_hole_is_planned_despite_newer_windows_becoming_due_again(): void
    {
        $this->window(1, 100);
        $this->candidate(10, 90, ageMinutes: 60);
        foreach ([101, 201, 301] as $first) {
            $this->window($first, $first + 99, 10);
            $this->scan($first, $first + 99);
            $this->candidate($first + 10, $first + 90);
        }

        for ($cycle = 0; $cycle < 2; $cycle++) {
            (new RecoveryGapPlanner)->step(2);
            $this->travel(5)->minutes();
        }

        $this->assertSame(1, (int) DB::table('obfuscation_recovery_gaps')->sole()->requested_first);
    }

    public function test_rotation_resumes_a_large_candidate_after_serving_older_candidates(): void
    {
        $this->window(1, 100);
        $this->candidate(10, 90, ageMinutes: 60);
        $this->window(101, 200, 15);
        $this->window(201, 300, 10);
        $this->window(301, 400, 5);
        $this->candidate(110, 390);

        $this->assertSame(2, (new RecoveryGapPlanner)->step(2));
        $this->assertSame([101, 201], DB::table('obfuscation_recovery_gaps')->orderBy('id')->pluck('requested_first')->all());
        $this->window(401, 500);
        $this->candidate(410, 490);
        $this->assertSame(1, (new RecoveryGapPlanner)->step(1));
        $this->assertSame(1, (int) DB::table('obfuscation_recovery_gaps')->orderByDesc('id')->value('requested_first'));
        $this->assertSame(2, (new RecoveryGapPlanner)->step(2));
        $this->assertSame([101, 201, 1, 401, 301], DB::table('obfuscation_recovery_gaps')->orderBy('id')->pluck('requested_first')->all());
    }

    public function test_candidate_rotation_survives_a_deadline_during_selection(): void
    {
        $this->window(1, 100);
        $this->candidate(10, 90, ageMinutes: 60);
        $this->window(101, 200);
        $this->candidate(110, 190);
        $deadline = hrtime(true) + 1000000000;
        DB::listen(static function (QueryExecuted $query) use ($deadline): void {
            if (str_starts_with($query->sql, 'select * from "obfuscation_recovery_scan_windows"')) {
                $remaining = $deadline - hrtime(true);
                if ($remaining > 0) {
                    usleep((int) ceil($remaining / 1000));
                }
            }
        });

        $this->assertSame(0, (new RecoveryGapPlanner)->step(1, $deadline));
        $this->assertSame(1, (new RecoveryGapPlanner)->step(1));
        $this->assertSame(1, (int) DB::table('obfuscation_recovery_gaps')->sole()->requested_first);
    }

    public function test_completed_needed_windows_go_quiet_except_for_the_advancing_frontier(): void
    {
        Schema::table('usenet_groups', function (Blueprint $table): void {
            $table->unsignedBigInteger('last_record')->default(300);
        });
        $completed = $this->window(1, 100);
        $pastFrontier = $this->window(101, 200);
        $newest = $this->window(201, 300);
        DB::table('obfuscation_recovery_scan_windows')->where('scan_id', $pastFrontier)->update(['gap_cursor' => 201]);
        $this->scan(1, 300);
        $this->candidate(10, 290);

        $this->assertSame(0, (new RecoveryGapPlanner)->step());
        foreach ([$completed, $pastFrontier] as $window) {
            $this->assertSame(now()->addHour()->toDateTimeString(), DB::table('obfuscation_recovery_scan_windows')->where('scan_id', $window)->value('next_gap_at'));
        }
        $this->assertSame(now()->addMinutes(5)->toDateTimeString(), DB::table('obfuscation_recovery_scan_windows')->where('scan_id', $newest)->value('next_gap_at'));

        DB::table('usenet_groups')->where('id', 1)->update(['last_record' => 350]);
        $this->travel(5)->minutes();
        $this->assertSame(1, (new RecoveryGapPlanner)->step());
        $gap = DB::table('obfuscation_recovery_gaps')->sole();
        $this->assertSame([301, 350], [(int) $gap->requested_first, (int) $gap->requested_last]);
    }

    public function test_unneeded_windows_use_a_slower_cadence_even_with_more_holes_to_plan(): void
    {
        $unneeded = $this->window(1, 30000, 120);
        $needed = $this->window(30001, 30100);
        $this->candidate(30010, 30090);

        $this->assertSame(2, (new RecoveryGapPlanner)->step());
        $this->assertSame(now()->addHour()->toDateTimeString(), DB::table('obfuscation_recovery_scan_windows')->where('scan_id', $unneeded)->value('next_gap_at'));
        $this->assertSame(now()->addHour()->toDateTimeString(), DB::table('obfuscation_recovery_scan_windows')->where('scan_id', $needed)->value('next_gap_at'));
        $this->assertSame(20001, (int) DB::table('obfuscation_recovery_scan_windows')->where('scan_id', $unneeded)->value('gap_cursor'));
    }

    public function test_deadline_stops_a_batch_after_the_current_window(): void
    {
        $first = $this->window(1, 100, 10);
        $second = $this->window(101, 200, 5);
        $this->candidate(10, 190);
        $deadline = hrtime(true) + 1000000000;
        DB::listen(static function (QueryExecuted $query) use ($deadline): void {
            if (str_starts_with($query->sql, 'insert or ignore into "obfuscation_recovery_gaps"')) {
                $remaining = $deadline - hrtime(true);
                if ($remaining > 0) {
                    usleep((int) ceil($remaining / 1000));
                }
            }
        });

        $this->assertSame(1, (new RecoveryGapPlanner)->step(50, $deadline));
        $this->assertSame(1, DB::table('obfuscation_recovery_gaps')->count());
        $this->assertSame(now()->addHour()->toDateTimeString(), DB::table('obfuscation_recovery_scan_windows')->where('scan_id', $first)->value('next_gap_at'));
        $this->assertSame(now()->subMinutes(5)->toDateTimeString(), DB::table('obfuscation_recovery_scan_windows')->where('scan_id', $second)->value('next_gap_at'));
    }

    public function test_expired_deadline_does_not_process_any_window(): void
    {
        $this->window(1, 100);
        $this->candidate(10, 90);

        $this->assertSame(0, (new RecoveryGapPlanner)->step(deadline: hrtime(true) - 1));
        $this->assertSame(0, DB::table('obfuscation_recovery_work')->count());
    }

    public function test_inactive_and_other_scope_candidates_do_not_prioritize_windows(): void
    {
        $inactive = $this->window(1, 100, 120);
        $otherScope = $this->window(101, 200, 100);
        $needed = $this->window(201, 300, 5);
        $this->candidate(210, 290, ageMinutes: 60);
        $this->candidate(10, 90, state: 'expired_unresolved');
        $candidate = $this->candidate(110, 190);
        DB::table('obfuscation_recovery_bundles')->where('id', $candidate)->update(['capture_generation' => 2]);

        $this->assertSame(3, (new RecoveryGapPlanner)->step());
        $this->assertSame([201, 1, 101], DB::table('obfuscation_recovery_gaps')->orderBy('id')->pluck('requested_first')->all());
        foreach ([$inactive, $otherScope] as $window) {
            $this->assertSame(now()->addHour()->toDateTimeString(), DB::table('obfuscation_recovery_scan_windows')->where('scan_id', $window)->value('next_gap_at'));
        }
        $this->assertSame(now()->addHour()->toDateTimeString(), DB::table('obfuscation_recovery_scan_windows')->where('scan_id', $needed)->value('next_gap_at'));
    }

    public function test_shared_needed_windows_are_processed_once_and_keep_the_fast_continuation(): void
    {
        $window = $this->window(1, 50000);
        $this->candidate(10, 90);
        $this->candidate(110, 190);

        $this->assertSame(1, (new RecoveryGapPlanner)->step());
        $this->assertSame(1, DB::table('obfuscation_recovery_work')->count());
        $this->assertSame(20001, (int) DB::table('obfuscation_recovery_scan_windows')->where('scan_id', $window)->value('gap_cursor'));
        $this->assertSame(now()->addSecond()->toDateTimeString(), DB::table('obfuscation_recovery_scan_windows')->where('scan_id', $window)->value('next_gap_at'));
    }

    private function window(int $first, int $last, int $overdueMinutes = 5): string
    {
        $id = (string) Str::uuid();
        DB::table('obfuscation_recovery_scan_windows')->insert([
            'scan_id' => $id, 'groups_id' => 1, 'source_epoch' => 'epoch', 'capture_generation' => 1,
            'requested_first' => $first, 'requested_last' => $last, 'gap_cursor' => $first,
            'next_gap_at' => now()->subMinutes($overdueMinutes), 'expires_at' => now()->addDay(), 'created_at' => now(),
        ]);

        return $id;
    }

    private function candidate(int $first, int $last, string $state = 'collecting', int $ageMinutes = 0): int
    {
        $run = DB::table('obfuscation_recovery_runs')->insertGetId([
            'run_identity' => hash('sha256', 'run-'.$first), 'scope_digest' => RecoveryPositiveCoverage::scope('epoch', 1, 1),
            'source_epoch' => 'epoch', 'groups_id' => 1, 'capture_generation' => 1, 'profile' => RecoveryAlgorithm::Media->value,
            'partition_value' => 'epoch', 'start_ms' => 1, 'end_ms' => 2, 'observed_count' => 2,
            'state' => 'collecting', 'membership_digest' => hash('sha256', 'members-'.$first),
            'summary' => json_encode(['first_article' => $first, 'last_article' => $last,
                'first_postdate' => '2026-09-16 12:00:00', 'last_postdate' => '2026-09-16 12:00:00'], JSON_THROW_ON_ERROR),
            'oldest_observed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('obfuscation_recovery_bundles')->insertGetId([
            'owner_digest' => hash('sha256', 'posting-'.$first), 'kind' => 'posting', 'groups_id' => 1,
            'profile' => RecoveryAlgorithm::Media->value, 'source_epoch' => 'epoch', 'capture_generation' => 1,
            'state' => $state, 'membership_changed_at' => now()->subHours(3), 'candidate_runs' => json_encode([$run], JSON_THROW_ON_ERROR),
            'created_at' => now()->subMinutes($ageMinutes), 'updated_at' => now(),
        ]);
    }

    private function witness(int $article, string $postdate): void
    {
        DB::table('obfuscation_recovery_frontiers')->insert([
            'scope_digest' => RecoveryPositiveCoverage::scope('epoch', 1, 1), 'article_number' => $article,
            'postdate' => $postdate, 'head_observed' => true,
        ]);
    }

    private function scan(int $first, int $last): void
    {
        DB::table('obfuscation_recovery_scans')->insert([
            'scan_id' => (string) Str::uuid(), 'groups_id' => 1, 'source_epoch' => 'epoch',
            'capture_generation' => 1, 'requested_first' => $first, 'requested_last' => $last,
            'chunk_ordinal' => 0, 'expected_chunks' => 1, 'direction' => 'Head',
            'capture_outcome' => 'captured', 'complete' => true, 'created_at' => now(),
        ]);
    }
}
