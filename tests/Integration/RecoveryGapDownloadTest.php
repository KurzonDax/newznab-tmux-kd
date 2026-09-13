<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Enums\HeaderScanDirection;
use App\Services\Binaries\HeaderParser;
use App\Services\BlacklistService;
use App\Services\NNTP\NntpProvider;
use App\Services\ObfuscationRecovery\RecoveryBundleRefresh;
use App\Services\ObfuscationRecovery\RecoveryCapture;
use App\Services\ObfuscationRecovery\RecoveryCaptureBatch;
use App\Services\ObfuscationRecovery\RecoveryConfig;
use App\Services\ObfuscationRecovery\RecoveryControl;
use App\Services\ObfuscationRecovery\RecoveryDownload;
use App\Services\ObfuscationRecovery\RecoveryFrontierRebuild;
use App\Services\ObfuscationRecovery\RecoveryFrontiers;
use App\Services\ObfuscationRecovery\RecoveryGapPlanner;
use App\Services\ObfuscationRecovery\RecoveryPositiveCoverage;
use App\Services\ObfuscationRecovery\RecoveryRunRefresh;
use App\Services\ObfuscationRecovery\RecoveryScanContext;
use App\Services\ObfuscationRecovery\RecoveryScheduler;
use App\Services\ObfuscationRecovery\RecoverySettlement;
use App\Services\ObfuscationRecovery\RecoveryStage;
use App\Services\ObfuscationRecovery\RecoveryWire;
use App\Services\ObfuscationRecovery\RecoveryWork;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\NeverBlacklistedService;
use Tests\Support\ObfuscationRecovery\InteractsWithRecoveryNntpServer;
use Tests\Support\ObfuscationRecovery\MediaPostingFixture;
use Tests\TestCase;

