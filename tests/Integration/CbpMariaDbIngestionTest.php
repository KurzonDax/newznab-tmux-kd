<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Enums\CollectionDeletionReason;
use App\Enums\CollectionFileCheckStatus;
use App\Enums\CollectionSweepOutcome;
use App\Enums\HeaderScanDirection;
use App\Models\UsenetGroup;
use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinaryHandler;
use App\Services\Binaries\CollectionHandler;
use App\Services\Binaries\HeaderStorageService;
use App\Services\Binaries\PartHandler;
use App\Services\CollectionCleanupService;
use App\Services\CollectionReconciliation\CollectionAdmission;
use App\Services\CollectionReconciliation\PopulationQuery;
use App\Services\CollectionReconciliation\PostingEvidence;
use App\Services\CollectionsCleaningService;
use App\Services\NNTP\NntpProviderPool;
use App\Services\ReleaseProcessingService;
use App\Services\Releases\CollectionDeletionSelection;
use App\Services\Releases\CollectionSweep;
use App\Services\Releases\CollectionSweepLease;
use App\Services\Releases\DueCollectionLookup;
use App\Services\YencService;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Support\CollectionFrontierAssertions;
use Tests\Support\ProductionTables;
use Tests\Support\RecordsTransactionStatements;
use Tests\TestCase;

final class CbpMariaDbIngestionTest extends TestCase
{
    use CollectionFrontierAssertions;
    use RecordsTransactionStatements;

    /** The two keys that cascade a collection's deletion, and the key on parts this change leaves alone. */
    private const COLLECTION_FOREIGN_KEYS = [
        'FK_Collections' => 'binaries.collections_id -> collections.id, update CASCADE, delete CASCADE',
        'FK_binaries' => 'parts.binaries_id -> binaries.id, update CASCADE, delete CASCADE',
        'fk_collection_groups_collection' => 'collection_groups.collections_id -> collections.id, update CASCADE, delete CASCADE',
    ];

    /** Uniqueness and columns of every index that includes collections_id. */
    private const COLLECTION_ID_INDEXES = [
        'binaries.ix_binaries_collection' => [false, ['collections_id']],
        'binaries.ix_binaries_collection_filenumber' => [false, ['collections_id', 'filenumber']],
        'binaries.ix_binaries_collection_hash' => [false, ['collections_id', 'binaryhash']],
        'binaries.ux_binaries_collection_hash' => [true, ['collections_id', 'binaryhash']],
        'collection_groups.PRIMARY' => [true, ['collections_id', 'group_name']],
        'obfuscation_recovery_publications.obfuscation_recovery_publications_collections_id_unique' => [true, ['collections_id']],
    ];

    /**
     * @var array<string, string|false>
     */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $database = getenv('CBP_INTEGRATION_DB_DATABASE');
        if ($database === false || $database === '') {
            return parent::createApplication();
        }
        if ($database !== 'cbp_integration') {
            throw new \RuntimeException('Collection scale tests require the isolated cbp_integration database.');
        }

        foreach (['DB_CONNECTION', 'DB_DATABASE', 'DB_HOST', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
            $this->originalEnvironment[$key] = getenv($key);
        }

        $this->setEnvironmentValue('DB_CONNECTION', 'mariadb');
        $this->setEnvironmentValue('DB_DATABASE', $database);
        $this->setEnvironmentValue('DB_HOST', 'mariadb');
        $this->setEnvironmentValue('DB_USERNAME', (string) getenv('CBP_INTEGRATION_DB_USERNAME'));
        $this->setEnvironmentValue('DB_PASSWORD', (string) getenv('CBP_INTEGRATION_DB_PASSWORD'));

        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! \in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MariaDB/MySQL integration test.');
        }

        if (DB::connection()->getDatabaseName() !== 'cbp_integration') {
            throw new \RuntimeException('Refusing a non-test database.');
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['parts', 'binaries', 'collection_groups', 'collections', 'collection_regexes', 'usenet_group_ingested_ranges', 'usenet_groups', 'collection_sweep_cursors', 'cbp_optimization_checkpoints', 'cbp_binary_map', 'parts_cbp_new', 'parts_cbp_pre_optimize', 'settings'] as $table) {
            DB::statement("DROP TABLE IF EXISTS {$table}");
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        DB::statement('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT NULL) ENGINE=InnoDB');
        DB::statement('CREATE TABLE usenet_groups (id INT UNSIGNED PRIMARY KEY, first_record BIGINT DEFAULT 0, last_record BIGINT DEFAULT 0, last_updated DATETIME NULL, name VARCHAR(255) NOT NULL UNIQUE, active TINYINT DEFAULT 1, backfill TINYINT DEFAULT 1, last_record_postdate DATETIME NULL, first_record_postdate DATETIME NULL, backfill_settled_at DATETIME NULL) ENGINE=InnoDB');
        (require database_path('migrations/2026_09_05_213352_create_usenet_group_ingested_ranges_table.php'))->up();
        (require database_path('migrations/2026_09_10_224820_create_collection_sweep_cursors_table.php'))->up();
        DB::statement('CREATE TABLE collection_regexes (id INT PRIMARY KEY, group_regex VARCHAR(255), regex VARCHAR(255), status TINYINT DEFAULT 1, ordinal INT DEFAULT 0) ENGINE=InnoDB');
        DB::statement('CREATE TABLE collections (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            subject VARCHAR(255) NOT NULL, fromname VARCHAR(255) NOT NULL, date DATETIME NULL,
            xref VARCHAR(2000) NOT NULL DEFAULT \'\', groups_id INT UNSIGNED NOT NULL,
            totalfiles INT UNSIGNED NOT NULL DEFAULT 0, declaredfiles INT UNSIGNED NOT NULL DEFAULT 0,
            collectionhash BINARY(20) NOT NULL,
            collection_regexes_id INT NOT NULL DEFAULT 0, dateadded DATETIME NULL,
            added DATETIME NULL, releases_id INT UNSIGNED NULL,
            last_seen_at DATETIME NULL, last_seen_head_postdate DATETIME NULL, last_seen_tail_postdate DATETIME NULL, filecheck TINYINT NOT NULL DEFAULT 0,
            filesize BIGINT UNSIGNED NOT NULL DEFAULT 0, noise CHAR(32) NOT NULL DEFAULT \'\',
            UNIQUE KEY ix_collection_collectionhash (collectionhash),
            KEY ix_collections_group_filecheck_seen_id (groups_id, filecheck, last_seen_at, id), KEY groups_id (groups_id)
        ) ENGINE=InnoDB');
        DB::statement('CREATE TABLE collection_groups (
            collections_id BIGINT UNSIGNED NOT NULL, group_name VARCHAR(255) NOT NULL,
            PRIMARY KEY (collections_id, group_name),
            CONSTRAINT fk_test_collection_groups FOREIGN KEY (collections_id) REFERENCES collections(id) ON DELETE CASCADE
        ) ENGINE=InnoDB');
        DB::statement('CREATE TABLE binaries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, binaryhash BINARY(16) NOT NULL,
            name VARCHAR(1000) NOT NULL, collections_id BIGINT UNSIGNED NOT NULL,
            filenumber INT UNSIGNED NOT NULL DEFAULT 0, totalparts INT UNSIGNED NOT NULL DEFAULT 0,
            currentparts INT UNSIGNED NOT NULL DEFAULT 0, partcheck TINYINT NOT NULL DEFAULT 0,
            partsize BIGINT UNSIGNED NOT NULL DEFAULT 0,
            UNIQUE KEY ux_binaries_collection_hash (collections_id, binaryhash),
            KEY ix_binaries_collection_filenumber (collections_id, filenumber),
            CONSTRAINT fk_test_binaries FOREIGN KEY (collections_id) REFERENCES collections(id) ON DELETE CASCADE
        ) ENGINE=InnoDB');
        DB::statement('CREATE TABLE parts (
            binaries_id BIGINT UNSIGNED NOT NULL,
            messageid VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            number BIGINT UNSIGNED NOT NULL, partnumber INT UNSIGNED NOT NULL, size INT UNSIGNED NOT NULL,
            PRIMARY KEY (binaries_id, partnumber), KEY ix_parts_number (number),
            CONSTRAINT fk_test_parts FOREIGN KEY (binaries_id) REFERENCES binaries(id) ON DELETE CASCADE
        ) ENGINE=InnoDB');
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.test']);
        DB::table('settings')->insert([
            ['name' => 'delaytime', 'value' => '2'],
            ['name' => 'collection_timeout', 'value' => '48'],
        ]);
    }

    protected function tearDown(): void
    {
        if (\in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            foreach (['collection_sweep_cursors', 'parts', 'parts_cbp_new', 'parts_cbp_pre_optimize', 'cbp_binary_map', 'cbp_optimization_checkpoints', 'binaries', 'collection_groups', 'collections', 'collection_regexes', 'usenet_group_ingested_ranges', 'usenet_groups', 'settings'] as $table) {
                DB::statement("DROP TABLE IF EXISTS {$table}");
            }
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
        parent::tearDown();

        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironmentValue($key, $value === false ? null : $value);
        }
        $this->originalEnvironment = [];
    }

    /** The storage-upgrade test rebuilds the legacy parts shape for this migration to rewrite. */
    protected function historicalSchema(): ?array
    {
        return $this->name() === 'test_reingestion_is_idempotent_and_hot_lookups_use_indexes'
            ? ['migration' => '2026_08_03_000001_finalize_cbp_binary_hash_storage.php', 'tables' => ['parts']]
            : null;
    }

    /** The storage optimizer that migration runs keeps its checkpoints in a working table. */
    protected function fixtureOnlyTables(): array
    {
        return $this->name() === 'test_reingestion_is_idempotent_and_hot_lookups_use_indexes' ? ['cbp_optimization_checkpoints'] : [];
    }

