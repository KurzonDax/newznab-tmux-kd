<?php

namespace Tests\Feature;

use App\Enums\NzbImportStatus;
use App\Facades\Search;
use App\Services\Nzb\NzbImportService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NzbImportSegmentHashDedupeTest extends TestCase
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

    public function test_import_persists_sorted_segment_message_id_hash(): void
    {
        $status = $this->scan($this->makeNzb([
            ['subject' => 'Hashed.Import.Release', 'segments' => ['seg-b@example.com', 'seg-a@example.com']],
            ['subject' => 'Hashed.Import.Release (2/2)', 'segments' => ['seg-c@example.com']],
        ]));

        $this->assertSame(NzbImportStatus::Inserted, $status);
        $expected = sha1("seg-a@example.com\nseg-b@example.com\nseg-c@example.com", true);
        $this->assertSame($expected, DB::table('releases')->value('collectionhash'));
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
