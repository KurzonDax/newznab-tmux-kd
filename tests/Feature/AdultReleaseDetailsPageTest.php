<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\Release;
use App\Models\User;
use App\Services\Releases\ReleaseSearchService;
use App\Services\Search\DTO\ReleaseSearchQuery;
use App\Services\Search\DTO\SearchPage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\AssertsFollowWording;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * The Adult release details page, GET /details/{guid} for a release in the Adult band
 * (docs/proposals/adult-redesign/SPEC.md 5A; DATA-CONTRACT.md 4.3, 4.4 and 6; the details checks of prototype/check.mjs).
 */
final class AdultReleaseDetailsPageTest extends TestCase
{
    use AssertsFollowWording;
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    private const X264 = 6040;

    private const VR = 6046;

    private const MOVIES_HD = 2040;

    private const GB = 1073741824;

    private ?User $user = null;

    private string $covers = '';

    private int $nextRelease = 0;

    /** @var list<array{int, string, list<int>}> searchSimilar's calls: release id, name, exclusions */
    private array $similarCalls = [];

    /** @var list<int> the ids of the rows searchSimilar answers with */
    private array $similarIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->withoutVite();
        $this->withoutMiddleware(TrustedDevice2FAMiddleware::class);
        Carbon::setTestNow('2026-09-25 12:00:00');
        $tables = ProductionTables::fromAuthority();
        $tables->create('releases', ['id', 'name', 'searchname', 'guid', 'display_name', 'categories_id', 'category_band', 'size', 'totalpart',
            'adddate', 'postdate', 'grabs', 'comments', 'completion', 'repair_outcome', 'rescan_outcome', 'declaredfiles', 'nzbstatus', 'passwordstatus', 'nfostatus',
            'haspreview', 'jpgstatus', 'videostatus', 'groups_id', 'fromname', 'isrenamed', 'additional_pp_claim_token', 'imdbid', 'movieinfo_id',
            'videos_id', 'tv_episodes_id', 'musicinfo_id', 'consoleinfo_id', 'gamesinfo_id', 'bookinfo_id', 'anidbid', 'predb_id', 'resolution', 'source']);
        foreach (['usenet_groups', 'users_releases', 'user_series', 'user_movies', 'videos', 'movieinfo', 'release_audio_tags', 'release_video_clips',
            'languages', 'release_audio_languages', 'releases_groups', 'release_regexes', 'release_comments', 'release_nfos', 'video_data', 'audio_data',
            'release_subtitles', 'media_infos', 'media_info_probes', 'media_info_tracks', 'predb', 'release_tv_episodes', 'tv_episodes', 'tv_info', 'networks', 'video_genres', 'video_people'] as $table) {
            $tables->create($table);
        }
        DB::table('root_categories')->insert([['id' => 6000, 'title' => 'XXX', 'status' => 1], ['id' => 2000, 'title' => 'Movies', 'status' => 1]]);
        DB::table('categories')->insert([
            ['id' => self::X264, 'title' => 'x264', 'root_categories_id' => 6000, 'status' => 1],
            ['id' => self::VR, 'title' => 'VR', 'root_categories_id' => 6000, 'status' => 1],
            ['id' => self::MOVIES_HD, 'title' => 'HD', 'root_categories_id' => 2000, 'status' => 1],
        ]);
        DB::table('usenet_groups')->insert(['id' => 99, 'name' => 'alt.binaries.example.erotica']);
        $this->covers = $this->makeTempDirectory('adult-details-covers');
        config(['nntmux_settings.covers_path' => $this->covers]);
        $search = Mockery::mock(ReleaseSearchService::class)->makePartial();
        $search->shouldReceive('searchSimilar')->andReturnUsing(function (mixed $id, mixed $name, array $exclusions = []): array {
            $this->similarCalls[] = [(int) $id, (string) $name, $exclusions];

            return Release::query()->whereIn('id', $this->similarIds)->get()->sortBy(fn (Release $release): int|false => array_search($release->id, $this->similarIds, true))->values()->all();
        });
        $this->app->instance(ReleaseSearchService::class, $search);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_the_page_is_the_release_only_form_with_the_crumb_to_the_adult_list_chips_without_source_and_three_buttons(): void
    {
        $id = $this->adult('Vixen.26.09.20.Some.Scene.XXX.1080p.MP4-GRP', ['categories_id' => self::VR, 'nfostatus' => 1, 'completion' => 94,
            'fromname' => 'paperboat <pb@example.invalid>', 'haspreview' => 1, 'jpgstatus' => 1, 'videostatus' => 1]);
        DB::table('release_nfos')->insert(['releases_id' => $id, 'nfo' => 'NFO']);
        DB::table('release_video_clips')->insert(['releases_id' => $id, 'extension' => 'mp4', 'mime' => 'video/mp4', 'duration_seconds' => 30]);

        $response = $this->details($id)->assertOk()->assertViewIs('details.adult.index');
        $html = (string) $response->getContent();

        $crumbs = $this->between($response, '<nav class="tv-crumbs" aria-label="Breadcrumb">', '</nav>');
        $this->assertSame('<a href="'.route('adult.releases').'">Adult releases</a><span aria-hidden="true">›</span><span>VR</span>', (string) preg_replace('/>\s+</', '><', $crumbs));
        $response->assertSee('<div class="tv-details-head is-release-only">', false)->assertSee('<div class="tv-details-columns is-release-only">', false)
            ->assertSee('<h1 class="is-release-name" data-part="details heading">Vixen.26.09.20.Some.Scene.XXX.1080p.MP4-GRP</h1>', false)
            ->assertDontSee('tv-details-art', false)->assertDontSee('tv-about', false)->assertDontSee('tv-source-chip', false)
            ->assertDontSee('data-watch-picker', false)->assertDontSee('Report')->assertDontSee('style="', false);
        $chips = $this->between($response, '<div class="tv-chips tv-details-chips">', '<div class="tv-chips tv-details-origin">');
        $this->assertSeeOrder($chips, ['resolution-chip-1080', '94% complete', 'nfo-badge', 'preview-badge', 'sample-badge']);
        $this->assertSame(1, substr_count($chips, 'preview-badge'));
        $this->assertMatchesRegularExpression('/<button[^>]*class="[^"]*chip-tone-preview[^"]*preview-badge[^"]*"[^>]*data-video-url="'.preg_quote(route('preview.video', $this->guid($id)), '/')
            .'"[^>]*data-video-type="video\/mp4"[^>]*data-image-title="Video preview"[^>]*title="Play the video preview"[^>]*>\s*<i class="fas fa-play" aria-hidden="true"><\/i>\s*Preview\s*<\/button>/', $chips);
        $response->assertDontSee('clip-badge', false)->assertDontSee('chip-tone-clip', false);
        $response->assertSeeInOrder(['tv-details-origin', 'href="'.route('browse.all', ['group' => 'alt.binaries.example.erotica']).'"',
            'href="'.route('browse.all', ['poster' => 'paperboat <pb@example.invalid>']).'"'], false);
        $this->assertSame(['Download NZB', 'Copy NZB link', 'Add to cart'], $this->buttons($html));
        $this->assertSame(['Overview', 'Files (1)', 'Media info', 'NFO', 'Comments (0)'], $this->tabs($html));
        $this->assertNoWatchWording($html, 'The Adult details page');
        $response->assertSee('x-data="movieReleaseDetails"', false)->assertSee('x-on:submit="handleSubmit"', false)->assertSee('x-data="tvImageDialog"', false);
    }

