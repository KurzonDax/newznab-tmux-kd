<?php

declare(strict_types=1);

namespace Tests\Support\ObfuscationRecovery;

use App\Enums\HeaderScanDirection;
use App\Services\BlacklistService;
use App\Services\NNTP\NntpProvider;
use App\Services\ObfuscationRecovery\RecoveryAlgorithm;
use App\Services\ObfuscationRecovery\RecoveryBudget;
use App\Services\ObfuscationRecovery\RecoveryCapture;
use App\Services\ObfuscationRecovery\RecoveryCaptureBatch;
use App\Services\ObfuscationRecovery\RecoveryConfig;
use App\Services\ObfuscationRecovery\RecoveryDownload;
use App\Services\ObfuscationRecovery\RecoveryFrontierEvidence;
use App\Services\ObfuscationRecovery\RecoveryFrontierRebuild;
use App\Services\ObfuscationRecovery\RecoveryFrontiers;
use App\Services\ObfuscationRecovery\RecoveryIdentity;
use App\Services\ObfuscationRecovery\RecoveryPositiveCoverage;
use App\Services\ObfuscationRecovery\RecoveryScanContext;
use App\Services\ObfuscationRecovery\RecoverySettlement;
use App\Services\ObfuscationRecovery\RecoverySlots;
use App\Services\ObfuscationRecovery\RecoveryStage;
use App\Services\ObfuscationRecovery\RecoveryTransfer;
use App\Services\ObfuscationRecovery\RecoveryWork;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NeverBlacklistedService;

trait ChecksRetainedFrontierRebuild
{
    use InteractsWithRecoveryNntpServer;

    private function sourceEpoch(): string
    {
        return DB::getDriverName() === 'sqlite' ? 'fixture' : '00000000-0000-4000-8000-000000000553';
    }

    protected function seedRetainedFrontierFixture(): void
    {
        $this->travelTo(Carbon::parse('2026-09-13 15:00:00', 'UTC'));
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
        (require database_path('migrations/2026_09_13_002751_add_recovery_frontier_evidence.php'))->up();
        (require database_path('migrations/2026_09_13_155226_add_recovery_frontier_repair_allowances.php'))->up();
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.fixture', 'obfuscation_recovery_profile' => 'media']);
        $provider = NntpProvider::fromConfig(['position' => 1, 'name' => $this->sourceEpoch(), 'host' => '127.0.0.1', 'port' => 1]);
        $fingerprint = (new RecoveryIdentity)->digest([$provider->host, (string) $provider->port, (string) $provider->ssl, $provider->username]);
        foreach (['primary', 'group:1'] as $scope) {
            DB::table('obfuscation_recovery_controls')->insert(['scope' => $scope, 'epoch' => $this->sourceEpoch(), 'generation' => 1,
                'fingerprint' => $fingerprint, 'updated_at' => now()]);
        }
    }

    #[DataProvider('retainedStates')]
    public function test_satisfied_left_witness_does_not_preempt_required_right_repair(bool $retained): void
    {
        $bundle = $this->candidate(60001, 60010);
        $this->coverage(1, 100000);
        foreach ([1, 20001, 40001, 60001, 80001] as $first) {
            $this->window($first, $first + 19999);
        }
        $scope = RecoveryPositiveCoverage::scope($this->sourceEpoch(), 1, 1);
        $frontiers = new RecoveryFrontiers;
        $frontiers->savePoints(DB::connection(), $scope, [[50000, '2026-09-13 09:50:00'], [90000, '2026-09-13 14:10:00']], true);
        $frontiers->saveRange(DB::connection(), $scope, 40001, 80000, [], true, true);
        $this->assertSame('ready', $this->assessment($bundle));
        DB::table('obfuscation_recovery_frontier_conflicts')->insert(['identity' => hash('sha256', 'legacy'),
            'scope_digest' => $scope, 'kind' => 'unknown', 'first_article' => 85000, 'last_article' => 85000]);
        $this->assertSame('frontier_rebuild_required', $this->assessment($bundle));
        if ($retained) {
            $this->terminalRequest($bundle, 20001, 40000);
            DB::table('obfuscation_recovery_frontier_progress')->insert([
                'scope' => (new RecoveryIdentity)->digest(['frontier-progress', (string) $bundle->id, '1', 'left']), 'cursor' => 40000,
            ]);
        }
        for ($visit = 0; $visit < 8; $visit++) {
            (new RecoveryFrontierRebuild)->step();
        }
        $this->assertSame($retained ? [[20001, 40000], [80001, 100000]] : [[80001, 100000]], DB::table('obfuscation_recovery_frontier_requests')->orderBy('id')->get()
            ->map(static fn (object $row): array => [(int) $row->requested_first, (int) $row->requested_last])->all());
        DB::disconnect();
        $provider = $this->server('', dialogue: [
            "GROUP alt.binaries.fixture\r\n" => "211 1 80001 100000 alt.binaries.fixture\r\n",
            "XOVER 80001-100000\r\n" => "224 overview\r\n90000\tNeutral boundary\tfixture\t2026-09-13 14:10:00 +0000\t<boundary@fixture>\t\t100\t1\r\n.\r\n",
        ]);
        $this->primary($provider);
        $claim = app(RecoveryWork::class)->claim(RecoveryStage::Download);
        $this->assertNotNull($claim);
        $this->app->instance(BlacklistService::class, new NeverBlacklistedService);
        $this->assertSame('frontier_rebuilt', app(RecoveryDownload::class)->run($claim, [$provider]));
        $current = DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->first();
        $this->assertSame($bundle->revision, $current->revision);
        $this->assertSame($bundle->membership_changed_at, $current->membership_changed_at);
        $this->assertSame('ready', $this->assessment($current));

    }

