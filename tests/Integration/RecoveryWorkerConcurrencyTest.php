<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Enums\HeaderScanDirection;
use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\CollectionHandler;
use App\Services\Binaries\HeaderParser;
use App\Services\Binaries\HeaderStorageService;
use App\Services\BlacklistService;
use App\Services\CollectionsCleaningService;
use App\Services\NNTP\NntpProvider;
use App\Services\ObfuscationRecovery\RecoveryAlgorithm;
use App\Services\ObfuscationRecovery\RecoveryBudget;
use App\Services\ObfuscationRecovery\RecoveryBundleRefresh;
use App\Services\ObfuscationRecovery\RecoveryCapture;
use App\Services\ObfuscationRecovery\RecoveryCaptureBatch;
use App\Services\ObfuscationRecovery\RecoveryConfig;
use App\Services\ObfuscationRecovery\RecoveryConstructionTargets;
use App\Services\ObfuscationRecovery\RecoveryControl;
use App\Services\ObfuscationRecovery\RecoveryDownload;
use App\Services\ObfuscationRecovery\RecoveryEnrichmentResume;
use App\Services\ObfuscationRecovery\RecoveryEvidence;
use App\Services\ObfuscationRecovery\RecoveryFilePlan;
use App\Services\ObfuscationRecovery\RecoveryFileRole;
use App\Services\ObfuscationRecovery\RecoveryFrontierRebuild;
use App\Services\ObfuscationRecovery\RecoveryFrontiers;
use App\Services\ObfuscationRecovery\RecoveryGapPlanner;
use App\Services\ObfuscationRecovery\RecoveryHeads;
use App\Services\ObfuscationRecovery\RecoveryPlan;
use App\Services\ObfuscationRecovery\RecoveryPositiveCoverage;
use App\Services\ObfuscationRecovery\RecoveryPreparation;
use App\Services\ObfuscationRecovery\RecoveryPublicationCoverage;
use App\Services\ObfuscationRecovery\RecoveryPublications;
use App\Services\ObfuscationRecovery\RecoveryRetention;
use App\Services\ObfuscationRecovery\RecoveryRunRefresh;
use App\Services\ObfuscationRecovery\RecoveryScanContext;
use App\Services\ObfuscationRecovery\RecoveryScheduler;
use App\Services\ObfuscationRecovery\RecoverySlots;
use App\Services\ObfuscationRecovery\RecoveryStage;
use App\Services\ObfuscationRecovery\RecoveryStatus;
use App\Services\ObfuscationRecovery\RecoveryWork;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\NeverBlacklistedService;
use Tests\Support\ObfuscationRecovery\CreatesRecoveryCbpSchema;
use Tests\Support\ObfuscationRecovery\MediaPostingFixture;
use Tests\Support\ObfuscationRecovery\SyntheticPosting;
use Tests\TestCase;

final class RecoveryWorkerConcurrencyTest extends TestCase
{
    use CreatesRecoveryCbpSchema;
    use IsolatedSqliteDatabase;

    private string $fixtureDatabase = '';

    private string $root;

    private array $children = [];

    private array $servers = [];

