<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Facades\Search;
use App\Models\Release;
use App\Services\CollectionReconciliation\ArtifactPublication;
use App\Services\CollectionReconciliation\CollectionAdmission;
use App\Services\CollectionReconciliation\CollectionClaims;
use App\Services\CollectionReconciliation\CollectionOwnership;
use App\Services\CollectionReconciliation\PendingInventory;
use App\Services\CollectionReconciliation\TrafficBudget;
use App\Services\CollectionsCleaningService;
use App\Services\Nzb\NzbService;
use App\Services\Releases\ReleaseDuplicateAbsorber;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('preliminaryDeclarations')]
    public function test_locked_admission_observes_a_counterpart_inserted_after_the_repeatable_read_snapshot(int $declaration): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });
        Schema::create('collection_regexes', function (Blueprint $table): void {
            $table->id();
            $table->string('group_regex');
            $table->text('regex');
            $table->integer('status')->default(1);
            $table->integer('ordinal')->default(0);
        });
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
        });
        Schema::create('collections', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedInteger('groups_id');
            $table->string('fromname');
            $table->integer('declaredfiles');
            $table->dateTime('date')->nullable();
            $table->dateTime('dateadded');
            $table->integer('filecheck')->default(0);
            $table->unsignedInteger('releases_id')->nullable();
            $table->index(['groups_id', 'declaredfiles', 'date']);
        });
        Schema::create('binaries', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('collections_id')->index();
            $table->string('name');
            $table->integer('totalparts');
        });
        Schema::create('parts', function (Blueprint $table): void {
            $table->unsignedBigInteger('binaries_id');
            $table->integer('partnumber');
            $table->string('messageid');
            $table->integer('size');
            $table->primary(['binaries_id', 'partnumber']);
        });
        (require database_path('migrations/2026_09_10_134847_add_reconciliation_admissions.php'))->up();
        (require database_path('migrations/2026_09_10_175414_add_collections_admission_window_index.php'))->up();
        config(['database.connections.reconciliation_peer' => config('database.connections.reconciliation_fixture')]);
        DB::purge('reconciliation_peer');
        $peer = DB::connection('reconciliation_peer');
        $peer->statement('SET SESSION innodb_lock_wait_timeout=1');
        try {
            DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'example.group']);
            DB::table('collection_regexes')->insert(['group_regex' => '.*', 'regex' => '/^\[\d+\/\d+\] - "(?P<name>[^.]+)\./']);
            $source = ['groups_id' => 1, 'fromname' => 'Synthetic Poster', 'declaredfiles' => 3,
                'date' => now()->subHours(3), 'dateadded' => now()->subHours(3)];
            DB::table('collections')->insert(['id' => 1, ...$source, 'declaredfiles' => $declaration]);
            DB::table('binaries')->insert(['id' => 1, 'collections_id' => 1, 'name' => '[03/03] - "Example.par2" yEnc', 'totalparts' => 1]);
            DB::table('parts')->insert(['binaries_id' => 1, 'partnumber' => 1, 'messageid' => 'base@example.invalid', 'size' => 100]);
            $this->assertTrue(app(CollectionAdmission::class)->screen([1], 1));
            $this->assertSame(0, DB::table('reconciliation_admissions')->count());
            DB::beginTransaction();
            $this->assertSame(1, DB::table('collections')->count());
            $peer->transaction(static function () use ($peer, $source): void {
                $peer->table('collections')->where('id', 1)->lockForUpdate()->first();
                $peer->table('collections')->where('id', 1)->update(['declaredfiles' => 3]);
                $peer->table('collections')->insert(['id' => 2, ...$source]);
                $peer->table('binaries')->insert(['id' => 2, 'collections_id' => 2, 'name' => '[01/03] - "example.mkv" yEnc', 'totalparts' => 1]);
                $peer->table('parts')->insert(['binaries_id' => 2, 'partnumber' => 1, 'messageid' => 'video@example.invalid', 'size' => 100]);
            });
            $cleaner = \Mockery::mock(CollectionsCleaningService::class);
            $cleaner->shouldReceive('collectionsCleaner')->andReturn(['name' => 'example']);
            $admission = new CollectionAdmission($cleaner);
            $this->assertTrue($admission->lockAndScreen([1], 1));
            $this->assertTrue(CollectionOwnership::protects(1));
            $this->assertSame(2, DB::table('reconciliation_admissions')->count());
            $this->assertSame(1, $peer->table('usenet_groups')->where('id', 1)->update(['name' => 'example.updated']));
            config(['database.default' => 'reconciliation_peer']);
            try {
                $peer->transaction(static fn () => CollectionOwnership::ingest([2]));
                $this->fail('Ingestion passed admission source locks.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, (int) $exception->errorInfo[1]);
            } finally {
                config(['database.default' => 'reconciliation_fixture']);
            }
            try {
                $peer->transaction(static fn () => $peer->table('collections')->insert(['id' => 3, ...$source]));
                $this->fail('A new counterpart slipped into the validated window.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, (int) $exception->errorInfo[1]);
            }
            DB::commit();
            $peer->table('collections')->insert(['id' => 3, ...$source]);
            $this->assertSame(3, $peer->table('collections')->count());
            $peer->table('collections')->where('id', 3)->update(['fromname' => 'synthetic poster']);
            $peer->table('collections')->insert(['id' => 4, ...$source, 'date' => null]);
            $this->assertTrue(DB::transaction(fn (): bool => $admission->lockAndScreen([1, 4], 1)));
            $this->assertFalse(DB::table('reconciliation_admissions')->where('collection_id', 3)->exists());
            $this->assertFalse(DB::table('reconciliation_admissions')->where('collection_id', 4)->exists());
            $this->assertFalse(DB::transaction(fn (): bool => $admission->lockAndScreen([999], 1)));
            DB::table('collections')->insert(['id' => 5, ...$source, 'fromname' => str_repeat('😺', 255)]);
            $this->assertTrue(DB::transaction(fn (): bool => $admission->lockAndScreen([5], 1)));
            (require database_path('migrations/2026_09_10_175414_add_collections_admission_window_index.php'))->down();
            $this->assertSame(5, DB::table('collections')->count());
            $this->assertTrue(Schema::hasIndex('collections', ['groups_id', 'declaredfiles', 'date']));
            $this->assertFalse(Schema::hasIndex('collections', 'collections_admission_window'));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::disconnect('reconciliation_peer');
            (require database_path('migrations/2026_09_10_134847_add_reconciliation_admissions.php'))->down();
            foreach (['parts', 'binaries', 'collections', 'usenet_groups', 'collection_regexes', 'settings'] as $table) {
                Schema::dropIfExists($table);
            }
        }
    }

    public static function preliminaryDeclarations(): array
    {
        return ['potentially admissible' => [3], 'impossible until source lock' => [1]];
    }

    #[DataProvider('artifactContenders')]
    public function test_artifact_rename_barrier_excludes_another_writer_and_survives_process_death(string $contender): void
    {
        Schema::create('releases', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('guid', 40);
            $table->integer('groups_id')->nullable();
            $table->integer('nzbstatus')->default(1);
            $table->double('completion')->default(0);
            $table->integer('totalpart')->default(1);
            $table->timestamp('additional_pp_claimed_at')->nullable();
            $table->timestamp('recovery_claimed_at')->nullable();
        });
        (require database_path('migrations/2026_09_10_140952_create_reconciled_artifact_operations.php'))->up();
        $guid = '53700000-0000-4000-8000-000000000002';
        $original = '<nzb><file subject="ordinary (1/2)" poster="Poster" date="1767268800"><groups><group>example.group</group></groups><segments><segment number="2" bytes="20">two@example.invalid</segment></segments></file></nzb>';
        $target = str_replace('</segments>', '<segment number="1" bytes="10">one@example.invalid</segment></segments>', $original);
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('mariadb-artifact')]);
        $nzbs = app(NzbService::class);
        DB::table('releases')->insert(['id' => 1, 'guid' => $guid]);
        DB::table('reconciled_postings')->insert(['release_id' => 1, 'digest' => str_repeat('a', 64),
            'state' => 'published', 'inventory' => '[]', 'decision' => '{}', 'artifact_digest' => hash('sha256', $original)]);
        file_put_contents($nzbs->getNzbPath($guid, $nzbs->getNzbSplitLevel(), true), gzencode($original));
        Search::shouldReceive('updateRelease')->zeroOrMoreTimes();
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->assertNotFalse($sockets);
        DB::beginTransaction();
        $receipt = $nzbs->replaceNzbContents($guid, $target);
        $this->assertNotNull($receipt->operationId);
        DB::commit();
        DB::disconnect('reconciliation_fixture');
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            DB::purge('reconciliation_fixture');
            $publisher = new class($sockets[1]) extends ArtifactPublication
            {
                public function __construct(private $barrier) {}

                protected function publish(string $temporary, string $path): bool
                {
                    fwrite($this->barrier, "locked\n");
                    if (trim((string) fgets($this->barrier)) !== 'rename') {
                        throw new \RuntimeException('barrier_aborted');
                    }
                    parent::publish($temporary, $path);
                    posix_kill(getmypid(), SIGKILL);
                    exit(99);
                }
            };
            $result = $publisher->execute($receipt->operationId);
            fwrite($sockets[1], 'failed:'.$result->reason."\n");
            exit(1);
        }
        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);
        try {
            $this->assertSame("locked\n", fgets($sockets[0]));
            DB::purge('reconciliation_fixture');
            DB::statement('SET SESSION innodb_lock_wait_timeout=1');
            try {
                if ($contender === 'delete') {
                    $nzbs->deleteNzb($guid);
                } elseif ($contender === 'duplicate') {
                    app(ReleaseDuplicateAbsorber::class)->absorbXml(
                        Release::query()->findOrFail(1), $target, 30, 1, 100.0);
                } else {
                    DB::table('releases')->where('id', 1)->update(['completion' => 55]);
                }
                $this->fail('The concurrent '.$contender.' passed the publication row lock.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, (int) $exception->errorInfo[1]);
            }
            fwrite($sockets[0], "rename\n");
            pcntl_waitpid($pid, $status);
            $this->assertSame(SIGKILL, pcntl_wtermsig($status));
            DB::purge('reconciliation_fixture');
            $this->assertSame($target, $nzbs->readNzbContents($guid));
            $this->assertSame('prepared', DB::table('reconciled_artifact_operations')->value('state'));
            $this->assertSame(1, (int) DB::table('reconciled_artifacts')->value('version'));
            $this->travel(121)->seconds();
            $result = app(ArtifactPublication::class)->execute($receipt->operationId);
            $this->assertTrue($result->success, $result->reason);
            $this->assertSame(2, (int) DB::table('reconciled_artifacts')->value('version'));
            $this->assertSame(100.0, (float) DB::table('releases')->value('completion'));
        } finally {
            @fwrite($sockets[0], "abort\n");
            fclose($sockets[0]);
            pcntl_waitpid($pid, $status, WNOHANG);
            (require database_path('migrations/2026_09_10_140952_create_reconciled_artifact_operations.php'))->down();
            Schema::dropIfExists('releases');
        }
    }

    public static function artifactContenders(): iterable
    {
        yield 'metadata writer' => ['metadata'];
        yield 'explicit deletion overrides interrupted publication' => ['delete'];
        yield 'real duplicate caller cannot cross the rename transaction' => ['duplicate'];
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
