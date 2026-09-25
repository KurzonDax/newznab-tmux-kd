<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/** The show page, GET /tv/show/{videos_id}/{season?} (issue #779; check.mjs lines 152-153 and 272-338). */
final class TvShowPageTest extends TestCase
{
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
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
            'adddate', 'postdate', 'grabs', 'comments', 'completion', 'repair_outcome', 'rescan_outcome', 'passwordstatus', 'nfostatus',
            'haspreview', 'jpgstatus', 'groups_id', 'fromname', 'isrenamed', 'additional_pp_claim_token', 'imdbid', 'videos_id',
            'tv_episodes_id', 'musicinfo_id', 'consoleinfo_id', 'gamesinfo_id', 'bookinfo_id', 'anidbid', 'resolution', 'source']);
        foreach (['usenet_groups', 'users_releases', 'user_series', 'user_movies', 'videos', 'tv_info', 'networks', 'people', 'genres',
            'video_genres', 'video_people', 'tv_episodes', 'release_tv_episodes', 'release_audio_tags', 'release_video_clips'] as $table) {
            $tables->create($table);
        }
        DB::table('root_categories')->insert(['id' => 5000, 'title' => 'TV', 'status' => 1]);
        foreach ([self::FOREIGN => 'Foreign', self::HD => 'HD'] as $id => $title) {
            DB::table('categories')->insert(['id' => $id, 'title' => $title, 'root_categories_id' => 5000, 'status' => 1]);
        }
        DB::table('videos')->insert(['id' => self::SHOW, 'type' => 0, 'title' => 'The Glass Meridian', 'started' => '2001-03-01 00:00:00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_the_header_carries_network_year_seasons_releases_summary_tags_and_starring_but_no_premiered_tag(): void
    {
        DB::table('networks')->insert(['id' => 1, 'name' => 'Northlight TV']);
        DB::table('tv_info')->insert(['videos_id' => self::SHOW, 'summary' => 'A retired surveyor returns home.', 'publisher' => 'NLTV',
            'original_language' => 'en', 'content_rating_us' => 'TV-14', 'status' => 2, 'premiered' => '2003-05-06', 'networks_id' => 1]);
        DB::table('genres')->insert([['id' => 2, 'title' => 'Drama', 'type' => 5000, 'disabled' => 0], ['id' => 3, 'title' => 'Crime', 'type' => 5000, 'disabled' => 0]]);
        DB::table('video_genres')->insert([['videos_id' => self::SHOW, 'genres_id' => 2], ['videos_id' => self::SHOW, 'genres_id' => 3]]);
        foreach (range(1, 10) as $position) {
            DB::table('people')->insert(['id' => $position, 'name' => 'Actor '.$position]);
            DB::table('video_people')->insert(['videos_id' => self::SHOW, 'people_id' => $position, 'position' => $position]);
        }
        $this->tv(1, 1);
        $this->tv(2, 1);
        $this->tv(2, null);
        $this->tv(null, null);

        $response = $this->page('/tv/show/'.self::SHOW)->assertOk()
            ->assertSee('<h1 data-part="show page title">The Glass Meridian</h1>', false)
            ->assertSee('Northlight TV · 2003 · 2 seasons on site · 4 releases')
            ->assertSee('A retired surveyor returns home.')
            ->assertSeeInOrder(['>Crime</a>', '>Drama</a>', '>English</span>', '>TV-14</span>', '>Ended</span>'], false)
            ->assertSee('href="'.route('tv.shows', ['genre' => [2]]).'"', false)
            ->assertSee('Starring <a href="'.route('tv.shows', ['person' => 1]).'">Actor 1</a>, <a', false)
            ->assertSee('>Actor 8</a></div>', false)->assertDontSee('Actor 9')
            ->assertDontSee('Premiered')->assertDontSee('style="', false)->assertDontSee('data-select-all', false);
        $thin = $this->thinHeader();
        $this->assertStringContainsString('class="tv-show-card"', $thin);
        $this->assertStringNotContainsString('tv-show-tags', $thin);
        $this->assertStringNotContainsString('tv-starring', $thin);
    }

    public function test_the_header_falls_back_to_the_publisher_and_start_year_and_leaves_out_what_is_unknown(): void
    {
        DB::table('tv_info')->insert(['videos_id' => self::SHOW, 'summary' => '', 'publisher' => 'FX', 'content_rating_us' => 'NR']);
        $this->tv(1, 1);
        $this->page('/tv/show/'.self::SHOW)->assertSee('FX · 2001 · 1 season on site · 1 release')->assertDontSee('>NR<', false);

        DB::table('videos')->where('id', self::SHOW)->update(['started' => '0000-00-00 00:00:00']);
        DB::table('release_tv_episodes')->delete();
        $response = $this->page('/tv/show/'.self::SHOW)->assertOk();
        $this->assertSame('FX · 1 release', $this->between($response, 'data-part="show meta line">', '</div>'));
        $response->assertDontSee('aria-label="Seasons"', false)->assertSee('Other releases')->assertDontSee('Whole-season packs')
            ->assertSee('data-name="resolution"', false)->assertSee('<h2 class="sr-only">Releases</h2>', false);
    }

    public function test_season_tabs_list_specials_first_and_open_on_the_season_of_the_newest_release(): void
    {
        $this->tv(0, 1, posted: '2026-09-01 00:00:00');
        $this->tv(1, 1, posted: '2026-09-02 00:00:00');
        $this->tv(3, 2, posted: '2026-09-20 00:00:00');
        $this->tv(2, null, posted: '2026-09-10 00:00:00');
        $this->tv(null, null, posted: '2026-09-24 00:00:00');

        $response = $this->page('/tv/show/'.self::SHOW)->assertOk();
        $this->assertSame(['Specials', 'Season 1', 'Season 2', 'Season 3'], $this->tabs($response));
        $this->assertSame('Season 3', $this->currentTab($response));
        $response->assertSee('<h2 class="sr-only">Season 3</h2>', false)->assertSee('4 seasons on site');
        $this->assertSame('Season 1', $this->currentTab($this->page('/tv/show/'.self::SHOW.'/1')));
        $this->assertSame('Season 3', $this->currentTab($this->page('/tv/show/'.self::SHOW.'/9')));
        $this->page('/tv/show/'.self::SHOW.'/0')->assertSee('<h2 class="sr-only">Specials</h2>', false);
        $this->page('/tv/show/'.self::SHOW.'?resolution[]=sd')
            ->assertSee('href="'.route('tv.show', ['videosId' => self::SHOW, 'season' => 1, 'resolution' => ['sd']]).'"', false);
    }

    public function test_from_nine_seasons_the_row_reads_season_then_numbers_named_season_n(): void
    {
        foreach (range(0, 8) as $season) {
            $this->tv($season, 1);
        }
        $response = $this->page('/tv/show/'.self::SHOW.'/3')->assertOk()
            ->assertSee('class="tv-season-tabs is-many"', false)->assertSee('<span class="tv-season-label" aria-hidden="true">Season</span>', false)
            ->assertSee('title="Season 3" aria-label="Season 3"', false);
        $this->assertSame(['Specials', '1', '2', '3', '4', '5', '6', '7', '8'], $this->tabs($response));

        DB::table('release_tv_episodes')->where('season', 8)->delete();
        $this->assertSame(['Specials', 'Season 1', 'Season 2', 'Season 3', 'Season 4', 'Season 5', 'Season 6', 'Season 7'], $this->tabs($this->page('/tv/show/'.self::SHOW.'/3')));
    }

    public function test_episode_rows_come_from_what_releases_declare_newest_first_with_titles_by_min_id(): void
    {
        DB::table('tv_episodes')->insert([
            ['id' => 20, 'videos_id' => self::SHOW, 'series' => 1, 'episode' => 2, 'title' => 'Second copy', 'firstaired' => '2022-07-15', 'se_complete' => '', 'summary' => ''],
            ['id' => 10, 'videos_id' => self::SHOW, 'series' => 1, 'episode' => 2, 'title' => 'Night Old', 'firstaired' => '2022-07-14', 'se_complete' => '', 'summary' => ''],
        ]);
        $this->tv(1, 2, size: 540 * 1048576, resolution: 1);
        $this->tv(1, 2, size: (int) (1.64 * self::GB), resolution: 2);
        $this->tv(1, 5, size: (int) (2.41 * self::GB));
        $this->tv(1, 5, size: (int) (2.41 * self::GB));
        $this->tv(1, 0);
        $multi = $this->tv(1, 3);
        DB::table('release_tv_episodes')->insert(['releases_id' => $multi, 'season' => 1, 'episode' => 4]);

        $response = $this->page('/tv/show/'.self::SHOW.'/1')->assertOk();
        $this->assertSame(['E05', 'E04', 'E03', 'E02', 'E00'], $this->episodeNumbers($response));
        $response->assertSee('Night Old<small>Aired 2022-07-14</small>', false)->assertDontSee('Second copy')
            ->assertSee('>Episode 5</span>', false)->assertSee('>Episode 0</span>', false)->assertSee('>Episode 4</span>', false)
            ->assertSee('540 MB – 1.64 GB')->assertSee('<span class="tv-episode-sizes">2.41 GB</span>', false)
            ->assertSee('2 releases<i class="fas fa-chevron-down"', false)->assertSee('1 release<i class="fas fa-chevron-down"', false)
            ->assertDontSee('data-open', false)->assertDontSee('aria-expanded="true"', false)->assertDontSee('tv-release-table', false);
        $this->assertStringContainsString('resolution-chip-4k', $this->episodeRow($response, 2));
        $this->assertLessThan(strpos($this->episodeRow($response, 2), 'resolution-chip-1080'), strpos($this->episodeRow($response, 2), 'resolution-chip-4k'));
        $this->assertStringNotContainsString('click', strtolower($this->episodeRow($response, 2)));
    }

    public function test_the_episode_the_user_came_from_renders_open_with_a_box_per_row_and_no_check_all(): void
    {
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.tv']);
        $small = $this->tv(1, 2, size: 100 * 1048576, name: 'Same.Name.S01E02');
        $big = $this->tv(1, 2, size: 3 * self::GB, name: 'Same.Name.S01E02', fromname: 'poster@example.invalid');
        $this->tv(1, 1);

        $response = $this->page('/tv/show/'.self::SHOW.'/1?open=2')->assertOk();
        $open = $this->episodeRow($response, 2);
        $this->assertStringContainsString('data-open', $open);
        $this->assertStringContainsString('aria-expanded="true"', $open);
        $this->assertStringContainsString('data-part="releases button, open"', $open);
        $this->assertSame([$big, $small], $this->rowIds($open));
        $this->assertSame(2, substr_count($open, 'data-select value="'));
        $this->assertStringContainsString('Same name posted more than once · this copy by poster@example.invalid in unknown group', $open);
        $this->assertStringContainsString('<th class="tv-num" aria-sort="descending"><button type="button" data-sort="size">', $open);
        $this->assertStringNotContainsString('data-open', $this->episodeRow($response, 1));
        $response->assertDontSee('data-select-all', false);

        $fragment = $this->page('/tv/show/'.self::SHOW.'/1?_fragment=episode&episode=2')->assertOk()->getContent();
        $this->assertStringStartsWith('<table class="tv-release-table is-pick">', trim((string) $fragment));
        $this->assertSame([$big, $small], $this->rowIds((string) $fragment));
        $this->page('/tv/show/'.self::SHOW.'/1?_fragment=episode&episode=x')->assertNotFound();
    }

    public function test_packs_and_other_releases_sit_under_the_episodes_on_every_season_tab(): void
    {
        $this->tv(1, 1);
        $this->tv(2, 1);
        $pack = $this->tv(1, null, name: 'Show.S01.COMPLETE.1080p');
        $other = $this->tv(null, null, name: 'Show.Special.Behind.The.Scenes');

        $one = $this->page('/tv/show/'.self::SHOW.'/1')->assertOk()
            ->assertSeeInOrder(['data-episode="1"', 'Whole-season packs', 'Show.S01.COMPLETE.1080p', 'Other releases', 'Show.Special.Behind.The.Scenes'], false);
        $this->assertSame([$pack, $other], $this->rowIds((string) $one->getContent()));
        $this->page('/tv/show/'.self::SHOW.'/2')->assertOk()->assertSee('None on site for this season.')
            ->assertSeeInOrder(['Whole-season packs', 'Other releases', 'Show.Special.Behind.The.Scenes'])->assertDontSee('Show.S01.COMPLETE.1080p');

        DB::table('releases')->where('id', $other)->delete();
        $this->page('/tv/show/'.self::SHOW.'/1')->assertDontSee('Other releases');
    }

    public function test_resolution_and_source_filters_narrow_every_section_and_say_so_when_nothing_matches(): void
    {
        $this->tv(1, 1, resolution: 2, source: 1);
        $this->tv(1, 2, resolution: 1, source: 2);
        $this->tv(1, null, resolution: 1, source: 5, name: 'Show.S01.2160p.REMUX');
        $this->tv(null, null, resolution: 4, source: 3, name: 'Show.Extras.DVD');

        $uhd = $this->page('/tv/show/'.self::SHOW.'/1?resolution[]=4k')->assertOk()->assertSee('Show.S01.2160p.REMUX')->assertDontSee('Show.Extras.DVD');
        $this->assertSame(['E02'], $this->episodeNumbers($uhd));
        $this->assertSame(['E01'], $this->episodeNumbers($this->page('/tv/show/'.self::SHOW.'/1?source[]=web')));
        $this->assertSame(['E02'], $this->episodeNumbers($this->page('/tv/show/'.self::SHOW.'/1?source[]=bluray&resolution[]=4k')));
        $this->page('/tv/show/'.self::SHOW.'/1?source[]=bluray')->assertSee('Show.S01.2160p.REMUX');

        $empty = $this->page('/tv/show/'.self::SHOW.'/1?resolution[]=720p')->assertOk()
            ->assertSee('No releases in this season match 720p.')->assertSee('None on site for this season with your filter.')
            ->assertSee('1 season on site · 4 releases');
        $this->assertSame('Season 1', $this->currentTab($empty));
        $this->assertSame([], $this->episodeNumbers($empty));

        $list = (string) $this->page('/tv/show/'.self::SHOW.'/1?_fragment=list&resolution[]=1080p&open[]=1')->assertOk()->getContent();
        $this->assertStringNotContainsString('tv-season-bar', $list);
        $this->assertStringNotContainsString('<html', $list);
        $this->assertMatchesRegularExpression('/data-episode="1"\s+data-open/', $list);
    }

    public function test_the_user_sees_only_releases_they_may_see_and_nothing_for_a_show_they_cannot_see(): void
    {
        $this->tv(1, 1);
        $this->tv(1, 2, categories: self::FOREIGN);
        $this->tv(1, 3, password: 1);
        DB::table('videos')->insert(['id' => 8, 'type' => 0, 'title' => 'Nothing Visible']);
        $this->release('Hidden', ['categories_id' => self::FOREIGN, 'videos_id' => 8, 'passwordstatus' => 0]);

        $this->assertSame(['E02', 'E01'], $this->episodeNumbers($this->page('/tv/show/'.self::SHOW)->assertSee('2 releases')));
        $this->page('/tv/show/8')->assertOk();
        Settings::query()->updateOrInsert(['name' => 'showpasswordedrelease'], ['value' => '1']);
        Cache::flush();
        $this->assertSame(['E03', 'E02', 'E01'], $this->episodeNumbers($this->page('/tv/show/'.self::SHOW)));

        DB::table('user_excluded_categories')->insert(['users_id' => $this->user?->id, 'categories_id' => self::FOREIGN]);
        Cache::flush();
        $this->assertSame(['E03', 'E01'], $this->episodeNumbers($this->page('/tv/show/'.self::SHOW)));
        $this->page('/tv/show/8')->assertNotFound();
        $this->page('/tv/show/404')->assertNotFound();
        $this->page('/tv/show/abc')->assertNotFound();
    }

    public function test_the_back_link_follows_the_list_the_user_came_from_across_season_switches(): void
    {
        $this->tv(1, 1);
        $this->tv(2, 1);
        $show = '/tv/show/'.self::SHOW;
        $this->page($show)->assertSee('<a class="tv-back" href="'.route('tv.releases').'"><i class="fas fa-arrow-left" aria-hidden="true"></i>Releases</a>', false);

        $wall = route('tv.shows', ['page' => 2]);
        $this->withHeader('referer', $wall)->page($show)->assertSee('class="tv-back" href="'.e($wall).'"', false)->assertSee('</i>Shows</a>', false);
        $this->withHeader('referer', url($show.'/2'))->page($show.'/1')->assertSee('class="tv-back" href="'.e($wall).'"', false);
        $this->withHeader('referer', route('tv.releases', ['page' => 3]))->page($show)->assertSee('</i>Releases</a>', false)
            ->assertSee('class="tv-back" href="'.e(route('tv.releases', ['page' => 3])).'"', false);
        $this->withHeader('referer', 'https://elsewhere.example/tv/shows')->page($show)->assertSee('class="tv-back" href="'.route('tv.releases').'"', false);
    }

    public function test_the_releases_screen_show_line_leads_here_and_the_page_needs_the_tv_permission(): void
    {
        $this->tv(1, 2);
        $this->page('/tv')->assertSee('href="'.url('/tv/show/'.self::SHOW.'/1?open=2').'"', false);

        $this->user?->revokePermissionTo('view tv');
        $this->page('/tv/show/'.self::SHOW)->assertForbidden();
    }

    /** A release of the show declaring (season, episode); a null season declares nothing, a null episode the whole season. */
    private function tv(?int $season, ?int $episode, string $posted = '2026-09-20 10:00:00', int $size = 1073741824, int $resolution = 2, int $source = 1,
        int $categories = self::HD, int $password = 0, ?string $name = null, string $fromname = ''): int
    {
        $id = $this->release($name ?? 'Show.Release.'.++$this->nextRelease, ['categories_id' => $categories, 'videos_id' => self::SHOW, 'passwordstatus' => $password,
            'postdate' => $posted, 'adddate' => $posted, 'tv_episodes_id' => 0, 'size' => $size, 'resolution' => $resolution, 'source' => $source,
            'guid' => md5('release '.$this->nextRelease.' '.($name ?? '').microtime()), 'fromname' => $fromname, 'groups_id' => 99, 'completion' => 100]);
        if ($season !== null) {
            DB::table('release_tv_episodes')->insert(['releases_id' => $id, 'season' => $season, 'episode' => $episode]);
        }

        return $id;
    }

    private function page(string $uri, ?User $user = null): TestResponse
    {
        $this->resetGlobalComposerState();

        return $this->actingAs($user ?? $this->user ??= $this->browserUser())->get($uri);
    }

    /** The header of a second show with no poster and no details. */
    private function thinHeader(): string
    {
        DB::table('videos')->insert(['id' => 9, 'type' => 0, 'title' => 'Thin Show']);
        $this->release('Thin.S01E01', ['categories_id' => self::HD, 'videos_id' => 9, 'passwordstatus' => 0]);
        $covers = $this->makeTempDirectory('tv-show-posters');
        config(['nntmux_settings.covers_path' => $covers]);
        File::ensureDirectoryExists($covers.'/tvshows');

        return $this->between($this->page('/tv/show/9')->assertOk(), 'class="tv-show-head"', 'data-part="season tab bar"');
    }

    /** @return list<string> */
    private function tabs(TestResponse $response): array
    {
        preg_match_all('/<a href="[^"]*" data-season="\d+"[^>]*>([^<]+)<\/a>/', (string) $response->getContent(), $matches);

        return $matches[1];
    }

    private function currentTab(TestResponse $response): string
    {
        $this->assertSame(1, preg_match_all('/<a href="[^"]*" data-season="\d+"[^>]*aria-current="page" data-part="season tab, current"\s*>([^<]+)<\/a>/', (string) $response->getContent(), $matches));

        return $matches[1][0];
    }

    /** @return list<string> */
    private function episodeNumbers(TestResponse $response): array
    {
        preg_match_all('/<span class="tv-episode-number"[^>]*>(E\d+)<\/span>/', (string) $response->getContent(), $matches);

        return $matches[1];
    }

    private function episodeRow(TestResponse $response, int $episode): string
    {
        $html = (string) $response->getContent();
        $start = strpos($html, '<div class="tv-episode" data-episode="'.$episode.'"');
        $this->assertNotFalse($start, 'No row for episode '.$episode);
        $end = strpos($html, '<div class="tv-episode" ', $start + 10);

        return substr($html, $start, ($end === false ? strpos($html, '<section', $start) : $end) - $start);
    }

    /** @return list<int> release ids in table order */
    private function rowIds(string $html): array
    {
        preg_match_all('/data-select value="([0-9a-f]{32})"/', $html, $matches);
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
