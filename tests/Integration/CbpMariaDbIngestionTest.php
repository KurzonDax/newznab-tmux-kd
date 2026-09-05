<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Enums\CollectionFileCheckStatus;
use App\Enums\HeaderScanDirection;
use App\Models\UsenetGroup;
use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\CollectionHandler;
use App\Services\Binaries\HeaderStorageService;
use App\Services\CollectionsCleaningService;
use App\Services\ReleaseProcessingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

        foreach (['cbp_optimization_checkpoints', 'cbp_binary_map', 'parts_cbp_new', 'parts_cbp_pre_optimize', 'settings'] as $table) {
            DB::statement("DROP TABLE IF EXISTS {$table}");
        }
        DB::statement('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT NULL) ENGINE=InnoDB');
        DB::statement('CREATE TABLE usenet_groups (id INT UNSIGNED PRIMARY KEY, first_record BIGINT DEFAULT 0, last_record BIGINT DEFAULT 0, last_updated DATETIME NULL, name VARCHAR(255) NOT NULL, active TINYINT DEFAULT 1, backfill TINYINT DEFAULT 1, last_record_postdate DATETIME NULL, first_record_postdate DATETIME NULL, backfill_settled_at DATETIME NULL) ENGINE=InnoDB');
        (require database_path('migrations/2026_09_05_213352_create_usenet_group_ingested_ranges_table.php'))->up();
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
            KEY ix_collections_group_filecheck_seen_id (groups_id, filecheck, last_seen_at, id)
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
            foreach (['parts', 'parts_cbp_new', 'parts_cbp_pre_optimize', 'cbp_binary_map', 'cbp_optimization_checkpoints', 'binaries', 'collection_groups', 'collections', 'collection_regexes', 'usenet_group_ingested_ranges', 'usenet_groups', 'settings'] as $table) {
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
