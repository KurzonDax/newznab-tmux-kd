<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\NNTP\NntpProvider;
use App\Services\ObfuscationRecovery\RecoveryBudget;
use App\Services\ObfuscationRecovery\RecoveryConfig;
use App\Services\ObfuscationRecovery\RecoveryFrontierRebuild;
use App\Services\ObfuscationRecovery\RecoverySlots;
use App\Services\ObfuscationRecovery\RecoveryStage;
use App\Services\ObfuscationRecovery\RecoveryWork;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ObfuscationRecovery\ChecksRetainedFrontierRebuild;
use Tests\TestCase;

final class RecoveryFrontierMariaDbTest extends TestCase
{
    use ChecksRetainedFrontierRebuild;
    use IsolatedSqliteDatabase;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->prefix = 'frontier_'.bin2hex(random_bytes(6)).'_';
        config(['database.default' => 'frontier_fixture', 'database.connections.frontier_fixture' => [
            'driver' => 'mariadb', 'host' => 'mariadb', 'port' => 3306, 'database' => 'cbp_integration',
            'username' => 'sail', 'password' => 'password', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            'prefix' => $this->prefix, 'strict' => true,
        ]]);
        DB::purge('frontier_fixture');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });
        $this->seedRetainedFrontierFixture();
    }

    protected function tearDown(): void
    {
        $this->stopRecoveryServers();
        $this->travelBack();
        try {
            foreach (Schema::getTables() as $table) {
                if (str_starts_with($table['name'], $this->prefix)) {
                    Schema::drop(substr($table['name'], strlen($this->prefix)));
                }
            }
            DB::disconnect('frontier_fixture');
        } finally {
            $this->tearDownIsolatedDatabase();
            parent::tearDown();
        }
    }

    public function test_overlapping_planners_and_reservations_share_one_historical_grant(): void
    {
        $this->coverage(20001, 40000);
        $this->window(20001, 40000);
        $this->candidate(39000, 39001);
        $this->candidate(39002, 39003);
        $this->fragmentedHistory();
        $this->concurrently(static function (): string {
            (new RecoveryFrontierRebuild)->step();

            return 'planned';
        });
        $this->assertSame(3, DB::table('obfuscation_recovery_frontier_requests')->count());
        $this->assertSame(2, DB::table('obfuscation_recovery_frontier_targets')->count());
        $claim = app(RecoveryWork::class)->claim(RecoveryStage::Download);
        $this->assertNotNull($claim);
        $results = $this->concurrently(static function () use ($claim): string {
            $slots = app(RecoverySlots::class);
            $slot = $slots->acquire(RecoveryConfig::fromSettings());
            if ($slot === null) {
                return 'denied';
            }
            $provider = NntpProvider::fromConfig(['position' => 1, 'name' => 'fixture', 'host' => '127.0.0.1', 'port' => 1]);
            $reservation = app(RecoveryBudget::class)->reserveGap($claim, $slot, $provider);

            return $reservation === null ? 'denied' : 'reserved';
        });
        $this->assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'reserved')));
        $this->assertSame(1, DB::table('obfuscation_recovery_frontier_allowances')->count());
        $this->assertSame(3, DB::table('obfuscation_recovery_attempts')->count());
    }

    public function test_concurrent_claimers_and_planners_redirect_inherited_work_once(): void
    {
        $this->coverage(20001, 40000);
        $this->window(20001, 40000);
        $bundle = $this->candidate(39000, 39001);
        $this->seedInheritedHistory($bundle, 20001, 'r3_pending');
        $results = $this->concurrently(static function (): string {
            if (getmypid() % 2 === 0) {
                (new RecoveryFrontierRebuild)->step();
            }
            $claim = app(RecoveryWork::class)->claim(RecoveryStage::Download);
            if ($claim === null) {
                return 'unclaimed';
            }
            if ($claim->payload['first'] !== 20001 || $claim->payload['last'] !== 40000) {
                throw new \RuntimeException('An inherited clip escaped coalescing.');
            }

            return 'claimed';
        });
        $this->assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'claimed')));
        $this->assertSame(3, DB::table('obfuscation_recovery_frontier_requests')->count());
        $this->assertSame(2, DB::table('obfuscation_recovery_frontier_requests')->where('outcome', 'superseded')->count());
        $this->assertSame(1, DB::table('obfuscation_recovery_frontier_requests')->whereNotNull('superseded_by')->distinct()->count('superseded_by'));
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
    }

    /** @return list<string> */
    private function concurrently(\Closure $operation): array
    {
        $this->assertTrue(function_exists('pcntl_fork'));
        $directory = $this->makeTempDirectory('frontier-race');
        DB::disconnect('frontier_fixture');
        $children = [];
        for ($worker = 0; $worker < 6; $worker++) {
            $pid = pcntl_fork();
            $this->assertGreaterThanOrEqual(0, $pid);
            if ($pid === 0) {
                DB::purge('frontier_fixture');
                try {
                    file_put_contents($directory.'/'.$worker, $operation());
                    exit(0);
                } catch (\Throwable $error) {
                    file_put_contents($directory.'/'.$worker, $error->getMessage());
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        $results = [];
        $statuses = [];
        foreach ($children as $worker => $pid) {
            pcntl_waitpid($pid, $status);
            $result = (string) file_get_contents($directory.'/'.$worker);
            $statuses[] = $status;
            $results[] = $result;
        }
        DB::purge('frontier_fixture');
        foreach ($statuses as $worker => $status) {
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), $results[$worker]);
        }

        return $results;
    }
}