    public static function retainedStates(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('retainedStates')]
    public function test_connected_retained_fragments_are_observed_within_the_normal_tile_allowance(bool $tls): void
    {
        $bundle = $this->candidate(39000, 39001);
        $this->coverage(20001, 40000);
        foreach ([[38339, 40000], [37966, 38338], [20001, 37965]] as [$first, $last]) {
            $this->window($first, $last);
        }
        (new RecoveryFrontierRebuild)->step();
        $this->assertSame([[20001, 40000]], DB::table('obfuscation_recovery_frontier_requests')->get()
            ->map(static fn (object $row): array => [(int) $row->requested_first, (int) $row->requested_last])->all());
        $this->observe($tls);
        $this->assertSame('examined', (new RecoveryFrontierEvidence)->answer(
            DB::connection(), RecoveryPositiveCoverage::scope($this->sourceEpoch(), 1, 1), 20001, 40000, (new RecoveryFrontierRebuild)->envelope($bundle)));
        $this->assertSame($tls ? 33554432 : 66536, (int) DB::table('obfuscation_recovery_budgets')->sum('debited_bytes'));
        for ($visit = 0; $visit < 12; $visit++) {
            (new RecoveryFrontierRebuild)->step();
        }
        $this->assertSame(1, DB::table('obfuscation_recovery_attempts')->count());
        $this->assertNull(app(RecoveryWork::class)->claim(RecoveryStage::Download));
        $this->assertNotSame('ready', $this->assessment($bundle));
    }

    public function test_historical_fragmentation_receives_one_bounded_repair_allowance(): void
    {
        $bundle = $this->candidate(39000, 39001);
        $this->coverage(20001, 40000);
        foreach ([[38339, 40000], [37966, 38338], [20001, 37965]] as [$first, $last]) {
            $this->window($first, $last);
        }
        $owner = $this->fragmentedHistory();
        $before = DB::table('obfuscation_recovery_attempts')->orderBy('id')->get()->toJson();
        (new RecoveryFrontierRebuild)->step();
        $claim = app(RecoveryWork::class)->claim(RecoveryStage::Download);
        $this->assertNotNull($claim);
        $slots = app(RecoverySlots::class);
        $slot = $slots->acquire(RecoveryConfig::fromSettings());
        $provider = NntpProvider::fromConfig(['position' => 1, 'name' => $this->sourceEpoch(), 'host' => '127.0.0.1', 'port' => 1]);
        $budget = app(RecoveryBudget::class);
        $reservation = $budget->reserveGap($claim, $slot, $provider);
        $this->assertNotNull($reservation, 'The remaining connected coverage must receive the historical repair grant.');
        $this->assertSame(1, $reservation->physicalAttempt);
        $this->assertSame($before, DB::table('obfuscation_recovery_attempts')->where('id', '<', $reservation->attemptId)->orderBy('id')->get()->toJson());
        $this->assertSame(1, DB::table('obfuscation_recovery_frontier_allowances')->count());
        $budget->settle($reservation, null, 65536, 'transport_failure');
        $slots->release($slot);
        $slot = $slots->acquire(RecoveryConfig::fromSettings());
        $retry = $budget->reserveGap($claim, $slot, $provider);
        $this->assertNotNull($retry);
        $this->assertSame(2, $retry->physicalAttempt);
        $budget->settle($retry, null, 65536, 'transport_failure');
        $this->assertNull($budget->reserveGap($claim, $slot, $provider));
        $this->assertFalse($budget->frontierPending($claim));
        $this->assertSame(134217728, $budget->spent($owner, RecoveryFrontierRebuild::PURPOSE));
        $slots->release($slot);
        $this->assertNotSame('ready', $this->assessment($bundle));
    }