    public function test_the_media_chip_leaves_the_resolution_to_its_own_chip_and_the_poster_chip_shows_the_full_name(): void
    {
        $id = $this->adult('Some.Scene.XXX.1080p', ['fromname' => 'granite41 <granite41@example.invalid>']);
        DB::table('video_data')->insert(['releases_id' => $id, 'videoformat' => 'HEVC', 'videocodec' => 'V_MPEGH/ISO/HEVC', 'videowidth' => 1920, 'videoheight' => 1080]);
        DB::table('audio_data')->insert(['releases_id' => $id, 'audioid' => 1, 'audioformat' => 'E-AC-3', 'audiochannels' => '6']);

        $response = $this->details($id)->assertOk();
        $chips = $this->between($response, '<div class="tv-chips tv-details-chips">', '<div class="tv-chips tv-details-origin">');
        $this->assertStringContainsString('H.265 · E-AC-3 5.1', $chips);
        $this->assertStringNotContainsString('1080p · H.265', $chips);
        $response->assertSee('<i class="fas fa-user" aria-hidden="true"></i>granite41 &lt;granite41@example.invalid&gt;</a>', false);
    }

    public function test_the_cart_button_reads_in_cart_when_pressed(): void
    {
        $id = $this->adult('Some.Scene.XXX.1080p');
        DB::table('users_releases')->insert(['users_id' => $this->user()->id, 'releases_id' => $id]);

        $actions = $this->between($this->details($id), '<div class="tv-details-actions">', '</div>');
        $this->assertStringContainsString('data-cart-label aria-pressed="true" title="In cart · click to remove"><i class="fas fa-cart-shopping" aria-hidden="true"></i>', $actions);
    }

