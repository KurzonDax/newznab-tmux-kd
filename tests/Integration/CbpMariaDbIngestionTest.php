<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Enums\CollectionDeletionReason;
use App\Enums\CollectionFileCheckStatus;
use App\Enums\CollectionSweepOutcome;
use App\Enums\HeaderScanDirection;
use App\Models\UsenetGroup;
use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\CollectionHandler;
use App\Services\Binaries\HeaderStorageService;
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
use App\Services\YencService;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\CollectionFrontierAssertions;
use Tests\TestCase;

final class CbpMariaDbIngestionTest extends TestCase
{
    use CollectionFrontierAssertions;

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
        DB::statement('CREATE TABLE usenet_groups (id INT UNSIGNED PRIMARY KEY, first_record BIGINT DEFAULT 0, last_record BIGINT DEFAULT 0, last_updated DATETIME NULL, name VARCHAR(255) NOT NULL, active TINYINT DEFAULT 1, backfill TINYINT DEFAULT 1, last_record_postdate DATETIME NULL, first_record_postdate DATETIME NULL, backfill_settled_at DATETIME NULL) ENGINE=InnoDB');
        (require database_path('migrations/2026_09_05_213352_create_usenet_group_ingested_ranges_table.php'))->up();
        (require database_path('migrations/2026_09_10_224820_create_collection_sweep_cursors_table.php'))->up();
        DB::statement('CREATE TABLE collection_regexes (id INT PRIMARY KEY, group_regex VARCHAR(255), regex VARCHAR(255), status TINYINT DEFAULT 1, ordinal INT DEFAULT 0) ENGINE=InnoDB');
        DB::statement('CREATE TABLE collections (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
            collections_id INT UNSIGNED NOT NULL, group_name VARCHAR(255) NOT NULL,
            PRIMARY KEY (collections_id, group_name),
            CONSTRAINT fk_test_collection_groups FOREIGN KEY (collections_id) REFERENCES collections(id) ON DELETE CASCADE
        ) ENGINE=InnoDB');
        DB::statement('CREATE TABLE binaries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, binaryhash BINARY(16) NOT NULL,
            name VARCHAR(1000) NOT NULL, collections_id INT UNSIGNED NOT NULL,
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
                    if (! str_starts_with($query['query'], 'select * from `collections` force index')) {
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

    public function test_ingestion_stamps_are_monotone_and_repair_uses_current_head(): void
    {
        $storage = new HeaderStorageService;
        $header = $this->header(8001, 1, 100);
        $header['Date'] = '2026-08-01 12:00:00';
        $group = ['id' => 1, 'name' => 'alt.binaries.test', 'last_record_postdate' => '2026-08-02 12:00:00'];
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
