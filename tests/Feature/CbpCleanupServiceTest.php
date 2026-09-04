<?php

namespace Tests\Feature;

use App\Enums\CollectionFileCheckStatus;
use App\Facades\Search;
use App\Models\Release;
use App\Services\CollectionCleanupService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseCleaningService;
use App\Services\ReleaseCreationService;
use App\Services\ReleaseProcessingService;
use App\Services\Releases\CollectionCompletionMeasurer;
use App\Services\Releases\ReleaseDuplicateAbsorber;
use App\Services\Releases\ReleaseDuplicateFinder;
use App\Support\ReleaseNameNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Output\BufferedOutput;
use Termwind\Termwind;
use Tests\TestCase;

class CbpCleanupServiceTest extends TestCase
{
    private string $originalTimezone;

    private string $nzbDirectory;

    protected function setUp(): void
    {
        $this->originalTimezone = date_default_timezone_get();

        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        $this->nzbDirectory = $this->makeTempDirectory('cbp-dedupe').DIRECTORY_SEPARATOR;
        config([
            'nntmux_settings.path_to_nzbs' => $this->nzbDirectory,
            'nntmux_settings.check_passworded_rars' => true,
            'nntmux_settings.unrar_path' => '/usr/bin/unrar',
        ]);
        $this->registerSqliteFunction('UNIX_TIMESTAMP', static fn (?string $value): int => strtotime((string) $value));
        $this->registerSqliteFunction('GREATEST', static fn (mixed ...$values): mixed => max($values));
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
        Search::shouldReceive('updateRelease')->zeroOrMoreTimes();
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
            0,
            dateAdded: $this->databaseTimestamp('-30 minutes'),
        );

        app(ReleaseProcessingService::class)->setEchoCLI(false)->processIncompleteCollections(1);

        $collection = DB::table('collections')->find(110);