    public function test_files_has_no_number_and_the_facts_read_a_dash_when_no_file_count_is_stored(): void
    {
        $id = $this->adult('Some.Scene.XXX.1080p', ['totalpart' => 0, 'grabs' => 7]);

        $response = $this->details($id);
        $this->assertSame(['Overview', 'Files', 'Media info', 'NFO', 'Comments (0)'], $this->tabs((string) $response->getContent()));
        $overview = $this->between($response, 'aria-labelledby="tab-overview" data-details-panel>', '<section id="files"');
        $this->assertSame(['Category' => 'Adult &gt; x264', 'Size' => '1.00 GB', 'Files' => '—', 'Completion' => '100%', 'Posted' => 'Sep 20, 2026, 10:00 AM',
            'Added' => 'Sep 20, 2026, 11:00 AM', 'Grabs' => '7', 'Group' => 'alt.binaries.example.erotica', 'Poster' => '—', 'Password status' => 'None detected'], $this->facts($overview));

        DB::table('releases')->where('id', $id)->update(['totalpart' => 12]);
        $counted = $this->details($id);
        $this->assertSame('Files (12)', $this->tabs((string) $counted->getContent())[1]);
        $this->assertSame('12', $this->facts($this->between($counted, 'aria-labelledby="tab-overview" data-details-panel>', '<section id="files"'))['Files']);
    }

    public function test_a_preview_with_a_clip_plays_it_with_its_poster_the_play_button_and_the_seconds_and_the_sample_opens_at_full_size(): void
    {
        $id = $this->adult('Some.Scene.XXX.1080p', ['haspreview' => 1, 'jpgstatus' => 1, 'videostatus' => 1]);
        $guid = $this->guid($id);
        DB::table('release_video_clips')->insert(['releases_id' => $id, 'extension' => 'mp4', 'mime' => 'video/mp4', 'duration_seconds' => 30]);
        $this->image('preview', $guid.'_thumb');
        $this->image('sample', $guid.'_thumb');
        $this->image('sample', $guid);

        $pictures = $this->pictures($id);
        $this->assertSame('<button type="button" class="tv-details-preview has-clip preview-badge" data-guid="'.$guid.'" data-release-display-name="Some.Scene.XXX.1080p"'
            .' data-video-url="'.route('preview.video', $guid).'" data-video-type="video/mp4" data-poster-url="'.$this->url('preview', $guid.'_thumb').'" data-image-title="Video preview" aria-label="Preview, play the 30-second video preview">'
            .'<img src="'.$this->url('preview', $guid.'_thumb').'" alt="Preview image"><span class="tv-details-play" aria-hidden="true"><i class="fas fa-play"></i></span>'
            .'<span class="tv-details-picture-label" aria-hidden="true">Preview</span><span class="tv-details-picture-label is-clip" aria-hidden="true"><i class="fas fa-play" aria-hidden="true"></i> 30 s</span></button>'
            .'<button type="button" class="tv-details-preview sample-badge" data-guid="'.$guid.'" data-release-display-name="Some.Scene.XXX.1080p"'
            .' data-image-url="'.$this->url('sample', $guid.'_thumb').'" data-full-url="'.$this->url('sample', $guid).'" data-image-title="Sample image" data-open-full aria-label="View sample image at full size">'
            .'<img src="'.$this->url('sample', $guid.'_thumb').'" alt="Sample image"><span class="tv-details-picture-label" aria-hidden="true">Sample</span></button>', $pictures);
        $html = (string) $this->details($id)->getContent();
        $this->assertSame(1, substr_count($html, 'data-open-full'), 'The header Sample chip keeps the fitted dialog.');
        $this->assertStringContainsString('data-poster-url="'.$this->url('preview', $guid.'_thumb').'" data-image-title="Video preview"', $this->between($this->details($id), '<div class="tv-chips tv-details-chips">', '<div class="tv-details-actions">'), 'The chip carries the same poster.');
        $this->assertStringNotContainsString('clip-badge', $html);
    }

