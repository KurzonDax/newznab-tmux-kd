<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CollectionFileCheckStatus;
use App\Facades\Search;
use App\Services\CollectionCleanupService;
use App\Services\ReleaseCleaningService;
use App\Services\ReleaseCreationService;
use App\Services\Releases\CollectionArticleRangeMeasurer;
use App\Services\Releases\CollectionCompletionMeasurer;
use App\Services\Releases\ReleaseDuplicateAbsorber;
use App\Services\Releases\ReleaseDuplicateFinder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `completion` is measured from the collections/binaries/parts rows at creation time, while
 * they are still there -- not lazily, whenever NFO post-processing happens to open the NZB.
 */
class ReleaseCreationCompletionTest extends TestCase
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
            static function (?string $pattern, ?string $subject): int {
                if ($pattern === null || $pattern === '' || $subject === null) {
                    return 0;
                }
                set_error_handler(static fn (): true => true);
                $ok = @preg_match('/'.str_replace('/', '\\/', $pattern).'/i', $subject);
                restore_error_handler();

                return $ok === 1 ? 1 : 0;
            },
            2
        );

        Search::shouldReceive('updateRelease')->zeroOrMoreTimes();

        $this->createTables();
        $this->seedSettings();
    }

    #[Test]
    public function a_new_release_carries_its_completion_before_any_post_processing_runs(): void
    {
        $this->insertCollection(100, 'hash-partial', 'Partial.Release.S01E01', totalfiles: 2);
        // 108 of the 110 segments the two files declare.
        $this->insertBinary(100, 1000, 'Partial.Release.S01E01.part01.rar', declaredParts: 50, presentParts: 50);
        $this->insertBinary(100, 1001, 'Partial.Release.S01E01.part02.rar', declaredParts: 60, presentParts: 58);

        $this->assertSame(['added' => 1, 'dupes' => 0], $this->service()->createReleases(null, 10, false));
        $this->assertEqualsWithDelta(98.18, $this->completionOfFirstRelease(), 0.01);
        $this->assertSame(0, (int) DB::table('releases')->value('nzbstatus'));
        $this->assertSame(
            'Partial.Release.S01E01',
            DB::table('releases')->value('searchname_normalized'),
        );
    }

    #[Test]
    public function a_complete_release_measures_one_hundred(): void
    {
        $this->insertCollection(100, 'hash-complete', 'Complete.Release.S01E01', totalfiles: 2);
        $this->insertBinary(100, 1000, 'Complete.Release.S01E01.part01.rar', declaredParts: 10, presentParts: 10);
        $this->insertBinary(100, 1001, 'Complete.Release.S01E01.part02.rar', declaredParts: 20, presentParts: 20);

        $this->service()->createReleases(null, 10, false);

        $this->assertSame(100.0, $this->completionOfFirstRelease());
    }

    #[Test]
    public function the_obfuscated_single_segment_style_is_measured_in_files(): void
    {
        // `[37/240] - "hash" yEnc (1/240)`: one segment per file, the parens repeating a
        // collection-wide total. Summing that total once per file would store 0.42%.
        $this->insertCollection(100, 'hash-obfuscated', '[1/240] - "9f2c1b" yEnc', totalfiles: 240);

        for ($file = 1; $file <= 220; $file++) {
            $this->insertBinary(
                100,
                1000 + $file,
                sprintf('[%d/240] - "9f2c1b%03d" yEnc', $file, $file),
                declaredParts: 240,
                presentParts: 1,
            );
        }

        $this->service()->createReleases(null, 10, false);

        $this->assertEqualsWithDelta(91.67, $this->completionOfFirstRelease(), 0.01);
    }

    #[Test]
    public function the_files_present_are_the_numerator_not_the_repeated_total(): void
    {
        // Observed in prod: `[10/1083]` with the parens repeating 1083 and 220 files present.
        $this->insertCollection(100, 'hash-1083', '[1/1083] - "9f2c1b" yEnc', totalfiles: 1083);

        for ($file = 1; $file <= 220; $file++) {
            $this->insertBinary(
                100,
                1000 + $file,
                sprintf('[%d/1083] - "9f2c1b%03d" yEnc', $file, $file),
                declaredParts: 1083,
                presentParts: 1,
            );
        }

        $this->service()->createReleases(null, 10, false);

        $this->assertEqualsWithDelta(20.31, $this->completionOfFirstRelease(), 0.01);
    }

    #[Test]
    public function a_normal_post_held_one_segment_per_file_is_never_measured_in_files(): void
    {
        // Equal-sized rar volumes declare identical totals, so a badly incomplete normal post has
        // the obfuscated style's exact shape. Measuring it in files would store 10% for a release
        // holding 5 segments of the 25,000 its 50 declared files would carry.
        $this->insertCollection(100, 'hash-normal-thin', '[1/50] - "Thin.Release" yEnc', totalfiles: 50);

        for ($file = 1; $file <= 5; $file++) {
            $this->insertBinary(
                100,
                1000 + $file,
                sprintf('[%d/50] - "Thin.Release.part%02d.rar" yEnc', $file, $file),
                declaredParts: 500,
                presentParts: 1,
            );
        }

        $this->service()->createReleases(null, 10, false);

        $this->assertSame(0.0, $this->completionOfFirstRelease());
    }

    #[Test]
    public function a_stale_promoted_collection_is_measured_against_what_the_headers_declared(): void
    {
        // ReleaseProcessingService rewrites `totalfiles` to the files actually present once a
        // collection goes stale, which used to make the two totals contradict each other and
        // leave the release unmeasured. `declaredfiles` survives that rewrite, so 220 of 240
        // files is now simply 220 of 240.
        $this->insertCollection(100, 'hash-stale', '[1/240] - "9f2c1b" yEnc', totalfiles: 220, declaredfiles: 240);

        for ($file = 1; $file <= 220; $file++) {
            $this->insertBinary(
                100,
                1000 + $file,
                sprintf('[%d/240] - "9f2c1b%03d" yEnc', $file, $file),
                declaredParts: 240,
                presentParts: 1,
            );
        }

        $this->service()->createReleases(null, 10, false);

        $this->assertEqualsWithDelta(91.67, $this->completionOfFirstRelease(), 0.01);
    }

    #[Test]
    public function a_whole_missing_file_drags_completion_down_even_when_every_held_segment_is_present(): void
    {
        // 9 of 10 declared files, every segment of those 9 held. The missing file has no binaries
        // row, so it is in neither side of the raw segment ratio and this used to read as 100%.
        $this->insertCollection(100, 'hash-missing-file', 'Short.Release.S01E01', totalfiles: 9, declaredfiles: 10);

        for ($file = 1; $file <= 9; $file++) {
            $this->insertBinary(
                100,
                1000 + $file,
                sprintf('Short.Release.S01E01.part%02d.rar', $file),
                declaredParts: 20,
                presentParts: 20,
            );
        }

        $this->service()->createReleases(null, 10, false);

        $this->assertEqualsWithDelta(90.0, $this->completionOfFirstRelease(), 0.01);
    }

    #[Test]
    public function a_release_holding_every_declared_file_is_unchanged(): void
    {
        $this->insertCollection(100, 'hash-all-files', 'Whole.Release.S01E01', totalfiles: 10, declaredfiles: 10);

        for ($file = 1; $file <= 10; $file++) {
            $this->insertBinary(
                100,
                1000 + $file,
                sprintf('Whole.Release.S01E01.part%02d.rar', $file),
                declaredParts: 20,
                presentParts: 20,
            );
        }

        $this->service()->createReleases(null, 10, false);

        $this->assertSame(100.0, $this->completionOfFirstRelease());
    }

    #[Test]
    public function a_stale_promoted_release_keeps_the_declared_count_and_the_seen_count_apart(): void
    {
        $this->insertCollection(100, 'hash-declared', 'Stale.Release.S01E01', totalfiles: 3, declaredfiles: 8);
        $this->insertBinary(100, 1000, 'Stale.Release.S01E01.part01.rar', declaredParts: 10, presentParts: 10);
        $this->insertBinary(100, 1001, 'Stale.Release.S01E01.part02.rar', declaredParts: 10, presentParts: 10);
        $this->insertBinary(100, 1002, 'Stale.Release.S01E01.part03.rar', declaredParts: 10, presentParts: 10);

        $this->service()->createReleases(null, 10, false);

        $release = DB::table('releases')->orderBy('id')->first();
        $this->assertSame(8, (int) $release->declaredfiles);
        $this->assertSame(3, (int) $release->totalpart, 'totalpart keeps its "files we hold" meaning.');
    }

    #[Test]
    public function a_normally_completed_release_declares_exactly_what_it_holds(): void
    {
        $this->insertCollection(100, 'hash-normal', 'Normal.Release.S01E01', totalfiles: 2);
        $this->insertBinary(100, 1000, 'Normal.Release.S01E01.part01.rar', declaredParts: 10, presentParts: 10);
        $this->insertBinary(100, 1001, 'Normal.Release.S01E01.part02.rar', declaredParts: 10, presentParts: 10);

        $this->service()->createReleases(null, 10, false);

        $release = DB::table('releases')->orderBy('id')->first();
        $this->assertSame(2, (int) $release->declaredfiles);
        $this->assertSame(2, (int) $release->totalpart);
    }

    #[Test]
    public function the_article_anchors_span_the_part_numbers_the_binaries_held(): void
    {
        // The NZB keeps no article numbers -- the parts rows are deleted at NZB creation -- so a
        // later header re-scan has nothing to aim at unless they are captured here.
        $this->insertCollection(100, 'hash-anchors', 'Anchored.Release.S01E01', totalfiles: 2);
        $this->insertBinary(100, 1000, 'Anchored.Release.S01E01.part01.rar', declaredParts: 3, presentParts: 3);
        $this->insertBinary(100, 1001, 'Anchored.Release.S01E01.part02.rar', declaredParts: 3, presentParts: 3);

        $this->service()->createReleases(null, 10, false);

        $expected = DB::selectOne(
            'SELECT MIN(p.number) AS first_article, MAX(p.number) AS last_article
             FROM parts p INNER JOIN binaries b ON b.id = p.binaries_id
             WHERE b.collections_id = 100'
        );

        $release = DB::table('releases')->orderBy('id')->first();
        $this->assertSame((int) $expected->first_article, (int) $release->firstarticle);
        $this->assertSame((int) $expected->last_article, (int) $release->lastarticle);
    }

    #[Test]
    public function subjects_declaring_no_part_totals_keep_the_never_measured_sentinel(): void
    {
        $this->insertCollection(100, 'hash-unknown', 'Unmeasurable.Release.S01E01', totalfiles: 1);
        $this->insertBinary(100, 1000, 'Unmeasurable.Release.S01E01.rar', declaredParts: 0, presentParts: 4);

        $this->service()->createReleases(null, 10, false);

        $this->assertSame(0.0, $this->completionOfFirstRelease());
    }

    private function service(): ReleaseCreationService
    {
        return new ReleaseCreationService(
            app(ReleaseCleaningService::class),
            app(CollectionCleanupService::class),
            app(ReleaseDuplicateFinder::class),
            app(CollectionCompletionMeasurer::class),
            app(ReleaseDuplicateAbsorber::class),
            app(CollectionArticleRangeMeasurer::class),
        );
    }

    private function completionOfFirstRelease(): float
    {
        return (float) DB::table('releases')->orderBy('id')->value('completion');
    }

    /**
     * @param  int|null  $declaredfiles  What the headers declared, before stale promotion rewrote
     *                                   `totalfiles` to the files actually seen. Defaults to
     *                                   agreeing with `totalfiles`, as a normal collection does.
     */
    private function insertCollection(
        int $id,
        string $hash,
        string $subject,
        int $totalfiles = 1,
        ?int $declaredfiles = null,
    ): void {
        DB::table('collections')->insert([
            'id' => $id,
            'subject' => $subject,
            'fromname' => 'poster@example.com',
            'date' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'dateadded' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'added' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'xref' => 'alt.test:'.$id,
            'groups_id' => 1,
            'totalfiles' => $totalfiles,
            'declaredfiles' => $declaredfiles ?? $totalfiles,
            'filesize' => 1000,
            'filecheck' => CollectionFileCheckStatus::Sized->value,
            'collectionhash' => $hash,
            'collection_regexes_id' => 0,
            'releases_id' => null,
            'noise' => '',
        ]);
    }

    private function insertBinary(int $collectionId, int $binaryId, string $name, int $declaredParts, int $presentParts): void
    {
        DB::table('binaries')->insert([
            'id' => $binaryId,
            'name' => $name,
            'collections_id' => $collectionId,
            'totalparts' => $declaredParts,
        ]);

        $parts = [];
        for ($number = 1; $number <= $presentParts; $number++) {
            $parts[] = [
                'binaries_id' => $binaryId,
                'number' => $binaryId * 1000 + $number,
                'messageid' => sprintf('<part-%d-%d@example.com>', $binaryId, $number),
                'partnumber' => $number,
                'size' => 10,
            ];
        }

        if ($parts !== []) {
            DB::table('parts')->insert($parts);
        }
    }

    private function seedSettings(): void
    {
        foreach ([
            'partretentionhours' => '1',
            'nzbsplitlevel' => '1',
            'check_passworded_rars' => '0',
            'categorizeforeign' => '1',
            'catwebdl' => '1',
        ] as $name => $value) {
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
            searchname_normalized VARCHAR(255),
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
            declaredfiles INTEGER NOT NULL DEFAULT 0,
            firstarticle INTEGER NULL,
            lastarticle INTEGER NULL,
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

        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.test']);
        DB::table('categories')->insert(['id' => 10, 'title' => 'Other Misc', 'parent_categories_id' => null]);
    }
}
