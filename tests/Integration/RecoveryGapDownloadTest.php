<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Enums\HeaderScanDirection;
use App\Services\BlacklistService;
use App\Services\NNTP\NntpProvider;
use App\Services\ObfuscationRecovery\RecoveryCapture;
use App\Services\ObfuscationRecovery\RecoveryCaptureBatch;
use App\Services\ObfuscationRecovery\RecoveryConfig;
use App\Services\ObfuscationRecovery\RecoveryControl;
use App\Services\ObfuscationRecovery\RecoveryDownload;
use App\Services\ObfuscationRecovery\RecoveryGapPlanner;
use App\Services\ObfuscationRecovery\RecoveryStage;
use App\Services\ObfuscationRecovery\RecoveryWire;
use App\Services\ObfuscationRecovery\RecoveryWork;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\NeverBlacklistedService;
use Tests\Support\ObfuscationRecovery\InteractsWithRecoveryNntpServer;
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
