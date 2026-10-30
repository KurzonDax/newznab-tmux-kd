<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Models\Settings;
use App\Services\ObfuscationRecovery\RecoveryAlgorithm;
use App\Services\ObfuscationRecovery\RecoveryBundleRefresh;
use App\Services\ObfuscationRecovery\RecoveryScheduler;
use App\Services\ObfuscationRecovery\RecoveryStage;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

final class RecoveryBundleRefreshTest extends TestCase
{
    use IsolatedSqliteDatabase;

    private int $sequence = 1000000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        ProductionTables::fromAuthority()->create('usenet_groups', ['id']);
        (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
        (require database_path('migrations/2026_09_13_002751_add_recovery_frontier_evidence.php'))->up();
        (require database_path('migrations/2026_09_13_155226_add_recovery_frontier_repair_allowances.php'))->up();
        (require database_path('migrations/2026_09_13_190549_add_recovery_frontier_request_attribution.php'))->up();
        (require database_path('migrations/2026_09_14_110835_add_recovery_handoff_and_process_identity.php'))->up();
        (require database_path('migrations/2026_09_18_120000_bucket_obfuscation_recovery_dirty_marks.php'))->up();
        $this->travelTo(now()->setDate(2026, 9, 16)->setTime(18, 0));
        DB::table('obfuscation_recovery_controls')->insert([
            ['scope' => 'primary', 'fingerprint' => str_repeat('a', 64), 'epoch' => 'epoch', 'generation' => 1, 'updated_at' => now()],
            ['scope' => 'group:1', 'fingerprint' => str_repeat('b', 64), 'epoch' => 'group', 'generation' => 1, 'updated_at' => now()],
        ]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_step_builds_from_the_fresh_active_run_and_only_retires_the_older_ineligible_ones(): void
    {
        $neighbourAt = now()->subHours(30)->getTimestampMs();
        $neighbour = $this->runRow($neighbourAt + 10000, dirty: false, updatedMinutesAgo: 60);
        $inactive = $this->runRow($neighbourAt, active: false, updatedMinutesAgo: 30);
        $past = $this->runRow($this->cutoff() - 1, updatedMinutesAgo: 20);
        $fresh = $this->runRow(now()->subHour()->getTimestampMs(), updatedMinutesAgo: 10);
        $untouched = DB::table('obfuscation_recovery_runs')->whereIn('id', [$neighbour, $inactive, $past])->orderBy('id')->get()
            ->map(static fn (object $run): object => (object) ([...(array) $run, 'bundle_dirty' => 0]))->all();

        $bundleId = app(RecoveryBundleRefresh::class)->step();

        $this->assertNotNull($bundleId);
        $bundle = DB::table('obfuscation_recovery_bundles')->sole();
        $this->assertSame($bundleId, (int) $bundle->id);
        $this->assertSame([$fresh], json_decode($bundle->candidate_runs, true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame([$bundleId], DB::table('obfuscation_recovery_work')->pluck('bundle_id')->map(static fn ($id): int => (int) $id)->all());
        $this->assertEquals($untouched, DB::table('obfuscation_recovery_runs')->whereIn('id', [$neighbour, $inactive, $past])->orderBy('id')->get()->all());
        $this->assertSame(0, DB::table('obfuscation_recovery_runs')->where('bundle_dirty', true)->count());
    }

    public function test_retirement_is_bounded_per_call_and_still_selects_the_fresh_run(): void
    {
        $bound = RecoveryBundleRefresh::RETIRE_LIMIT;
        $rows = [];
        for ($i = 1; $i <= 2 * $bound + 500; $i++) {
            $rows[] = $this->runValues($i, $i % 2 === 0 ? now()->subHours(30)->getTimestampMs() : $this->cutoff() - $i,
                active: $i % 2 === 1, updatedAt: now()->subDay());
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('obfuscation_recovery_runs')->insert($chunk);
        }
        $fresh = $this->runRow(now()->subHour()->getTimestampMs(), updatedMinutesAgo: 1);
        $refresh = app(RecoveryBundleRefresh::class);

        $this->assertNotNull($refresh->step());
        $this->assertSame(0, (int) DB::table('obfuscation_recovery_runs')->where('id', $fresh)->value('bundle_dirty'));
        $this->assertSame($bound + 500, DB::table('obfuscation_recovery_runs')->where('bundle_dirty', true)->count());
        $this->assertNull($refresh->step());
        $this->assertSame(500, DB::table('obfuscation_recovery_runs')->where('bundle_dirty', true)->count());
        $this->assertSame(1, DB::table('obfuscation_recovery_bundles')->count());
    }

    /** @return array<string, array{int, int, bool}> */
    public static function retentionEdges(): array
    {
        return [
            'exactly at the cutoff' => [144, 0, true],
            'one millisecond older' => [144, -1, false],
            'one millisecond older under a longer retention' => [240, -1, true],
        ];
    }

    #[DataProvider('retentionEdges')]
    public function test_eligibility_follows_the_retention_setting_inclusively(int $hours, int $offset, bool $eligible): void
    {
        Settings::settingsUpsert(['obfuscation_recovery_retention_hours' => (string) $hours]);
        $run = $this->runRow($this->cutoff() + $offset);

        $bundleId = app(RecoveryBundleRefresh::class)->step();

        $this->assertSame($eligible, $bundleId !== null);
        $this->assertSame($eligible ? 1 : 0, DB::table('obfuscation_recovery_bundles')->count());
        $this->assertSame(0, (int) DB::table('obfuscation_recovery_runs')->where('id', $run)->value('bundle_dirty'));
    }

    public function test_scheduler_counts_a_built_candidate_but_not_a_retirement(): void
    {
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        $this->runRow($this->cutoff() - 1);
        $this->assertSame(0, $this->bundlesRefreshed());

        $this->runRow(now()->subHour()->getTimestampMs());
        $this->assertSame(1, $this->bundlesRefreshed());
    }

    private function bundlesRefreshed(): int
    {
        $planning = null;
        app(RecoveryScheduler::class)->local(RecoveryStage::Discover, limit: 1, seconds: 5, observe: function (string $event, array $data) use (&$planning): void {
            if ($event === 'planning') {
                $planning = $data;
            }
        });
        $this->assertIsArray($planning);

        return $planning['bundles_refreshed'];
    }

    /** 144 hours, the default retention, before the frozen test clock. */
    private function cutoff(): int
    {
        return now()->subHours(144)->getTimestampMs();
    }

    private function runRow(int $endMs, bool $active = true, bool $dirty = true, int $updatedMinutesAgo = 0): int
    {
        return DB::table('obfuscation_recovery_runs')->insertGetId(
            $this->runValues(++$this->sequence, $endMs, $active, $dirty, now()->subMinutes($updatedMinutesAgo)));
    }

    /** @return array<string, mixed> */
    private function runValues(int $key, int $endMs, bool $active = true, bool $dirty = true, mixed $updatedAt = null): array
    {
        return [
            'run_identity' => hash('sha256', 'run-'.$key), 'scope_digest' => hash('sha256', 'scope-'.$key),
            'source_epoch' => 'epoch', 'groups_id' => 1, 'capture_generation' => 1, 'profile' => RecoveryAlgorithm::Media->value,
            'partition_value' => (string) $key, 'start_ms' => $endMs - 1000, 'end_ms' => $endMs, 'observed_count' => 1,
            'state' => 'count_exact', 'membership_digest' => hash('sha256', 'members-'.$key), 'active' => $active, 'bundle_dirty' => $dirty,
            'summary' => json_encode(['membership_changed_at' => now()->format('Y-m-d H:i:s.u')], JSON_THROW_ON_ERROR),
            'oldest_observed_at' => now(), 'created_at' => now(), 'updated_at' => $updatedAt ?? now(),
        ];
    }
}