    private array $providers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->root = $this->makeTempDirectory('recovery-workers');
        $this->fixtureDatabase = 'recovery_workers_'.bin2hex(random_bytes(8));
        $config = ['driver' => 'mariadb', 'host' => 'mariadb', 'port' => 3306, 'database' => null,
            'username' => 'root', 'password' => 'password', 'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true];
        config(['database.connections.recovery_admin' => $config]);
        DB::connection('recovery_admin')->statement('CREATE DATABASE `'.$this->fixtureDatabase.'`');
        config(['database.connections.recovery_workers' => [...$config, 'database' => $this->fixtureDatabase],
            'database.default' => 'recovery_workers', 'cache.default' => 'array',
            'filesystems.disks.recovery.root' => $this->root.'/artifacts']);
        DB::purge('recovery_workers');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
        (require database_path('migrations/2026_09_13_002751_add_recovery_frontier_evidence.php'))->up();
        DB::table('settings')->insert([['name' => 'categorizeforeign', 'value' => 0], ['name' => 'catwebdl', 'value' => 0], ['name' => 'running', 'value' => 1]]);
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.fixture', 'obfuscation_recovery_profile' => 'both']);
        $this->createRecoveryCbpSchema();
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->children as $child) {
                $child->stop(2);
            }
            // Supervisor termination closes its children before the disposable database is removed.
            foreach (DB::table('obfuscation_recovery_slots')->whereNotNull('worker_token')->get() as $slot) {
                if ((int) $slot->owner_pid !== getmypid()) {
                    @posix_kill((int) $slot->owner_pid, SIGKILL);
                }
            }
            foreach ($this->servers as $server) {
                $server->stop(1);
            }
            DB::disconnect('recovery_workers');
            if ($this->fixtureDatabase !== '') {
                DB::connection('recovery_admin')->statement('DROP DATABASE IF EXISTS `'.$this->fixtureDatabase.'`');
            }
            DB::disconnect('recovery_admin');
        } finally {
            $this->tearDownIsolatedDatabase();
            parent::tearDown();
        }
    }

    public static function allocations(): array
    {
        return [[1], [3]];
    }

    #[DataProvider('allocations')]
    public function test_duplicate_supervisors_and_restart_share_one_allocation_and_fallback_is_serial(int $threads): void
    {
        $this->server(1, true, 0.4);
        $this->server(2, false, 0.4);
        $this->settingsThreads($threads);
        $this->seedConstruction(8);
        for ($round = 0; $round < 20 && DB::table('obfuscation_recovery_work')->where('stage', 'download')->where('status', '<>', 'completed')->exists(); $round++) {
            $supervisors = [$this->supervisor(), $this->supervisor(), $this->supervisor()];
            $this->await(fn (): bool => $this->events('open') !== [], 10);
            if ($round === 0) {
                $this->await(fn (): bool => app(RecoveryStatus::class)->summary()['active_connections'] > 0, 10);
                $this->ordinaryProgress();
            }
            // Local claims progress while independent supervisors own sockets.
            $work = app(RecoveryWork::class);
            $work->enqueue(RecoveryStage::Discover, 'local-'.$round, 1, 'discover', []);
            $this->assertTrue($work->complete($work->claim(RecoveryStage::Discover)));
            foreach ($supervisors as $supervisor) {
                $supervisor->wait();
                $this->assertTrue($supervisor->isSuccessful(), $supervisor->getErrorOutput());
            }
            DB::table('obfuscation_recovery_work')->where('status', 'pending')->update(['due_at' => now()->subSecond()]);
        }
        $this->assertSame(8, DB::table('obfuscation_recovery_work')->where('stage', 'download')->where('result', 'downloaded')->count());
        $this->assertSame(16, DB::table('obfuscation_recovery_attempts')->count());
        $this->assertSame(0, DB::table('obfuscation_recovery_slots')->whereNotNull('worker_token')->count());
        $this->assertSame($threads, $this->peak());
        foreach ([1, 2] as $provider) {
            $this->assertCount(8, $this->events('request', $provider));
        }
        foreach (DB::table('obfuscation_recovery_attempts')->orderBy('id')->get()->groupBy('request_digest') as $attempts) {
            $this->assertSame([1, 2], $attempts->pluck('physical_attempt')->map(intval(...))->all());
            $this->assertLessThanOrEqual($attempts[1]->connected_at, $attempts[0]->closed_at);
        }
    }

    public function test_lowering_threads_and_engine_stop_do_not_replace_live_sockets_then_dead_workers_are_accounted(): void
    {
        $this->server(1, false, 3);
        $this->settingsThreads(3);
        $this->seedConstruction(8);
        $supervisor = $this->supervisor(true);
        $this->await(fn (): bool => count($this->events('request')) === 3, 15);
        $this->settingsThreads(1);
        $extra = $this->supervisor(true);
        $extra->wait();
        $this->assertCount(3, $this->events('open'));
        $this->assertSame(3, DB::table('obfuscation_recovery_slots')->whereNotNull('worker_token')->count());
        DB::table('settings')->where('name', 'running')->update(['value' => 0]);
        $supervisor->wait();
        $this->assertTrue($supervisor->isSuccessful(), $supervisor->getErrorOutput());
        $stopped = $this->supervisor(true);
        $stopped->wait();
        $this->assertTrue($stopped->isSuccessful(), $stopped->getErrorOutput());
        $this->assertSame('admission_pending', trim($stopped->getOutput()));
        $this->assertCount(3, $this->events('open'));
        DB::table('obfuscation_recovery_slots')->whereNotNull('worker_token')->update(['expires_at' => now()->subSecond()]);
        DB::table('obfuscation_recovery_work')->where('status', 'claimed')->update(['claim_expires_at' => now()->subSecond()]);
        $this->assertSame(3, app(RecoverySlots::class)->reap());
        $this->assertSame(3, app(RecoveryWork::class)->reclaimExpired());
        $this->assertSame(3, DB::table('obfuscation_recovery_attempts')->where('outcome', 'worker_lost')->count());
        foreach (DB::table('obfuscation_recovery_attempts')->get() as $attempt) {
            $this->assertSame((int) $attempt->reserved_bytes, (int) $attempt->debited_bytes);
        }
        $this->assertSame(3, $this->peak());
    }

    public function test_construction_enrichment_and_gap_requests_contend_for_the_same_three_sockets(): void
    {
        $this->server(1, false, 0.6);
        $this->settingsThreads(3);
        $this->seedConstruction(2);
        $this->seedEnrichment();
        $provider = NntpProvider::fromConfig($this->providers[0]);
        (new RecoveryControl)->begin(
            RecoveryConfig::fromSettings(), $provider, 1, 'alt.binaries.fixture',
            100, 110, HeaderScanDirection::Head, 1);
        DB::table('obfuscation_recovery_scan_windows')->update(['next_gap_at' => now()->subSecond(), 'created_at' => now()->subMinutes(3)]);
        $this->assertSame(1, app(RecoveryGapPlanner::class)->step());
        for ($round = 0; $round < 5 && DB::table('obfuscation_recovery_work')->where('stage', 'download')->where('status', 'pending')->exists(); $round++) {
            $supervisors = [$this->supervisor(), $this->supervisor()];
            foreach ($supervisors as $supervisor) {
                $supervisor->wait();
                $this->assertTrue($supervisor->isSuccessful(), $supervisor->getErrorOutput());
            }
            DB::table('obfuscation_recovery_work')->where('status', 'pending')->update(['due_at' => now()->subSecond()]);
        }
        $this->assertSame(2, DB::table('obfuscation_recovery_work')->where('purpose', 'index')->where('result', 'downloaded')->count());
        $this->assertSame('captured', DB::table('obfuscation_recovery_work')->where('purpose', 'gap')->value('result'));
        $this->assertSame('downloaded', DB::table('obfuscation_recovery_work')->where('purpose', 'enrichment')->value('result'));
        $this->assertSame(['construction', 'enrichment', 'gap'], DB::table('obfuscation_recovery_budgets')->distinct()->orderBy('purpose')->pluck('purpose')->all());
        $this->assertSame(3, $this->peak());
        $this->assertSame(0, DB::table('obfuscation_recovery_slots')->whereNotNull('worker_token')->count());
    }

    public function test_optional_limit_increase_resumes_existing_targets_without_resetting_attempts(): void
    {
        $this->seedEnrichment();
        DB::table('settings')->where('name', 'obfuscation_recovery_enrichment_release_mib')->update(['value' => 1]);
        $target = DB::table('obfuscation_recovery_targets')->first();
        DB::table('obfuscation_recovery_targets')->where('id', $target->id)->update(['status' => 'completed', 'outcome' => 'enrichment_limit_reached']);
        DB::table('obfuscation_recovery_work')->where('purpose', 'enrichment')->update(['status' => 'completed', 'result' => 'enrichment_limit_reached']);
        DB::table('releases')->where('id', 1)->update(['haspreview' => 0]);
        $resume = app(RecoveryEnrichmentResume::class);
        $this->assertFalse($resume->step());
        DB::table('settings')->where('name', 'obfuscation_recovery_enrichment_release_mib')->update(['value' => 4]);
        $this->assertTrue($resume->step());
        $this->assertSame('pending', DB::table('obfuscation_recovery_targets')->value('status'));
        $this->assertSame('pending', DB::table('obfuscation_recovery_work')->where('purpose', 'enrichment')->value('status'));
        $this->assertSame(-1, (int) DB::table('releases')->value('haspreview'));
        $this->assertSame(1, DB::table('obfuscation_recovery_targets')->count());
        $owner = DB::table('obfuscation_recovery_bundles')->value('owner_digest');
        foreach ([1, 2] as $_) {
            $reservation = app(RecoveryBudget::class)->reserve($owner, 'enrichment', $target->message_id, 2097152, 4194304);
            $this->assertNotNull($reservation);
            app(RecoveryBudget::class)->settle($reservation, 100, 65536, 'transport_failure');
        }
        DB::table('obfuscation_recovery_targets')->where('id', $target->id)->update(['status' => 'completed', 'outcome' => 'enrichment_limit_reached']);
        DB::table('obfuscation_recovery_work')->where('purpose', 'enrichment')->update(['status' => 'completed', 'result' => 'enrichment_limit_reached']);
        $this->assertFalse($resume->step());
        $this->assertSame(2, DB::table('obfuscation_recovery_attempts')->count());
        $this->assertSame(131272, app(RecoveryBudget::class)->spent($owner, 'enrichment'));
    }

    #[DataProvider('frontierRaces')]
    public function test_frontier_replacement_rechecks_mariadb_ownership_after_transport(string $race): void
    {
        [$headers, $context, $bundle] = $this->seedFrontier($race !== 'retention' && $race !== 'membership');
        $this->assertSame(1, app(RecoveryFrontierRebuild::class)->step()['frontier_rebuild_pending']);
        $worker = $this->supervisor();
        $this->await(fn (): bool => count($this->events('request')) === 1, 10);
        match ($race) {
            'claim' => DB::table('obfuscation_recovery_work')->where('purpose', 'frontier_rebuild')->update(['claim_token' => (string) Str::uuid()]),
            'generation' => DB::table('obfuscation_recovery_controls')->where('scope', 'group:1')->increment('generation'),
            'epoch' => DB::table('obfuscation_recovery_controls')->where('scope', 'primary')->update(['epoch' => (string) Str::uuid()]),
            'disabled' => DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 0]),
            'revision' => DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->increment('revision'),
            'retention' => app(RecoveryRetention::class)->purge(RecoveryConfig::fromValues(['obfuscation_recovery_retention_hours' => 1])),
            'membership' => $this->changeFrontierMembership($headers, $context),
        };
        $worker->wait();
        $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
        $scope = RecoveryPositiveCoverage::scope($context->sourceEpoch, 1, $context->generation);
        $this->assertSame(0, DB::table('obfuscation_recovery_frontier_ranges')->where('scope_digest', $scope)->where('first_article', 1)->where('last_article', 300)->count());
        $this->assertGreaterThan(0, DB::table('obfuscation_recovery_frontier_conflicts')->where('scope_digest', $scope)->count());
        $this->assertSame(1, DB::table('obfuscation_recovery_attempts')->count());
        $retained = DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->first();
        if ($bundle->sealed_plan !== null) {
            $this->assertSame($bundle->sealed_plan, $retained->sealed_plan);
            $this->assertFalse((new RecoveryPublicationCoverage)->ready($retained));
        }
        if ($race === 'epoch') {
            app(RecoveryFrontierRebuild::class)->step();
            app(RecoveryFrontierRebuild::class)->step();
            $this->assertSame('frontier_source_epoch_unavailable', DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->value('reason'));
        }
    }

    public static function frontierRaces(): array
    {
        return [['claim'], ['generation'], ['epoch'], ['disabled'], ['revision'], ['retention'], ['membership']];
    }

    public function test_sealed_repair_resumes_after_request_expiry_without_resetting_spend(): void
    {
        [, , $bundle] = $this->seedFrontier(true);
        $planner = app(RecoveryFrontierRebuild::class);
        $planner->step();
        $request = DB::table('obfuscation_recovery_frontier_requests')->first();
        $this->assertNotNull($request);
        DB::table('obfuscation_recovery_frontier_requests')->where('id', $request->id)->update(['expires_at' => now()->subHour(), 'outcome' => 'expired_unresolved']);
        $planner->step();
        $planner->step();
        $worker = $this->supervisor();
        $worker->wait();
        $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
        $this->assertSame($request->budget_owner, DB::table('obfuscation_recovery_frontier_requests')->value('budget_owner'));
        $this->assertSame(1, DB::table('obfuscation_recovery_attempts')->count());
        $retained = DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->first();
        $this->assertSame($bundle->sealed_plan, $retained->sealed_plan);
        $this->assertTrue((new RecoveryPublicationCoverage)->ready($retained));
    }

    #[DataProvider('memberContradictions')]
    public function test_rebuild_checks_actual_candidate_members_when_summaries_and_raw_headers_are_gone(string $change): void
    {
        [$headers, $context, $bundle] = $this->seedFrontier(true);
        DB::table('obfuscation_recovery_frontiers')->whereBetween('article_number', [110, 190])->delete();
        $rows = DB::table('obfuscation_recovery_headers')->get();
        foreach ($rows as $row) {
            DB::table('obfuscation_recovery_expired_headers')->insert(['source_epoch' => $row->source_epoch, 'groups_id' => $row->groups_id,
                'message_id_digest' => $row->message_id_digest, 'first_observed_at' => $row->first_observed_at]);
        }
        DB::table('obfuscation_recovery_headers')->delete();
        if ($change === 'date') {
            $headers[2]['Date'] = 'invalid date';
        } else {
            $headers[2]['Subject'] = 'unrelated neutral subject';
        }
        app(RecoveryFrontierRebuild::class)->step();
        $claim = app(RecoveryWork::class)->claim(RecoveryStage::Download);
        $this->assertNotNull($claim);
        $replay = new RecoveryScanContext(1, $context->groupName, $context->sourceEpoch, 2,
            1, 300, HeaderScanDirection::Repair, (string) Str::uuid());
        $report = (new RecoveryCapture(RecoveryConfig::fromSettings(), new NeverBlacklistedService))
            ->capture(new RecoveryCaptureBatch($headers, []), $replay, $claim);
        $this->assertFalse($report->coverageComplete);
        $this->assertSame(0, DB::table('obfuscation_recovery_headers')->count());
        $this->assertSame(1, DB::table('obfuscation_recovery_frontier_conflicts')->where('kind', 'unknown')->count());
        $retained = DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->first();
        $this->assertSame((int) $bundle->revision + 1, (int) $retained->revision);
        $this->assertNull($retained->sealed_plan);
        $this->assertFalse((new RecoveryPublicationCoverage)->ready($retained));
    }

    public static function memberContradictions(): array
    {
        return [['date'], ['identity']];
    }

    public function test_frontier_spend_survives_generation_changes_and_wider_overlapping_ranges(): void
    {
        [, $context, $bundle] = $this->seedFrontier(true);
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $planner = app(RecoveryFrontierRebuild::class);
            $planner->step();
            $planner->step();
            $worker = $this->supervisor();
            $this->await(fn (): bool => count($this->events('request')) === $attempt, 10);
            DB::table('obfuscation_recovery_controls')->where('scope', 'group:1')->increment('generation');
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
        }
        DB::transaction(fn () => (new RecoveryPositiveCoverage)->record(DB::connection(),
            new RecoveryScanContext(1, $context->groupName, $context->sourceEpoch, $context->generation,
                1, 600, HeaderScanDirection::Head, (string) Str::uuid())));
        DB::table('obfuscation_recovery_scan_windows')->where('scan_id', $context->scanId)->update(['requested_last' => 600]);
        for ($cycle = 0; $cycle < 6; $cycle++) {
            app(RecoveryFrontierRebuild::class)->step();
            $worker = $this->supervisor();
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
        }
        $this->assertCount(2, $this->events('request'));
        $this->assertSame(2, DB::table('obfuscation_recovery_attempts')->count());
        $this->assertSame([1, 2], DB::table('obfuscation_recovery_attempts')->orderBy('id')->pluck('physical_attempt')->map(intval(...))->all());
        $this->assertSame(1, DB::table('obfuscation_recovery_frontier_requests')->distinct()->count('budget_owner'));
        $this->assertSame(1, DB::table('obfuscation_recovery_budgets')->where('purpose', 'frontier_rebuild')->count());
        $this->assertSame('frontier_limit_reached', DB::table('obfuscation_recovery_frontier_requests')->where('requested_last', 600)->value('outcome'));
        $retained = DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->first();
        $this->assertSame($bundle->sealed_plan, $retained->sealed_plan);
        $this->assertFalse((new RecoveryPublicationCoverage)->ready($retained));
    }

    public function test_frontier_scheduler_uses_bounded_actual_reads_with_irrelevant_legacy_history(): void
    {
        [, $context] = $this->seedFrontier(false);
        $scope = RecoveryPositiveCoverage::scope($context->sourceEpoch, 1, $context->generation);
        for ($page = 0; $page < 10; $page++) {
            $rows = [];
            for ($i = 0; $i < 1000; $i++) {
                $first = ($page < 5 ? 10000 : 1000000000) + ($page * 1000 + $i) * 20000;
                $rows[] = ['identity' => hash('sha256', 'irrelevant:'.$first), 'scope_digest' => $scope,
                    'kind' => 'unknown', 'first_article' => $first, 'last_article' => $first + 19999];
            }
            DB::table('obfuscation_recovery_frontier_conflicts')->insert($rows);
        }
        DB::table('obfuscation_recovery_frontier_conflicts')->insert([
            'identity' => hash('sha256', 'middle'), 'scope_digest' => $scope, 'kind' => 'unknown',
            'first_article' => 500000000, 'last_article' => 500000300,
        ]);
        DB::transaction(fn () => (new RecoveryFrontiers)->saveRange(DB::connection(), $scope, 500000000, 500000300, [], true, true));
        $before = $this->frontierReads();
        app(RecoveryFrontierRebuild::class)->step();
        $reads = $this->frontierReads() - $before;
        $this->assertLessThan(2000, $reads, 'Scheduler examined irrelevant historical conflict rows.');
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(10001, DB::table('obfuscation_recovery_frontier_conflicts')->count());
    }

    private function frontierReads(): int
    {
        return array_sum(array_map(static fn (object $row): int => (int) $row->Value,
            DB::select("SHOW SESSION STATUS WHERE Variable_name IN ('Handler_read_next', 'Handler_read_prev', 'Handler_read_rnd_next', 'Handler_read_key')")));
    }

    #[Group('frontier-scale')]
    public function test_frontier_slices_remain_bounded_at_one_hundred_thousand_and_one_million_legacy_rows(): void
    {
        [, $context] = $this->seedFrontier(false);
        $scope = RecoveryPositiveCoverage::scope($context->sourceEpoch, 1, $context->generation);
        $template = (array) DB::table('obfuscation_recovery_scans')->first();
        unset($template['id']);
        $inserted = 0;
        foreach ([100000, 1000000] as $population) {
            while ($inserted < $population) {
                $scans = $conflicts = [];
                for ($i = 0; $i < 1000; $i++, $inserted++) {
                    $first = ($inserted % 2 === 0 ? 1000000 : 1000000000000) + intdiv($inserted, 2) * 20000;
                    $conflicts[] = ['identity' => hash('sha256', 'scale:'.$inserted), 'scope_digest' => $scope,
                        'kind' => 'unknown', 'first_article' => $first, 'last_article' => $first + 19999];
                    $scans[] = [...$template, 'scan_id' => sprintf('00000000-0000-4000-8000-%012x', $inserted),
                        'requested_first' => $first, 'requested_last' => $first + 19999];
                }
                DB::table('obfuscation_recovery_scans')->insert($scans);
                DB::table('obfuscation_recovery_frontier_conflicts')->insert($conflicts);
            }
            DB::table('obfuscation_recovery_frontier_conflicts')->insert([
                'identity' => hash('sha256', 'middle:'.$population), 'scope_digest' => $scope, 'kind' => 'unknown',
                'first_article' => 500000000000, 'last_article' => 500000000300,
            ]);
            DB::transaction(fn () => (new RecoveryFrontiers)->saveRange(DB::connection(),
                $scope, 500000000000, 500000000300, [], true, true));
            $reads = [];
            for ($slice = 0; $slice < 4; $slice++) {
                $before = $this->frontierReads();
                app(RecoveryScheduler::class)->local(RecoveryStage::Discover, 1, 10);
                $reads[] = $this->frontierReads() - $before;
                $this->assertLessThan(2000, $reads[$slice]);
                $this->assertSame(0, DB::transactionLevel());
            }
            fwrite(STDOUT, json_encode(['legacy_population' => $population, 'slice_reads' => $reads], JSON_THROW_ON_ERROR)."\n");
            $this->assertSame(1, DB::table('obfuscation_recovery_frontier_requests')->count());
            $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
        }
    }

    public function test_differing_overlapping_ranges_defer_while_their_shared_attempt_is_running(): void
    {
        [, , $bundle] = $this->seedFrontier(false, 3);
        $this->settingsThreads(2);
        $planner = app(RecoveryFrontierRebuild::class);
        $planner->step();
        $worker = $this->supervisor();
        $this->await(fn (): bool => count($this->events('request')) === 1, 10);
        DB::table('obfuscation_recovery_scan_windows')->update(['requested_last' => 600]);
        $planner->step();
        $planner->step();
        $claim = app(RecoveryWork::class)->claim(RecoveryStage::Download);
        $this->assertNotNull($claim);
        $this->assertSame(600, $claim->payload['last']);
        $provider = NntpProvider::fromConfig($this->providers[0]);
        $this->app->instance(BlacklistService::class, new NeverBlacklistedService);
        $this->assertSame('range_pending', app(RecoveryDownload::class)->run($claim, [$provider]));
        $worker->wait();
        $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
        DB::table('obfuscation_recovery_work')->where('id', $claim->id)->update(['due_at' => now()]);
        $next = app(RecoveryWork::class)->claim(RecoveryStage::Download);
        $this->assertNotNull($next);
        $this->assertSame('frontier_rebuilt', app(RecoveryDownload::class)->run($next, [$provider]));
        $this->assertCount(2, $this->events('request'));
        $this->assertSame(1, DB::table('obfuscation_recovery_budgets')->where('purpose', 'frontier_rebuild')->count());
        $this->assertSame(2, DB::table('obfuscation_recovery_attempts')->count());
        $this->assertSame($bundle->revision, DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->value('revision'));
    }

    public function test_overlapping_frontier_workers_share_one_request_and_accept_concurrent_normal_capture(): void
    {
        [$headers, $context, $bundle] = $this->seedFrontier(false);
        $duplicate = (array) $bundle;
        unset($duplicate['id']);
        $duplicate['owner_digest'] = hash('sha256', 'overlapping-candidate');
        DB::table('obfuscation_recovery_bundles')->insert($duplicate);
        $this->assertSame(2, app(RecoveryFrontierRebuild::class)->step()['frontier_rebuild_pending']);
        $this->settingsThreads(3);
        $workers = [$this->supervisor(), $this->supervisor()];
        $this->await(fn (): bool => count($this->events('request')) === 1, 10);
        $policy = new NeverBlacklistedService;
        $parsed = (new HeaderParser($policy))->parse($headers, 'alt.binaries.fixture');
        $replay = new RecoveryScanContext(1, $context->groupName, $context->sourceEpoch, $context->generation,
            1, 300, HeaderScanDirection::Head, (string) Str::uuid());
        $this->assertTrue((new RecoveryCapture(RecoveryConfig::fromSettings(), $policy))
            ->capture(new RecoveryCaptureBatch($headers, $parsed['headers']), $replay)->coverageComplete);
        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
        }
        $this->assertCount(1, $this->events('request'));
        $this->assertSame(1, DB::table('obfuscation_recovery_attempts')->count());
        $this->assertSame(1, DB::table('obfuscation_recovery_frontier_requests')->count());
        $this->assertSame(2, DB::table('obfuscation_recovery_frontier_targets')->count());
        $this->assertSame(0, DB::table('obfuscation_recovery_frontier_conflicts')->count());
        $this->assertSame($bundle->membership_changed_at, DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->value('membership_changed_at'));
    }

    private function seedFrontier(bool $sealed, float $delay = 0.75): array
    {
        Schema::create('binaryblacklist', function (Blueprint $table): void {
            $table->id();
            foreach (['optype', 'status', 'description', 'groupname', 'regex', 'msgcol', 'last_activity'] as $name) {
                $table->string($name)->nullable();
            }
        });
        DB::table('usenet_groups')->where('id', 1)->update(['obfuscation_recovery_profile' => 'media']);
        $bytes = "\x1a\x45\xdf\xa3\x8b\x42\x82\x88matroska".SyntheticPosting::bytes('media', 2250400 - 16);
        $fixture = MediaPostingFixture::make(['fixture.mkv' => $bytes], now('UTC')->subHours(6)->toIso8601String());
        $headers = $fixture['headers'];
        foreach ([1, 110, 120, 130, 180, 190, 300] as $ordinal => $number) {
            $headers[$ordinal]['Number'] = (string) $number;
        }
        $this->server(1, false, $delay, $headers);
        $this->travel(-6)->hours();
        $context = (new RecoveryControl)->begin(RecoveryConfig::fromSettings(), NntpProvider::fromConfig($this->providers[0]), 1, 'alt.binaries.fixture', 1, 300, HeaderScanDirection::Head, 1);
        $policy = new NeverBlacklistedService;
        $parsed = (new HeaderParser($policy))->parse($headers, 'alt.binaries.fixture');
        $this->assertTrue((new RecoveryCapture(RecoveryConfig::fromSettings(), $policy))
            ->capture(new RecoveryCaptureBatch($headers, $parsed['headers']), $context)->coverageComplete);
        $this->travelBack();
        while (app(RecoveryRunRefresh::class)->step() !== null) {
        }
        while (app(RecoveryBundleRefresh::class)->step() !== null) {
        }
        if ($sealed) {
            foreach ($fixture['cache'] as $id => $article) {
                app(RecoveryEvidence::class)->store($id, $article);
            }
            $claim = app(RecoveryWork::class)->claim(RecoveryStage::Discover);
            $this->assertSame('ready', app(RecoveryPreparation::class)->run($claim));
            DB::table('obfuscation_recovery_controls')->where('scope', 'group:1')->increment('generation');
        }
        DB::table('obfuscation_recovery_frontier_ranges')->delete();
        DB::table('obfuscation_recovery_frontiers')->where('article_number', 1)->delete();
        DB::table('obfuscation_recovery_scans')->update(['evidence_version' => 1, 'date_order_consistent' => false, 'date_points' => null]);
        DB::table('obfuscation_recovery_frontier_conflicts')->insert([
            'identity' => hash('sha256', 'legacy'), 'scope_digest' => RecoveryPositiveCoverage::scope($context->sourceEpoch, 1, $context->generation),
            'kind' => 'unknown', 'first_article' => 1, 'last_article' => 300,
        ]);

        return [$headers, $context, DB::table('obfuscation_recovery_bundles')->where('kind', 'posting')->first()];
    }

    private function changeFrontierMembership(array $headers, RecoveryScanContext $context): void
    {
        $header = $headers[1];
        $header['Number'] = '140';
        $header['Message-ID'] = str_replace('<', '<new-', $header['Message-ID']);
        $policy = new NeverBlacklistedService;
        $parsed = (new HeaderParser($policy))->parse([$header], 'alt.binaries.fixture');
        $changed = new RecoveryScanContext(1, $context->groupName, $context->sourceEpoch, $context->generation,
            140, 140, HeaderScanDirection::Head, (string) Str::uuid());
        (new RecoveryCapture(RecoveryConfig::fromSettings(), $policy))
            ->capture(new RecoveryCaptureBatch([$header], $parsed['headers']), $changed);
    }

    private function seedEnrichment(): void
    {
        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid');
            $table->timestamp('recovery_claimed_at')->nullable();
            $table->uuid('recovery_claim_token')->nullable();
            $table->timestamp('additional_pp_claimed_at')->nullable();
            $table->uuid('additional_pp_claim_token')->nullable();
            $table->integer('haspreview')->default(-1);
            $table->integer('nfostatus')->default(-1);
        });
        $work = app(RecoveryWork::class);
        $seed = $work->enqueue(RecoveryStage::Discover, 'enrichment', 1, 'prepare', []);
        $bundle = (int) DB::table('obfuscation_recovery_work')->where('id', $seed)->value('bundle_id');
        DB::table('obfuscation_recovery_work')->where('id', $seed)->update(['status' => 'completed']);
        DB::table('obfuscation_recovery_bundles')->where('id', $bundle)->update(['groups_id' => 1, 'profile' => RecoveryAlgorithm::Media->value]);
        $file = new RecoveryFilePlan(md5('enrichment'), RecoveryFileRole::Media,
            1433604, 3, 'fixture.mkv', 'mkv');
        $index = new RecoveryFilePlan('enrichment-index@fixture', RecoveryFileRole::Index,
            4, 1, 'index.par2', 'par2');
        $plan = new RecoveryPlan(RecoveryAlgorithm::Media, $bundle, 1, 'alt.binaries.fixture', 'epoch',
            str_repeat('a', 32), [$file, $index], str_repeat('b', 64));
        $publication = app(RecoveryPublications::class)->register($plan);
        $records = [];
        foreach ([$file, $index] as $entry) {
            for ($i = 1; $i <= $entry->totalParts; $i++) {
                $records[$entry->identity][] = ['file' => $entry->identity, 'role' => $entry->role->value, 'ordinal' => $i,
                    'group' => $plan->group, 'message_id' => $entry === $file ? 'enrichment-'.$i.'@fixture' : $index->identity];
            }
        }
        DB::table('obfuscation_recovery_publications')->where('id', $publication->id)->update([
            'state' => 'published', 'releases_id' => 1, 'guid' => 'fixture', 'head_membership' => json_encode($records, JSON_THROW_ON_ERROR),
        ]);
        DB::table('releases')->insert(['id' => 1, 'guid' => 'fixture']);
        DB::table('obfuscation_recovery_files')->insert(['bundle_id' => $bundle, 'revision' => 1, 'groups_id' => 1,
            'profile' => RecoveryAlgorithm::Media->value, 'run_digest' => str_repeat('c', 64), 'observed_count' => 3, 'start_ms' => 1, 'end_ms' => 3,
            'state' => 'ready', 'role' => $file->role->value, 'file_id' => $file->identity]);
        $this->assertTrue(app(RecoveryHeads::class)->read(1, $file->identity)->pending());
    }

    private function ordinaryProgress(): void
    {
        $this->assertGreaterThan(count($this->events('close')), count($this->events('open')));
        $service = new HeaderStorageService(
            new CollectionHandler(new class extends CollectionsCleaningService
            {
                public function collectionsCleaner(string $subject, string $groupName = ''): array
                {
                    return ['id' => 0, 'name' => $subject];
                }
            }),
            config: new BinariesConfig(headerChunkSize: 2, sqlChunkSize: 2),
        );
        $headers = [];
        foreach ([1, 2] as $part) {
            $headers[] = ['Number' => 1000 + $part, 'Subject' => "Ordinary.Fixture ({$part}/2)",
                'From' => 'fixture@example.invalid', 'Date' => time(), 'Bytes' => 150,
                'Message-ID' => '<ordinary-'.$part.'@fixture>', 'Xref' => '',
                'matches' => ["Ordinary.Fixture ({$part}/2)", 'Ordinary.Fixture', $part, 2]];
        }
        $started = hrtime(true);
        $report = $service->store($headers, ['id' => 1, 'name' => 'alt.binaries.fixture'], false);
        $this->assertSame([], $report->uniqueFailedNumbers());
        $this->assertLessThan(5000000000, hrtime(true) - $started);
        $this->assertSame(2, DB::table('parts')->count());
        $this->assertSame(300, (int) DB::table('collections')->value('filesize'));
    }

    private function settingsThreads(int $threads): void
    {
        DB::table('settings')->where('name', 'obfuscation_recovery_threads')->update(['value' => $threads]);
    }

    private function seedConstruction(int $count): void
    {
        $work = app(RecoveryWork::class);
        for ($i = 0; $i < $count; $i++) {
            $id = $work->enqueue(RecoveryStage::Download, 'construction-'.$i, 1, 'index', ['message_id' => 'target-'.$i.'@fixture']);
            $bundle = DB::table('obfuscation_recovery_work')->where('id', $id)->value('bundle_id');
            DB::table('obfuscation_recovery_bundles')->where('id', $bundle)->update(['groups_id' => 1,
                'profile' => ($i % 2 === 0 ? RecoveryAlgorithm::Media : RecoveryAlgorithm::Rar)->value]);
            $claim = $work->claim(RecoveryStage::Download);
            (new RecoveryConstructionTargets)->register($claim, [['kind' => 'index', 'file_id' => null, 'message_id' => 'target-'.$i.'@fixture']]);
            $work->defer($claim);
        }
        DB::table('obfuscation_recovery_work')->update(['due_at' => now()->subSecond()]);
    }

    private function server(int $position, bool $fail, float $delay, ?array $overviewHeaders = null): void
    {
        $root = $this->root.'/provider-'.$position;
        mkdir($root, 0700);
        $body = $root.'/body';
        file_put_contents($body, "=ybegin line=128 size=4 name=opaque\r\naaaa\r\n=yend size=4\r\n");
        $articles = [];
        for ($i = 0; $i < 8; $i++) {
            $articles['target-'.$i.'@fixture'] = ['body' => $body, 'header' => ['Number' => $i + 1]];
        }
        $enrichmentBody = $root.'/enrichment-body';
        file_put_contents($enrichmentBody, "=ybegin part=1 total=3 line=128 size=1433604 name=opaque\r\n=ypart begin=1 end=716800\r\n".
            str_repeat(str_repeat('a', 128)."\r\n", 5600)."=yend size=716800 part=1\r\n");
        $articles['enrichment-1@fixture'] = ['body' => $enrichmentBody, 'header' => ['Number' => 9]];
        if ($overviewHeaders !== null) {
            $articles = [];
            foreach ($overviewHeaders as $header) {
                $articles[trim($header['Message-ID'], '<>')] = ['body' => $body, 'header' => $header];
            }
        }
        file_put_contents($root.'/articles.json', json_encode($articles, JSON_THROW_ON_ERROR));
        $server = new Process(['python3', base_path('tests/Support/ObfuscationRecovery/fixture_nntp.py'), $root,
            '--delay', (string) $delay, '--chunk-size', '1048576', ...($fail ? ['--fail-body'] : [])]);
        $server->setTimeout(180)->start();
        $this->servers[$position] = $server;
        $this->await(fn (): bool => is_file($root.'/server-port'), 5);
        $this->providers[] = ['position' => $position, 'name' => 'fixture-'.$position, 'host' => '127.0.0.1',
            'port' => (int) file_get_contents($root.'/server-port'), 'ssl' => false, 'username' => '', 'password' => '', 'connections' => 10, 'timeout' => 10, 'enabled' => true];
        config(['nntmux_nntp.providers' => $this->providers]);
    }

    private function supervisor(bool $engine = false): Process
    {
        $cache = $this->root.'/config-'.count($this->children).'.php';
        file_put_contents($cache, '<?php return '.var_export(config()->all(), true).';');
        chmod($cache, 0600);
        $env = ['APP_CONFIG_CACHE' => $cache, 'APP_ENV' => 'testing'];
        foreach (['ROUTES', 'EVENTS', 'PACKAGES', 'SERVICES'] as $kind) {
            $env['APP_'.$kind.'_CACHE'] = $this->root.'/'.strtolower($kind).'.php';
        }
        $process = new Process([PHP_BINARY, base_path('artisan'), 'obfuscation:download', ...($engine ? ['--engine'] : [])], base_path(), $env, timeout: 60);
        $process->start();
        $this->children[] = $process;

        return $process;
    }

    private function await(callable $condition, int $seconds): void
    {
        $deadline = hrtime(true) + $seconds * 1000000000;
        while (! $condition() && hrtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertTrue($condition(), implode("\n", array_map(fn (Process $child): string => $child->getErrorOutput().$child->getOutput(), $this->children)).json_encode(['events' => $this->events('request'), 'work' => DB::table('obfuscation_recovery_work')->get(['status', 'result', 'purpose']), 'attempts' => DB::table('obfuscation_recovery_attempts')->get(['outcome', 'failure_phase'])], JSON_THROW_ON_ERROR));
    }

    private function events(string $kind, ?int $provider = null): array
    {
        $events = [];
        foreach ($provider === null ? array_keys($this->servers) : [$provider] as $position) {
            $path = $this->root.'/provider-'.$position.'/server-events.jsonl';
            foreach (is_file($path) ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
                $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if ($event['kind'] === $kind) {
                    $events[] = $event;
                }
            }
        }

        return $events;
    }

    private function peak(): int
    {
        $events = [...$this->events('open'), ...$this->events('close')];
        usort($events, fn (array $a, array $b): int => $a['time'] <=> $b['time']);
        $active = $peak = 0;
        foreach ($events as $event) {
            $active += $event['kind'] === 'open' ? 1 : -1;
            $peak = max($peak, $active);
        }

        return $peak;
    }
}
