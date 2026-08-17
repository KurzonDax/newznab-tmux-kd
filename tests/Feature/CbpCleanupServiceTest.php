<?php

namespace Tests\Feature;

use App\Enums\CollectionFileCheckStatus;
use App\Models\Release;
use App\Services\CollectionCleanupService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseCleaningService;
use App\Services\ReleaseCreationService;
use App\Services\ReleaseProcessingService;
use App\Services\Releases\ReleaseDuplicateFinder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CbpCleanupServiceTest extends TestCase
{
    private string $originalTimezone;

    protected function setUp(): void
    {
        $this->originalTimezone = date_default_timezone_get();

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

        $this->createTables();
        $this->seedSettings();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        date_default_timezone_set($this->originalTimezone);

        parent::tearDown();
    }

    public function test_retention_cleanup_deletes_parts_binaries_and_collections_without_fk_cascades(): void
    {
        DB::table('collections')->insert([
            'id' => 100,
            'subject' => 'Retention.Release',
            'fromname' => 'poster@example.com',
            'date' => now()->subHours(10)->format('Y-m-d H:i:s'),
            'dateadded' => now()->subHours(10)->format('Y-m-d H:i:s'),
            'added' => now()->subHours(10)->format('Y-m-d H:i:s'),
            'xref' => 'alt.test:123',
            'groups_id' => 1,
            'totalfiles' => 1,
            'filesize' => 500,
            'filecheck' => CollectionFileCheckStatus::Sized->value,
            'collectionhash' => 'retention-hash',
            'collection_regexes_id' => 0,
            'releases_id' => null,
            'noise' => '',
        ]);
        DB::table('binaries')->insert([
            'id' => 1000,
            'name' => 'Retention.Release.par2',
            'collections_id' => 100,
            'totalparts' => 1,
        ]);
        DB::table('parts')->insert([
            'binaries_id' => 1000,
            'number' => 1,
            'messageid' => '<retention-1@example.com>',
            'partnumber' => 1,
            'size' => 10,
        ]);

        app(CollectionCleanupService::class)->deleteFinishedAndOrphans(false);

        $this->assertSame(0, DB::table('parts')->count());
        $this->assertSame(0, DB::table('binaries')->count());
        $this->assertSame(0, DB::table('collections')->count());
    }

    public function test_non_utc_database_clock_promotes_collection_after_delay(): void
    {
        $this->useChicagoApplicationClock();
        $this->insertCollectionTree(
            110,
            $this->databaseTimestamp('-3 hours'),
            CollectionFileCheckStatus::Default->value,
            0
        );

        app(ReleaseProcessingService::class)->setEchoCLI(false)->processIncompleteCollections(1);

        $this->assertSame(
            CollectionFileCheckStatus::CompleteParts->value,
            (int) DB::table('collections')->where('id', 110)->value('filecheck')
        );
    }

    public function test_non_utc_database_clock_deletes_collection_after_retention(): void
    {
        $this->useChicagoApplicationClock();
        $this->insertCollectionTree(
            120,
            $this->databaseTimestamp('-2 hours'),
            CollectionFileCheckStatus::Sized->value,
            1
        );

        app(CollectionCleanupService::class)->deleteFinishedAndOrphans(false);

        $this->assertFalse(DB::table('collections')->where('id', 120)->exists());
    }

    public function test_non_utc_stuck_cutoff_honors_last_run_time(): void
    {
        $this->useChicagoApplicationClock();
        DB::table('settings')->where('name', 'collection_timeout')->update(['value' => '2']);
        DB::table('settings')->where('name', 'last_run_time')->update([
            'value' => now()->subHour()->format('Y-m-d H:i:s'),
        ]);

        $this->insertCollectionTree(
            130,
            $this->databaseTimestamp('-4 hours'),
            CollectionFileCheckStatus::Default->value,
            0
        );
        $this->insertCollectionTree(
            131,
            $this->databaseTimestamp('-150 minutes'),
            CollectionFileCheckStatus::Default->value,
            0
        );

        app(ReleaseProcessingService::class)->setEchoCLI(false)->processIncompleteCollections(1);

        $this->assertFalse(DB::table('collections')->where('id', 130)->exists());
        $this->assertTrue(DB::table('collections')->where('id', 131)->exists());
    }

    public function test_nzb_creation_cleans_up_collection_binary_and_parts_explicitly(): void
    {
        DB::table('releases')->insert([
            'id' => 1,
            'name' => 'Nzb.Release',
            'searchname' => 'Nzb.Release',
            'totalpart' => 1,
            'groups_id' => 1,
            'adddate' => now()->format('Y-m-d H:i:s'),
            'guid' => str_repeat('a', 36),
            'leftguid' => 'a',
            'postdate' => now()->format('Y-m-d H:i:s'),
            'fromname' => 'poster@example.com',
            'size' => 500,
            'passwordstatus' => 0,
            'haspreview' => -1,
            'categories_id' => 1,
            'nfostatus' => -1,
            'nzbstatus' => NzbService::NZB_NONE,
            'isrenamed' => 1,
            'iscategorized' => 1,
            'predb_id' => 0,
            'source' => null,
        ]);

        DB::table('collections')->insert([
            'id' => 200,
            'subject' => 'Nzb.Release',
            'fromname' => 'poster@example.com',
            'date' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'dateadded' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'added' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'xref' => 'alt.test:200',
            'groups_id' => 1,
            'totalfiles' => 1,
            'filesize' => 500,
            'filecheck' => CollectionFileCheckStatus::Inserted->value,
            'collectionhash' => 'nzb-hash',
            'collection_regexes_id' => 0,
            'releases_id' => 1,
            'noise' => '',
        ]);
        DB::table('binaries')->insert([
            'id' => 2000,
            'name' => 'Nzb.Release yEnc',
            'collections_id' => 200,
            'totalparts' => 1,
        ]);
        DB::table('parts')->insert([
            'binaries_id' => 2000,
            'number' => 1,
            'messageid' => '<nzb-1@example.com>',
            'partnumber' => 1,
            'size' => 10,
        ]);

        $release = Release::query()->findOrFail(1);
        $release->setRelation('category', (object) ['title' => 'Misc', 'parent' => (object) ['title' => 'Other']]);

        $written = app(NzbService::class)->writeNzbForReleaseId($release);

        $this->assertTrue($written);
        $this->assertSame(0, DB::table('parts')->count());
        $this->assertSame(0, DB::table('binaries')->count());
        $this->assertSame(0, DB::table('collections')->count());
        $this->assertSame(1, (int) DB::table('releases')->where('id', 1)->value('nzbstatus'));
    }

    public function test_duplicate_release_path_cleans_up_collection_binary_and_parts(): void
    {
        DB::table('releases')->insert([
            'id' => 2,
            'name' => 'Duplicate.Release',
            'searchname' => 'Duplicate.Release',
            'totalpart' => 1,
            'groups_id' => 1,
            'adddate' => now()->format('Y-m-d H:i:s'),
            'guid' => str_repeat('b', 36),
            'leftguid' => 'b',
            'postdate' => now()->format('Y-m-d H:i:s'),
            'fromname' => 'poster@example.com',
            'size' => 1000,
            'passwordstatus' => 0,
            'haspreview' => -1,
            'categories_id' => 1,
            'nfostatus' => -1,
            'nzbstatus' => NzbService::NZB_NONE,
            'isrenamed' => 1,
            'iscategorized' => 1,
            'predb_id' => 0,
            'source' => null,
        ]);

        DB::table('collections')->insert([
            'id' => 300,
            'subject' => 'Duplicate.Release',
            'fromname' => 'poster@example.com',
            'date' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'dateadded' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'added' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'xref' => 'alt.test:300',
            'groups_id' => 1,
            'totalfiles' => 1,
            'filesize' => 1000,
            'filecheck' => CollectionFileCheckStatus::Sized->value,
            'collectionhash' => 'duplicate-hash',
            'collection_regexes_id' => 0,
            'releases_id' => null,
            'noise' => '',
        ]);
        DB::table('binaries')->insert([
            'id' => 3000,
            'name' => 'Duplicate.Release yEnc',
            'collections_id' => 300,
            'totalparts' => 1,
        ]);
        DB::table('parts')->insert([
            'binaries_id' => 3000,
            'number' => 1,
            'messageid' => '<duplicate-1@example.com>',
            'partnumber' => 1,
            'size' => 10,
        ]);

        $service = new ReleaseCreationService(
            app(ReleaseCleaningService::class),
            app(CollectionCleanupService::class),
            app(ReleaseDuplicateFinder::class)
        );
        $result = $service->createReleases(null, 10, false);

        $this->assertSame(['added' => 0, 'dupes' => 1], $result);
        $this->assertSame(0, DB::table('parts')->count());
        $this->assertSame(0, DB::table('binaries')->count());
        $this->assertSame(0, DB::table('collections')->count());
    }

    public function test_release_duplicate_finder_matches_searchname_within_size_band(): void
    {
        DB::table('releases')->insert([
            'id' => 20,
            'name' => 'raw-obfuscated-a',
            'searchname' => 'Unified.Scene.S01E01.1080p',
            'totalpart' => 1,
            'groups_id' => 1,
            'adddate' => now()->format('Y-m-d H:i:s'),
            'guid' => str_repeat('c', 36),
            'leftguid' => 'c',
            'postdate' => now()->format('Y-m-d H:i:s'),
            'fromname' => 'poster-a@example.com',
            'size' => 1_000_000,
            'passwordstatus' => 0,
            'haspreview' => -1,
            'categories_id' => 1,
            'nfostatus' => -1,
            'nzbstatus' => NzbService::NZB_NONE,
            'isrenamed' => 1,
            'iscategorized' => 1,
            'predb_id' => 0,
            'source' => null,
        ]);

        $finder = app(ReleaseDuplicateFinder::class);
        [$dup, $reason] = $finder->findDuplicate(
            'raw-obfuscated-b',
            'Unified.Scene.S01E01.1080p',
            0,
            1_020_000
        );

        $this->assertNotNull($dup);
        $this->assertSame('searchname_match', $reason);
    }

    public function test_release_duplicate_finder_matches_predb_id_when_searchname_differs(): void
    {
        DB::table('releases')->insert([
            'id' => 21,
            'name' => 'old',
            'searchname' => 'Old Style Name',
            'totalpart' => 1,
            'groups_id' => 1,
            'adddate' => now()->format('Y-m-d H:i:s'),
            'guid' => str_repeat('d', 36),
            'leftguid' => 'd',
            'postdate' => now()->format('Y-m-d H:i:s'),
            'fromname' => 'p@example.com',
            'size' => 2_000_000,
            'passwordstatus' => 0,
            'haspreview' => -1,
            'categories_id' => 1,
            'nfostatus' => -1,
            'nzbstatus' => NzbService::NZB_NONE,
            'isrenamed' => 1,
            'iscategorized' => 1,
            'predb_id' => 9001,
            'source' => null,
        ]);

        $finder = app(ReleaseDuplicateFinder::class);
        [$dup, $reason] = $finder->findDuplicate(
            'new',
            'New Style Name',
            9001,
            2_050_000
        );

        $this->assertNotNull($dup);
        $this->assertSame('predb_id_match', $reason);
    }

    public function test_release_duplicate_finder_does_not_match_outside_size_tolerance(): void
    {
        config(['nntmux.release_dedupe_size_tolerance' => 0.05]);

        DB::table('releases')->insert([
            'id' => 22,
            'name' => 'x',
            'searchname' => 'Same.Search',
            'totalpart' => 1,
            'groups_id' => 1,
            'adddate' => now()->format('Y-m-d H:i:s'),
            'guid' => str_repeat('e', 36),
            'leftguid' => 'e',
            'postdate' => now()->format('Y-m-d H:i:s'),
            'fromname' => 'p@example.com',
            'size' => 1_000_000,
            'passwordstatus' => 0,
            'haspreview' => -1,
            'categories_id' => 1,
            'nfostatus' => -1,
            'nzbstatus' => NzbService::NZB_NONE,
            'isrenamed' => 1,
            'iscategorized' => 1,
            'predb_id' => 0,
            'source' => null,
        ]);

        $finder = app(ReleaseDuplicateFinder::class);
        [$dup] = $finder->findDuplicate('x', 'Same.Search', 0, 1_200_000);

        $this->assertNull($dup);
    }

    public function test_release_duplicate_finder_falls_back_to_name_when_searchname_empty(): void
    {
        DB::table('releases')->insert([
            'id' => 23,
            'name' => 'fallback.unique',
            'searchname' => '',
            'totalpart' => 1,
            'groups_id' => 1,
            'adddate' => now()->format('Y-m-d H:i:s'),
            'guid' => str_repeat('f', 36),
            'leftguid' => 'f',
            'postdate' => now()->format('Y-m-d H:i:s'),
            'fromname' => 'p@example.com',
            'size' => 500,
            'passwordstatus' => 0,
            'haspreview' => -1,
            'categories_id' => 1,
            'nfostatus' => -1,
            'nzbstatus' => NzbService::NZB_NONE,
            'isrenamed' => 1,
            'iscategorized' => 1,
            'predb_id' => 0,
            'source' => null,
        ]);

        $finder = app(ReleaseDuplicateFinder::class);
        [$dup, $reason] = $finder->findDuplicate('fallback.unique', '', 0, 500);

        $this->assertNotNull($dup);
        $this->assertSame('name_match_fallback', $reason);
    }

    private function seedSettings(): void
    {
        $settings = [
            'delaytime' => '2',
            'collection_timeout' => '48',
            'last_run_time' => '',
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
            iscategorized INTEGER,
            predb_id INTEGER,
            source VARCHAR(255) NULL
        )');
        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY,
            subject VARCHAR(255),
            fromname VARCHAR(255),
            date DATETIME NULL,
            dateadded DATETIME NULL,
            added DATETIME NULL,
            last_seen_at DATETIME NULL,
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
            totalparts INTEGER,
            currentparts INTEGER DEFAULT 0,
            partsize INTEGER DEFAULT 0,
            partcheck INTEGER DEFAULT 0
        )');
        DB::statement('CREATE TABLE parts (
            binaries_id INTEGER,
            number INTEGER,
            messageid VARCHAR(255),
            partnumber INTEGER,
            size INTEGER
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

    private function useChicagoApplicationClock(): void
    {
        config(['app.timezone' => 'America/Chicago']);
        date_default_timezone_set('America/Chicago');

        $databaseNow = Carbon::createFromFormat(
            'Y-m-d H:i:s',
            $this->databaseTimestamp(),
            'UTC'
        );

        $this->travelTo($databaseNow->setTimezone('America/Chicago'));
    }

    private function databaseTimestamp(string $modifier = '+0 seconds'): string
    {
        $row = DB::selectOne('SELECT datetime(CURRENT_TIMESTAMP, ?) AS timestamp', [$modifier]);

        return (string) $row->timestamp;
    }

    private function insertCollectionTree(int $id, string $timestamp, int $fileCheck, int $totalFiles): void
    {
        DB::table('collections')->insert([
            'id' => $id,
            'subject' => "Database.Clock.{$id}",
            'fromname' => 'poster@example.com',
            'date' => $timestamp,
            'dateadded' => $timestamp,
            'added' => $timestamp,
            'last_seen_at' => $timestamp,
            'xref' => "alt.test:{$id}",
            'groups_id' => 1,
            'totalfiles' => $totalFiles,
            'filesize' => 0,
            'filecheck' => $fileCheck,
            'collectionhash' => "database-clock-{$id}",
            'collection_regexes_id' => 0,
            'releases_id' => null,
            'noise' => '',
        ]);
        DB::table('binaries')->insert([
            'id' => $id * 10,
            'name' => "Database.Clock.{$id}.par2",
            'collections_id' => $id,
            'totalparts' => 1,
        ]);
        DB::table('parts')->insert([
            'binaries_id' => $id * 10,
            'number' => 1,
            'messageid' => "<database-clock-{$id}@example.com>",
            'partnumber' => 1,
            'size' => 10,
        ]);
    }
}
