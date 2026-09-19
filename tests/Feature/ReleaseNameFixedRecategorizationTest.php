<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Listeners\RecategorizeReleaseAfterNameFix;
use App\Models\Category;
use App\Models\Predb;
use App\Models\Release;
use App\Models\UsenetGroup;
use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\AdditionalProcessing\Config\PasswordInspectionMode;
use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\ReleaseFileManager;
use App\Services\AdditionalProcessing\ReleaseProcessor;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Services\Categorization\CategorizationService;
use App\Services\Categorization\MediaInfoRefinementService;
use App\Services\NameFixing\DowngradedNameRestorer;
use App\Services\NameFixing\FileNameCleaner;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\NNTP\DTO\ArticleDownloadResult;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use App\Services\Releases\PreviewGenerationPolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ObfuscationRecovery\SyntheticPosting;
use Tests\Support\ProductionTables;
use Tests\TestCase;
use Tests\Unit\AdditionalProcessing\CreatesProcessingConfiguration;
use ZipArchive;

class ReleaseNameFixedRecategorizationTest extends TestCase
{
    use CreatesProcessingConfiguration;
    use IsolatedSqliteDatabase;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return ['categorizeforeign' => '0', 'catwebdl' => '1'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        DB::table('settings')->upsert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '1'],
        ], ['name'], ['value']);

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    #[DataProvider('parenthesizedEpisodeNames')]
    public function test_parenthesized_episode_names_reach_tv_other_through_categorization(string $name): void
    {
        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.wtfnzb.golf',
            'active' => 1,
            'backfill' => 0,
        ]);

        $result = app(CategorizationService::class)->determineCategory($group->id, $name);

        $this->assertSame(Category::TV_OTHER, $result['categories_id']);

        $unparenthesized = str_replace(['(', ')'], '', $name);
        $this->assertSame(
            $result['categories_id'],
            app(CategorizationService::class)->determineCategory($group->id, $unparenthesized)['categories_id'],
        );
    }

    public function test_parenthesized_episode_rename_refines_to_tv_x265_from_existing_media_info(): void
    {
        $synchronizedCategories = [];
        Search::shouldReceive('updateRelease')->andReturnUsing(function (int $releaseId) use (&$synchronizedCategories): bool {
            $synchronizedCategories[] = (int) Release::query()->findOrFail($releaseId)->categories_id;

            return true;
        });
        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.wtfnzb.golf',
            'active' => 1,
            'backfill' => 0,
        ]);
        $release = Release::factory()->create([
            'name' => '5da7b5393d4f4445ac4db1ee8e95f567',
            'searchname' => '5da7b5393d4f4445ac4db1ee8e95f567',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'iscategorized' => 1,
            'isrenamed' => 0,
            'guid' => str_repeat('b', 40),
            'leftguid' => 'b',
        ]);
        DB::table('video_data')->insert([
            'releases_id' => $release->id,
            'containerformat' => 'Matroska',
            'videoformat' => 'HEVC',
            'videocodec' => 'V_MPEGH/ISO/HEVC',
            'videowidth' => 1920,
            'videoheight' => 1080,
        ]);
        $name = 'Brickleberry - Obamascare (S03E01)';

        app(ReleaseUpdateService::class)->updateRelease(
            $release->fresh(), $name, 'MediaInfo: Movie Name', true, 'Mediainfo, ', true, false,
        );

        $release->refresh();
        $this->assertSame($name, $release->searchname);
        $this->assertSame(Category::TV_X265, (int) $release->categories_id);
        $this->assertSame(1, (int) $release->isrenamed);
        $this->assertSame(1, (int) $release->proc_media_movie);
        $this->assertNotEmpty($synchronizedCategories);
        $this->assertSame(Category::TV_X265, end($synchronizedCategories));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function parenthesizedEpisodeNames(): array
    {
        return [
            'episode 1' => ['Brickleberry - Obamascare (S03E01)'],
            'episode 2' => ['Brickleberry - In Da Club (S03E02)'],
            'episode 3' => ['Brickleberry - Miss National Park (S03E03)'],
            'episode 4' => ["Brickleberry - That Brother's My Father (S03E04)"],
            'episode 5' => ["Brickleberry - Write 'Em Cowboy (S03E05)"],
            'episode 6' => ['Brickleberry - Old Wounds (S03E06)'],
            'episode 7' => ['Brickleberry - Baby Daddy (S03E07)'],
        ];
    }

    public function test_renaming_hashed_release_recategorizes_it_synchronously(): void
    {
        Search::shouldReceive('updateRelease')->twice();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.hdtv',
            'active' => 1,
            'backfill' => 0,
        ]);

        $release = Release::factory()->create([
            'name' => 'd41d8cd98f00b204e9800998ecf8427e',
            'searchname' => 'd41d8cd98f00b204e9800998ecf8427e',
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'iscategorized' => 1,
            'isrenamed' => 0,
            'guid' => str_repeat('a', 40),
            'leftguid' => 'a',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        $service = app(ReleaseUpdateService::class);
        $service->updateRelease(
            $release->fresh(),
            'Show.Name.S03E05.720p.HDTV.x264-GROUP',
            'nfoCheck: Title Match',
            true,
            'NFO, ',
            true,
            false,
        );

        $release->refresh();

        $this->assertSame('Show.Name.S03E05.720p.HDTV.x264-GROUP', $release->searchname);
        $this->assertSame(Category::TV_HD, $release->categories_id);
        $this->assertSame(1, (int) $release->iscategorized);
        $this->assertSame(1, (int) $release->isrenamed);
    }

    public function test_descriptive_rename_recovers_a_misc_release_with_bracketed_movie_formats(): void
    {
        Http::preventStrayRequests();
        Search::shouldReceive('updateRelease')->twice();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.boneless',
            'active' => 1,
            'backfill' => 0,
        ]);
        $release = Release::factory()->create([
            'name' => '5da7b5393d4f4445ac4db1ee8e95f567',
            'searchname' => '5da7b5393d4f4445ac4db1ee8e95f567',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_MISC,
            'iscategorized' => 1,
            'isrenamed' => 0,
            'guid' => str_repeat('d', 40),
            'leftguid' => 'd',
        ]);
        $name = 'Example.Feature.(1986).{tmdb-123456}.-.[DVD][AC3.2.0][XviD]-GROUP';

        app(ReleaseUpdateService::class)->updateRelease(
            $release->fresh(),
            $name,
            'fileCheck: Descriptive title',
            true,
            'Filenames, ',
            true,
            false,
            descriptiveTitleCandidate: true,
        );

        $release->refresh();

        $this->assertSame($name, $release->searchname);
        $this->assertSame(Category::MOVIE_SD, (int) $release->categories_id);
        $this->assertSame(1, (int) $release->iscategorized);
        $this->assertSame(1, (int) $release->isrenamed);
    }

    public function test_xxx_filename_rename_consumes_only_the_xxx_source(): void
    {
        Search::shouldReceive('updateRelease')->twice();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.xxx',
            'active' => 1,
            'backfill' => 0,
        ]);
        $release = Release::factory()->create([
            'name' => 'd41d8cd98f00b204e9800998ecf8427e',
            'searchname' => 'd41d8cd98f00b204e9800998ecf8427e',
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'iscategorized' => 1,
            'isrenamed' => 0,
            'guid' => str_repeat('x', 40),
            'leftguid' => 'x',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        app(ReleaseUpdateService::class)->updateRelease(
            $release->fresh(),
            'XXX.Release.2026.1080p-GROUP',
            'fileCheck: XXX SDPORN',
            true,
            'XXX filenames, ',
            true,
            false,
        );

        $release->refresh();

        $this->assertSame(1, (int) $release->proc_xxx);
        $this->assertSame(0, (int) $release->proc_files);
    }

    public function test_media_movie_rename_consumes_only_the_media_movie_source(): void
    {
        Search::shouldReceive('updateRelease')->twice();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.movies',
            'active' => 1,
            'backfill' => 0,
        ]);
        $release = Release::factory()->create([
            'name' => 'd41d8cd98f00b204e9800998ecf8427e',
            'searchname' => 'd41d8cd98f00b204e9800998ecf8427e',
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'iscategorized' => 1,
            'isrenamed' => 0,
            'guid' => str_repeat('m', 40),
            'leftguid' => 'm',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        app(ReleaseUpdateService::class)->updateRelease(
            $release->fresh(),
            'Movie.Name.2026.1080p-GROUP',
            'MediaInfo: Movie Name',
            true,
            'Mediainfo, ',
            true,
            false,
        );

        $release->refresh();

        $this->assertSame(1, (int) $release->proc_media_movie);
        $this->assertSame(0, (int) $release->proc_uid);
    }

    public function test_renaming_a_policy_skipped_release_flips_it_back_to_pending(): void
    {
        // Two syncs from the rename path plus one from the owed-preview flip.
        Search::shouldReceive('updateRelease')->times(3);

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.hdtv',
            'active' => 1,
            'backfill' => 0,
        ]);

        $release = Release::factory()->create([
            'name' => 'd41d8cd98f00b204e9800998ecf8427e',
            'searchname' => 'd41d8cd98f00b204e9800998ecf8427e',
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'iscategorized' => 1,
            'isrenamed' => 0,
            'guid' => str_repeat('b', 40),
            'leftguid' => 'b',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
            'haspreview' => -2,
            'passwordstatus' => 0,
        ]);

        app(ReleaseUpdateService::class)->updateRelease(
            $release->fresh(),
            'Show.Name.S03E05.720p.HDTV.x264-GROUP',
            'nfoCheck: Title Match',
            true,
            'NFO, ',
            true,
            false,
        );

        $release->refresh();

        $this->assertSame(Category::TV_HD, $release->categories_id);
        $this->assertSame(-1, (int) $release->haspreview, 'The name-fix listener owes the release a regeneration.');
        $this->assertSame(
            PasswordInspectionMode::pendingReleaseStatus(),
            (int) $release->passwordstatus
        );
    }

    public function test_renaming_tv_episode_to_full_season_keeps_it_out_of_movies(): void
    {
        Search::shouldReceive('updateRelease')->twice();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.warcraft',
            'active' => 1,
            'backfill' => 0,
        ]);

        $oldName = 'Tale.of.the.Nine.Tailed.S02E10.2023.1080p.AMZN.WEB-DL.x264.DDP2.0-ADWeb';
        $newName = 'Tale.of.the.Nine.Tailed.S02.2023.1080p.AMZN.WEB-DL.x264.DDP2.0-ADWeb';

        $release = Release::factory()->create([
            'name' => '[1/8] - "'.$oldName.'.par2" yEnc',
            'searchname' => $oldName,
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::TV_WEBDL,
            'iscategorized' => 1,
            'isrenamed' => 0,
            'guid' => str_repeat('e', 40),
            'leftguid' => 'e',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        app(ReleaseUpdateService::class)->updateRelease(
            $release->fresh(),
            $newName,
            'Raw file: Flat scene release',
            true,
            'Filenames, ',
            true,
            false,
        );

        $release->refresh();

        $this->assertSame($newName, $release->searchname);
        $this->assertSame(Category::TV_WEBDL, $release->categories_id);
        $this->assertNotSame(Category::MOVIE_WEBDL, $release->categories_id);
        $this->assertSame(1, (int) $release->iscategorized);
        $this->assertSame(1, (int) $release->isrenamed);
    }

    public function test_prettifying_a_tv_title_preserves_hd_evidence_during_recategorization(): void
    {
        Search::shouldReceive('updateRelease')->twice();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.hdtv',
            'active' => 1,
            'backfill' => 0,
        ]);
        $release = Release::factory()->create([
            'name' => '[1/25] - "True.Evil.the.Making.of.a.Nazi.S01E02.Werner.Von.Braun.1080p.par2" yEnc',
            'searchname' => 'True.Evil.the.Making.of.a.Nazi.S01E02.Werner.Von.Braun.1080p',
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::TV_HD,
            'iscategorized' => 1,
            'isrenamed' => 0,
            'guid' => str_repeat('u', 40),
            'leftguid' => 'u',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        app(ReleaseUpdateService::class)->updateRelease(
            $release->fresh(),
            'True Evil: The Making Of A Nazi S01E02 Werner von Braun',
            'nfoCheck: Title Match',
            true,
            'NFO, ',
            true,
            false,
        );

        $release->refresh();

        $this->assertStringEndsWith(' 1080p', $release->searchname);
        $this->assertSame(Category::TV_HD, (int) $release->categories_id);
    }

    public function test_rename_preserves_missing_evidence_from_the_original_subject(): void
    {
        Search::shouldReceive('updateRelease')->twice();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.hdtv',
            'active' => 1,
            'backfill' => 0,
        ]);
        $release = Release::factory()->create([
            'name' => '[1/25] - "True.Evil.S01E02.1080p.WEB-DL.x264.GERMAN.par2" yEnc',
            'searchname' => 'True.Evil.S01E02',
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::TV_OTHER,
            'iscategorized' => 1,
            'isrenamed' => 0,
            'guid' => str_repeat('v', 40),
            'leftguid' => 'v',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        app(ReleaseUpdateService::class)->updateRelease(
            $release->fresh(),
            'True Evil: The Making Of A Nazi S01E02 Werner von Braun',
            'nfoCheck: Title Match',
            true,
            'NFO, ',
            true,
            false,
        );

        $release->refresh();

        $this->assertStringEndsWith(' 1080p WEB-DL x264 GERMAN', $release->searchname);
        $this->assertSame(Category::TV_WEBDL, (int) $release->categories_id);
    }

    public function test_renaming_a_release_in_a_forced_root_group_keeps_it_in_that_root(): void
    {
        Search::shouldReceive('updateRelease')->twice();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.ijsklontje',
            'active' => 1,
            'backfill' => 0,
            'forced_root_categories_id' => Category::XXX_ROOT,
        ]);

        $oldName = 'a3f9c1d4e7b2085f6c1d9e4a7b3f0c28';
        $newName = 'The.Matrix.1999.1080p.BluRay.x264-GROUP';

        $release = Release::factory()->create([
            'name' => '[1/8] - "'.$oldName.'.par2" yEnc',
            'searchname' => $oldName,
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'iscategorized' => 1,
            'isrenamed' => 0,
            'guid' => str_repeat('k', 40),
            'leftguid' => 'k',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        app(ReleaseUpdateService::class)->updateRelease(
            $release->fresh(),
            $newName,
            'Raw file: Flat scene release',
            true,
            'Filenames, ',
            true,
            false,
        );

        $release->refresh();

        $this->assertSame($newName, $release->searchname);
        $this->assertSame(Category::XXX_OTHER, (int) $release->categories_id);
        $this->assertSame(Category::XXX_ROOT, Category::rootCategoryFor((int) $release->categories_id));
    }

    public function test_renaming_olympic_webdl_release_recategorizes_it_from_movie_webdl_to_tv_sport(): void
    {
        Search::shouldReceive('updateRelease')->twice();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.hdtv',
            'active' => 1,
            'backfill' => 0,
        ]);

        $oldName = 'WinterOlympics2026__NZBSPLIT__0456f274737cea074abd86a89144cc7b__NZBSPLIT__Winter_Olympic_Games_Milano_Cortina_2026_Closing_Ceremony_1080p25_WEB-DL_(MultiAudio).7z.065';
        $newName = 'Winter.Olympic.Games.Milano.Cortina.2026.Closing.Ceremony.1080p25.WEB-DL.(MultiAudio)';

        $release = Release::factory()->create([
            'name' => $oldName,
            'searchname' => $oldName,
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::MOVIE_WEBDL,
            'iscategorized' => 1,
            'isrenamed' => 0,
            'guid' => str_repeat('b', 40),
            'leftguid' => 'b',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        $service = app(ReleaseUpdateService::class);
        $service->updateRelease(
            $release->fresh(),
            $newName,
            'NZBSPLIT wrapper',
            true,
            'Filenames, ',
            true,
            false,
        );

        $release->refresh();

        $this->assertSame($newName, $release->searchname);
        $this->assertSame(Category::TV_SPORT, $release->categories_id);
        $this->assertSame(1, (int) $release->iscategorized);
        $this->assertSame(1, (int) $release->isrenamed);
    }

    public function test_renaming_space_separated_scene_title_does_not_overwrite_dotted_searchname(): void
    {
        Search::shouldReceive('updateRelease')->once();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.multimedia',
            'active' => 1,
            'backfill' => 0,
        ]);

        $oldName = 'Southern.Charm.S11E12.Even.Further.South.720p.AMZN.WEB-DL.DDP2.0.H.264-NTb';
        $release = Release::factory()->create([
            'name' => '[1/25] - "Southern.Charm.S11E12.Even.Further.South.720p.AMZN.WEB-DL.DDP2.0.H.264-NTb.par2" yEnc',
            'searchname' => $oldName,
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::TV_HD,
            'iscategorized' => 1,
            'isrenamed' => 1,
            'guid' => str_repeat('c', 40),
            'leftguid' => 'c',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        $service = app(ReleaseUpdateService::class);
        $service->updateRelease(
            $release->fresh(),
            'Southern Charm S11E12 Even Further South 720p AMZN WEB-DL DDP2 0 H 264-NTb',
            'RarInfo FileName Match',
            true,
            'Filenames, ',
            true,
            false,
        );

        $release->refresh();

        $this->assertSame($oldName, $release->searchname);
        $this->assertSame(Category::TV_HD, $release->categories_id);
        $this->assertSame(1, (int) $release->iscategorized);
        $this->assertSame(1, (int) $release->isrenamed);
    }

    /**
     * @param  list<string>  $currentFiles
     * @param  list<string>  $persistedFiles
     * @param  list<string>  $pendingFiles
     */
    #[DataProvider('rarEpisodeEvidence')]
    public function test_rar_rename_uses_episode_evidence(
        array $currentFiles,
        array $persistedFiles,
        array $pendingFiles,
        string $expected,
        bool $preDbMatch = false,
    ): void {
        Search::shouldReceive('updateRelease')->andReturn(true);
        Search::shouldReceive('searchPredb')->andReturn([]);
        config(['nntmux.echocli' => false]);
        Schema::create('release_files', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('name');
            $table->primary(['releases_id', 'name']);
        });
        $group = UsenetGroup::query()->create(['name' => 'alt.binaries.test']);
        $release = Release::factory()->create([
            'name' => '(mm02nl) [000/280]',
            'searchname' => '(mm02nl) [000/280]',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'isrenamed' => 0,
        ]);
        foreach ($persistedFiles as $fileName) {
            DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $fileName]);
        }
        $preDbId = $preDbMatch
            ? DB::table('predb')->insertGetId(['title' => $expected])
            : 0;
        $context = new ReleaseProcessingContext($release);
        $manager = app(ReleaseFileManager::class);
        foreach ($pendingFiles as $fileName) {
            $this->assertTrue($manager->addFileInfo(['name' => $fileName, 'size' => 1024], $context, '\\.(?:par2|sfv|nzb)'));
        }

        $manager->processReleaseNameFromRar([
            'file_list' => array_map(static fn (string $name): array => ['name' => $name], $currentFiles),
        ], $context);

        $release->refresh();
        $this->assertSame($expected, $release->searchname);
        $this->assertSame(1, (int) $release->proc_files);
        $this->assertSame(1, (int) $release->isrenamed);
        $this->assertSame($preDbMatch ? 1 : 0, (int) $release->is_trusted_name);
        $this->assertSame($preDbId, (int) $release->predb_id);
    }

    /**
     * @return array<string, array{list<string>, list<string>, list<string>, string, 4?: bool}>
     */
    public static function rarEpisodeEvidence(): array
    {
        $first = 'Season 2/Murdoch Mysteries S02E01 Mild, Mild West.mkv';
        $last = 'Season 2/Murdoch Mysteries S02E13 Anything You Can Do.mkv';
        $episodeTitle = 'Murdoch Mysteries S02E13 Anything You Can Do';

        return [
            'persisted forward volume' => [[$last], [$first], [], 'Murdoch Mysteries S02'],
            'queued forward volume' => [[$last], [], [$first], 'Murdoch Mysteries S02'],
            'two episodes in one volume' => [[$last, $first], [], [], 'Murdoch Mysteries S02'],
            'season episodes with matching sidecar' => [[$last, $first, 'Season 2/Murdoch Mysteries S02E01 Mild, Mild West.nfo'], [], [], 'Murdoch Mysteries S02'],
            'single episode in season directory' => [[$last], [], [], $episodeTitle],
            'same episode repeated' => [[$last], [$last], [], $episodeTitle],
            'different seasons' => [[$last], ['Murdoch Mysteries S01E01 Title.mkv'], [], $episodeTitle],
            'different shows' => [[$last], ['Other Mysteries S02E01 Title.mkv'], [], $episodeTitle],
            'non-video evidence' => [[$last], ['Murdoch Mysteries S02E01 Title.nfo'], [], $episodeTitle],
            'normalized show and numeric season' => [[$last], ['Season 2\\murdoch_mysteries s2e1 Title.MKV'], [], 'Murdoch Mysteries S02'],
            'PreDB wins over season evidence' => [[$last], [$first], [], $episodeTitle, true],
            'hidden persisted episode is not season evidence' => [[$last], ['Parent/.hidden/'.$first], [], $episodeTitle],
            'hidden queued episode is not season evidence' => [[$last], [], ['Parent/.hidden/'.$first], $episodeTitle],
        ];
    }

    /** @param array<string, mixed> $manifest */
    #[DataProvider('archiveNamingManifests')]
    public function test_archive_naming_excludes_hidden_directory_descendants_before_selecting_a_title(array $manifest, bool $rename = true): void
    {
        Search::shouldReceive('updateRelease')->andReturn(true);
        Search::shouldReceive('searchPredb')->andReturn([]);
        config(['nntmux.echocli' => false]);
        $group = UsenetGroup::query()->create(['name' => 'alt.binaries.test']);
        $release = Release::factory()->create([
            'name' => '5da7b5393d4f4445ac4db1ee8e95f567',
            'searchname' => '5da7b5393d4f4445ac4db1ee8e95f567',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'isrenamed' => 0,
        ]);
        DB::table('predb')->insert(['title' => 'Hidden.Payload.2026.2160p-GROUP']);

        Schema::create('release_files', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('name');
            $table->primary(['releases_id', 'name']);
        });
        $manager = app(ReleaseFileManager::class);
        $context = new ReleaseProcessingContext($release);
        $manager->processReleaseNameFromRar($manifest, $context);

        $release->refresh();
        $this->assertSame($rename ? 'Visible.Release.2026.1080p-GROUP' : '5da7b5393d4f4445ac4db1ee8e95f567', $release->searchname);
        $this->assertSame(0, (int) $release->predb_id);
        $this->assertSame($rename ? 1 : 0, (int) $release->is_trusted_name);
        $this->assertSame($rename ? 1 : 0, (int) $release->proc_files);
        $this->assertSame($rename ? Category::MOVIE_HD : Category::OTHER_HASHED, (int) $release->categories_id);
    }

    public function test_a_truncated_first_rar_volume_names_an_obfuscated_release(): void
    {
        Search::shouldReceive('updateRelease')->andReturn(true);
        Search::shouldReceive('searchPredb')->andReturn([]);
        config(['nntmux.echocli' => false]);
        $group = UsenetGroup::query()->create(['name' => 'alt.binaries.test']);
        $release = Release::factory()->create([
            'name' => '5da7b5393d4f4445ac4db1ee8e95f567',
            'searchname' => '5da7b5393d4f4445ac4db1ee8e95f567',
            'groups_id' => $group->id, 'categories_id' => Category::OTHER_HASHED, 'isrenamed' => 0,
        ]);
        ProductionTables::fromAuthority()->create('release_files', ['releases_id', 'name']);
        $set = SyntheticPosting::rar([400000, 400000, 100000], 'Visible.Release.2026.1080p-GROUP.mkv');
        $context = new ReleaseProcessingContext($release);
        $result = (new ArchiveExtractionService($this->makeConfig()))->processCompressedData(substr($set['volumes'][0], 0, 200000), $context, $this->makeTempDirectory('volume-naming').'/');
        $this->assertTrue($result['manifestComplete']);
        app(ReleaseFileManager::class)->processReleaseNameFromRar($result['dataSummary'], $context);
        $release->refresh();
        $this->assertSame('Visible.Release.2026.1080p-GROUP', $release->searchname);
        $this->assertSame(1, (int) $release->is_trusted_name);
        $this->assertSame(1, (int) $release->proc_files);
        $this->assertSame(Category::MOVIE_HD, (int) $release->categories_id);
    }

    /** @return array<string, array{array<string, mixed>, 1?: bool}> */
    public static function archiveNamingManifests(): array
    {
        $hidden = ['name' => '.Hidden.Payload.2026.2160p-GROUP/Hidden.Payload.2026.2160p-GROUP.mkv', 'size' => 262144];
        $visible = ['name' => 'Visible.Release.2026/Visible.Release.2026.1080p-GROUP.mkv', 'size' => 32768];
        $cases = [
            'root hidden first' => [['file_list' => [$hidden, $visible]]],
            'matching movie sidecar' => [['file_list' => [$hidden, $visible, ['name' => 'Visible.Release.2026.1080p-GROUP.nfo']]]],
            'hidden last' => [['file_list' => [$visible, $hidden]]],
            'hidden only' => [['file_list' => [$hidden]], false],
            'root dotfile policy retained' => [['file_list' => [['name' => '.Visible.Release.2026.1080p-GROUP.mkv']]], false],
            'nested dotfile policy retained' => [['file_list' => [['name' => 'Visible/.Visible.Release.2026.1080p-GROUP.mkv']]]],
            'unrelated visible titles' => [['file_list' => [$visible, ['name' => 'Other.Movie.2025.2160p-GROUP.mkv']]], false],
            'unrelated descriptive titles' => [['file_list' => [['name' => 'A Wonderful Day.mkv'], ['name' => 'A Trip To The Mountains.mkv']]], false],
            'summary error' => [['file_list' => [$visible], 'error' => 'Truncated header'], false],
            'entry error' => [['file_list' => [$visible, ['error' => 'Unreadable entry']]], false],
            'missing entries' => [['file_count' => 2, 'file_list' => [$visible]], false],
            'payload continues into the next volume' => [['use_range' => '0-999', 'file_list' => [array_merge($visible, ['next_offset' => 2000, 'split_after' => 1])]]],
            'truncated payload before later headers' => [['use_range' => '0-999', 'file_list' => [array_merge($visible, ['next_offset' => 2000])]], false],
            'directories are not titles' => [['file_list' => [['name' => 'Other.Movie.2025-GROUP', 'is_dir' => 1], $visible]]],
            'hidden archive cannot name through fallback' => [['file_list' => [['name' => 'Parent/.hidden/opaque.rar']], 'archives' => ['Parent/.hidden/opaque.rar' => ['file_list' => [$visible]]]], false],
        ];
        foreach (['Parent/', './', 'Parent/Nested/'] as $prefix) {
            $cases['hidden ancestor '.$prefix] = [['file_list' => [array_replace($hidden, ['name' => $prefix.$hidden['name']]), $visible]]];
        }
        $cases['backslash paths'] = [['file_list' => array_map(static fn (array $file): array => array_replace($file, ['name' => str_replace('/', '\\', $file['name'])]), [$hidden, $visible])]];
        $cases['leading current directory'] = [['file_list' => [$hidden, array_replace($visible, ['name' => './'.$visible['name']])]]];
        foreach (['../', '.hidden/../', '/absolute/', 'C:\\', './C:\\', './/', "invalid\x00/"] as $prefix) {
            $cases['invalid '.$prefix] = [['file_list' => [['name' => $prefix.'Hidden.Payload.2026.2160p-GROUP.mkv'], $visible]]];
        }
        $cases['nested invalid path'] = [['file_list' => [['name' => 'opaque.rar']], 'archives' => ['opaque.rar' => ['file_list' => [['name' => '/Hidden.Payload.2026.2160p-GROUP.mkv']]]]], false];
        $manyHidden = [];
        for ($i = 0; $i < 15; $i++) {
            $manyHidden[] = array_replace($hidden, ['name' => '.hidden/'.$i.'/'.$hidden['name']]);
        }
        $cases['past storage cap'] = [['file_list' => [...$manyHidden, $visible]]];

        return $cases;
    }

    #[DataProvider('generatedArchiveCases')]
    public function test_generated_archive_names_from_visible_content_and_finalizes_idempotently(int $hiddenCount, bool $encrypted, int $priorPassword): void
    {
        config(['nntmux.echocli' => false]);
        Search::shouldReceive('searchPredb')->andReturn([]);
        $synchronized = [];
        Search::shouldReceive('updateRelease')->andReturnUsing(function (int $id) use (&$synchronized): bool {
            $synchronized[] = Release::query()->findOrFail($id)->searchname;

            return true;
        });
        Schema::table('releases', function (Blueprint $table): void {
            $table->timestamp('additional_pp_claimed_at')->nullable();
            $table->string('additional_pp_claim_token')->nullable();
            $table->integer('rarinnerfilecount')->default(0);
            $table->integer('jpgstatus')->default(0);
            $table->integer('videostatus')->default(0);
            $table->integer('nfostatus')->default(1);
        });
        Schema::create('categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('root_categories_id');
        });
        DB::table('categories')->insert([
            ['id' => Category::OTHER_HASHED, 'root_categories_id' => 1],
            ['id' => Category::MOVIE_HD, 'root_categories_id' => 2000],
        ]);
        Schema::create('release_files', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('name');
            $table->unsignedBigInteger('size');
            $table->string('crc32')->default('');
            $table->boolean('passworded')->default(false);
            $table->timestamps();
            $table->primary(['releases_id', 'name']);
        });
        $group = UsenetGroup::query()->create(['name' => 'alt.binaries.test']);
        $release = Release::factory()->create([
            'name' => '5da7b5393d4f4445ac4db1ee8e95f567',
            'searchname' => '5da7b5393d4f4445ac4db1ee8e95f567',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'isrenamed' => 0,
            'passwordstatus' => $priorPassword,
        ]);
        DB::table('predb')->insert(['title' => 'Hidden.Payload.2026.2160p-GROUP']);
        $payload = '';
        for ($i = 0; $i < 8192; $i++) {
            $payload .= hash('sha256', pack('V', $i), true);
        }
        $hidden = '.Hidden.Payload.2026.2160p-GROUP/Hidden.Payload.2026.2160p-GROUP.mkv';
        $visible = 'Visible.Release.2026.1080p-GROUP/Visible.Release.2026.1080p-GROUP.mkv';
        $archivePath = $this->makeTempPath('hidden-first', '.zip');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $entries = [$hidden => $payload];
        for ($i = 1; $i < $hiddenCount; $i++) {
            $entries['Parent/.hidden/'.$i.'/Hidden.Payload.2026.2160p-GROUP.mkv'] = substr($payload, 0, 32);
        }
        $entries[$visible] = substr($payload, 0, 32768);
        foreach ($entries as $name => $bytes) {
            $this->assertTrue($zip->addFromString($name, $bytes));
            $this->assertTrue($zip->setCompressionName($name, ZipArchive::CM_STORE));
        }
        if ($encrypted) {
            $this->assertTrue($zip->setEncryptionName($hidden, ZipArchive::EM_AES_256, 'fixture-only'));
        }
        $this->assertTrue($zip->close());
        $config = $this->makeConfig(['processPasswords' => true]);
        $this->app->instance(ProcessingConfiguration::class, $config);
        $inspector = new ArchiveExtractionService($config);
        $tmpPath = $this->makeTempDirectory('manifest-only').'/';
        $archive = (string) file_get_contents($archivePath);
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('manifest-nzbs').'/']);
        $nzb = app(NzbService::class);
        $nzbPath = $nzb->getNzbPath($release->guid, createIfNotExist: true);
        file_put_contents($nzbPath, gzencode('<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb"><file poster="fixture@example.invalid" date="1788600000" subject="&quot;opaque.zip&quot; yEnc (1/1)"><groups><group>alt.binaries.test</group></groups><segments><segment bytes="'.strlen($archive).'" number="1">fixture@example.invalid</segment></segments></file></nzb>'));
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getMessagesByMessageIDWithCrcStatus')->twice()->andReturn(new ArticleDownloadResult($archive));
        $this->app->instance(UsenetDownloadService::class, new UsenetDownloadService($config, $nntp));
        $synchronized = [];
        for ($pass = 0; $pass < 2; $pass++) {
            $context = new ReleaseProcessingContext($release->fresh());
            $context->nzbHasCompressedFile = true;
            $result = $inspector->processCompressedData($archive, $context, $tmpPath);
            $this->assertTrue($result['success']);
            $this->assertFalse($result['hasPassword']);
            $this->assertSame(array_keys($entries), array_column($result['dataSummary']['file_list'], 'name'));
            $this->assertSame(262144, $result['files'][0]['size']);
            $this->assertSame(32768, $result['files'][$hiddenCount]['size']);
            $this->assertSame($encrypted ? 1 : 0, $result['files'][0]['pass']);

            $this->assertTrue($result['manifestComplete']);
            app(ReleaseProcessor::class)->process($context, $tmpPath);
            $release->refresh();
            $this->assertSame('Visible.Release.2026.1080p-GROUP', $release->searchname);
            $this->assertSame(Category::MOVIE_HD, (int) $release->categories_id);
            $this->assertSame(1, (int) $release->is_trusted_name);
            $this->assertSame(1, (int) $release->proc_files);
            $this->assertSame($encrypted ? 1 : $priorPassword, (int) $release->passwordstatus);
            $this->assertSame(min(11 * ($pass + 1), $hiddenCount + 1 - (int) $encrypted), (int) $release->rarinnerfilecount);
            $this->assertSame($hiddenCount + 1 - (int) $encrypted, $context->totalFileInfo);
            $this->assertSame(0, (int) $release->predb_id);
            $this->assertSame([], glob($tmpPath.'unzip/*'));
        }
        $this->assertNotEmpty($synchronized);
        $this->assertSame(['Visible.Release.2026.1080p-GROUP'], array_values(array_unique($synchronized)));
    }

    /** @return array<string, array{int, bool, int}> */
    public static function generatedArchiveCases(): array
    {
        return [
            'hidden first does not imply encryption' => [1, false, 0],
            'prior password evidence survives' => [1, false, 1],
            'visible candidate beyond inventory cap' => [15, false, 0],
            'hidden encrypted entry with visible headers' => [1, true, 0],
        ];
    }

    public function test_descriptive_title_renames_an_obfuscated_release_from_a_video_filename(): void
    {
        Search::shouldReceive('updateRelease')->twice();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.multimedia.erotica',
            'active' => 1,
            'backfill' => 0,
        ]);
        $release = Release::factory()->create([
            'name' => '(Els1212) [02/23] - "CQPVTOVKUDJVGELG.part01.rar"',
            'searchname' => '(Els1212) [02/23] - "CQPVTOVKUDJVGELG.part01.rar"',
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'iscategorized' => 1,
            'isrenamed' => 0,
            'guid' => str_repeat('f', 40),
            'leftguid' => 'f',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        app(ReleaseUpdateService::class)->updateRelease(
            $release->fresh(),
            'SupergirlPerv.avi',
            'fileCheck: Descriptive title',
            true,
            'Filenames, ',
            true,
            false,
            descriptiveTitleCandidate: true,
        );

        $release->refresh();

        $this->assertSame('SupergirlPerv', $release->searchname);
        $this->assertSame(1, (int) $release->proc_files);
        $this->assertSame(1, (int) $release->isrenamed);
    }

    public function test_descriptive_title_does_not_replace_a_real_current_release_name(): void
    {
        Search::shouldReceive('updateRelease')->once();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.movies',
            'active' => 1,
            'backfill' => 0,
        ]);
        $currentName = 'Some.Movie.2019.1080p.x264-GRP';
        $release = Release::factory()->create([
            'name' => $currentName,
            'searchname' => $currentName,
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::MOVIE_HD,
            'iscategorized' => 1,
            'isrenamed' => 0,
            'guid' => str_repeat('g', 40),
            'leftguid' => 'g',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        app(ReleaseUpdateService::class)->updateRelease(
            $release->fresh(),
            'Behind The Scenes Featurette.mp4',
            'fileCheck: Descriptive title',
            true,
            'Filenames, ',
            true,
            false,
            descriptiveTitleCandidate: true,
        );

        $release->refresh();

        $this->assertSame($currentName, $release->searchname);
        $this->assertSame(0, (int) $release->proc_files);
        $this->assertSame(0, (int) $release->isrenamed);
    }

    public function test_a_raw_usenet_subject_accepts_its_inner_file_title(): void
    {
        Search::shouldReceive('updateRelease')->andReturn(true);

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.test',
            'active' => 1,
            'backfill' => 0,
        ]);
        $subject = '(Poster) - Visible.Release.2026.1080p - [19/57] - "Visible.Release.2026.1080p.part17.rar"';
        $release = Release::factory()->create([
            'name' => $subject.' yEnc',
            'searchname' => $subject,
            'groups_id' => $group->id,
            'categories_id' => Category::MOVIE_HD,
            'isrenamed' => 0,
            'proc_files' => 0,
            'is_trusted_name' => 0,
        ]);

        app(ReleaseUpdateService::class)->updateRelease(
            $release->fresh(),
            'Visible.Release.2026.1080p.mkv',
            'fileCheck: Descriptive title',
            true,
            'Filenames, ',
            true,
            false,
            descriptiveTitleCandidate: true,
        );

        $release->refresh();

        $this->assertSame('Visible.Release.2026.1080p', $release->searchname);
        $this->assertSame(1, (int) $release->isrenamed);
        $this->assertSame(1, (int) $release->proc_files);
        $this->assertSame(0, (int) $release->is_trusted_name);
    }

    public function test_an_archive_listing_replaces_a_raw_usenet_subject_with_a_trusted_name(): void
    {
        Search::shouldReceive('updateRelease')->andReturn(true);

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.test',
            'active' => 1,
            'backfill' => 0,
        ]);
        $subject = '(Poster) - Visible.Release.2026.1080p - [19/57] - "Visible.Release.2026.1080p.part17.rar"';
        $release = Release::factory()->create([
            'name' => $subject.' yEnc',
            'searchname' => $subject,
            'groups_id' => $group->id,
            'categories_id' => Category::MOVIE_HD,
            'isrenamed' => 0,
            'proc_files' => 0,
            'is_trusted_name' => 0,
        ]);

        app(ReleaseUpdateService::class)->updateRelease(
            $release->fresh(),
            'Visible.Release.2026.1080p',
            'RarInfo FileName Match',
            true,
            'Filenames, ',
            true,
            false,
            preId: 0,
        );

        $release->refresh();

        $this->assertSame('Visible.Release.2026.1080p', $release->searchname);
        $this->assertSame(1, (int) $release->isrenamed);
        $this->assertSame(1, (int) $release->proc_files);
        $this->assertSame(1, (int) $release->is_trusted_name);
    }

    public function test_a_raw_usenet_subject_still_refuses_a_candidate_that_drops_its_episode(): void
    {
        Search::shouldReceive('updateRelease')->andReturn(true);

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.test',
            'active' => 1,
            'backfill' => 0,
        ]);
        $subject = '(Poster) - Visible.Show.S01E02.1080p - [01/20] - "Visible.Show.S01E02.1080p.part01.rar"';
        $release = Release::factory()->create([
            'name' => $subject.' yEnc',
            'searchname' => $subject,
            'groups_id' => $group->id,
            'categories_id' => Category::MOVIE_HD,
            'isrenamed' => 0,
            'proc_files' => 0,
            'is_trusted_name' => 0,
        ]);

        app(ReleaseUpdateService::class)->updateRelease(
            $release->fresh(),
            'Visible.Show.1080p.mkv',
            'fileCheck: Descriptive title',
            true,
            'Filenames, ',
            true,
            false,
            descriptiveTitleCandidate: true,
        );

        $release->refresh();

        $this->assertSame($subject, $release->searchname);
        $this->assertSame(0, (int) $release->isrenamed);
        $this->assertSame(0, (int) $release->proc_files);
        $this->assertSame(0, (int) $release->is_trusted_name);
    }

    public function test_a_raw_usenet_subject_still_refuses_an_abbreviated_inner_name(): void
    {
        Search::shouldReceive('updateRelease')->andReturn(true);

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.test',
            'active' => 1,
            'backfill' => 0,
        ]);
        $subject = '(Poster) - Visible.Release.2026.1080p.BluRay.x264-GROUP - [19/57] - "Visible.Release.2026.1080p.BluRay.x264-GROUP.part17.rar"';
        $release = Release::factory()->create([
            'name' => $subject.' yEnc',
            'searchname' => $subject,
            'groups_id' => $group->id,
            'categories_id' => Category::MOVIE_HD,
            'isrenamed' => 0,
            'proc_files' => 0,
            'is_trusted_name' => 0,
        ]);

        app(ReleaseUpdateService::class)->updateRelease(
            $release->fresh(),
            'grp-vr.2026.1080p',
            'RarInfo FileName Match',
            true,
            'Filenames, ',
            true,
            false,
            preId: 0,
        );

        $release->refresh();

        $this->assertSame($subject, $release->searchname);
        $this->assertSame(0, (int) $release->isrenamed);
        $this->assertSame(0, (int) $release->proc_files);
        $this->assertSame(0, (int) $release->is_trusted_name);
    }

    public function test_name_fix_listener_refines_an_other_category_from_existing_media_info(): void
    {
        $release = Release::factory()->create([
            'categories_id' => Category::OTHER_HASHED,
            'iscategorized' => 1,
            'guid' => str_repeat('h', 40),
            'leftguid' => 'h',
        ]);
        DB::table('video_data')->insert([
            'releases_id' => $release->id,
            'videowidth' => 1920,
            'videoheight' => 1080,
            'videoformat' => 'AVC',
        ]);

        $categorization = Mockery::mock(CategorizationService::class);
        $categorization->shouldReceive('determineCategory')->once()->andReturn([
            'categories_id' => Category::MOVIE_OTHER,
        ]);
        $synchronized = [];
        $coordinator = new ReleaseSearchSyncCoordinator(
            new PersistenceMetricsCollector,
            function (int $releaseId) use (&$synchronized): void {
                $synchronized[] = $releaseId;
            },
        );
        $previewPolicy = new PreviewGenerationPolicy;
        $listener = new RecategorizeReleaseAfterNameFix(
            $categorization,
            $previewPolicy,
            new MediaInfoRefinementService($previewPolicy, $coordinator),
        );

        $listener->handle(new ReleaseNameFixed(
            (int) $release->id,
            'old-name',
            'new-name',
            Category::OTHER_HASHED,
            1,
        ));

        $this->assertSame(Category::MOVIE_HD, (int) $release->fresh()->categories_id);
        $this->assertSame(1, (int) $release->fresh()->iscategorized);
        $this->assertSame([(int) $release->id], $synchronized);
    }

    public function test_name_fix_listener_honors_an_explicit_category_override(): void
    {
        Search::shouldReceive('updateRelease')->once();

        $release = Release::factory()->create([
            'categories_id' => Category::MUSIC_MP3,
            'iscategorized' => 1,
            'guid' => str_repeat('a', 40),
            'leftguid' => 'a',
        ]);
        $categorization = Mockery::mock(CategorizationService::class);
        $categorization->shouldNotReceive('determineCategory');
        $previewPolicy = new PreviewGenerationPolicy;
        $listener = new RecategorizeReleaseAfterNameFix(
            $categorization,
            $previewPolicy,
            new MediaInfoRefinementService(
                $previewPolicy,
                new ReleaseSearchSyncCoordinator(
                    new PersistenceMetricsCollector,
                    static function (int $releaseId): void {},
                ),
            ),
        );

        $listener->handle(new ReleaseNameFixed(
            (int) $release->id,
            'old-name',
            'Artist - Album FLAC',
            Category::MUSIC_OTHER,
            1,
            categoryOverride: Category::MUSIC_MP3,
        ));

        $this->assertSame(Category::MUSIC_MP3, (int) $release->fresh()->categories_id);
        $this->assertSame(1, (int) $release->fresh()->iscategorized);
    }

    public function test_internal_processing_status_updates_do_not_refresh_the_search_index(): void
    {
        Search::shouldReceive('updateRelease')->once();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.test',
            'active' => 1,
            'backfill' => 0,
        ]);
        $release = Release::factory()->create([
            'groups_id' => $group->id,
            'guid' => str_repeat('d', 40),
            'leftguid' => 'd',
            'proc_nfo' => 0,
        ]);

        app(ReleaseUpdateService::class)->updateSingleColumn('proc_nfo', 1, $release->id);

        $this->assertSame(1, (int) $release->fresh()->proc_nfo);
    }

    public function test_exact_predb_attachment_applies_the_canonical_name_transition(): void
    {
        Search::shouldReceive('updateRelease')->once();
        Event::fake([ReleaseNameFixed::class]);

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.test',
            'active' => 1,
            'backfill' => 0,
        ]);
        $release = Release::factory()->create([
            'searchname' => 'Exact.PreDB.Title-GROUP',
            'groups_id' => $group->id,
            'guid' => str_repeat('p', 40),
            'leftguid' => 'p',
            'videos_id' => 41,
            'tv_episodes_id' => 42,
            'imdbid' => 'tt1234567',
            'musicinfo_id' => 43,
            'consoleinfo_id' => 44,
            'bookinfo_id' => 45,
            'anidbid' => 46,
            'isrenamed' => 0,
            'is_trusted_name' => 0,
        ]);
        $synchronized = [];
        $updates = new ReleaseUpdateService(
            searchSyncCoordinator: new ReleaseSearchSyncCoordinator(
                new PersistenceMetricsCollector,
                function (int $releaseId) use (&$synchronized): void {
                    $synchronized[] = $releaseId;
                },
            ),
        );

        $updates->attachPredbId((int) $release->id, 123);

        $release->refresh();
        $this->assertSame(123, (int) $release->predb_id);
        $this->assertSame(1, (int) $release->isrenamed);
        $this->assertSame(1, (int) $release->is_trusted_name);
        $this->assertSame(0, (int) $release->videos_id);
        $this->assertSame(0, (int) $release->tv_episodes_id);
        $this->assertNull($release->imdbid);
        $this->assertNull($release->musicinfo_id);
        $this->assertNull($release->consoleinfo_id);
        $this->assertNull($release->bookinfo_id);
        $this->assertNull($release->anidbid);
        $this->assertSame([(int) $release->id], $synchronized);
        Event::assertDispatched(
            ReleaseNameFixed::class,
            fn (ReleaseNameFixed $event): bool => $event->releaseId === (int) $release->id
                && $event->oldName === 'Exact.PreDB.Title-GROUP'
                && $event->newName === 'Exact.PreDB.Title-GROUP',
        );
    }

    public function test_predb_title_sweep_applies_the_canonical_name_transition(): void
    {
        Search::shouldReceive('updateRelease')->twice();
        Event::fake([ReleaseNameFixed::class]);

        $predbId = DB::table('predb')->insertGetId(['title' => 'Exact.PreDB.Title-GROUP']);
        $release = Release::factory()->create([
            'searchname' => 'Exact.PreDB.Title-GROUP',
            'guid' => str_repeat('q', 40),
            'leftguid' => 'q',
            'predb_id' => 0,
            'isrenamed' => 0,
            'is_trusted_name' => 0,
            'musicinfo_id' => 43,
        ]);

        Predb::checkPre();

        $release->refresh();
        $this->assertSame($predbId, (int) $release->predb_id);
        $this->assertSame(1, (int) $release->isrenamed);
        $this->assertSame(1, (int) $release->is_trusted_name);
        $this->assertNull($release->musicinfo_id);
        Event::assertDispatched(
            ReleaseNameFixed::class,
            fn (ReleaseNameFixed $event): bool => $event->releaseId === (int) $release->id,
        );
    }

    public function test_srrdb_attachment_applies_the_canonical_name_transition(): void
    {
        Search::shouldReceive('updateRelease')->once();
        Event::fake([ReleaseNameFixed::class]);

        $release = Release::factory()->create([
            'searchname' => 'Exact.SRRDB.Title-GROUP',
            'guid' => str_repeat('s', 40),
            'leftguid' => 's',
            'bookinfo_id' => 45,
        ]);
        $synchronized = [];
        $updates = new ReleaseUpdateService(
            searchSyncCoordinator: new ReleaseSearchSyncCoordinator(
                new PersistenceMetricsCollector,
                function (int $releaseId) use (&$synchronized): void {
                    $synchronized[] = $releaseId;
                },
            ),
        );

        $updates->attachSrrdbMatch((int) $release->id, 321, 'tt7654321');

        $release->refresh();
        $this->assertSame(321, (int) $release->predb_id);
        $this->assertSame('tt7654321', $release->imdbid);
        $this->assertSame(1, (int) $release->proc_srrdb);
        $this->assertSame(1, (int) $release->isrenamed);
        $this->assertSame(1, (int) $release->is_trusted_name);
        $this->assertNull($release->bookinfo_id);
        $this->assertSame([(int) $release->id], $synchronized);
        Event::assertDispatched(
            ReleaseNameFixed::class,
            fn (ReleaseNameFixed $event): bool => $event->releaseId === (int) $release->id,
        );
    }

    public function test_downgraded_name_restorer_dry_run_reports_without_changing_releases(): void
    {
        Search::shouldReceive('updateRelease')->never();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.movies',
            'active' => 1,
            'backfill' => 0,
        ]);
        $release = Release::withoutEvents(fn (): Release => Release::factory()->create([
            'name' => '[12123]-[FULL]-[ The.Odd.Life.of.Timothy.Green.2012.NTSC.DVDR-SCREAM ]-[alt.binaries.really.long.movies.collection]-[013/111] -',
            'searchname' => 'Tolotg-Scream',
            'groups_id' => $group->id,
            'categories_id' => Category::MOVIE_OTHER,
            'guid' => str_repeat('d', 40),
            'leftguid' => 'd',
        ]));
        $unquoted = Release::withoutEvents(fn (): Release => Release::factory()->create([
            'name' => '[01/10] Recovered.Movie.2014.720p.WEB-DL-GROUP yEnc',
            'searchname' => 'Recoveredm-GROUP',
            'groups_id' => $group->id,
            'categories_id' => Category::MOVIE_OTHER,
            'guid' => str_repeat('u', 40),
            'leftguid' => 'u',
        ]));

        $limitedResult = app(DowngradedNameRestorer::class)->run(dryRun: true, limit: 1);

        $this->assertSame(1, $limitedResult->scanned);
        $this->assertCount(1, $limitedResult->pairs);

        $result = app(DowngradedNameRestorer::class)->run(dryRun: true, limit: null);

        $this->assertSame(2, $result->scanned);
        $this->assertSame(0, $result->restored);
        $this->assertSame(0, $result->skipped);
        $this->assertSame([
            [
                'release_id' => (int) $release->id,
                'old' => 'Tolotg-Scream',
                'new' => 'The.Odd.Life.of.Timothy.Green.2012.NTSC.DVDR-SCREAM',
            ],
            [
                'release_id' => (int) $unquoted->id,
                'old' => 'Recoveredm-GROUP',
                'new' => 'Recovered.Movie.2014.720p.WEB-DL-GROUP',
            ],
        ], $result->pairs);
        $this->assertSame('Tolotg-Scream', $release->fresh()->searchname);
        $this->assertSame('Recoveredm-GROUP', $unquoted->fresh()->searchname);
    }

    public function test_downgraded_name_restorer_applies_the_canonical_name_transition(): void
    {
        Search::shouldReceive('updateRelease')->never();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.movies',
            'active' => 1,
            'backfill' => 0,
        ]);
        $restoredTitle = 'The.Odd.Life.of.Timothy.Green.2012.NTSC.DVDR-SCREAM';
        $predb = Predb::query()->create(['title' => $restoredTitle]);
        $downgraded = Release::withoutEvents(fn (): Release => Release::factory()->create([
            'name' => '[12123]-[FULL]-[a.b.mooveeEFNet]-[ '.$restoredTitle.' ]-[013/111] -',
            'searchname' => 'Tolotg-Scream',
            'groups_id' => $group->id,
            'categories_id' => Category::MOVIE_OTHER,
            'guid' => str_repeat('r', 40),
            'leftguid' => 'r',
            'videos_id' => 11,
            'movieinfo_id' => 12,
        ]));
        $legitimatelyShort = Release::withoutEvents(fn (): Release => Release::factory()->create([
            'name' => '[12124]-[FULL]-[a.b.mooveeEFNet]-[ Upstream-GROUP ]-[001/010] -',
            'searchname' => 'Upstream-GROUP',
            'groups_id' => $group->id,
            'categories_id' => Category::MOVIE_OTHER,
            'guid' => str_repeat('s', 40),
            'leftguid' => 's',
        ]));
        $untrusted = Release::withoutEvents(fn (): Release => Release::factory()->create([
            'name' => '[12125]-[FULL]-[alt.binaries.movies]-[ Recovered.Movie.2014.720p.WEB-DL-GROUP ]-[001/010] -',
            'searchname' => 'Recoveredm-GROUP',
            'groups_id' => $group->id,
            'categories_id' => Category::MOVIE_OTHER,
            'guid' => str_repeat('t', 40),
            'leftguid' => 't',
            'is_trusted_name' => 1,
        ]));
        $synchronized = [];
        $updates = new ReleaseUpdateService(
            searchSyncCoordinator: new ReleaseSearchSyncCoordinator(
                new PersistenceMetricsCollector,
                static function (int $releaseId) use (&$synchronized): void {
                    $synchronized[] = $releaseId;
                },
            ),
        );
        $restorer = new DowngradedNameRestorer(new FileNameCleaner, $updates);

        $result = $restorer->run(dryRun: false, limit: null);

        $downgraded->refresh();
        $this->assertSame(3, $result->scanned);
        $this->assertSame(2, $result->restored);
        $this->assertSame(1, $result->skipped);
        $this->assertSame($restoredTitle, $downgraded->searchname);
        $this->assertSame((int) $predb->id, (int) $downgraded->predb_id);
        $this->assertSame(0, (int) $downgraded->videos_id);
        $this->assertNull($downgraded->movieinfo_id);
        $this->assertNotSame(Category::MOVIE_OTHER, (int) $downgraded->categories_id);
        $this->assertSame('Upstream-GROUP', $legitimatelyShort->fresh()->searchname);
        $this->assertSame('Recovered.Movie.2014.720p.WEB-DL-GROUP', $untrusted->fresh()->searchname);
        $this->assertSame(0, (int) $untrusted->fresh()->is_trusted_name);
        $this->assertSame([(int) $downgraded->id, (int) $untrusted->id], $synchronized);
    }

    private function createSchema(): void
    {
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->text('value')->nullable();
            });
        }

        if (! Schema::hasTable('root_categories')) {
            Schema::create('root_categories', function (Blueprint $table): void {
                $table->unsignedInteger('id')->primary();
                $table->string('title')->default('');
                $table->boolean('generate_previews')->default(true);
            });
        }

        if (! Schema::hasTable('usenet_groups')) {
            Schema::create('usenet_groups', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name')->unique();
                $table->integer('backfill_target')->default(1);
                $table->unsignedBigInteger('first_record')->default(0);
                $table->dateTime('first_record_postdate')->nullable();
                $table->unsignedBigInteger('last_record')->default(0);
                $table->dateTime('last_record_postdate')->nullable();
                $table->dateTime('last_updated')->nullable();
                $table->integer('minfilestoformrelease')->nullable();
                $table->bigInteger('minsizetoformrelease')->nullable();
                $table->boolean('active')->default(false);
                $table->boolean('backfill')->default(false);
                $table->string('description')->nullable();
                $table->boolean('route_obfuscated_names')->default(false);
                $table->unsignedInteger('obfuscated_default_root_categories_id')->nullable();
                $table->unsignedInteger('forced_root_categories_id')->nullable();
            });
        }

        if (! Schema::hasTable('releases')) {
            Schema::create('releases', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name')->default('');
                $table->string('searchname')->default('');
                $table->string('searchname_normalized')->nullable();
                $table->string('display_name')->nullable();
                $table->unsignedInteger('groups_id')->default(0);
                $table->unsignedBigInteger('size')->default(0);
                $table->dateTime('postdate')->nullable();
                $table->dateTime('adddate')->nullable();
                $table->string('guid', 40)->unique();
                $table->char('leftguid', 1);
                $table->string('fromname')->nullable();
                $table->integer('categories_id')->default(Category::OTHER_MISC);
                $table->unsignedInteger('videos_id')->default(0);
                $table->integer('tv_episodes_id')->default(0);
                $table->integer('movieinfo_id')->nullable();
                $table->string('imdbid')->nullable();
                $table->integer('musicinfo_id')->nullable();
                $table->integer('consoleinfo_id')->nullable();
                $table->integer('bookinfo_id')->nullable();
                $table->integer('anidbid')->nullable();
                $table->integer('gamesinfo_id')->default(0);
                $table->unsignedInteger('predb_id')->default(0);
                $table->tinyInteger('iscategorized')->default(0);
                $table->tinyInteger('isrenamed')->default(0);
                $table->tinyInteger('is_trusted_name')->default(0);
                $table->tinyInteger('proc_nfo')->default(0);
                $table->tinyInteger('proc_files')->default(0);
                $table->tinyInteger('proc_xxx')->default(0);
                $table->tinyInteger('proc_par2')->default(0);
                $table->tinyInteger('proc_uid')->default(0);
                $table->tinyInteger('proc_media_movie')->default(0);
                $table->tinyInteger('proc_hash16k')->default(0);
                $table->tinyInteger('proc_srr')->default(0);
                $table->tinyInteger('proc_crc32')->default(0);
                $table->tinyInteger('proc_srrdb')->default(0);
                $table->tinyInteger('passwordstatus')->default(0);
                $table->tinyInteger('haspreview')->default(0);
                $table->tinyInteger('nzbstatus')->default(0);
                $table->unsignedInteger('pp_timeout_count')->default(0);
            });
        }

        if (! Schema::hasTable('predb')) {
            Schema::create('predb', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('title')->unique();
            });
        }

        if (! Schema::hasTable('releases_groups')) {
            Schema::create('releases_groups', function (Blueprint $table): void {
                $table->unsignedInteger('releases_id');
                $table->unsignedInteger('groups_id');
                $table->primary(['releases_id', 'groups_id']);
            });
        }

        if (! Schema::hasTable('video_data')) {
            Schema::create('video_data', function (Blueprint $table): void {
                $table->unsignedInteger('releases_id')->primary();
                $table->string('containerformat')->nullable();
                $table->string('videoformat')->nullable();
                $table->string('videocodec')->nullable();
                $table->integer('videowidth')->nullable();
                $table->integer('videoheight')->nullable();
            });
        }

        if (! Schema::hasTable('audio_data')) {
            Schema::create('audio_data', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('releases_id');
                $table->unsignedInteger('audioid');
                $table->string('audioformat')->nullable();
                $table->unique(['releases_id', 'audioid']);
            });
        }
    }
}