    public function test_every_dense_live_state_yields_after_257_raw_members(): void
    {
        (require database_path('migrations/2026_09_10_175414_add_collections_admission_window_index.php'))->up();
        $queries = new PopulationQuery;
        foreach ([0, 1, 2, 3, 10, 15, 16] as $state) {
            DB::table('collections')->delete();
            DB::statement("INSERT INTO collections (id,subject,fromname,groups_id,declaredfiles,collectionhash,date,dateadded,filecheck)
                SELECT seq,'dense fixture','one prolific poster',1,3,UNHEX(SHA1(CONCAT('dense:',seq))),
                '2026-01-10 08:00:00','2026-01-10 08:00:00',$state FROM seq_1_to_10000");
            $window = $queries->sourceWindow(DB::table('collections')->where('id', 1)->first());
            $before = $this->admissionHandlerReads();
            $read = $queries->readWindow($window);
            $locked = DB::transaction(fn (): array => $queries->lockWindow($window));
            $reads = $this->admissionHandlerReads() - $before;
            $this->assertFalse($read['complete']);
            $this->assertFalse($locked['complete']);
            $this->assertCount(257, $read['rows']);
            $this->assertSame($read['rows']->pluck('id')->all(), $locked['rows']->pluck('id')->all());
            $this->assertLessThan(1100, $reads);
        }
    }

    #[Group('ci-scale')]
    public function test_maintenance_pages_bound_descendant_reads_and_advance_without_matches(): void
    {
        DB::statement('CREATE TABLE releases (id INT UNSIGNED PRIMARY KEY, nzbstatus INT NOT NULL)');
        $metrics = [];
        try {
            foreach ([100000, 1000000] as $size) {
                DB::table('collection_sweep_cursors')->delete();
                DB::table('collections')->delete();
                DB::statement("INSERT INTO collections (id,subject,fromname,groups_id,collectionhash,date,dateadded,added,last_seen_at,filecheck)
                    SELECT seq,'neutral','neutral',100+MOD(seq,20),UNHEX(SHA1(CONCAT('background:',seq))),NOW(),NOW(),NOW(),NOW(),0 FROM seq_1_to_$size");
                DB::statement("INSERT INTO binaries (id,collections_id,binaryhash,name,totalparts,currentparts)
                    SELECT id,id,UNHEX(MD5(CONCAT('binary:',id))),'neutral.rar',1,1 FROM collections");
                DB::statement("INSERT INTO parts (binaries_id,messageid,number,partnumber,size)
                    SELECT id,CONCAT('fixture:',id),id,1,100 FROM binaries");
                foreach (CollectionDeletionReason::cases() as $reason) {
                    foreach ([null, 100] as $group) {
                        $scope = $reason->value.':'.($group === null ? 'global' : 'group:'.$group);
                        $before = $this->admissionHandlerReads();
                        $start = microtime(true);
                        DB::enableQueryLog();
                        $deleted = app(CollectionCleanupService::class)->runMaintenance(new CollectionDeletionSelection($reason), $group)->deleted;
                        $seconds = microtime(true) - $start;
                        $sql = DB::getQueryLog();
                        DB::disableQueryLog();
                        DB::flushQueryLog();
                        $reads = $this->admissionHandlerReads() - $before;
                        $this->assertSame(0, $deleted);
                        $cursor = DB::table('collection_sweep_cursors')->where('scope', $scope)->first();
                        $this->assertNull($cursor->lease_token);
                        if ($group === null) {
                            $this->assertSame(20000, (int) $cursor->last_id);
                        }
                        $pages = array_filter($sql, static fn (array $query): bool => str_contains($query['query'], 'limit 1000'));
                        $this->assertLessThanOrEqual(20, count($pages));
                        $metrics[$size][$scope] = ['reads' => $reads, 'seconds' => $seconds, 'sql_count' => count($sql), 'pages' => count($pages)];
                    }
                }
                DB::table('binaries')->where('collections_id', 20001)->delete();
                $this->assertSame(1, app(CollectionCleanupService::class)->runMaintenance(
                    new CollectionDeletionSelection(CollectionDeletionReason::Orphan))->deleted);
                $this->assertFalse(DB::table('collections')->where('id', 20001)->exists());
                DB::table('collection_sweep_cursors')->where('scope', 'orphan:global')->update(['last_id' => $size]);
                app(CollectionCleanupService::class)->runMaintenance(new CollectionDeletionSelection(CollectionDeletionReason::Orphan))->deleted;
                $this->assertSame(0, (int) DB::table('collection_sweep_cursors')->where('scope', 'orphan:global')->value('high_water_id'));
                DB::table('binaries')->where('collections_id', 1)->delete();
                $this->assertSame(1, app(CollectionCleanupService::class)->runMaintenance(
                    new CollectionDeletionSelection(CollectionDeletionReason::Orphan))->deleted);
                $this->assertFalse(DB::table('collections')->where('id', 1)->exists());
            }
            foreach ($metrics[100000] as $scope => $small) {
                // Group 100 has 5 pages at 100k and reaches the explicit 20-page budget at 1m.
                $pageRatio = $metrics[1000000][$scope]['pages'] / $small['pages'];
                $this->assertLessThanOrEqual($small['reads'] * $pageRatio * 2 + 1000, $metrics[1000000][$scope]['reads']);
            }
            fwrite(STDERR, 'MAINTENANCE_METRICS='.json_encode($metrics, JSON_THROW_ON_ERROR).PHP_EOL);
        } finally {
            DB::statement('DROP TABLE IF EXISTS releases');
        }
    }

    public function test_collection_lock_wait_crossing_lease_expiry_rolls_back_before_deletion(): void
    {
        (new HeaderStorageService)->store([$this->header(1001, 1, 100)], ['id' => 1, 'name' => 'alt.binaries.test']);
        DB::table('collections')->update(['date' => null, 'dateadded' => now()->subDays(10), 'filecheck' => 2]);
        $ready = $this->makeTempPath('collection-lock-ready');
        $waited = $this->makeTempPath('collection-lock-waited');
        $thread = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        $blocker = new Process([PHP_BINARY, base_path('tests/Fixtures/collection-lock-barrier.php'), '1', (string) $thread, $ready, $waited]);
        $blocker->setTimeout(15);
        $blocker->start();
        $deadline = microtime(true) + 5;
        while (! is_file($ready) && microtime(true) < $deadline && $blocker->isRunning()) {
            usleep(1000);
        }
        $this->assertFileExists($ready, $blocker->getErrorOutput());
        $expired = false;
        DB::listen(function (QueryExecuted $event) use (&$expired): void {
            if (! $expired && str_contains($event->sql, 'from `collections`') && str_contains($event->sql, 'for update')) {
                $expired = true;
                Carbon::setTestNow(now()->addSeconds(61));
            }
        });
        try {
            $this->assertSame(0, app(CollectionCleanupService::class)->runMaintenance(
                new CollectionDeletionSelection(CollectionDeletionReason::Retention))->deleted);
            $this->assertSame(0, $blocker->wait(), $blocker->getErrorOutput());
            $this->assertFileExists($waited, 'The blocker must observe a real InnoDB lock wait before releasing its lock.');
            $this->assertTrue($expired);
            $this->assertSame(1, DB::table('collections')->count());
            $this->assertSame(1, DB::table('binaries')->count());
            $this->assertSame(1, DB::table('parts')->count());
            $this->assertSame(0, (int) DB::table('collection_sweep_cursors')->value('last_id'));
        } finally {
            $blocker->stop(0);
            Carbon::setTestNow();
        }
    }

    public function test_a_binary_inserted_after_orphan_selection_survives_current_read_revalidation(): void
    {
        (new HeaderStorageService)->store([$this->header(1001, 1, 100)], ['id' => 1, 'name' => 'alt.binaries.test']);
        DB::table('binaries')->delete();
        config(['database.connections.sweep_peer' => config('database.connections.mariadb')]);
        $peer = DB::connection('sweep_peer');
        $inserted = false;
        DB::listen(function (QueryExecuted $event) use ($peer, &$inserted): void {
            if ($inserted || $event->connectionName !== 'mariadb' || ! str_contains($event->sql, 'not exists')
                || ! str_contains($event->sql, '`binaries`') || str_contains($event->sql, 'for update')) {
                return;
            }
            $inserted = true;
            $peer->table('binaries')->insert(['id' => 2, 'collections_id' => 1, 'binaryhash' => md5('concurrent binary', true), 'name' => 'arrived.rar']);
        });
        try {
            $this->assertSame(0, app(CollectionCleanupService::class)->runMaintenance(
                new CollectionDeletionSelection(CollectionDeletionReason::Orphan))->deleted);
            $this->assertTrue($inserted);
            $this->assertSame(1, DB::table('collections')->count());
            $this->assertSame(1, DB::table('binaries')->count());
        } finally {
            DB::disconnect('sweep_peer');
        }
    }

    public function test_a_superseded_maintenance_worker_cannot_delete_or_advance(): void
    {
        $storage = new HeaderStorageService;
        $storage->store([$this->header(1001, 1, 100)], ['id' => 1, 'name' => 'alt.binaries.test']);
        config(['database.connections.sweep_peer' => config('database.connections.mariadb')]);
        $peer = DB::connection('sweep_peer');
        $replacement = (string) Str::uuid();
        $deleted = (new CollectionSweep)->run('retention', null,
            function (array $ids, CollectionSweepLease $lease) use ($peer, $replacement): int {
                $lease->renew();
                $peer->table('collection_sweep_cursors')->where('scope', $lease->scope)->update([
                    'lease_expires_at' => now()->subSecond(),
                ]);
                $peer->transaction(function () use ($peer, $lease, $replacement): void {
                    $row = $peer->table('collection_sweep_cursors')->where('scope', $lease->scope)->lockForUpdate()->first();
                    $this->assertLessThan(now()->timestamp, strtotime($row->lease_expires_at));
                    $peer->table('collection_sweep_cursors')->where('scope', $lease->scope)->update([
                        'lease_token' => $replacement, 'lease_expires_at' => now()->addMinute(),
                    ]);
                });

                return app(CollectionCleanupService::class)->deleteCollectionsAndDescendants($ids, 'retention',
                    lease: $lease, selection: new CollectionDeletionSelection(CollectionDeletionReason::Retention));
            });
        $this->assertSame(0, $deleted->deleted);
        $this->assertSame(CollectionSweepOutcome::LeaseLost, $deleted->outcome);
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(1, DB::table('binaries')->count());
        $this->assertSame(1, DB::table('parts')->count());
        $row = DB::table('collection_sweep_cursors')->first();
        $this->assertSame(0, (int) $row->last_id);
        $this->assertSame($replacement, $row->lease_token);
        DB::disconnect('sweep_peer');
    }

    public function test_takeover_is_blocked_through_a_fenced_chunk_and_expiry_rolls_it_back(): void
    {
        (require database_path('migrations/2026_09_10_175414_add_collections_admission_window_index.php'))->up();
        $storage = new HeaderStorageService;
        $storage->store([$this->header(1001, 1, 100)], ['id' => 1, 'name' => 'alt.binaries.test']);
        DB::table('collections')->update(['date' => null, 'dateadded' => now()->subDays(10), 'filecheck' => 2]);
        config(['database.connections.sweep_peer' => config('database.connections.mariadb')]);
        $peer = DB::connection('sweep_peer');
        $peer->statement('SET SESSION innodb_lock_wait_timeout=1');
        $attempted = false;
        DB::listen(function (QueryExecuted $event) use ($peer, &$attempted): void {
            if ($attempted || $event->connectionName !== 'mariadb'
                || ! str_contains($event->sql, 'from `collections`') || ! str_contains($event->sql, 'for update')) {
                return;
            }
            $attempted = true;
            try {
                $peer->table('collection_sweep_cursors')->where('scope', 'retention:global')->update(['lease_token' => '11111111-1111-4111-8111-111111111111']);
                $this->fail('A successor acquired a scope while the source mutation held its fence.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, (int) $exception->errorInfo[1]);
            }
            Carbon::setTestNow(now()->addSeconds(61));
        });
        try {
            $deleted = app(CollectionCleanupService::class)->runMaintenance(
                new CollectionDeletionSelection(CollectionDeletionReason::Retention))->deleted;
            $this->assertTrue($attempted);
            $this->assertSame(0, $deleted);
            $this->assertSame(1, DB::table('collections')->count());
            $this->assertSame(1, DB::table('binaries')->count());
            $this->assertSame(1, DB::table('parts')->count());
            $this->assertSame(0, (int) DB::table('collection_sweep_cursors')->value('last_id'));
            $this->assertNull($peer->table('collection_sweep_cursors')->value('lease_token'));
            $peer->table('collection_sweep_cursors')->where('scope', 'retention:global')->update(['lease_token' => '11111111-1111-4111-8111-111111111111']);
            $this->assertSame('11111111-1111-4111-8111-111111111111', $peer->table('collection_sweep_cursors')->value('lease_token'));
        } finally {
            Carbon::setTestNow();
            DB::disconnect('sweep_peer');
        }
    }

    public function test_overlapping_admission_retries_from_a_fresh_transaction_after_ingestion(): void
    {
        (require database_path('migrations/2026_09_10_134847_add_reconciliation_admissions.php'))->up();
        (require database_path('migrations/2026_09_10_175414_add_collections_admission_window_index.php'))->up();
        $connection = DB::getDefaultConnection();
        $main = DB::connection();
        config(['database.connections.admission_retry_peer' => config('database.connections.'.$connection)]);
        $peer = DB::connection('admission_retry_peer');
        $peer->statement('SET SESSION innodb_lock_wait_timeout=1');
        $storage = new HeaderStorageService;
        $group = ['id' => 1, 'name' => 'alt.binaries.test'];
        $first = $this->header(1001, 1, 100);
        $second = $this->header(2001, 1, 100);
        $second['Subject'] = 'Other.Release (1/2)';
        $second['matches'][1] = 'Other.Release';
        $this->assertSame([], $storage->store([$first, $second], $group)->uniqueFailedNumbers());
        $ids = DB::table('collections')->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $this->assertCount(2, $ids);
        $released = false;
        $attempts = 0;
        $this->app['events']->listen(TransactionRolledBack::class,
            function ($event) use (&$released, $peer, $main, $connection, $storage, $group): void {
                if ($event->connection !== $peer || $released) {
                    return;
                }
                $released = true;
                $this->assertSame(0, $peer->transactionLevel());
                $main->commit();
                $this->assertSame(0, (int) $main->table('usenet_groups')->where('id', 1)->value('last_record'));
                config(['database.default' => $connection]);
                try {
                    $this->assertSame([], $storage->store([$this->header(1002, 2, 200)], $group)->uniqueFailedNumbers());
                } finally {
                    config(['database.default' => 'admission_retry_peer']);
                }
            });
        try {
            $main->beginTransaction();
            $this->assertTrue(app(CollectionAdmission::class)->lockAndScreen($ids, 1));
            config(['database.default' => 'admission_retry_peer']);
            $peer->transaction(function () use (&$attempts, $peer, $ids): void {
                $attempts++;
                $peer->table('usenet_groups')->where('id', 1)->update(['last_record' => 100 + $attempts]);
                $this->assertTrue(app(CollectionAdmission::class)->lockAndScreen(array_reverse($ids), 1));
                $this->assertSame(3, $peer->table('parts')->count());
            }, 3);
            $this->assertSame(2, $attempts);
            $this->assertTrue($released);
            $this->assertSame(102, (int) $peer->table('usenet_groups')->where('id', 1)->value('last_record'));
            $this->assertSame(0, $peer->transactionLevel());
            $this->assertSame(0, $main->transactionLevel());
        } finally {
            config(['database.default' => $connection]);
            while ($main->transactionLevel() > 0) {
                $main->rollBack();
            }
            DB::disconnect('admission_retry_peer');
            (require database_path('migrations/2026_09_10_134847_add_reconciliation_admissions.php'))->down();
        }
    }

    #[Group('ci-scale')]
    public function test_admission_work_stays_local_as_background_grows(): void
    {
        $this->travelTo(Carbon::parse('2026-01-01 12:00:00', 'UTC'));
        DB::statement('SET timestamp = 1767268800');
        DB::statement('DROP TABLE IF EXISTS releases');
        DB::statement('CREATE TABLE releases (id INT UNSIGNED PRIMARY KEY, groups_id INT UNSIGNED) ENGINE=InnoDB');
        $this->app->instance(PostingEvidence::class,
            new PostingEvidence(new NntpProviderPool([]), new YencService));
        config(['collection-reconciliation.candidate_limit' => 1]);
        DB::statement('ALTER TABLE collections CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        DB::statement('ALTER TABLE collections ADD KEY fromname (fromname), ADD KEY date (date), ADD KEY ix_collection_dateadded (dateadded), ADD KEY ix_collection_filecheck (filecheck), ADD KEY ix_collection_releaseid (releases_id)');
        (require database_path('migrations/2026_09_08_121907_create_collection_reconciliation_tables.php'))->up();
        (require database_path('migrations/2026_09_10_134847_add_reconciliation_admissions.php'))->up();
        $index = require database_path('migrations/2026_09_10_175414_add_collections_admission_window_index.php');
        $connection = DB::getDefaultConnection();
        config(['database.connections.admission_peer' => config('database.connections.'.$connection),
            'database.connections.admission_observer' => array_replace(config('database.connections.'.$connection), ['username' => 'root', 'password' => 'password'])]);
        $peer = DB::connection('admission_peer');
        $peer->statement('SET SESSION innodb_lock_wait_timeout=1');
        $cleaner = new class extends CollectionsCleaningService
        {
            public function collectionsCleaner(string $subject, string $groupName = ''): array
            {
                return ['id' => 0, 'name' => 'example'];
            }
        };
        $admission = new CollectionAdmission($cleaner);
        $metrics = [];
        try {
            DB::table('usenet_groups')->where('id', 1)->update(['active' => 0, 'backfill' => 0]);
            foreach (range(100, 119) as $id) {
                DB::table('usenet_groups')->insert(['id' => $id, 'name' => 'synthetic.'.$id, 'active' => 0, 'backfill' => 0]);
            }
            foreach ([100000, 1000000] as $background) {
                if ($background > 100000) {
                    DB::table('collections')->where('id', '>', 100000)->delete();
                    $index->down();
                }
                for ($first = $background === 100000 ? 1 : 100001; $first <= $background; $first += 1000) {
                    $rows = [];
                    for ($id = $first; $id < $first + 1000; $id++) {
                        $rows[] = ['id' => $id, 'subject' => 'Synthetic background', 'groups_id' => 100 + $id % 20,
                            'fromname' => 'SyntheticPoster-'.($id % 997), 'declaredfiles' => 2 + $id % 17,
                            'filecheck' => $id % 4, 'date' => gmdate('Y-m-d H:i:s', strtotime('2025-12-01 UTC') + ($id % 30) * 86400),
                            'dateadded' => '2025-12-01 00:00:00', 'added' => '2025-12-01 00:00:00', 'collectionhash' => sha1('background:'.$id, true)];
                    }
                    DB::table('collections')->insert($rows);
                }
                for ($i = 1; $i <= 500; $i++) {
                    $id = $background + $i;
                    DB::table('collections')->insert(['id' => $id, 'subject' => 'Target'.$i, 'groups_id' => 1,
                        'fromname' => $i <= 2 ? 'Synthetic Poster' : 'TargetPoster-'.$i, 'declaredfiles' => 3, 'totalfiles' => 1,
                        'date' => '2026-01-01 09:00:00', 'dateadded' => '2026-01-01 09:00:00', 'last_seen_at' => '2026-01-01 09:00:00',
                        'added' => '2026-01-01 09:00:00', 'filecheck' => 2, 'collectionhash' => sha1('target:'.$i, true)]);
                    DB::table('binaries')->insert(['id' => $id, 'collections_id' => $id, 'binaryhash' => md5('binary:'.$id, true),
                        'name' => $i === 1 ? '[03/03] - "Example.par2" yEnc' : '[01/03] - "example.mkv" yEnc',
                        'totalparts' => 1, 'currentparts' => 1, 'filenumber' => $i === 1 ? 3 : 1, 'partcheck' => 1]);
                    DB::table('parts')->insert(['binaries_id' => $id, 'partnumber' => 1, 'number' => $i, 'size' => 100,
                        'messageid' => $i === 1 ? 'base@example.invalid' : ($i === 2 ? 'video@example.invalid' : 'target-'.$i.'@example.invalid')]);
                }
                DB::statement('ANALYZE TABLE collections');
                $ids = range($background + 1, $background + 500);
                $sources = DB::table('collections')->whereIn('id', $ids)->get();
                $original = DB::table('collections')->where(function ($query) use ($ids, $sources): void {
                    $query->whereIn('id', $ids);
                    foreach ($sources as $source) {
                        $query->orWhere(static fn ($window) => $window->where('groups_id', $source->groups_id)
                            ->where('declaredfiles', $source->declaredfiles)->where('fromname', $source->fromname)
                            ->whereIn('filecheck', [0, 1, 2, 3, 10, 15, 16])
                            ->whereBetween('date', ['2026-01-01 08:00:00', '2026-01-01 10:00:00']));
                    }
                })->orderBy('id')->limit(128501)->lockForUpdate();
                $before = $this->admissionHandlerReads();
                DB::beginTransaction();
                $original->get();
                $oldReads = $this->admissionHandlerReads() - $before;
                $blocked = false;
                try {
                    $peer->table('collections')->where('id', 50000)->update(['subject' => 'unrelated baseline writer']);
                } catch (QueryException $exception) {
                    $this->assertSame(1205, (int) $exception->errorInfo[1]);
                    $blocked = true;
                }
                DB::rollBack();
                $this->assertTrue($blocked || $oldReads > 10000, 'Original query must exhibit broad work or unrelated contention.');
                for ($batch = 0; $batch < 20; $batch++) {
                    $rows = [];
                    for ($i = 0; $i < 1000; $i++) {
                        $distant = $batch < 10;
                        $rows[] = ['subject' => 'Dense selective fixture', 'groups_id' => 1,
                            'fromname' => $distant ? 'TargetPoster-3' : 'TargetPoster-4', 'declaredfiles' => 3,
                            'filecheck' => $distant ? 0 : 4, 'date' => $distant ? '2025-01-01 09:00:00' : '2026-01-01 09:00:00',
                            'dateadded' => '2025-01-01 09:00:00', 'collectionhash' => sha1('dense:'.$batch.':'.$i, true)];
                    }
                    DB::table('collections')->insert($rows);
                }
                $insertBefore = $this->measureAdmissionHeaderInserts($background);
                $buildStart = microtime(true);
                $index->up();
                $buildSeconds = microtime(true) - $buildStart;
                DB::statement('ANALYZE TABLE collections');
                $insertAfter = $this->measureAdmissionHeaderInserts($background + 1000);
                $indexBytes = (int) DB::connection('admission_observer')->selectOne("SELECT COALESCE(SUM(stat_value), 0) * @@innodb_page_size AS bytes FROM mysql.innodb_index_stats WHERE database_name = DATABASE() AND table_name = 'collections' AND index_name = 'collections_admission_window' AND stat_name = 'size'")->bytes;
                $times = [];
                $reads = [];
                for ($sample = 0; $sample < 20; $sample++) {
                    $before = $this->admissionHandlerReads();
                    $start = microtime(true);
                    DB::transaction(function () use ($admission, $background): void {
                        $this->assertTrue($admission->lockAndScreen(range($background + 1, $background + 8), 1));
                    });
                    $times[] = microtime(true) - $start;
                    $reads[] = $this->admissionHandlerReads() - $before;
                }
                DB::enableQueryLog();
                DB::beginTransaction();
                $this->assertTrue($admission->lockAndScreen(range($background + 1, $background + 8), 1));
                $lockedQueries = DB::getQueryLog();
                DB::disableQueryLog();
                DB::flushQueryLog();
                foreach ($lockedQueries as $query) {
                    if (! str_contains($query['query'], 'from `collections` force index')) {
                        continue;
                    }
                    $plan = DB::select('EXPLAIN '.$query['query'], $query['bindings'])[0];
                    $this->assertContains($plan->type, ['const', 'ref', 'range']);
                    $this->assertSame(str_contains($query['query'], 'collections_admission_window') ? 'collections_admission_window' : 'PRIMARY', $plan->key);
                    $this->assertStringNotContainsString('filesort', (string) $plan->Extra);
                }
                $locks = $this->admissionRowLocks();
                $this->assertLessThan(10000, $locks);
                $this->assertSame(1, $peer->table('collections')->where('id', 50000)->update(['subject' => 'unrelated replacement writer '.$background]));
                config(['database.default' => 'admission_peer']);
                try {
                    foreach ([[100, 'synthetic.100', 'SeparateGroup', '2025-01-01 12:00:00'],
                        [1, 'alt.binaries.test', 'UnrelatedPoster', '2026-01-01 09:00:00'],
                        [1, 'alt.binaries.test', 'TargetPoster-3', '2024-01-01 09:00:00']] as $case => [$groupId, $groupName, $poster, $date]) {
                        $storage = new HeaderStorageService;
                        $number = 9000001 + $case;
                        $header = $this->header($number, 1, 100);
                        $header['From'] = $poster;
                        $header['Date'] = $date;
                        $header['Subject'] = '[01/03] - "UnrelatedProbe'.$case.'.mkv" yEnc (1/2)';
                        $header['matches'][1] = '[01/03] - "UnrelatedProbe'.$case.'.mkv" yEnc';
                        $report = $storage->store([$header], ['id' => $groupId, 'name' => $groupName], direction: HeaderScanDirection::Head);
                        $this->assertSame([], $report->uniqueFailedNumbers());
                        $this->assertTrue($peer->table('parts')->where('messageid', '<'.$number.'@example.test>')->exists());
                        $stored = $peer->table('collections')->where('fromname', $poster)->where('date', $date)->first();
                        $this->assertNotNull($stored);
                        $this->assertSame($groupId, (int) $stored->groups_id);
                        $this->assertSame(3, (int) $stored->declaredfiles);
                        $this->assertSame($date, $stored->last_seen_head_postdate);
                    }
                } finally {
                    config(['database.default' => $connection]);
                }
                DB::commit();
                sort($times);
                $this->assertLessThan(1.0, $times[18]);
                DB::table('collections')->where('id', '>', $background + 500)->update(['filecheck' => 4]);
                $processing = app(ReleaseProcessingService::class);
                $processing->setEchoCLI(false);
                $callerMetrics = [];
                $callerMetrics['sizing'] = $this->measureAdmissionCaller(fn () => $processing->processCollectionSizes(1));
                $this->assertSame(498, DB::table('collections')->whereIn('id', $ids)->where('filecheck', 3)->count());
                DB::table('collections')->whereIn('id', array_slice($ids, 2))->update(['filecheck' => 0]);
                $callerMetrics['completeness'] = $this->measureAdmissionCaller(fn () => $processing->processIncompleteCollections(1));
                $this->assertSame(498, DB::table('collections')->whereIn('id', array_slice($ids, 2))->where('filecheck', 2)->count());
                $callerMetrics['cleanup'] = $this->measureAdmissionCaller(fn () => app(CollectionCleanupService::class)->deleteCollectionsAndDescendants($ids));
                $this->assertSame(2, DB::table('collections')->whereIn('id', $ids)->count());
                DB::table('reconciliation_admissions')->delete();
                foreach ([$background + 1, $background + 2] as $binaryId) {
                    DB::table('binaries')->where('id', $binaryId)->update(['totalparts' => 10000, 'currentparts' => 10000]);
                    for ($firstPart = 2; $firstPart <= 10000; $firstPart += 1000) {
                        $parts = [];
                        for ($part = $firstPart; $part < min(10001, $firstPart + 1000); $part++) {
                            $parts[] = ['binaries_id' => $binaryId, 'partnumber' => $part, 'number' => $part,
                                'size' => 100, 'messageid' => 'inventory-'.$binaryId.'-'.$part.'@example.invalid'];
                        }
                        DB::table('parts')->insert($parts);
                    }
                }
                $partsStart = microtime(true);
                $partsReads = $this->admissionHandlerReads();
                $this->assertTrue(DB::transaction(fn (): bool => $admission->lockAndScreen([$background + 1, $background + 2], 1)));
                $partsReads = $this->admissionHandlerReads() - $partsReads;
                $partsSeconds = microtime(true) - $partsStart;
                $this->assertLessThan(1.0, $partsSeconds);
                DB::table('parts')->whereIn('binaries_id', [$background + 1, $background + 2])->where('partnumber', '>', 1)->delete();
                DB::table('binaries')->whereIn('id', [$background + 1, $background + 2])->update(['totalparts' => 1, 'currentparts' => 1]);
                DB::table('reconciliation_admissions')->delete();

                $metrics[$background] = ['original_reads' => $oldReads, 'original_blocked' => $blocked,
                    'reads' => max($reads), 'locks' => $locks, 'p95_seconds' => $times[18], 'index_build_seconds' => $buildSeconds, 'index_bytes' => $indexBytes,
                    'header_insert_p95_before' => $insertBefore, 'header_insert_p95_after' => $insertAfter, 'callers' => $callerMetrics,
                    'supported_20000_parts_seconds' => $partsSeconds, 'supported_20000_parts_reads' => $partsReads];
                DB::table('reconciliation_admissions')->delete();
                DB::table('reconciliation_decisions')->delete();
            }
            $this->assertLessThan($metrics[100000]['reads'] * 2 + 100, $metrics[1000000]['reads']);
            foreach (['sizing', 'completeness', 'cleanup'] as $caller) {
                $this->assertLessThanOrEqual($metrics[100000]['callers'][$caller]['reads'] * 2 + 100,
                    $metrics[1000000]['callers'][$caller]['reads'], $caller.' must include bounded preflight discovery.');
            }
            fwrite(STDERR, 'ADMISSION_METRICS='.json_encode($metrics, JSON_THROW_ON_ERROR).PHP_EOL);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::disconnect('admission_peer');
            DB::disconnect('admission_observer');
            (require database_path('migrations/2026_09_10_134847_add_reconciliation_admissions.php'))->down();
            (require database_path('migrations/2026_09_08_121907_create_collection_reconciliation_tables.php'))->down();
            DB::statement('DROP TABLE IF EXISTS releases');
            DB::statement('SET timestamp = 0');
        }
    }

    private function admissionRowLocks(): int
    {
        $thread = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        $status = DB::connection('admission_observer')->selectOne('SHOW ENGINE INNODB STATUS')->Status;
        preg_match_all('/---TRANSACTION .*?(?=---TRANSACTION|--------\nFILE I\/O)/s', $status, $transactions);
        foreach ($transactions[0] as $transaction) {
            if (str_contains($transaction, 'MariaDB thread id '.$thread.',') && preg_match('/(\d+) row lock\(s\)/', $transaction, $count)) {
                return (int) $count[1];
            }
        }
        $this->fail('The held transaction must appear in the live InnoDB monitor.');
    }

    /** @return array{commits: int, p95_seconds: float, max_locks: int, seconds: float, reads: int, sql_count: int} */
    private function measureAdmissionCaller(callable $operation): array
    {
        $active = true;
        $start = 0.0;
        $times = [];
        $locks = [];
        $this->app['events']->listen(TransactionCommitting::class,
            function ($event) use (&$active, &$locks): void {
                if ($active && $event->connection->transactionLevel() === 1) {
                    $locks[] = $this->admissionRowLocks();
                    $this->assertLessThan(10000, end($locks));
                    if (count($locks) === 1) {
                        $this->assertSame(1, DB::connection('admission_peer')->table('collections')->where('id', 50000)
                            ->update(['subject' => 'Public caller barrier '.microtime(true)]));
                    }
                }
            });
        $this->app['events']->listen(TransactionBeginning::class,
            static function ($event) use (&$active, &$start): void {
                if ($active && $event->connection->transactionLevel() === 1) {
                    $start = microtime(true);
                }
            });
        $this->app['events']->listen(TransactionCommitted::class,
            static function ($event) use (&$active, &$start, &$times): void {
                if ($active && $event->connection->transactionLevel() === 0) {
                    $times[] = microtime(true) - $start;
                }
            });
        DB::flushQueryLog();
        DB::enableQueryLog();
        $readsBefore = $this->admissionHandlerReads();
        $callStart = microtime(true);
        try {
            $operation();
            $callSeconds = microtime(true) - $callStart;
            $callReads = $this->admissionHandlerReads() - $readsBefore;
            $queries = DB::getQueryLog();
        } finally {
            $active = false;
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        foreach ($queries as $query) {
            if (str_starts_with($query['query'], 'select * from `collections`') && str_contains($query['query'], '`date` between')) {
                $this->assertStringContainsString('force index (collections_admission_window)', $query['query']);
                $plan = DB::select('EXPLAIN '.$query['query'], $query['bindings'])[0];
                $this->assertContains($plan->type, ['const', 'ref', 'range']);
                $this->assertStringNotContainsString('filesort', (string) $plan->Extra);
            }
        }
        sort($times);
        $this->assertGreaterThanOrEqual(20, count($times));
        $p95 = $times[(int) floor(count($times) * 0.95)];
        $this->assertLessThan(1.0, $p95);

        return ['commits' => count($times), 'p95_seconds' => $p95, 'max_locks' => max($locks),
            'seconds' => $callSeconds, 'reads' => $callReads, 'sql_count' => count($queries)];
    }

    private function measureAdmissionHeaderInserts(int $offset): float
    {
        $times = [];
        $storage = new HeaderStorageService;
        for ($sample = 1; $sample <= 20; $sample++) {
            $header = $this->header($offset + $sample, 1, 100);
            $header['From'] = 'InsertCost-'.$offset.'-'.$sample;
            $header['Subject'] = 'InsertCost-'.$offset.'-'.$sample.' (1/2)';
            $header['matches'][1] = 'InsertCost-'.$offset.'-'.$sample;
            $header['Date'] = '2025-01-01 12:00:00';
            $start = microtime(true);
            $report = $storage->store([$header], ['id' => 100, 'name' => 'synthetic.100']);
            $this->assertSame([], $report->uniqueFailedNumbers());
            $times[] = microtime(true) - $start;
        }
        sort($times);

        return $times[18];
    }

    private function admissionHandlerReads(): int
    {
        return array_sum(array_map(static fn ($row): int => (int) $row->Value, DB::select("SHOW SESSION STATUS LIKE 'Handler_read%'")));
    }

    private function setEnvironmentValue(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    public function test_concurrent_delete_after_snapshot_resolution_recreates_collection_on_retry(): void
    {
        $service = new HeaderStorageService(new CollectionHandler(new class extends CollectionsCleaningService
        {
            public function collectionsCleaner(string $subject, string $groupName = ''): array
            {
                return ['id' => 0, 'name' => $subject];
            }
        }), config: new BinariesConfig);
        $group = ['id' => 1, 'name' => 'alt.binaries.test'];
        $header = $this->header(1001, 1, 125);
        $this->assertSame([], $service->store([$header], $group)->uniqueFailedNumbers());
        $oldId = (int) DB::table('collections')->value('id');
        config(['database.connections.ingestion_peer' => config('database.connections.'.DB::getDefaultConnection())]);
        $peer = DB::connection('ingestion_peer');
        $deleted = false;
        DB::listen(static function (QueryExecuted $query) use ($peer, &$deleted, $oldId): void {
            if (! $deleted && str_starts_with($query->sql, 'SELECT id, collectionhash FROM collections')) {
                $deleted = true;
                $peer->table('collections')->where('id', $oldId)->delete();
            }
        });
        try {
            $report = $service->store([$header], $group);
            $this->assertTrue($deleted);
            $this->assertSame(1, $report->recoveredChunks);
            $this->assertSame(0, $report->rolledBackChunks);
            $this->assertSame([], $report->uniqueFailedNumbers());
            $this->assertNotSame($oldId, (int) DB::table('collections')->value('id'));
            $this->assertSame(1, DB::table('parts')->count());
        } finally {
            DB::purge('ingestion_peer');
        }
    }

    public function test_reingestion_is_idempotent_and_hot_lookups_use_indexes(): void
    {
        $service = new HeaderStorageService(
            new CollectionHandler(new class extends CollectionsCleaningService
            {
                public function collectionsCleaner(string $subject, string $groupName = ''): array
                {
                    return ['id' => 0, 'name' => $subject];
                }
            }),
            config: new BinariesConfig(headerChunkSize: 2, sqlChunkSize: 2),
        );
        $headers = [$this->header(1001, 1, 125), $this->header(1002, 2, 175)];

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->assertSame([], $service->store($headers, ['id' => 1, 'name' => 'alt.binaries.test'])->uniqueFailedNumbers());
        }

        $binary = DB::table('binaries')->first();
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(1, DB::table('binaries')->count());
        $this->assertSame(2, DB::table('parts')->count());
        $this->assertSame(2, (int) $binary->currentparts);
        $this->assertSame(300, (int) $binary->partsize);
        $this->assertSame(300, (int) DB::table('collections')->value('filesize'));

        $plan = DB::selectOne(
            'EXPLAIN FORMAT=JSON SELECT id FROM binaries WHERE collections_id = ? AND binaryhash = ?',
            [1, $binary->binaryhash]
        );
        $json = strtolower((string) array_values((array) $plan)[0]);
        $this->assertStringContainsString('ux_binaries_collection_hash', $json);

        $this->assertSame(0, Artisan::call('cbp:optimize-storage'));
        $this->assertStringContainsString('Dry-run only', Artisan::output());

        $this->restoreLegacyStorageShape();
        $this->assertSame(2, DB::table('parts')->count());
        config()->set('nntmux.cbp.reconcile_batch_size', 2);

        $migration = require database_path('migrations/2026_08_03_000001_finalize_cbp_binary_hash_storage.php');
        config()->set('nntmux.cbp.storage_migration_execute', false);
        try {
            $migration->up();
            $this->fail('The legacy storage migration ran without explicit maintenance approval.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('CBP_STORAGE_MIGRATION_EXECUTE=true', $exception->getMessage());
        }
        $this->assertSame(2, DB::table('parts')->count());

        config()->set('nntmux.cbp.storage_migration_execute', true);
        $migration->up();

        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(1, DB::table('binaries')->count());
        $this->assertSame(2, DB::table('parts')->count());
        $this->assertSame(300, (int) DB::table('binaries')->value('partsize'));
    }

    public function test_a_stored_chunk_counts_its_parts_without_an_aggregate_over_parts(): void
    {
        $storage = $this->countedStorage();
        $group = ['id' => 1, 'name' => 'alt.binaries.test'];

        $first = $this->transactionStatements(fn () => $this->assertSame([], $storage->store([
            $this->fileHeader(2001, 'alpha', 1, 5, 100),
            $this->fileHeader(2002, 'alpha', 2, 5, 150),
            $this->fileHeader(2003, 'beta', 1, 3, 40),
        ], $group)->uniqueFailedNumbers()));
        $this->assertNoAggregateOverParts($first);
        $this->assertSame(['alpha' => [2, 250, 0], 'beta' => [1, 40, 0]], $this->binaryCounts());

        $second = $this->transactionStatements(fn () => $this->assertSame([], $storage->store([
            $this->fileHeader(2004, 'alpha', 3, 5, 175),
        ], $group)->uniqueFailedNumbers()));
        $this->assertNoAggregateOverParts($second);
        $this->assertSame(['alpha' => [3, 425, 0], 'beta' => [1, 40, 0]], $this->binaryCounts());
        $this->assertBinaryCountsEqualStoredParts();
    }

    public function test_a_binary_is_complete_only_in_the_chunk_that_stores_its_last_part(): void
    {
        $storage = $this->countedStorage();
        $group = ['id' => 1, 'name' => 'alt.binaries.test'];

        $storage->store([$this->fileHeader(2101, 'alpha', 1, 4, 100)], $group);
        $this->assertSame(['alpha' => [1, 100, 0]], $this->binaryCounts());

        // Two parts in one chunk leave the file exactly one part short.
        $storage->store([$this->fileHeader(2102, 'alpha', 2, 4, 100), $this->fileHeader(2103, 'alpha', 3, 4, 100)], $group);
        $this->assertSame(['alpha' => [3, 300, 0]], $this->binaryCounts());

        $storage->store([$this->fileHeader(2104, 'alpha', 4, 4, 100)], $group);
        $this->assertSame(['alpha' => [4, 400, 1]], $this->binaryCounts());
    }

    public function test_a_higher_total_reopens_a_complete_binary_the_chunk_stores_nothing_for(): void
    {
        $storage = $this->countedStorage();
        $group = ['id' => 1, 'name' => 'alt.binaries.test'];

        $storage->store([$this->fileHeader(2201, 'alpha', 1, 2, 100), $this->fileHeader(2202, 'alpha', 2, 2, 100)], $group);
        $this->assertSame(['alpha' => [2, 200, 1]], $this->binaryCounts());

        // The same second part again, now announcing three parts: nothing is stored, the total rises.
        $this->assertSame([], $storage->store([$this->fileHeader(2202, 'alpha', 2, 3, 100)], $group)->uniqueFailedNumbers());
        $this->assertSame(3, (int) DB::table('binaries')->value('totalparts'));
        $this->assertSame(['alpha' => [2, 200, 0]], $this->binaryCounts());
    }

    public function test_a_chunk_of_new_and_already_stored_parts_counts_only_the_new_ones(): void
    {
        $storage = $this->countedStorage();
        $group = ['id' => 1, 'name' => 'alt.binaries.test'];
        $storage->store([
            $this->fileHeader(2401, 'alpha', 1, 5, 100),
            $this->fileHeader(2402, 'alpha', 2, 5, 150),
            $this->fileHeader(2403, 'beta', 1, 3, 40),
        ], $group);

        $this->assertSame([], $storage->store([
            $this->fileHeader(2402, 'alpha', 2, 5, 150),
            $this->fileHeader(2404, 'alpha', 3, 5, 175),
            $this->fileHeader(2403, 'beta', 1, 3, 40),
            $this->fileHeader(2405, 'beta', 2, 3, 60),
            $this->fileHeader(2406, 'gamma', 1, 2, 25),
        ], $group)->uniqueFailedNumbers());

        $this->assertSame(['alpha' => [3, 425, 0], 'beta' => [2, 100, 0], 'gamma' => [1, 25, 0]], $this->binaryCounts());
        $this->assertBinaryCountsEqualStoredParts();
    }

    public function test_a_chunk_of_already_stored_parts_leaves_the_counts_unchanged(): void
    {
        $storage = $this->countedStorage();
        $group = ['id' => 1, 'name' => 'alt.binaries.test'];
        $headers = [
            $this->fileHeader(2501, 'alpha', 1, 5, 100),
            $this->fileHeader(2502, 'alpha', 2, 5, 150),
            $this->fileHeader(2503, 'beta', 1, 1, 40),
        ];
        $storage->store($headers, $group);
        $this->assertSame(['alpha' => [2, 250, 0], 'beta' => [1, 40, 1]], $this->binaryCounts());

        $statements = $this->transactionStatements(fn () => $this->assertSame([], $storage->store($headers, $group)->uniqueFailedNumbers()));

        $this->assertNoAggregateOverParts($statements);
        $this->assertSame(['alpha' => [2, 250, 0], 'beta' => [1, 40, 1]], $this->binaryCounts());
        $this->assertSame(3, DB::table('parts')->count());
    }

    public function test_parts_stored_by_several_inserts_of_one_chunk_are_all_counted(): void
    {
        $storage = $this->countedStorage(new BinariesConfig(headerChunkSize: 4, sqlChunkSize: 2));

        $statements = $this->transactionStatements(fn () => $this->assertSame([], $storage->store([
            $this->fileHeader(2601, 'alpha', 1, 4, 100),
            $this->fileHeader(2602, 'alpha', 2, 4, 150),
            $this->fileHeader(2603, 'alpha', 3, 4, 175),
            $this->fileHeader(2604, 'alpha', 4, 4, 60),
        ], ['id' => 1, 'name' => 'alt.binaries.test'])->uniqueFailedNumbers()));

        $this->assertCount(1, $statements);
        $this->assertCount(2, array_filter($statements[0], static fn (array $statement): bool => str_starts_with($statement['sql'], 'INSERT IGNORE INTO parts')));
        $this->assertSame(['alpha' => [4, 485, 1]], $this->binaryCounts());
    }

    public function test_a_rolled_back_chunk_leaves_the_counts_of_its_binaries_as_they_were(): void
    {
        $storage = $this->countedStorage();
        $group = ['id' => 1, 'name' => 'alt.binaries.test'];
        $storage->store([
            $this->fileHeader(2701, 'alpha', 1, 3, 100),
            $this->fileHeader(2702, 'alpha', 2, 3, 100),
            $this->fileHeader(2703, 'beta', 1, 1, 50),
        ], $group);
        $this->assertSame(['alpha' => [2, 200, 0], 'beta' => [1, 50, 1]], $this->binaryCounts());

        // The chunk completes alpha and reopens beta, then fails on the collection totals.
        $binaryUpdates = 0;
        DB::listen(static function (QueryExecuted $query) use (&$binaryUpdates): void {
            $binaryUpdates += str_starts_with(ltrim($query->sql), 'UPDATE binaries') ? 1 : 0;
        });
        DB::connection()->beforeExecuting(static function (string $sql): void {
            if (str_starts_with(ltrim($sql), 'UPDATE collections')) {
                $failure = new \PDOException('Forced failure of the collection totals');
                $failure->errorInfo = ['HY000', 1105, $failure->getMessage()];
                throw new QueryException('mariadb', $sql, [], $failure);
            }
        });
        $report = $storage->store([
            $this->fileHeader(2704, 'alpha', 3, 3, 100),
            $this->fileHeader(2703, 'beta', 1, 2, 50),
        ], $group);

        $this->assertSame(1, $report->rolledBackChunks);
        $this->assertGreaterThan(0, $binaryUpdates);
        $this->assertSame(['alpha' => [2, 200, 0], 'beta' => [1, 50, 1]], $this->binaryCounts());
        $this->assertSame(3, DB::table('parts')->count());
        $this->assertBinaryCountsEqualStoredParts();
    }

    public function test_a_failed_binary_count_update_rolls_the_chunk_back_before_the_collection_totals(): void
    {
        $storage = $this->countedStorage();
        $group = ['id' => 1, 'name' => 'alt.binaries.test'];
        $storage->store([$this->fileHeader(2751, 'alpha', 1, 3, 100)], $group);

        $collectionUpdates = 0;
        DB::listen(static function (QueryExecuted $query) use (&$collectionUpdates): void {
            $collectionUpdates += str_starts_with(ltrim($query->sql), 'UPDATE collections') ? 1 : 0;
        });
        DB::connection()->beforeExecuting(static function (string $sql): void {
            if (str_starts_with($sql, 'UPDATE binaries SET')) {
                $failure = new \PDOException('Forced failure of the binary counts');
                $failure->errorInfo = ['HY000', 1105, $failure->getMessage()];
                throw new QueryException('mariadb', $sql, [], $failure);
            }
        });
        $report = $storage->store([$this->fileHeader(2752, 'alpha', 2, 3, 100)], $group);

        $this->assertSame(1, $report->rolledBackChunks);
        $this->assertSame([2752], $report->uniqueFailedNumbers());
        $this->assertSame(0, $collectionUpdates);
        $this->assertSame(['alpha' => [1, 100, 0]], $this->binaryCounts());
        $this->assertSame(1, DB::table('parts')->count());
    }

    public function test_the_parts_insert_reports_only_the_rows_it_stored(): void
    {
        $this->insertCollectionTree(1, now(), now(), 1);
        $this->insertCollectionTree(2, now(), now(), 1);
        $handler = new PartHandler(2);

        // Binary 1 already holds part 1. The first insert offers it again with a new part 2.
        $this->assertTrue($handler->addPart(1, $this->fileHeader(2801, 'alpha', 1, 9, 500)));
        $this->assertTrue($handler->addPart(1, $this->fileHeader(2802, 'alpha', 2, 9, 120)));
        // The second insert offers part 2 again, stored a moment ago, with a new part 3.
        $this->assertTrue($handler->addPart(1, $this->fileHeader(2803, 'alpha', 2, 9, 120)));
        $this->assertTrue($handler->addPart(1, $this->fileHeader(2804, 'alpha', 3, 9, 80)));
        $this->assertTrue($handler->addPart(2, $this->fileHeader(2805, 'beta', 2, 9, 55)));
        $this->assertTrue($handler->flush());

        $this->assertSame([1 => ['parts' => 2, 'bytes' => 200], 2 => ['parts' => 1, 'bytes' => 55]], $handler->getStoredByBinary());
        $this->assertSame([1, 2], $handler->getTouchedBinaryIds());
        $this->assertSame([], $handler->getFailedNumbers());

        // A part whose binary does not exist is dropped by the insert: not counted, and reported failed.
        $handler->reset();
        $this->assertTrue($handler->addPart(999, $this->fileHeader(2806, 'gone', 1, 9, 70)));
        $this->assertFalse($handler->flush());
        $this->assertSame([], $handler->getStoredByBinary());
        $this->assertSame([2806], $handler->getFailedNumbers());
    }

    public function test_no_parts_insert_follows_a_failed_one_until_reset(): void
    {
        $this->insertCollectionTree(1, now(), now(), 1);
        $failNext = true;
        $inserts = 0;
        DB::connection()->beforeExecuting(static function (string $sql) use (&$failNext, &$inserts): void {
            if (! str_starts_with($sql, 'INSERT IGNORE INTO parts')) {
                return;
            }
            $inserts++;
            if ($failNext) {
                $failNext = false;
                $failure = new \PDOException('Deadlock found when trying to get lock; try restarting transaction');
                $failure->errorInfo = ['40001', 1213, $failure->getMessage()];
                throw new QueryException('mariadb', $sql, [], $failure);
            }
        });

        $handler = new PartHandler(2);
        $this->assertTrue($handler->addPart(1, $this->fileHeader(2901, 'alpha', 2, 9, 100)));
        $this->assertFalse($handler->addPart(1, $this->fileHeader(2902, 'alpha', 3, 9, 100)));
        $handler->addPart(1, $this->fileHeader(2903, 'alpha', 4, 9, 100));
        $handler->addPart(1, $this->fileHeader(2904, 'alpha', 5, 9, 100));
        $handler->addPart(1, $this->fileHeader(2905, 'alpha', 6, 9, 100));
        $this->assertFalse($handler->flush());

        $this->assertSame(1, $inserts);
        $this->assertSame([2901, 2902, 2903, 2904, 2905], $handler->getFailedNumbers());
        $this->assertSame([], $handler->getStoredByBinary());
        $this->assertSame(1, DB::table('parts')->count());

        $handler->reset();
        $this->assertTrue($handler->addPart(1, $this->fileHeader(2903, 'alpha', 4, 9, 100)));
        $this->assertTrue($handler->flush());
        $this->assertSame(2, $inserts);
        $this->assertSame([], $handler->getFailedNumbers());
        $this->assertSame([1 => ['parts' => 1, 'bytes' => 100]], $handler->getStoredByBinary());
        $this->assertSame(2, DB::table('parts')->count());
    }

    public function test_stored_parts_are_added_to_every_touched_binary_in_bounded_statements(): void
    {
        // currentparts, totalparts and partcheck before the chunk; no parts row backs them.
        foreach ([1 => [60, 100, 0], 2 => [99, 100, 0], 3 => [2, 3, 1], 4 => [5, 10, 0]] as $id => [$current, $total, $check]) {
            $this->insertCollectionTree($id, now(), now(), 1);
            DB::table('binaries')->where('id', $id)->update(['currentparts' => $current, 'totalparts' => $total, 'partcheck' => $check, 'partsize' => $current * 10]);
        }
        $statements = [];
        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        // Binary 3 kept its two parts while its total rose to three; binary 4 received nothing.
        $this->assertTrue((new BinaryHandler(2))->addStoredParts(
            [1, 3, 2, 4],
            [1 => ['parts' => 30, 'bytes' => 3000], 2 => ['parts' => 1, 'bytes' => 75]],
        ));

        $this->assertCount(2, $statements);
        foreach ($statements as $sql) {
            $this->assertStringStartsWith('UPDATE binaries', $sql);
            $this->assertDoesNotMatchRegularExpression('/\bparts\b/i', $sql);
        }
        $this->assertSame(
            [1 => [90, 3600, 0], 2 => [100, 1065, 1], 3 => [2, 20, 0], 4 => [5, 50, 0]],
            DB::table('binaries')->orderBy('id')->get()->mapWithKeys(static fn (object $binary): array => [
                (int) $binary->id => [(int) $binary->currentparts, (int) $binary->partsize, (int) $binary->partcheck],
            ])->all(),
        );
    }

    public function test_ingestion_stamps_are_monotone_and_repair_uses_current_head(): void
    {
        $storage = new HeaderStorageService;
        $header = $this->header(8001, 1, 100);
        $header['Date'] = '2026-08-01 12:00:00';
        // A group frontier earlier than every forward header leaves the forward stamps to the headers.
        $group = ['id' => 1, 'name' => 'alt.binaries.test', 'last_record_postdate' => '2026-07-31 12:00:00'];
        $storage->store([$header], $group, direction: HeaderScanDirection::Head);
        $this->assertSame('2026-08-01 12:00:00', DB::table('collections')->value('last_seen_head_postdate'));
        $header['Date'] = '2026-08-01 10:00:00';
        $storage->store([$header], $group, direction: HeaderScanDirection::Head);
        $this->assertSame('2026-08-01 12:00:00', DB::table('collections')->value('last_seen_head_postdate'));
        $header['Date'] = '2026-08-01 13:00:00';
        $storage->store([$header], $group, direction: HeaderScanDirection::Head);
        $this->assertSame('2026-08-01 13:00:00', DB::table('collections')->value('last_seen_head_postdate'));
        $header['Date'] = '2026-08-01 10:00:00';
        $storage->store([$header], $group, direction: HeaderScanDirection::Tail);
        $header['Date'] = '2026-08-01 11:00:00';
        $storage->store([$header], $group, direction: HeaderScanDirection::Tail);
        $this->assertSame('2026-08-01 10:00:00', DB::table('collections')->value('last_seen_tail_postdate'));
        $group['last_record_postdate'] = '2026-08-02 12:00:00';
        $storage->store([$header], $group, direction: HeaderScanDirection::Repair);
        $this->assertSame('2026-08-02 12:00:00', DB::table('collections')->value('last_seen_head_postdate'));
        $this->assertNotNull(DB::table('collections')->value('last_seen_at'));
    }

    public function test_head_waits_for_missing_chunk_then_coalesces_completed_ranges(): void
    {
        DB::table('usenet_groups')->where('id', 1)->update(['last_record' => 1000]);
        UsenetGroup::advanceLastRecordContiguously(1, 1101, 1200, (int) strtotime('2026-08-17 12:00:00'));
        $this->assertSame(1000, (int) DB::table('usenet_groups')->value('last_record'));
        $this->assertDatabaseHas('usenet_group_ingested_ranges', ['first_record' => 1101, 'last_record' => 1200]);

        UsenetGroup::advanceLastRecordContiguously(1, 1001, 1100, (int) strtotime('2026-08-17 11:00:00'));
        $this->assertDatabaseHas('usenet_groups', ['id' => 1, 'last_record' => 1200, 'last_record_postdate' => '2026-08-17 12:00:00']);
        $this->assertSame(0, DB::table('usenet_group_ingested_ranges')->count());

        UsenetGroup::advanceLastRecordContiguously(1, 1201, 1300, null);
        $this->assertDatabaseHas('usenet_groups', ['id' => 1, 'last_record' => 1300, 'last_record_postdate' => '2026-08-17 12:00:00']);
    }

    public function test_collection_promotion_uses_the_quiet_clock(): void
    {
        $this->insertCollectionTree(10, now()->subMinutes(30), now()->subHours(3), 0);
        $this->insertCollectionTree(11, now()->subHours(3), now()->subMinutes(30), 0);
        $this->insertCollectionTree(12, now()->subHours(3), null, 0);
        $this->insertCollectionTree(
            13,
            now()->subMinutes(30),
            now()->subHours(3),
            1,
            CollectionFileCheckStatus::TempComplete,
        );

        app(ReleaseProcessingService::class)->setEchoCLI(false)->processIncompleteCollections(1);

        $collections = DB::table('collections')->whereIn('id', [10, 11, 12, 13])->get()->keyBy('id');

        $this->assertSame(CollectionFileCheckStatus::CompleteParts->value, (int) $collections[10]->filecheck);
        $this->assertSame(1, (int) $collections[10]->totalfiles);
        $this->assertSame(CollectionFileCheckStatus::Default->value, (int) $collections[11]->filecheck);
        $this->assertSame(0, (int) $collections[11]->totalfiles);
        $this->assertSame(CollectionFileCheckStatus::CompleteParts->value, (int) $collections[12]->filecheck);
        $this->assertSame(1, (int) $collections[12]->totalfiles);
        $this->assertSame(CollectionFileCheckStatus::CompleteParts->value, (int) $collections[13]->filecheck);
        $this->assertSame(1, (int) $collections[13]->totalfiles);
    }

    public function test_due_lookups_and_stuck_maintenance_find_due_collections_through_their_indexes(): void
    {
        (require database_path('migrations/2026_10_20_180000_add_collection_due_lookups.php'))->up();
        $this->assertTrue(Schema::hasIndex('collections', 'ix_collections_group_filecheck_head_id'));
        DB::table('usenet_groups')->where('id', 1)->update(['active' => 1, 'last_record_postdate' => '2026-01-01 12:00:00']);
        DB::table('usenet_groups')->insert(['id' => 2, 'name' => 'alt.binaries.inactive', 'active' => 0]);
        $collection = static function (int $id, int $group, ?string $head, string $seen): void {
            DB::table('collections')->insert(['id' => $id, 'subject' => 'Due.'.$id, 'fromname' => 'poster@example.test',
                'groups_id' => $group, 'collectionhash' => hash('sha1', 'due-'.$id, true), 'filecheck' => 0,
                'last_seen_head_postdate' => $head, 'last_seen_at' => DB::raw($seen)]);
        };
        $collection(1, 1, null, 'NOW()');
        $collection(2, 1, '2026-01-01 09:00:00', 'NOW()');
        $collection(3, 1, '2026-01-01 11:00:00', 'DATE_SUB(NOW(), INTERVAL 40 MINUTE)');
        $collection(7, 2, '2026-01-01 11:00:00', 'DATE_SUB(NOW(), INTERVAL 3 HOUR)');
        $collection(8, 2, '2026-01-01 11:00:00', 'DATE_SUB(NOW(), INTERVAL 10 MINUTE)');
        $lookup = static function (int $group): array {
            $found = [];
            app(DueCollectionLookup::class)->run('formation:0', $group, 0, 2,
                static function (array $ids) use (&$found): int {
                    array_push($found, ...$ids);

                    return 0;
                }, touched: true, lateMinutes: 15);
            sort($found);

            return $found;
        };

        $this->assertSame([1, 2], $lookup(1));
        $this->assertSame([7], $lookup(2));
        // Touched since that pass, and crossed the late line since it.
        $collection(4, 1, '2026-01-01 11:00:00', 'DATE_SUB(NOW(), INTERVAL 1 MINUTE)');
        $collection(5, 1, '2026-01-01 11:00:00', 'DATE_SUB(NOW(), INTERVAL 16 MINUTE)');
        $this->assertSame([1, 2, 4, 5], $lookup(1));

        $collection(9, 1, '2025-12-29 00:00:00', 'NOW()');
        $stuck = new CollectionDeletionSelection(CollectionDeletionReason::Stuck, 48);
        $this->assertSame(1, app(CollectionCleanupService::class)->runMaintenance($stuck, 1)->deleted);
        $this->assertFalse(DB::table('collections')->where('id', 9)->exists());
        $collection(10, 1, '2025-12-29 00:00:00', 'NOW()');
        $result = app(CollectionCleanupService::class)->runMaintenance($stuck, 1);
        // The lookup also hands over collection 1, which has no head stamp; the walk rests for the hour.
        $this->assertSame([2, 1, CollectionSweepOutcome::Resting], [$result->examined, $result->deleted, $result->outcome]);
    }

    public function test_a_late_collection_is_promoted_after_fifteen_quiet_minutes(): void
    {
        DB::statement('DROP TABLE IF EXISTS releases');
        DB::statement('CREATE TABLE releases (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, collectionhash BINARY(20) NULL,
            UNIQUE KEY ux_releases_collectionhash (collectionhash)) ENGINE=InnoDB');
        try {
            $frontier = '2026-09-02 12:00:00';
            DB::table('usenet_groups')->where('id', 1)->update(['last_record_postdate' => $frontier]);
            $this->insertCollectionTree(20, now()->subHours(3), now()->subMinutes(16), 0);
            $this->insertCollectionTree(21, now()->subHours(3), now()->subMinutes(14), 0);
            $this->insertCollectionTree(22, now()->subHours(3), now()->subMinutes(16), 0);
            DB::table('collections')->whereIn('id', [20, 21, 22])->update(['last_seen_head_postdate' => $frontier]);
            foreach ([20, 21] as $late) {
                DB::table('releases')->insert(['collectionhash' => DB::table('collections')->where('id', $late)->value('collectionhash')]);
            }

            app(ReleaseProcessingService::class)->setEchoCLI(false)->processIncompleteCollections(1);

            $collections = DB::table('collections')->whereIn('id', [20, 21, 22])->get()->keyBy('id');
            $this->assertSame(CollectionFileCheckStatus::CompleteParts->value, (int) $collections[20]->filecheck);
            $this->assertSame(1, (int) $collections[20]->totalfiles);
            $this->assertSame(CollectionFileCheckStatus::Default->value, (int) $collections[21]->filecheck);
            $this->assertSame(CollectionFileCheckStatus::Default->value, (int) $collections[22]->filecheck);
        } finally {
            DB::statement('DROP TABLE IF EXISTS releases');
        }
    }

    private function insertCollectionTree(
        int $id,
        \DateTimeInterface $dateAdded,
        ?\DateTimeInterface $lastSeenAt,
        int $totalFiles,
        CollectionFileCheckStatus $fileCheck = CollectionFileCheckStatus::Default,
    ): void {
        DB::table('collections')->insert([
            'id' => $id,
            'subject' => "Quiet.Clock.{$id}",
            'fromname' => 'poster@example.test',
            'date' => $dateAdded,
            'xref' => "alt.binaries.test:{$id}",
            'groups_id' => 1,
            'totalfiles' => $totalFiles,
            'collectionhash' => hash('sha1', "quiet-clock-{$id}", true),
            'dateadded' => $dateAdded,
            'added' => $dateAdded,
            'last_seen_at' => $lastSeenAt,
            'filecheck' => $fileCheck->value,
        ]);
        DB::table('binaries')->insert([
            'id' => $id,
            'binaryhash' => hash('md5', "quiet-clock-{$id}", true),
            'name' => "Quiet.Clock.{$id}.par2",
            'collections_id' => $id,
            'totalparts' => 1,
        ]);
        DB::table('parts')->insert([
            'binaries_id' => $id,
            'messageid' => "<quiet-clock-{$id}@example.test>",
            'number' => $id,
            'partnumber' => 1,
            'size' => 10,
        ]);
    }

    private function restoreLegacyStorageShape(): void
    {
        DB::statement('ALTER TABLE parts DROP FOREIGN KEY fk_test_parts');
        DB::statement('ALTER TABLE parts DROP PRIMARY KEY, DROP INDEX ix_parts_number, ADD PRIMARY KEY (binaries_id, number)');
        DB::statement('ALTER TABLE parts MODIFY messageid VARCHAR(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL');

        DB::statement('ALTER TABLE binaries DROP INDEX ux_binaries_collection_hash');
        DB::statement('ALTER TABLE binaries MODIFY binaryhash BLOB NOT NULL');
        DB::statement('CREATE INDEX ix_binaries_binaryhash ON binaries (binaryhash(16))');
        DB::statement('CREATE UNIQUE INDEX ux_collection_id_filenumber ON binaries (collections_id, filenumber)');

        DB::statement('ALTER TABLE collections DROP INDEX ix_collection_collectionhash');
        DB::statement('ALTER TABLE collections MODIFY collectionhash BLOB NOT NULL');
        DB::statement('CREATE UNIQUE INDEX ix_collection_collectionhash ON collections (collectionhash(20))');
    }

    public function test_filter_led_release_indexes_migrate_up_and_down_in_one_alter(): void
    {
        $bandCount = ['category_band', 'resolution', 'source', 'categories_id', 'passwordstatus'];
        $before = [
            'ix_releases_band_added' => ['category_band', 'adddate', 'id', 'resolution', 'source', 'categories_id', 'passwordstatus'],
            'ix_releases_band_count' => $bandCount,
            'ix_releases_band_posted' => ['category_band', 'postdate', 'id', 'resolution', 'source', 'categories_id', 'passwordstatus'],
            'ix_releases_movieinfo_cat' => ['movieinfo_id', 'categories_id', 'passwordstatus', 'postdate'],
        ];
        $after = [
            'ix_releases_band_added' => [...$before['ix_releases_band_added'], 'videos_id', 'completion'],
            'ix_releases_band_cat_added' => ['category_band', 'categories_id', 'adddate', 'id', 'resolution', 'source', 'passwordstatus', 'completion'],
            'ix_releases_band_cat_posted' => ['category_band', 'categories_id', 'postdate', 'id', 'resolution', 'source', 'passwordstatus', 'completion'],
            'ix_releases_band_count' => [...$bandCount, 'completion'],
            'ix_releases_band_posted' => [...$before['ix_releases_band_posted'], 'videos_id', 'completion'],
            'ix_releases_band_res_added' => ['category_band', 'resolution', 'adddate', 'id', 'source', 'categories_id', 'passwordstatus', 'completion'],
            'ix_releases_band_res_posted' => ['category_band', 'resolution', 'postdate', 'id', 'source', 'categories_id', 'passwordstatus', 'completion'],
            'ix_releases_band_src_added' => ['category_band', 'source', 'adddate', 'id', 'resolution', 'categories_id', 'passwordstatus', 'completion'],
            'ix_releases_band_src_posted' => ['category_band', 'source', 'postdate', 'id', 'resolution', 'categories_id', 'passwordstatus', 'completion'],
            'ix_releases_movieinfo_cat' => [...$before['ix_releases_movieinfo_cat'], 'adddate', 'resolution', 'source', 'completion'],
        ];
        $shape = static function (): array {
            $indexes = [];
            foreach (DB::select('SHOW INDEX FROM releases') as $row) {
                if (str_starts_with($row->Key_name, 'ix_releases_band') || str_starts_with($row->Key_name, 'ix_releases_movie')) {
                    $indexes[$row->Key_name][(int) $row->Seq_in_index] = $row->Column_name;
                }
            }
            ksort($indexes);

            return array_map(static function (array $columns): array {
                ksort($columns);

                return array_values($columns);
            }, $indexes);
        };
        $alters = 0;
        DB::listen(static function (QueryExecuted $query) use (&$alters): void {
            $alters += (int) str_starts_with($query->sql, 'ALTER TABLE');
        });
        $migration = require database_path('migrations/2026_09_27_000000_add_filter_led_indexes_to_releases.php');
        preg_match('/CREATE TABLE `releases` \(.*?\) ENGINE=[^;]*/s', (string) file_get_contents(database_path('schema/mariadb-schema.sql')), $create);
        DB::statement('DROP TABLE IF EXISTS releases');
        DB::statement($create[0]);

        try {
            $this->assertSame($after, $shape(), 'The schema dump carries the migrated indexes.');
            $migration->down();
            $this->assertSame($before, $shape());
            $migration->up();
            $this->assertSame($after, $shape());
            $migration->down();
            $this->assertSame($before, $shape());
            $this->assertSame(3, $alters, 'Each direction alters releases in one statement.');

            // An install that indexed the old columns under another name has no ix_releases_movieinfo_cat.
            DB::statement('ALTER TABLE releases RENAME INDEX ix_releases_movieinfo_cat TO ix_releases_movie_legacy');
            $migration->up();
            $expected = ['ix_releases_movie_legacy' => $before['ix_releases_movieinfo_cat'], ...$after];
            ksort($expected);
            $this->assertSame($expected, $shape());
            $migration->down();
            $expected = ['ix_releases_movie_legacy' => $before['ix_releases_movieinfo_cat'], ...$before];
            unset($expected['ix_releases_movieinfo_cat']);
            ksort($expected);
            $this->assertSame($expected, $shape());
        } finally {
            DB::statement('DROP TABLE IF EXISTS releases');
        }
    }

    public function test_widening_collection_ids_keeps_the_columns_keys_indexes_and_rows(): void
    {
        $this->withThe32BitCollectionIdTables(function (Migration $migration): void {
            $this->assertSame($this->expectedCollectionIdColumns('int(10) unsigned'), $this->collectionIdColumns());
            $this->assertSame(self::COLLECTION_FOREIGN_KEYS, $this->collectionForeignKeys());
            $this->assertSame(self::COLLECTION_ID_INDEXES, $this->collectionIdIndexes());
            $collection = $this->storeCollection('kept', 4000000000);
            $binary = $this->storeBinary($collection);
            $this->storeGroupRow($collection);
            $this->storePart($binary);
            $this->storePublication($collection);

            $migration->up();

            $this->assertSame($this->expectedCollectionIdColumns('bigint(20) unsigned'), $this->collectionIdColumns());
            $this->assertSame(self::COLLECTION_FOREIGN_KEYS, $this->collectionForeignKeys());
            $this->assertSame(self::COLLECTION_ID_INDEXES, $this->collectionIdIndexes());
            $this->assertSame([4000000000], $this->storedIds('collections', 'id'));
            $this->assertSame([$binary => 4000000000], DB::table('binaries')->pluck('collections_id', 'id')->all());
            $this->assertSame([4000000000], $this->storedIds('collection_groups', 'collections_id'));
            $this->assertSame([4000000000], $this->storedIds('obfuscation_recovery_publications', 'collections_id'));
            $this->assertSame(1, DB::table('parts')->where('binaries_id', $binary)->count());
        });
    }

    public function test_collections_get_ids_above_the_32_bit_ceiling_once_widened(): void
    {
        $this->withThe32BitCollectionIdTables(function (Migration $migration): void {
            DB::statement('ALTER TABLE collections AUTO_INCREMENT = 4294967295');
            $this->assertSame(4294967295, $this->collectionCounter());
            $this->assertSame(4294967295, $this->storeCollection('last 32-bit id'));
            $this->assertSame(167, $this->refusal(fn () => $this->storeCollection('out of ids')), 'A 32-bit id column has no id left to hand out.');

            $migration->up();

            $collection = $this->storeCollection('first 64-bit id');
            $this->assertSame(4294967296, $collection);
            $binary = $this->storeBinary($collection);
            $this->storeGroupRow($collection);
            $this->storePart($binary);
            $this->assertSame([4294967296], $this->storedIds('binaries', 'collections_id'));
            $this->assertSame([4294967296], $this->storedIds('collection_groups', 'collections_id'));

            DB::table('collections')->where('id', $collection)->delete();

            $this->assertSame([4294967295], $this->storedIds('collections', 'id'));
            $this->assertSame(0, DB::table('binaries')->count());
            $this->assertSame(0, DB::table('collection_groups')->count());
            $this->assertSame(0, DB::table('parts')->count());
        });
    }

    public function test_rows_for_a_collection_that_does_not_exist_are_refused_once_widened(): void
    {
        $this->withThe32BitCollectionIdTables(function (Migration $migration): void {
            $migration->up();

            $this->assertSame(1452, $this->refusal(fn () => $this->storeBinary(999)));
            $this->assertSame(1452, $this->refusal(fn () => $this->storeGroupRow(999)));
            $this->assertSame(0, DB::table('binaries')->count());
            $this->assertSame(0, DB::table('collection_groups')->count());
        });
    }

    public function test_widening_collection_ids_a_second_time_alters_nothing(): void
    {
        $this->withThe32BitCollectionIdTables(function (Migration $migration): void {
            $alters = $this->recordAlters();
            $migration->up();
            $this->assertCount(6, $alters, 'Two children, the parent, the publications and the two keys.');

            $migration->up();

            $this->assertCount(6, $alters);
            $this->assertSame($this->expectedCollectionIdColumns('bigint(20) unsigned'), $this->collectionIdColumns());
            $this->assertSame(self::COLLECTION_FOREIGN_KEYS, $this->collectionForeignKeys());
        });
    }

    public function test_a_widening_interrupted_after_both_children_completes_when_run_again(): void
    {
        $this->withThe32BitCollectionIdTables(function (Migration $migration): void {
            $collection = $this->storeCollection('kept');
            $binary = $this->storeBinary($collection);
            $this->storeGroupRow($collection);
            DB::statement('ALTER TABLE binaries DROP FOREIGN KEY FK_Collections, MODIFY collections_id BIGINT UNSIGNED NOT NULL DEFAULT 0');
            DB::statement('ALTER TABLE collection_groups DROP FOREIGN KEY fk_collection_groups_collection, MODIFY collections_id BIGINT UNSIGNED NOT NULL');
            $alters = $this->recordAlters();

            $migration->up();

            $this->assertCount(4, $alters, 'The parent, the publications and the two keys.');
            $this->assertSame($this->expectedCollectionIdColumns('bigint(20) unsigned'), $this->collectionIdColumns());
            $this->assertSame(self::COLLECTION_FOREIGN_KEYS, $this->collectionForeignKeys());
            $this->assertSame(self::COLLECTION_ID_INDEXES, $this->collectionIdIndexes());
            $this->assertSame([$binary => $collection], DB::table('binaries')->pluck('collections_id', 'id')->all());
            $this->assertSame([$collection], $this->storedIds('collection_groups', 'collections_id'));
        });
    }

    public function test_a_widening_that_lacks_only_one_key_adds_it_in_one_alter(): void
    {
        $this->withThe32BitCollectionIdTables(function (Migration $migration): void {
            $migration->up();
            DB::statement('ALTER TABLE collection_groups DROP FOREIGN KEY fk_collection_groups_collection');
            $alters = $this->recordAlters();

            $migration->up();

            $this->assertCount(1, $alters);
            $this->assertSame($this->expectedCollectionIdColumns('bigint(20) unsigned'), $this->collectionIdColumns());
            $this->assertSame(self::COLLECTION_FOREIGN_KEYS, $this->collectionForeignKeys());
        });
    }

    public function test_a_32_bit_child_whose_key_is_already_gone_is_still_widened(): void
    {
        $this->withThe32BitCollectionIdTables(function (Migration $migration): void {
            DB::statement('ALTER TABLE binaries DROP FOREIGN KEY FK_Collections');
            DB::statement('ALTER TABLE collection_groups DROP FOREIGN KEY fk_collection_groups_collection');

            $migration->up();

            $this->assertSame($this->expectedCollectionIdColumns('bigint(20) unsigned'), $this->collectionIdColumns());
            $this->assertSame(self::COLLECTION_FOREIGN_KEYS, $this->collectionForeignKeys());
        });
    }

    public function test_a_key_that_cannot_be_added_back_is_named_and_the_columns_stay_widened(): void
    {
        $this->withThe32BitCollectionIdTables(function (Migration $migration): void {
            DB::statement('ALTER TABLE binaries DROP FOREIGN KEY FK_Collections');
            $orphan = $this->storeBinary(999);

            $failure = $this->failureOf($migration->up(...));

            $this->assertInstanceOf(\RuntimeException::class, $failure);
            $this->assertStringStartsWith('Foreign key FK_Collections could not be added to binaries.collections_id.', $failure->getMessage());
            $this->assertStringContainsString('already BIGINT UNSIGNED', $failure->getMessage());
            $this->assertInstanceOf(QueryException::class, $failure->getPrevious());
            $this->assertSame(1452, $failure->getPrevious()->errorInfo[1] ?? null);
            $this->assertSame($this->expectedCollectionIdColumns('bigint(20) unsigned'), $this->collectionIdColumns());
            $this->assertArrayNotHasKey('FK_Collections', $this->collectionForeignKeys());
            $this->assertSame([$orphan => 999], DB::table('binaries')->pluck('collections_id', 'id')->all());

            DB::table('binaries')->where('id', $orphan)->delete();
            $migration->up();

            $this->assertSame(self::COLLECTION_FOREIGN_KEYS, $this->collectionForeignKeys());
        });
    }

    public function test_the_keys_are_added_back_with_foreign_key_checks_on_whatever_the_session_has(): void
    {
        $this->withThe32BitCollectionIdTables(function (Migration $migration): void {
            DB::statement('ALTER TABLE binaries DROP FOREIGN KEY FK_Collections');
            $this->storeBinary(999);
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            try {
                $failure = $this->failureOf($migration->up(...));
                $checks = (int) DB::selectOne('SELECT @@SESSION.foreign_key_checks AS checks')->checks;
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }

            $this->assertInstanceOf(\RuntimeException::class, $failure);
            $this->assertStringStartsWith('Foreign key FK_Collections could not be added', $failure->getMessage());
            $this->assertArrayNotHasKey('FK_Collections', $this->collectionForeignKeys());
            $this->assertSame(0, $checks, 'The session gets its own setting back.');
        });
    }

    public function test_narrowing_collection_ids_restores_the_32_bit_columns_and_both_keys(): void
    {
        $this->withThe32BitCollectionIdTables(function (Migration $migration): void {
            $migration->up();
            $collection = $this->storeCollection('kept', 4000000000);
            $binary = $this->storeBinary($collection);
            $this->storeGroupRow($collection);
            $this->storePublication($collection);
            $alters = $this->recordAlters();

            $migration->down();

            $this->assertCount(6, $alters);
            $this->assertSame($this->expectedCollectionIdColumns('int(10) unsigned'), $this->collectionIdColumns());
            $this->assertSame(self::COLLECTION_FOREIGN_KEYS, $this->collectionForeignKeys());
            $this->assertSame(self::COLLECTION_ID_INDEXES, $this->collectionIdIndexes());
            $this->assertSame([4000000000], $this->storedIds('collections', 'id'));
            $this->assertSame([$binary => 4000000000], DB::table('binaries')->pluck('collections_id', 'id')->all());
            $this->assertSame([4000000000], $this->storedIds('collection_groups', 'collections_id'));
            $this->assertSame([4000000000], $this->storedIds('obfuscation_recovery_publications', 'collections_id'));

            $migration->down();

            $this->assertCount(6, $alters, 'Narrowing a second time alters nothing.');
        });
    }

    public function test_narrowing_is_refused_while_a_stored_collection_id_is_above_32_bits(): void
    {
        $this->withThe32BitCollectionIdTables(function (Migration $migration): void {
            $migration->up();
            $alters = $this->recordAlters();
            $assertRefused = function (string $column) use ($migration, $alters): void {
                $issued = \count($alters);
                $failure = $this->failureOf($migration->down(...));
                $this->assertInstanceOf(\RuntimeException::class, $failure);
                $this->assertStringStartsWith("{$column} holds a value above 4294967295", $failure->getMessage());
                $this->assertCount($issued, $alters, 'Nothing is altered.');
                $this->assertSame($this->expectedCollectionIdColumns('bigint(20) unsigned'), $this->collectionIdColumns());
            };

            // obfuscation_recovery_publications has no foreign key, so it can hold the id alone.
            $this->storePublication(4294967296);
            $assertRefused('obfuscation_recovery_publications.collections_id');
            DB::table('obfuscation_recovery_publications')->delete();

            $collection = $this->storeCollection('64-bit id', 4294967296);
            $assertRefused('collections.id');
            $this->assertSame(self::COLLECTION_FOREIGN_KEYS, $this->collectionForeignKeys());
            DB::table('collections')->where('id', $collection)->delete();

            // A child can hold the id alone only while its key is missing.
            DB::statement('ALTER TABLE binaries DROP FOREIGN KEY FK_Collections');
            $binary = $this->storeBinary(4294967296);
            $assertRefused('binaries.collections_id');
            DB::table('binaries')->where('id', $binary)->delete();

            DB::statement('ALTER TABLE collection_groups DROP FOREIGN KEY fk_collection_groups_collection');
            $this->storeGroupRow(4294967296);
            $assertRefused('collection_groups.collections_id');
        });
    }

    public function test_narrowing_is_refused_while_the_collection_counter_is_above_32_bits(): void
    {
        $this->withThe32BitCollectionIdTables(function (Migration $migration): void {
            $migration->up();
            $this->storeCollection('fits', 5);
            DB::statement('ALTER TABLE collections AUTO_INCREMENT = 4294967297');
            $this->assertSame(4294967297, $this->collectionCounter());
            $alters = $this->recordAlters();

            $failure = $this->failureOf($migration->down(...));

            $this->assertInstanceOf(\RuntimeException::class, $failure);
            $this->assertStringContainsString('AUTO_INCREMENT', $failure->getMessage());
            $this->assertCount(0, $alters);
            $this->assertSame($this->expectedCollectionIdColumns('bigint(20) unsigned'), $this->collectionIdColumns());
            $this->assertSame(self::COLLECTION_FOREIGN_KEYS, $this->collectionForeignKeys());
            $this->assertSame([5], $this->storedIds('collections', 'id'));
        });
    }

    public function test_the_schema_dump_holds_the_widened_columns_and_leaves_the_migration_nothing_to_alter(): void
    {
        $this->withTheDumpedCollectionIdTables(function (Migration $migration): void {
            $alters = $this->recordAlters();

            $migration->up();

            $this->assertCount(0, $alters);
            $this->assertSame($this->expectedCollectionIdColumns('bigint(20) unsigned'), $this->collectionIdColumns());
            $this->assertSame(self::COLLECTION_FOREIGN_KEYS, $this->collectionForeignKeys());
            $this->assertSame(self::COLLECTION_ID_INDEXES, $this->collectionIdIndexes());
        });
    }

    public function test_widening_and_narrowing_collection_ids_change_nothing_on_sqlite(): void
    {
        $tables = ['collections' => ['id', 'collectionhash'], 'binaries' => ['id', 'binaryhash', 'collections_id'], 'collection_groups' => null, 'obfuscation_recovery_publications' => ['id', 'collections_id']];
        foreach ($tables as $table => $columns) {
            ProductionTables::fromAuthority()->create($table, $columns, 'testing');
        }
        $definitions = static fn (): array => DB::connection('testing')->table('sqlite_master')->orderBy('name')->pluck('sql', 'name')->all();
        $before = $definitions();
        $this->assertSame(['binaries', 'collection_groups', 'collections', 'obfuscation_recovery_publications'], array_keys(array_intersect_key($before, $tables)));
        $migration = require database_path('migrations/2026_10_12_120000_widen_collection_ids_to_bigint.php');
        $statements = [];
        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('testing');
        try {
            $migration->up();
            $migration->down();
        } finally {
            DB::setDefaultConnection($default);
        }

        $this->assertSame([], $statements, 'On sqlite neither direction issues a statement.');
        $this->assertSame($before, $definitions());
    }

    private function countedStorage(?BinariesConfig $config = null): HeaderStorageService
    {
        return new HeaderStorageService(new CollectionHandler(new class extends CollectionsCleaningService
        {
            public function collectionsCleaner(string $subject, string $groupName = ''): array
            {
                return ['id' => 0, 'name' => (string) strtok($subject, ' ')];
            }
        }), config: $config ?? new BinariesConfig);
    }

    /**
     * One part of one file of the "Counted.Release" collection.
     *
     * @return array<string, mixed>
     */
    private function fileHeader(int $number, string $file, int $part, int $total, int $bytes): array
    {
        $name = "Counted.Release {$file}";

        return [
            'Number' => $number,
            'Subject' => "{$name} ({$part}/{$total})",
            'From' => 'poster@example.test',
            'Date' => time(),
            'Bytes' => $bytes,
            'Message-ID' => "<{$number}@example.test>",
            'Xref' => "news.example alt.binaries.test:{$number}",
            'matches' => [0 => "{$name} ({$part}/{$total})", 1 => $name, 2 => $part, 3 => $total],
        ];
    }

    /** @return array<string, array{0: int, 1: int, 2: int}> currentparts, partsize and partcheck by file */
    private function binaryCounts(): array
    {
        $counts = [];
        foreach (DB::table('binaries')->orderBy('id')->get() as $binary) {
            $counts[substr((string) $binary->name, \strlen('Counted.Release '))] = [(int) $binary->currentparts, (int) $binary->partsize, (int) $binary->partcheck];
        }

        return $counts;
    }

    private function assertBinaryCountsEqualStoredParts(): void
    {
        $binaries = DB::table('binaries')->orderBy('id')->get();
        $this->assertNotEmpty($binaries);
        foreach ($binaries as $binary) {
            $parts = DB::table('parts')->where('binaries_id', $binary->id);
            $this->assertSame($parts->count(), (int) $binary->currentparts, "currentparts of {$binary->name}");
            $this->assertSame((int) $parts->sum('size'), (int) $binary->partsize, "partsize of {$binary->name}");
        }
    }

    /** @param  list<list<array{sql: string, bindings: array<int, mixed>}>>  $transactions */
    private function assertNoAggregateOverParts(array $transactions): void
    {
        $statements = array_merge(...$transactions);
        $this->assertNotNull($this->firstStatement($statements, '/^\s*INSERT IGNORE INTO parts\b/i'), 'The chunk stored parts.');
        $this->assertNotNull($this->firstStatement($statements, '/^\s*UPDATE binaries\b/i'), 'The chunk updated its binaries.');
        foreach ($statements as $statement) {
            $readsParts = preg_match('/\b(?:FROM|JOIN)\s+`?parts`?\b/i', $statement['sql']) === 1;
            $aggregates = preg_match('/\b(?:COUNT|SUM|MIN|MAX|AVG)\s*\(/i', $statement['sql']) === 1;
            $this->assertFalse($readsParts && $aggregates, 'Aggregate over parts: '.$statement['sql']);
        }
    }

    /**
     * Runs the test on collections, binaries, collection_groups, parts and
     * obfuscation_recovery_publications as the schema dump defined them while collection ids
     * were 32-bit, in place of the cut-down tables setUp() built.
     *
     * @param  callable(Migration): void  $test
     */
    private function withThe32BitCollectionIdTables(callable $test): void
    {
        $this->withTheDumpedCollectionIdTables($test, static function (string $definition, string $column): string {
            $narrowed = (string) preg_replace("/^(\s*`{$column}`) bigint\(20\) unsigned/m", '$1 int(10) unsigned', $definition);
            self::assertStringContainsString("`{$column}` int(10) unsigned", $narrowed);

            return $narrowed;
        });
    }

    /**
     * @param  callable(Migration): void  $test
     * @param  (callable(string, string): string)|null  $redefine  Rewrites a table's definition, given the column that holds the collection id.
     */
    private function withTheDumpedCollectionIdTables(callable $test, ?callable $redefine = null): void
    {
        $dump = (string) file_get_contents(database_path('schema/mariadb-schema.sql'));
        $this->dropCollectionIdTables();
        try {
            foreach (['collections' => 'id', 'binaries' => 'collections_id', 'collection_groups' => 'collections_id', 'parts' => null, 'obfuscation_recovery_publications' => 'collections_id'] as $table => $column) {
                $this->assertSame(1, preg_match("/CREATE TABLE `{$table}` \(.*?\) ENGINE=[^;]*/s", $dump, $create));
                DB::statement($column === null || $redefine === null ? $create[0] : $redefine($create[0], $column));
            }
            $test(require database_path('migrations/2026_10_12_120000_widen_collection_ids_to_bigint.php'));
        } finally {
            // tearDown() drops the other four.
            DB::statement('DROP TABLE IF EXISTS obfuscation_recovery_publications');
        }
    }

    private function dropCollectionIdTables(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['parts', 'binaries', 'collection_groups', 'obfuscation_recovery_publications', 'collections'] as $table) {
            DB::statement("DROP TABLE IF EXISTS {$table}");
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /** @return array<string, array{0: string, 1: string, 2: string|null, 3: string}> */
    private function expectedCollectionIdColumns(string $type): array
    {
        return [
            'binaries.collections_id' => [$type, 'NO', '0', ''],
            'collection_groups.collections_id' => [$type, 'NO', null, ''],
            'collections.id' => [$type, 'NO', null, 'auto_increment'],
            'obfuscation_recovery_publications.collections_id' => [$type, 'YES', 'NULL', ''],
        ];
    }

    /** @return array<string, array{0: string, 1: string, 2: string|null, 3: string}> type, nullability, default and extra of the four columns that hold a collection id */
    private function collectionIdColumns(): array
    {
        $columns = [];
        foreach (DB::select(
            "SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS preset, EXTRA AS extra
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND ((TABLE_NAME = 'collections' AND COLUMN_NAME = 'id')
                OR (TABLE_NAME IN ('binaries', 'collection_groups', 'obfuscation_recovery_publications') AND COLUMN_NAME = 'collections_id'))"
        ) as $column) {
            $columns["{$column->table_name}.{$column->column_name}"] = [$column->type, $column->nullable, $column->preset, $column->extra];
        }
        ksort($columns);

        return $columns;
    }

    /** @return array<string, string> */
    private function collectionForeignKeys(): array
    {
        $keys = [];
        foreach (DB::select(
            "SELECT r.CONSTRAINT_NAME AS name, k.TABLE_NAME AS child, k.COLUMN_NAME AS child_column, k.REFERENCED_TABLE_NAME AS parent,
                    k.REFERENCED_COLUMN_NAME AS parent_column, r.UPDATE_RULE AS on_update, r.DELETE_RULE AS on_delete
             FROM information_schema.REFERENTIAL_CONSTRAINTS r
             JOIN information_schema.KEY_COLUMN_USAGE k
               ON k.CONSTRAINT_SCHEMA = r.CONSTRAINT_SCHEMA AND k.CONSTRAINT_NAME = r.CONSTRAINT_NAME AND k.TABLE_NAME = r.TABLE_NAME
             WHERE r.CONSTRAINT_SCHEMA = DATABASE()
               AND r.TABLE_NAME IN ('binaries', 'collection_groups', 'parts', 'obfuscation_recovery_publications')"
        ) as $key) {
            $keys[$key->name] = "{$key->child}.{$key->child_column} -> {$key->parent}.{$key->parent_column}, update {$key->on_update}, delete {$key->on_delete}";
        }
        ksort($keys);

        return $keys;
    }

    /** @return array<string, array{0: bool, 1: list<string>}> */
    private function collectionIdIndexes(): array
    {
        $indexes = [];
        foreach (['binaries', 'collection_groups', 'obfuscation_recovery_publications'] as $table) {
            $unique = $columns = [];
            foreach (DB::select("SHOW INDEX FROM {$table}") as $row) {
                $unique[$row->Key_name] = (int) $row->Non_unique === 0;
                $columns[$row->Key_name][(int) $row->Seq_in_index] = $row->Column_name;
            }
            foreach ($columns as $name => $indexed) {
                ksort($indexed);
                if (\in_array('collections_id', $indexed, true)) {
                    $indexes["{$table}.{$name}"] = [$unique[$name], array_values($indexed)];
                }
            }
        }
        ksort($indexes);

        return $indexes;
    }

    /** @return \ArrayObject<int, string> every ALTER TABLE issued from now on */
    private function recordAlters(): \ArrayObject
    {
        $alters = new \ArrayObject;
        DB::listen(static function (QueryExecuted $query) use ($alters): void {
            if (preg_match('/^\s*ALTER\s+TABLE\b/i', $query->sql) === 1) {
                $alters->append($query->sql);
            }
        });

        return $alters;
    }

    /** The id the next stored collection is given. */
    private function collectionCounter(): int
    {
        return (int) DB::selectOne("SELECT AUTO_INCREMENT AS counter FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'collections'")->counter;
    }

    /** The MariaDB error number the statement was refused with, or null when it was accepted. */
    private function refusal(callable $statement): ?int
    {
        $failure = $this->failureOf($statement);
        if ($failure === null) {
            return null;
        }
        $this->assertInstanceOf(QueryException::class, $failure);

        return (int) ($failure->errorInfo[1] ?? 0);
    }

    private function failureOf(callable $action): ?\Throwable
    {
        try {
            $action();
        } catch (\Throwable $failure) {
            return $failure;
        }

        return null;
    }

    /** @return list<int> */
    private function storedIds(string $table, string $column): array
    {
        return DB::table($table)->orderBy($column)->pluck($column)->map(static fn (mixed $id): int => (int) $id)->all();
    }

    /** Stores a collection, under the given id or the next one the table hands out, and returns its id. */
    private function storeCollection(string $label, ?int $id = null): int
    {
        $hash = sha1("collection {$label}", true);
        DB::table('collections')->insert(['collectionhash' => $hash] + ($id === null ? [] : ['id' => $id]));

        return (int) DB::table('collections')->where('collectionhash', $hash)->value('id');
    }

    private function storeBinary(int $collectionId): int
    {
        return (int) DB::table('binaries')->insertGetId(['binaryhash' => md5("binary of {$collectionId}", true), 'collections_id' => $collectionId]);
    }

    private function storeGroupRow(int $collectionId): void
    {
        DB::table('collection_groups')->insert(['collections_id' => $collectionId, 'group_name' => 'alt.binaries.test']);
    }

    private function storePart(int $binaryId): void
    {
        DB::table('parts')->insert(['binaries_id' => $binaryId, 'messageid' => "part-of-{$binaryId}@example.test", 'number' => 1, 'partnumber' => 1, 'size' => 1]);
    }

    private function storePublication(int $collectionId): void
    {
        $digest = hash('sha256', "publication of {$collectionId}");
        DB::table('obfuscation_recovery_publications')->insert([
            'identity' => $digest, 'index_identity' => $digest, 'index_message_id' => "index-{$collectionId}@example.test",
            'set_id' => md5("set of {$collectionId}"), 'plan_digest' => $digest, 'collection_projection' => sha1("projection of {$collectionId}", true),
            'collections_id' => $collectionId, 'profile' => 'fixture', 'group_name' => 'alt.binaries.test', 'source_epoch' => 'fixture',
            'ordering_mode' => 'fixture', 'inventory_scope' => 'fixture', 'protected_files' => 1, 'planned_files' => 1, 'planned_parts' => 1,
            'sealed_plan' => '{}', 'manifest_digest' => $digest,
        ]);
    }

    /** @return array<string, mixed> */
    private function header(int $number, int $part, int $bytes): array
    {
        return [
            'Number' => $number,
            'Subject' => "Integration.Release ({$part}/2)",
            'From' => 'poster@example.test',
            'Date' => time(),
            'Bytes' => $bytes,
            'Message-ID' => "<{$number}@example.test>",
            'Xref' => "news.example alt.binaries.test:{$number}",
            'matches' => [
                0 => "Integration.Release ({$part}/2)",
                1 => 'Integration.Release',
                2 => $part,
                3 => 2,
            ],
        ];
    }
}
