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
use App\Services\ObfuscationRecovery\RecoveryFrontierAllowance;
use App\Services\ObfuscationRecovery\RecoveryFrontierEvidence;
use App\Services\ObfuscationRecovery\RecoveryFrontierRebuild;
use App\Services\ObfuscationRecovery\RecoveryFrontiers;
use App\Services\ObfuscationRecovery\RecoveryFrontierTargets;
use App\Services\ObfuscationRecovery\RecoveryIdentity;
use App\Services\ObfuscationRecovery\RecoveryPositiveCoverage;
use App\Services\ObfuscationRecovery\RecoveryScanContext;
use App\Services\ObfuscationRecovery\RecoverySettlement;
use App\Services\ObfuscationRecovery\RecoverySlots;
use App\Services\ObfuscationRecovery\RecoveryStage;
use App\Services\ObfuscationRecovery\RecoveryTransfer;
use App\Services\ObfuscationRecovery\RecoveryWork;
use App\Services\ObfuscationRecovery\RecoveryWorkClaim;
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
    use SeedsInheritedFrontierHistory;

    #[DataProvider('continuationBacklogs')]
    public function test_ready_polling_preserves_queue_age_and_future_retry_in_the_same_scope(int $population, int $ticks): void
    {
        $this->coverage(1, 100000);
        foreach ([1, 20001, 40001, 60001, 80001] as $first) {
            $this->window($first, $first + 19999);
        }
        $scope = RecoveryPositiveCoverage::scope($this->sourceEpoch(), 1, 1);
        $frontiers = new RecoveryFrontiers;
        $frontiers->savePoints(DB::connection(), $scope, [[50000, '2026-09-13 09:50:00'], [90000, '2026-09-13 14:10:00']], true);
        $frontiers->saveRange(DB::connection(), $scope, 40001, 100000, [], true, true);
        $work = app(RecoveryWork::class);
        $turns = [];
        for ($i = 0; $i < $population; $i++) {
            $bundle = $this->candidate(60001 + $i * 10, 60010 + $i * 10);
            DB::table('obfuscation_recovery_runs')->whereIn('id', json_decode($bundle->candidate_runs, true))->update([
                'summary' => json_encode(['first_article' => 60001, 'last_article' => 60010,
                    'first_postdate' => '2026-09-13 12:00:00', 'last_postdate' => '2026-09-13 12:00:00'], JSON_THROW_ON_ERROR),
            ]);
            $id = $work->enqueueForBundle(RecoveryStage::Discover, (int) $bundle->id, 1, 'prepare', []);
            DB::table('obfuscation_recovery_work')->where('id', $id)->update(['due_at' => $i === 3 ? '2026-09-13 14:59:59' : '2026-09-13 14:58:00']);
            $turns[$id] = 0;
        }
        for ($tick = 0; $tick < $ticks; $tick++) {
            $this->travelTo(Carbon::parse('2026-09-13 15:00:00', 'UTC')->addSeconds(5 * $tick));
            $due = DB::table('obfuscation_recovery_work')->where('stage', RecoveryStage::Discover->value)->pluck('due_at', 'id')->all();
            (new RecoveryFrontierRebuild)->step();
            $this->assertSame($due, DB::table('obfuscation_recovery_work')->where('stage', RecoveryStage::Discover->value)->pluck('due_at', 'id')->all());
            $claim = $work->claim(RecoveryStage::Discover);
            if ($claim !== null) {
                $turns[$claim->id]++;
                $this->assertTrue($work->defer($claim, 60));
            }
        }
        $this->assertGreaterThan(0, min($turns));
        $this->assertSame($population, DB::table('obfuscation_recovery_work')->count());
    }

    public static function continuationBacklogs(): array
    {
        // Keep 108 candidates beyond the rebuild limit(2) and claim limit(10).
        // limit(201) bounds conflict retirement, not this candidate queue.
        return [[4, 16], [108, 120]];
    }

    #[DataProvider('frontierWaits')]
    public function test_newly_satisfied_frontier_dependencies_wake_a_future_retry_once(string $wait): void
    {
        $bundle = $this->candidate(60001, 60010);
        $this->coverage(1, 100000);
        $scope = RecoveryPositiveCoverage::scope($this->sourceEpoch(), 1, 1);
        $frontiers = new RecoveryFrontiers;
        $points = [[50000, '2026-09-13 09:50:00'], [90000, '2026-09-13 14:10:00']];
        $frontiers->savePoints(DB::connection(), $scope, $wait === 'quiet' ? $points : [$points[$wait === 'left' ? 1 : 0]], true);
        $frontiers->saveRange(DB::connection(), $scope, 40001, 100000, [], true, true);
        if ($wait === 'quiet') {
            DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->update(['membership_changed_at' => now()->subMinutes(119)]);
        }
        $work = app(RecoveryWork::class);
        $id = $work->enqueueForBundle(RecoveryStage::Discover, (int) $bundle->id, 1, 'prepare', []);
        DB::table('obfuscation_recovery_work')->where('id', $id)->update(['due_at' => now()->addHour()]);
        $future = DB::table('obfuscation_recovery_work')->where('id', $id)->value('due_at');
        (new RecoveryFrontierRebuild)->step();
        $this->assertSame($future, DB::table('obfuscation_recovery_work')->where('id', $id)->value('due_at'));
        $this->travel(61)->seconds();
        $frontiers->savePoints(DB::connection(), $scope, $points, true);
        for ($i = 0; $i < 3; $i++) {
            (new RecoveryFrontierRebuild)->step();
        }
        $this->assertLessThan($future, DB::table('obfuscation_recovery_work')->where('id', $id)->value('due_at'));
        $claim = $work->claim(RecoveryStage::Discover);
        $this->assertSame($id, $claim->id);
        $this->assertTrue($work->defer($claim, 60));
        $retry = DB::table('obfuscation_recovery_work')->where('id', $id)->value('due_at');
        for ($i = 0; $i < 3; $i++) {
            (new RecoveryFrontierRebuild)->step();
        }
        $this->assertSame($retry, DB::table('obfuscation_recovery_work')->where('id', $id)->value('due_at'));
    }

    public static function frontierWaits(): array
    {
        return [['quiet'], ['left'], ['head']];
    }

    #[DataProvider('inheritedHistories')]
    public function test_original_cutoff_histories_progress_with_preserved_spend(string $history, bool $plannerFirst): void
    {
        $bundle = $this->candidate(39000, 39001);
        $this->coverage(20001, 40000);
        $this->window(20001, 40000);
        $seed = $this->seedInheritedHistory($bundle, 20001, $history);
        $oldAttempts = DB::table('obfuscation_recovery_attempts')->count();
        if ($plannerFirst || ! str_ends_with($history, '_pending')) {
            (new RecoveryFrontierRebuild)->step();
        }
        $this->observe(true);
        $this->assertSame($seed['attempts'], DB::table('obfuscation_recovery_attempts')->where('id', '<=', $oldAttempts)->orderBy('id')->get()->toJson());
        $this->assertEquals($seed['policy'], DB::table('obfuscation_recovery_frontier_policy')->first());
        $this->assertSame($oldAttempts + 1, DB::table('obfuscation_recovery_attempts')->count());
        $this->assertSame(str_ends_with($history, '_pending') ? 0 : 1, DB::table('obfuscation_recovery_frontier_allowances')->count());
        $this->assertSame('examined', (new RecoveryFrontierEvidence)->answer(DB::connection(),
            RecoveryPositiveCoverage::scope($this->sourceEpoch(), 1, 1), 20001, 40000, (new RecoveryFrontierRebuild)->envelope($bundle)));
        for ($visit = 0; $visit < 12; $visit++) {
            (new RecoveryFrontierRebuild)->step();
        }
        $this->assertNull(app(RecoveryWork::class)->claim(RecoveryStage::Download));
    }

    public static function inheritedHistories(): array
    {
        return [['r1', true], ['r2', true], ['r3', true], ['r2_pending', true], ['r2_pending', false], ['r3_pending', true], ['r3_pending', false]];
    }

    public function test_pending_candidate_work_is_retired_when_its_witnesses_become_sufficient(): void
    {
        $bundle = $this->candidate(60001, 60010);
        $this->coverage(1, 100000);
        $this->window(60001, 80000);
        $this->terminalRequest($bundle, 60001, 80000, false);
        $scope = RecoveryPositiveCoverage::scope($this->sourceEpoch(), 1, 1);
        (new RecoveryFrontiers)->savePoints(DB::connection(), $scope, [[50000, '2026-09-13 09:50:00'], [90000, '2026-09-13 14:10:00']], true);
        $this->assertSame('ready', $this->assessment($bundle));
        $this->assertNull(app(RecoveryWork::class)->claim(RecoveryStage::Download));
        $this->assertSame('frontier_reused', DB::table('obfuscation_recovery_frontier_requests')->value('outcome'));
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
    }

    #[DataProvider('reservationStates')]
    public function test_claimed_partial_is_coalesced_only_before_its_reservation(string $reserved): void
    {
        $bundle = $this->candidate(39000, 39001);
        $this->coverage(20001, 40000);
        $this->window(20001, 40000);
        $this->terminalRequest($bundle, 20001, 35404, false);
        $row = DB::table('obfuscation_recovery_work')->first();
        $token = (string) Str::uuid();
        DB::table('obfuscation_recovery_work')->where('id', $row->id)->update([
            'status' => 'claimed', 'claim_token' => $token, 'claim_expires_at' => now()->addMinute(),
        ]);
        $claim = new RecoveryWorkClaim((int) $row->id, (int) $row->bundle_id, 1, RecoveryStage::Download,
            RecoveryFrontierRebuild::PURPOSE, $token, json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR));
        $budget = app(RecoveryBudget::class);
        if ($reserved !== 'unreserved') {
            $request = DB::table('obfuscation_recovery_frontier_requests')->first();
            $reservation = $budget->reserve($request->budget_owner, RecoveryFrontierRebuild::PURPOSE,
                $reserved === 'inherited_owner' ? $request->budget_owner : (new RecoveryFrontierAllowance)->logicalRequest($request), 33554432, 67108864);
            if ($reserved === 'reserved') {
                DB::table('obfuscation_recovery_frontier_requests')->where('id', $request->id)->update(['reserved_attempt_id' => $reservation->attemptId]);
            }
        }
        $before = DB::table('obfuscation_recovery_frontier_requests')->get()->toJson();
        $slots = app(RecoverySlots::class);
        $slot = $slots->acquire(RecoveryConfig::fromSettings());
        $provider = NntpProvider::fromConfig(['position' => 1, 'name' => 'fixture', 'host' => '127.0.0.1', 'port' => 1]);
        $this->assertNull($budget->reserveGap($claim, $slot, $provider));
        if ($reserved !== 'unreserved') {
            $this->assertSame($before, DB::table('obfuscation_recovery_frontier_requests')->get()->toJson());
            $this->assertTrue(app(RecoveryWork::class)->heartbeat($claim));
            $this->assertSame(1, DB::table('obfuscation_recovery_attempts')->count());
        } else {
            $this->assertFalse(app(RecoveryWork::class)->heartbeat($claim));
            $successor = app(RecoveryWork::class)->claim(RecoveryStage::Download);
            $this->assertNotNull($successor);
            $this->assertSame([20001, 40000], [$successor->payload['first'], $successor->payload['last']]);
            $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
        }
        $slots->release($slot);
    }

    public static function reservationStates(): array
    {
        return [['unreserved'], ['reserved'], ['inherited_owner'], ['inherited_logical']];
    }

    public function test_alias_preserves_its_target_while_the_shared_successor_is_claimed(): void
    {
        $first = $this->candidate(39000, 39001);
        $second = $this->candidate(39500, 39501);
        $this->coverage(20001, 40000);
        $this->window(20001, 40000);
        $this->terminalRequest($first, 20001, 40000, false);
        $work = app(RecoveryWork::class);
        $claim = $work->claim(RecoveryStage::Download);
        $this->assertNotNull($claim);
        $this->terminalRequest($second, 20001, 35404, false);
        $alias = DB::table('obfuscation_recovery_frontier_requests')->where('requested_last', 35404)->first();
        $targets = DB::table('obfuscation_recovery_frontier_targets')->orderBy('id')->get()->toJson();
        $this->assertNull($work->claim(RecoveryStage::Download));
        $this->assertSame('pending', DB::table('obfuscation_recovery_frontier_requests')->where('id', $alias->id)->value('outcome'));
        $this->assertSame($targets, DB::table('obfuscation_recovery_frontier_targets')->orderBy('id')->get()->toJson());
        $this->assertTrue($work->heartbeat($claim));
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
        $this->assertTrue($work->defer($claim, 1));
        $this->travel(2)->seconds();
        $successor = $work->claim(RecoveryStage::Download);
        $this->assertNotNull($successor);
        $this->assertSame($claim->bundleId, $successor->bundleId);
        $request = DB::table('obfuscation_recovery_frontier_requests')->where('bundle_id', $successor->bundleId)->first();
        $this->assertSame('superseded', DB::table('obfuscation_recovery_frontier_requests')->where('id', $alias->id)->value('outcome'));
        $this->assertSame(2, DB::table('obfuscation_recovery_frontier_targets')->where('request_id', $request->id)->count());
    }

    public function test_sealed_member_verification_cannot_be_retired_by_sufficient_range_summaries(): void
    {
        $bundle = $this->candidate(39000, 39001);
        $envelope = (new RecoveryFrontierRebuild)->envelope($bundle);
        $this->coverage(20001, 40000);
        $this->window(20001, 40000);
        $this->terminalRequest($bundle, 20001, 40000, false);
        $plan = json_encode(['manifest_bytes' => 1], JSON_THROW_ON_ERROR);
        DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->update([
            'state' => 'ready', 'sealed_plan' => $plan, 'manifest_verified_at' => now(), 'coverage_evidence' => json_encode($envelope, JSON_THROW_ON_ERROR),
        ]);
        DB::table('obfuscation_recovery_frontier_targets')->update(['plan_digest' => hash('sha256', $plan)]);
        DB::table('obfuscation_recovery_frontier_members')->insert([
            'bundle_id' => $bundle->id, 'revision' => 1, 'article_number' => 39000, 'postdate' => '2026-09-13 12:00:00',
            'observation_digest' => hash('sha256', 'member'), 'embedded_timestamp_ms' => 1,
        ]);
        DB::table('obfuscation_recovery_frontier_progress')->insert([
            'scope' => (new RecoveryIdentity)->digest(['frontier-members', (string) $bundle->id, '1', hash('sha256', $plan)]), 'cursor' => 1,
        ]);
        $scope = RecoveryPositiveCoverage::scope($this->sourceEpoch(), 1, 1);
        (new RecoveryFrontiers)->saveRange(DB::connection(), $scope, 20001, 40000, [], true, true);
        $owner = DB::table('obfuscation_recovery_bundles')->where('kind', 'frontier')->first();
        $payload = ['first' => 20001, 'last' => 40000, 'version' => RecoveryFrontiers::VERSION];
        $this->assertFalse((new RecoveryFrontierTargets)->sufficient(DB::connection(), $owner, $payload));
        $this->assertNotNull(app(RecoveryWork::class)->claim(RecoveryStage::Download));
        $this->assertSame('pending', DB::table('obfuscation_recovery_frontier_requests')->value('outcome'));
    }

    #[DataProvider('unsafeInheritedHistories')]
    public function test_omitted_history_repair_requires_unambiguous_installed_success(string $change): void
    {
        $bundle = $this->candidate(39000, 39001);
        $this->coverage(20001, 40000);
        $this->window(20001, 40000);
        $seed = $this->seedInheritedHistory($bundle, 20001, 'r3');
        match ($change) {
            'failed' => DB::table('obfuscation_recovery_attempts')->where('id', 1)->update(['outcome' => 'transport_failure']),
            'crash' => DB::table('obfuscation_recovery_attempts')->where('id', 1)->update(['outcome' => 'reserved', 'settled_at' => null]),
            'uninstalled' => DB::table('obfuscation_recovery_frontier_ranges')->delete(),
            'ambiguous' => DB::table('obfuscation_recovery_frontier_requests')->where('id', $seed['requests'][0])->update(['updated_at' => now()]),
            'fresh' => DB::table('obfuscation_recovery_frontier_requests')->whereIn('id', $seed['requests'])->update(['created_at' => now()]),
            'attributed_without_install' => DB::table('obfuscation_recovery_frontier_installs')->insert([
                'attempt_id' => 1, 'request_id' => $seed['requests'][0], 'work_id' => 1, 'claim_token' => (string) Str::uuid(),
            ]),
        };
        (new RecoveryFrontierRebuild)->step();
        $claim = app(RecoveryWork::class)->claim(RecoveryStage::Download);
        $this->assertNotNull($claim);
        $slots = app(RecoverySlots::class);
        $slot = $slots->acquire(RecoveryConfig::fromSettings());
        $provider = NntpProvider::fromConfig(['position' => 1, 'name' => 'fixture', 'host' => '127.0.0.1', 'port' => 1]);
        $this->assertNull(app(RecoveryBudget::class)->reserveGap($claim, $slot, $provider));
        $this->assertSame(0, DB::table('obfuscation_recovery_frontier_allowances')->count());
        $this->assertSame(2, DB::table('obfuscation_recovery_attempts')->count());
        $slots->release($slot);
    }

    public static function unsafeInheritedHistories(): array
    {
        return [['failed'], ['crash'], ['uninstalled'], ['ambiguous'], ['fresh'], ['attributed_without_install']];
    }

    public function test_unrelated_legacy_uncertainty_does_not_require_recapture(): void
    {
        $bundle = $this->scopedCandidate();
        $this->coverage(1, 100000);
        foreach ([1, 20001, 40001, 60001, 80001] as $first) {
            $this->window($first, $first + 19999);
        }
        $scope = RecoveryPositiveCoverage::scope($this->sourceEpoch(), 1, 1);
        $frontiers = new RecoveryFrontiers;
        $frontiers->savePoints(DB::connection(), $scope, [[50000, '2026-09-13 09:50:00'], [90000, '2026-09-13 14:10:00']], true);
        foreach ([[40001, 60000], [60001, 80000], [90000, 90000]] as [$first, $last]) {
            $frontiers->saveRange(DB::connection(), $scope, $first, $last, [], true, true);
        }
        $this->assertSame('ready', $this->assessment($bundle));
        DB::table('obfuscation_recovery_frontier_conflicts')->insert([
            'identity' => hash('sha256', 'unrelated-legacy'), 'scope_digest' => $scope,
            'kind' => 'unknown', 'first_article' => 85000, 'last_article' => 85001,
        ]);
        $this->assertSame('ready', $this->assessment($bundle));
        for ($visit = 0; $visit < 8; $visit++) {
            (new RecoveryFrontierRebuild)->step();
        }
        $this->assertSame(0, DB::table('obfuscation_recovery_frontier_requests')->count());
        $this->assertSame(1, DB::table('obfuscation_recovery_frontier_conflicts')->count());
        $this->assertSame('examined', (new RecoveryFrontierEvidence)->answer(DB::connection(), $scope, 80001, 100000,
            (new RecoveryFrontierRebuild)->envelope($bundle)));
        $this->assertSame('frontier_rebuild_required', $this->assessment($this->candidate(85000, 85001)));
    }

    #[DataProvider('scopedControls')]
    public function test_candidate_scoping_preserves_required_uncertainty_and_frontier_guards(string $control, string $expected): void
    {
        $bundle = $this->scopedCandidate();
        $this->coverage(1, 100000);
        $scope = RecoveryPositiveCoverage::scope($this->sourceEpoch(), 1, 1);
        (new RecoveryFrontiers)->savePoints(DB::connection(), $scope, [[50000, '2026-09-13 09:50:00'], [90000, '2026-09-13 14:10:00']], true);
        DB::table('obfuscation_recovery_frontier_conflicts')->insert([
            'identity' => hash('sha256', 'irrelevant'), 'scope_digest' => $scope, 'kind' => 'unknown', 'first_article' => 85000, 'last_article' => 85001,
        ]);
        if (in_array($control, ['member_legacy', 'witness_legacy', 'member_conflict', 'witness_conflict', 'unrelated_conflict'], true)) {
            $article = match ($control) {
                'witness_legacy', 'witness_conflict' => 90000,
                'unrelated_conflict' => 85000,
                default => 60005,
            };
            DB::table('obfuscation_recovery_frontier_conflicts')->insert([
                'identity' => hash('sha256', 'required'), 'scope_digest' => $scope,
                'kind' => str_ends_with($control, '_legacy') ? 'unknown' : 'contradiction', 'first_article' => $article, 'last_article' => $article,
            ]);
        } elseif (in_array($control, ['gap', 'expiry'], true)) {
            DB::transaction(fn () => (new RecoveryPositiveCoverage)->expire(DB::connection(), $this->sourceEpoch(), 1, 1, $control === 'gap' ? 85000 : 60005));
        } elseif ($control === 'quiet') {
            $bundle->membership_changed_at = now()->subMinute()->format('Y-m-d H:i:s');
        } elseif ($control === 'frozen') {
            DB::table('obfuscation_recovery_frontiers')->where('article_number', 90000)->update(['postdate' => '2026-09-13 13:59:00']);
        }
        $this->assertSame($expected, $this->assessment($bundle));
    }

    public static function scopedControls(): array
    {
        return [['member_legacy', 'frontier_rebuild_required'], ['witness_legacy', 'frontier_rebuild_required'],
            ['member_conflict', 'conflicting_posting_frontier'], ['witness_conflict', 'conflicting_boundary_witness'],
            ['unrelated_conflict', 'ready'], ['gap', 'waiting_head_frontier'], ['expiry', 'unknown_capture_gap'],
            ['quiet', 'waiting_quiet_interval'], ['frozen', 'waiting_head_frontier']];
    }

    private function scopedCandidate(): object
    {
        $bundle = $this->candidate(60001, 60010);
        DB::table('obfuscation_recovery_runs')->whereIn('id', json_decode($bundle->candidate_runs, true, flags: JSON_THROW_ON_ERROR))
            ->update(['partition_value' => '10', 'observed_count' => 10]);
        foreach (range(60001, 60010) as $article) {
            $message = 'member-'.$article.'@fixture.invalid';
            DB::table('obfuscation_recovery_headers')->insert([
                'source_epoch' => $this->sourceEpoch(), 'groups_id' => 1, 'capture_generation' => 1,
                'message_id' => $message, 'source_message_id' => '<'.$message.'>', 'message_id_digest' => hash('sha256', $message),
                'article_number' => $article, 'raw_subject' => 'Neutral candidate member', 'poster_identity' => 'fixture',
                'source_date' => '2026-09-13 12:00:00 +0000', 'postdate' => '2026-09-13 12:00:00', 'advertised_bytes' => 100,
                'advertised_total' => 10, 'original_part' => $article - 60000, 'embedded_timestamp_ms' => 1,
                'profile' => $bundle->profile, 'key_digest' => hash('sha256', $message),
                'first_observed_at' => '2026-09-13 12:00:00', 'last_observed_at' => '2026-09-13 12:00:00',
            ]);
        }

        return $bundle;
    }

    private function sourceEpoch(): string
    {
        return DB::getDriverName() === 'sqlite' ? 'fixture' : '00000000-0000-4000-8000-000000000553';
    }

    protected function seedRetainedFrontierFixture(): void
    {
        $this->travelTo(Carbon::parse('2026-09-13 15:00:00', 'UTC'));
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->unique();
        });
        (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
        (require database_path('migrations/2026_09_13_002751_add_recovery_frontier_evidence.php'))->up();
        (require database_path('migrations/2026_09_13_155226_add_recovery_frontier_repair_allowances.php'))->up();
        (require database_path('migrations/2026_09_13_190549_add_recovery_frontier_request_attribution.php'))->up();
        (require database_path('migrations/2026_09_14_110835_add_recovery_handoff_and_process_identity.php'))->up();
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
            'scope_digest' => $scope, 'kind' => 'unknown', 'first_article' => 90000, 'last_article' => 90000]);
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
    public function test_inherited_pending_clips_coalesce_before_a_claim_spends_the_tile(bool $plannerFirst): void
    {
        $bundle = $this->candidate(39000, 39001);
        $this->coverage(20001, 40000);
        $this->window(20001, 40000);
        $this->terminalRequest($bundle, 20001, 35404, false);
        $this->terminalRequest($bundle, 35405, 37000, false);
        if ($plannerFirst) {
            (new RecoveryFrontierRebuild)->step();
        }
        $claim = app(RecoveryWork::class)->claim(RecoveryStage::Download);
        $this->assertNotNull($claim);
        $this->assertSame([20001, 40000], [$claim->payload['first'], $claim->payload['last']]);
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
        $this->assertSame(2, DB::table('obfuscation_recovery_frontier_requests')->where('outcome', 'superseded')->count());
        $this->assertTrue(app(RecoveryWork::class)->defer($claim, 1));
        $this->travel(2)->seconds();
        $this->observe(false);
        $this->assertNull(app(RecoveryWork::class)->claim(RecoveryStage::Download));
        $this->assertSame(1, DB::table('obfuscation_recovery_attempts')->count());
        $this->assertSame(0, DB::table('obfuscation_recovery_frontier_allowances')->count());
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

    #[DataProvider('installedPartialIntervals')]
    public function test_installed_partial_successes_receive_the_existing_allowance(array $intervals): void
    {
        $bundle = $this->candidate(39000, 39001);
        $this->coverage(20001, 40000);
        $this->window(20001, 40000);
        $this->fragmentedHistory(true, $intervals);
        $before = DB::table('obfuscation_recovery_attempts')->orderBy('id')->get()->toJson();
        (new RecoveryFrontierRebuild)->step();
        $this->observe(true);
        $this->assertSame(1, DB::table('obfuscation_recovery_frontier_allowances')->count());
        $this->assertSame($before, DB::table('obfuscation_recovery_attempts')->where('id', '<=', 2)->orderBy('id')->get()->toJson());
        $this->assertSame('examined', (new RecoveryFrontierEvidence)->answer(DB::connection(),
            RecoveryPositiveCoverage::scope($this->sourceEpoch(), 1, 1), 20001, 40000, (new RecoveryFrontierRebuild)->envelope($bundle)));
        $this->assertSame(100663296, (int) DB::table('obfuscation_recovery_budgets')->sum('debited_bytes'));
    }

    public static function installedPartialIntervals(): array
    {
        return ['same request' => [[[20001, 35404], [20001, 35404]]], 'overlapping requests' => [[[20001, 35404], [30000, 37000]]]];
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
    public function test_unattributed_or_ineligible_historical_successes_do_not_qualify(string $history): void
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
            'unattributed_interval' => (clone $requests)->where('id', 2)->update(['requested_last' => 39000]),
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
            'full_tile', 'unattributed_interval', 'late_installation', 'other_epoch']);
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
        $requests = DB::table('obfuscation_recovery_frontier_requests')->orderBy('id')->get()->toJson();
        $targets = DB::table('obfuscation_recovery_frontier_targets')->orderBy('id')->get()->toJson();
        $installs = DB::table('obfuscation_recovery_frontier_installs')->orderBy('attempt_id')->get()->toJson();
        $owner = DB::table('obfuscation_recovery_frontier_requests')->value('budget_owner');
        $this->travel(1)->hours();
        $migration = require database_path('migrations/2026_09_13_155226_add_recovery_frontier_repair_allowances.php');
        $migration->down();
        $migration->up();
        $migration->up();
        $attribution = require database_path('migrations/2026_09_13_190549_add_recovery_frontier_request_attribution.php');
        $attribution->down();
        $attribution->up();
        $attribution->up();
        $this->assertSame($requests, DB::table('obfuscation_recovery_frontier_requests')->orderBy('id')->get()->toJson());
        $this->assertSame($targets, DB::table('obfuscation_recovery_frontier_targets')->orderBy('id')->get()->toJson());
        $this->assertSame($installs, DB::table('obfuscation_recovery_frontier_installs')->orderBy('attempt_id')->get()->toJson());
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
        foreach ([50000, 90000] as $article) {
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
        $bundle = $this->candidate(60001, 60500);
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
        foreach (array_chunk(range(60001, 60450), 100) as $articles) {
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

    private function terminalRequest(object $bundle, int $first, int $last, bool $spent = true): void
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
        if (! $spent) {
            DB::table('obfuscation_recovery_frontier_requests')->where('id', $request)->update(['outcome' => 'pending']);
            DB::table('obfuscation_recovery_bundles')->where('id', $id)->update(['state' => 'frontier_pending', 'reason' => null]);

            return;
        }
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

    /** @param list<array{int,int}> $intervals */
    private function fragmentedHistory(bool $tls = true, array $intervals = [[38339, 40000], [37966, 38338]]): string
    {
        $identity = new RecoveryIdentity;
        $owner = $identity->digest(['frontier-range', $this->sourceEpoch(), '1', (string) RecoveryFrontiers::VERSION, '20001']);
        $budgetId = DB::table('obfuscation_recovery_budgets')->insertGetId([
            'owner_digest' => $identity->digest(['budget', $owner]), 'purpose' => RecoveryFrontierRebuild::PURPOSE,
            'debited_bytes' => $tls ? 67108864 : 133072, 'created_at' => now()->subHour(), 'updated_at' => now()->subHour(),
        ]);
        foreach ($intervals as $ordinal => [$first, $last]) {
            $when = now()->subMinutes(60 - $ordinal * 10);
            $ownerDigest = $identity->digest([$owner, '1', (string) $first, (string) $last]);
            DB::table('obfuscation_recovery_bundles')->insertOrIgnore([
                'owner_digest' => $ownerDigest, 'kind' => 'frontier',
                'groups_id' => 1, 'profile' => RecoveryAlgorithm::Media->value, 'source_epoch' => $this->sourceEpoch(),
                'capture_generation' => 1, 'state' => 'frontier_complete', 'created_at' => $when, 'updated_at' => $when,
            ]);
            $ownerId = DB::table('obfuscation_recovery_bundles')->where('owner_digest', $ownerDigest)->value('id');
            DB::table('obfuscation_recovery_frontier_requests')->insertOrIgnore([
                'bundle_id' => $ownerId, 'budget_owner' => $owner, 'groups_id' => 1, 'source_epoch' => $this->sourceEpoch(),
                'capture_generation' => 1, 'evidence_version' => RecoveryFrontiers::VERSION, 'requested_first' => $first,
                'requested_last' => $last, 'outcome' => 'frontier_rebuilt', 'expires_at' => now()->addDay(),
                'created_at' => $when, 'updated_at' => $when->copy()->addSeconds(2),
            ]);
            DB::table('obfuscation_recovery_frontier_requests')->where('bundle_id', $ownerId)->update(['updated_at' => $when->copy()->addSeconds(2)]);
            DB::table('obfuscation_recovery_attempts')->insert([
                'budget_id' => $budgetId, 'request_digest' => $identity->digest(['request', $owner]),
                'physical_attempt' => $ordinal + 1, 'token' => (string) Str::uuid(), 'reserved_bytes' => 33554432,
                'debited_bytes' => $tls ? 33554432 : 66536, 'outcome' => 'success', 'settled_at' => $when->copy()->addSecond(),
                'created_at' => $when, 'updated_at' => $when->copy()->addSecond(),
            ]);
            (new RecoveryFrontiers)->saveRange(DB::connection(), RecoveryPositiveCoverage::scope($this->sourceEpoch(), 1, 1), $first, $last, [], true, true);
            DB::table('obfuscation_recovery_frontier_ranges')->where('first_article', $first)->where('last_article', $last)
                ->update(['observed_at' => $when->copy()->addSeconds(2)]);
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
