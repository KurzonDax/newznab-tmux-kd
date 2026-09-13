<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryConfig;
use App\Services\ObfuscationRecovery\RecoverySlots;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class RecoverySlotsTest extends TestCase
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