    public function test_the_picture_has_no_seconds_label_when_no_clip_row_or_no_duration_is_stored(): void
    {
        $id = $this->adult('Some.Scene.XXX.1080p', ['haspreview' => 1, 'videostatus' => 1]);
        $this->image('preview', $this->guid($id).'_thumb');

        $legacy = $this->pictures($id);
        $this->assertStringContainsString('aria-label="Preview, play the video preview"', $legacy);
        $this->assertStringContainsString('<span class="tv-details-play" aria-hidden="true">', $legacy);
        $this->assertStringNotContainsString('is-clip', $legacy);

        DB::table('release_video_clips')->insert(['releases_id' => $id, 'extension' => 'webm', 'mime' => 'video/webm', 'duration_seconds' => null]);
        $unmeasured = $this->pictures($id);
        $this->assertStringContainsString('data-video-type="video/webm"', $unmeasured);
        $this->assertStringContainsString('aria-label="Preview, play the video preview"', $unmeasured);
        $this->assertStringNotContainsString('is-clip', $unmeasured);
    }

    public function test_a_sample_without_a_full_size_copy_opens_its_thumbnail(): void
    {
        $id = $this->adult('Some.Scene.XXX.1080p', ['jpgstatus' => 1]);
        $guid = $this->guid($id);
        $this->image('sample', $guid.'_thumb');

        $this->assertSame('<button type="button" class="tv-details-preview sample-badge" data-guid="'.$guid.'" data-release-display-name="Some.Scene.XXX.1080p"'
            .' data-image-url="'.$this->url('sample', $guid.'_thumb').'" data-full-url="" data-image-title="Sample image" data-open-full aria-label="View sample image at full size">'
            .'<img src="'.$this->url('sample', $guid.'_thumb').'" alt="Sample image"><span class="tv-details-picture-label" aria-hidden="true">Sample</span></button>', $this->pictures($id));
    }

    public function test_a_preview_without_a_clip_opens_the_image_dialog_and_has_no_play_button_or_tag(): void
    {
        $id = $this->adult('Some.Scene.XXX.1080p', ['haspreview' => 1]);
        $guid = $this->guid($id);
        $this->image('preview', $guid.'_thumb');
        $this->image('preview', $guid);

        $pictures = $this->pictures($id);
        $this->assertSame('<button type="button" class="tv-details-preview preview-badge" data-guid="'.$guid.'" data-release-display-name="Some.Scene.XXX.1080p"'
            .' data-image-url="'.$this->url('preview', $guid.'_thumb').'" data-full-url="'.$this->url('preview', $guid).'" data-image-title="Image preview" aria-label="View the image preview">'
            .'<img src="'.$this->url('preview', $guid.'_thumb').'" alt="Preview image"><span class="tv-details-picture-label" aria-hidden="true">Preview</span></button>', $pictures);
        $this->details($id)->assertDontSee('clip-badge', false)->assertDontSee('tv-details-play', false)->assertDontSee('data-video-url', false);
        $chip = $this->between($this->details($id), '<div class="tv-chips tv-details-chips">', '<div class="tv-details-actions">');
        $this->assertMatchesRegularExpression('/<button[^>]*preview-badge[^>]*data-image-title="Image preview"[^>]*title="View the image preview"[^>]*>\s*Preview\s*<\/button>/', $chip);
    }

    public function test_a_release_without_pictures_has_no_pictures_block(): void
    {
        $id = $this->adult('Some.Scene.XXX.1080p');

        $this->details($id)->assertOk()->assertDontSee('tv-details-pictures', false)->assertDontSee('No picture');
    }

