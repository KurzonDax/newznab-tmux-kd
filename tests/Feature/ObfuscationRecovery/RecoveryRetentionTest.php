<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryConfig;
use App\Services\ObfuscationRecovery\RecoveryRetention;
use App\Services\ObfuscationRecovery\RecoveryScheduler;
use App\Services\ObfuscationRecovery\RecoveryStage;
use App\Services\ObfuscationRecovery\RecoveryWork;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class RecoveryRetentionTest extends TestCase
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

    public function test_old_backlog_is_purged_in_bounded_batches_using_first_observation(): void
    {
        $this->header(1, null, 145);
        $this->header(2, null, 150);
        $this->header(3, null, 143);
        $purger = new RecoveryRetention;
        $report = $purger->purge(RecoveryConfig::fromValues([]), 1);
        $this->assertSame(1, $report['headers']);
        $this->assertSame([1, 3], DB::table('obfuscation_recovery_headers')->orderBy('id')->pluck('id')->all());
        $this->assertSame(1, $purger->purge(RecoveryConfig::fromValues([]), 100)['headers']);
        $this->assertSame(0, $purger->purge(RecoveryConfig::fromValues([]), 100)['headers']);
        $this->assertSame(2, (int) DB::table('obfuscation_recovery_metrics')->where('metric', 'expired_headers')->sum('value'));
        $this->assertSame(1, $purger->purge(RecoveryConfig::fromValues(['obfuscation_recovery_retention_hours' => 100]), 100)['headers']);
    }

    public function test_cleanup_runs_even_when_other_housekeeping_exhausts_the_local_deadline(): void
    {
        $this->header(1, null, 145);
        $delayed = false;
        DB::listen(static function ($event) use (&$delayed): void {
            if (! $delayed && str_contains($event->sql, 'reclaim_after')) {
                $delayed = true;
                usleep(1100000);
            }
        });
        $report = app(RecoveryScheduler::class)->local(RecoveryStage::Discover, 1, 1);
        $this->assertTrue($delayed);
        $this->assertSame(0, DB::table('obfuscation_recovery_headers')->count());
        $this->assertSame(1, $report['expired_headers']);
    }

    public function test_live_claim_can_close_but_cannot_renew_or_publish_after_expiry(): void
    {
        $work = app(RecoveryWork::class);
        $work->enqueue(RecoveryStage::Discover, 'expiry-owner', 1, 'discover', []);
        $claim = $work->claim(RecoveryStage::Discover);
        $this->header(1, $claim->bundleId, 145);
        $purger = new RecoveryRetention;
        $report = $purger->purge(RecoveryConfig::fromValues([]));
        $this->assertSame(0, $report['headers']);
        $this->assertSame(1, $report['waiting']);
        $this->assertSame('expiry_pending', DB::table('obfuscation_recovery_bundles')->value('state'));
        $this->assertFalse($work->heartbeat($claim));
        $this->assertFalse($work->complete($claim));
        $this->travel(91)->seconds();
        $this->assertSame(1, $purger->purge(RecoveryConfig::fromValues([]))['headers']);
        $this->assertSame('expired_unresolved', DB::table('obfuscation_recovery_bundles')->value('state'));
        $this->assertSame('obsolete', DB::table('obfuscation_recovery_work')->value('status'));
        $this->assertSame(1, (int) DB::table('obfuscation_recovery_metrics')->where('metric', 'expired_candidates')->sum('value'));
    }

    public function test_only_verified_durable_plans_survive_expiry_of_staging(): void
    {
        $work = app(RecoveryWork::class);
        $work->enqueue(RecoveryStage::Publish, 'ready-owner', 1, 'publish', []);
        $bundle = DB::table('obfuscation_recovery_bundles')->first();
        DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->update([
            'state' => 'ready', 'sealed_plan' => '{}', 'manifest_verified_at' => now(),
        ]);
        $this->header(1, (int) $bundle->id, 145);
        $this->assertSame(1, (new RecoveryRetention)->purge(RecoveryConfig::fromValues([]))['headers']);
        $this->assertSame('ready', DB::table('obfuscation_recovery_bundles')->value('state'));
        $this->assertNotNull($work->claim(RecoveryStage::Publish));
    }

    public function test_unlinked_raw_rows_expire_their_discovered_candidate_before_raw_deletion(): void
    {
        $work = app(RecoveryWork::class);
        $work->enqueue(RecoveryStage::Discover, 'unlinked-owner', 1, 'discover', []);
        DB::table('obfuscation_recovery_bundles')->update(['groups_id' => 1, 'profile' => 'nyuu-media-v1',
            'source_epoch' => 'epoch', 'capture_generation' => 1, 'start_ms' => 1001, 'end_ms' => 1001]);
        DB::table('obfuscation_recovery_controls')->insert([
            ['scope' => 'primary', 'fingerprint' => str_repeat('a', 64), 'epoch' => 'epoch', 'generation' => 1, 'updated_at' => now()],
            ['scope' => 'group:1', 'fingerprint' => str_repeat('b', 64), 'epoch' => 'group', 'generation' => 1, 'updated_at' => now()],
        ]);
        $claim = $work->claim(RecoveryStage::Discover);
        $this->assertNotNull($claim);
        $this->header(1, null, 145);
        $purger = new RecoveryRetention;
        $this->assertSame(0, $purger->purge(RecoveryConfig::fromValues([]))['headers']);
        $this->assertSame('expiry_pending', DB::table('obfuscation_recovery_bundles')->value('state'));
        $this->assertFalse($work->heartbeat($claim));
        $this->travel(91)->seconds();
        $this->assertSame(1, $purger->purge(RecoveryConfig::fromValues([]))['headers']);
        $this->assertSame('expired_unresolved', DB::table('obfuscation_recovery_bundles')->value('state'));
    }

    private function header(int $id, ?int $bundle, int $age): void
    {
        DB::table('obfuscation_recovery_headers')->insert([
            'id' => $id, 'source_epoch' => 'epoch', 'groups_id' => 1, 'capture_generation' => 1,
            'message_id' => 'm'.$id.'@local', 'source_message_id' => '<m'.$id.'@local>', 'message_id_digest' => hash('sha256', (string) $id),
            'article_number' => $id, 'raw_subject' => 'opaque', 'poster_identity' => 'fixture',
            'source_date' => '2026-01-01T00:00:00Z', 'postdate' => '2026-01-01 00:00:00', 'advertised_bytes' => 100,
            'embedded_timestamp_ms' => 1000 + $id, 'profile' => 'nyuu-media-v1', 'key_digest' => str_repeat('a', 64),
            'first_observed_at' => now()->subHours($age), 'last_observed_at' => now(), 'bundle_id' => $bundle,
        ]);
    }
}
