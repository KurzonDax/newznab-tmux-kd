<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryAlgorithm;
use App\Services\ObfuscationRecovery\RecoveryDirty;
use App\Services\ObfuscationRecovery\RecoveryRunDiscovery;
use App\Services\ObfuscationRecovery\RecoveryRunRefresh;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class RecoveryRunDiscoveryTest extends TestCase
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
        (require database_path('migrations/2026_09_14_110835_add_recovery_handoff_and_process_identity.php'))->up();
        (require database_path('migrations/2026_09_18_120000_bucket_obfuscation_recovery_dirty_marks.php'))->up();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_a_middle_change_rebuilds_the_whole_run_but_keeps_the_next_run_separate(): void
    {
        foreach ([1000, 2000, 2000, 5000, 8001, 9000] as $i => $time) {
            $this->header($i + 1, $time, 4);
        }
        $dirty = (object) ['source_epoch' => 'epoch', 'groups_id' => 1, 'capture_generation' => 1,
            'profile' => RecoveryAlgorithm::Media->value, 'partition_value' => '4', 'first_ms' => 2000, 'last_ms' => 9000];
        $run = (new RecoveryRunDiscovery)->scan($dirty);
        $this->assertSame(1000, $run['start_ms']);
        $this->assertSame(5000, $run['end_ms']);
        $this->assertSame(4, $run['observed_count']);
        $this->assertSame('count_exact', $run['state']);
        $dirty->first_ms = 5001;
        $next = (new RecoveryRunDiscovery)->scan($dirty);
        $this->assertSame(8001, $next['start_ms']);
        $this->assertSame(2, $next['observed_count']);
        $this->assertSame('short', $next['state']);
    }

    public function test_total_and_generation_partitioning_do_not_admit_neighboring_headers(): void
    {
        $this->header(1, 1000, 4);
        $this->header(2, 2000, 9);
        $this->header(3, 3000, 4, 2);
        $dirty = (object) ['source_epoch' => 'epoch', 'groups_id' => 1, 'capture_generation' => 1,
            'profile' => RecoveryAlgorithm::Media->value, 'partition_value' => '4', 'first_ms' => 1000, 'last_ms' => 3000];
        $run = (new RecoveryRunDiscovery)->scan($dirty);
        $this->assertSame(1, $run['observed_count']);
        $this->assertSame(1000, $run['end_ms']);
        $dirty->first_ms = 2000;
        $this->assertNull((new RecoveryRunDiscovery)->scan($dirty));
    }

    public function test_dirty_refresh_persists_one_complete_run_at_a_time_and_rejects_a_racing_capture(): void
    {
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        DB::table('usenet_groups')->insert(['id' => 1, 'obfuscation_recovery_profile' => 'media']);
        DB::table('obfuscation_recovery_controls')->insert([
            ['scope' => 'primary', 'fingerprint' => str_repeat('a', 64), 'epoch' => 'epoch', 'generation' => 1, 'updated_at' => now()],
            ['scope' => 'group:1', 'fingerprint' => str_repeat('b', 64), 'epoch' => 'group', 'generation' => 1, 'updated_at' => now()],
        ]);
        foreach ([1000, 2000, 2000, 5000, 8001, 9000] as $i => $time) {
            $this->header($i + 1, $time, 4);
        }
        $rows = DB::table('obfuscation_recovery_headers')->get()->map(static fn (object $row): array => (array) $row)->all();
        RecoveryDirty::mark(DB::connection(), $rows);
        $refresh = new RecoveryRunRefresh(new RecoveryRunDiscovery);
        $claim = $refresh->claim();
        $run = (new RecoveryRunDiscovery)->scan($claim);
        RecoveryDirty::mark(DB::connection(), [$rows[0]]);
        $this->assertFalse($refresh->finish($claim, $run));
        $this->assertSame(0, DB::table('obfuscation_recovery_runs')->count());
        $this->assertNotNull($refresh->step());
        $this->assertSame(5001, (int) DB::table('obfuscation_recovery_dirty')->value('first_ms'));
        $this->assertNotNull($refresh->step());
        $this->assertSame(0, DB::table('obfuscation_recovery_dirty')->count());
        $this->assertSame(['count_exact', 'short'], DB::table('obfuscation_recovery_runs')->orderBy('start_ms')->pluck('state')->all());
        $this->assertNull($refresh->step());
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
    }

    public function test_dirty_marks_keep_distant_minutes_separate_and_widen_only_the_same_minute(): void
    {
        $this->header(1, 1000, 4);
        $this->header(2, 7201000, 4);
        $rows = DB::table('obfuscation_recovery_headers')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        RecoveryDirty::mark(DB::connection(), [$rows[0]]);
        RecoveryDirty::mark(DB::connection(), [$rows[1]]);
        $this->assertSame(2, DB::table('obfuscation_recovery_dirty')->count());
        $rows[0]['embedded_timestamp_ms'] = 59000;
        RecoveryDirty::mark(DB::connection(), [$rows[0]]);
        $cells = DB::table('obfuscation_recovery_dirty')->orderBy('first_ms')->get();
        $this->assertCount(2, $cells);
        $this->assertSame(0, (int) $cells[0]->bucket);
        $this->assertSame(1000, (int) $cells[0]->first_ms);
        $this->assertSame(59000, (int) $cells[0]->last_ms);
        $this->assertSame(2, (int) $cells[0]->version);
        $this->assertSame(120, (int) $cells[1]->bucket);
        $this->assertSame(7201000, (int) $cells[1]->first_ms);
        $this->assertSame(7201000, (int) $cells[1]->last_ms);
        $this->assertSame(1, (int) $cells[1]->version);
    }

    public function test_refresh_batch_honors_the_limit_and_deadline(): void
    {
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        DB::table('usenet_groups')->insert(['id' => 1, 'obfuscation_recovery_profile' => 'media']);
        DB::table('obfuscation_recovery_controls')->insert([
            ['scope' => 'primary', 'fingerprint' => str_repeat('a', 64), 'epoch' => 'epoch', 'generation' => 1, 'updated_at' => now()],
            ['scope' => 'group:1', 'fingerprint' => str_repeat('b', 64), 'epoch' => 'group', 'generation' => 1, 'updated_at' => now()],
        ]);
        foreach ([1000, 61000, 121000] as $i => $time) {
            $this->header($i + 1, $time, 1);
        }
        RecoveryDirty::mark(DB::connection(), DB::table('obfuscation_recovery_headers')->get()->map(static fn (object $row): array => (array) $row)->all());
        $refresh = new RecoveryRunRefresh(new RecoveryRunDiscovery);
        $this->assertSame(0, $refresh->batch(deadline: hrtime(true) - 1));
        $this->assertSame(3, DB::table('obfuscation_recovery_dirty')->count());
        $this->assertSame(2, $refresh->batch(2));
        $this->assertSame(1, DB::table('obfuscation_recovery_dirty')->count());
        $this->assertSame(2, DB::table('obfuscation_recovery_runs')->count());
        $this->assertSame(1, $refresh->batch());
        $this->assertSame(0, $refresh->batch());
        $this->assertNull($refresh->step());
    }

    public function test_dirty_migration_rebuilds_only_occupied_scope_minutes_and_collapses_on_rollback(): void
    {
        $migration = require database_path('migrations/2026_09_18_120000_bucket_obfuscation_recovery_dirty_marks.php');
        $migration->down();
        foreach ([1000, 59000, 3601000, 1801000, 7201000] as $i => $time) {
            $this->header($i + 1, $time, $i === 3 ? 9 : 4);
        }
        $changed = now()->subHour()->format('Y-m-d H:i:s.u');
        DB::table('obfuscation_recovery_dirty')->insert([
            'scope_digest' => str_repeat('c', 64), 'source_epoch' => 'epoch', 'groups_id' => 1,
            'capture_generation' => 1, 'profile' => RecoveryAlgorithm::Media->value, 'partition_value' => '4',
            'first_ms' => 1000, 'last_ms' => 3601000, 'version' => 9, 'membership_changed_at' => $changed,
            'next_action_at' => now()->addHour(), 'claim_token' => 'old-claim', 'claim_expires_at' => now()->addHour(),
        ]);
        $migration->up();
        $cells = DB::table('obfuscation_recovery_dirty')->orderBy('bucket')->get();
        $this->assertSame([0, 60], $cells->pluck('bucket')->all());
        $this->assertSame([1000, 3601000], $cells->pluck('first_ms')->all());
        $this->assertSame([59000, 3601000], $cells->pluck('last_ms')->all());
        foreach ($cells as $cell) {
            $this->assertSame(1, (int) $cell->version);
            $this->assertSame($changed, $cell->membership_changed_at);
            $this->assertNull($cell->claim_token);
            $this->assertNull($cell->claim_expires_at);
            $this->assertLessThanOrEqual(now(), new \DateTimeImmutable($cell->next_action_at));
        }
        DB::table('obfuscation_recovery_dirty')->where('bucket', 60)->update([
            'version' => 3, 'membership_changed_at' => now(), 'next_action_at' => now()->subMinute(),
        ]);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('obfuscation_recovery_dirty', 'bucket'));
        $this->assertSame(1, DB::table('obfuscation_recovery_dirty')->count());
        $row = DB::table('obfuscation_recovery_dirty')->first();
        $this->assertSame(1000, (int) $row->first_ms);
        $this->assertSame(3601000, (int) $row->last_ms);
        $this->assertSame(3, (int) $row->version);
        $this->assertNull($row->claim_token);
        $this->assertNull($row->claim_expires_at);
        $migration->up();
    }

    private function header(int $id, int $timestamp, int $total, int $generation = 1): void
    {
        DB::table('obfuscation_recovery_headers')->insert([
            'id' => $id, 'source_epoch' => 'epoch', 'groups_id' => 1, 'capture_generation' => $generation,
            'message_id' => sprintf('m%06d@local', $id), 'source_message_id' => '<m'.$id.'@local>', 'message_id_digest' => hash('sha256', (string) $id),
            'article_number' => $id, 'raw_subject' => 'opaque', 'poster_identity' => 'fixture', 'source_date' => '2026-01-01T00:00:00Z',
            'postdate' => '2026-01-01 00:00:00', 'advertised_bytes' => 100, 'advertised_total' => $total,
            'embedded_timestamp_ms' => $timestamp, 'profile' => RecoveryAlgorithm::Media->value, 'key_digest' => str_repeat('a', 64),
            'first_observed_at' => now(), 'last_observed_at' => now(),
        ]);
    }
}
