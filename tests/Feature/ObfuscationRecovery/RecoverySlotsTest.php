<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryBudget;
use App\Services\ObfuscationRecovery\RecoveryConfig;
use App\Services\ObfuscationRecovery\RecoveryProcess;
use App\Services\ObfuscationRecovery\RecoverySlots;
use App\Services\ObfuscationRecovery\RecoveryStage;
use App\Services\ObfuscationRecovery\RecoveryWork;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class RecoverySlotsTest extends TestCase
{
    use IsolatedSqliteDatabase;

    public function test_process_proof_preserves_foreign_namespaces_live_owners_and_legacy_unknowns(): void
    {
        $local = RecoveryProcess::current();
        $this->assertSame('alive', $local->status());
        $this->assertSame('dead', RecoveryProcess::inDomain($local->machine, $local->boot, $local->namespace, $local->pid, '0')->status());
        $this->assertSame('unknown', RecoveryProcess::inDomain(hash('sha256', 'foreign'), hash('sha256', 'older'), $local->namespace, 99999999, '0')->status());
        $this->assertSame('unknown', RecoveryProcess::inDomain($local->machine, $local->boot, hash('sha256', 'other-namespace'), 99999999, '0')->status());
        $this->assertSame('unknown', (new RecoveryProcess(hash('sha256', 'legacy'), 99999999, '0'))->status());
        $legacy = hash('sha256', file_get_contents('/proc/sys/kernel/random/boot_id').'|'.readlink('/proc/self/ns/pid').'|'.gethostname());
        $this->assertSame('alive', (new RecoveryProcess($legacy, $local->pid, $local->started))->status());
        $this->assertSame('dead', (new RecoveryProcess($legacy, 99999999, '0'))->status());
        $this->assertSame('unknown', (new RecoveryProcess($local->host, $local->pid, $local->started, $local->machine))->status());
    }

    public function test_operator_reclamation_is_exact_expired_confirmed_and_settles_before_releasing_work(): void
    {
        $slots = new RecoverySlots;
        $slot = $slots->acquire(RecoveryConfig::fromValues(['obfuscation_recovery_enabled' => 1]));
        $work = app(RecoveryWork::class);
        $work->enqueue(RecoveryStage::Download, 'operator-owner', 1, 'index', []);
        $claim = $work->claim(RecoveryStage::Download);
        $attempt = app(RecoveryBudget::class)->reserve('operator-owner', 'construction', 'unknown@local', 131072, 262144);
        DB::table('obfuscation_recovery_slots')->where('id', $slot->id)->update(['attempt_id' => $attempt->attemptId]);
        $args = ['kind' => 'slot', 'id' => $slot->id, 'token' => $slot->token, '--confirmed-stopped' => true];
        $this->artisan('obfuscation:reclaim', $args)->assertFailed();
        $this->travel(5)->minutes();
        $this->artisan('obfuscation:reclaim', $args)->assertFailed();
        foreach (['obfuscation_recovery_slots' => 'owner_', 'obfuscation_recovery_work' => 'claim_owner_'] as $table => $prefix) {
            DB::table($table)->where('id', $table === 'obfuscation_recovery_slots' ? $slot->id : $claim->id)->update([
                $prefix.'host' => hash('sha256', 'legacy-unknown'), $prefix.'machine' => null, $prefix.'boot' => null, $prefix.'namespace' => null,
            ]);
        }
        $this->assertSame(0, $slots->reap());
        $this->assertSame(0, $work->reclaimExpired());
        $this->assertFalse($work->confirmStopped($claim->id, $claim->token));
        $this->artisan('obfuscation:reclaim', [...$args, '--confirmed-stopped' => false])->assertExitCode(2);
        $this->artisan('obfuscation:reclaim', [...$args, 'token' => (string) Str::uuid()])->assertFailed();
        $this->artisan('obfuscation:reclaim', $args)->expectsOutput('reclaimed')->assertSuccessful();
        $this->assertSame(131072, app(RecoveryBudget::class)->spent('operator-owner', 'construction'));
        $this->assertSame('worker_lost', DB::table('obfuscation_recovery_attempts')->value('outcome'));
        $this->assertTrue($work->confirmStopped($claim->id, $claim->token));
        $this->assertFalse($work->confirmStopped($claim->id, $claim->token));
        $this->assertNotNull($work->claim(RecoveryStage::Download));
    }

    public function test_a_previous_boot_reclaims_the_slot_and_claim_without_erasing_unknown_transfer_spend(): void
    {
        $pool = new RecoverySlots;
        $config = RecoveryConfig::fromValues(['obfuscation_recovery_enabled' => 1, 'obfuscation_recovery_threads' => 1]);
        $work = app(RecoveryWork::class);
        $work->enqueue(RecoveryStage::Download, 'previous-boot', 1, 'index', []);
        $claim = $work->claim(RecoveryStage::Download);
        $slot = $pool->acquire($config);
        $current = RecoveryProcess::current();
        $old = RecoveryProcess::inDomain($current->machine, hash('sha256', 'previous-boot'), $current->namespace, $current->pid, $current->started);
        $budget = app(RecoveryBudget::class);
        $reservation = $budget->reserve('previous-boot', 'construction', 'index@fixture', 131072, 20971520);
        DB::table('obfuscation_recovery_slots')->where('id', $slot->id)->update([
            ...$old->columns('owner_'), 'expires_at' => now()->subDay(), 'attempt_id' => $reservation->attemptId,
        ]);
        DB::table('obfuscation_recovery_work')->where('id', $claim->id)->update([
            ...$old->columns('claim_owner_'), 'claim_expires_at' => now()->subDay(),
        ]);
        $this->assertTrue($old->provenDead());
        $this->assertSame(1, $pool->reap());
        $this->assertSame(1, $work->reclaimExpired());
        $this->assertSame(131072, $budget->spent('previous-boot', 'construction'));
        $this->assertSame('worker_lost', DB::table('obfuscation_recovery_attempts')->value('outcome'));
        $this->assertNotNull($pool->acquire($config));
        $this->assertNotNull($work->claim(RecoveryStage::Download));
        $this->assertSame(0, $pool->reap());
        $this->assertSame(0, $work->reclaimExpired());
    }

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
        $this->travelBack();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_global_slots_include_expired_but_live_workers(): void
    {
        $pool = new RecoverySlots;
        $config = RecoveryConfig::fromValues(['obfuscation_recovery_enabled' => 1, 'obfuscation_recovery_threads' => 2]);
        $one = $pool->acquire($config);
        $this->assertNotNull($one);
        DB::table('obfuscation_recovery_slots')->where('id', 2)->update([
            'worker_token' => (string) Str::uuid(), 'owner_host' => str_repeat('f', 64),
            'owner_pid' => 1, 'owner_started' => '1', 'expires_at' => now()->subMinute(),
        ]);
        $this->assertNull($pool->acquire($config));
        $this->travel(5)->minutes();
        $this->assertSame(0, $pool->reap());
        $this->assertNull($pool->acquire($config));
        $this->assertTrue($pool->release($one));
        $next = $pool->acquire($config);
        $this->assertNotNull($next);
        $this->assertNotSame($one->token, $next->token);
        $this->assertFalse($pool->release($one));
        $this->assertTrue($pool->release($next));
        $this->assertNull($pool->acquire(RecoveryConfig::fromValues(['obfuscation_recovery_enabled' => 1, 'obfuscation_recovery_threads' => 1])));
    }

    public function test_disabled_pool_and_unknown_host_ownership_never_open_slots(): void
    {
        $pool = new RecoverySlots;
        $this->assertNull($pool->acquire(RecoveryConfig::fromValues([])));
        $config = RecoveryConfig::fromValues(['obfuscation_recovery_enabled' => 1, 'obfuscation_recovery_threads' => 1]);
        $lease = $pool->acquire($config);
        DB::table('obfuscation_recovery_slots')->where('id', $lease->id)->update(['owner_host' => str_repeat('f', 64)]);
        $this->travel(5)->minutes();
        $this->assertSame(0, $pool->reap());
        $this->assertNull($pool->acquire($config));
        $this->assertFalse($pool->release($lease));
    }

    public function test_a_slot_is_reclaimed_only_after_the_owning_process_has_exited(): void
    {
        $result = $this->makeTempPath('recovery-worker-result');
        DB::disconnect();
        $pid = pcntl_fork();
        $this->assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            DB::reconnect();
            $lease = (new RecoverySlots)->acquire(RecoveryConfig::fromValues(['obfuscation_recovery_enabled' => 1]));
            file_put_contents($result, (string) $lease?->id);
            exit($lease === null ? 1 : 0);
        }
        pcntl_waitpid($pid, $status);
        DB::reconnect();
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertNotSame('', file_get_contents($result));
        $pool = new RecoverySlots;
        $this->assertSame(0, $pool->reap());
        $this->travel(2)->minutes();
        $this->assertSame(1, $pool->reap());
        $this->assertSame(0, DB::table('obfuscation_recovery_slots')->whereNotNull('worker_token')->count());
    }
}
