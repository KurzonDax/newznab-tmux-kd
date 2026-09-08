<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\CollectionReconciliation\CollectionClaims;
use App\Services\CollectionReconciliation\CollectionOwnership;
use App\Services\CollectionReconciliation\PendingInventory;
use App\Services\CollectionReconciliation\TrafficBudget;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class ReconciliationMariaDbTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        config(['database.default' => 'reconciliation_fixture',
            'database.connections.reconciliation_fixture' => [
                'driver' => 'mariadb', 'host' => 'mariadb', 'port' => 3306, 'database' => 'cbp_integration',
                'username' => 'sail', 'password' => 'password', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
                'prefix' => 'reconciliation_'.bin2hex(random_bytes(6)).'_', 'strict' => true,
            ],
            'collection-reconciliation.hour_bytes' => 100, 'collection-reconciliation.day_bytes' => 100,
            'collection-reconciliation.decision_bytes' => 100,
        ]);
        DB::purge('reconciliation_fixture');
        (require database_path('migrations/2026_09_08_121907_create_collection_reconciliation_tables.php'))->up();
    }

    protected function tearDown(): void
    {
        try {
            (require database_path('migrations/2026_09_08_121907_create_collection_reconciliation_tables.php'))->down();
            DB::disconnect('reconciliation_fixture');
        } finally {
            $this->tearDownIsolatedDatabase();
            parent::tearDown();
        }
    }

    public function test_concurrent_decisions_cannot_reserve_the_same_remaining_bytes(): void
    {
        $results = $this->makeTempDirectory('reconciliation-budget-race');
        DB::disconnect('reconciliation_fixture');
        $children = [];
        for ($worker = 0; $worker < 4; $worker++) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                DB::purge('reconciliation_fixture');
                try {
                    $allowed = (new TrafficBudget('worker-'.$worker))->reserve(60);
                    file_put_contents($results.'/'.$worker, $allowed ? 'allowed' : 'denied');
                    exit(0);
                } catch (\Throwable $e) {
                    file_put_contents($results.'/'.$worker, $e->getMessage());
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
        $answers = array_map(static fn (int $i): string => file_get_contents($results.'/'.$i), range(0, 3));
        $this->assertSame(1, count(array_filter($answers, static fn (string $answer): bool => $answer === 'allowed')));
        DB::purge('reconciliation_fixture');
        $this->assertSame(60, (int) DB::table('reconciliation_traffic')->where('bucket', 'like', 'hour:%')->value('charged'));
    }

    public function test_competing_workers_cannot_claim_or_mutate_a_live_source(): void
    {
        Schema::create('collections', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedInteger('groups_id');
            $table->string('fromname');
            $table->dateTime('date');
            $table->dateTime('dateadded');
            $table->integer('filecheck')->default(0);
        });
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
        });
        Schema::create('binaries', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('collections_id');
            $table->string('name');
            $table->integer('totalparts');
        });
        Schema::create('parts', function (Blueprint $table): void {
            $table->unsignedBigInteger('binaries_id');
            $table->integer('partnumber');
            $table->string('messageid');
            $table->integer('size');
        });
        try {
            DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.boneless']);
            DB::table('collections')->insert(['id' => 1, 'groups_id' => 1, 'fromname' => 'Synthetic Poster A',
                'date' => now()->subHours(3), 'dateadded' => now()->subHours(3)]);
            DB::table('binaries')->insert(['id' => 1, 'collections_id' => 1, 'name' => '[01/03] - "Example.mkv" yEnc', 'totalparts' => 1]);
            DB::table('parts')->insert(['binaries_id' => 1, 'partnumber' => 1, 'messageid' => '<fixture@example.invalid>', 'size' => 64]);
            $inventory = new PendingInventory;
            $digest = $inventory::digest($inventory->load([1]));
            $claims = new CollectionClaims($inventory);
            $this->assertNotNull($claims->claim([1], 'first', $digest, 1));
            $this->assertNull($claims->claim([1], 'second', $digest, 1));
            $this->assertTrue(CollectionOwnership::protects(1));
            DB::table('reconciliation_claims')->where('collection_id', 1)->update(['lease_until' => now()->subSecond()]);
            $this->assertNotNull($claims->claim([1], 'second', $digest, 1));
            $claims->settle('first', 'obsolete');
            $this->assertSame('second', DB::table('reconciliation_claims')->value('owner'));
        } finally {
            foreach (['parts', 'binaries', 'usenet_groups', 'collections'] as $table) {
                Schema::dropIfExists($table);
            }
        }
    }

    public function test_collection_ownership_has_one_unique_owner_and_real_row_lock_exclusion(): void
    {
        DB::table('reconciliation_claims')->insert(['collection_id' => 1, 'deadline' => now()->addMinutes(15), 'owner' => 'first']);
        config(['database.connections.reconciliation_peer' => config('database.connections.reconciliation_fixture')]);
        $peer = DB::connection('reconciliation_peer');
        $peer->statement('SET SESSION innodb_lock_wait_timeout=1');
        DB::beginTransaction();
        try {
            DB::table('reconciliation_claims')->where('collection_id', 1)->lockForUpdate()->first();
            try {
                $peer->table('reconciliation_claims')->where('collection_id', 1)->update(['owner' => 'second']);
                $this->fail('Another worker acquired a locked source.');
            } catch (QueryException $e) {
                $this->assertSame(1205, (int) $e->errorInfo[1]);
            }
            $this->assertSame('first', DB::table('reconciliation_claims')->value('owner'));
        } finally {
            DB::rollBack();
            DB::disconnect('reconciliation_peer');
        }
        $this->assertSame(0, DB::table('reconciliation_claims')->insertOrIgnore(['collection_id' => 1, 'deadline' => now()->addMinutes(15)]));
    }
}
