<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryStage;
use App\Services\ObfuscationRecovery\RecoveryWork;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class RecoveryWorkTest extends TestCase
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
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_disabled_commands_leave_work_pending_and_status_is_read_only_without_ordinary_cbp(): void
    {
        $work = app(RecoveryWork::class);
        $work->enqueue(RecoveryStage::Discover, 'off-owner', 1, 'prepare', []);
        $this->artisan('obfuscation:discover')->assertSuccessful();
        $this->artisan('obfuscation:publish')->assertSuccessful();
        $this->artisan('obfuscation:download')->assertSuccessful();
        $this->assertSame('pending', DB::table('obfuscation_recovery_work')->value('status'));
        $before = DB::table('obfuscation_recovery_work')->get()->toJson();
        $this->artisan('obfuscation:recovery-status')->assertSuccessful();
        $this->assertSame($before, DB::table('obfuscation_recovery_work')->get()->toJson());
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
        $this->assertSame(0, DB::table('obfuscation_recovery_slots')->whereNotNull('worker_token')->count());
        $this->assertFalse(Schema::hasTable('collections'));
    }

    public function test_duplicate_scheduling_and_competing_claims_do_not_duplicate_work(): void
    {
        $work = app(RecoveryWork::class);
        $id = $work->enqueue(RecoveryStage::Discover, 'bundle-a', 1, 'discover', []);
        $this->assertSame($id, $work->enqueue(RecoveryStage::Discover, 'bundle-a', 1, 'discover', []));
        $bundleId = (int) DB::table('obfuscation_recovery_work')->where('id', $id)->value('bundle_id');
        $this->assertSame($id, $work->enqueueForBundle(RecoveryStage::Discover, $bundleId, 1, 'discover', []));
        $claim = $work->claim(RecoveryStage::Discover);
        $this->assertNotNull($claim);
        $this->assertNull($work->claim(RecoveryStage::Discover));
        $this->assertTrue($work->heartbeat($claim));
        $this->assertTrue($work->heartbeat($claim));
        $this->assertTrue($work->complete($claim));
        $this->assertFalse($work->complete($claim));
        $this->assertNull($work->claim(RecoveryStage::Discover));
    }

    public function test_expiry_stops_heartbeats_completion_and_new_claims(): void
    {
        $work = app(RecoveryWork::class);
        $work->enqueue(RecoveryStage::Discover, 'expiring', 1, 'discover', []);
        $claim = $work->claim(RecoveryStage::Discover);
        $this->assertNotNull($claim);
        DB::table('obfuscation_recovery_bundles')->where('id', $claim->bundleId)->update(['state' => 'expiry_pending']);
        $this->assertFalse($work->heartbeat($claim));
        $this->assertFalse($work->complete($claim));
        try {
            $work->enqueue(RecoveryStage::Discover, 'expiring', 1, 'other', []);
            $this->fail('Expired work was requeued.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('inactive_work_owner', $e->getMessage());
        }
        DB::table('obfuscation_recovery_work')->where('id', $claim->id)->update(['status' => 'pending']);
        $this->assertNull($work->claim(RecoveryStage::Discover));
    }

    public function test_source_epoch_and_capture_generation_changes_fence_owned_work(): void
    {
        $work = app(RecoveryWork::class);
        $work->enqueue(RecoveryStage::Discover, 'controlled', 1, 'discover', []);
        DB::table('obfuscation_recovery_bundles')->update(['groups_id' => 1, 'source_epoch' => 'epoch', 'capture_generation' => 1]);
        DB::table('obfuscation_recovery_controls')->insert([
            ['scope' => 'primary', 'fingerprint' => str_repeat('a', 64), 'epoch' => 'epoch', 'generation' => 1, 'updated_at' => now()],
            ['scope' => 'group:1', 'fingerprint' => str_repeat('b', 64), 'epoch' => 'group', 'generation' => 1, 'updated_at' => now()],
        ]);
        $claim = $work->claim(RecoveryStage::Discover);
        $this->assertTrue($work->heartbeat($claim));
        DB::table('obfuscation_recovery_controls')->where('scope', 'group:1')->update(['generation' => 2]);
        $this->assertFalse($work->heartbeat($claim));
        $this->assertFalse($work->complete($claim));
        DB::table('obfuscation_recovery_controls')->where('scope', 'group:1')->update(['generation' => 1]);
        DB::table('obfuscation_recovery_controls')->where('scope', 'primary')->update(['epoch' => 'other']);
        $this->assertFalse($work->heartbeat($claim));
        $this->assertFalse($work->complete($claim));
    }

    public function test_expired_claims_require_proven_worker_death_and_fence_the_old_token(): void
    {
        $work = app(RecoveryWork::class);
        $work->enqueue(RecoveryStage::Discover, 'crashed-worker', 1, 'discover', []);
        $old = $work->claim(RecoveryStage::Discover);
        DB::table('obfuscation_recovery_work')->where('id', $old->id)->update(['claim_expires_at' => now()->subSecond()]);
        $this->assertSame(0, $work->reclaimExpired());
        $this->assertNull($work->claim(RecoveryStage::Discover));
        DB::table('obfuscation_recovery_work')->where('id', $old->id)->update(['claim_owner_pid' => 99999999]);
        $this->travel(61)->seconds();
        $this->assertSame(1, $work->reclaimExpired());
        $next = $work->claim(RecoveryStage::Discover);
        $this->assertNotNull($next);
        $this->assertSame($old->id, $next->id);
        $this->assertNotSame($old->token, $next->token);
        $this->assertFalse($work->complete($old));
        $this->assertTrue($work->complete($next));
    }

    public function test_obsolete_pending_capture_work_is_retired_before_current_work_is_claimed(): void
    {
        $work = app(RecoveryWork::class);
        $stale = $work->enqueue(RecoveryStage::Download, 'old-capture', 1, 'index', []);
        DB::table('obfuscation_recovery_bundles')->update(['groups_id' => 1, 'source_epoch' => 'old', 'capture_generation' => 1]);
        DB::table('obfuscation_recovery_controls')->insert([
            ['scope' => 'primary', 'fingerprint' => str_repeat('a', 64), 'epoch' => 'new', 'generation' => 1, 'updated_at' => now()],
            ['scope' => 'group:1', 'fingerprint' => str_repeat('b', 64), 'epoch' => 'group', 'generation' => 2, 'updated_at' => now()],
        ]);
        $current = $work->enqueue(RecoveryStage::Download, 'current-capture', 1, 'index', []);
        $this->assertSame($current, $work->claim(RecoveryStage::Download)?->id);
        $this->assertSame('obsolete', DB::table('obfuscation_recovery_work')->where('id', $stale)->value('status'));
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
    }

    public function test_unknown_hosts_cannot_starve_reclamation_of_later_proven_dead_workers(): void
    {
        $work = app(RecoveryWork::class);
        $claims = [];
        for ($i = 0; $i < 11; $i++) {
            $work->enqueue(RecoveryStage::Discover, 'lost-'.$i, 1, 'discover', []);
            $claims[] = $work->claim(RecoveryStage::Discover);
        }
        DB::table('obfuscation_recovery_work')->update(['claim_expires_at' => now()->subSecond(), 'claim_owner_pid' => 99999999]);
        DB::table('obfuscation_recovery_work')->whereIn('id', array_map(fn ($claim) => $claim->id, array_slice($claims, 0, 10)))
            ->update(['claim_owner_host' => str_repeat('f', 64)]);
        $this->assertSame(0, $work->reclaimExpired());
        $this->assertSame(1, $work->reclaimExpired());
        $this->assertSame($claims[10]->id, $work->claim(RecoveryStage::Discover)?->id);
        $this->assertSame(10, DB::table('obfuscation_recovery_work')->where('claim_owner_host', str_repeat('f', 64))->where('status', 'claimed')->count());
    }

    public function test_download_completion_wakes_the_existing_local_revision_atomically(): void
    {
        $work = app(RecoveryWork::class);
        $localId = $work->enqueue(RecoveryStage::Discover, 'waiting-owner', 1, 'discover', []);
        $local = $work->claim(RecoveryStage::Discover);
        $work->defer($local, 60);
        $work->enqueueForBundle(RecoveryStage::Download, $local->bundleId, 1, 'index', ['message_id' => 'target@fixture']);
        $this->assertNull($work->claim(RecoveryStage::Discover));
        $this->assertTrue($work->complete($work->claim(RecoveryStage::Download), 'downloaded'));
        $this->assertSame($localId, $work->claim(RecoveryStage::Discover)->id);
    }

    public function test_dispatch_rotates_groups_profiles_and_request_classes(): void
    {
        $work = app(RecoveryWork::class);
        foreach ([[1, 'media', 'index', 8], [1, 'media', 'enrichment', 1], [1, 'rar', 'anchor', 1], [2, 'media', 'index', 1]] as $scope => [$group, $profile, $purpose, $count]) {
            $seed = $work->enqueue(RecoveryStage::Discover, 'fair-'.$scope, 1, 'discover', []);
            $bundle = (int) DB::table('obfuscation_recovery_work')->where('id', $seed)->value('bundle_id');
            DB::table('obfuscation_recovery_bundles')->where('id', $bundle)->update(['groups_id' => $group, 'profile' => $profile]);
            for ($i = 0; $i < $count; $i++) {
                $work->enqueueForBundle(RecoveryStage::Download, $bundle, 1, $purpose, ['message_id' => $scope.'-'.$i.'@fixture']);
            }
        }
        $seen = [];
        for ($i = 0; $i < 4; $i++) {
            $claim = $work->claim(RecoveryStage::Download);
            $this->assertNotNull($claim);
            $seen[] = $claim->bundleId;
        }
        $this->assertCount(4, array_unique($seen));
    }

    public function test_revision_change_fences_an_already_running_claim(): void
    {
        $work = app(RecoveryWork::class);
        $work->enqueue(RecoveryStage::Discover, 'bundle-a', 1, 'discover', []);
        $claim = $work->claim(RecoveryStage::Discover);
        $this->assertNotNull($claim);
        $work->invalidate('bundle-a', 2);
        $this->assertFalse($work->heartbeat($claim));
        $this->assertFalse($work->complete($claim));
        $work->enqueue(RecoveryStage::Discover, 'bundle-a', 2, 'discover', []);
        $next = $work->claim(RecoveryStage::Discover);
        $this->assertNotNull($next);
        $this->assertSame(2, $next->revision);
    }
}