        $this->assertNotNull($collection);
        $this->assertSame(CollectionFileCheckStatus::CompleteParts->value, (int) $collection->filecheck);
        $this->assertSame(1, (int) $collection->totalfiles);
    }

    public function test_collection_seen_within_delay_is_not_promoted_when_dateadded_is_old(): void
    {
        $this->insertCollectionTree(
            111,
            now()->subMinutes(30)->format('Y-m-d H:i:s'),
            CollectionFileCheckStatus::Default->value,
            0,
            dateAdded: now()->subHours(3)->format('Y-m-d H:i:s'),
        );

        app(ReleaseProcessingService::class)->setEchoCLI(false)->processIncompleteCollections(1);

        $collection = DB::table('collections')->find(111);

        $this->assertNotNull($collection);
        $this->assertSame(CollectionFileCheckStatus::Default->value, (int) $collection->filecheck);
        $this->assertSame(0, (int) $collection->totalfiles);
    }

    public function test_collection_without_last_seen_at_promotes_on_dateadded(): void
    {
        $this->insertCollectionTree(
            112,
            now()->subMinutes(30)->format('Y-m-d H:i:s'),
            CollectionFileCheckStatus::Default->value,
            0,
            dateAdded: now()->subHours(3)->format('Y-m-d H:i:s'),
        );
        DB::table('collections')->where('id', 112)->update(['last_seen_at' => null]);

        app(ReleaseProcessingService::class)->setEchoCLI(false)->processIncompleteCollections(1);

        $collection = DB::table('collections')->find(112);

        $this->assertNotNull($collection);
        $this->assertSame(CollectionFileCheckStatus::CompleteParts->value, (int) $collection->filecheck);
        $this->assertSame(1, (int) $collection->totalfiles);
    }

    public function test_declared_file_count_still_promotes_complete_binaries(): void
    {
        $this->insertCollectionTree(
            113,
            now()->subHours(3)->format('Y-m-d H:i:s'),
            CollectionFileCheckStatus::TempComplete->value,
            1,
            dateAdded: now()->subMinutes(30)->format('Y-m-d H:i:s'),
        );

        app(ReleaseProcessingService::class)->setEchoCLI(false)->processIncompleteCollections(1);

        $collection = DB::table('collections')->find(113);

        $this->assertNotNull($collection);
        $this->assertSame(CollectionFileCheckStatus::CompleteParts->value, (int) $collection->filecheck);
        $this->assertSame(1, (int) $collection->totalfiles);
    }

    public function test_collection_promotion_falls_back_to_dateadded_when_last_seen_column_is_absent(): void
    {
        DB::statement('ALTER TABLE collections DROP COLUMN last_seen_at');
        $this->insertCollectionTree(
            114,
            now()->subHours(3)->format('Y-m-d H:i:s'),
            CollectionFileCheckStatus::Default->value,
            0,
        );

        app(ReleaseProcessingService::class)->setEchoCLI(false)->processIncompleteCollections(1);

        $collection = DB::table('collections')->find(114);

        $this->assertNotNull($collection);
        $this->assertSame(CollectionFileCheckStatus::CompleteParts->value, (int) $collection->filecheck);
        $this->assertSame(1, (int) $collection->totalfiles);
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

    public function test_inactive_groups_with_sized_collections_keep_their_quality_policy(): void
    {
        DB::table('usenet_groups')->insert([
            'id' => 2,
            'name' => 'alt.inactive',
            'active' => 0,
            'minsizetoformrelease' => 1000,
            'minfilestoformrelease' => 3,
        ]);

        $this->insertSizedCollectionTree(140, 2, 500, 5);
        $this->insertSizedCollectionTree(141, 2, 6000, 5);
        $this->insertSizedCollectionTree(142, 2, 2000, 1);

        app(ReleaseProcessingService::class)->setEchoCLI(false)->deleteUnwantedCollections(null);

        $this->assertDatabaseMissing('collections', ['id' => 140]);
        $this->assertDatabaseMissing('collections', ['id' => 141]);
        $this->assertDatabaseMissing('collections', ['id' => 142]);
        $this->assertSame(0, DB::table('binaries')->whereIn('collections_id', [140, 141, 142])->count());
        $this->assertSame(0, DB::table('parts')->whereIn('binaries_id', [1400, 1410, 1420])->count());
    }

    public function test_inactive_groups_with_releases_are_included_in_per_group_cleanup(): void
    {
        DB::table('usenet_groups')->insert([
            'id' => 2,
            'name' => 'alt.inactive',
            'active' => 0,
            'minsizetoformrelease' => 0,
            'minfilestoformrelease' => 200,
        ]);
        $this->insertRelease(140, 'Inactive.Underfilled.Release', 1000, groupId: 2);
        Search::shouldReceive('deleteReleases')->once()->with([140]);

        app(ReleaseProcessingService::class)->setEchoCLI(false)->deletedReleasesByGroup();

        $this->assertDatabaseMissing('releases', ['id' => 140]);
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
            'completion' => 91.67,
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
        // The writer reconciles against the CBP it just streamed, so late-arriving parts cannot
        // leave stale completion behind after those source rows are removed.
        $this->assertSame(100.0, (float) DB::table('releases')->where('id', 1)->value('completion'));
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
            'completion' => 100.0,
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
            app(ReleaseDuplicateFinder::class),
            app(CollectionCompletionMeasurer::class),
            app(ReleaseDuplicateAbsorber::class),
        );
        $result = $service->createReleases(null, 10, false);

        $this->assertSame(['added' => 0, 'dupes' => 1], $result);
        $this->assertSame(0, DB::table('parts')->count());
        $this->assertSame(0, DB::table('binaries')->count());
        $this->assertSame(0, DB::table('collections')->count());
    }

    public function test_more_complete_duplicate_collection_is_absorbed_into_the_existing_release(): void
    {
        $guid = str_repeat('h', 36);
        DB::table('releases')->insert([
            'id' => 3,
            'name' => 'Better.Native.Repost',
            'searchname' => 'Better.Native.Repost',
            'searchname_normalized' => 'Better.Native.Repost',
            'totalpart' => 1,
            'declaredfiles' => 1,
            'groups_id' => 1,
            'adddate' => now()->format('Y-m-d H:i:s'),
            'guid' => $guid,
            'leftguid' => 'h',
            'postdate' => now()->format('Y-m-d H:i:s'),
            'fromname' => 'poster@example.com',
            'size' => 19_000,
            'passwordstatus' => 1,
            'haspreview' => 0,
            'categories_id' => 1,
            'nfostatus' => 0,
            'nzbstatus' => NzbService::NZB_ADDED,
            'completion' => 95.0,
            'isrenamed' => 1,
            'is_trusted_name' => 1,
            'iscategorized' => 1,
            'predb_id' => 0,
        ]);

        $nzb = app(NzbService::class);
        file_put_contents(
            $nzb->getNzbPath($guid, 0, true),
            gzencode('<nzb><file subject="old"><segments><segment bytes="1000" number="1">old@example.test</segment></segments></file></nzb>'),
        );

        DB::table('collections')->insert([
            'id' => 301,
            'subject' => 'Better.Native.Repost',
            'fromname' => 'poster@example.com',
            'date' => now()->subHour()->format('Y-m-d H:i:s'),
            'dateadded' => now()->subHour()->format('Y-m-d H:i:s'),
            'added' => now()->subHour()->format('Y-m-d H:i:s'),
            'xref' => 'alt.test:301',
            'groups_id' => 1,
            'totalfiles' => 1,
            'declaredfiles' => 1,
            'filesize' => 20_000,
            'filecheck' => CollectionFileCheckStatus::Sized->value,
            'collectionhash' => 'better-native-hash',
            'collection_regexes_id' => 0,
            'releases_id' => null,
            'noise' => '',
        ]);
        DB::table('binaries')->insert([
            'id' => 3010,
            'name' => 'Better.Native.Repost.part01.rar yEnc (1/20)',
            'collections_id' => 301,
            'totalparts' => 20,
        ]);
        foreach (range(1, 20) as $part) {
            DB::table('parts')->insert([
                'binaries_id' => 3010,
                'number' => $part,
                'messageid' => "<new-{$part}@example.test>",
                'partnumber' => $part,
                'size' => 1_000,
            ]);
        }

        $result = app(ReleaseCreationService::class)->createReleases(null, 10, false);

        $this->assertSame(['added' => 0, 'dupes' => 1], $result);
        $this->assertSame(1, DB::table('releases')->count());
        $stored = DB::table('releases')->find(3);
        $this->assertNotNull($stored);
        $this->assertSame($guid, $stored->guid);
        $this->assertSame(20_000, (int) $stored->size);
        $this->assertSame(100.0, (float) $stored->completion);
        $this->assertSame(1, (int) $stored->totalpart);
        $this->assertSame(0, (int) $stored->proc_files);
        $this->assertSame(0, DB::table('collections')->count());

        $contents = $nzb->readNzbContents($guid);
        $this->assertIsString($contents);
        $this->assertStringContainsString('new-20@example.test', $contents);
        $this->assertStringNotContainsString('old@example.test', $contents);
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

    public function test_release_duplicate_finder_matches_quoted_part_file_candidate(): void
    {
        $this->insertRelease(24, 'HookupHotshot - 2020 Flashback Highlight Compilation', 13_897_458_182);

        $finder = app(ReleaseDuplicateFinder::class);
        [$dup, $reason] = $finder->findDuplicate(
            '"HookupHotshot - 2020 Flashback Highlight Compilation.part018.rar" yEnc',
            '"HookupHotshot - 2020 Flashback Highlight Compilation.part018.rar"',
            0,
            13_897_458_182
        );

        $this->assertNotNull($dup);
        $this->assertSame(24, (int) $dup->id);
        $this->assertSame('normalized_searchname_match', $reason);
    }

    public function test_release_duplicate_finder_matches_stored_quoted_part_file_release(): void
    {
        $this->insertRelease(25, '"HookupHotshot - 2020 Flashback Highlight Compilation.part018.rar"', 13_897_458_182);

        $finder = app(ReleaseDuplicateFinder::class);
        [$dup, $reason] = $finder->findDuplicate(
            'HookupHotshot - 2020 Flashback Highlight Compilation',
            'HookupHotshot - 2020 Flashback Highlight Compilation',
            0,
            13_897_458_182
        );

        $this->assertNotNull($dup);
        $this->assertSame(25, (int) $dup->id);
        $this->assertSame('normalized_searchname_match', $reason);
    }

    public function test_release_duplicate_finder_matches_stored_unquoted_part_file_release(): void
    {
        $this->insertRelease(26, 'HookupHotshot - 2020 Flashback Highlight Compilation.vol012+10.par2', 13_897_458_182);

        $finder = app(ReleaseDuplicateFinder::class);
        [$dup, $reason] = $finder->findDuplicate(
            'HookupHotshot - 2020 Flashback Highlight Compilation',
            'HookupHotshot - 2020 Flashback Highlight Compilation',
            0,
            13_897_458_182
        );

        $this->assertNotNull($dup);
        $this->assertSame(26, (int) $dup->id);
        $this->assertSame('normalized_searchname_match', $reason);
    }

    public function test_release_duplicate_finder_normalization_still_respects_size_tolerance(): void
    {
        config(['nntmux.release_dedupe_size_tolerance' => 0.05]);

        $this->insertRelease(27, 'HookupHotshot - 2020 Flashback Highlight Compilation', 1_000_000);

        $finder = app(ReleaseDuplicateFinder::class);
        [$dup, $reason] = $finder->findDuplicate(
            '"HookupHotshot - 2020 Flashback Highlight Compilation.part018.rar" yEnc',
            '"HookupHotshot - 2020 Flashback Highlight Compilation.part018.rar"',
            0,
            1_200_000
        );

        $this->assertNull($dup);
        $this->assertNull($reason);
    }

    public function test_release_duplicate_finder_does_not_match_a_different_release_with_the_same_prefix(): void
    {
        $this->insertRelease(28, 'HookupHotshot - 2020 Flashback Highlight Compilation.Extras', 13_897_458_182);

        $finder = app(ReleaseDuplicateFinder::class);
        [$dup, $reason] = $finder->findDuplicate(
            '"HookupHotshot - 2020 Flashback Highlight Compilation.part018.rar" yEnc',
            '"HookupHotshot - 2020 Flashback Highlight Compilation.part018.rar"',
            0,
            13_897_458_182
        );

        $this->assertNull($dup);
        $this->assertNull($reason);
    }

    public function test_normalized_duplicate_identity_finds_the_true_match_after_twenty_five_prefixes(): void
    {
        for ($id = 101; $id <= 125; $id++) {
            $this->insertRelease($id, 'ReleaseName.Extras.'.$id, 13_897_458_182);
        }
        $this->insertRelease(126, '"ReleaseName.part009.rar"', 13_897_458_182);

        $finder = app(ReleaseDuplicateFinder::class);
        [$dup, $reason] = $finder->findDuplicate(
            'ReleaseName',
            'ReleaseName',
            0,
            13_897_458_182
        );

        $this->assertNotNull($dup);
        $this->assertSame(126, (int) $dup->id);
        $this->assertSame('normalized_searchname_match', $reason);
    }

    public function test_normalized_duplicate_identity_sees_a_legacy_counter_prefix(): void
    {
        $this->insertRelease(127, '[10/88] "ReleaseName.part009.rar" yEnc', 13_897_458_182);

        $finder = app(ReleaseDuplicateFinder::class);
        [$dup, $reason] = $finder->findDuplicate(
            'ReleaseName',
            'ReleaseName',
            0,
            13_897_458_182
        );

        $this->assertNotNull($dup);
        $this->assertSame(127, (int) $dup->id);
        $this->assertSame('normalized_searchname_match', $reason);
    }

    public function test_duplicate_anchor_is_highest_completion_with_id_as_tiebreak(): void
    {
        $this->insertRelease(130, '"ReleaseName.part001.rar"', 13_897_458_182);
        $this->insertRelease(129, 'ReleaseName.par2', 13_897_458_182);
        $this->insertRelease(128, 'ReleaseName.rar', 13_897_458_182);
        DB::table('releases')->where('id', 130)->update(['completion' => 95.0]);
        DB::table('releases')->where('id', 129)->update(['completion' => 95.0]);
        DB::table('releases')->where('id', 128)->update(['completion' => 80.0]);

        $finder = app(ReleaseDuplicateFinder::class);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            [$dup, $reason] = $finder->findDuplicate('ReleaseName', 'ReleaseName', 0, 13_897_458_182);

            $this->assertNotNull($dup);
            $this->assertSame(129, (int) $dup->id);
            $this->assertSame('normalized_searchname_match', $reason);
        }
    }

    public function test_exact_duplicate_anchor_uses_the_same_quality_ordering(): void
    {
        $this->insertRelease(133, 'Exact.ReleaseName', 13_897_458_182);
        $this->insertRelease(132, 'Exact.ReleaseName', 13_897_458_182);
        $this->insertRelease(131, 'Exact.ReleaseName', 13_897_458_182);
        DB::table('releases')->where('id', 133)->update(['completion' => 95.0]);
        DB::table('releases')->where('id', 132)->update(['completion' => 95.0]);
        DB::table('releases')->where('id', 131)->update(['completion' => 80.0]);

        [$dup, $reason] = app(ReleaseDuplicateFinder::class)->findDuplicate(
            'Exact.ReleaseName',
            'Exact.ReleaseName',
            0,
            13_897_458_182,
        );

        $this->assertNotNull($dup);
        $this->assertSame(132, (int) $dup->id);
        $this->assertSame('searchname_match', $reason);
    }

    public function test_quality_ordering_selects_the_best_anchor_across_exact_and_normalized_matches(): void
    {
        $this->insertRelease(134, 'Cross.Arm.Release', 13_897_458_182);
        $this->insertRelease(135, '[1/2] "Cross.Arm.Release.part001.rar" yEnc', 13_897_458_182);
        DB::table('releases')->where('id', 134)->update(['completion' => 80.0]);
        DB::table('releases')->where('id', 135)->update(['completion' => 95.0]);

        [$dup, $reason] = app(ReleaseDuplicateFinder::class)->findDuplicate(
            'Cross.Arm.Release',
            'Cross.Arm.Release',
            0,
            13_897_458_182,
        );

        $this->assertNotNull($dup);
        $this->assertSame(135, (int) $dup->id);
        $this->assertSame('normalized_searchname_match', $reason);
    }

    private function insertRelease(int $id, string $searchName, int $size, int $groupId = 1): void
    {
        DB::table('releases')->insert([
            'id' => $id,
            'name' => 'raw-subject-'.$id,
            'searchname' => $searchName,
            'searchname_normalized' => ReleaseNameNormalizer::normalize($searchName),
            'totalpart' => 135,
            'groups_id' => $groupId,
            'adddate' => now()->format('Y-m-d H:i:s'),
            'guid' => str_pad((string) $id, 36, 'g'),
            'leftguid' => 'g',
            'postdate' => now()->format('Y-m-d H:i:s'),
            'fromname' => 'CCP@gmail.com (AdultPoster)',
            'size' => $size,
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
    }

    /**
     * A retention cutoff derived from an unusable setting used to land on "now" -- deleting
     * every collection still being assembled -- or, for a blanked row, to throw out of
     * `subHours('')`. Anything that is not a positive number of hours resolves to the
     * seeded default instead.
     *
     * The ages straddle the default by an hour in each direction, so a cutoff that is off
     * by more than that fails here.
     *
     * @param  string|null  $stored  the stored value, or null for a row that never existed
     */
    #[DataProvider('unusableRetentionSettings')]
    public function test_an_unusable_retention_setting_falls_back_to_the_seeded_default(?string $stored): void
    {
        $this->storeRetentionHours($stored);
        $this->insertCollectionTree(
            300,
            now()->subHours(71)->format('Y-m-d H:i:s'),
            CollectionFileCheckStatus::Default->value,
            1
        );
        $this->insertCollectionTree(
            301,
            now()->subHours(73)->format('Y-m-d H:i:s'),
            CollectionFileCheckStatus::Default->value,
            1
        );

        app(CollectionCleanupService::class)->deleteFinishedAndOrphans(false);

        $this->assertSame([300], DB::table('collections')->orderBy('id')->pluck('id')->all());
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function unusableRetentionSettings(): array
    {
        return [
            'missing row' => [null],
            'blanked in the admin form' => [''],
            'stored zero' => ['0'],
            'stored negative' => ['-12'],
            'non-numeric' => ['never'],
        ];
    }

    public function test_a_stored_positive_retention_setting_is_honored(): void
    {
        $this->storeRetentionHours('12');
        $this->insertCollectionTree(
            310,
            now()->subHours(11)->format('Y-m-d H:i:s'),
            CollectionFileCheckStatus::Default->value,
            1
        );
        $this->insertCollectionTree(
            311,
            now()->subHours(13)->format('Y-m-d H:i:s'),
            CollectionFileCheckStatus::Default->value,
            1
        );

        app(CollectionCleanupService::class)->deleteFinishedAndOrphans(false);

        $this->assertSame([310], DB::table('collections')->orderBy('id')->pluck('id')->all());
    }

    public function test_the_cleanup_message_reports_the_retention_actually_used(): void
    {
        $this->storeRetentionHours('');
        $consoleOutput = new BufferedOutput;
        Termwind::renderUsing($consoleOutput);

        ob_start();
        try {
            app(CollectionCleanupService::class)->deleteFinishedAndOrphans(true);
        } finally {
            ob_end_clean();
            Termwind::renderUsing(null);
        }

        $this->assertStringContainsString(
            'older than 72 hours',
            $consoleOutput->fetch()
        );
    }

    /**
     * @param  string|null  $value  the stored value, or null to leave the row missing
     */
    private function storeRetentionHours(?string $value): void
    {
        DB::table('settings')->where('name', 'partretentionhours')->delete();

        if ($value !== null) {
            DB::table('settings')->insert(['name' => 'partretentionhours', 'value' => $value]);
        }
    }

    private function seedSettings(): void
    {
        $settings = [
            'delaytime' => '2',
            'collection_timeout' => '48',
            'last_run_time' => '',
            'partretentionhours' => '1',
            'maxsizetoformrelease' => '5000',
            'minsizetoformrelease' => '0',
            'minfilestoformrelease' => '0',
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
        DB::statement('CREATE TABLE usenet_groups (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255),
            active INTEGER NOT NULL DEFAULT 1,
            minsizetoformrelease INTEGER NULL,
            minfilestoformrelease INTEGER NULL
        )');
        DB::statement('CREATE TABLE categories (id INTEGER PRIMARY KEY, title VARCHAR(255), parent_categories_id INTEGER NULL)');
        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255),
            searchname VARCHAR(255),
            searchname_normalized VARCHAR(255),
            display_name VARCHAR(255),
            totalpart INTEGER,
            declaredfiles INTEGER NULL,
            firstarticle INTEGER NULL,
            lastarticle INTEGER NULL,
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
            completion DOUBLE NOT NULL DEFAULT 0,
            pp_timeout_count INTEGER NOT NULL DEFAULT 0,
            proc_nfo INTEGER NOT NULL DEFAULT 0,
            proc_files INTEGER NOT NULL DEFAULT 0,
            proc_srr INTEGER NOT NULL DEFAULT 0,
            proc_crc32 INTEGER NOT NULL DEFAULT 0,
            proc_uid INTEGER NOT NULL DEFAULT 0,
            proc_hash16k INTEGER NOT NULL DEFAULT 0,
            proc_par2 INTEGER NOT NULL DEFAULT 0,
            proc_srrdb INTEGER NOT NULL DEFAULT 0,
            proc_xxx INTEGER NOT NULL DEFAULT 0,
            proc_media_movie INTEGER NOT NULL DEFAULT 0,
            isrenamed INTEGER,
            is_trusted_name INTEGER NOT NULL DEFAULT 0,
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
            declaredfiles INT NOT NULL DEFAULT 0,
            firstarticle INT NULL,
            lastarticle INT NULL,
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
        DB::statement('CREATE TABLE video_data (id INTEGER PRIMARY KEY, releases_id INTEGER)');
        DB::statement('CREATE TABLE audio_data (id INTEGER PRIMARY KEY, releases_id INTEGER)');
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

    private function insertCollectionTree(
        int $id,
        string $activityTimestamp,
        int $fileCheck,
        int $totalFiles,
        ?string $dateAdded = null,
    ): void {
        $collection = [
            'id' => $id,
            'subject' => "Database.Clock.{$id}",
            'fromname' => 'poster@example.com',
            'date' => $activityTimestamp,
            'dateadded' => $dateAdded ?? $activityTimestamp,
            'added' => $activityTimestamp,
            'xref' => "alt.test:{$id}",
            'groups_id' => 1,
            'totalfiles' => $totalFiles,
            'filesize' => 0,
            'filecheck' => $fileCheck,
            'collectionhash' => "database-clock-{$id}",
            'collection_regexes_id' => 0,
            'releases_id' => null,
            'noise' => '',
        ];
        if (DB::getSchemaBuilder()->hasColumn('collections', 'last_seen_at')) {
            $collection['last_seen_at'] = $activityTimestamp;
        }
        DB::table('collections')->insert($collection);
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

    private function insertSizedCollectionTree(int $id, int $groupId, int $fileSize, int $totalFiles): void
    {
        $timestamp = now()->format('Y-m-d H:i:s');
        DB::table('collections')->insert([
            'id' => $id,
            'subject' => "Inactive.Group.Policy.{$id}",
            'fromname' => 'poster@example.com',
            'date' => $timestamp,
            'dateadded' => $timestamp,
            'added' => $timestamp,
            'last_seen_at' => $timestamp,
            'xref' => "alt.inactive:{$id}",
            'groups_id' => $groupId,
            'totalfiles' => $totalFiles,
            'filesize' => $fileSize,
            'filecheck' => CollectionFileCheckStatus::Sized->value,
            'collectionhash' => "inactive-policy-{$id}",
            'collection_regexes_id' => 0,
            'releases_id' => null,
            'noise' => '',
        ]);
        DB::table('binaries')->insert([
            'id' => $id * 10,
            'name' => "Inactive.Group.Policy.{$id}.rar",
            'collections_id' => $id,
            'totalparts' => 1,
        ]);
        DB::table('parts')->insert([
            'binaries_id' => $id * 10,
            'number' => 1,
            'messageid' => "<inactive-policy-{$id}@example.com>",
            'partnumber' => 1,
            'size' => 10,
        ]);
    }
}