    public function test_the_predb_block_shows_only_with_a_match(): void
    {
        $id = $this->adult('Some.Scene.XXX.1080p');
        $this->details($id)->assertDontSee('PreDB');

        DB::table('predb')->insert(['id' => 5, 'title' => 'Some.Scene.XXX.1080p-GRP', 'source' => 'abgx', 'predate' => '2026-09-19 08:30:00', 'category' => 'XXX']);
        DB::table('releases')->where('id', $id)->update(['predb_id' => 5]);
        $predb = $this->between($this->details($id), '<section class="tv-details-predb" aria-labelledby="predb-heading">', '</section>');
        $this->assertStringContainsString('<h3 id="predb-heading">PreDB</h3>', $predb);
        $this->assertSame(['Title' => 'Some.Scene.XXX.1080p-GRP', 'Source' => 'abgx', 'Pre date' => 'Sep 19, 2026, 8:30 AM', 'Category' => 'XXX'], $this->facts($predb));
    }

    public function test_similar_releases_list_today_s_search_without_a_source_column_with_dashes_for_unstored_files_and_the_row_s_own_clip(): void
    {
        $current = $this->adult('Some.Scene.XXX.1080p');
        $clip = $this->adult('Some.Scene.Part.Two.XXX.720p', ['videostatus' => 1, 'totalpart' => 0, 'resolution' => 3, 'postdate' => '2026-09-22 10:00:00']);
        $plain = $this->adult('Some.Scene.Other.XXX.2160p', ['totalpart' => 40, 'resolution' => 1, 'postdate' => '2026-09-21 10:00:00']);
        $this->excludeForUser(self::VR);
        // The search's answer is put newest posted first, and this release is left out whatever it returns.
        $this->similarIds = [$plain, $current, $clip];

        $response = $this->details($current)->assertOk();
        $this->assertSame([[$current, 'Some.Scene.XXX.1080p', [self::VR]]], $this->similarCalls);
        $similar = $this->between($response, '<section class="tv-siblings tv-similar-releases" aria-labelledby="similar-releases-heading" data-similar-releases>', '</section>');
        $this->assertStringContainsString('<h2 id="similar-releases-heading">Similar releases</h2>', $similar);
        $this->assertSame(['Release', 'Resolution', 'Size', 'Files', 'Posted', 'Actions'], $this->headings($similar));
        $this->assertStringNotContainsString('tv-col-source', $similar);
        $this->assertSame([$clip, $plain], $this->rowIds($similar));
        $this->assertSame(['posted' => 'descending'], $this->sortedHeadings($similar, 'data-similar-sort'));
        $this->assertSame(['—', '40'], $this->fileCells($similar));
        $this->assertSame(1, substr_count($similar, 'preview-badge'));
        $this->assertStringNotContainsString('clip-badge', $similar);
        $this->assertMatchesRegularExpression('/data-guid="'.$this->guid($clip).'"[^>]*data-video-url="'.preg_quote(route('preview.video', $this->guid($clip)), '/')
            .'"[^>]*data-image-title="Video preview"[^>]*title="Play the video preview"[^>]*>\s*<i class="fas fa-play" aria-hidden="true"><\/i>\s*Preview\s*<\/button>/', $similar);
        foreach (['type="checkbox"', 'Grabs', 'data-watch', 'is-current', 'pager', 'tv-show-line'] as $absent) {
            $this->assertStringNotContainsString($absent, $similar);
        }
        $this->assertSame(2, substr_count($similar, 'tv-action tv-action-slot'));

        $this->similarIds = [];
        $this->details($current)->assertOk()->assertDontSee('Similar releases')->assertDontSee('data-similar-releases', false);
    }