    #[DataProvider('retainedStates')]
    public function test_historical_success_covers_the_remaining_articles_without_rewriting_old_debits(bool $tls): void
    {
        $bundle = $this->candidate(39000, 39001);
        $this->coverage(20001, 40000);
        foreach ([[38339, 40000], [37966, 38338], [20001, 37965]] as [$first, $last]) {
            $this->window($first, $last);
        }
        $owner = $this->fragmentedHistory($tls);
        $before = DB::table('obfuscation_recovery_attempts')->orderBy('id')->get()->toJson();
        (new RecoveryFrontierRebuild)->step();
        $this->observe($tls);
        $this->assertSame($before, DB::table('obfuscation_recovery_attempts')->where('id', '<=', 2)->orderBy('id')->get()->toJson());
        $this->assertSame('examined', (new RecoveryFrontierEvidence)->answer(
            DB::connection(), RecoveryPositiveCoverage::scope($this->sourceEpoch(), 1, 1), 20001, 40000, (new RecoveryFrontierRebuild)->envelope($bundle)));
        for ($i = 0; $i < 20; $i++) {
            (new RecoveryFrontierRebuild)->step();
        }
        $this->assertSame(3, DB::table('obfuscation_recovery_attempts')->count());
        $this->assertSame($tls ? 100663296 : 199608, app(RecoveryBudget::class)->spent($owner, RecoveryFrontierRebuild::PURPOSE));
        $this->assertNotSame('ready', $this->assessment($bundle));
    }

    #[DataProvider('ineligibleHistories')]
    public function test_only_installed_disjoint_successes_before_cutover_qualify(string $history): void
    {
        $this->candidate(39000, 39001);
        $this->coverage(20001, 40000);
        $this->window(20001, 40000);
        $this->fragmentedHistory();
        $requests = DB::table('obfuscation_recovery_frontier_requests');
        match ($history) {
            'post_cutover' => DB::table('obfuscation_recovery_frontier_policy')->update(['last_attempt_id' => 0, 'last_request_id' => 0]),
            'reused' => (clone $requests)->where('id', 1)->update(['outcome' => 'frontier_reused']),
            'uninstalled' => (clone $requests)->update(['outcome' => 'pending']),
            'failures' => DB::table('obfuscation_recovery_attempts')->update(['outcome' => 'transport_failure']),
            'unsettled' => DB::table('obfuscation_recovery_attempts')->where('id', 1)->update(['settled_at' => null]),
            'full_tile' => (clone $requests)->where('id', 1)->update(['requested_first' => 20001, 'requested_last' => 40000]),
            'overlapping' => (clone $requests)->where('id', 2)->update(['requested_last' => 39000]),
            'late_installation' => (clone $requests)->update(['updated_at' => now()->addSecond()]),
            'other_epoch' => (clone $requests)->where('id', 1)->update(['source_epoch' => '00000000-0000-4000-8000-000000000554']),
        };
        (new RecoveryFrontierRebuild)->step();
        $claim = app(RecoveryWork::class)->claim(RecoveryStage::Download);
        $this->assertNotNull($claim);
        $slots = app(RecoverySlots::class);
        $slot = $slots->acquire(RecoveryConfig::fromSettings());
        $provider = NntpProvider::fromConfig(['position' => 1, 'name' => $this->sourceEpoch(), 'host' => '127.0.0.1', 'port' => 1]);
        $this->assertNull(app(RecoveryBudget::class)->reserveGap($claim, $slot, $provider));
        $this->assertSame(0, DB::table('obfuscation_recovery_frontier_allowances')->count());
        $this->assertSame(2, DB::table('obfuscation_recovery_attempts')->count());
        $slots->release($slot);
    }

    public static function ineligibleHistories(): array
    {
        return array_map(static fn (string $case): array => [$case], ['post_cutover', 'reused', 'uninstalled', 'failures', 'unsettled',
            'full_tile', 'overlapping', 'late_installation', 'other_epoch']);
    }

