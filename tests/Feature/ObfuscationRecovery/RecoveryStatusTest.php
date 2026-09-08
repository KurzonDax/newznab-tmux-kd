<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryBudget;
use App\Services\ObfuscationRecovery\RecoveryBudgetOwners;
use App\Services\ObfuscationRecovery\RecoveryStatus;
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
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_status_separates_candidates_from_verified_coverage_and_retains_merged_lifetime_allowances(): void
    {
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
}