    public function test_the_similar_search_reads_the_adult_categories_without_the_viewer_s_excluded_ones(): void
    {
        $this->app->forgetInstance(ReleaseSearchService::class);
        $current = $this->adult('Some.Scene.XXX.1080p');
        $this->excludeForUser(self::VR);
        $queries = [];
        Search::shouldReceive('isAvailable')->andReturn(true);
        Search::shouldReceive('searchReleasePage')->andReturnUsing(function (ReleaseSearchQuery $query) use (&$queries): SearchPage {
            $queries[] = $query;

            return new SearchPage(ids: [], total: 0, fuzzy: false, driver: 'manticore');
        });

        $this->details($current)->assertOk()->assertDontSee('data-similar-releases', false);
        $this->assertCount(1, $queries);
        $this->assertSame(['searchname' => getSimilarName('Some.Scene.XXX.1080p')], $queries[0]->phrases);
        $this->assertSame([self::X264], array_values($queries[0]->categoryIds ?? []));
        $this->assertContains(self::VR, $queries[0]->excludedCategoryIds);
        $this->assertSame((int) config('nntmux.items_per_page'), $queries[0]->limit);
        $this->assertFalse($queries[0]->passwordAllowRar, 'The default password setting hides passworded releases.');
    }

    /** Another root keeps its page (TvReleaseDetailsPageTest, MovieReleaseDetailsPageTest, DetailsControllerTest). */
    public function test_a_movies_release_keeps_the_movies_page(): void
    {
        $adult = $this->adult('Some.Scene.XXX.1080p');
        $movie = $this->release('Film.2019.1080p', ['categories_id' => self::MOVIES_HD, 'movieinfo_id' => null, 'videos_id' => 0, 'tv_episodes_id' => 0, 'imdbid' => null]);

        $this->details($adult)->assertViewIs('details.adult.index');
        $this->details($movie)->assertViewIs('details.movies.index');
    }

    public function test_a_hidden_adult_category_is_refused(): void
    {
        $id = $this->adult('Hidden.Scene.XXX.1080p', ['categories_id' => self::VR]);
        $this->excludeForUser(self::VR);

        $this->details($id)->assertForbidden()->assertViewIs('errors.category-disabled')->assertDontSee('Hidden.Scene');
    }

    public function test_comments_still_post_to_the_details_url_and_return_to_the_comments_tab(): void
    {
        $id = $this->adult('Some.Scene.XXX.1080p');
        $url = '/details/'.$this->guid($id);

        $this->actingAs($this->user())->post($url, ['txtAddComment' => 'Works well.'])->assertRedirect($url.'#comments');
        $response = $this->details($id)->assertSee('Works well.');
        $this->assertSame('Comments (1)', $this->tabs((string) $response->getContent())[4]);
    }

    public function test_a_release_no_engine_will_take_reads_complete_without_a_repair_promise(): void
    {
        $id = $this->adult('Vixen.26.09.20.Nearly.Whole.XXX.1080p.MP4-GRP', ['completion' => 99]);

        $chips = $this->between($this->details($id)->assertOk(), '<div class="tv-chips tv-details-chips">', '<div class="tv-chips tv-details-origin">');
        $this->assertMatchesRegularExpression('/>\s*99% complete\s*</', $chips);
        $this->assertStringNotContainsString('still repairing', $chips);
        $this->assertStringContainsString('The site will not try to recover more of it."', $chips);
    }

    /** @param array<string, mixed> $attributes */
    private function adult(string $name, array $attributes = []): int
    {
        $number = ++$this->nextRelease;
        $posted = (string) ($attributes['postdate'] ?? '2026-09-20 10:00:00');

        return $this->release($name, ['categories_id' => self::X264, 'passwordstatus' => 0, 'resolution' => 2, 'source' => 0, 'imdbid' => null,
            'movieinfo_id' => null, 'videos_id' => 0, 'tv_episodes_id' => 0, 'completion' => 100, 'nfostatus' => 0, 'haspreview' => 0, 'jpgstatus' => 0,
            'videostatus' => 0, 'size' => self::GB, 'totalpart' => 1, 'groups_id' => 99, 'guid' => md5('adult release '.$number),
            'adddate' => Carbon::parse($posted)->addHour()->toDateTimeString(), ...$attributes, 'postdate' => $posted]);
    }

    private function image(string $type, string $basename): void
    {
        File::ensureDirectoryExists($this->covers.'/'.$type);
        File::put($this->covers.'/'.$type.'/'.$basename.'.jpg', 'jpg');
    }

