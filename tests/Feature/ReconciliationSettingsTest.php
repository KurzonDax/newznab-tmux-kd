<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\CollectionReconciliation\ReconciliationLimits;
use App\Services\CollectionReconciliation\ReconciliationStatus;
use App\Services\CollectionReconciliation\TrafficBudget;
use Carbon\Carbon;
use Database\Seeders\SettingsTableSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\Settings\InteractsWithSettingsHub;
use Tests\TestCase;

class ReconciliationSettingsTest extends TestCase
{
    use InteractsWithSettingsHub;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->createSettingsHubSchema();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_limits_use_defaults_and_observe_subsequent_settings_saves(): void
    {
        $limits = new ReconciliationLimits;
        $this->assertSame(268435456, $limits->hourBytes());
        $this->assertSame(2147483648, $limits->dayBytes());
        $this->saveCard('release-formation', 'reconciliation', [
            'reconciliation_hourly_mib' => '300', 'reconciliation_daily_mib' => '200',
        ]);
        $this->assertSame(314572800, $limits->hourBytes());
        $this->assertSame(209715200, $limits->dayBytes());
        foreach (['', '0', '-1'] as $legacy) {
            DB::table('settings')->where('name', 'reconciliation_hourly_mib')->update(['value' => $legacy]);
            $this->assertSame(268435456, $limits->hourBytes());
        }
    }

    public function test_seeded_limits_and_card_validation_preserve_both_values_on_failure(): void
    {
        (new SettingsTableSeeder)->run();
        $this->assertSame('256', $this->storedSettingValue('reconciliation_hourly_mib'));
        $this->assertSame('2048', $this->storedSettingValue('reconciliation_daily_mib'));
        foreach (['0', '-1', '1.5', (string) (intdiv(PHP_INT_MAX, 1048576) + 1)] as $invalid) {
            try {
                $this->saveCard('release-formation', 'reconciliation', [
                    'reconciliation_hourly_mib' => $invalid, 'reconciliation_daily_mib' => '1',
                ]);
                $this->fail('Invalid MiB values must be rejected.');
            } catch (ValidationException) {
                $this->assertSame('256', $this->storedSettingValue('reconciliation_hourly_mib'));
                $this->assertSame('2048', $this->storedSettingValue('reconciliation_daily_mib'));
            }
        }
    }

    public function test_reservations_observe_limit_changes_without_resetting_usage(): void
    {
        (require database_path('migrations/2026_09_08_121907_create_collection_reconciliation_tables.php'))->up();
        $this->saveCard('release-formation', 'reconciliation', [
            'reconciliation_hourly_mib' => '1', 'reconciliation_daily_mib' => '10',
        ]);
        $first = new TrafficBudget('first');
        $second = new TrafficBudget('second');
        $this->assertTrue($first->reserve(1048576));
        $this->assertFalse($second->reserve(1));
        $this->saveCard('release-formation', 'reconciliation', [
            'reconciliation_hourly_mib' => '2', 'reconciliation_daily_mib' => '10',
        ]);
        $this->assertTrue($second->reserve(1048576));
        $this->assertFalse((new TrafficBudget('third'))->reserve(1));
    }

    public function test_shared_deferrals_count_postings_once_and_decision_exhaustion_takes_precedence(): void
    {
        (require database_path('migrations/2026_09_08_121907_create_collection_reconciliation_tables.php'))->up();
        (require database_path('migrations/2026_09_10_133215_add_reconciliation_budget_controls.php'))->up();
        $this->travelTo(Carbon::parse('2026-01-01T12:00:00Z'));
        config(['collection-reconciliation.decision_bytes' => 1048576]);
        $this->saveCard('release-formation', 'reconciliation', [
            'reconciliation_hourly_mib' => '1', 'reconciliation_daily_mib' => '1',
        ]);
        $this->assertTrue((new TrafficBudget('full'))->reserve(1048576));
        $permanent = new TrafficBudget('full');
        $this->assertFalse($permanent->reserve(1));
        $this->assertSame('decision_budget_exhausted', $permanent->denialReason());
        $deferred = new TrafficBudget('deferred');
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->assertFalse($deferred->reserve(1));
        }
        $this->assertSame('budget_exhausted', $deferred->denialReason());
        $this->assertSame(2, DB::table('reconciliation_budget_deferrals')->count());
        $status = (new ReconciliationStatus)->summary();
        $this->assertSame(1048576, $status['hour']['used']);
        $this->assertSame(1048576, $status['hour']['limit']);
        $this->assertSame(1, $status['hour']['deferrals']);
        $this->assertSame(1, $status['day']['deferrals']);
        $this->travel(1)->hours();
        $this->assertFalse($deferred->reserve(1));
        $this->assertSame(3, DB::table('reconciliation_budget_deferrals')->count());
    }
}
