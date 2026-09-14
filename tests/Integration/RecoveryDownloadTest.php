<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\NNTP\NntpProviderPool;
use App\Services\ObfuscationRecovery\RecoveryAlgorithm;
use App\Services\ObfuscationRecovery\RecoveryArtifacts;
use App\Services\ObfuscationRecovery\RecoveryBudget;
use App\Services\ObfuscationRecovery\RecoveryConstructionTargets;
use App\Services\ObfuscationRecovery\RecoveryDownload;
use App\Services\ObfuscationRecovery\RecoveryEvidence;
use App\Services\ObfuscationRecovery\RecoveryIdentity;
use App\Services\ObfuscationRecovery\RecoveryLimitResume;
use App\Services\ObfuscationRecovery\RecoveryProviderBackoff;
use App\Services\ObfuscationRecovery\RecoveryScheduler;
use App\Services\ObfuscationRecovery\RecoverySlots;
use App\Services\ObfuscationRecovery\RecoveryStage;
use App\Services\ObfuscationRecovery\RecoveryWork;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ObfuscationRecovery\InteractsWithRecoveryNntpServer;
use Tests\TestCase;

final class RecoveryDownloadTest extends TestCase
{
    use InteractsWithRecoveryNntpServer;
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
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        DB::table('usenet_groups')->insert(['id' => 1, 'obfuscation_recovery_profile' => 'media']);
    }

    protected function tearDown(): void
    {
        $this->stopRecoveryServers();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public static function interruptedHandoffs(): array
    {
        return [['obfuscation_recovery_artifacts', 2, false], ['obfuscation_recovery_evidence', 1, false], ['completed', 1, false],
            ['obfuscation_recovery_artifacts', 2, true], ['obfuscation_recovery_evidence', 1, true], ['completed', 1, true]];
    }

    #[DataProvider('interruptedHandoffs')]
    public function test_successful_wire_handoffs_resume_after_storage_or_completion_interruption(string $boundary, int $attempts, bool $historical): void
    {
        $work = app(RecoveryWork::class);
        $work->enqueue(RecoveryStage::Download, 'handoff-owner', 1, 'index', ['message_id' => 'fixture@local']);
        DB::table('obfuscation_recovery_bundles')->update(['profile' => RecoveryAlgorithm::Media->value, 'groups_id' => 1]);
        $claim = $work->claim(RecoveryStage::Download);
        (new RecoveryConstructionTargets)->register($claim, [['kind' => 'index', 'file_id' => null, 'message_id' => 'fixture@local']]);
        $body = "222 0 <fixture@local> body\r\n=ybegin line=128 size=4 name=index.par2\r\naaaa\r\n=yend size=4\r\n.\r\n";
        $providers = [$this->server($body), $this->server($body, position: 2)];
        $cache = new RecoveryEvidence(new RecoveryArtifacts($this->makeTempDirectory('handoff-cache')), new RecoveryIdentity);
        $this->app->instance(RecoveryEvidence::class, $cache);
        $download = new RecoveryDownload($cache, new RecoverySlots, app(RecoveryBudget::class), $work);
        $armed = true;
        DB::listen(function (QueryExecuted $query) use ($boundary, &$armed): void {
            $hit = $boundary === 'completed'
                ? str_starts_with($query->sql, 'update "obfuscation_recovery_work"') && in_array('completed', $query->bindings, true)
                : str_starts_with($query->sql, 'insert into "'.$boundary.'"');
            if ($armed && $hit) {
                $armed = false;
                throw new \RuntimeException('interrupted_handoff');
            }
        });
        try {
            $download->run($claim, $providers);
            $this->fail('The real persistence boundary must be interrupted.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(match ($boundary) {
                'obfuscation_recovery_artifacts' => 'transfer_artifact_pending',
                'obfuscation_recovery_evidence' => 'transfer_evidence_pending',
                default => 'interrupted_handoff',
            }, $exception->getMessage());
        }
        $original = DB::table('obfuscation_recovery_attempts')->first();
        $this->assertSame('success', $original->outcome);
        $this->assertGreaterThan(0, (int) $original->debited_bytes);
        if ($historical) {
            $this->assertTrue($work->complete($claim, 'construction_limit_reached'));
            $discover = $work->enqueueForBundle(RecoveryStage::Discover, $claim->bundleId, $claim->revision, 'prepare', []);
            DB::table('obfuscation_recovery_work')->where('id', $discover)->update(['status' => 'completed', 'result' => 'construction_limit_reached']);
            DB::table('obfuscation_recovery_bundles')->where('id', $claim->bundleId)->update(['state' => 'construction_limit_reached', 'inactive_since' => now()]);
            $this->assertTrue(app(RecoveryLimitResume::class)->step());
            $this->assertSame('pending', DB::table('obfuscation_recovery_work')->where('id', $discover)->value('status'));
            $this->assertNull(DB::table('obfuscation_recovery_bundles')->where('id', $claim->bundleId)->value('inactive_since'));
        } else {
            $this->assertTrue($work->defer($claim, 1));
        }
        $this->travel(2)->seconds();
        $result = $download->run($work->claim(RecoveryStage::Download), $providers);
        $this->assertContains($result, ['downloaded', 'cache_hit']);
        $this->assertSame('7777', $cache->get('fixture@local')->data);
        $this->assertEquals($original, DB::table('obfuscation_recovery_attempts')->where('id', $original->id)->first());
        $this->assertSame($attempts, DB::table('obfuscation_recovery_attempts')->count());
    }

    #[DataProvider('handoffDiagnostics')]
    public function test_scheduler_persists_the_failed_handoff_boundary(string $table, string $reason): void
    {
        $work = app(RecoveryWork::class);
        $id = $work->enqueue(RecoveryStage::Download, 'diagnostic-owner', 1, 'index', ['message_id' => 'fixture@local']);
        DB::table('obfuscation_recovery_bundles')->update(['profile' => RecoveryAlgorithm::Media->value, 'groups_id' => 1]);
        $claim = $work->claim(RecoveryStage::Download);
        (new RecoveryConstructionTargets)->register($claim, [['kind' => 'index', 'file_id' => null, 'message_id' => 'fixture@local']]);
        $work->defer($claim, 1);
        DB::table('obfuscation_recovery_work')->where('id', $id)->update(['due_at' => now()->subSecond()]);
        $provider = $this->server("222 0 <fixture@local> body\r\n=ybegin line=128 size=4 name=index.par2\r\naaaa\r\n=yend size=4\r\n.\r\n");
        config(['nntmux_nntp.providers' => [['position' => 1, 'name' => 'fixture', 'host' => $provider->host, 'port' => $provider->port, 'ssl' => false]]]);
        NntpProviderPool::forgetConfiguredProviders();
        $this->app->instance(RecoveryEvidence::class, new RecoveryEvidence(new RecoveryArtifacts($this->makeTempDirectory('diagnostics')), new RecoveryIdentity));
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed, $table): void {
            $hit = $table === 'attempts'
                ? str_starts_with($query->sql, 'update "obfuscation_recovery_attempts"') && str_contains($query->sql, '"settled_at" =')
                : str_starts_with($query->sql, 'insert into "obfuscation_recovery_'.$table.'"');
            if ($armed && $hit) {
                $armed = false;
                throw new \RuntimeException('injected_storage_failure');
            }
        });
        try {
            $this->assertSame($reason, app(RecoveryScheduler::class)->download());
            $this->assertFalse($armed);
            $row = DB::table('obfuscation_recovery_work')->where('id', $id)->first();
            $this->assertSame('pending', $row->status);
            $this->assertSame($reason, $row->result);
            $this->assertSame(1, DB::table('obfuscation_recovery_attempts')->count());
        } finally {
            NntpProviderPool::forgetConfiguredProviders();
        }
    }

    public static function handoffDiagnostics(): array
    {
        return [['attempts', 'transfer_receipt_pending'], ['artifacts', 'transfer_artifact_pending'], ['evidence', 'transfer_evidence_pending']];
    }

    public function test_provider_failures_back_off_other_owners_without_spending_their_attempts(): void
    {
        $work = app(RecoveryWork::class);
        $provider = $this->server("400 connection limit\r\n");
        $cache = new RecoveryEvidence(new RecoveryArtifacts($this->makeTempDirectory('backoff-cache')), new RecoveryIdentity);
        $download = new RecoveryDownload($cache, new RecoverySlots, app(RecoveryBudget::class), $work);
        foreach (['first', 'second'] as $i => $owner) {
            $id = $work->enqueue(RecoveryStage::Download, $owner, 1, 'index', ['message_id' => 'fixture@local']);
            $bundle = DB::table('obfuscation_recovery_work')->where('id', $id)->value('bundle_id');
            DB::table('obfuscation_recovery_bundles')->where('id', $bundle)->update(['profile' => RecoveryAlgorithm::Media->value, 'groups_id' => 1]);
            $claim = $work->claim(RecoveryStage::Download);
            (new RecoveryConstructionTargets)->register($claim, [['kind' => 'index', 'file_id' => null, 'message_id' => 'fixture@local']]);
            $this->assertSame($i === 0 ? 'retry_pending' : 'provider_backoff', $download->run($claim, [$provider]));
        }
        $this->assertSame(1, DB::table('obfuscation_recovery_attempts')->count());
        $this->assertSame(1, DB::table('obfuscation_recovery_budgets')->count());
        $this->assertSame(0, DB::table('obfuscation_recovery_slots')->whereNotNull('worker_token')->count());
        $this->assertFalse(app(RecoveryProviderBackoff::class)->allows([$provider]));
        $this->travel(61)->seconds();
        $this->assertTrue(app(RecoveryProviderBackoff::class)->allows([$provider]));
    }

    public function test_fallback_requires_a_new_dispatch_and_cached_revisions_open_no_socket(): void
    {
        $work = app(RecoveryWork::class);
        $work->enqueue(RecoveryStage::Download, 'download-owner', 1, 'index', ['message_id' => 'fixture@local']);
        DB::table('obfuscation_recovery_bundles')->update(['profile' => RecoveryAlgorithm::Media->value, 'groups_id' => 1]);
        $claim = $work->claim(RecoveryStage::Download);
        (new RecoveryConstructionTargets)->register($claim, [['kind' => 'index', 'file_id' => null, 'message_id' => 'fixture@local']]);
        $first = $this->server("430 absent\r\n");
        $second = $this->server("222 0 <fixture@local> body\r\n=ybegin line=128 size=4 name=index.par2\r\naaaa\r\n=yend size=4\r\n.\r\n", position: 2);
        $cache = new RecoveryEvidence(new RecoveryArtifacts($this->makeTempDirectory('download-cache')), new RecoveryIdentity);
        $download = new RecoveryDownload($cache, new RecoverySlots, app(RecoveryBudget::class), $work);
        $this->assertSame('retry_pending', $download->run($claim, [$first, $second]));
        $this->assertSame(1, DB::table('obfuscation_recovery_attempts')->count());
        $this->assertSame(0, DB::table('obfuscation_recovery_slots')->whereNotNull('worker_token')->count());
        $this->assertNull($work->claim(RecoveryStage::Download));
        $this->travel(61)->seconds();
        $next = $work->claim(RecoveryStage::Download);
        $this->assertSame('downloaded', $download->run($next, [$first, $second]));
        $this->assertSame('7777', $cache->get('fixture@local')->data);
        $this->assertSame([1, 2], DB::table('obfuscation_recovery_attempts')->orderBy('id')->pluck('physical_attempt')->map(intval(...))->all());
        $this->assertSame(0, DB::table('obfuscation_recovery_slots')->whereNotNull('worker_token')->count());
        $work->invalidate('download-owner', 2);
        $work->enqueue(RecoveryStage::Download, 'download-owner', 2, 'index', ['message_id' => 'fixture@local']);
        $this->assertSame('cache_hit', $download->run($work->claim(RecoveryStage::Download), []));
        $this->assertSame(2, DB::table('obfuscation_recovery_attempts')->count());
    }
}
