<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\CollectionsCleaningService;
use Database\Seeders\CollectionRegexesTableSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
