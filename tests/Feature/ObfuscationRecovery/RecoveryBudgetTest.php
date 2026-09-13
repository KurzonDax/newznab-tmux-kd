<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryAlgorithm;
use App\Services\ObfuscationRecovery\RecoveryBudget;
use App\Services\ObfuscationRecovery\RecoveryBudgetOwners;
use App\Services\ObfuscationRecovery\RecoveryConfig;
use App\Services\ObfuscationRecovery\RecoveryConstructionTargets;
use App\Services\ObfuscationRecovery\RecoveryIdentity;
use App\Services\ObfuscationRecovery\RecoveryLimitResume;
use App\Services\ObfuscationRecovery\RecoverySlots;
use App\Services\ObfuscationRecovery\RecoveryStage;
use App\Services\ObfuscationRecovery\RecoveryTransfer;
use App\Services\ObfuscationRecovery\RecoveryWork;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class RecoveryBudgetTest extends TestCase
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

    public function test_unknown_attempts_keep_their_reservation_across_restarts_and_midnight(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(23)->addMinutes(59));
        $budget = app(RecoveryBudget::class);
        $first = $budget->reserve('candidate-a', 'construction', 'target-a', 128, 200);
        $this->assertNotNull($first);
        $this->travel(2)->minutes();
        $restarted = app(RecoveryBudget::class);
        $this->assertNull($restarted->reserve('candidate-a', 'construction', 'target-b', 128, 200));
        $this->assertNotNull($restarted->reserve('candidate-b', 'construction', 'target-b', 128, 200));
        $this->assertSame(128, $restarted->spent('candidate-a', 'construction'));
    }

    public function test_attempt_settlement_is_idempotent_and_retries_keep_the_same_lifetime_cap(): void
    {
        $budget = app(RecoveryBudget::class);
        $first = $budget->reserve('candidate', 'construction', 'target', 128, 400);
        $this->assertNotNull($first);
        $this->assertNull($budget->reserve('candidate', 'construction', 'target', 128, 400));
        $this->assertTrue($budget->settle($first, 30, 32, 'transport_failure'));
        $this->assertFalse($budget->settle($first, 0, 0, 'success'));
        $this->assertSame(62, $budget->spent('candidate', 'construction'));
        $second = $budget->reserve('candidate', 'construction', 'target', 128, 400);
        $this->assertNotNull($second);
        $this->assertSame(2, $second->physicalAttempt);
        $budget->settle($second, null, 32, 'worker_lost');
        $this->assertSame(190, $budget->spent('candidate', 'construction'));
        $this->assertNull($budget->reserve('candidate', 'construction', 'target', 128, 400));
    }

    public function test_merging_candidate_owners_preserves_all_debits_and_physical_attempts(): void
    {
        $budget = app(RecoveryBudget::class);
        $left = $budget->reserve('left', 'construction', 'shared-target', 128, 400);
        $right = $budget->reserve('right', 'construction', 'shared-target', 128, 400);
        $budget->settle($left, null, 32, 'transport_failure');
        $budget->settle($right, null, 32, 'transport_failure');
        (new RecoveryBudgetOwners(new RecoveryIdentity))->merge(['left', 'right']);
        $this->assertSame(256, $budget->spent('left', 'construction'));
        $this->assertSame(256, $budget->spent('right', 'construction'));
        $this->assertNull($budget->reserve('left', 'construction', 'shared-target', 128, 400));
        $this->assertNotNull($budget->reserve('right', 'construction', 'new-target', 128, 400));
        $this->assertSame(384, $budget->spent('left', 'construction'));
        $this->assertNull($budget->reserve('left', 'construction', 'another-target', 128, 400));
    }

    public function test_a_limit_increase_resumes_permitted_work_without_erasing_spent_bytes(): void
    {
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        DB::table('settings')->where('name', 'obfuscation_recovery_media_candidate_mib')->update(['value' => 1]);
        DB::table('usenet_groups')->insert(['id' => 1, 'obfuscation_recovery_profile' => 'media']);
        $work = app(RecoveryWork::class);
        $id = $work->enqueue(RecoveryStage::Download, 'resume-owner', 1, 'index', ['message_id' => 'index@local']);
        DB::table('obfuscation_recovery_bundles')->update(['groups_id' => 1, 'profile' => RecoveryAlgorithm::Media->value]);
        $claim = $work->claim(RecoveryStage::Download);
        (new RecoveryConstructionTargets)->register($claim, [['kind' => 'index', 'file_id' => null, 'message_id' => 'index@local']]);
        $owner = DB::table('obfuscation_recovery_bundles')->value('owner_digest');
        $budget = app(RecoveryBudget::class);
        $prior = $budget->reserve($owner, 'construction', 'prior@local', 1048576, 1048576);
        $budget->settle($prior, null, 0, 'worker_lost');
        $work->complete($claim, 'construction_limit_reached');
        DB::table('obfuscation_recovery_bundles')->update(['state' => 'construction_limit_reached']);
        $resume = app(RecoveryLimitResume::class);
        $this->assertFalse($resume->step());
        $this->assertNull($work->claim(RecoveryStage::Download));
        $this->travel(1)->days();
        $this->assertFalse($resume->step());
        DB::table('settings')->where('name', 'obfuscation_recovery_media_candidate_mib')->update(['value' => 3]);
        $this->assertTrue($resume->step());
        $this->assertSame(1048576, $budget->spent($owner, 'construction'));
        $this->assertSame(1, DB::table('obfuscation_recovery_attempts')->count());
        $this->assertSame($id, $work->claim(RecoveryStage::Download)->id);
    }

    public function test_only_an_admitted_current_claim_with_an_unused_worker_slot_can_reserve(): void
    {
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        DB::table('usenet_groups')->insert(['id' => 1, 'obfuscation_recovery_profile' => 'both']);
        $work = app(RecoveryWork::class);
        $work->enqueue(RecoveryStage::Download, 'owned-candidate', 1, 'index', []);
        DB::table('obfuscation_recovery_bundles')->update(['groups_id' => 1, 'profile' => RecoveryAlgorithm::Media->value]);
        $claim = $work->claim(RecoveryStage::Download);
        $pool = new RecoverySlots;
        $slot = $pool->acquire(RecoveryConfig::fromSettings());
        $budget = app(RecoveryBudget::class);
        $this->assertNull($budget->reserveConstruction($claim, $slot, 'fixture@local', 131072));
        (new RecoveryConstructionTargets)->register($claim, [
            ['kind' => 'index', 'file_id' => null, 'message_id' => 'index@local'],
            ['kind' => 'anchor', 'file_id' => str_repeat('a', 32), 'message_id' => 'fixture@local'],
        ]);
        $reservation = $budget->reserveConstruction($claim, $slot, 'fixture@local', 131072);
        $this->assertNotNull($reservation);
        $this->assertNull($budget->reserveConstruction($claim, $slot, 'another@local', 131072));
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 0]);
        $transfer = new RecoveryTransfer(null, 'transport_failure', 'provider_read_failed', 1000, 80, null, 1, 65536, 20, true, 100, 512);
        $this->assertTrue($budget->recordTransfer($reservation, $transfer, false, 32768));
        $this->assertFalse($budget->recordTransfer($reservation, $transfer, false, 32768));
        $record = DB::table('obfuscation_recovery_attempts')->first();
        $this->assertSame(33848, (int) $record->debited_bytes);
        $this->assertSame(512, (int) $record->decoded_bytes);
        $this->assertSame(1000, (int) $record->socket_received_bytes);
        $this->assertTrue($pool->release($slot));
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        $slot = $pool->acquire(RecoveryConfig::fromSettings());
        $work->invalidate('owned-candidate', 2);
        $this->assertNull($budget->reserveConstruction($claim, $slot, 'another@local', 131072));
        $this->assertSame(1, DB::table('obfuscation_recovery_attempts')->count());
        $this->assertTrue($pool->release($slot));
    }

    public function test_lowering_worker_limit_after_claiming_stops_new_socket_reservations(): void
    {
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        DB::table('usenet_groups')->insert(['id' => 1, 'obfuscation_recovery_profile' => 'both']);
        $work = app(RecoveryWork::class);
        $work->enqueue(RecoveryStage::Download, 'lowered-limit', 1, 'index', []);
        DB::table('obfuscation_recovery_bundles')->update(['groups_id' => 1, 'profile' => RecoveryAlgorithm::Media->value]);
        $claim = $work->claim(RecoveryStage::Download);
        (new RecoveryConstructionTargets)->register($claim, [['kind' => 'index', 'file_id' => null, 'message_id' => 'index@local']]);
        $pool = new RecoverySlots;
        $slot = $pool->acquire(RecoveryConfig::fromSettings());
        DB::table('obfuscation_recovery_slots')->where('id', 2)->update([
            'worker_token' => 'other', 'owner_host' => 'uncertain-owner', 'owner_pid' => 1, 'owner_started' => 'old', 'expires_at' => now()->addMinute(),
        ]);
        DB::table('settings')->where('name', 'obfuscation_recovery_threads')->update(['value' => 1]);
        $this->assertNull(app(RecoveryBudget::class)->reserveConstruction($claim, $slot, 'index@local', 2097152));
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
        $this->assertTrue($pool->release($slot));
        $this->assertNull($pool->acquire(RecoveryConfig::fromSettings()));
    }

    public function test_closed_socket_without_measurements_retains_its_entire_reservation(): void
    {
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        $pool = new RecoverySlots;
        $slot = $pool->acquire(RecoveryConfig::fromSettings());
        $budget = app(RecoveryBudget::class);
        $attempt = $budget->reserve('closed-owner', 'gap', 'immutable-range', 33554432, 67108864);
        DB::table('obfuscation_recovery_slots')->where('id', $slot->id)->update(['attempt_id' => $attempt->attemptId]);
        $this->assertTrue($pool->release($slot));
        $this->assertSame('closed_without_counter', DB::table('obfuscation_recovery_attempts')->value('outcome'));
        $this->assertSame(33554432, $budget->spent('closed-owner', 'gap'));
        $this->travel(1)->days();
        $retry = $budget->reserve('closed-owner', 'gap', 'immutable-range', 33554432, 67108864);
        $this->assertSame(2, $retry->physicalAttempt);
        $budget->settle($retry, null, 0, 'worker_lost');
        $this->assertSame(67108864, $budget->spent('closed-owner', 'gap'));
        $this->assertNull($budget->reserve('closed-owner', 'gap', 'immutable-range', 33554432, 67108864));
    }

    public function test_reaping_a_dead_socket_owner_preserves_the_full_unknown_attempt_debit(): void
    {
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        $pool = new RecoverySlots;
        $slot = $pool->acquire(RecoveryConfig::fromSettings());
        $budget = app(RecoveryBudget::class);
        $attempt = $budget->reserve('lost-owner', 'construction', 'target@fixture', 131072, 262144);
        DB::table('obfuscation_recovery_slots')->where('id', $slot->id)->update([
            'attempt_id' => $attempt->attemptId, 'expires_at' => now()->subSecond(),
        ]);
        $this->assertSame(0, $pool->reap());
        $this->assertNull(DB::table('obfuscation_recovery_attempts')->where('id', $attempt->attemptId)->value('settled_at'));
        DB::table('obfuscation_recovery_slots')->where('id', $slot->id)->update(['owner_pid' => 99999999]);
        $this->assertSame(1, $pool->reap());
        $this->assertSame('worker_lost', DB::table('obfuscation_recovery_attempts')->where('id', $attempt->attemptId)->value('outcome'));
        $this->assertSame(131072, $budget->spent('lost-owner', 'construction'));
        $this->assertSame(0, DB::table('obfuscation_recovery_slots')->whereNotNull('worker_token')->count());
        $retry = $budget->reserve('lost-owner', 'construction', 'target@fixture', 131072, 262144);
        $this->assertSame(2, $retry->physicalAttempt);
    }

    public function test_prefix_refetch_and_fallback_share_the_two_physical_attempt_limit(): void
    {
        $budget = app(RecoveryBudget::class);
        $prefix = $budget->reserve('shared-owner', 'construction', 'target@fixture', 131072, 20971520);
        $this->assertNotNull($prefix);
        $budget->settle($prefix, null, 32768, 'success');
        $full = $budget->reserve('shared-owner', 'enrichment', 'target@fixture', 2097152, 4194304);
        $this->assertNotNull($full);
        $this->assertSame(2, $full->physicalAttempt);
        $budget->settle($full, null, 65536, 'transport_failure');
        $this->assertNull($budget->reserve('shared-owner', 'enrichment', 'target@fixture', 2097152, 4194304));
        $this->assertSame(131072, $budget->spent('shared-owner', 'construction'));
        $this->assertSame(2097152, $budget->spent('shared-owner', 'enrichment'));
    }

    public function test_unmeasured_tls_use_keeps_the_reservation_and_semantic_failures_are_not_retried(): void
    {
        $budget = app(RecoveryBudget::class);
        $reservation = $budget->reserve('tls-owner', 'construction', 'fixture@local', 131072, 1048576);
        $transfer = new RecoveryTransfer(null, 'semantic_failure', 'response_identity_mismatch', 100, 80, null, 1, 65536, 0, true, 100);
        $this->assertTrue($budget->recordTransfer($reservation, $transfer, true, 32768));
        $this->assertSame(131072, $budget->spent('tls-owner', 'construction'));
        $this->assertNull(DB::table('obfuscation_recovery_attempts')->value('socket_received_bytes'));
        $this->assertNull($budget->reserve('tls-owner', 'construction', 'fixture@local', 131072, 1048576));
    }
}
