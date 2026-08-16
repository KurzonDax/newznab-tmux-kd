<?php

namespace Tests\Feature;

use App\Enums\CollectionFileCheckStatus;
use App\Facades\Search;
use App\Services\CollectionCleanupService;
use App\Services\ReleaseCleaningService;
use App\Services\ReleaseCreationService;
use App\Services\Releases\ReleaseDuplicateFinder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReleaseCollectionHashDedupeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        $this->registerSqliteFunction('UNIX_TIMESTAMP', static fn (?string $value): int => strtotime((string) $value));
        $this->registerSqliteFunction(
            'REGEXP',
            static function (?string $subject, ?string $pattern): int {
                if ($subject === null || $pattern === null || $pattern === '') {
                    return 0;
                }
                set_error_handler(static fn (): true => true);
                $ok = @preg_match($pattern, $subject);
                restore_error_handler();

                return $ok ? 1 : 0;
            },
            2
        );

        Search::shouldReceive('updateRelease')->zeroOrMoreTimes();

        $this->createTables();
        $this->seedSettings();
    }

    public function test_release_created_from_collection_persists_collectionhash(): void
    {
        $this->insertCollection(100, 'hash-aaaa', 'Fresh.Release.S01E01');

        $result = $this->service()->createReleases(null, 10, false);

        $this->assertSame(['added' => 1, 'dupes' => 0], $result);
        $release = DB::table('releases')->first();
        $this->assertNotNull($release);
        $this->assertSame('hash-aaaa', $release->collectionhash);
        $this->assertSame(
            CollectionFileCheckStatus::Inserted->value,
            (int) DB::table('collections')->where('id', 100)->value('filecheck')
        );
        $this->assertSame((int) $release->id, (int) DB::table('collections')->where('id', 100)->value('releases_id'));
    }

    public function test_rescan_of_identical_collection_is_deduped_and_cleaned_up(): void
    {
        $this->insertCollection(100, 'hash-bbbb', 'Rescanned.Release.S01E01');

        $first = $this->service()->createReleases(null, 10, false);
        $this->assertSame(['added' => 1, 'dupes' => 0], $first);

        // Simulate the post-NZB-creation cleanup dropping the CBP rows, then the
        // same articles being fetched again and re-forming an identical collection.
        DB::table('parts')->delete();
        DB::table('binaries')->delete();
        DB::table('collections')->delete();
        $this->insertCollection(101, 'hash-bbbb', 'Rescanned.Release.S01E01');

        $second = $this->service()->createReleases(null, 10, false);

        $this->assertSame(['added' => 0, 'dupes' => 1], $second);
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame(0, DB::table('collections')->count());
        $this->assertSame(0, DB::table('binaries')->count());
        $this->assertSame(0, DB::table('parts')->count());
    }

    public function test_renamed_release_with_size_drift_is_deduped_by_collectionhash(): void
    {
        // An existing release whose searchname was rewritten post-creation
        // (name-fixing/PAR2/NFO) and whose size no longer matches the re-formed
        // collection: the heuristic finder cannot match it, only the persisted
        // collectionhash can.
        DB::table('releases')->insert([
            'id' => 1,
            'name' => 'raw-original-subject',
            'searchname' => 'Completely.Renamed.By.PostProcessing',
            'totalpart' => 1,
            'groups_id' => 1,
            'adddate' => now()->format('Y-m-d H:i:s'),
            'guid' => str_repeat('a', 36),
            'leftguid' => 'a',
            'postdate' => now()->format('Y-m-d H:i:s'),
            'fromname' => 'poster@example.com',
            'size' => 999_999_999,
            'passwordstatus' => 0,
            'haspreview' => -1,
            'categories_id' => 1,
            'nfostatus' => -1,
            'nzbstatus' => 0,
            'isrenamed' => 1,
            'iscategorized' => 1,
            'predb_id' => 0,
            'source' => null,
            'collectionhash' => 'hash-cccc',
        ]);

        $this->insertCollection(100, 'hash-cccc', 'Original.Subject.Name', filesize: 1000);

        $result = $this->service()->createReleases(null, 10, false);

        $this->assertSame(['added' => 0, 'dupes' => 1], $result);
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame(
            'Completely.Renamed.By.PostProcessing',
            DB::table('releases')->where('id', 1)->value('searchname')
        );
        $this->assertSame(0, DB::table('collections')->count());
        $this->assertSame(0, DB::table('binaries')->count());
        $this->assertSame(0, DB::table('parts')->count());
    }

    public function test_distinct_collections_both_insert(): void
    {
        $this->insertCollection(100, 'hash-dddd', 'First.Distinct.Release');
        $this->insertCollection(101, 'hash-eeee', 'Second.Distinct.Release');

        $result = $this->service()->createReleases(null, 10, false);

        $this->assertSame(['added' => 2, 'dupes' => 0], $result);
        $this->assertSame(2, DB::table('releases')->count());
    }

    public function test_migration_adds_nullable_unique_collectionhash_on_sqlite(): void
    {
        DB::statement('CREATE TABLE migration_probe_releases (id INTEGER PRIMARY KEY, guid VARCHAR(40))');
        // SQLite index names are database-global; free the name for the migration.
        DB::statement('DROP INDEX ux_releases_collectionhash');
        DB::statement('ALTER TABLE releases RENAME TO releases_backup');
        DB::statement('ALTER TABLE migration_probe_releases RENAME TO releases');

        try {
            $migration = require base_path('database/migrations/2026_08_14_120000_add_collectionhash_to_releases_table.php');
            $migration->up();

            DB::table('releases')->insert(['id' => 1, 'guid' => 'a', 'collectionhash' => null]);
            DB::table('releases')->insert(['id' => 2, 'guid' => 'b', 'collectionhash' => null]);
            DB::table('releases')->insert(['id' => 3, 'guid' => 'c', 'collectionhash' => 'same-hash']);

            $this->expectException(UniqueConstraintViolationException::class);
            DB::table('releases')->insert(['id' => 4, 'guid' => 'd', 'collectionhash' => 'same-hash']);
        } finally {
            DB::statement('DROP TABLE releases');
            DB::statement('ALTER TABLE releases_backup RENAME TO releases');
            DB::statement('CREATE UNIQUE INDEX ux_releases_collectionhash ON releases (collectionhash)');
        }
    }

    public function test_migration_is_a_noop_without_a_releases_table(): void
    {
        DB::statement('ALTER TABLE releases RENAME TO releases_backup');

        try {
            $migration = require base_path('database/migrations/2026_08_14_120000_add_collectionhash_to_releases_table.php');
            $migration->up();
            $this->assertFalse(DB::getSchemaBuilder()->hasTable('releases'));
        } finally {
            DB::statement('ALTER TABLE releases_backup RENAME TO releases');
        }
    }

    private function service(): ReleaseCreationService
    {
        return new ReleaseCreationService(
            app(ReleaseCleaningService::class),
            app(CollectionCleanupService::class),
            app(ReleaseDuplicateFinder::class)
        );
    }

    private function insertCollection(int $id, string $hash, string $subject, int $filesize = 1000): void
    {
        DB::table('collections')->insert([
            'id' => $id,
            'subject' => $subject,
            'fromname' => 'poster@example.com',
            'date' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'dateadded' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'added' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'xref' => 'alt.test:'.$id,
            'groups_id' => 1,
            'totalfiles' => 1,
            'filesize' => $filesize,
            'filecheck' => CollectionFileCheckStatus::Sized->value,
            'collectionhash' => $hash,
            'collection_regexes_id' => 0,
            'releases_id' => null,
            'noise' => '',
        ]);
        DB::table('binaries')->insert([
            'id' => $id * 10,
            'name' => $subject.' yEnc',
            'collections_id' => $id,
            'totalparts' => 1,
        ]);
        DB::table('parts')->insert([
            'binaries_id' => $id * 10,
            'number' => $id,
            'messageid' => '<part-'.$id.'@example.com>',
            'partnumber' => 1,
            'size' => 10,
        ]);
    }

    private function seedSettings(): void
    {
        $settings = [
            'partretentionhours' => '1',
            'nzbsplitlevel' => '1',
            'check_passworded_rars' => '0',
            'categorizeforeign' => '1',
            'catwebdl' => '1',
        ];

        foreach ($settings as $name => $value) {
            DB::table('settings')->insert(['name' => $name, 'value' => $value]);
        }
    }

    private function createTables(): void
    {
        DB::statement('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT)');
        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name VARCHAR(255))');
        DB::statement('CREATE TABLE categories (id INTEGER PRIMARY KEY, title VARCHAR(255), parent_categories_id INTEGER NULL)');
        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255),
            searchname VARCHAR(255),
            totalpart INTEGER,
            groups_id INTEGER,
            adddate DATETIME NULL,
            guid VARCHAR(64),
            leftguid VARCHAR(1),
            postdate DATETIME NULL,
            fromname VARCHAR(255),
            size INTEGER,
            passwordstatus INTEGER,
            haspreview INTEGER,
            categories_id INTEGER,
            nfostatus INTEGER,
            nzbstatus INTEGER,
            isrenamed INTEGER,
            is_trusted_name INTEGER DEFAULT 0,
            iscategorized INTEGER,
            predb_id INTEGER,
            source VARCHAR(255) NULL,
            collectionhash BLOB NULL
        )');
        DB::statement('CREATE UNIQUE INDEX ux_releases_collectionhash ON releases (collectionhash)');
        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY,
            subject VARCHAR(255),
            fromname VARCHAR(255),
            date DATETIME NULL,
            dateadded DATETIME NULL,
            added DATETIME NULL,
            xref TEXT,
            groups_id INTEGER,
            totalfiles INTEGER,
            filesize INTEGER,
            filecheck INTEGER,
            collectionhash VARCHAR(255),
            collection_regexes_id INTEGER,
            releases_id INTEGER NULL,
            noise VARCHAR(64)
        )');
        DB::statement('CREATE TABLE binaries (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255),
            collections_id INTEGER,
            totalparts INTEGER
        )');
        DB::statement('CREATE TABLE parts (
            binaries_id INTEGER,
            number INTEGER,
            messageid VARCHAR(255),
            partnumber INTEGER,
            size INTEGER
        )');
        DB::statement('CREATE TABLE release_regexes (
            releases_id INTEGER,
            collection_regex_id INTEGER,
            naming_regex_id INTEGER
        )');
        DB::statement('CREATE TABLE releases_groups (
            releases_id INTEGER,
            groups_id INTEGER
        )');
        DB::statement('CREATE TABLE release_naming_regexes (
            id INTEGER PRIMARY KEY,
            group_regex VARCHAR(255),
            regex VARCHAR(255),
            status INTEGER DEFAULT 1,
            ordinal INTEGER DEFAULT 0
        )');
        DB::statement('CREATE TABLE collection_regexes (
            id INTEGER PRIMARY KEY,
            group_regex VARCHAR(255),
            regex VARCHAR(255),
            status INTEGER DEFAULT 1,
            ordinal INTEGER DEFAULT 0
        )');
        DB::statement('CREATE TABLE predb (
            id INTEGER PRIMARY KEY,
            title VARCHAR(255),
            filename VARCHAR(255)
        )');
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.test']);
        DB::table('categories')->insert(['id' => 1, 'title' => 'Misc', 'parent_categories_id' => null]);
    }
}
