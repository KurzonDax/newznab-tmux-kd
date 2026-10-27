<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryBudget;
use App\Services\ObfuscationRecovery\RecoveryBudgetOwners;
use App\Services\ObfuscationRecovery\RecoveryStatus;
use App\Services\Tmux\Tmux;
use App\Services\Tmux\TmuxMonitorService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class RecoveryStatusTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        Schema::create('usenet_groups', fn (Blueprint $table) => $table->increments('id'));
        (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
        (require database_path('migrations/2026_09_13_002751_add_recovery_frontier_evidence.php'))->up();
        (require database_path('migrations/2026_09_13_155226_add_recovery_frontier_repair_allowances.php'))->up();
        (require database_path('migrations/2026_09_13_190549_add_recovery_frontier_request_attribution.php'))->up();
        (require database_path('migrations/2026_09_14_110835_add_recovery_handoff_and_process_identity.php'))->up();
        (require database_path('migrations/2026_09_18_120000_bucket_obfuscation_recovery_dirty_marks.php'))->up();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_status_separates_candidates_from_verified_coverage_and_retains_merged_lifetime_allowances(): void
    {
        $this->freezeSecond();
        DB::table('usenet_groups')->insert(['id' => 1, 'obfuscation_recovery_profile' => 'both']);
        foreach (['first', 'second'] as $owner) {
            DB::table('obfuscation_recovery_bundles')->insert(['owner_digest' => $owner, 'groups_id' => 1,
                'profile' => 'nyuu-media-v1', 'state' => 'collecting', 'created_at' => now(), 'updated_at' => now()]);
            app(RecoveryBudget::class)->reserve($owner, 'construction', $owner, 1048576, 20971520);
        }
        app(RecoveryBudgetOwners::class)->merge(['first', 'second']);
        DB::table('obfuscation_recovery_bundles')->where('owner_digest', 'second')->update(['state' => 'coalesced', 'merged_into' => 1]);
        DB::table('obfuscation_recovery_work')->insert(['bundle_id' => 1, 'revision' => 1, 'stage' => 'discover',
            'purpose' => 'construction', 'dispatch_scope' => str_repeat('a', 64), 'request_digest' => str_repeat('b', 64),
            'payload' => '{}', 'due_at' => now()->subHours(2), 'created_at' => now()->subHours(3), 'updated_at' => now()]);
        $before = DB::table('obfuscation_recovery_work')->first();
        $status = app(RecoveryStatus::class)->details();
        $this->assertSame(10800, $status['oldest_pending_age_seconds']);
        $this->assertNull($status['retention_runway_seconds']);
        $this->assertSame(1, $status['arrivals_last_minute']);
        $this->assertCount(1, $status['allowances']);
        $this->assertSame(2097152, $status['allowances'][0]['construction_debited_bytes']);
        $this->assertSame(18874368, $status['allowances'][0]['construction_remaining_bytes']);
        $this->assertSame(0, (int) $status['group_bundles'][0]->verified_manifests);
        $this->assertSame(1, (int) $status['group_bundles'][0]->bundles);
        $this->assertEquals($before, DB::table('obfuscation_recovery_work')->first());
    }

    public function test_the_display_read_returns_the_seven_rendered_fields_exactly_as_the_summary_computes_them(): void
    {
        $this->freezeSecond();
        $this->seedLiveRecoveryActivity();

        $display = app(RecoveryStatus::class)->display();
        $fields = ['available', 'enabled', 'worker_limit', 'occupied_slots', 'opens_per_second',
            'observed_bytes_per_second', 'accounted_bytes_per_second'];

        $this->assertSame($fields, array_keys($display));
        $this->assertSame(array_intersect_key(app(RecoveryStatus::class)->summary(), array_flip($fields)), $display);
        $this->assertSame(2, $display['occupied_slots']);
        $this->assertEquals(1, $display['opens_per_second'] * 60);
        $this->assertEquals(2048, $display['accounted_bytes_per_second'] * 60);
    }

    public function test_the_operational_refresh_reads_the_display_fields_without_running_the_full_summary(): void
    {
        $this->freezeSecond();
        $this->seedLiveRecoveryActivity();
        $tables = [];
        DB::listen(function (QueryExecuted $query) use (&$tables): void {
            preg_match_all('/obfuscation_recovery_\w+/', $query->sql, $matches);
            array_push($tables, ...$matches[0]);
        });

        $runVar = (new class extends TmuxMonitorService
        {
            public function __construct()
            {
                $this->tmux = new class extends Tmux
                {
                    public function __construct() {}

                    public function getMonitorSettings(): array
                    {
                        return [];
                    }
                };
            }

            /** @return array<string, mixed> */
            public function refresh(): array
            {
                $this->refreshOperationalStatistics();

                return $this->runVar;
            }

            protected function getProcessCounts(): void {}

            protected function refreshOperationalReleaseStatistics(): void {}
        })->refresh();

        $this->assertSame(app(RecoveryStatus::class)->display(), $runVar['recovery']);
        // The table check, the slots and the last minute's attempts: summary() would
        // also count the header table (9.7M rows on prod) and read the catalog.
        $this->assertSame(
            ['obfuscation_recovery_work', 'obfuscation_recovery_slots', 'obfuscation_recovery_attempts'],
            array_values(array_unique($tables)),
        );
    }

    private function seedLiveRecoveryActivity(): void
    {
        DB::table('obfuscation_recovery_slots')->whereIn('id', [1, 2])->update(['worker_token' => DB::raw("'token-' || id")]);
        DB::table('obfuscation_recovery_attempts')->insert(['budget_id' => 1, 'request_digest' => str_repeat('c', 64),
            'physical_attempt' => 1, 'token' => 'attempt-token', 'reserved_bytes' => 4096, 'debited_bytes' => 2048,
            'socket_received_bytes' => 3072, 'connections_opened' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
}
