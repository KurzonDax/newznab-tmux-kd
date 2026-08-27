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
use App\Services\AdditionalProcessing\Config\PasswordInspectionMode;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\Categorization\MediaInfoRefinementService;
use App\Services\NameFixing\DowngradedNameRestorer;
use App\Services\NameFixing\FileNameCleaner;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\Releases\PreviewGenerationPolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class ReleaseNameFixedRecategorizationTest extends TestCase
{
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
                $table->string('guid', 40);
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
            });
        }
    }
}