    private function url(string $type, string $basename): string
    {
        return url('/covers/'.$type.'/'.$basename.'.jpg');
    }

    private function excludeForUser(int $category): void
    {
        DB::table('user_excluded_categories')->insert(['users_id' => $this->user()->id, 'categories_id' => $category]);
    }

    private function user(): User
    {
        return $this->user ??= $this->browserUser();
    }

    private function guid(int $id): string
    {
        return (string) DB::table('releases')->where('id', $id)->value('guid');
    }

    private function details(int $id): TestResponse
    {
        return $this->page('/details/'.$this->guid($id));
    }

    private function page(string $uri): TestResponse
    {
        $this->resetGlobalComposerState();

        return $this->actingAs($this->user())->get($uri);
    }

    /** The Overview's pictures block, whitespace between tags removed. */
    private function pictures(int $id): string
    {
        return (string) preg_replace(['/\s+/', '/>\s+</'], [' ', '><'], $this->between($this->details($id), '<div class="tv-details-pictures">', '</div>'));
    }

    /** @return list<string> */
    private function buttons(string $html): array
    {
        preg_match('/<div class="tv-details-actions">(.*?)<\/div>/s', $html, $match);
        preg_match_all('/<\/i>(?:<span[^>]*>)*([^<]+)/', $match[1] ?? '', $labels);

        return array_map('trim', $labels[1]);
    }

    /** @return list<string> */
    private function tabs(string $html): array
    {
        preg_match_all('/<button type="button" role="tab"[^>]*>([^<]+)<\/button>/', $html, $matches);

        return array_map('trim', $matches[1]);
    }

    /** @return array<string, string> the first facts grid's labels and values, in order */
    private function facts(string $html): array
    {
        preg_match('/<dl class="tv-details-facts">(.*?)<\/dl>/s', $html, $match);
        preg_match_all('/<dt[^>]*>([^<]+)<\/dt><dd[^>]*>([^<]*)<\/dd>/', $match[1] ?? '', $facts);

        return array_combine($facts[1], $facts[2]);
    }

    /** @return list<int> release ids in table order */
    private function rowIds(string $html): array
    {
        preg_match_all('/data-copy-nzb="([0-9a-f]{32})"/', $html, $matches);
        $ids = DB::table('releases')->whereIn('guid', $matches[1])->pluck('id', 'guid');

        return array_map(static fn (string $guid): int => (int) $ids[$guid], $matches[1]);
    }

    /** @return list<string> each row's Files cell text (the fourth cell: Release, Resolution, Size, Files) */
    private function fileCells(string $html): array
    {
        preg_match_all('/<tr data-release-row[^>]*>(.*?)<\/tr>/s', $html, $rows);

        return array_map(static function (string $row): string {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $row, $cells);

            return trim(strip_tags($cells[1][3] ?? ''));
        }, $rows[1]);
    }

    /** @return list<string> */
    private function headings(string $html): array
    {
        preg_match('/<thead>(.*?)<\/thead>/s', $html, $head);
        preg_match_all('/<th[^>]*>(.*?)<\/th>/s', $head[1] ?? '', $cells);

        return array_map(static fn (string $cell): string => trim(strip_tags($cell)), $cells[1]);
    }

    /** @return array<string, string> the sorted heading's key and direction */
    private function sortedHeadings(string $html, string $attribute): array
    {
        preg_match_all('/<th[^>]*aria-sort="([a-z]+)"[^>]*><button type="button" '.$attribute.'="([a-z]+)"/', $html, $sorted);

        return array_combine($sorted[2], $sorted[1]);
    }

    /** @param list<string> $needles */
    private function assertSeeOrder(string $html, array $needles): void
    {
        $last = -1;
        foreach ($needles as $needle) {
            $at = strpos($html, $needle, $last + 1);
            $this->assertNotFalse($at, 'Missing '.$needle);
            $last = $at;
        }
    }

    private function between(TestResponse $response, string $from, string $to): string
    {
        $html = (string) $response->getContent();
        $start = strpos($html, $from);
        $this->assertNotFalse($start, 'Missing '.$from);
        $start += strlen($from);

        return trim(substr($html, $start, strpos($html, $to, $start) - $start));
    }
}