final class RecoveryGapDownloadTest extends TestCase
{
    use InteractsWithRecoveryNntpServer;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
        (require database_path('migrations/2026_09_13_002751_add_recovery_frontier_evidence.php'))->up();
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.fixture', 'obfuscation_recovery_profile' => 'both']);
        $this->app->instance(BlacklistService::class, new NeverBlacklistedService);
    }

    protected function tearDown(): void
    {
        $this->stopRecoveryServers();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    #[DataProvider('transports')]
    public function test_unknown_range_uses_the_shared_worker_and_primary_epoch_to_capture_counterless_headers(bool $tls): void
    {
        $provider = $this->server('', tls: $tls, dialogue: ["GROUP alt.binaries.fixture\r\n" => "211 1 4000000001 4000000001 alt.binaries.fixture\r\n",
            "XOVER 4000000001-4000000001\r\n" => "224 overview\r\n4000000001\t0123456789abcdefghij\tfixture\tTue, 14 Nov 2023 22:13:20 +0000\t<m1-1700000000000@nyuu>\t\t740000\t10\r\n.\r\n"]);
        $config = RecoveryConfig::fromSettings();
        (new RecoveryControl)->begin($config, $provider, 1, 'alt.binaries.fixture', 4000000001, 4000000001, HeaderScanDirection::Head, 1);
        $this->assertSame(0, app(RecoveryGapPlanner::class)->step());
        $this->travel(121)->seconds();
        $this->assertSame(1, app(RecoveryGapPlanner::class)->step());
        $work = app(RecoveryWork::class);
        $claim = $work->claim(RecoveryStage::Download);
        $this->assertSame('gap', $claim->purpose);
        $this->app->bind(RecoveryWire::class, fn (): RecoveryWire => new RecoveryWire(caFile: $this->certificateAuthority));
        $this->assertSame('captured', app(RecoveryDownload::class)->run($claim, [$provider]));
        $this->assertSame(1, DB::table('obfuscation_recovery_headers')->count());
        $this->assertSame('nyuu-rar-sequential-v1', DB::table('obfuscation_recovery_headers')->value('profile'));
        $this->assertSame(1, DB::table('obfuscation_recovery_attempts')->count());
        $this->assertSame('gap', DB::table('obfuscation_recovery_budgets')->value('purpose'));
        $this->assertSame(33554432, (int) DB::table('obfuscation_recovery_attempts')->value('reserved_bytes'));
        $this->assertSame(0, DB::table('obfuscation_recovery_slots')->whereNotNull('worker_token')->count());
        $this->travel(301)->seconds();
        $this->assertSame(0, app(RecoveryGapPlanner::class)->step());
        $this->assertSame(1, DB::table('obfuscation_recovery_gaps')->count());
    }

    public function test_large_holes_stay_disjoint_and_capture_toggles_do_not_create_fresh_retries(): void
    {
        $provider = NntpProvider::fromConfig(['position' => 1, 'name' => 'fixture', 'host' => '127.0.0.1', 'port' => 1]);
        $config = RecoveryConfig::fromSettings();
        $context = (new RecoveryControl)->begin($config, $provider, 1, 'alt.binaries.fixture', 1, 45001, HeaderScanDirection::Head, 1);
        $this->travel(121)->seconds();
        $planner = app(RecoveryGapPlanner::class);
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(1, $planner->step());
            $this->travel(2)->seconds();
        }
        $ranges = DB::table('obfuscation_recovery_gaps')->orderBy('requested_first')->get();
        $this->assertSame([[1, 20000], [20001, 40000], [40001, 45001]], $ranges->map(fn (object $row): array => [(int) $row->requested_first, (int) $row->requested_last])->all());
        DB::table('obfuscation_recovery_controls')->where('scope', 'group:1')->increment('generation');
        DB::table('obfuscation_recovery_scan_windows')->insert([
            'scan_id' => (string) Str::uuid(), 'groups_id' => 1, 'source_epoch' => $context->sourceEpoch,
            'capture_generation' => $context->generation + 1, 'requested_first' => 1, 'requested_last' => 45001,
            'next_gap_at' => now()->subSecond(), 'expires_at' => now()->addDay(), 'created_at' => now(),
        ]);
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(0, $planner->step());
            $this->travel(2)->seconds();
        }
        $this->assertSame(3, DB::table('obfuscation_recovery_gaps')->count());
    }

    public function test_missing_scan_window_is_reconciled_against_the_known_ordinary_frontier_without_claiming_coverage(): void
    {
        Schema::table('usenet_groups', fn (Blueprint $table) => $table->unsignedBigInteger('last_record')->default(0));
        $provider = NntpProvider::fromConfig(['position' => 1, 'name' => 'fixture', 'host' => '127.0.0.1', 'port' => 1]);
        $config = RecoveryConfig::fromSettings();
        $context = (new RecoveryControl)->begin($config, $provider, 1, 'alt.binaries.fixture', 1, 20, HeaderScanDirection::Head, 1);
        (new RecoveryCapture($config, new NeverBlacklistedService))->capture(new RecoveryCaptureBatch([], []), $context);
        $expiry = DB::table('obfuscation_recovery_scan_windows')->value('expires_at');
        // Ordinary storage progressed while no recovery scan-window record could be saved.
        DB::table('usenet_groups')->where('id', 1)->update(['last_record' => 40]);
        $this->travel(121)->seconds();
        $planner = app(RecoveryGapPlanner::class);
        $this->assertSame(1, $planner->step());
        $gap = DB::table('obfuscation_recovery_gaps')->first();
        $this->assertSame([21, 40], [(int) $gap->requested_first, (int) $gap->requested_last]);
        $this->assertSame($expiry, $gap->expires_at);
        $this->assertSame([], $planner->positive($gap, 21, 40));
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
    }

    public static function transports(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('frontierEvidenceCases')]
    public function test_scheduler_rebuilds_legacy_frontiers_even_when_capture_is_already_positive(string $evidence): void
    {
        $this->travelTo(Carbon::parse('2026-09-07 12:00:00', 'UTC'));
        $fixture = MediaPostingFixture::make(['fixture.mkv' => str_repeat('a', 2250400)], '2026-09-07T12:00:00Z');
        $headers = $fixture['headers'];
        foreach ([100, 110, 120, 130, 180, 190, 200] as $ordinal => $number) {
            $headers[$ordinal]['Number'] = (string) $number;
        }
        if ($evidence === 'no_left_witness') {
            $headers[0]['Date'] = '2026-09-07 11:00:00 +0000';
        }
        if ($evidence === 'conflicted_summary') {
            $headers[0]['Date'] = '2026-09-07 09:00:00 +0000';
            $headers[] = ['Number' => '90', 'Subject' => 'Alternative unrelated boundary', 'From' => 'fixture@example.invalid',
                'Date' => '2026-09-07 09:50:00 +0000', 'Message-ID' => '<alternative@fixture.invalid>', 'Bytes' => 100, 'Xref' => ''];
        }
        foreach ([1 => '12:00:00', 150 => '08:00:00', 160 => '16:00:00', 300 => '12:00:00'] as $number => $time) {
            $headers[] = ['Number' => (string) $number, 'Subject' => 'Unrelated ordinary posting', 'From' => 'fixture@example.invalid',
                'Date' => '2026-09-07 '.$time.' +0000', 'Message-ID' => '<unrelated-'.$number.'@fixture.invalid>', 'Bytes' => 100, 'Xref' => ''];
        }
        usort($headers, static fn (array $a, array $b): int => (int) $a['Number'] <=> (int) $b['Number']);
        $first = 1;
        $last = 300;
        $overview = "224 overview\r\n";
        foreach ($headers as $header) {
            $overview .= implode("\t", [$header['Number'], $header['Subject'], $header['From'], $header['Date'], $header['Message-ID'], '', $header['Bytes'], 10])."\r\n";
        }
        if ($evidence === 'malformed_reply') {
            $overview .= "not-an-article\tbroken\r\n";
        }
        $provider = $this->server('', dialogue: ["GROUP alt.binaries.fixture\r\n" => "211 4 $first $last alt.binaries.fixture\r\n",
            "XOVER $first-$last\r\n" => $overview.".\r\n"]);
        $context = (new RecoveryControl)->begin(RecoveryConfig::fromSettings(), $provider, 1, 'alt.binaries.fixture', $first, $last, HeaderScanDirection::Head, 1);
        $policy = new NeverBlacklistedService;
        $parsed = (new HeaderParser($policy))->parse($headers, 'alt.binaries.fixture');
        $capture = new RecoveryCapture(RecoveryConfig::fromSettings(), $policy);
        $this->assertTrue($capture->capture(new RecoveryCaptureBatch($headers, $parsed['headers']), $context)->coverageComplete);
        $this->travel(360)->minutes();
        while (app(RecoveryRunRefresh::class)->step() !== null) {
        }
        while (app(RecoveryBundleRefresh::class)->step() !== null) {
        }
        $bundle = DB::table('obfuscation_recovery_bundles')->where('kind', 'posting')->first();
        $this->assertNotNull($bundle);
        $scope = RecoveryPositiveCoverage::scope($context->sourceEpoch, 1, $context->generation);
        if ($evidence !== 'no_left_witness') {
            $this->assertSame('ready', (new RecoverySettlement)->assess($context->sourceEpoch, 1, $context->generation, 110, 190, '2026-09-07 12:00:00', '2026-09-07 12:00:00', $bundle->membership_changed_at));
        }
        DB::table('obfuscation_recovery_frontiers')->delete();
        if (! in_array($evidence, ['insufficient_summary', 'no_left_witness', 'conflicted_summary'], true)) {
            DB::table('obfuscation_recovery_frontier_ranges')->delete();
            DB::table('obfuscation_recovery_scans')->update(['evidence_version' => 1, 'date_order_consistent' => false, 'date_points' => null]);
            DB::table('obfuscation_recovery_frontier_conflicts')->insert([
                'identity' => hash('sha256', 'legacy'), 'scope_digest' => $scope,
                'kind' => 'unknown', 'first_article' => $first, 'last_article' => $last,
            ]);
        } else {
            $summary = [[150, '2026-09-07 08:00:00'], [160, '2026-09-07 16:00:00']];
            DB::table('obfuscation_recovery_frontier_ranges')->update(['exhaustive' => false, 'points' => json_encode($summary, JSON_THROW_ON_ERROR)]);
            (new RecoveryFrontiers)->savePoints(DB::connection(), $scope, $summary, true);
        }
        if ($evidence === 'conflicted_summary') {
            $frontiers = new RecoveryFrontiers;
            $frontiers->savePoints(DB::connection(), $scope, [[100, '2026-09-07 09:00:00']], true);
            $frontiers->saveRange(DB::connection(), $scope, 1, 109, [[100, '2026-09-07 09:00:00']], true, false);
            DB::table('obfuscation_recovery_frontier_conflicts')->insert(['identity' => hash('sha256', 'witness-conflict'),
                'scope_digest' => $scope, 'kind' => 'contradiction', 'first_article' => 100, 'last_article' => 100]);
        }
        if ($evidence === 'missing_head_provenance') {
            DB::table('obfuscation_recovery_scan_windows')->delete();
            app(RecoveryScheduler::class)->local(RecoveryStage::Discover, 2, 10);
            $this->assertSame(0, DB::table('obfuscation_recovery_frontier_requests')->count());
            $this->assertSame(1, DB::table('obfuscation_recovery_frontier_conflicts')->count());

            return;
        }
        $report = app(RecoveryScheduler::class)->local(RecoveryStage::Discover, 1, 10);
        $this->assertSame(1, $report['frontier_rebuild_pending'] ?? 0);
        if ($evidence === 'obsolete_work') {
            DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->increment('revision');
            $this->assertNull(app(RecoveryWork::class)->claim(RecoveryStage::Download));
            $this->assertSame('obsolete', DB::table('obfuscation_recovery_work')->where('purpose', 'frontier_rebuild')->value('status'));
            app(RecoveryFrontierRebuild::class)->step();
            app(RecoveryFrontierRebuild::class)->step();
        }
        if ($evidence === 'obsolete_link') {
            $target = (array) DB::table('obfuscation_recovery_frontier_targets')->first();
            unset($target['id']);
            $target['revision']++;
            DB::table('obfuscation_recovery_frontier_targets')->insert($target);
        }

        $claim = app(RecoveryWork::class)->claim(RecoveryStage::Download);
        $this->assertNotNull($claim);
        $this->assertSame('frontier_rebuild', $claim->purpose);
        if (in_array($evidence, ['partial_scan', 'partial_claim_loss'], true)) {
            $partial = new RecoveryScanContext(1, 'alt.binaries.fixture', $context->sourceEpoch,
                $context->generation, 1, 300, HeaderScanDirection::Repair, (string) Str::uuid(), 0, 2);
            $this->assertFalse($capture->capture(new RecoveryCaptureBatch(array_slice($headers, 0, 2), []), $partial, $claim)->coverageComplete);
            $this->assertSame(1, DB::table('obfuscation_recovery_frontier_conflicts')->count());
            $this->assertSame(0, DB::table('obfuscation_recovery_frontier_ranges')->count());
            if ($evidence === 'partial_claim_loss') {
                DB::table('obfuscation_recovery_work')->where('id', $claim->id)->update(['claim_token' => (string) Str::uuid()]);
                $this->assertFalse($capture->capture(new RecoveryCaptureBatch(array_slice($headers, 2), []), $partial->chunk(1), $claim)->coverageComplete);
                $this->assertSame(1, DB::table('obfuscation_recovery_frontier_conflicts')->count());
                $this->assertSame(0, DB::table('obfuscation_recovery_frontier_ranges')->count());

                return;
            }
        }
        if ($evidence === 'malformed_reply') {
            $this->assertSame('frontier_unresolved', app(RecoveryDownload::class)->run($claim, [$provider]));
            $this->assertSame(1, DB::table('obfuscation_recovery_frontier_conflicts')->count());
            $this->assertSame(0, DB::table('obfuscation_recovery_frontier_ranges')->count());

            return;
        }
        $this->assertSame('frontier_rebuilt', app(RecoveryDownload::class)->run($claim, [$provider]));
        $this->assertSame($bundle->membership_changed_at, DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->value('membership_changed_at'));
        $this->assertSame(1, DB::table('obfuscation_recovery_attempts')->count());
        $this->assertSame($evidence === 'conflicted_summary' ? 1 : 0, DB::table('obfuscation_recovery_frontier_conflicts')->count());
        $report = app(RecoveryScheduler::class)->local(RecoveryStage::Discover, 1, 10);
        if ($evidence === 'obsolete_work') {
            for ($cycle = 0; $cycle < 3 && ! isset($report['awaiting_index']); $cycle++) {
                $report = app(RecoveryScheduler::class)->local(RecoveryStage::Discover, 2, 10);
            }
        }
        if ($evidence === 'no_left_witness') {
            $this->assertArrayNotHasKey('awaiting_index', $report);
            for ($cycle = 0; $cycle < 5; $cycle++) {
                app(RecoveryScheduler::class)->local(RecoveryStage::Discover, 2, 10);
            }
            $this->assertSame(1, DB::table('obfuscation_recovery_attempts')->count());
            $this->assertNull(app(RecoveryWork::class)->claim(RecoveryStage::Download));
            $this->assertNotSame('ready', DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->value('state'));
        } else {
            $this->assertSame(1, $report['awaiting_index'] ?? 0);
        }
    }

    public static function frontierEvidenceCases(): array
    {
        return [['legacy'], ['insufficient_summary'], ['no_left_witness'], ['partial_scan'], ['partial_claim_loss'], ['malformed_reply'], ['missing_head_provenance'], ['obsolete_link'], ['conflicted_summary'], ['obsolete_work']];
    }

    public function test_completed_empty_scans_are_positive_coverage_and_later_ordinary_capture_cancels_a_retry(): void
    {
        $provider = NntpProvider::fromConfig(['position' => 1, 'name' => 'fixture', 'host' => '127.0.0.1', 'port' => 1]);
        $config = RecoveryConfig::fromSettings();
        $first = (new RecoveryControl)->begin($config, $provider, 1, 'alt.binaries.fixture', 1, 20, HeaderScanDirection::Head, 1);
        $capture = new RecoveryCapture($config, new NeverBlacklistedService);
        $capture->capture(new RecoveryCaptureBatch([], []), $first);
        $this->travel(121)->seconds();
        $this->assertSame(0, app(RecoveryGapPlanner::class)->step());
        $next = (new RecoveryControl)->begin($config, $provider, 1, 'alt.binaries.fixture', 21, 40, HeaderScanDirection::Head, 1);
        $this->travel(121)->seconds();
        $this->assertSame(1, app(RecoveryGapPlanner::class)->step());
        $capture->capture(new RecoveryCaptureBatch([], []), $next);
        $claim = app(RecoveryWork::class)->claim(RecoveryStage::Download);
        $this->assertSame('reused_capture', app(RecoveryDownload::class)->run($claim, [$provider]));
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
    }
}
