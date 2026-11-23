<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\CollectionsCleaningService;
use Database\Seeders\CollectionRegexesTableSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\MultimediaMediaParityTestCase;
use Tests\Support\NumberedMkvArchiveTestCase;
use Tests\TestCase;

class CollectionsCleaningSeededRegexTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();

        $this->registerSqliteFunction(
            'REGEXP',
            static function (?string $pattern, ?string $subject): int {
                if ($pattern === null || $pattern === '' || $subject === null) {
                    return 0;
                }

                return @preg_match('/'.str_replace('/', '\\/', $pattern).'/i', $subject) === 1 ? 1 : 0;
            },
            2,
        );

        Schema::create('collection_regexes', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('group_regex');
            $table->text('regex');
            $table->boolean('status')->default(true);
            $table->string('description', 1000)->default('');
            $table->integer('ordinal')->default(0);
        });

        $this->seed(CollectionRegexesTableSeeder::class);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('collection_regexes');

        parent::tearDown();
    }

    public function test_numbered_mkv_archives_and_parity_share_the_exact_stock_base(): void
    {
        $cleaner = new CollectionsCleaningService;
        foreach (['Example.mkv.par2', 'Example.mkv.part01.rar', 'Example.mkv.part02.rar',
            'Example.mkv.vol000+001.par2', 'Example.mkv.vol001+002.par2'] as $index => $filename) {
            $subject = sprintf('[%02d/05] - "%s" yEnc', $index + 1, $filename);
            $this->assertSame(['id' => 113, 'name' => 'Example'],
                $cleaner->collectionsCleaner($subject, 'alt.binaries.boneless'), $subject);
        }
    }

    public function test_previous_numbered_mkv_split_is_corrected_by_migration(): void
    {
        DB::table('collection_regexes')->where('id', 113)->update(['regex' => NumberedMkvArchiveTestCase::PREVIOUS]);
        $cleaner = new CollectionsCleaningService;
        $subjects = [
            '[01/05] - "Example.mkv.par2" yEnc',
            '[02/05] - "Example.mkv.part01.rar" yEnc',
            '[03/05] - "Example.mkv.part02.rar" yEnc',
            '[04/05] - "Example.mkv.vol000+001.par2" yEnc',
            '[05/05] - "Example.mkv.vol001+002.par2" yEnc',
        ];
        foreach ($subjects as $index => $subject) {
            $this->assertSame(['id' => 113, 'name' => in_array($index, [1, 2], true) ? 'Example.mkv' : 'Example'],
                $cleaner->collectionsCleaner($subject, 'alt.binaries.boneless'));
        }

        (require database_path('migrations/2026_09_13_120000_fix_numbered_mkv_archive_collection_regex.php'))->up();
        foreach ($subjects as $subject) {
            $this->assertSame(['id' => 113, 'name' => 'Example'],
                $cleaner->collectionsCleaner($subject, 'alt.binaries.boneless'));
        }
    }

    public function test_numbered_mkv_suffixes_preserve_literal_stems_and_strip_only_the_terminal_suffix(): void
    {
        $cleaner = new CollectionsCleaningService;
        foreach (['Example', 'Another.Example', 'Example.mkv.Title', 'Episode.S01E02', 'Résumé.2026',
            'Example.mkv.part01.rar'] as $stem) {
            foreach (['.mkv.part1.rar', '.mkv.part01.rar', '.mkv.part123456.rar', '.MKV.PART02.RAR',
                '.mKv.pArT003.rAr', '.mkv.par2', '.mkv.vol000+001.par2'] as $suffix) {
                $subject = '[02/05] - "'.$stem.$suffix.'" yEnc';
                $this->assertSame(['id' => 113, 'name' => $stem],
                    $cleaner->collectionsCleaner($subject, 'alt.binaries.boneless'), $subject);
            }
        }
    }

    public function test_numbered_mkv_correction_preserves_excluded_filenames_and_neighboring_rule_ownership(): void
    {
        $updated = DB::table('collection_regexes')->where('id', 113)->value('regex');
        $cases = [
            '[02/05] - "Example.bin.part01.rar" yEnc',
            '[02/05] - "Example.mp4.part01.rar" yEnc',
            '[02/05] - "Example.mkv.part.rar" yEnc',
            '[02/05] - "Example.mkv.partx.rar" yEnc',
            '[02/05] - "Example.mkv.r00" yEnc',
            '[02/05] - "Example.mkv.7z.001" yEnc',
            '[02/05] - "Example.mkv.part01.rar.par2" yEnc',
            '[02/05] - "Example.mkv.part01.rar.vol000+001.par2" yEnc',
            '[02/05] - "Example.part01.rar" yEnc',
            '[03/12] - "01-example-show.mp3" yEnc',
            '[04/12] - "02-example-show.mp3" yEnc',
        ];
        foreach (['bin', 'mkv', 'tar.zst'] as $extension) {
            foreach (['', '.par2', '.vol000+001.par2'] as $suffix) {
                $cases[] = '[02/05] - "Example.'.$extension.$suffix.'" yEnc';
            }
        }
        $neighbors = [
            96 => '4Etmo7uBeuTW[047/106] - "006dEbPcea29U6K.part046.rar" yEnc',
            103 => '1VSXrAZPD - [123/177] - "1VSXrAZPD.part122.rar" yEnc',
            107 => '82561089-n [25/33] - "p4cvu9gj503a1dui6c113v9nq1ejkqrd9u4eo998o0d5acajvog56" yEnc',
            108 => 'P2H - "AMHZQHPHDUZZJSFZ.vol181+33.par2" yEnc',
            111 => '"Example.tar.zst.par2" yEnc',
        ];
        $cases = [...$cases, ...array_values($neighbors)];
        DB::table('collection_regexes')->where('id', 113)->update(['regex' => NumberedMkvArchiveTestCase::PREVIOUS]);
        $previousCleaner = new CollectionsCleaningService;
        $previous = array_map(static fn (string $subject): array => $previousCleaner->collectionsCleaner($subject, 'alt.binaries.boneless'), $cases);
        DB::table('collection_regexes')->where('id', 113)->update(['regex' => $updated]);
        Cache::flush();
        $cleaner = new CollectionsCleaningService;
        foreach ($cases as $index => $subject) {
            $this->assertSame($previous[$index], $cleaner->collectionsCleaner($subject, 'alt.binaries.boneless'), $subject);
        }
        foreach ($neighbors as $id => $subject) {
            $this->assertSame($id, $cleaner->collectionsCleaner($subject, 'alt.binaries.boneless')['id']);
        }
    }

    public function test_numbered_payloads_and_their_par2_sidecars_share_one_stock_base(): void
    {
        $cleaner = new CollectionsCleaningService;
        foreach (['bin', 'mkv'] as $extension) {
            foreach (['', '.par2', '.vol000+001.par2'] as $index => $suffix) {
                $result = $cleaner->collectionsCleaner(
                    sprintf('[%02d/03] - "Example.%s%s" yEnc', $index + 1, $extension, $suffix),
                    'alt.binaries.boneless',
                );
                $this->assertSame(113, $result['id']);
                $this->assertSame('Example', $result['name']);
            }
        }
        $this->assertNotSame(
            $cleaner->collectionsCleaner('[03/12] - "01-example-show.mp3" yEnc', 'alt.binaries.boneless')['name'],
            $cleaner->collectionsCleaner('[04/12] - "02-example-show.mp3" yEnc', 'alt.binaries.boneless')['name'],
        );
    }

    public function test_seeded_named_set_regexes_reduce_declared_files_and_bare_par2_to_one_base(): void
    {
        $cleaner = new CollectionsCleaningService;
        $declaredSubjects = [
            '[1/4] - "1787977202_nicovideo_jp_watch_sm23010895.tar.zst" yEnc',
            '[2/4] - "1787977202_nicovideo_jp_watch_sm23010895.tar.zst.vol00+01.par2" yEnc',
            '[3/4] - "1787977202_nicovideo_jp_watch_sm23010895.tar.zst.vol01+02.par2" yEnc',
        ];

        $declaredResults = array_map(
            static fn (string $subject): array => $cleaner->collectionsCleaner($subject, 'alt.binaries.boneless'),
            $declaredSubjects,
        );
        $barePar2 = $cleaner->collectionsCleaner(
            '"1787977202_nicovideo_jp_watch_sm23010895.tar.zst.par2" yEnc',
            'alt.binaries.boneless',
        );
        $differentSet = $cleaner->collectionsCleaner(
            '[1/6] - "1787977190_nicovideo_jp_watch_sm23010845.tar.zst" yEnc',
            'alt.binaries.boneless',
        );

        $this->assertSame([113], array_values(array_unique(array_column($declaredResults, 'id'))));
        $this->assertCount(1, array_unique(array_column($declaredResults, 'name')));
        $this->assertSame($declaredResults[0]['name'], $barePar2['name']);
        $this->assertSame(111, $barePar2['id']);
        $this->assertNotSame($declaredResults[0]['name'], $differentSet['name']);
    }

    public function test_seeded_prefix_regex_groups_varying_filenames_by_prefix_and_declared_total(): void
    {
        $cleaner = new CollectionsCleaningService;
        $first = $cleaner->collectionsCleaner(
            '82561089-n [25/33] - "p4cvu9gj503a1dui6c113v9nq1ejkqrd9u4eo998o0d5acajvog56" yEnc',
            'alt.binaries.boneless',
        );
        $second = $cleaner->collectionsCleaner(
            '82561089-n [26/33] - "pvaoiqjnuslgs5kr1iqo18lu3ck14ac7a4ifrhgd3c35jplj0b54g" yEnc',
            'alt.binaries.boneless',
        );
        $differentPrefix = $cleaner->collectionsCleaner(
            '82426524-n [02/20] - "2du6cclgmi8rnh1tdv6js256831d7rshfm35aba4t3lqe1un5f63n09pq92nn0vlgm9s13j6bv30ea3" yEnc',
            'alt.binaries.boneless',
        );

        $this->assertSame(107, $first['id']);
        $this->assertSame('82561089-n/33', $first['name']);
        $this->assertSame($first['name'], $second['name']);
        $this->assertNotSame($first['name'], $differentPrefix['name']);
    }

    public function test_neighboring_prefix_style_rows_keep_ownership(): void
    {
        $cleaner = new CollectionsCleaningService;
        $subjectsByExpectedRegex = [
            96 => '4Etmo7uBeuTW[047/106] - "006dEbPcea29U6K.part046.rar" yEnc',
            103 => '1VSXrAZPD - [123/177] - "1VSXrAZPD.part122.rar" yEnc',
            108 => 'P2H - "AMHZQHPHDUZZJSFZ.vol181+33.par2" yEnc',
        ];

        foreach ($subjectsByExpectedRegex as $expectedRegex => $subject) {
            $result = $cleaner->collectionsCleaner($subject, 'alt.binaries.boneless');

            $this->assertSame($expectedRegex, $result['id'], $subject);
        }
    }

    public function test_classic_seeded_multi_file_subject_keeps_grouping(): void
    {
        $cleaner = new CollectionsCleaningService;
        $first = $cleaner->collectionsCleaner(
            '[02/80] - "The.West.Wing.S06E02.1080p.BluRay.Remux.DTS-HD.MA.2.0.H.264-BTN.mkv.part01.rar" yEnc',
            'alt.binaries.boneless',
        );
        $second = $cleaner->collectionsCleaner(
            '[03/80] - "The.West.Wing.S06E02.1080p.BluRay.Remux.DTS-HD.MA.2.0.H.264-BTN.mkv.part02.rar" yEnc',
            'alt.binaries.boneless',
        );

        $this->assertSame(113, $first['id']);
        $this->assertSame($first['name'], $second['name']);
    }

    public function test_migration_updates_only_the_three_targeted_seeded_rows_and_rolls_back(): void
    {
        $targetIds = [107, 111, 113];
        $updatedRegexes = DB::table('collection_regexes')
            ->whereIn('id', $targetIds)
            ->orderBy('id')
            ->pluck('regex', 'id')
            ->all();

        $updatedRegexes[113] = '/^\\[\\d+\\/\\d+\\] - "(?P<match0>.+?)(?:\\.tar\\.zst(?:\\.vol\\d+\\+\\d+\\.par2|\\.par2)?|([\-_](proof|sample|thumbs?))*(\\.part\\d*(\\.rar)?|\\.rar|\\.7z)?(?:\\d{1,3}\\.rev|\\.vol\\d+\\+\\d+\\.par2|\\.[A-Za-z0-9]{2,4})?)"[\-_\\s]{0,3}yEnc$/ui';

        foreach ($this->legacyRegexes() as $id => $regex) {
            DB::table('collection_regexes')->where('id', $id)->update(['regex' => $regex]);
        }

        $before = DB::table('collection_regexes')->orderBy('id')->pluck('regex', 'id')->all();
        $migration = require database_path(
            'migrations/2026_08_29_213637_update_boneless_collection_regexes_for_consistent_grouping.php'
        );

        $migration->up();

        $after = DB::table('collection_regexes')->orderBy('id')->pluck('regex', 'id')->all();
        $changedIds = array_keys(array_filter(
            $after,
            static fn (string $regex, int $id): bool => $regex !== $before[$id],
            ARRAY_FILTER_USE_BOTH,
        ));

        $this->assertSame($targetIds, $changedIds);
        $this->assertSame($updatedRegexes, array_intersect_key($after, array_flip($targetIds)));

        $migration->down();

        $this->assertSame($before, DB::table('collection_regexes')->orderBy('id')->pluck('regex', 'id')->all());
    }

    public function test_payload_upgrade_invalidates_live_caches_and_preserves_custom_definitions(): void
    {
        $previous = '/^\\[\\d+\\/\\d+\\] - "(?P<match0>.+?)(?:\\.tar\\.zst(?:\\.vol\\d+\\+\\d+\\.par2|\\.par2)?|([\-_](proof|sample|thumbs?))*(\\.part\\d*(\\.rar)?|\\.rar|\\.7z)?(?:\\d{1,3}\\.rev|\\.vol\\d+\\+\\d+\\.par2|\\.[A-Za-z0-9]{2,4})?)"[\-_\\s]{0,3}yEnc$/ui';
        DB::table('collection_regexes')->where('id', 113)->update(['regex' => $previous]);
        $cleaner = new CollectionsCleaningService;
        $subject = '[02/03] - "Example.bin.par2" yEnc';
        $this->assertSame('Example.bin', $cleaner->collectionsCleaner($subject, 'alt.binaries.boneless')['name']);
        $migration = require database_path('migrations/2026_09_08_121212_fix_numbered_payload_par2_collection_regex.php');
        $migration->up();
        $this->assertSame('Example', $cleaner->collectionsCleaner($subject, 'alt.binaries.boneless')['name']);
        $migration->down();
        $this->assertSame('Example.bin', $cleaner->collectionsCleaner($subject, 'alt.binaries.boneless')['name']);
        $custom = '/^custom (?P<match0>.+)$/';
        DB::table('collection_regexes')->where('id', 113)->update(['regex' => $custom]);
        $migration->up();
        $this->assertSame($custom, DB::table('collection_regexes')->where('id', 113)->value('regex'));
        $migration->down();
        $this->assertSame($custom, DB::table('collection_regexes')->where('id', 113)->value('regex'));
    }

    /** @return array<string, array{string, string}> filename => [before, after] */
    private static function multimediaMediaParityNames(): array
    {
        return [
            'Example.S01E07.mp4' => ['Example.S01E07', 'Example.S01E07'],
            'Example.S01E07.nfo' => ['Example.S01E07', 'Example.S01E07'],
            'Example.S01E07.mp4.par2' => ['Example.S01E07.mp4', 'Example.S01E07'],
            'Example.S01E07.mp4.vol015+16.par2' => ['Example.S01E07.mp4', 'Example.S01E07'],
            'Example.S01E07.MKV.PAR2' => ['Example.S01E07.MKV', 'Example.S01E07'],
            'Example.S01E07.vob.vol07+7.PAR2' => ['Example.S01E07.vob', 'Example.S01E07'],
            'Example.S01E07.avi.r00' => ['Example.S01E07.avi', 'Example.S01E07'],
            'Example.S01E07.avi.r000' => ['Example.S01E07.avi', 'Example.S01E07'],
            'Example.S01E07.avi.part01.rar' => ['Example.S01E07.avi', 'Example.S01E07'],
            'Example.S01E07.avi.rar' => ['Example.S01E07.avi', 'Example.S01E07'],
            'Example.S01E07.avi.sfv' => ['Example.S01E07.avi', 'Example.S01E07'],
            'Example.S01E07.avi.srr' => ['Example.S01E07.avi', 'Example.S01E07'],
            'Example-sample.avi.vol1+2.PAR2' => ['Example-sample.avi', 'Example-sample'],
            'Example.720p.x264.par2' => ['Example.720p.x264', 'Example.720p.x264'],
            'Example.720p.x264.rar' => ['Example.720p.x264', 'Example.720p.x264'],
            'Example.par2' => ['Example', 'Example'],
            'Example.vol00+01.par2' => ['Example', 'Example'],
            'Example.part01.rar' => ['Example', 'Example'],
            'Example.r00' => ['Example', 'Example'],
            'Example 1x24.part.vol15+16.par2' => ['Example 1x24', 'Example 1x24'],
            'Example.mkv.7z.001' => ['Example.mkv.7z', 'Example.mkv.7z'],
            'Example.mp4.Extras.par2' => ['Example.mp4.Extras', 'Example.mp4.Extras'],
            'Example.wmv.par2' => ['Example.wmv', 'Example.wmv'],
            'Example.m4v.par2' => ['Example.m4v', 'Example.m4v'],
        ];
    }

    private function assertMultimediaMediaParityNames(CollectionsCleaningService $cleaner, bool $migrated): void
    {
        foreach (self::multimediaMediaParityNames() as $filename => $names) {
            $subject = '[01/10] - "'.$filename.'" yEnc';
            $this->assertSame(['id' => 461, 'name' => $names[$migrated ? 1 : 0]],
                $cleaner->collectionsCleaner($subject, 'alt.binaries.multimedia'), $subject);
        }
    }

    public function test_seeded_multimedia_rule_groups_media_named_parity_with_its_video(): void
    {
        $this->assertMultimediaMediaParityNames(new CollectionsCleaningService, true);
    }

    public function test_previous_multimedia_media_parity_split_is_corrected_by_migration(): void
    {
        DB::table('collection_regexes')->where('id', 461)->update(['regex' => MultimediaMediaParityTestCase::PREVIOUS]);
        $cleaner = new CollectionsCleaningService;
        $this->assertMultimediaMediaParityNames($cleaner, false);

        (require database_path('migrations/2026_11_21_120000_group_media_named_par2_in_multimedia_collection_regex.php'))->up();
        $this->assertMultimediaMediaParityNames($cleaner, true);
    }

    public function test_multimedia_media_parity_correction_preserves_neighboring_rule_ownership(): void
    {
        $neighbors = [
            460 => 'Example.S01E07 - [01/05] - "Example.S01E07.mp4" yEnc',
            462 => 'homeland.s02e12.1080p.bluray-bia.r08 - [011/111]  yEnc',
            463 => '(TOWN)(www.town.ag ) (partner of www.ssl-news.info ) Twinz-Conversation-CD-FLAC-1995-CUSTODES  [01/23] - #34;Twinz-Conversation-CD-FLAC-1995-CUSTODES.par2#34; - 266,00 MB - yEnc',
        ];
        $unmatched = '[01/05] - "Example.mp4"yEnc';
        DB::table('collection_regexes')->where('id', 461)->update(['regex' => MultimediaMediaParityTestCase::PREVIOUS]);
        $cleaner = new CollectionsCleaningService;
        foreach ($neighbors as $id => $subject) {
            $this->assertSame($id, $cleaner->collectionsCleaner($subject, 'alt.binaries.multimedia')['id'], $subject);
        }
        $previous = $cleaner->collectionsCleaner($unmatched, 'alt.binaries.multimedia');
        $this->assertNotSame(461, $previous['id']);

        (require database_path('migrations/2026_11_21_120000_group_media_named_par2_in_multimedia_collection_regex.php'))->up();
        foreach ($neighbors as $id => $subject) {
            $this->assertSame($id, $cleaner->collectionsCleaner($subject, 'alt.binaries.multimedia')['id'], $subject);
        }
        $this->assertSame($previous, $cleaner->collectionsCleaner($unmatched, 'alt.binaries.multimedia'));
    }

    /**
     * @return array<int, string>
     */
    private function legacyRegexes(): array
    {
        return [
            107 => '/^[-a-zA-Z0-9 ]+ \\[\\d+(?P<match0>\\/\\d+\\] - "(.+?))([\-_](proof|sample|thumbs?))*(\\.part\\d*(\\.rar)?|\\.rar|\\.7z)?(\\d{1,3}\\.rev"|\\.vol\\d+\\+\\d+\\.par2"|\\.[A-Za-z0-9]{2,4}"|")[\-_\\s]{0,3}yEnc$/ui',
            111 => '/^"(?P<match0>.+?)([\-_](proof|sample|thumbs?))*(\\.part\\d*(\\.rar)?|\\.rar|\\.7z)?(\\d{1,3}\\.rev"|\\.vol\\d+\\+\\d+\\.par2"|\\.[A-Za-z0-9]{2,4}"|")[\-_\\s]{0,3}yEnc$/ui',
            113 => '/^\\[\\d+(?P<match0>\\/\\d+\\] - "(.+?))([\-_](proof|sample|thumbs?))*(\\.part\\d*(\\.rar)?|\\.rar|\\.7z)?(\\d{1,3}\\.rev"|\\.vol\\d+\\+\\d+\\.par2"|\\.[A-Za-z0-9]{2,4}"|")[\-_\\s]{0,3}yEnc$/ui',
        ];
    }
}
