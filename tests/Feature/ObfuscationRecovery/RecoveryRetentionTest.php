<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Enums\HeaderScanDirection;
use App\Services\ObfuscationRecovery\RecoveryConfig;
use App\Services\ObfuscationRecovery\RecoveryFrontierRebuild;
use App\Services\ObfuscationRecovery\RecoveryFrontiers;
use App\Services\ObfuscationRecovery\RecoveryHistoryRetention;
use App\Services\ObfuscationRecovery\RecoveryPositiveCoverage;
use App\Services\ObfuscationRecovery\RecoveryPublicationCoverage;
use App\Services\ObfuscationRecovery\RecoveryRetention;
use App\Services\ObfuscationRecovery\RecoveryScanContext;
use App\Services\ObfuscationRecovery\RecoveryScheduler;
use App\Services\ObfuscationRecovery\RecoverySettlement;
use App\Services\ObfuscationRecovery\RecoveryStage;
use App\Services\ObfuscationRecovery\RecoveryWork;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
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
        (require database_path('migrations/2026_09_13_002751_add_recovery_frontier_evidence.php'))->up();
        (require database_path('migrations/2026_09_13_155226_add_recovery_frontier_repair_allowances.php'))->up();
        (require database_path('migrations/2026_09_13_190549_add_recovery_frontier_request_attribution.php'))->up();
        (require database_path('migrations/2026_09_14_110835_add_recovery_handoff_and_process_identity.php'))->up();
        (require database_path('migrations/2026_09_18_120000_bucket_obfuscation_recovery_dirty_marks.php'))->up();
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

    public function test_purge_trims_dead_captured_coverage_and_preserves_live_settlement(): void
    {
        $this->header(50, null, 145);
        $this->header(100, null, 1);
        $this->coverage(1, 20);
        $this->coverage(40, 200);
        $this->coverage(30, 200, HeaderScanDirection::Repair);
        $retained = DB::table('obfuscation_recovery_coverage')->where('kind', 'retained')->get()->all();
        $settlement = new RecoverySettlement;
        $before = $settlement->context('epoch', 1, 1, 100, 150, '2026-01-01 00:00:00', '2026-01-01 00:00:00');
        $this->assertSame([100, 150], $before['containing']);

        $this->assertSame(1, (new RecoveryRetention)->purge(RecoveryConfig::fromValues([]))['headers']);

        $captured = DB::table('obfuscation_recovery_coverage')->where('kind', 'captured')->orderBy('direction')->get();
        $this->assertSame([[51, 200], [51, 200]], $captured->map(
            static fn (object $row): array => [(int) $row->first_article, (int) $row->last_article])->all());
        $this->assertSame($before, $settlement->context('epoch', 1, 1, 100, 150, '2026-01-01 00:00:00', '2026-01-01 00:00:00'));
        $this->assertEquals($retained, DB::table('obfuscation_recovery_coverage')->where('kind', 'retained')->get()->all());
    }

    #[DataProvider('frontierCoverageCases')]
    public function test_purge_preserves_live_candidates_left_frontier_below_the_raw_header_floor(bool $bridged): void
    {
        $this->header(50, null, 145);
        $this->header(100, null, 1);
        $this->header(150, null, 1);
        if ($bridged) {
            $this->coverage(40, 90);
            $this->coverage(91, 120, HeaderScanDirection::Repair);
            $this->coverage(121, 200, HeaderScanDirection::Tail);
        } else {
            $this->coverage(40, 200);
        }
        DB::transaction(fn () => (new RecoveryFrontiers)->record(DB::connection(), (object) [
            'source_epoch' => 'epoch', 'groups_id' => 1, 'capture_generation' => 1,
            'requested_first' => 40, 'requested_last' => 200, 'direction' => 'Head',
            'evidence_version' => RecoveryFrontiers::VERSION,
            'date_points' => json_encode([[80, '2025-12-31 21:00:00'], [180, '2026-01-01 03:00:00']], JSON_THROW_ON_ERROR),
        ]));
        $settlement = new RecoverySettlement;
        $before = $settlement->context('epoch', 1, 1, 100, 150, '2026-01-01 00:00:00', '2026-01-01 00:00:00');
        $this->assertSame([80, 180], $before['containing']);
        $this->assertSame('ready', $settlement->assess('epoch', 1, 1, 100, 150,
            '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00'));

        $this->assertSame(1, (new RecoveryRetention)->purge(RecoveryConfig::fromValues([]))['headers']);

        $this->assertSame($before, $settlement->context('epoch', 1, 1, 100, 150, '2026-01-01 00:00:00', '2026-01-01 00:00:00'));
        $this->assertSame('ready', $settlement->assess('epoch', 1, 1, 100, 150,
            '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00'));
    }

    public static function frontierCoverageCases(): array
    {
        return ['one direction' => [false], 'bridged across all directions' => [true]];
    }

    public function test_purge_preserves_headers_below_out_of_order_expiry_and_other_coverage_scopes(): void
    {
        $this->header(10, null, 1);
        $this->header(20, null, 145);
        DB::table('obfuscation_recovery_headers')->where('id', 10)->update(['capture_generation' => 2]);
        $this->coverage(1, 30);
        $otherScopes = [['other', 1, 1], ['epoch', 2, 1], ['epoch', 1, 2]];
        foreach ($otherScopes as [$epoch, $group, $generation]) {
            DB::transaction(fn () => (new RecoveryPositiveCoverage)->record(DB::connection(),
                new RecoveryScanContext($group, 'alt.binaries.fixture', $epoch, $generation, 1, 30,
                    HeaderScanDirection::Head, 'other-scope')));
        }
        $scope = RecoveryPositiveCoverage::scope('epoch', 1, 1);
        $others = DB::table('obfuscation_recovery_coverage')->where('scope_digest', '!=', $scope)->get()->all();

        $this->assertSame(1, (new RecoveryRetention)->purge(RecoveryConfig::fromValues([]))['headers']);

        $ranges = DB::table('obfuscation_recovery_coverage')->where('scope_digest', $scope)->where('kind', 'captured')
            ->orderBy('first_article')->get()->map(static fn (object $row): array => [(int) $row->first_article, (int) $row->last_article])->all();
        $this->assertSame([[1, 19], [21, 30]], $ranges);
        $this->assertEquals($others, DB::table('obfuscation_recovery_coverage')->where('scope_digest', '!=', $scope)->get()->all());
    }

    public function test_default_purge_batch_leaves_backlog_for_the_next_transaction(): void
    {
        for ($id = 1; $id <= 101; $id++) {
            $this->header($id, null, 145);
        }
        $this->assertSame(100, (new RecoveryRetention)->purge(RecoveryConfig::fromValues([]))['headers']);
        $this->assertSame(1, DB::table('obfuscation_recovery_headers')->count());
        $this->assertSame(1, (new RecoveryRetention)->purge(RecoveryConfig::fromValues([]))['headers']);
    }

    public function test_expiry_removes_tail_coverage_for_the_expired_article(): void
    {
        $this->coverage(10, 30, HeaderScanDirection::Tail);
        DB::transaction(fn () => (new RecoveryPositiveCoverage)->expire(DB::connection(), 'epoch', 1, 1, 20));
        $context = (new RecoverySettlement)->context('epoch', 1, 1, 15, 25, '2026-01-01 00:00:00', '2026-01-01 00:00:00');
        $this->assertNull($context['containing']);
    }

    #[DataProvider('coverageExpiryCases')]
    public function test_expiry_preserves_disjoint_remainders_in_each_direction(int $article, array $expected): void
    {
        $this->coverage(10, 20);
        $this->coverage(30, 40);
        $this->coverage(15, 35, HeaderScanDirection::Repair);
        $retained = DB::table('obfuscation_recovery_coverage')->where('kind', 'retained')->get()->all();
        DB::transaction(fn () => (new RecoveryPositiveCoverage)->expire(DB::connection(), 'epoch', 1, 1, $article));
        $ranges = DB::table('obfuscation_recovery_coverage')->where('kind', 'captured')
            ->orderBy('direction')->orderBy('first_article')->get()->map(
                static fn (object $row): array => [$row->direction, (int) $row->first_article, (int) $row->last_article])->all();
        $this->assertSame($expected, $ranges);
        $this->assertEquals($retained, DB::table('obfuscation_recovery_coverage')->where('kind', 'retained')->get()->all());
    }

    public static function coverageExpiryCases(): array
    {
        return [
            'interior' => [18, [['Head', 10, 17], ['Head', 19, 20], ['Head', 30, 40], ['Repair', 15, 17], ['Repair', 19, 35]]],
            'gap in Head only' => [25, [['Head', 10, 20], ['Head', 30, 40], ['Repair', 15, 24], ['Repair', 26, 35]]],
            'before all ranges' => [5, [['Head', 10, 20], ['Head', 30, 40], ['Repair', 15, 35]]],
            'after all ranges' => [45, [['Head', 10, 20], ['Head', 30, 40], ['Repair', 15, 35]]],
            'left endpoint' => [10, [['Head', 11, 20], ['Head', 30, 40], ['Repair', 15, 35]]],
            'right endpoint' => [40, [['Head', 10, 20], ['Head', 30, 39], ['Repair', 15, 35]]],
        ];
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
        $this->coverage(1, 100);
        $retained = DB::table('obfuscation_recovery_coverage')->where('kind', 'retained')->get()->all();
        $settlement = new RecoverySettlement;
        $before = $settlement->context('epoch', 1, 1, 1, 100, '2026-01-01 00:00:00', '2026-01-01 00:00:00', retainedPlan: true);
        $this->assertSame(1, (new RecoveryRetention)->purge(RecoveryConfig::fromValues([]))['headers']);
        $this->assertSame('ready', DB::table('obfuscation_recovery_bundles')->value('state'));
        $this->assertSame(0, DB::table('obfuscation_recovery_coverage')->where('kind', 'captured')->count());
        $this->assertEquals($retained, DB::table('obfuscation_recovery_coverage')->where('kind', 'retained')->get()->all());
        $this->assertSame($before, $settlement->context('epoch', 1, 1, 1, 100, '2026-01-01 00:00:00', '2026-01-01 00:00:00', retainedPlan: true));
        $this->assertNotNull($work->claim(RecoveryStage::Publish));
    }

    #[DataProvider('unlinkedProfiles')]
    public function test_unlinked_raw_rows_expire_their_discovered_candidate_before_raw_deletion(string $profile): void
    {
        $work = app(RecoveryWork::class);
        $work->enqueue(RecoveryStage::Discover, 'unlinked-owner', 1, 'discover', []);
        DB::table('obfuscation_recovery_bundles')->update(['groups_id' => 1, 'profile' => $profile,
            'source_epoch' => 'epoch', 'capture_generation' => 1, 'key_digest' => str_repeat('a', 64), 'start_ms' => 1001, 'end_ms' => 1001]);
        DB::table('obfuscation_recovery_controls')->insert([
            ['scope' => 'primary', 'fingerprint' => str_repeat('a', 64), 'epoch' => 'epoch', 'generation' => 1, 'updated_at' => now()],
            ['scope' => 'group:1', 'fingerprint' => str_repeat('b', 64), 'epoch' => 'group', 'generation' => 1, 'updated_at' => now()],
        ]);
        $claim = $work->claim(RecoveryStage::Discover);
        $this->assertNotNull($claim);
        $this->header(1, null, 145);
        DB::table('obfuscation_recovery_headers')->update(['profile' => $profile]);
        $purger = new RecoveryRetention;
        $this->assertSame(0, $purger->purge(RecoveryConfig::fromValues([]))['headers']);
        $this->assertSame('expiry_pending', DB::table('obfuscation_recovery_bundles')->value('state'));
        $this->assertFalse($work->heartbeat($claim));
        $this->travel(91)->seconds();
        $this->assertSame(1, $purger->purge(RecoveryConfig::fromValues([]))['headers']);
        $this->assertSame('expired_unresolved', DB::table('obfuscation_recovery_bundles')->value('state'));
    }

    public static function unlinkedProfiles(): array
    {
        return [['nyuu-media-v1'], ['nyuu-rar-sequential-v1']];
    }

    #[DataProvider('unrelatedRarScopes')]
    public function test_overlapping_rar_keys_do_not_expire_or_wait_for_an_unrelated_claim(string $different): void
    {
        $this->travelTo(Carbon::parse('2026-09-13 15:00:00', 'UTC'));
        $work = app(RecoveryWork::class);
        $owners = [];
        foreach (['a', 'b'] as $i => $key) {
            $id = $work->enqueue(RecoveryStage::Discover, 'rar-'.$key, 1, 'prepare', []);
            $owners[] = (int) DB::table('obfuscation_recovery_work')->where('id', $id)->value('bundle_id');
            $this->header($i + 1, null, $i === 0 ? 145 : 1);
            DB::table('obfuscation_recovery_headers')->where('id', $i + 1)->update([
                'profile' => 'nyuu-rar-sequential-v1', 'key_digest' => str_repeat($key, 64), 'embedded_timestamp_ms' => 1001,
            ]);
            DB::table('obfuscation_recovery_bundles')->where('id', $owners[$i])->update([
                'groups_id' => 1, 'profile' => 'nyuu-rar-sequential-v1', 'source_epoch' => 'epoch', 'capture_generation' => 1,
                'key_digest' => str_repeat($key, 64), 'start_ms' => 1001, 'end_ms' => 1001,
            ]);
        }
        if ($different !== 'key') {
            $difference = match ($different) {
                'generation' => ['capture_generation' => 2], 'group' => ['groups_id' => 2], default => ['source_epoch' => 'other']
            };
            DB::table('obfuscation_recovery_bundles')->where('id', $owners[1])->update(['key_digest' => str_repeat('a', 64), ...$difference]);
            DB::table('obfuscation_recovery_headers')->where('id', 2)->update(['key_digest' => str_repeat('a', 64), ...$difference]);
        }
        DB::table('obfuscation_recovery_work')->where('bundle_id', $owners[1])->update([
            'status' => 'claimed', 'claim_token' => 'unrelated', 'claim_expires_at' => now()->addMinute(),
        ]);
        $before = DB::table('obfuscation_recovery_bundles')->where('id', $owners[1])->first();
        $purger = new RecoveryRetention;
        $this->assertSame(['headers' => 1, 'candidates' => 1, 'waiting' => 0], $purger->purge(RecoveryConfig::fromValues([])));
        $this->assertEquals($before, DB::table('obfuscation_recovery_bundles')->where('id', $owners[1])->first());
        $this->assertSame([2], DB::table('obfuscation_recovery_headers')->pluck('id')->all());
        $expired = DB::table('obfuscation_recovery_bundles')->where('id', $owners[0])->first();
        $this->assertSame('expired_unresolved', $expired->state);
        $this->assertSame(2, (int) $expired->revision);
        $this->assertSame('obsolete', DB::table('obfuscation_recovery_work')->where('bundle_id', $owners[0])->value('status'));
        $this->assertSame(['headers' => 0, 'candidates' => 0, 'waiting' => 0], $purger->purge(RecoveryConfig::fromValues([])));
        $this->assertSame(1, (int) DB::table('obfuscation_recovery_metrics')->where('metric', 'expired_candidates')->sum('value'));
    }

    public static function unrelatedRarScopes(): array
    {
        return [['key'], ['generation'], ['group'], ['epoch']];
    }

    public function test_scan_history_expires_by_age_and_abandoned_json_is_cleared_in_bounded_batches(): void
    {
        $this->freezeTime();
        foreach ([1 => 146, 2 => 146, 3 => 145, 4 => 25, 5 => 24, 6 => 25] as $id => $hours) {
            DB::table('obfuscation_recovery_scan_batches')->insert([
                'scan_id' => 'scan-'.$id, 'context_digest' => str_repeat('a', 64), 'created_at' => now()->subHours($hours),
            ]);
            DB::table('obfuscation_recovery_scans')->insert([
                'id' => $id, 'scan_id' => 'scan-'.$id, 'source_epoch' => 'epoch', 'groups_id' => 1, 'capture_generation' => 1,
                'requested_first' => 1, 'requested_last' => 10, 'chunk_ordinal' => 0, 'expected_chunks' => 2,
                'direction' => 'Head', 'capture_outcome' => 'captured', 'complete' => $id === 6,
                'date_points' => '[[1,"2026-01-01 00:00:00"]]', 'date_conflicts' => '[]', 'invalid_date_articles' => '[]',
                'returned_ranges' => '[[1,5]]', 'missing_ranges' => '[[6,10]]', 'created_at' => now()->subHours($hours),
            ]);
        }
        $history = new RecoveryHistoryRetention;
        $config = RecoveryConfig::fromValues([]);
        $first = $history->step($config, 1);
        $this->assertSame(1, $first['expired_scans']);
        $this->assertSame(1, $first['expired_scan_batches']);
        $this->assertSame(1, $first['compacted_incomplete_scans']);
        $this->assertSame(5, DB::table('obfuscation_recovery_scans')->count());
        $history->step($config);
        $this->assertSame([3, 4, 5, 6], DB::table('obfuscation_recovery_scans')->orderBy('id')->pluck('id')->all());
        $this->assertSame(['scan-3', 'scan-4', 'scan-5', 'scan-6'], DB::table('obfuscation_recovery_scan_batches')->orderBy('scan_id')->pluck('scan_id')->all());
        $abandoned = DB::table('obfuscation_recovery_scans')->where('id', 4)->first();
        $this->assertNull($abandoned->date_points);
        $this->assertNull($abandoned->date_conflicts);
        $this->assertNull($abandoned->invalid_date_articles);
        $this->assertSame('[[1,5]]', $abandoned->returned_ranges);
        $this->assertSame('[[6,10]]', $abandoned->missing_ranges);
        foreach ([5, 6] as $id) {
            $this->assertNotNull(DB::table('obfuscation_recovery_scans')->where('id', $id)->value('date_points'));
        }
        $this->travelBack();
    }

    public function test_history_floor_for_an_empty_scope_prunes_each_kind_in_bounded_batches(): void
    {
        $this->travelTo(Carbon::parse('2026-09-16 12:00:00', 'UTC'));
        $this->coverage(1, 500);
        $scope = RecoveryPositiveCoverage::scope('epoch', 1, 1);
        // Default retention is 144 hours, with another 24 hours of frontier margin.
        foreach ([10 => '2026-09-09 11:59:59', 20 => '2026-09-09 11:59:59', 100 => '2026-09-09 12:00:00', 200 => '2026-09-10 00:00:00'] as $article => $date) {
            foreach ([false, true] as $head) {
                $this->frontier($scope, $article + (int) $head, $date, $head);
            }
        }
        foreach (['unknown', 'ordering', 'contradiction'] as $kind) {
            foreach ([10, 20, 99, 100, 101] as $article) {
                DB::table('obfuscation_recovery_frontier_conflicts')->insert([
                    'identity' => hash('sha256', $kind.$article), 'scope_digest' => $scope, 'kind' => $kind,
                    'first_article' => $article, 'last_article' => $article,
                ]);
            }
        }
        foreach ([10, 20, 99, 100, 101] as $article) {
            $this->frontierRange($scope, $article);
        }
        $this->frontier('other-scope', 1, '2020-01-01 00:00:00', true);
        $this->frontierRange('other-scope', 1);
        DB::table('obfuscation_recovery_frontier_conflicts')->insert([
            'identity' => 'other', 'scope_digest' => 'other-scope', 'kind' => 'unknown', 'first_article' => 1, 'last_article' => 1,
        ]);
        $history = new RecoveryHistoryRetention;
        $first = $history->step(RecoveryConfig::fromValues([]), 1);
        $this->assertSame(2, $first['expired_frontiers']);
        $this->assertSame(3, $first['expired_frontier_conflicts']);
        $this->assertSame(1, $first['expired_frontier_ranges']);
        $history->step(RecoveryConfig::fromValues([]));
        $this->assertSame([100, 101, 200, 201], DB::table('obfuscation_recovery_frontiers')->where('scope_digest', $scope)->orderBy('article_number')->pluck('article_number')->all());
        foreach (['unknown', 'ordering', 'contradiction'] as $kind) {
            $this->assertSame([100, 101], DB::table('obfuscation_recovery_frontier_conflicts')->where('scope_digest', $scope)->where('kind', $kind)->orderBy('last_article')->pluck('last_article')->all());
        }
        $this->assertSame([100, 101], DB::table('obfuscation_recovery_frontier_ranges')->where('scope_digest', $scope)->orderBy('last_article')->pluck('last_article')->all());
        foreach (['frontiers', 'frontier_ranges', 'frontier_conflicts'] as $table) {
            $this->assertSame(1, DB::table('obfuscation_recovery_'.$table)->where('scope_digest', 'other-scope')->count());
        }
        $this->travelBack();
    }

    #[DataProvider('durableHistoryStates')]
    public function test_history_preserves_durable_bundle_witnesses_after_raw_headers_expire(string $state): void
    {
        $this->travelTo(Carbon::parse('2026-09-16 12:00:00', 'UTC'));
        $this->coverage(1, 500);
        $scope = RecoveryPositiveCoverage::scope('epoch', 1, 1);
        $this->frontier($scope, 10, '2025-12-30 20:59:59');
        $this->frontier($scope, 20, '2025-12-30 21:00:00');
        $this->frontier($scope, 80, '2025-12-31 21:00:00');
        $this->frontier($scope, 180, '2026-01-01 03:00:00');
        $id = DB::table('obfuscation_recovery_bundles')->insertGetId([
            'owner_digest' => 'durable', 'kind' => 'posting', 'state' => $state,
            'source_epoch' => 'epoch', 'groups_id' => 1, 'capture_generation' => 1,
            'profile' => 'nyuu-media-v1', 'start_ms' => 1100, 'end_ms' => 1150,
            'sealed_plan' => '{}', 'manifest_verified_at' => now()->subDays(200),
            'coverage_evidence' => json_encode(['first_article' => 100, 'last_article' => 150,
                'first_postdate' => '2026-01-01 00:00:00', 'last_postdate' => '2026-01-01 00:00:00',
                'changed_at' => '2026-01-01 00:00:00'], JSON_THROW_ON_ERROR),
        ]);
        if ($state === 'published') {
            $publication = DB::table('obfuscation_recovery_publications')->insertGetId([
                'canonical_bundle_id' => $id, 'canonical_revision' => 1, 'identity' => 'publication', 'index_identity' => 'index',
                'index_message_id' => 'index@local', 'set_id' => 'set', 'plan_digest' => 'plan', 'collection_projection' => 'projection',
                'profile' => 'nyuu-media-v1', 'group_name' => 'alt.binaries.fixture', 'source_epoch' => 'epoch',
                'state' => 'published', 'initialization_state' => 'pending', 'ordering_mode' => 'manifest', 'inventory_scope' => 'posting',
                'protected_files' => 1, 'planned_files' => 1, 'planned_parts' => 1, 'sealed_plan' => '{}', 'manifest_digest' => 'manifest',
            ]);
            DB::table('obfuscation_recovery_bundles')->where('id', $id)->update(['publication_id' => $publication]);
        }
        $this->header(100, $id, 145);
        $this->assertSame(1, (new RecoveryRetention)->purge(RecoveryConfig::fromValues([]))['headers']);
        $this->assertSame(0, DB::table('obfuscation_recovery_headers')->count());
        $bundle = DB::table('obfuscation_recovery_bundles')->where('id', $id)->first();
        $this->assertTrue((new RecoveryPublicationCoverage)->ready($bundle));

        $report = (new RecoveryHistoryRetention)->step(RecoveryConfig::fromValues([]));

        $this->assertSame(1, $report['expired_frontiers']);
        $this->assertSame([20, 80, 180], DB::table('obfuscation_recovery_frontiers')->orderBy('article_number')->pluck('article_number')->all());
        $this->assertTrue((new RecoveryPublicationCoverage)->ready($bundle));
        $this->assertSame(1, DB::table('obfuscation_recovery_expired_headers')->count());
        if ($state === 'published') {
            DB::table('obfuscation_recovery_publications')->update(['initialization_state' => 'complete']);
            $this->assertSame(3, (new RecoveryHistoryRetention)->step(RecoveryConfig::fromValues([]))['expired_frontiers']);
        }
        $this->travelBack();
    }

    public static function durableHistoryStates(): array
    {
        return [['ready'], ['publishing'], ['published']];
    }

    public function test_live_header_floor_uses_the_lowest_article_across_generations(): void
    {
        $this->coverage(1, 500);
        $scope = RecoveryPositiveCoverage::scope('epoch', 1, 1);
        $this->header(100, null, 1);
        DB::table('obfuscation_recovery_headers')->update(['capture_generation' => 2]);
        $this->frontier($scope, 10, '2025-12-30 23:59:59');
        $this->frontier($scope, 20, '2025-12-31 00:00:00');
        $this->frontier($scope, 180, '2026-01-01 03:00:00');
        $this->assertSame(1, (new RecoveryHistoryRetention)->step(RecoveryConfig::fromValues([]))['expired_frontiers']);
        $this->assertSame([20, 180], DB::table('obfuscation_recovery_frontiers')->orderBy('article_number')->pluck('article_number')->all());
    }

    public function test_unsealed_candidates_use_run_envelopes_and_unresolved_candidates_keep_history(): void
    {
        $this->coverage(1, 500);
        $scope = RecoveryPositiveCoverage::scope('epoch', 1, 1);
        $this->frontier($scope, 10, '2025-12-30 20:59:59');
        $this->frontier($scope, 20, '2025-12-30 21:00:00');
        $this->frontier($scope, 80, '2025-12-31 21:00:00');
        $this->frontier($scope, 180, '2026-01-01 03:00:00');
        $ids = [];
        foreach ([100, 150] as $article) {
            $ids[] = DB::table('obfuscation_recovery_runs')->insertGetId([
                'run_identity' => 'run-'.$article, 'scope_digest' => $scope, 'source_epoch' => 'epoch', 'groups_id' => 1,
                'capture_generation' => 1, 'profile' => 'nyuu-media-v1', 'partition_value' => 'fixture',
                'start_ms' => 1000, 'end_ms' => 1100, 'observed_count' => 1, 'state' => 'collecting',
                'membership_digest' => 'membership', 'oldest_observed_at' => now(),
                'summary' => json_encode(['first_article' => $article, 'last_article' => $article,
                    'first_postdate' => '2026-01-01 00:00:00', 'last_postdate' => '2026-01-01 00:00:00'], JSON_THROW_ON_ERROR),
            ]);
        }
        $id = DB::table('obfuscation_recovery_bundles')->insertGetId([
            'owner_digest' => 'unsealed', 'kind' => 'posting', 'state' => 'collecting',
            'source_epoch' => 'epoch', 'groups_id' => 1, 'capture_generation' => 1,
            'membership_changed_at' => '2026-01-01 00:00:00', 'candidate_runs' => json_encode($ids, JSON_THROW_ON_ERROR),
        ]);
        $history = new RecoveryHistoryRetention;
        $this->assertSame(1, $history->step(RecoveryConfig::fromValues([]))['expired_frontiers']);
        $this->assertSame([20, 80, 180], DB::table('obfuscation_recovery_frontiers')->orderBy('article_number')->pluck('article_number')->all());
        DB::table('obfuscation_recovery_bundles')->where('id', $id)->update(['candidate_runs' => null]);
        $this->assertSame(0, $history->step(RecoveryConfig::fromValues([]))['expired_frontiers']);
        DB::table('obfuscation_recovery_bundles')->where('id', $id)->update(['state' => 'expired_unresolved']);
        $this->assertSame(3, $history->step(RecoveryConfig::fromValues([]))['expired_frontiers']);
    }

    public function test_frontier_retirement_advances_past_a_range_deleted_by_history_retention(): void
    {
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => '1']);
        $this->coverage(1, 500);
        $scope = RecoveryPositiveCoverage::scope('epoch', 1, 1);
        $this->frontier($scope, 100, now('UTC')->format('Y-m-d H:i:s'));
        $this->frontierRange($scope, 10);
        $this->frontierRange($scope, 100);
        $retainedId = DB::table('obfuscation_recovery_frontier_ranges')->where('last_article', 100)->value('id');
        $this->assertSame(1, (new RecoveryHistoryRetention)->step(RecoveryConfig::fromValues([]))['expired_frontier_ranges']);
        (new RecoveryFrontierRebuild)->step();
        $this->assertSame((int) $retainedId, (int) DB::table('obfuscation_recovery_frontier_progress')->where('scope', 'retirement')->value('cursor'));
    }

    public function test_a_live_header_without_dated_frontiers_keeps_its_conflicts(): void
    {
        $this->coverage(1, 500);
        $this->header(100, null, 1);
        $scope = RecoveryPositiveCoverage::scope('epoch', 1, 1);
        DB::table('obfuscation_recovery_frontier_conflicts')->insert([
            'identity' => 'live', 'scope_digest' => $scope, 'kind' => 'contradiction', 'first_article' => 100, 'last_article' => 100,
        ]);
        $this->assertSame(0, (new RecoveryHistoryRetention)->step(RecoveryConfig::fromValues([]))['expired_frontier_conflicts']);
        $this->assertSame(1, DB::table('obfuscation_recovery_frontier_conflicts')->count());
    }

    #[DataProvider('frontierPresence')]
    public function test_nonmonotonic_dates_cannot_retire_a_live_articles_contradiction(bool $dated): void
    {
        $this->coverage(1, 500);
        $this->header(80, null, 1);
        $scope = RecoveryPositiveCoverage::scope('epoch', 1, 1);
        $this->frontier($scope, 100, '2025-12-31 00:00:00');
        if ($dated) {
            $this->frontier($scope, 80, '2026-01-01 00:00:00');
        }
        DB::table('obfuscation_recovery_frontier_conflicts')->insert([
            'identity' => 'out-of-order', 'scope_digest' => $scope, 'kind' => 'contradiction', 'first_article' => 80, 'last_article' => 80,
        ]);
        $this->frontierRange($scope, 80);
        $history = new RecoveryHistoryRetention;
        $report = $history->step(RecoveryConfig::fromValues([]));
        $this->assertSame(0, $report['expired_frontier_conflicts']);
        $this->assertSame(0, $report['expired_frontier_ranges']);
        $this->assertFalse(RecoveryFrontiers::witnesses(DB::connection(), $scope)->where('article_number', 80)->exists());
        if ($dated) {
            // An orphaned surviving point must not lose its contradiction during a bounded purge either.
            DB::table('obfuscation_recovery_headers')->delete();
            $this->travelTo(Carbon::parse('2026-01-07 00:00:00', 'UTC'));
            $this->assertSame(0, $history->step(RecoveryConfig::fromValues([]))['expired_frontier_conflicts']);
            $this->travelBack();
        }
    }

    public static function frontierPresence(): array
    {
        return [[false], [true]];
    }

    private function frontier(string $scope, int $article, string $date, bool $head = true): void
    {
        DB::table('obfuscation_recovery_frontiers')->insert([
            'scope_digest' => $scope, 'article_number' => $article, 'postdate' => $date,
            'head_observed' => $head, 'evidence_version' => RecoveryFrontiers::VERSION,
        ]);
    }

    private function frontierRange(string $scope, int $article): void
    {
        DB::table('obfuscation_recovery_frontier_ranges')->insert([
            'identity' => hash('sha256', $scope.$article), 'scope_digest' => $scope,
            'first_article' => $article, 'last_article' => $article, 'head_observed' => true, 'exhaustive' => true,
            'evidence_version' => RecoveryFrontiers::VERSION, 'points' => '[]', 'observed_at' => now(),
        ]);
    }

    private function coverage(int $first, int $last, HeaderScanDirection $direction = HeaderScanDirection::Head): void
    {
        DB::transaction(fn () => (new RecoveryPositiveCoverage)->record(DB::connection(),
            new RecoveryScanContext(1, 'alt.binaries.fixture', 'epoch', 1, $first, $last, $direction, 'retention-fixture')));
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
