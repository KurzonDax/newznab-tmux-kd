<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\ObfuscationRecovery\RecoveryAlgorithm;
use App\Services\ObfuscationRecovery\RecoveryArtifacts;
use App\Services\ObfuscationRecovery\RecoveryBudget;
use App\Services\ObfuscationRecovery\RecoveryConstructionTargets;
use App\Services\ObfuscationRecovery\RecoveryDownload;
use App\Services\ObfuscationRecovery\RecoveryEvidence;
use App\Services\ObfuscationRecovery\RecoveryIdentity;
use App\Services\ObfuscationRecovery\RecoveryProviderBackoff;
use App\Services\ObfuscationRecovery\RecoverySlots;
use App\Services\ObfuscationRecovery\RecoveryStage;
use App\Services\ObfuscationRecovery\RecoveryWork;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        DB::table('usenet_groups')->insert(['id' => 1, 'obfuscation_recovery_profile' => 'media']);
    }

    protected function tearDown(): void
    {
        $this->stopRecoveryServers();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
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
