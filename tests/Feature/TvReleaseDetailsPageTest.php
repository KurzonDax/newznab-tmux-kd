<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\Settings;
use App\Models\User;
use App\Services\NNTP\NntpProviderPool;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\AssertsFollowWording;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\InteractsWithSecondaryProviders;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/** The TV release details page, GET /details/{guid} for a TV release (issue #780; check.mjs lines 162-199). */
final class TvReleaseDetailsPageTest extends TestCase
{
    use AssertsFollowWording;
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use InteractsWithSecondaryProviders;
    use IsolatedSqliteDatabase;

    private const HD = 5040;

    private const FOREIGN = 5020;

    private const SHOW = 7;

    private const GB = 1073741824;

    private ?User $user = null;

    private int $nextRelease = 0;

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
            'haspreview', 'jpgstatus', 'videostatus', 'groups_id', 'fromname', 'isrenamed', 'additional_pp_claim_token', 'imdbid', 'videos_id',
            'tv_episodes_id', 'musicinfo_id', 'consoleinfo_id', 'gamesinfo_id', 'bookinfo_id', 'anidbid', 'movieinfo_id', 'predb_id', 'resolution', 'source']);
        foreach (['usenet_groups', 'users_releases', 'user_series', 'user_movies', 'videos', 'tv_info', 'networks', 'people', 'genres',
            'video_genres', 'video_people', 'tv_episodes', 'release_tv_episodes', 'release_audio_tags', 'release_video_clips', 'languages', 'release_audio_languages',
            'releases_groups', 'release_regexes', 'release_comments', 'release_nfos', 'video_data', 'audio_data', 'release_subtitles', 'media_infos', 'media_info_probes', 'media_info_tracks'] as $table) {
            $tables->create($table);
        }
        DB::table('root_categories')->insert(['id' => 5000, 'title' => 'TV', 'status' => 1]);
        foreach ([self::FOREIGN => 'Foreign', self::HD => 'HD'] as $id => $title) {
            DB::table('categories')->insert(['id' => $id, 'title' => $title, 'root_categories_id' => 5000, 'status' => 1]);
        }
        DB::table('usenet_groups')->insert(['id' => 99, 'name' => 'alt.binaries.example.tv']);
        DB::table('videos')->insert(['id' => self::SHOW, 'type' => 0, 'title' => 'The Glass Meridian', 'started' => '2001-03-01 00:00:00']);
        DB::table('networks')->insert(['id' => 1, 'name' => 'Northlight TV']);
        DB::table('tv_info')->insert(['videos_id' => self::SHOW, 'summary' => 'A retired surveyor returns home.', 'publisher' => 'NLTV',
            'original_language' => 'en', 'content_rating_us' => 'TV-14', 'status' => 2, 'premiered' => '2003-05-06', 'networks_id' => 1]);
        DB::table('genres')->insert(['id' => 2, 'title' => 'Drama', 'type' => 5000, 'disabled' => 0]);
        DB::table('video_genres')->insert(['videos_id' => self::SHOW, 'genres_id' => 2]);
        DB::table('people')->insert(['id' => 1, 'name' => 'Lucia Castellanos']);
        DB::table('video_people')->insert(['videos_id' => self::SHOW, 'people_id' => 1, 'position' => 1]);
        DB::table('tv_episodes')->insert(['id' => 1, 'videos_id' => self::SHOW, 'series' => 7, 'episode' => 5, 'se_complete' => 'S07E05',
            'title' => 'Shift Ends', 'firstaired' => '2025-11-16', 'summary' => '']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        NntpProviderPool::forgetConfiguredProviders();
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_the_page_has_the_heading_chips_four_buttons_five_tabs_and_the_facts(): void
    {
        $id = $this->tv(7, 5, name: 'The.Glass.Meridian.S07E05.720p.WEB-DL.DDP5.1.H.264-PLAiD', resolution: 3, fromname: 'paperboat <pb@example.invalid>');
        DB::table('releases')->where('id', $id)->update(['grabs' => 38, 'nfostatus' => 1]);
        DB::table('release_nfos')->insert(['releases_id' => $id, 'nfo' => 'NFO']);

        $response = $this->details($id)->assertOk();
        $html = (string) $response->getContent();
        $this->assertMatchesRegularExpression('/<button[^>]*aria-controls="nav-menu-tv"\s+aria-current="true"/', $html);

        $response->assertSee('<h1 data-part="details heading"><a href="'.url('/tv/show/'.self::SHOW.'/7?open=5').'">The Glass Meridian</a> · S07E05 — Shift Ends</h1>', false)
            ->assertSee('<div class="tv-details-name" data-part="details release name">The.Glass.Meridian.S07E05.720p.WEB-DL.DDP5.1.H.264-PLAiD</div>', false)
            ->assertSee('resolution-chip-720', false)->assertSee('<span class="tv-source-chip">WEB</span>', false)
            ->assertSee('class="tv-details-art" href="'.route('tv.show', ['videosId' => self::SHOW]).'"', false)
            ->assertSeeInOrder(['TV releases</a>', 'The Glass Meridian</a>', '<span>TV &gt; HD</span>'], false)
            ->assertSee('href="'.route('browse.all', ['group' => 'alt.binaries.example.tv']).'"', false)->assertSee('a.b.example.tv')
            ->assertSee('href="'.route('browse.all', ['poster' => 'paperboat <pb@example.invalid>']).'"', false)
            ->assertSee('nfo-badge', false)->assertSee('data-has-nfo="1"', false)->assertDontSee('Report')->assertDontSee('style="', false);
        $this->assertSame(['Download NZB', 'Copy NZB link', 'Add to cart', 'Follow show'], $this->buttons($html));
        $this->assertNoWatchWording($html, 'The TV details page');
        $response->assertSee('x-on:click.self="close()"', false)->assertSee('aria-label="Close"', false);
        $this->assertSame(['Overview', 'Files (1)', 'Media info', 'NFO', 'Comments (0)'], $this->tabs($html));
        $this->assertSame([
            'Category' => 'TV &gt; HD', 'Size' => '1.00 GB', 'Files' => '1', 'Completion' => '100%', 'Posted' => 'Sep 20, 2026, 10:00 AM',
            'Added' => 'Sep 20, 2026, 11:00 AM', 'Grabs' => '38', 'Group' => 'alt.binaries.example.tv', 'Poster' => 'paperboat &lt;pb@example.invalid&gt;',
            'Password status' => 'None detected',
        ], $this->facts($html));
        $response->assertSee('<span>Aired 2025-11-16</span>', false)
            ->assertSee('data-part="tab, current"', false)->assertSee('data-part="details primary button"', false);
    }

    public function test_the_header_buttons_match_the_movies_details_page_download_coral_copy_and_cart_neutral_follow_show_violet(): void
    {
        $id = $this->tv(7, 5);
        $guid = (string) DB::table('releases')->where('id', $id)->value('guid');
        $picker = route('watchlist.picker', ['root' => 'tv', 'id' => self::SHOW]);

        $actions = $this->between($this->details($id)->assertOk(), '<div class="tv-details-actions">', '</div>');
        $this->assertSame('<a class="tv-details-button download-nzb" href="'.route('getnzb.guid', $guid).'" data-part="details primary button"><i class="fas fa-download" aria-hidden="true"></i>Download NZB</a>'
            .'<button type="button" class="tv-details-button is-secondary" data-copy-nzb="'.$guid.'" data-part="details secondary button"><i class="fas fa-link" aria-hidden="true"></i>Copy NZB link</button>'
            .'<button type="button" class="tv-details-button is-secondary" data-cart="'.$guid.'" data-cart-label aria-pressed="false" title="Add to cart"><i class="fas fa-cart-shopping" aria-hidden="true"></i>'
            .'<span class="tv-state-label"><span class="is-off">Add to cart</span><span class="is-on">In cart</span></span></button>'
            .'<button type="button" class="tv-details-button tv-follow-show" data-watch-picker="'.$picker.'" data-watch-key="tv:'.self::SHOW.'" data-watch-title="The Glass Meridian" data-watch-off-title="Follow this show" data-watch-on-title="Following this show · click to unfollow" data-watched="0" aria-pressed="false" title="Follow this show">'
            .'<i class="far fa-bookmark" aria-hidden="true"></i><span class="tv-state-label"><span class="is-off">Follow show</span><span class="is-on">Following show</span></span></button>',
            (string) preg_replace('/>\s+</', '><', $actions));

        DB::table('users_releases')->insert(['users_id' => $this->user()->id, 'releases_id' => $id]);
        DB::table('user_series')->insert(['users_id' => $this->user()->id, 'videos_id' => self::SHOW, 'categories' => '5040']);
        $pressed = $this->between($this->details($id), '<div class="tv-details-actions">', '</div>');
        $this->assertStringContainsString('data-cart-label aria-pressed="true" title="In cart · click to remove"><i class="fas fa-cart-shopping" aria-hidden="true"></i>', $pressed);
        $this->assertStringContainsString('data-watched="1" aria-pressed="true" title="Following this show · click to unfollow"><i class="fas fa-bookmark" aria-hidden="true"></i>', $pressed);
        $this->assertNoWatchWording((string) $this->details($id)->getContent(), 'A followed TV details page');
        $this->assertStringNotContainsString('fa-check', $pressed);
    }

    public function test_about_the_show_has_no_year_on_its_line_but_keeps_the_premiered_tag(): void
    {
        $this->tv(6, 1);
        $id = $this->tv(7, 5);

        $about = $this->between($this->details($id), '<aside class="tv-about">', '</aside>');

        $this->assertStringContainsString('<div class="tv-about-meta">Northlight TV · 2 seasons on site</div>', $about);
        $this->assertStringNotContainsString('2003 ·', $about);
        $this->assertStringContainsString('<span class="tv-tag tv-tag-plain">Premiered 2003</span>', $about);
        $this->assertStringContainsString('<a class="tv-tag" href="'.route('tv.shows', ['genre' => [2]]).'">Drama</a>', $about);
        $this->assertStringContainsString('Starring <a href="'.route('tv.shows', ['person' => 1]).'">Lucia Castellanos</a>', $about);
        $this->assertStringContainsString('<a class="tv-about-link" href="'.url('/tv/show/'.self::SHOW.'/7?open=5').'">All seasons and episodes</a>', $about);
    }

    public function test_the_episode_table_lists_the_releases_sharing_the_episode_largest_first_with_this_one_marked_and_no_boxes(): void
    {
        $small = $this->tv(7, 5, size: self::GB, name: 'The.Glass.Meridian.S07E05.1080p');
        $large = $this->tv(7, 5, size: 3 * self::GB, name: 'The.Glass.Meridian.S07E05.1080p');
        $mine = $this->tv(7, 5, size: 2 * self::GB);
        $this->tv(7, 6);
        $this->tv(7, null);
        $this->tv(7, 5, categories: self::FOREIGN);
        DB::table('user_excluded_categories')->insert(['users_id' => $this->user()->id, 'categories_id' => self::FOREIGN]);

        $response = $this->details($mine);
        $table = $this->between($response, '<section class="tv-siblings" x-ref="siblings">', '</section>');

        $this->assertStringContainsString('<h2 data-part="episode releases heading">All 3 releases of this episode</h2>', $table);
        $this->assertSame([$large, $mine, $small], $this->rowIds($table));
        $this->assertStringNotContainsString('type="checkbox"', $table);
        $this->assertSame(1, substr_count($table, 'aria-current="true"'));
        $this->assertSame(1, substr_count($table, 'The release on this page'));
        $guid = (string) DB::table('releases')->where('id', $mine)->value('guid');
        $this->assertStringNotContainsString('href="'.route('details', $guid).'"', $table);
        $this->assertSame(2, substr_count($table, 'href="'.url('/details/')));
        $this->assertSame(3, substr_count($table, 'data-copy-nzb='));
        $this->assertStringContainsString('<button type="button" data-sort="grabs">Grabs', $table);
        $this->assertSame(3, substr_count($table, 'data-grabs="'));
        $this->assertStringContainsString('<col class="tv-col-count">', $table);
        $this->assertSame(3, substr_count($table, 'data-cart="'));
        $this->assertStringNotContainsString('data-watch-picker', $table);
        $this->assertStringNotContainsString('Same name posted more than once', $table);
    }

    public function test_a_pack_lists_the_season_packs_and_a_release_that_declares_nothing_has_no_table(): void
    {
        $pack = $this->tv(7, null);
        $this->tv(7, null);
        $this->tv(7, 5);
        $none = $this->tv(null, null);

        $this->details($pack)->assertSee('All 2 releases of this season pack')->assertSee('The Glass Meridian</a> · Season 7 pack</h1>', false);
        $this->details($none)->assertDontSee('tv-siblings')->assertSee('>All releases of this show</a>', false)
            ->assertSee('<h1 data-part="details heading"><a href="'.url('/tv/show/'.self::SHOW).'">The Glass Meridian</a></h1>', false);
        $this->details($this->tv(8, 1))->assertSee('The only release of this episode')->assertSee('The Glass Meridian</a> · S08E01</h1>', false);
    }

    public function test_a_release_with_no_matched_show_has_no_show_parts(): void
    {
        $id = $this->release('Unmatched.Show.S01E01.1080p', ['categories_id' => self::HD, 'videos_id' => 0, 'passwordstatus' => 0, 'groups_id' => 99,
            'guid' => md5('unmatched'), 'completion' => 100]);
        DB::table('release_tv_episodes')->insert(['releases_id' => $id, 'season' => 1, 'episode' => 1]);

        $response = $this->details($id)->assertOk()
            ->assertSee('<h1 class="is-release-name" data-part="details heading">Unmatched.Show.S01E01.1080p</h1>', false)
            ->assertSee('tv-details-head is-release-only', false)->assertSee('tv-details-columns is-release-only', false)
            ->assertDontSee('tv-details-art')->assertDontSee('tv-about')->assertDontSee('tv-siblings')->assertDontSee('data-watch-picker', false);
        $this->assertSame(['Download NZB', 'Copy NZB link', 'Add to cart'], $this->buttons((string) $response->getContent()));
        $this->assertSame('<a href="'.route('tv.releases').'">TV releases</a><span aria-hidden="true">›</span>', $this->between($response, 'aria-label="Breadcrumb">', '<span>TV'));
    }

    public function test_the_media_info_chip_leads_with_the_release_resolution_and_the_page_uses_the_tv_dialogs(): void
    {
        $id = $this->tv(7, 5, resolution: 2);
        DB::table('video_data')->insert(['releases_id' => $id, 'videoformat' => 'HEVC', 'videocodec' => 'V_MPEGH/ISO/HEVC', 'videowidth' => 1920, 'videoheight' => 1080]);
        DB::table('audio_data')->insert(['releases_id' => $id, 'audioid' => 1, 'audioformat' => 'E-AC-3', 'audiochannels' => '6']);

        $this->details($id)->assertSee('1080p · H.265 · E-AC-3 5.1')->assertSee('data-has-media="1"', false)
            ->assertSee('x-data="tvFilesDialog"', false)->assertSee('x-data="tvImageDialog"', false)->assertSee('x-data="mediainfoModal"', false)
            ->assertSee('x-data="nfoModal"', false)->assertDontSee('x-data="filelistModal"', false)->assertDontSee('x-data="previewModal"', false)
            ->assertDontSee('releaseReport', false)
            ->assertSeeInOrder(['Copy text', 'Download .nfo', 'Details', 'Download NZB'], false);
        $this->page('/tv')->assertSee('x-data="tvImageDialog"', false)->assertDontSee('x-data="previewModal"', false);
    }

    public function test_comments_still_post_to_the_details_url_and_return_to_the_comments_tab(): void
    {
        $id = $this->tv(7, 5);
        $url = '/details/'.DB::table('releases')->where('id', $id)->value('guid');

        $this->actingAs($this->user())->post($url, ['txtAddComment' => 'Works well.'])->assertRedirect($url.'#comments');
        $response = $this->details($id)->assertSee('Works well.');
        $this->assertSame('Comments (1)', $this->tabs((string) $response->getContent())[4]);
    }

    public function test_the_completion_chip_promises_recovery_only_while_an_engine_can_still_take_the_release(): void
    {
        $cases = [
            'above the target' => [['completion' => 99], false],
            'at the target' => [['completion' => 95], false],
            'nothing to rescan' => [['completion' => 80, 'repair_outcome' => 'failed', 'declaredfiles' => 10, 'totalpart' => 12], false],
            'rescan owed' => [['completion' => 80, 'repair_outcome' => 'failed', 'declaredfiles' => null, 'totalpart' => 12], true],
            'no verdict' => [['completion' => 80], true],
            'no NZB' => [['completion' => 80, 'nzbstatus' => 0], false],
        ];
        foreach ($cases as $label => [$columns, $repairing]) {
            $id = $this->tv(1, 1);
            DB::table('releases')->where('id', $id)->update($columns);
            $chips = $this->chips($id);
            $percent = (int) $columns['completion'];
            if ($repairing) {
                $this->assertMatchesRegularExpression('/>\s*'.$percent.'% complete · still repairing\s*</', $chips, $label);
                $this->assertStringContainsString('The site may still recover more of it."', $chips, $label);
            } else {
                $this->assertMatchesRegularExpression('/>\s*'.$percent.'% complete\s*</', $chips, $label);
                $this->assertStringNotContainsString('still repairing', $chips, $label);
                $this->assertStringContainsString('The site will not try to recover more of it."', $chips, $label);
            }
        }
    }

    public function test_the_repair_target_comes_from_the_completionpercent_setting(): void
    {
        Settings::query()->updateOrInsert(['name' => 'completionpercent'], ['value' => '99']);
        $id = $this->tv(1, 1);
        DB::table('releases')->where('id', $id)->update(['completion' => 97]);

        $this->assertMatchesRegularExpression('/>\s*97% complete · still repairing\s*</', $this->chips($id));
    }

    public function test_a_secondary_provider_still_reading_the_post_holds_the_label_until_its_position_passes_it(): void
    {
        $this->configureSecondaryProvider();
        ProductionTables::fromAuthority()->create('usenet_group_provider_cursors');
        DB::table('usenet_groups')->where('id', 99)->update(['active' => 1]);
        $above = $this->tv(1, 1);
        DB::table('releases')->where('id', $above)->update(['completion' => 99]);
        $finished = $this->tv(1, 2);
        DB::table('releases')->where('id', $finished)->update(['completion' => 80, 'repair_outcome' => 'failed', 'declaredfiles' => 1]);
        // The releases are posted at 10:00 and delaytime is unset, so the window closes at 12:00.
        $this->secondaryPosition(99, '2026-09-20 11:00:00');
        foreach ([$above => 99, $finished => 80] as $id => $percent) {
            $this->assertMatchesRegularExpression('/>\s*'.$percent.'% complete · still repairing\s*</', $this->chips($id));
        }

        $this->secondaryPosition(99, '2026-09-20 13:00:00');
        foreach ([$above => 99, $finished => 80] as $id => $percent) {
            $this->assertMatchesRegularExpression('/>\s*'.$percent.'% complete\s*</', $this->chips($id));
        }
    }

    /** The chip line of a release's details page. */
    private function chips(int $id): string
    {
        return $this->between($this->details($id)->assertOk(), '<div class="tv-chips tv-details-chips">', '</div>');
    }

    /** A release of the show declaring (season, episode); a null season declares nothing, a null episode the whole season. */
    private function tv(?int $season, ?int $episode, int $size = self::GB, int $resolution = 2, int $categories = self::HD, ?string $name = null, string $fromname = ''): int
    {
        $number = ++$this->nextRelease;
        $id = $this->release($name ?? 'Show.Release.'.$number, ['categories_id' => $categories, 'videos_id' => self::SHOW, 'passwordstatus' => 0,
            'postdate' => '2026-09-20 10:00:00', 'adddate' => '2026-09-20 11:00:00', 'tv_episodes_id' => 0, 'size' => $size, 'resolution' => $resolution,
            'source' => 1, 'guid' => md5('release '.$number), 'fromname' => $fromname, 'groups_id' => 99, 'completion' => 100, 'totalpart' => 1]);
        if ($season !== null) {
            DB::table('release_tv_episodes')->insert(['releases_id' => $id, 'season' => $season, 'episode' => $episode]);
        }

        return $id;
    }

    private function user(): User
    {
        return $this->user ??= $this->browserUser();
    }

    private function details(int $id): TestResponse
    {
        return $this->page('/details/'.DB::table('releases')->where('id', $id)->value('guid'));
    }

    private function page(string $uri): TestResponse
    {
        $this->resetGlobalComposerState();
        // Each render is a request of its own: nothing a request resolved survives into the next.
        $this->app->forgetScopedInstances();

        return $this->actingAs($this->user())->get($uri);
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

    /** @return array<string, string> the Overview facts, label => value, in order */
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

    private function between(TestResponse $response, string $from, string $to): string
    {
        $html = (string) $response->getContent();
        $start = strpos($html, $from);
        $this->assertNotFalse($start, 'Missing '.$from);
        $start += strlen($from);

        return trim(substr($html, $start, strpos($html, $to, $start) - $start));
    }
}
