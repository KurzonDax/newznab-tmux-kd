<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\Settings;
use App\Models\User;
use App\Services\Releases\ReleaseBrowseService;
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

/** The TV shows wall, GET /tv/shows, and the TV search, GET /tv/search (issue #778; check.mjs lines 201-270). */
final class TvShowsPageTest extends TestCase
{
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    private const HD = 5040;

    private const FOREIGN = 5020;

    private const COMEDY = 1;

    private const DRAMA = 2;

    private const SCI_FI = 3;

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
        DB::table('genres')->insert([
            ['id' => self::COMEDY, 'title' => 'Comedy', 'type' => 5000, 'disabled' => 0],
            ['id' => self::DRAMA, 'title' => 'Drama', 'type' => 5000, 'disabled' => 0],
            ['id' => self::SCI_FI, 'title' => 'Sci-Fi', 'type' => 5000, 'disabled' => 0],
            ['id' => 9, 'title' => 'Western', 'type' => 2000, 'disabled' => 0],
        ]);
        DB::table('networks')->insert([['id' => 1, 'name' => 'HBO'], ['id' => 2, 'name' => 'abc'], ['id' => 3, 'name' => 'Channel 4']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_the_wall_lists_shows_with_a_release_newest_release_first_42_per_page(): void
    {
        foreach (range(1, 45) as $id) {
            $this->show($id, sprintf('Show %02d', $id));
            $this->tv($id, sprintf('2026-09-20 00:%02d:00', $id));
        }
        $this->show(99, 'No Releases Yet');

        $response = $this->page('/tv/shows')->assertOk()
            ->assertSee('<h1 data-part="page title">TV shows</h1>', false)
            ->assertSee('aria-current="page" data-part="view switch, current">Shows</a>', false)
            ->assertSee('Showing 1–42 of 45 shows')->assertSee('Page 1 of 2')
            ->assertDontSee('No Releases Yet')->assertDontSee('data-name="resolution"', false)->assertDontSee('data-name="source"', false)
            ->assertDontSee('style="', false);
        $this->assertCount(42, $this->tileIds($response));
        $this->assertSame([45, 44, 43, 42, 41, 40, 39, 38, 37, 36], array_slice($this->tileIds($response), 0, 10));
        $this->assertSame(2, substr_count((string) $response->getContent(), 'aria-label="Pages"'));
        $response->assertSee('href="'.url('/tv/show/45').'"', false);

        $second = $this->page('/tv/shows?page=2')->assertOk()->assertSee('Showing 43–45 of 45 shows');
        $this->assertCount(3, $this->tileIds($second));
        $this->page('/tv/shows?page=9')->assertRedirect(route('tv.shows', ['page' => 2]));
    }

    public function test_tiles_show_year_genres_language_and_rating_and_a_show_without_details_gets_the_thin_tile(): void
    {
        $this->show(1, 'Glass Meridian', started: '2001-03-01 00:00:00', details: ['premiered' => '2004-05-06', 'original_language' => 'ko',
            'content_rating_us' => 'TV-14', 'status' => 1, 'networks_id' => 1], genres: [self::SCI_FI, self::DRAMA, self::COMEDY]);
        $this->show(2, 'Unrated Show', details: ['original_language' => 'en', 'content_rating_us' => 'NR']);
        $this->show(3, 'Thin Show', started: '1999-01-01 00:00:00');
        foreach ([1, 2, 3] as $id) {
            $this->tv($id);
        }
        $covers = $this->makeTempDirectory('tv-wall-posters');
        config(['nntmux_settings.covers_path' => $covers]);
        File::ensureDirectoryExists($covers.'/tvshows');
        File::put($covers.'/tvshows/1.webp', 'poster');

        $response = $this->page('/tv/shows')->assertOk();
        $glass = $this->tile($response, 1);
        $this->assertStringContainsString('src="'.url('/covers/tvshows/1.webp').'"', $glass);
        $this->assertStringContainsString('>2004 · Comedy, Drama<', $glass);
        $this->assertStringContainsString('>Korean · TV-14<', $glass);
        $this->assertStringNotContainsString('chip', $glass);
        $this->assertStringContainsString('>English<', $this->tile($response, 2));
        $thin = $this->tile($response, 3);
        $this->assertStringNotContainsString('<img', $thin);
        $this->assertStringContainsString('tv-tile-card', $thin);
        $this->assertStringContainsString('>1999<', $thin);
    }

    public function test_filter_menus_list_only_values_of_listed_shows_in_the_prototype_order(): void
    {
        $this->show(1, 'A', details: ['premiered' => '2004-01-01', 'original_language' => 'ko', 'content_rating_us' => 'TV-MA', 'status' => 2, 'networks_id' => 1], genres: [self::SCI_FI]);
        $this->show(2, 'B', details: ['premiered' => '2019-01-01', 'original_language' => 'en', 'content_rating_us' => 'TV-Y7', 'status' => 1, 'networks_id' => 2], genres: [self::DRAMA]);
        $this->show(3, 'C', started: '1987-01-01 00:00:00', details: ['original_language' => 'en', 'content_rating_us' => 'NR', 'networks_id' => 3], genres: [self::COMEDY]);
        $this->show(4, 'Hidden', details: ['premiered' => '1950-01-01', 'original_language' => 'fr', 'content_rating_us' => 'TV-G'], genres: [self::DRAMA]);
        $this->tv(1);
        $this->tv(2);
        $this->tv(3);
        $this->tv(4, categories: self::FOREIGN);
        $user = $this->browserUser();
        DB::table('user_excluded_categories')->insert(['users_id' => $user->id, 'categories_id' => self::FOREIGN]);

        $options = $this->page('/tv/shows', $user)->assertOk()->viewData('options');
        $this->assertSame([self::COMEDY => 'Comedy', self::DRAMA => 'Drama', self::SCI_FI => 'Sci-Fi'], $options['genre']);
        $this->assertSame(['2010' => '2010s', '2000' => '2000s', '1980' => '1980s'], $options['decade']);
        $this->assertSame(['en' => 'English', 'ko' => 'Korean'], $options['language']);
        $this->assertSame([2 => 'abc', 3 => 'Channel 4', 1 => 'HBO'], $options['network']);
        $this->assertSame(['TV-Y7' => 'TV-Y7', 'TV-MA' => 'TV-MA'], $options['rating']);
        $this->assertSame(['running' => 'Running', 'ended' => 'Ended'], $options['status']);
        $this->page('/tv/shows', $user)->assertSeeInOrder(['Any genre', 'Any decade', 'Any language', 'Any network', 'Any rating', 'Any status'])
            ->assertSee('Genre: any')->assertSee('Premiered: any')->assertDontSee('Western');
    }

    public function test_any_ticked_value_within_a_filter_matches_and_filters_combine(): void
    {
        $this->show(1, 'Drama English', details: ['original_language' => 'en', 'status' => 2, 'premiered' => '2004-01-01', 'content_rating_us' => 'TV-14', 'networks_id' => 1], genres: [self::DRAMA]);
        $this->show(2, 'Comedy English', details: ['original_language' => 'en', 'status' => 1, 'premiered' => '2011-01-01', 'content_rating_us' => 'TV-MA', 'networks_id' => 2], genres: [self::COMEDY]);
        $this->show(3, 'Drama Korean', details: ['original_language' => 'ko', 'status' => 2, 'premiered' => '2008-01-01', 'content_rating_us' => 'TV-14', 'networks_id' => 1], genres: [self::DRAMA, self::SCI_FI]);
        $this->show(4, 'Sci-Fi Only', details: ['original_language' => 'en', 'status' => 1, 'premiered' => '1995-01-01', 'networks_id' => 3], genres: [self::SCI_FI]);
        foreach ([1, 2, 3, 4] as $id) {
            $this->tv($id);
        }

        $this->assertWall('/tv/shows?genre[]='.self::DRAMA, ['Drama English', 'Drama Korean']);
        $this->assertWall('/tv/shows?genre[]='.self::DRAMA.'&genre[]='.self::COMEDY, ['Comedy English', 'Drama English', 'Drama Korean']);
        $this->assertWall('/tv/shows?genre[]='.self::DRAMA.'&genre[]='.self::COMEDY.'&language[]=en', ['Comedy English', 'Drama English']);
        $this->assertWall('/tv/shows?decade[]=2000', ['Drama English', 'Drama Korean']);
        $this->assertWall('/tv/shows?decade[]=2000&decade[]=1990', ['Drama English', 'Drama Korean', 'Sci-Fi Only']);
        $this->assertWall('/tv/shows?network[]=1', ['Drama English', 'Drama Korean']);
        $this->assertWall('/tv/shows?rating[]=TV-MA', ['Comedy English']);
        $this->assertWall('/tv/shows?status[]=ended&language[]=en', ['Drama English']);
        $this->assertWall('/tv/shows?status[]=bogus&genre[]=99', ['Comedy English', 'Drama English', 'Drama Korean', 'Sci-Fi Only']);

        $one = $this->page('/tv/shows?genre[]='.self::DRAMA)->assertSee('Showing 1–2 of 2 shows');
        $this->assertMatchesRegularExpression('/class="checkbox-menu is-fixed is-set"[^>]*data-name="genre"/', (string) $one->getContent());
        $one->assertSee('Genre: Drama')->assertSee('title="Genre: Drama"', false)->assertSee('data-clear-all aria-hidden="false"', false);
        $this->page('/tv/shows?genre[]='.self::DRAMA.'&genre[]='.self::COMEDY)->assertSee('Genre: 2 chosen')->assertSee('title="Genre: Comedy, Drama"', false);
        $this->page('/tv/shows')->assertSee('data-clear-all aria-hidden="true" tabindex="-1"', false);
    }

    public function test_the_person_filter_narrows_the_wall_and_its_chip_removes_it(): void
    {
        $this->show(1, 'With Her');
        $this->show(2, 'Also With Her', genres: [self::DRAMA]);
        $this->show(3, 'Without Her', genres: [self::DRAMA]);
        foreach ([1, 2, 3] as $id) {
            $this->tv($id);
        }
        DB::table('people')->insert([['id' => 7, 'name' => 'Ada Quill', 'tmdb_id' => 70]]);
        DB::table('video_people')->insert([['videos_id' => 1, 'people_id' => 7, 'position' => 0], ['videos_id' => 2, 'people_id' => 7, 'position' => 3]]);

        $response = $this->page('/tv/shows?person=7')->assertOk()->assertSee('Starring Ada Quill')
            ->assertSee('href="'.route('tv.shows').'" data-remove-person aria-label="Remove Ada Quill"', false)
            ->assertSee('data-clear-all aria-hidden="false"', false);
        $this->assertSame([1, 2], $this->sortedTileIds($response));
        $this->assertSame([2], $this->sortedTileIds($this->page('/tv/shows?person=7&genre[]='.self::DRAMA)));
        $this->page('/tv/shows?person=7&genre[]='.self::DRAMA)
            ->assertSee('href="'.route('tv.shows', ['genre' => [self::DRAMA]]).'" data-remove-person aria-label="Remove Ada Quill"', false);
        $this->page('/tv/shows?person=999')->assertDontSee('Starring')->assertSee('Showing 1–3 of 3 shows');
    }

    public function test_a_show_is_listed_only_when_the_user_may_see_one_of_its_releases(): void
    {
        $this->show(1, 'Visible Show');
        $this->show(2, 'Only Foreign');
        $this->show(3, 'Only Passworded');
        $this->show(4, 'Only A Movie');
        $this->tv(1);
        $this->tv(2, categories: self::FOREIGN);
        $this->tv(3, password: 1);
        $this->tv(4, categories: 2030);
        $user = $this->browserUser();
        DB::table('user_excluded_categories')->insert(['users_id' => $user->id, 'categories_id' => self::FOREIGN]);

        $this->assertSame([1], $this->sortedTileIds($this->page('/tv/shows', $user)->assertSee('Showing 1–1 of 1 show')));
        Settings::query()->updateOrInsert(['name' => 'showpasswordedrelease'], ['value' => '1']);
        Cache::flush();
        $this->assertSame([1, 3], $this->sortedTileIds($this->page('/tv/shows', $user)->assertSee('Showing 1–2 of 2 shows')));
    }

    public function test_the_count_is_cached_under_the_browse_version_and_the_list_fragment_is_the_list_alone(): void
    {
        $this->show(1, 'First');
        $this->show(2, 'Second');
        $this->tv(1);
        $this->page('/tv/shows')->assertSee('Showing 1–1 of 1 show');
        $this->tv(2);
        $this->page('/tv/shows')->assertSee('Showing 1–1 of 1 show');
        ReleaseBrowseService::bumpCacheVersion();
        $this->page('/tv/shows')->assertSee('Showing 1–2 of 2 shows');

        $fragment = (string) $this->page('/tv/shows?_fragment=list')->assertOk()->assertSee('Showing 1–2 of 2 shows')->getContent();
        $this->assertStringNotContainsString('<html', $fragment);
        $this->assertStringNotContainsString('checkbox-menu', $fragment);
        $this->assertStringContainsString('data-show="1"', $fragment);
    }

    public function test_the_four_sorts_order_the_wall_and_the_chosen_one_is_remembered(): void
    {
        $this->show(1, 'Bravo', details: ['premiered' => '2010-01-01']);
        $this->show(2, 'Alpha', started: '2020-01-01 00:00:00');
        $this->show(3, 'Charlie', details: ['premiered' => '2015-01-01']);
        $this->tv(1, '2026-09-20 00:00:00', '2026-09-01 00:00:00');
        $this->tv(1, '2026-09-24 00:00:00', '2026-09-24 00:00:00');
        $this->tv(2, '2026-09-22 00:00:00', '2026-09-10 00:00:00');
        $this->tv(3, '2026-09-10 00:00:00', '2026-09-05 00:00:00');
        $user = $this->browserUser();

        $this->assertSame([1, 2, 3], $this->tileIds($this->page('/tv/shows', $user)->assertSee('<option value="recent" selected', false)
            ->assertSeeInOrder(['Newest releases first', 'Newest to the site first', 'Newest premiere first', 'A to Z'])));
        foreach (['newsite' => [2, 3, 1], 'prem' => [2, 3, 1], 'az' => [2, 1, 3], 'recent' => [1, 2, 3]] as $sort => $order) {
            $this->postJson('/profile/update-view', ['root' => 'tv', 'shows_sort' => $sort])->assertOk();
            $response = $this->page('/tv/shows', User::query()->findOrFail($user->id))->assertSee('<option value="'.$sort.'" selected', false);
            $this->assertSame($order, $this->tileIds($response), $sort);
        }
        $preferences = User::query()->findOrFail($user->id)->releaseViewPreferences('tv');
        $this->assertSame('recent', $preferences['shows_sort']);
        $this->postJson('/profile/update-view', ['root' => 'tv', 'shows_sort' => 'grabs'])->assertUnprocessable();
        $this->postJson('/profile/update-view', ['root' => 'movies', 'shows_sort' => 'az'])->assertUnprocessable();
    }

    public function test_an_empty_result_keeps_the_line_and_a_single_page_greys_both_arrows(): void
    {
        $this->show(1, 'Only Show', genres: [self::DRAMA]);
        $this->tv(1);

        $single = $this->page('/tv/shows')->assertSee('Showing 1–1 of 1 show')->assertSee('Page 1 of 1');
        $this->assertSame(2, substr_count((string) $single->getContent(), '<span class="is-off"'));
        $this->assertSame(1, substr_count((string) $single->getContent(), 'aria-label="Pages"'));
        DB::table('video_genres')->delete();
        $this->page('/tv/shows?person=1&language[]=en')->assertSee('Showing 1–1 of 1 show');
        DB::table('people')->insert(['id' => 1, 'name' => 'Nobody Cast', 'tmdb_id' => 1]);
        $this->page('/tv/shows?person=1')->assertSee('Showing 0 shows')->assertSee('No shows match. Try removing one of the choices above.')
            ->assertSee('Page 1 of 1');
    }

    public function test_the_search_finds_shows_from_one_character_and_people_from_two(): void
    {
        $this->show(1, 'Harbor Lights', started: '2011-01-01 00:00:00', genres: [self::DRAMA, self::COMEDY]);
        $this->show(2, 'The Harbor', started: '2015-01-01 00:00:00');
        $this->show(3, 'Arbor Day');
        $this->show(4, 'Harbor Hidden');
        $this->show(5, 'Harbor No Releases');
        foreach ([1, 2, 3] as $id) {
            $this->tv($id);
        }
        $this->tv(4, categories: self::FOREIGN);
        foreach (range(10, 16) as $id) {
            $this->show($id, 'Harbor Extra '.$id);
            $this->tv($id);
        }
        DB::table('people')->insert([['id' => 1, 'name' => 'Hal Harbinger', 'tmdb_id' => 1], ['id' => 2, 'name' => 'Rob Harbin', 'tmdb_id' => 2], ['id' => 3, 'name' => 'Haro Unseen', 'tmdb_id' => 3]]);
        DB::table('video_people')->insert([
            ['videos_id' => 1, 'people_id' => 1, 'position' => 0], ['videos_id' => 1, 'people_id' => 2, 'position' => 1],
            ['videos_id' => 2, 'people_id' => 2, 'position' => 0], ['videos_id' => 4, 'people_id' => 3, 'position' => 0],
        ]);
        $user = $this->browserUser();
        DB::table('user_excluded_categories')->insert(['users_id' => $user->id, 'categories_id' => self::FOREIGN]);
        $this->actingAs($user);

        $result = $this->getJson('/tv/search?q=harb')->assertOk()->json();
        $this->assertSame(['Harbor Extra 10', 'Harbor Extra 11', 'Harbor Extra 12', 'Harbor Extra 13', 'Harbor Extra 14', 'Harbor Extra 15'], array_column($result['shows'], 'title'));
        $this->assertSame([['id' => 2, 'name' => 'Rob Harbin', 'shows' => ['Harbor Lights', 'The Harbor']], ['id' => 1, 'name' => 'Hal Harbinger', 'shows' => ['Harbor Lights']]], $result['people']);

        $lights = $this->getJson('/tv/search?q=lights')->assertOk()->json('shows');
        $this->assertSame([['id' => 1, 'title' => 'Harbor Lights', 'year' => 2011, 'genres' => ['Comedy', 'Drama'], 'poster' => null]], $lights);
        $this->assertSame(['Arbor Day', 'Harbor Extra 10', 'Harbor Extra 11', 'Harbor Extra 12', 'Harbor Extra 13', 'Harbor Extra 14'],
            array_column($this->getJson('/tv/search?q=arbor')->json('shows'), 'title'));
        $this->assertSame([], $this->getJson('/tv/search?q=h')->assertOk()->json('people'));
        $this->assertSame([], $this->getJson('/tv/search?q=haro')->assertOk()->json('people'));
        $this->assertSame(['shows' => [], 'people' => []], $this->getJson('/tv/search?q=%20')->assertOk()->json());
        $this->assertSame([], $this->getJson('/tv/search?q=%25')->json('shows'));
    }

    public function test_the_search_field_sits_beside_the_switch_on_the_releases_screen_and_the_wall(): void
    {
        $this->show(1, 'Any Show');
        $this->tv(1);
        foreach (['/tv', '/tv/shows'] as $uri) {
            $this->page($uri)->assertOk()->assertSeeInOrder(['data-part="view switch, other"', 'x-data="tvSearch"', 'placeholder="Search shows or actors"'], false)
                ->assertSee('data-search-url="'.route('tv.search').'"', false)->assertSee('data-part="search field"', false);
        }
    }

    public function test_the_old_directory_is_gone_and_the_header_link_leads_to_the_wall(): void
    {
        foreach (['/series', '/series/M?year=1970s', '/series/12', '/trending-tv'] as $uri) {
            $this->page($uri)->assertNotFound();
        }
        $this->page('/tv/shows')->assertSee('href="'.route('tv.shows').'"><i class="fas fa-tv" aria-hidden="true"></i>TV Shows</a>', false);
    }

    public function test_the_wall_and_the_search_need_the_tv_permission(): void
    {
        $user = $this->browserUser();
        $user->revokePermissionTo('view tv');
        $this->page('/tv/shows', $user)->assertForbidden();
        $this->page('/tv/search?q=a', $user)->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $details  tv_info values; none stores no tv_info row
     * @param  list<int>  $genres
     */
    private function show(int $id, string $title, string $started = '2000-01-01 00:00:00', array $details = [], array $genres = []): void
    {
        DB::table('videos')->insert(['id' => $id, 'type' => 0, 'title' => $title, 'started' => $started]);
        if ($details !== []) {
            DB::table('tv_info')->insert(['videos_id' => $id, 'summary' => '', 'publisher' => '', ...$details]);
        }
        foreach ($genres as $genre) {
            DB::table('video_genres')->insert(['videos_id' => $id, 'genres_id' => $genre]);
        }
    }

    private function tv(int $show, string $posted = '2026-09-20 10:00:00', ?string $added = null, int $categories = self::HD, int $password = 0): int
    {
        $name = 'Show.'.$show.'.Release.'.++$this->nextRelease;

        return $this->release($name, ['categories_id' => $categories, 'videos_id' => $show, 'passwordstatus' => $password,
            'postdate' => $posted, 'adddate' => $added ?? $posted, 'tv_episodes_id' => 0, 'resolution' => 2, 'source' => 1]);
    }

    private function page(string $uri, ?User $user = null): TestResponse
    {
        $this->resetGlobalComposerState();

        return $this->actingAs($user ?? $this->user ??= $this->browserUser())->get($uri);
    }

    /** @param list<string> $titles */
    private function assertWall(string $uri, array $titles): void
    {
        $ids = $this->tileIds($this->page($uri)->assertOk());
        $listed = DB::table('videos')->whereIn('id', $ids)->orderBy('title')->pluck('title')->all();
        $this->assertSame($titles, $listed, $uri);
    }

    /** @return list<int> in display order */
    private function tileIds(TestResponse $response): array
    {
        preg_match_all('/data-show="(\d+)"/', (string) $response->getContent(), $matches);

        return array_map('intval', $matches[1]);
    }

    /** @return list<int> */
    private function sortedTileIds(TestResponse $response): array
    {
        $ids = $this->tileIds($response);
        sort($ids);

        return $ids;
    }

    private function tile(TestResponse $response, int $id): string
    {
        $html = (string) $response->getContent();
        $start = strpos($html, 'data-show="'.$id.'"');
        $this->assertNotFalse($start, 'No tile for show '.$id);

        return substr($html, $start, strpos($html, '</a>', $start) - $start);
    }
}
