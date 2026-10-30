<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryAlgorithm;
use App\Services\ObfuscationRecovery\RecoveryDirty;
use App\Services\ObfuscationRecovery\RecoveryPositiveCoverage;
use App\Services\ObfuscationRecovery\RecoveryScheduler;
use App\Services\ObfuscationRecovery\RecoveryStage;
use App\Services\ObfuscationRecovery\RecoveryWork;
use App\Services\Tmux\Tmux;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
                'expired_frontier_conflicts', 'expired_frontier_ranges', 'expired_markers'] as $metric) {
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

    /** @return array<string, array{int, int, int}> */
    public static function plannerShares(): array
    {
        return [
            'defaults' => [60, 10, 3000000000],
            'five iterations' => [60, 5, 6000000000],
            'uneven split' => [10, 3, 1666666666],
        ];
    }

    #[DataProvider('plannerShares')]
    public function test_planner_deadline_is_half_of_an_even_iteration_share(int $seconds, int $limit, int $share): void
    {
        $scheduler = app(RecoveryScheduler::class);
        $now = 5000000000000;

        $this->assertSame($now + $share, $scheduler->plannerDeadline($now, $now + 60000000000, $seconds, $limit));
        $this->assertSame($now + $share - 1, $scheduler->plannerDeadline($now, $now + $share - 1, $seconds, $limit));
    }

    public function test_local_caps_the_planner_so_run_refresh_still_gets_time(): void
    {
        $this->createRecoverySchema();
        $this->enableRecoveryScope();
        foreach ([1010, 2010, 3010, 4010] as $first) {
            $this->candidate($first);
        }
        foreach ([1, 101, 201, 301] as $first) {
            $this->window($first);
        }
        $this->dirtyMark();
        DB::listen(static function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'select "summary" from "obfuscation_recovery_runs"')
                || str_starts_with($query->sql, 'insert or ignore into "obfuscation_recovery_gaps"')) {
                usleep(600000);
            }
        });

        $planning = $this->localPlanning(seconds: 2);

        $this->assertGreaterThanOrEqual(1, $planning['gaps_queued']);
        $this->assertGreaterThanOrEqual(1, $planning['runs_refreshed']);
    }

    public function test_housekeeping_does_not_spend_the_loop_budget(): void
    {
        $this->createRecoverySchema();
        $this->enableRecoveryScope();
        $this->dirtyMark();
        $this->recoveryRun(now()->subHour()->getTimestampMs(), dirty: true);
        DB::listen(static function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'reclaim_after')) {
                usleep(1100000);
            }
        });

        $planning = $this->localPlanning(seconds: 1);

        $this->assertGreaterThanOrEqual(1, $planning['runs_refreshed']);
        $this->assertSame(1, $planning['bundles_refreshed']);

        app(RecoveryWork::class)->enqueue(RecoveryStage::Publish, 'housekeeping-publish', 1, 'publish', []);
        $events = [];
        app(RecoveryScheduler::class)->local(RecoveryStage::Publish, limit: 1, seconds: 1, observe: function (string $event, array $data) use (&$events): void {
            $events[$event][] = $data;
        });

        $this->assertCount(1, $events['claim'] ?? []);
    }

    /** @return array<string, mixed> */
    private function localPlanning(int $seconds): array
    {
        $planning = null;
        app(RecoveryScheduler::class)->local(RecoveryStage::Discover, limit: 1, seconds: $seconds, observe: function (string $event, array $data) use (&$planning): void {
            if ($event === 'planning') {
                $planning = $data;
            }
        });
        $this->assertIsArray($planning);

        return $planning;
    }

    private function enableRecoveryScope(): void
    {
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        DB::table('usenet_groups')->insert(['id' => 1, 'obfuscation_recovery_profile' => 'both']);
        foreach (['primary', 'group:1'] as $scope) {
            DB::table('obfuscation_recovery_controls')->insert([
                'scope' => $scope, 'fingerprint' => str_repeat('a', 64), 'epoch' => 'epoch', 'generation' => 1, 'updated_at' => now(),
            ]);
        }
    }

    private function dirtyMark(): void
    {
        RecoveryDirty::mark(DB::connection(), [[
            'source_epoch' => 'epoch', 'groups_id' => 1, 'capture_generation' => 1, 'profile' => RecoveryAlgorithm::Media->value,
            'advertised_total' => 4, 'key_digest' => str_repeat('a', 64), 'embedded_timestamp_ms' => now()->subHours(2)->getTimestampMs(),
        ]]);
    }

    private function recoveryRun(int $endMs, bool $dirty = false, int $first = 1): int
    {
        return DB::table('obfuscation_recovery_runs')->insertGetId([
            'run_identity' => hash('sha256', 'run-'.$first), 'scope_digest' => RecoveryPositiveCoverage::scope('epoch', 1, 1),
            'source_epoch' => 'epoch', 'groups_id' => 1, 'capture_generation' => 1, 'profile' => RecoveryAlgorithm::Media->value,
            'partition_value' => (string) $first, 'start_ms' => $endMs - 1000, 'end_ms' => $endMs, 'observed_count' => 2,
            'state' => 'collecting', 'membership_digest' => hash('sha256', 'members-'.$first), 'bundle_dirty' => $dirty,
            'summary' => json_encode(['first_article' => $first, 'last_article' => $first + 80, 'first_postdate' => now()->subHours(3)->toDateTimeString(),
                'last_postdate' => now()->subHours(3)->toDateTimeString(), 'membership_changed_at' => now()->subHours(3)->format('Y-m-d H:i:s.u')], JSON_THROW_ON_ERROR),
            'oldest_observed_at' => now(), 'created_at' => now()->subMinute(), 'updated_at' => now()->subMinute(),
        ]);
    }

    private function candidate(int $first): void
    {
        $endMs = now()->subHours(3)->getTimestampMs();
        DB::table('obfuscation_recovery_bundles')->insert([
            'owner_digest' => hash('sha256', 'posting-'.$first), 'kind' => 'posting', 'groups_id' => 1,
            'profile' => RecoveryAlgorithm::Media->value, 'source_epoch' => 'epoch', 'capture_generation' => 1, 'state' => 'collecting',
            'membership_changed_at' => now()->subHours(3), 'candidate_runs' => json_encode([$this->recoveryRun($endMs, first: $first)], JSON_THROW_ON_ERROR),
            'end_ms' => $endMs, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function window(int $first): void
    {
        DB::table('obfuscation_recovery_scan_windows')->insert([
            'scan_id' => (string) Str::uuid(), 'groups_id' => 1, 'source_epoch' => 'epoch', 'capture_generation' => 1,
            'requested_first' => $first, 'requested_last' => $first + 99, 'gap_cursor' => $first,
            'next_gap_at' => now()->subMinutes(5), 'expires_at' => now()->addDay(), 'created_at' => now(),
        ]);
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
