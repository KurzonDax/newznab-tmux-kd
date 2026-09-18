<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryScheduler;
use App\Services\ObfuscationRecovery\RecoveryStage;
use App\Services\ObfuscationRecovery\RecoveryWork;
use App\Services\Tmux\Tmux;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class RecoverySchedulerTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
    }

    protected function tearDown(): void
    {
        \Termwind\renderUsing(null);
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_engine_admission_follows_the_persisted_running_flag(): void
    {
        DB::table('settings')->insert(['name' => 'running', 'value' => 1]);
        $scheduler = app(RecoveryScheduler::class);

        $this->assertTrue($scheduler->allowed(true));

        DB::table('settings')->where('name', 'running')->update(['value' => 0]);

        $this->assertFalse($scheduler->allowed(true));
    }

    /** @return array<string, array{?string, bool}> */
    public static function runningSettings(): array
    {
        return [
            'missing' => [null, false],
            'blank' => ['', false],
            'stopped' => ['0', false],
            'running' => ['1', true],
            'unsupported' => ['2', false],
        ];
    }

    #[DataProvider('runningSettings')]
    public function test_manual_admission_is_unconditional_and_monitor_agrees_with_engine_admission(?string $running, bool $allowed): void
    {
        if ($running !== null) {
            DB::table('settings')->insert(['name' => 'running', 'value' => $running]);
        }
        $scheduler = app(RecoveryScheduler::class);

        $this->assertTrue($scheduler->allowed(false));
        $this->assertSame($allowed, $scheduler->allowed(true));
        $this->assertSame((int) $allowed, app(Tmux::class)->getMonitorSettings()['is_running']);
    }

    public function test_local_stages_run_only_while_the_engine_is_running(): void
    {
        $this->createRecoverySchema();
        DB::table('settings')->insert(['name' => 'running', 'value' => 1]);
        $scheduler = app(RecoveryScheduler::class);

        foreach ([RecoveryStage::Discover, RecoveryStage::Publish] as $stage) {
            $events = [];
            $report = $scheduler->local($stage, limit: 1, seconds: 1, engine: true, observe: function (string $event, array $data) use (&$events): void {
                $events[] = [$event, $data];
            });
            $this->assertSame('housekeeping', $events[0][0]);
            $this->assertSame('done', $events[array_key_last($events)][0]);
            $this->assertSame($stage === RecoveryStage::Discover, in_array('planning', array_column($events, 0), true));
            $this->assertSame($report, $scheduler->local($stage, limit: 1, seconds: 1, engine: true));
            $this->assertArrayNotHasKey('engine_stopped', $report);
            $this->assertSame(0, $report['expired_headers']);
            foreach (['expired_scans', 'expired_scan_batches', 'compacted_incomplete_scans', 'expired_frontiers',
                'expired_frontier_conflicts', 'expired_frontier_ranges'] as $metric) {
                if ($stage === RecoveryStage::Discover) {
                    $this->assertSame(0, $report[$metric]);
                } else {
                    $this->assertArrayNotHasKey($metric, $report);
                }
            }
        }

        DB::table('settings')->where('name', 'running')->update(['value' => 0]);

        foreach ([RecoveryStage::Discover, RecoveryStage::Publish] as $stage) {
            $events = [];
            $this->assertSame(['engine_stopped' => 1], $scheduler->local($stage, engine: true, observe: function (string $event, array $data) use (&$events): void {
                $events[] = [$event, $data];
            }));
            $this->assertSame([['engine_stopped', []]], $events);
        }
    }

    public function test_download_details_preserve_idle_and_claim_outcomes(): void
    {
        $this->createRecoverySchema();
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        $scheduler = app(RecoveryScheduler::class);
        $details = $scheduler->downloadDetailed();
        $this->assertSame('idle', $details['outcome']);
        $this->assertNull($details['bundle_id']);
        $this->assertNull($details['purpose']);
        $this->assertSame([], $details['payload']);
        $this->assertGreaterThanOrEqual(0, $details['seconds']);
        $this->assertSame('idle', $scheduler->download());
    }

    public function test_commands_render_sentences_and_escape_literal_angle_brackets(): void
    {
        $this->createRecoverySchema();
        $buffer = new BufferedOutput;
        \Termwind\renderUsing($buffer);
        foreach (['discover', 'publish'] as $stage) {
            $this->artisan('obfuscation:'.$stage)->assertSuccessful();
            $output = $buffer->fetch();
            $this->assertStringContainsString('Recovery '.$stage.' at ', $output);
            $this->assertStringContainsString('Housekeeping:', $output);
            $this->assertStringContainsString('Done:', $output);
            $this->assertStringNotContainsString('{', $output);
            if ($stage === 'discover') {
                $this->assertStringContainsString('Backlog:', $output);
            }
        }
    }

    public function test_each_processed_claim_reports_its_identity_and_elapsed_time(): void
    {
        $this->createRecoverySchema();
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        $work = app(RecoveryWork::class);
        $id = $work->enqueue(RecoveryStage::Discover, 'observer-candidate', 1, 'prepare', []);
        $bundleId = (int) DB::table('obfuscation_recovery_work')->where('id', $id)->value('bundle_id');
        $events = [];
        $report = app(RecoveryScheduler::class)->local(RecoveryStage::Discover, limit: 1, observe: function (string $event, array $data) use (&$events): void {
            $events[$event][] = $data;
        });
        $this->assertCount(1, $events['claim']);
        $this->assertSame($bundleId, $events['claim'][0]['bundle_id']);
        $this->assertSame(1, $events['claim'][0]['revision']);
        $this->assertSame('prepare', $events['claim'][0]['purpose']);
        $this->assertSame(1, $report[$events['claim'][0]['result']]);
        $this->assertGreaterThanOrEqual(0, $events['claim'][0]['seconds']);
        $this->assertSame(1, $events['done'][0]['claims']);
    }

    private function createRecoverySchema(): void
    {
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
}
