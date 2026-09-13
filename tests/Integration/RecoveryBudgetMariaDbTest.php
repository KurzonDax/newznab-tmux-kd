<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Enums\HeaderScanDirection;
use App\Services\ObfuscationRecovery\RecoveryAlgorithm;
use App\Services\ObfuscationRecovery\RecoveryArtifacts;
use App\Services\ObfuscationRecovery\RecoveryBudget;
use App\Services\ObfuscationRecovery\RecoveryCapture;
use App\Services\ObfuscationRecovery\RecoveryCaptureBatch;
use App\Services\ObfuscationRecovery\RecoveryConfig;
use App\Services\ObfuscationRecovery\RecoveryConstructionTargets;
use App\Services\ObfuscationRecovery\RecoveryEvidenceRetention;
use App\Services\ObfuscationRecovery\RecoveryScanContext;
use App\Services\ObfuscationRecovery\RecoveryStage;
use App\Services\ObfuscationRecovery\RecoveryWork;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\NeverBlacklistedService;
use Tests\Support\ObfuscationRecovery\ChecksFrontierMigration;
use Tests\TestCase;

final class RecoveryBudgetMariaDbTest extends TestCase
{
    use ChecksFrontierMigration;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        config([
            'database.default' => 'recovery_fixture',
            'database.connections.recovery_fixture' => [
                'driver' => 'mariadb', 'host' => 'mariadb', 'port' => 3306,
                'database' => 'cbp_integration', 'username' => 'sail', 'password' => 'password',
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
                'prefix' => 'recovery_'.bin2hex(random_bytes(6)).'_', 'strict' => true,
            ],
        ]);
        DB::purge('recovery_fixture');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
        });
        (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
        (require database_path('migrations/2026_09_13_002751_add_recovery_frontier_evidence.php'))->up();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        try {
            foreach (['obfuscation_recovery_frontier_members', 'obfuscation_recovery_frontier_progress', 'obfuscation_recovery_frontier_targets', 'obfuscation_recovery_frontier_requests', 'obfuscation_recovery_frontier_ranges', 'obfuscation_recovery_frontier_conflicts', 'obfuscation_recovery_frontiers', 'obfuscation_recovery_catalog', 'obfuscation_recovery_coverage', 'obfuscation_recovery_housekeeping', 'obfuscation_recovery_artifacts', 'obfuscation_recovery_references', 'obfuscation_recovery_index_owners', 'obfuscation_recovery_traffic', 'obfuscation_recovery_scan_windows', 'obfuscation_recovery_gaps', 'obfuscation_recovery_expired_headers', 'obfuscation_recovery_dispatch', 'obfuscation_recovery_provider_backoff', 'obfuscation_recovery_targets', 'obfuscation_recovery_headers', 'obfuscation_recovery_dirty', 'obfuscation_recovery_metrics', 'obfuscation_recovery_runs', 'obfuscation_recovery_scans', 'obfuscation_recovery_scan_batches', 'obfuscation_recovery_controls', 'obfuscation_recovery_files', 'obfuscation_recovery_publications', 'obfuscation_recovery_work', 'obfuscation_recovery_bundles', 'obfuscation_recovery_attempts', 'obfuscation_recovery_budgets', 'obfuscation_recovery_budget_owners', 'obfuscation_recovery_slots', 'obfuscation_recovery_evidence', 'usenet_groups', 'settings'] as $table) {
                Schema::dropIfExists($table);
            }
            DB::disconnect('recovery_fixture');
        } finally {
            $this->tearDownIsolatedDatabase();
            parent::tearDown();
        }
    }

    public function test_full_overview_chunk_preserves_interior_date_proofs_on_mariadb(): void
    {
        $queries = (object) ['count' => 0];
        DB::listen(function (QueryExecuted $event) use ($queries): void {
            if ($event->connectionName === 'obfuscation_recovery_capture') {
                $queries->count++;
            }
        });
        DB::table('usenet_groups')->insert(['id' => 1, 'obfuscation_recovery_profile' => 'both']);
        $headers = [];
        for ($article = 1; $article <= 20000; $article++) {
            $headers[] = ['Number' => $article, 'Subject' => 'ordinary marker', 'Date' => gmdate('Y-m-d H:i:s', 1788771600 + $article).' +0000'];
        }
        $capture = new RecoveryCapture(
            RecoveryConfig::fromValues(['obfuscation_recovery_enabled' => 1]), new NeverBlacklistedService);
        $context = new RecoveryScanContext(1, 'alt.binaries.fixture', 'epoch', 1, 1, 20000,
            HeaderScanDirection::Head, (string) Str::uuid());
        $report = $capture->capture(new RecoveryCaptureBatch($headers, []), $context);
        $this->assertTrue($report->coverageComplete);
        $this->assertLessThanOrEqual(128, DB::table('obfuscation_recovery_frontiers')->count());
        $this->assertNull(DB::table('obfuscation_recovery_scans')->value('date_points'));
        $this->assertSame(0, DB::table('obfuscation_recovery_frontier_conflicts')->count());
        $context = new RecoveryScanContext(1, 'alt.binaries.fixture', 'epoch', 1, 1, 20000,
            HeaderScanDirection::Head, (string) Str::uuid());
        $report = $capture->capture(new RecoveryCaptureBatch([$headers[0], $headers[19999]], []), $context);
        $this->assertTrue($report->coverageComplete);
        $this->assertSame(0, DB::table('obfuscation_recovery_frontier_conflicts')->count());
        $retainedPoints = DB::table('obfuscation_recovery_frontiers')->count();
        foreach ($headers as &$header) {
            $header['Date'] = gmdate('Y-m-d H:i:s', 1788771600 + $header['Number'] + 1).' +0000';
        }
        unset($header);
        $context = new RecoveryScanContext(1, 'alt.binaries.fixture', 'epoch', 1, 1, 20000,
            HeaderScanDirection::Head, (string) Str::uuid());
        $report = $capture->capture(new RecoveryCaptureBatch($headers, []), $context);
        $this->assertTrue($report->coverageComplete);
        $this->assertSame($retainedPoints, DB::table('obfuscation_recovery_frontier_conflicts')->where('kind', 'contradiction')->count());
        $this->assertLessThanOrEqual(128, DB::table('obfuscation_recovery_frontiers')->count());
        $this->assertGreaterThan(0, $queries->count);
        $this->assertLessThan(1200, $queries->count);
    }

    public function test_concurrent_requests_cannot_spend_the_same_remaining_allowance(): void
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'The concurrency regression requires pcntl.');
        $results = $this->makeTempDirectory('recovery-concurrency');
        DB::disconnect('recovery_fixture');
        $children = [];
        for ($worker = 0; $worker < 6; $worker++) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                DB::purge('recovery_fixture');
                try {
                    $reservation = app(RecoveryBudget::class)->reserve('shared', 'construction', 'request-'.$worker, 128, 256);
                    file_put_contents($results.'/'.$worker, $reservation === null ? 'denied' : 'reserved');
                    DB::disconnect('recovery_fixture');
                    exit(0);
                } catch (\Throwable $exception) {
                    file_put_contents($results.'/'.$worker, $exception->getMessage());
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        $statuses = [];
        foreach ($children as $worker => $pid) {
            pcntl_waitpid($pid, $status);
            $statuses[$worker] = $status;
        }
        foreach ($statuses as $worker => $status) {
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), (string) file_get_contents($results.'/'.$worker));
        }
        DB::purge('recovery_fixture');
        $accepted = 0;
        for ($worker = 0; $worker < 6; $worker++) {
            $accepted += file_get_contents($results.'/'.$worker) === 'reserved' ? 1 : 0;
        }
        $this->assertSame(2, $accepted);
        $this->assertSame(256, app(RecoveryBudget::class)->spent('shared', 'construction'));
    }

    public function test_a_killed_artifact_writer_leaves_a_bounded_collectible_temporary_file(): void
    {
        $root = $this->makeTempDirectory('killed-artifact-writer');
        DB::disconnect('recovery_fixture');
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            $artifacts = new RecoveryArtifacts($root);
            $artifacts->put((static function (): \Generator {
                yield str_repeat('x', 65536);
                posix_kill(getmypid(), SIGKILL);
            })(), 1048576);
            exit(1);
        }
        pcntl_waitpid($pid, $status);
        DB::purge('recovery_fixture');
        $this->assertTrue(pcntl_wifsignaled($status));
        $this->assertSame(SIGKILL, pcntl_wtermsig($status));
        $this->travel(31)->days();
        $artifacts = new RecoveryArtifacts($root);
        $this->assertSame(1, (new RecoveryEvidenceRetention($artifacts))->step()['artifacts']);
        $this->assertSame([], array_values(array_diff(scandir($root), ['.', '..', '.lock', '.journal.sqlite'])));
    }

    public function test_concurrent_provenance_nominations_share_one_physical_retry_history(): void
    {
        $work = app(RecoveryWork::class);
        $claims = [];
        foreach (range(1, 6) as $i) {
            $work->enqueue(RecoveryStage::Discover, 'scope-'.$i, 1, 'discover', []);
            $claim = $work->claim(RecoveryStage::Discover);
            DB::table('obfuscation_recovery_bundles')->where('id', $claim->bundleId)->update([
                'groups_id' => $i, 'profile' => RecoveryAlgorithm::Media->value,
            ]);
            $claims[] = $claim;
        }
        $results = $this->makeTempDirectory('nomination-concurrency');
        DB::disconnect('recovery_fixture');
        $children = [];
        foreach ($claims as $i => $claim) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                DB::purge('recovery_fixture');
                try {
                    (new RecoveryConstructionTargets)->register($claim, [
                        ['kind' => 'index', 'file_id' => null, 'message_id' => 'same-index@local'],
                    ]);
                    $owner = DB::table('obfuscation_recovery_bundles')->where('id', $claim->bundleId)->value('owner_digest');
                    $budget = app(RecoveryBudget::class);
                    $reservation = $budget->reserve($owner, 'construction', 'same-index@local', 128, 1024);
                    if ($reservation !== null) {
                        $budget->settle($reservation, null, 0, 'transport_failure');
                    }
                    exit(0);
                } catch (\Throwable $error) {
                    file_put_contents($results.'/'.$i, $error->getMessage());
                    exit(1);
                }
            }
            $children[$i] = $pid;
        }
        foreach ($children as $i => $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), is_file($results.'/'.$i) ? file_get_contents($results.'/'.$i) : 'child failed');
        }
        DB::purge('recovery_fixture');
        $attempts = DB::table('obfuscation_recovery_attempts')->count();
        $this->assertGreaterThanOrEqual(1, $attempts);
        $this->assertLessThanOrEqual(2, $attempts);
        foreach (DB::table('obfuscation_recovery_bundles')->pluck('owner_digest') as $owner) {
            $this->assertSame(128 * $attempts, app(RecoveryBudget::class)->spent($owner, 'construction'));
        }
        $this->assertSame(1, DB::table('obfuscation_recovery_index_owners')->count());
    }

    public function test_budget_owner_lookup_uses_its_unique_index(): void
    {
        $budget = app(RecoveryBudget::class);
        $budget->reserve('owner', 'construction', 'target', 128, 256);
        $row = DB::table('obfuscation_recovery_budgets')->first();
        $query = DB::table('obfuscation_recovery_budgets')
            ->where('owner_digest', $row->owner_digest)->where('purpose', 'construction');
        $plan = DB::select('EXPLAIN '.$query->toSql(), $query->getBindings());
        $this->assertSame('recovery_budget_owner', $plan[0]->key);
    }

    public function test_retained_header_discovery_and_expiry_use_bounded_indexes(): void
    {
        $table = DB::connection()->getTablePrefix().'obfuscation_recovery_headers';
        DB::statement("INSERT INTO `{$table}`
            (source_epoch, groups_id, capture_generation, message_id, source_message_id, message_id_digest,
             article_number, raw_subject, poster_identity, source_date, postdate, advertised_bytes,
             advertised_total, embedded_timestamp_ms, profile, key_digest, first_observed_at, last_observed_at)
            SELECT 'epoch', 1, 1, CONCAT('m', seq, '@fixture.invalid'), CONCAT('m', seq, '@fixture.invalid'), SHA2(CONCAT('m', seq), 256),
             4000000000 + seq, 'opaque', 'fixture@example.invalid', '2023-11-14', '2023-11-14 00:00:00', 720000,
             MOD(seq, 1000) + 1, 1700000000000 + seq, 'nyuu-media-v1', SHA2(CONCAT('key', MOD(seq, 1000)), 256),
             '2023-11-14 00:00:00', '2023-11-14 00:00:00' FROM seq_1_to_100000");
        foreach ([
            [DB::table('obfuscation_recovery_headers')->where('source_epoch', 'epoch')->where('groups_id', 1)
                ->where('advertised_total', 20)->where('embedded_timestamp_ms', '>', 1700000000100)
                ->orderBy('embedded_timestamp_ms')->orderBy('message_id')->limit(500), 'recovery_media_discovery'],
            [DB::table('obfuscation_recovery_headers')->where('source_epoch', 'epoch')->where('groups_id', 1)
                ->where('key_digest', hash('sha256', 'key20'))->where('embedded_timestamp_ms', '>', 1700000000100)
                ->orderBy('embedded_timestamp_ms')->orderBy('message_id')->limit(500), 'recovery_rar_discovery'],
            [DB::table('obfuscation_recovery_headers')->where('first_observed_at', '<', '2023-11-15')
                ->orderBy('first_observed_at')->orderBy('id')->limit(500), 'recovery_header_expiry'],
        ] as [$query, $index]) {
            $plan = DB::select('EXPLAIN '.$query->toSql(), $query->getBindings());
            $this->assertSame($index, $plan[0]->key);
            $this->assertStringNotContainsString('filesort', strtolower($plan[0]->Extra));
        }
        $this->assertSame(100000, DB::table('obfuscation_recovery_headers')->count());
    }
}
