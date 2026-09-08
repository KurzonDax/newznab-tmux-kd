<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\CollectionReconciliation\TrafficBudget;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReconciliationTrafficBudgetTest extends TestCase
{
    public function test_workers_share_reservations_and_failed_attempts_stay_charged(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'collection-reconciliation.hour_bytes' => 100, 'collection-reconciliation.day_bytes' => 200,
            'collection-reconciliation.decision_bytes' => 100]);
        DB::purge();
        DB::reconnect();
        $migration = require database_path('migrations/2026_09_08_121907_create_collection_reconciliation_tables.php');
        $migration->up();
        $a = new TrafficBudget('a');
        $b = new TrafficBudget('b');
        $this->assertTrue($a->reserve(80));
        $this->assertFalse($b->reserve(30));
        $a->settle(80, 60);
        $this->assertTrue($b->reserve(30));
        $this->assertFalse((new TrafficBudget('c'))->reserve(20));
        $this->assertSame(60, (int) DB::table('reconciliation_traffic')->where('bucket', 'decision:a')->value('actual'));
        $migration->down();
    }
}
