<?php

namespace Tests\Feature;

use App\Enums\NzbImportStatus;
use App\Facades\Search;
use App\Models\Release;
use App\Models\UsenetGroup;
use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinariesService;
use App\Services\Nzb\NzbImportService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseRepair\RescanWindowResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class NzbImportSegmentHashDedupeTest extends TestCase
{
    private string $nzbDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        $this->nzbDirectory = $this->makeTempDirectory('import-dedupe').DIRECTORY_SEPARATOR;
        config([
            'nntmux_settings.path_to_nzbs' => $this->nzbDirectory,
            'nntmux_settings.check_passworded_rars' => true,
            'nntmux_settings.unrar_path' => '/usr/bin/unrar',
        ]);
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

    public function test_import_persists_sorted_segment_message_id_hash(): void
    {
        $status = $this->scan($this->makeNzb([
            ['subject' => '[1/2] Hashed.Import.Release.part01.rar yEnc (1/2)', 'segments' => ['seg-b@example.com', 'seg-a@example.com']],
            ['subject' => '[2/2] Hashed.Import.Release.part02.rar yEnc (1/1)', 'segments' => ['seg-c@example.com']],
        ]));

        $this->assertSame(NzbImportStatus::Inserted, $status);
        $expected = sha1("seg-a@example.com\nseg-b@example.com\nseg-c@example.com", true);
        $this->assertSame($expected, DB::table('releases')->value('collectionhash'));
        $this->assertSame(100.0, (float) DB::table('releases')->value('completion'));
        $this->assertSame(2, DB::table('releases')->value('declaredfiles'));
        $this->assertNull(DB::table('releases')->value('firstarticle'));
        $this->assertNull(DB::table('releases')->value('lastarticle'));
    }

    public function test_import_measures_partial_nzb_without_nfo_processing(): void
    {
        $status = $this->scan($this->makeNzb([
            ['subject' => '[1/2] Partial.Import.Release.part01.rar yEnc (1/4)', 'segments' => ['part1-1@example.com', 'part1-2@example.com']],
            ['subject' => '[2/2] Partial.Import.Release.part02.rar yEnc (1/4)', 'segments' => ['part2-1@example.com', 'part2-2@example.com', 'part2-3@example.com', 'part2-4@example.com']],
        ]));

        $this->assertSame(NzbImportStatus::Inserted, $status);
        $this->assertSame(75.0, (float) DB::table('releases')->value('completion'));
        $this->assertSame(2, DB::table('releases')->value('declaredfiles'));
    }

    public function test_import_without_declared_totals_keeps_the_never_measured_sentinel(): void
    {
        $status = $this->scan($this->makeNzb([
            ['subject' => 'Unmeasurable.Import.Release', 'segments' => ['unknown-total@example.com']],
        ]));

        $this->assertSame(NzbImportStatus::Inserted, $status);
        $this->assertSame(0.0, (float) DB::table('releases')->value('completion'));
        $this->assertSame(0, DB::table('releases')->value('declaredfiles'));
    }

    public function test_import_without_article_anchors_resolves_its_rescan_window_from_postdate(): void
    {
        $status = $this->scan($this->makeNzb([
            ['subject' => '[1/2] Rescan.Import.Release.part01.rar yEnc (1/2)', 'segments' => ['rescan-1@example.com']],
        ]));

        $this->assertSame(NzbImportStatus::Inserted, $status);

        $binaries = new class(new BinariesConfig(echoCli: false)) extends BinariesService
        {
            /** @var list<int> */
            public array $requestedTimestamps = [];

            public function articleForTimestamp(int $goalTime, array $data): string
            {
                $this->requestedTimestamps[] = $goalTime;

                return count($this->requestedTimestamps) === 1 ? '200' : '400';
            }
        };
        $release = Release::query()->firstOrFail();
        $group = UsenetGroup::query()->firstOrFail();
        $window = (new RescanWindowResolver($binaries))->resolve(
            $release,
            $group,
            ['first' => 1, 'last' => 1000, 'group' => 'alt.test'],
            60,
        );

        $this->assertNotNull($window);
        $this->assertFalse($window->anchored);
        $this->assertSame(200, $window->first);
        $this->assertSame(400, $window->last);
        $postdate = strtotime((string) $release->postdate);
        $this->assertSame([$postdate - 3600, $postdate + 3600], $binaries->requestedTimestamps);
    }

    public function test_reimport_with_rewritten_subject_is_duplicate_via_hash(): void
    {
        $first = $this->scan($this->makeNzb([
            ['subject' => 'Original.Subject.Release', 'segments' => ['s1@example.com', 's2@example.com']],
        ]));
        $this->assertSame(NzbImportStatus::Inserted, $first);

        // Re-generated NZB for the same upload: subject rewritten (so the
        // heuristic finder cannot match on searchname) and segments listed in
        // a different order (hash must be order-invariant).
        $second = $this->scan($this->makeNzb([
            ['subject' => 'Rewritten.Different.Subject', 'segments' => ['s2@example.com', 's1@example.com']],
        ]));

        $this->assertSame(NzbImportStatus::Duplicate, $second);
        $this->assertSame(1, DB::table('releases')->count());
    }

    public function test_reimport_of_identical_nzb_is_duplicate(): void
    {
        $nzbFiles = [['subject' => 'Identical.Reimport.Release', 'segments' => ['x1@example.com', 'x2@example.com']]];

        $this->assertSame(NzbImportStatus::Inserted, $this->scan($this->makeNzb($nzbFiles)));
        $this->assertSame(NzbImportStatus::Duplicate, $this->scan($this->makeNzb($nzbFiles)));
        $this->assertSame(1, DB::table('releases')->count());
    }

    public function test_more_complete_same_name_import_is_absorbed_into_the_existing_release(): void
    {
        $partial = $this->makeNzb([
            [
                'subject' => '[1/1] Better.Repost.part01.rar yEnc (1/20)',
                'segments' => array_map(static fn (int $part): string => "old-{$part}@example.test", range(1, 19)),
            ],
        ]);
        $this->assertSame(NzbImportStatus::Inserted, $this->scan($partial));

        $anchor = Release::query()->firstOrFail();
        $anchorId = (int) $anchor->id;
        $anchorGuid = (string) $anchor->guid;
        $nzb = app(NzbService::class);
        file_put_contents($nzb->getNzbPath($anchorGuid, 0, true), gzencode((string) $partial->asXML()));

        $complete = $this->makeNzb([
            [
                'subject' => '[1/1] Better.Repost.part01.rar yEnc (1/20)',
                'segments' => array_map(static fn (int $part): string => "new-{$part}@example.test", range(1, 20)),
            ],
        ]);
        $this->assertSame(NzbImportStatus::Duplicate, $this->scan($complete));

        $stored = Release::query()->firstOrFail();
        $this->assertSame(1, Release::query()->count());
        $this->assertSame($anchorId, (int) $stored->id);
        $this->assertSame($anchorGuid, (string) $stored->guid);
        $this->assertSame(100.0, (float) $stored->completion);
        $this->assertSame(20_000, (int) $stored->size);
        $this->assertSame(1, (int) $stored->totalpart);
        $this->assertSame(1, (int) $stored->declaredfiles);
        $this->assertSame(0, (int) $stored->proc_files);

        $contents = $nzb->readNzbContents($anchorGuid);
        $this->assertIsString($contents);
        $this->assertStringContainsString('new-20@example.test', $contents);
        $this->assertStringNotContainsString('old-1@example.test', $contents);
    }

    public function test_zero_segment_nzbs_get_null_hash_and_do_not_collide(): void
    {
        $first = $this->scan($this->makeNzb([['subject' => 'Empty.Segments.One', 'segments' => []]]));
        $second = $this->scan($this->makeNzb([['subject' => 'Empty.Segments.Two', 'segments' => []]]));

        $this->assertSame(NzbImportStatus::Inserted, $first);
        $this->assertSame(NzbImportStatus::Inserted, $second);
        $this->assertSame(2, DB::table('releases')->count());
        $this->assertSame(2, DB::table('releases')->whereNull('collectionhash')->count());
    }

    public function test_distinct_nzbs_both_insert(): void
    {
        $first = $this->scan($this->makeNzb([['subject' => 'Distinct.Release.One', 'segments' => ['d1@example.com']]]));
        $second = $this->scan($this->makeNzb([['subject' => 'Distinct.Release.Two', 'segments' => ['d2@example.com']]]));

        $this->assertSame(NzbImportStatus::Inserted, $first);
        $this->assertSame(NzbImportStatus::Inserted, $second);
        $this->assertSame(2, DB::table('releases')->count());
        $this->assertSame(
            2,
            DB::table('releases')->whereNotNull('collectionhash')->distinct()->count('collectionhash')
        );
    }

    public function test_a_deferred_absorb_records_the_import_as_an_ordinary_duplicate(): void
    {
        $files = [
            ['subject' => '[1/2] Deferred.Import.Release.part01.rar yEnc (1/4)', 'segments' => ['d1-1@example.com', 'd1-2@example.com']],
            ['subject' => '[2/2] Deferred.Import.Release.part02.rar yEnc (1/4)', 'segments' => ['d2-1@example.com', 'd2-2@example.com', 'd2-3@example.com', 'd2-4@example.com']],
        ];
        $this->assertSame(NzbImportStatus::Inserted, $this->scan($this->makeNzb($files)));

        // The lagging-anchor state: the release row exists but its stored NZB
        // does not, and a better copy of the same upload arrives.
        DB::table('releases')->update(['nzbstatus' => NzbService::NZB_NONE, 'completion' => 10.0]);

        $status = $this->scan($this->makeNzb($files));

        $this->assertSame(NzbImportStatus::Duplicate, $status);
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame(
            10.0,
            (float) DB::table('releases')->value('completion'),
            'A deferred absorb must leave the anchor untouched.'
        );
    }

    public function test_a_failed_absorb_records_the_import_as_an_ordinary_duplicate_with_the_reason(): void
    {
        $files = [
            ['subject' => '[1/2] Failing.Import.Release.part01.rar yEnc (1/4)', 'segments' => ['f1-1@example.com', 'f1-2@example.com']],
            ['subject' => '[2/2] Failing.Import.Release.part02.rar yEnc (1/4)', 'segments' => ['f2-1@example.com', 'f2-2@example.com', 'f2-3@example.com', 'f2-4@example.com']],
        ];
        $this->assertSame(NzbImportStatus::Inserted, $this->scan($this->makeNzb($files)));

        // nzbstatus says NZB_ADDED but no stored file exists in this harness:
        // the absorb is attempted and fails on the missing NZB.
        DB::table('releases')->update(['completion' => 10.0]);
        Log::spy();

        $status = $this->scan($this->makeNzb($files));

        $this->assertSame(NzbImportStatus::Duplicate, $status);
        $this->assertSame(
            10.0,
            (float) DB::table('releases')->value('completion'),
            'A failed absorb must leave the anchor untouched.'
        );
        Log::shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message, array $context): bool => str_contains($message, 'absorb failed')
                && str_contains((string) ($context['reason'] ?? ''), 'No stored NZB')
        );
    }

    private function scan(\SimpleXMLElement $nzbXML): NzbImportStatus
    {
        $service = new class(['Browser' => true]) extends NzbImportService
        {
            public function scanForTest(\SimpleXMLElement $nzbXML): NzbImportStatus
            {
                $this->getAllGroups();

                return $this->scanNZBFile($nzbXML);
            }
        };

        return $service->scanForTest($nzbXML);
    }

    /**
     * @param  list<array{subject: string, segments: list<string>}>  $files
     */
    private function makeNzb(array $files): \SimpleXMLElement
    {
        $fileXml = '';
        foreach ($files as $file) {
            $segmentXml = '';
            $number = 1;
            foreach ($file['segments'] as $messageId) {
                $segmentXml .= '<segment bytes="1000" number="'.$number++.'">'
                    .htmlspecialchars($messageId, ENT_QUOTES)
                    .'</segment>';
            }
            $fileXml .= '<file poster="poster@example.com" date="1700000000" subject="'
                .htmlspecialchars($file['subject'], ENT_QUOTES).'">'
                .'<groups><group>alt.test</group></groups>'
                .'<segments>'.$segmentXml.'</segments>'
                .'</file>';
        }

        $nzb = simplexml_load_string(
            '<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">'.$fileXml.'</nzb>'
        );
        $this->assertInstanceOf(\SimpleXMLElement::class, $nzb);

        return $nzb;
    }

    private function seedSettings(): void
    {
        $settings = [
            'nzbsplitlevel' => '1',
            'check_passworded_rars' => '0',
            'categorizeforeign' => '1',
            'catwebdl' => '1',
            'crossposttime' => '2',
        ];

        foreach ($settings as $name => $value) {
            DB::table('settings')->insert(['name' => $name, 'value' => $value]);
        }
    }

    private function createTables(): void
    {
        DB::statement('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT)');
        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name VARCHAR(255))');
        DB::statement('CREATE TABLE categories (
            id INTEGER PRIMARY KEY,
            title VARCHAR(255),
            parent_categories_id INTEGER NULL,
            status INTEGER DEFAULT 1
        )');
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
            is_trusted_name INTEGER DEFAULT 0,
            iscategorized INTEGER,
            predb_id INTEGER,
            source VARCHAR(255) NULL,
            collectionhash BLOB NULL
        )');
        DB::statement('CREATE UNIQUE INDEX ux_releases_collectionhash ON releases (collectionhash)');
        DB::statement('CREATE TABLE video_data (id INTEGER PRIMARY KEY, releases_id INTEGER)');
        DB::statement('CREATE TABLE audio_data (id INTEGER PRIMARY KEY, releases_id INTEGER)');
        DB::statement('CREATE TABLE release_naming_regexes (
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
        DB::statement('CREATE TABLE binaryblacklist (
            id INTEGER PRIMARY KEY,
            groupname VARCHAR(255) NULL,
            regex VARCHAR(2000),
            msgcol INTEGER DEFAULT 1,
            optype INTEGER DEFAULT 1,
            status INTEGER DEFAULT 1,
            description VARCHAR(1000) NULL,
            last_activity DATE NULL
        )');
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.test']);
        DB::table('categories')->insert(['id' => 1, 'title' => 'Misc', 'parent_categories_id' => null, 'status' => 1]);
    }
}
