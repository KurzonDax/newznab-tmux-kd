<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryBudget;
use App\Services\ObfuscationRecovery\RecoveryCompaction;
use App\Services\ObfuscationRecovery\RecoveryStatus;
use App\Services\ObfuscationRecovery\RecoveryTransfer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class RecoveryCompactionTest extends TestCase
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

    public function test_bounded_compaction_preserves_totals_unknown_counters_and_lifetime_attempt_limits(): void
    {
        $budget = app(RecoveryBudget::class);
        $this->travel(-31)->days();
        foreach ([false, true] as $tls) {
            $reservation = $budget->reserve('owner', 'construction', 'target', 128, 400);
            $budget->recordTransfer($reservation, new RecoveryTransfer(null, 'transport_failure', 'socket_closed', 40, 10, null, 1, 32768, 0, true, 500), $tls, 10);
        }
        $unsettled = $budget->reserve('other', 'enrichment', 'pending', 128, 400);
        $this->travelBack();
        $recent = $budget->reserve('new-owner', 'gap', 'recent', 128, 400);
        $budget->settle($recent, 50, 10, 'success');
        $status = app(RecoveryStatus::class);
        $before = $status->summary()['traffic'];
        $this->assertSame(1, app(RecoveryCompaction::class)->step(1));
        $this->assertSame(1, app(RecoveryCompaction::class)->step(1));
        $this->assertSame(0, app(RecoveryCompaction::class)->step(1));
        $this->assertEquals($before, $status->summary()['traffic']);
        $this->assertSame(188, $budget->spent('owner', 'construction'));
        $this->assertNull($budget->reserve('owner', 'construction', 'target', 128, 400));
        $this->assertSame(4, DB::table('obfuscation_recovery_attempts')->count());
        $this->assertSame(2, (int) DB::table('obfuscation_recovery_traffic')->sum('attempts'));
        $this->assertSame(2, (int) DB::table('obfuscation_recovery_traffic')->sum('connections_opened'));
        $this->assertSame(1, (int) DB::table('obfuscation_recovery_traffic')->sum('unknown_transport_counters'));
        $this->assertNull(DB::table('obfuscation_recovery_attempts')->where('id', $unsettled->attemptId)->value('compacted_at'));
        $this->assertNull(DB::table('obfuscation_recovery_attempts')->where('id', $recent->attemptId)->value('compacted_at'));
    }
}
