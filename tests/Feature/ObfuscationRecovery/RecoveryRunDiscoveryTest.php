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