    public function test_connected_windows_do_not_bridge_a_positive_coverage_hole(): void
    {
        $bundle = $this->candidate(39000, 39001);
        $this->coverage(20001, 30000);
        $this->coverage(30002, 40000);
        $this->window(20001, 40000);
        (new RecoveryFrontierRebuild)->step();
        $request = DB::table('obfuscation_recovery_frontier_requests')->first();
        $this->assertSame([30002, 40000], [(int) $request->requested_first, (int) $request->requested_last]);
        $this->observe(false);
        $this->assertNotSame('ready', $this->assessment($bundle));
    }

    #[DataProvider('staleAuthorizations')]
    public function test_coalesced_interval_is_rechecked_before_reservation(string $change): void
    {
        $bundle = $this->candidate(39000, 39001);
        $this->coverage(20001, 40000);
        foreach ([[20001, 37965], [37966, 38338], [38339, 40000]] as [$first, $last]) {
            $this->window($first, $last);
        }
        (new RecoveryFrontierRebuild)->step();
        $claim = app(RecoveryWork::class)->claim(RecoveryStage::Download);
        $this->assertNotNull($claim);
        match ($change) {
            'retention' => DB::table('obfuscation_recovery_scan_windows')->where('requested_first', 20001)->update(['expires_at' => now()]),
            'revision' => DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->increment('revision'),
            'generation' => DB::table('obfuscation_recovery_controls')->where('scope', 'group:1')->increment('generation'),
            'epoch' => DB::table('obfuscation_recovery_controls')->where('scope', 'primary')->update(['epoch' => '00000000-0000-4000-8000-000000000554']),
            'claim' => DB::table('obfuscation_recovery_work')->where('id', $claim->id)->update(['claim_token' => (string) Str::uuid()]),
            'admission' => DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 0]),
        };
        $slots = app(RecoverySlots::class);
        $slot = $slots->acquire(RecoveryConfig::fromValues(['obfuscation_recovery_enabled' => 1]));
        $provider = NntpProvider::fromConfig(['position' => 1, 'name' => $this->sourceEpoch(), 'host' => '127.0.0.1', 'port' => 1]);
        $this->assertNull(app(RecoveryBudget::class)->reserveGap($claim, $slot, $provider));
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
        $slots->release($slot);
    }

    public static function staleAuthorizations(): array
    {
        return array_map(static fn (string $case): array => [$case], ['retention', 'revision', 'generation', 'epoch', 'claim', 'admission']);
    }

    public function test_many_adjacent_windows_and_overlapping_candidates_share_one_bounded_observation(): void
    {
        $this->coverage(20001, 40000);
        for ($first = 20001; $first < 40000; $first += 50) {
            $this->window($first, $first + 49);
        }
        for ($candidate = 0; $candidate < 20; $candidate++) {
            $this->candidate(39000 + $candidate * 2, 39001 + $candidate * 2);
        }
        DB::enableQueryLog();
        (new RecoveryFrontierRebuild)->step();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThan(256, count($queries));
        $this->assertLessThan(12, count(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'obfuscation_recovery_scan_windows'))));
        $this->observe(false);
        for ($visit = 0; $visit < 60; $visit++) {
            (new RecoveryFrontierRebuild)->step();
        }
        $this->assertSame(1, DB::table('obfuscation_recovery_frontier_requests')->count());
        $this->assertSame(1, DB::table('obfuscation_recovery_attempts')->count());
    }

    public function test_migration_and_restarts_do_not_renew_the_cutover_or_spent_allowance(): void
    {
        $this->test_historical_fragmentation_receives_one_bounded_repair_allowance();
        $policy = DB::table('obfuscation_recovery_frontier_policy')->first();
        $grants = DB::table('obfuscation_recovery_frontier_allowances')->get()->toJson();
        $attempts = DB::table('obfuscation_recovery_attempts')->get()->toJson();
        $owner = DB::table('obfuscation_recovery_frontier_requests')->value('budget_owner');
        $this->travel(1)->hours();
        $migration = require database_path('migrations/2026_09_13_155226_add_recovery_frontier_repair_allowances.php');
        $migration->down();
        $migration->up();
        $migration->up();
        DB::table('obfuscation_recovery_frontier_progress')->delete();
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 0]);
        (new RecoveryFrontierRebuild)->step();
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        DB::table('obfuscation_recovery_controls')->where('scope', 'group:1')->increment('generation');
        DB::table('obfuscation_recovery_bundles')->where('kind', 'posting')->increment('revision');
        $this->assertNull((new RecoveryBudget(new RecoveryIdentity))->reserve($owner, RecoveryFrontierRebuild::PURPOSE, 'alternate-interval', 33554432, 134217728));
        $this->assertEquals($policy, DB::table('obfuscation_recovery_frontier_policy')->first());
        $this->assertSame($grants, DB::table('obfuscation_recovery_frontier_allowances')->get()->toJson());
        $this->assertSame($attempts, DB::table('obfuscation_recovery_attempts')->get()->toJson());
    }

    #[DataProvider('retainedStates')]
    public function test_historical_worker_uses_the_logical_retry_and_stops_after_two_repair_requests(bool $succeed): void
    {
        $this->candidate(39000, 39001);
        $this->coverage(20001, 40000);
        $this->window(20001, 40000);
        $this->fragmentedHistory();
        (new RecoveryFrontierRebuild)->step();
        $dialogue = ["GROUP alt.binaries.fixture\r\n" => "211 1 20001 40000 alt.binaries.fixture\r\n",
            "XOVER 20001-40000\r\n" => "224 overview\r\n"];
        $second = $dialogue;
        if ($succeed) {
            $second["XOVER 20001-40000\r\n"] .= "20001\tNeutral observation\tfixture\t2026-09-13 12:00:00 +0000\t<neutral@fixture>\t\t100\t1\r\n.\r\n";
        }
        DB::disconnect();
        $provider = $this->server('', conversations: [$dialogue, $second]);
        $this->primary($provider);
        $this->app->instance(BlacklistService::class, new NeverBlacklistedService);
        $work = app(RecoveryWork::class);
        $first = $work->claim(RecoveryStage::Download);
        $this->assertNotNull($first);
        $this->assertSame('retry_pending', app(RecoveryDownload::class)->run($first, [$provider]));
        $this->travel(301)->seconds();
        $retry = $work->claim(RecoveryStage::Download);
        $this->assertNotNull($retry);
        $this->assertSame($succeed ? 'frontier_rebuilt' : 'frontier_unresolved', app(RecoveryDownload::class)->run($retry, [$provider]));
        $this->assertSame([1, 2], DB::table('obfuscation_recovery_attempts')->where('id', '>', 2)->orderBy('id')->pluck('physical_attempt')->map(intval(...))->all());
        $this->assertSame(4, DB::table('obfuscation_recovery_attempts')->count());
        $this->assertNull($work->claim(RecoveryStage::Download));
    }

    public function test_disconnected_components_cannot_mint_more_than_two_normal_requests(): void
    {
        $bundle = $this->candidate(39000, 39001);
        $this->coverage(20001, 40000);
        foreach ([[20001, 22000], [30001, 31000], [39000, 40000]] as [$first, $last]) {
            $this->window($first, $last);
        }
        for ($request = 1; $request <= 2; $request++) {
            for ($visit = 0; $visit < 12 && DB::table('obfuscation_recovery_frontier_requests')->count() < $request; $visit++) {
                (new RecoveryFrontierRebuild)->step();
            }
            $this->observe(false);
        }
        for ($visit = 0; $visit < 12 && DB::table('obfuscation_recovery_frontier_requests')->count() < 3; $visit++) {
            (new RecoveryFrontierRebuild)->step();
        }
        $claim = app(RecoveryWork::class)->claim(RecoveryStage::Download);
        $this->assertNotNull($claim);
        $provider = NntpProvider::fromConfig(['position' => 1, 'name' => 'fixture', 'host' => '127.0.0.1', 'port' => 1]);
        $this->primary($provider);
        $this->assertSame('frontier_limit_reached', app(RecoveryDownload::class)->run($claim, [$provider]));
        $outcomes = [];
        for ($visit = 0; $visit < 12; $visit++) {
            $outcomes += (new RecoveryFrontierRebuild)->step();
        }
        $this->assertArrayHasKey('frontier_required_exhausted', $outcomes);
        $this->assertSame(2, DB::table('obfuscation_recovery_attempts')->count());
        $this->assertSame(0, DB::table('obfuscation_recovery_frontier_allowances')->count());
        $this->assertNotSame('ready', $this->assessment($bundle));
    }

    public function test_an_exhausted_required_left_interval_does_not_prevent_useful_right_work(): void
    {
        $bundle = $this->candidate(60001, 60010);
        $this->coverage(1, 100000);
        foreach ([1, 20001, 40001, 60001, 80001] as $first) {
            $this->window($first, $first + 19999);
        }
        $scope = RecoveryPositiveCoverage::scope($this->sourceEpoch(), 1, 1);
        $frontiers = new RecoveryFrontiers;
        $frontiers->savePoints(DB::connection(), $scope, [[50000, '2026-09-13 09:50:00'], [90000, '2026-09-13 14:10:00']], true);
        $frontiers->saveRange(DB::connection(), $scope, 60001, 80000, [], true, true);
        foreach ([55000, 85000] as $article) {
            DB::table('obfuscation_recovery_frontier_conflicts')->insert(['identity' => hash('sha256', 'legacy-'.$article),
                'scope_digest' => $scope, 'kind' => 'unknown', 'first_article' => $article, 'last_article' => $article]);
        }
        $this->terminalRequest($bundle, 40001, 60000);
        for ($visit = 0; $visit < 8; $visit++) {
            (new RecoveryFrontierRebuild)->step();
        }
        $this->assertSame([[40001, 60000], [80001, 100000]], DB::table('obfuscation_recovery_frontier_requests')->orderBy('id')->get()
            ->map(static fn (object $row): array => [(int) $row->requested_first, (int) $row->requested_last])->all());
        $this->assertSame('frontier_rebuild_required', $this->assessment($bundle));
    }

    public function test_required_legacy_retirement_resumes_before_advancing_past_its_witness(): void
    {
        $bundle = $this->candidate(60001, 60010);
        $this->coverage(1, 100000);
        foreach ([1, 20001, 40001, 60001, 80001] as $first) {
            $this->window($first, $first + 19999);
        }
        $scope = RecoveryPositiveCoverage::scope($this->sourceEpoch(), 1, 1);
        $frontiers = new RecoveryFrontiers;
        for ($range = 0; $range < 32; $range++) {
            $frontiers->saveRange(DB::connection(), $scope, 200001 + $range * 20000, 220000 + $range * 20000, [], true, true);
        }
        $frontiers->savePoints(DB::connection(), $scope, [[50000, '2026-09-13 09:50:00'], [90000, '2026-09-13 14:10:00']], true);
        $frontiers->saveRange(DB::connection(), $scope, 40001, 100000, [], true, true);
        foreach (array_chunk(range(80100, 80549), 100) as $articles) {
            DB::table('obfuscation_recovery_frontier_conflicts')->insert(array_map(static fn (int $article): array => [
                'identity' => hash('sha256', 'legacy-'.$article), 'scope_digest' => $scope,
                'kind' => 'unknown', 'first_article' => $article, 'last_article' => $article,
            ], $articles));
        }
        $this->assertSame('frontier_rebuild_required', $this->assessment($bundle));
        for ($visit = 0; $visit < 20; $visit++) {
            (new RecoveryFrontierRebuild)->step();
        }
        $this->assertSame('ready', $this->assessment($bundle));
        $this->assertSame(0, DB::table('obfuscation_recovery_frontier_conflicts')->count());
        $this->assertSame(0, DB::table('obfuscation_recovery_frontier_requests')->count());
    }

    private function primary(NntpProvider $provider): void
    {
        DB::table('obfuscation_recovery_controls')->where('scope', 'primary')->update([
            'fingerprint' => (new RecoveryIdentity)->digest([$provider->host, (string) $provider->port, (string) $provider->ssl, $provider->username]),
        ]);
    }

    private function terminalRequest(object $bundle, int $first, int $last): void
    {
        $identity = new RecoveryIdentity;
        $partition = intdiv($first - 1, 20000) * 20000 + 1;
        $owner = $identity->digest(['frontier-range', $this->sourceEpoch(), '1', (string) RecoveryFrontiers::VERSION, (string) $partition]);
        $id = DB::table('obfuscation_recovery_bundles')->insertGetId([
            'owner_digest' => $identity->digest([$owner, '1', (string) $first, (string) $last]), 'kind' => 'frontier',
            'groups_id' => 1, 'profile' => RecoveryAlgorithm::Media->value, 'source_epoch' => $this->sourceEpoch(), 'capture_generation' => 1,
            'state' => 'frontier_complete', 'reason' => 'frontier_limit_reached', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $request = DB::table('obfuscation_recovery_frontier_requests')->insertGetId([
            'bundle_id' => $id, 'budget_owner' => $owner, 'groups_id' => 1, 'source_epoch' => $this->sourceEpoch(), 'capture_generation' => 1,
            'evidence_version' => RecoveryFrontiers::VERSION, 'requested_first' => $first, 'requested_last' => $last,
            'outcome' => 'frontier_limit_reached', 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('obfuscation_recovery_frontier_targets')->insert([
            'request_id' => $request, 'bundle_id' => $bundle->id, 'revision' => 1, 'capture_generation' => 1,
            'first_article' => $first, 'last_article' => $last, 'envelope' => json_encode((new RecoveryFrontierRebuild)->envelope($bundle), JSON_THROW_ON_ERROR),
        ]);
        $work = app(RecoveryWork::class)->enqueueForBundle(RecoveryStage::Download, $id, 1, RecoveryFrontierRebuild::PURPOSE,
            ['first' => $first, 'last' => $last, 'version' => RecoveryFrontiers::VERSION]);
        DB::table('obfuscation_recovery_work')->where('id', $work)->update(['status' => 'completed', 'result' => 'frontier_limit_reached']);
        $budget = app(RecoveryBudget::class);
        for ($i = 0; $i < 2; $i++) {
            $reservation = $budget->reserve($owner, RecoveryFrontierRebuild::PURPOSE, $owner, 33554432, 67108864);
            $this->assertNotNull($reservation);
            $budget->settle($reservation, null, 65536, 'transport_failure');
        }
    }

    private function observe(bool $tls): void
    {
        $work = app(RecoveryWork::class);
        $claim = $work->claim(RecoveryStage::Download);
        $this->assertNotNull($claim);
        $slots = app(RecoverySlots::class);
        $slot = $slots->acquire(RecoveryConfig::fromSettings());
        $provider = NntpProvider::fromConfig(['position' => 1, 'name' => $this->sourceEpoch(), 'host' => '127.0.0.1', 'port' => 1, 'ssl' => $tls]);
        $this->primary($provider);
        $budget = app(RecoveryBudget::class);
        $reservation = $budget->reserveGap($claim, $slot, $provider);
        $this->assertNotNull($reservation);
        $transfer = new RecoveryTransfer(null, 'success', null, 1000, 0, null, 1, null, 0, true, 1);
        $this->assertTrue($budget->recordTransfer($reservation, $transfer, $tls, 65536));
        $context = new RecoveryScanContext(1, 'alt.binaries.fixture', $this->sourceEpoch(), 1, $claim->payload['first'], $claim->payload['last'], HeaderScanDirection::Repair, (string) Str::uuid());
        $capture = new RecoveryCapture(RecoveryConfig::fromSettings(), new NeverBlacklistedService);
        $this->assertTrue($capture->capture(new RecoveryCaptureBatch([
            ['Number' => $claim->payload['first'], 'Subject' => 'Neutral observation', 'Date' => '2026-09-13 12:00:00 +0000'],
        ], []), $context, $claim)->coverageComplete);
        $this->assertTrue($work->complete($claim, 'frontier_rebuilt'));
        DB::table('obfuscation_recovery_frontier_requests')->where('bundle_id', $claim->bundleId)->update(['outcome' => 'frontier_rebuilt', 'updated_at' => now()]);
        $slots->release($slot);
    }

    private function fragmentedHistory(bool $tls = true): string
    {
        $identity = new RecoveryIdentity;
        $owner = $identity->digest(['frontier-range', $this->sourceEpoch(), '1', (string) RecoveryFrontiers::VERSION, '20001']);
        $budgetId = DB::table('obfuscation_recovery_budgets')->insertGetId([
            'owner_digest' => $identity->digest(['budget', $owner]), 'purpose' => RecoveryFrontierRebuild::PURPOSE,
            'debited_bytes' => $tls ? 67108864 : 133072, 'created_at' => now()->subHour(), 'updated_at' => now()->subHour(),
        ]);
        foreach ([[38339, 40000], [37966, 38338]] as $ordinal => [$first, $last]) {
            $when = now()->subMinutes(60 - $ordinal * 10);
            $ownerId = DB::table('obfuscation_recovery_bundles')->insertGetId([
                'owner_digest' => $identity->digest([$owner, '1', (string) $first, (string) $last]), 'kind' => 'frontier',
                'groups_id' => 1, 'profile' => RecoveryAlgorithm::Media->value, 'source_epoch' => $this->sourceEpoch(),
                'capture_generation' => 1, 'state' => 'frontier_complete', 'created_at' => $when, 'updated_at' => $when,
            ]);
            DB::table('obfuscation_recovery_frontier_requests')->insert([
                'bundle_id' => $ownerId, 'budget_owner' => $owner, 'groups_id' => 1, 'source_epoch' => $this->sourceEpoch(),
                'capture_generation' => 1, 'evidence_version' => RecoveryFrontiers::VERSION, 'requested_first' => $first,
                'requested_last' => $last, 'outcome' => 'frontier_rebuilt', 'expires_at' => now()->addDay(),
                'created_at' => $when, 'updated_at' => $when->copy()->addSeconds(2),
            ]);
            DB::table('obfuscation_recovery_attempts')->insert([
                'budget_id' => $budgetId, 'request_digest' => $identity->digest(['request', $owner]),
                'physical_attempt' => $ordinal + 1, 'token' => (string) Str::uuid(), 'reserved_bytes' => 33554432,
                'debited_bytes' => $tls ? 33554432 : 66536, 'outcome' => 'success', 'settled_at' => $when->copy()->addSecond(),
                'created_at' => $when, 'updated_at' => $when->copy()->addSecond(),
            ]);
            (new RecoveryFrontiers)->saveRange(DB::connection(), RecoveryPositiveCoverage::scope($this->sourceEpoch(), 1, 1), $first, $last, [], true, true);
        }
        DB::table('obfuscation_recovery_frontier_policy')->update([
            'last_attempt_id' => DB::table('obfuscation_recovery_attempts')->max('id'),
            'last_request_id' => DB::table('obfuscation_recovery_frontier_requests')->max('id'),
        ]);

        return $owner;
    }

    private function candidate(int $first, int $last): object
    {
        $run = DB::table('obfuscation_recovery_runs')->insertGetId([
            'run_identity' => hash('sha256', 'run-'.$first), 'scope_digest' => RecoveryPositiveCoverage::scope($this->sourceEpoch(), 1, 1),
            'source_epoch' => $this->sourceEpoch(), 'groups_id' => 1, 'capture_generation' => 1, 'profile' => RecoveryAlgorithm::Media->value,
            'partition_value' => $this->sourceEpoch(), 'start_ms' => 1, 'end_ms' => 2, 'observed_count' => 2,
            'state' => 'collecting', 'membership_digest' => hash('sha256', 'members-'.$first), 'bundle_dirty' => false,
            'summary' => json_encode(['first_article' => $first, 'last_article' => $last,
                'first_postdate' => '2026-09-13 12:00:00', 'last_postdate' => '2026-09-13 12:00:00'], JSON_THROW_ON_ERROR),
            'oldest_observed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = DB::table('obfuscation_recovery_bundles')->insertGetId([
            'owner_digest' => hash('sha256', 'posting-'.$first), 'kind' => 'posting', 'groups_id' => 1,
            'profile' => RecoveryAlgorithm::Media->value, 'source_epoch' => $this->sourceEpoch(), 'capture_generation' => 1,
            'revision' => 1, 'state' => 'collecting', 'membership_changed_at' => '2026-09-13 12:00:00',
            'candidate_runs' => json_encode([$run], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('obfuscation_recovery_bundles')->where('id', $id)->first();
    }

    private function coverage(int $first, int $last): void
    {
        DB::transaction(fn () => (new RecoveryPositiveCoverage)->record(DB::connection(),
            new RecoveryScanContext(1, 'alt.binaries.fixture', $this->sourceEpoch(), 1, $first, $last, HeaderScanDirection::Head, (string) Str::uuid())));
    }

    private function window(int $first, int $last): void
    {
        DB::table('obfuscation_recovery_scan_windows')->insert(['scan_id' => (string) Str::uuid(), 'groups_id' => 1,
            'source_epoch' => $this->sourceEpoch(), 'capture_generation' => 1, 'requested_first' => $first, 'requested_last' => $last,
            'expires_at' => now()->addDay(), 'created_at' => now()]);
    }

    private function assessment(object $bundle): string
    {
        $envelope = (new RecoveryFrontierRebuild)->envelope($bundle);

        return (new RecoverySettlement)->assess($this->sourceEpoch(), 1, 1, $envelope['first_article'], $envelope['last_article'],
            $envelope['first_postdate'], $envelope['last_postdate'], $envelope['changed_at'], false, $bundle);
    }
}
