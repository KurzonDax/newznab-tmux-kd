<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\Settings;
use App\Models\User;
use App\Services\Releases\MovieFilmWall;
use App\Services\Releases\MovieReleaseList;
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

/** The Films wall, GET /movies/films (docs/proposals/movies-redesign/SPEC.md 5A; the wall checks of prototype/check.mjs). */
final class MovieFilmsPageTest extends TestCase
{
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    private const HD = 2040;

    private const SD = 2030;

    private const FOREIGN = 2010;

    private const TV_HD = 5040;

    private const DRAMA = 1;

    private const COMEDY = 2;

    private const HORROR = 3;

    private const WESTERN = 4;

    private ?User $user = null;

    private string $covers = '';

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
            'haspreview', 'jpgstatus', 'groups_id', 'fromname', 'isrenamed', 'additional_pp_claim_token', 'imdbid', 'movieinfo_id', 'videos_id',
            'tv_episodes_id', 'musicinfo_id', 'consoleinfo_id', 'gamesinfo_id', 'bookinfo_id', 'anidbid', 'resolution', 'source']);
        foreach (['usenet_groups', 'users_releases', 'user_series', 'user_movies', 'videos', 'movieinfo', 'people', 'genres', 'movie_genres',
            'movie_people', 'release_audio_tags', 'release_video_clips', 'languages', 'release_audio_languages'] as $table) {
            $tables->create($table);
        }
        DB::table('root_categories')->insert([['id' => 2000, 'title' => 'Movies', 'status' => 1], ['id' => 5000, 'title' => 'TV', 'status' => 1]]);
        foreach ([self::FOREIGN => 'Foreign', self::SD => 'SD', self::HD => 'HD'] as $id => $title) {
            DB::table('categories')->insert(['id' => $id, 'title' => $title, 'root_categories_id' => 2000, 'status' => 1]);
        }
        DB::table('categories')->insert(['id' => self::TV_HD, 'title' => 'HD', 'root_categories_id' => 5000, 'status' => 1]);
        foreach ([self::DRAMA => 'Drama', self::COMEDY => 'Comedy', self::HORROR => 'Horror', self::WESTERN => 'Western'] as $id => $title) {
            DB::table('genres')->insert(['id' => $id, 'title' => $title, 'type' => 2000, 'disabled' => 0]);
        }
        $this->covers = $this->makeTempDirectory('movie-covers');
        config(['nntmux_settings.covers_path' => $this->covers]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_the_wall_lists_films_with_a_movies_release_newest_release_first_42_per_page(): void
    {
        foreach (range(1, 45) as $id) {
            $this->film($id, sprintf('Film %02d', $id));
            $this->movie($id, sprintf('2026-09-20 00:%02d:00', $id));
        }
        $this->film(99, 'No Releases Yet');

        $response = $this->page('/movies/films')->assertOk()
            ->assertSee('<h1 data-part="page title">Films</h1>', false)
            ->assertSee('aria-current="page" data-part="view switch, current">Films</a>', false)
            ->assertSee('placeholder="Search films or actors"', false)
            ->assertSee('Showing 1–42 of 45 films')->assertSee('Page 1 of 2')
            ->assertSee('<nav class="pager-line is-fixed" aria-label="Pages">', false)
            ->assertDontSee('No Releases Yet')->assertDontSee('data-name="resolution"', false)->assertDontSee('data-name="category"', false)
            ->assertDontSee('style="', false);
        $this->assertCount(42, $this->tileIds($response));
        $this->assertSame([45, 44, 43, 42, 41, 40, 39, 38, 37, 36], array_slice($this->tileIds($response), 0, 10));
        $this->assertSame(2, substr_count((string) $response->getContent(), 'aria-label="Pages"'));
        $response->assertSee('<a class="tv-tile is-film" href="'.url('/movies/film/45').'" data-film="45"', false);

        $second = $this->page('/movies/films?page=2')->assertOk()->assertSee('Showing 43–45 of 45 films');
        $this->assertSame([3, 2, 1], $this->tileIds($second));
        $this->page('/movies/films?page=9')->assertRedirect(route('movies.films', ['page' => 2]));
    }

    public function test_the_title_row_holds_the_switch_search_and_sort_and_the_bar_holds_the_film_filters_clear_all_and_the_person(): void
    {
        $this->film(1, 'With Her');
        $this->movie(1);
        DB::table('people')->insert(['id' => 7, 'name' => 'Ada Quill', 'tmdb_id' => 70]);
        DB::table('movie_people')->insert(['movieinfo_id' => 1, 'people_id' => 7, 'role' => 1, 'position' => 0]);

        $response = $this->page('/movies/films?person=7')->assertOk();
        $html = (string) $response->getContent();
        $title = (string) strstr((string) strstr($html, '<div class="tv-filters">'), '<div class="filter-row tv-bar-wall">', true);
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('movies.releases'), '/').'"[^>]*>Releases<\/a>\s*<a href="'.preg_quote(route('movies.films'), '/').'" aria-current="page"[^>]*>Films<\/a>\s*<\/div>\s*<div class="tv-search"/', $title);
        $this->assertStringContainsString('data-shows-url="'.route('movies.films').'"', $title);
        $this->assertStringContainsString('<select aria-label="Sort films"', $title);
        $this->assertStringNotContainsString('checkbox-menu', $title);
        $this->assertSame(['Newest releases first', 'Newest to the site first', 'Newest films first', 'A to Z'], $this->sortOptions($response));
        $response->assertSee('<option value="recent" selected', false);
        $response->assertSeeInOrder(['<div class="filter-row tv-bar-wall">', '<div class="filter-bar is-film" role="group" aria-label="The film">',
            'data-name="language"', 'class="tv-clear-all" data-clear-all aria-hidden="false"', 'data-part="person chip"', 'x-ref="list" class="tv-list-end"'], false);
        preg_match_all('/class="checkbox-menu is-cell[^"]*"[^>]*data-name="([a-z]+)"/', $html, $cells);
        $this->assertSame(['genre', 'year', 'score', 'rating', 'language'], $cells[1]);
        preg_match_all('/<span class="checkbox-menu-name">([^<]+)<\/span>/', (string) strstr($html, '<div class="filter-row tv-bar-wall">'), $names);
        $this->assertSame(['Genre', 'Year', 'Score', 'MPAA Rating', 'Language'], $names[1]);
        // Clear all keeps its fixed slot, hidden, while nothing is set
        $this->page('/movies/films')->assertSee('class="tv-clear-all is-hidden" data-clear-all aria-hidden="true" tabindex="-1">Clear all</a>', false)
            ->assertDontSee('data-part="person chip"', false);
        // the header's Movies menu links the wall where the TV menu links TV Shows
        $response->assertSee('<a href="'.route('movies.films').'">Films</a>', false)
            ->assertDontSee('Trending Movies');
    }

    public function test_a_tile_shows_the_poster_or_name_card_the_title_year_and_two_genres_the_score_line_and_the_visible_releases(): void
    {
        $this->film(1, 'Glass Meridian', '1994', ['rating' => '8.5', 'vote_count' => 30521, 'content_rating_us' => 'PG-13']);
        $this->film(2, 'Salt Harbour', '2024', ['rating' => '9.2', 'vote_count' => 3, 'content_rating_us' => 'R']);
        $this->film(3, 'Nameless', '', ['rating' => '']);
        $this->film(4, 'Whole Score', '2001', ['rating' => '7.0', 'vote_count' => null]);
        $this->film(5, 'Not Rated', '2002', ['rating' => '7', 'vote_count' => 50, 'content_rating_us' => 'NR']);
        $this->film(6, 'Zero Score', '2003', ['rating' => '0', 'vote_count' => 100]);
        foreach ([[self::DRAMA, 0], [self::COMEDY, 1], [self::HORROR, 2]] as [$genre, $position]) {
            $this->genreOf(1, $genre, $position);
        }
        $this->genreOf(5, self::HORROR, 0);
        $this->poster('0000001');
        $this->movie(1, '2026-09-24 00:00:00');
        $this->movie(1, '2026-09-23 00:00:00', categories: self::SD);
        $this->movie(1, '2026-09-22 00:00:00', password: 1);
        $this->movie(1, '2026-09-21 00:00:00', categories: self::FOREIGN);
        $this->movie(1, '2026-09-20 00:00:00', categories: self::TV_HD);
        foreach ([2, 3, 4, 5, 6] as $id) {
            $this->movie($id, '2026-09-1'.$id.' 00:00:00');
        }
        $user = $this->browserUser();
        DB::table('user_excluded_categories')->insert(['users_id' => $user->id, 'categories_id' => self::FOREIGN]);

        $response = $this->page('/movies/films', $user)->assertOk();
        $glass = $this->tile($response, 1);
        $this->assertStringContainsString('<img src="'.url('/covers/movies/0000001-cover.jpg').'" alt="" loading="lazy">', $glass);
        $this->assertMatchesRegularExpression('/<b\s+data-part="film tile title"\s*>Glass Meridian<\/b>/', $glass);
        $this->assertSame('1994 · Drama, Comedy', $this->line($glass, 'tv-tile-what'));
        $this->assertStringContainsString('1994 · <span class="tv-tile-genre">Drama</span>, <span class="tv-tile-genre">Comedy</span></span>', $glass);
        $this->assertSame('8.5 · PG-13', $this->line($glass, 'tv-tile-more'));
        $this->assertSame('2 releases', $this->line($glass, 'tv-tile-more is-count'));
        $this->assertStringNotContainsString('<button', $glass);

        $salt = $this->tile($response, 2);
        $this->assertStringContainsString('<span class="tv-tile-card"><span class="tv-tile-card-title">Salt Harbour</span><small>2024</small></span>', $salt);
        $this->assertSame('2024', $this->line($salt, 'tv-tile-what'));
        $this->assertSame('Too few votes · R', $this->line($salt, 'tv-tile-more'));
        $this->assertSame('1 release', $this->line($salt, 'tv-tile-more is-count'));
        $nameless = $this->tile($response, 3);
        $this->assertStringContainsString('<span class="tv-tile-card"><span class="tv-tile-card-title">Nameless</span></span>', $nameless);
        $this->assertSame('', $this->line($nameless, 'tv-tile-what'));
        $this->assertSame('Too few votes', $this->line($nameless, 'tv-tile-more'));
        $this->assertSame('7', $this->line($this->tile($response, 4), 'tv-tile-more'));
        $this->assertSame('7 · NR', $this->line($this->tile($response, 5), 'tv-tile-more'));
        $this->assertSame('2002 · Horror', $this->line($this->tile($response, 5), 'tv-tile-what'));
        $this->assertSame('Too few votes', $this->line($this->tile($response, 6), 'tv-tile-more'));
        $this->assertSame(1, preg_match('/<div class="tv-tiles">(.*?)<\/div>/s', (string) $response->getContent(), $tiles));
        $this->assertStringNotContainsString('<button', $tiles[1]);
        $this->assertStringNotContainsString('data-watch', $tiles[1]);

        // a genre the Genre filter matched comes first, then the film's order
        $horror = $this->page('/movies/films?genre[]='.self::HORROR, $user);
        $this->assertSame([1, 5], $this->tileIds($horror));
        $this->assertSame('1994 · Horror, Drama', $this->line($this->tile($horror, 1), 'tv-tile-what'));
        $this->assertSame('1994 · Drama, Horror', $this->line($this->tile($this->page('/movies/films?genre[]='.self::HORROR.'&genre[]='.self::DRAMA, $user), 1), 'tv-tile-what'));
    }

    public function test_the_score_line_reads_the_score_as_stored_or_too_few_votes_and_the_rating(): void
    {
        $this->assertSame('Too few votes · R', MovieFilmWall::scoreLine('9.2', 3, 'R'));
        $this->assertSame('Too few votes', MovieFilmWall::scoreLine('', null, ''));
        $this->assertSame('8.5 · PG-13', MovieFilmWall::scoreLine('8.5', 30521, 'PG-13'));
        $this->assertSame('7', MovieFilmWall::scoreLine('7', null, ''));
        $this->assertSame('7', MovieFilmWall::scoreLine('7.0', 10, ''));
        $this->assertSame('Too few votes · G', MovieFilmWall::scoreLine('0', 500, 'G'));
    }

    public function test_the_film_filters_combine_and_the_menus_are_the_lists_with_language_by_film_count(): void
    {
        $this->film(1, 'Old Drama', '1994', ['rating' => '8.1', 'vote_count' => 100, 'content_rating_us' => 'R', 'original_language' => 'en']);
        $this->film(2, 'New Drama', '2012', ['rating' => '7.2', 'vote_count' => 100, 'content_rating_us' => 'PG-13', 'original_language' => 'fr']);
        $this->film(3, 'New Comedy', '2015', ['rating' => '9.1', 'vote_count' => 5, 'content_rating_us' => 'R', 'original_language' => 'fr']);
        $this->film(4, 'Unreleased', '2016', ['content_rating_us' => 'NC-17', 'original_language' => 'de']);
        $this->genreOf(1, self::DRAMA);
        $this->genreOf(2, self::DRAMA);
        $this->genreOf(3, self::COMEDY);
        $this->genreOf(4, self::WESTERN);
        foreach (range(1, 5) as $release) {
            $this->movie(1, '2026-09-1'.$release.' 00:00:00');
        }
        $this->movie(2);
        $this->movie(3);

        $response = $this->page('/movies/films')->assertOk();
        $list = app(MovieReleaseList::class)->filmOptions();
        $this->assertSame($list['genre'], $response->viewData('options')['genre']);
        $this->assertSame($list['rating'], $response->viewData('options')['rating']);
        // the list orders Language by releases (English 5, French 2); the wall by films (French 2, English 1)
        $this->assertSame(['en', 'fr'], array_keys($list['language']));
        $this->assertSame(['fr' => 'French', 'en' => 'English'], $response->viewData('options')['language']);

        $this->assertWall('/movies/films?genre[]='.self::DRAMA, [1, 2]);
        $this->assertWall('/movies/films?genre[]='.self::DRAMA.'&genre[]='.self::COMEDY, [1, 2, 3]);
        $this->assertWall('/movies/films?decade[]=2010', [2, 3]);
        $this->assertWall('/movies/films?year_from=1990&year_to=2012', [1, 2]);
        $this->assertWall('/movies/films?score[]=9', []);
        $this->assertWall('/movies/films?score[]=few', [3]);
        $this->assertWall('/movies/films?score[]=7&score[]=8', [1, 2]);
        $this->assertWall('/movies/films?rating[]=R', [1, 3]);
        $this->assertWall('/movies/films?language[]=fr', [2, 3]);
        $this->assertWall('/movies/films?language[]=fr&genre[]='.self::DRAMA.'&rating[]=PG-13&decade[]=2010&score[]=7', [2]);
        $this->assertWall('/movies/films?genre[]=999', [1, 2, 3]);
        $this->page('/movies/films?genre[]='.self::DRAMA)->assertSee('data-clear-all aria-hidden="false"', false);
    }

    public function test_the_four_sorts_order_the_wall_with_their_ties_and_the_chosen_one_is_remembered(): void
    {
        $this->film(1, 'bravo', '2010');
        $this->film(2, 'Alpha', '2010');
        $this->film(3, 'charlie', '2020');
        $this->film(4, 'alpha', '');
        $this->film(5, 'Delta', '2010');
        $this->movie(1, '2026-09-24 00:00:00', '2026-09-01 00:00:00');
        $this->movie(2, '2026-09-24 00:00:00', '2026-09-01 00:00:00');
        $this->movie(3, '2026-09-10 00:00:00', '2026-09-05 00:00:00');
        $this->movie(4, '2026-09-22 00:00:00', '2026-09-10 00:00:00');
        $this->movie(5, '2026-09-20 00:00:00', '2026-09-10 00:00:00');
        $this->movie(5, '2026-09-01 00:00:00', '2026-08-01 00:00:00');
        $user = $this->browserUser();

        $this->assertSame([2, 1, 4, 5, 3], $this->tileIds($this->page('/movies/films', $user)->assertSee('<option value="recent" selected', false)));
        foreach (['newsite' => [4, 3, 2, 1, 5], 'year' => [3, 2, 1, 5, 4], 'az' => [2, 4, 1, 3, 5], 'recent' => [2, 1, 4, 5, 3]] as $sort => $order) {
            $this->postJson('/profile/update-view', ['root' => 'movies', 'films_sort' => $sort])->assertOk();
            $response = $this->page('/movies/films?page=1', User::query()->findOrFail($user->id))->assertSee('<option value="'.$sort.'" selected', false);
            $this->assertSame($order, $this->tileIds($response), $sort);
        }
        $this->assertSame('recent', User::query()->findOrFail($user->id)->releaseViewPreferences('movies')['films_sort']);
        $this->postJson('/profile/update-view', ['root' => 'movies', 'films_sort' => 'grabs'])->assertUnprocessable();
        $this->postJson('/profile/update-view', ['root' => 'tv', 'films_sort' => 'az'])->assertUnprocessable();
    }

    public function test_page_one_reads_the_films_in_batches_and_keeps_ties_at_a_batch_edge_in_title_order(): void
    {
        // 99 films the viewer may not see, then eleven sharing the 100th film's date, then fifty older
        foreach (range(1, 99) as $id) {
            $this->film($id, sprintf('Hidden %03d', $id));
            $this->movie($id, Carbon::parse('2026-09-24 00:00:00')->subMinutes($id)->toDateTimeString(), password: 1);
        }
        foreach (range(100, 110) as $id) {
            $this->film($id, sprintf('Tie %02d', 110 - $id));
            $this->movie($id, '2026-09-20 00:00:00');
        }
        foreach (range(111, 160) as $id) {
            $this->film($id, sprintf('Older %03d', $id));
            $this->movie($id, Carbon::parse('2026-09-19 00:00:00')->subMinutes($id)->toDateTimeString());
        }
        DB::table('people')->insert(['id' => 7, 'name' => 'Ada Quill', 'tmdb_id' => 70]);
        foreach (range(1, 160) as $id) {
            $this->genreOf($id, self::DRAMA);
            DB::table('movie_people')->insert(['movieinfo_id' => $id, 'people_id' => 7, 'role' => 1, 'position' => 0]);
        }
        $newest = [...range(110, 100), ...range(111, 160)];
        $user = $this->browserUser();
        // every film is from 2000: "Newest films first" falls to the newest release, then the title
        foreach (['recent' => $newest, 'year' => $newest, 'az' => [...range(111, 160), ...range(110, 100)]] as $sort => $expected) {
            $this->actingAs($user)->postJson('/profile/update-view', ['root' => 'movies', 'films_sort' => $sort])->assertOk();
            $user = User::query()->findOrFail($user->id);
            foreach (['', '?genre[]='.self::DRAMA, '?person=7'] as $query) {
                $first = $this->page('/movies/films'.$query, $user)->assertOk()->assertSee('Showing 1–42 of 61 films');
                $this->assertSame(array_slice($expected, 0, 42), $this->tileIds($first), $sort.$query);
                $second = $this->page('/movies/films'.($query === '' ? '?' : $query.'&').'page=2', $user)->assertSee('Showing 43–61 of 61 films');
                $this->assertSame(array_slice($expected, 42), $this->tileIds($second), $sort.$query);
            }
        }
    }

    public function test_a_film_is_listed_and_counted_only_with_a_movies_release_the_viewer_may_see_and_sorts_by_all_its_movies_releases(): void
    {
        $this->film(1, 'Visible Film');
        $this->film(2, 'Only Foreign');
        $this->film(3, 'Only Passworded');
        $this->film(4, 'Only In TV');
        $this->film(5, 'Newest Hidden Release');
        $this->movie(1, '2026-09-20 00:00:00');
        $this->movie(2, categories: self::FOREIGN);
        $this->movie(3, password: 1);
        $this->movie(4, '2026-09-24 12:00:00', categories: self::TV_HD);
        $this->movie(5, '2026-09-10 00:00:00');
        $this->movie(5, '2026-09-24 00:00:00', password: 1);
        $user = $this->browserUser();
        DB::table('user_excluded_categories')->insert(['users_id' => $user->id, 'categories_id' => self::FOREIGN]);

        // film 5's passworded release still sets its place; the TV release never counts
        $response = $this->page('/movies/films', $user)->assertSee('Showing 1–2 of 2 films');
        $this->assertSame([5, 1], $this->tileIds($response));
        $this->assertSame('1 release', $this->line($this->tile($response, 5), 'tv-tile-more is-count'));
        Settings::query()->updateOrInsert(['name' => 'showpasswordedrelease'], ['value' => '1']);
        Cache::flush();
        $this->assertSame([5, 3, 1], $this->tileIds($this->page('/movies/films', $user)->assertSee('Showing 1–3 of 3 films')));
    }

    public function test_the_person_filter_narrows_the_wall_and_its_chip_removes_it(): void
    {
        $this->film(1, 'With Her');
        $this->film(2, 'Also With Her');
        $this->film(3, 'Without Her');
        $this->genreOf(2, self::DRAMA);
        $this->genreOf(3, self::DRAMA);
        foreach ([1, 2, 3] as $id) {
            $this->movie($id, '2026-09-2'.$id.' 00:00:00');
        }
        DB::table('people')->insert(['id' => 7, 'name' => 'Ada Quill', 'tmdb_id' => 70]);
        DB::table('movie_people')->insert([['movieinfo_id' => 1, 'people_id' => 7, 'role' => 0, 'position' => 0],
            ['movieinfo_id' => 1, 'people_id' => 7, 'role' => 1, 'position' => 2], ['movieinfo_id' => 2, 'people_id' => 7, 'role' => 1, 'position' => 0]]);

        $response = $this->page('/movies/films?person=7')->assertOk()->assertSee('Films with Ada Quill')->assertSee('Showing 1–2 of 2 films')
            ->assertSee('href="'.route('movies.films').'" data-remove-person aria-label="Stop showing only films with Ada Quill"', false)
            ->assertSee('data-clear-all aria-hidden="false"', false);
        $this->assertSame([2, 1], $this->tileIds($response));
        $this->assertSame([2], $this->tileIds($this->page('/movies/films?person=7&genre[]='.self::DRAMA)));
        $this->page('/movies/films?person=7&genre[]='.self::DRAMA)
            ->assertSee('href="'.route('movies.films', ['genre' => [self::DRAMA]]).'" data-remove-person', false);
        $this->page('/movies/films?person=999')->assertDontSee('Films with')->assertSee('Showing 1–3 of 3 films')
            ->assertSee('data-clear-all aria-hidden="true"', false);
    }

    public function test_one_film_reads_showing_1_film_and_no_match_names_the_filters_in_words(): void
    {
        $this->film(1, 'Only Film', '1994', ['content_rating_us' => 'NC-17']);
        $this->genreOf(1, self::DRAMA);
        $this->film(2, 'Unreleased Western');
        $this->genreOf(2, self::WESTERN);
        $this->movie(1);

        $single = $this->page('/movies/films')->assertSee('Showing 1 film')->assertDontSee('Showing 1–1')->assertSee('Page 1 of 1');
        $this->assertSame(2, substr_count((string) $single->getContent(), '<span class="is-off"'));
        $this->assertSame(1, substr_count((string) $single->getContent(), 'aria-label="Pages"'));

        $this->page('/movies/films?genre[]='.self::WESTERN.'&score[]=9&rating[]=NC-17')->assertOk()
            ->assertSee('Showing 0 films')->assertSee('Page 1 of 1')
            ->assertSee('<p class="tv-empty">No films match Western · score 9+ · rated NC-17.</p>', false);
        DB::table('people')->insert(['id' => 7, 'name' => 'Ada Quill', 'tmdb_id' => 70]);
        $this->page('/movies/films?decade[]=1990&person=7')->assertSee('No films match 1990s · with Ada Quill.');
        // the list keeps "Showing 1–1 of 1 release"
        $this->page('/movies')->assertSee('Showing 1–1 of 1 release');
    }

    public function test_the_count_is_cached_under_the_browse_version_and_the_list_fragment_is_the_list_alone(): void
    {
        $this->film(1, 'First');
        $this->film(2, 'Second');
        $this->movie(1);
        $this->page('/movies/films')->assertSee('Showing 1 film');
        $this->movie(2);
        $this->page('/movies/films')->assertSee('Showing 1 film');
        ReleaseBrowseService::bumpCacheVersion();
        $this->page('/movies/films')->assertSee('Showing 1–2 of 2 films');

        $fragment = (string) $this->page('/movies/films?_fragment=list')->assertOk()->assertSee('Showing 1–2 of 2 films')->getContent();
        $this->assertStringNotContainsString('<html', $fragment);
        $this->assertStringNotContainsString('checkbox-menu', $fragment);
        $this->assertStringContainsString('data-film="1"', $fragment);
    }

    public function test_the_wall_needs_the_movies_permission(): void
    {
        $user = $this->browserUser();
        $user->revokePermissionTo('view movies');
        $this->page('/movies/films', $user)->assertForbidden();
    }

    /** @param array<string, mixed> $attributes */
    private function film(int $id, string $title, string $year = '2000', array $attributes = []): void
    {
        DB::table('movieinfo')->insert(['id' => $id, 'imdbid' => sprintf('%07d', $id), 'title' => $title, 'year' => $year, ...$attributes]);
    }

    private function genreOf(int $film, int $genre, int $position = 0): void
    {
        DB::table('movie_genres')->insert(['movieinfo_id' => $film, 'genres_id' => $genre, 'position' => $position]);
    }

    private function movie(int $film, string $posted = '2026-09-20 10:00:00', ?string $added = null, int $categories = self::HD, int $password = 0): int
    {
        return $this->release('Film.'.$film.'.Release.'.++$this->nextRelease, ['categories_id' => $categories, 'movieinfo_id' => $film,
            'passwordstatus' => $password, 'postdate' => $posted, 'adddate' => $added ?? $posted, 'imdbid' => null, 'videos_id' => 0,
            'tv_episodes_id' => 0, 'resolution' => 2, 'source' => 1]);
    }

    private function poster(string $imdbId): void
    {
        File::ensureDirectoryExists($this->covers.'/movies');
        File::put($this->covers.'/movies/'.$imdbId.'-cover.jpg', 'jpg');
    }

    private function page(string $uri, ?User $user = null): TestResponse
    {
        $this->resetGlobalComposerState();

        return $this->actingAs($user ?? $this->user ??= $this->browserUser())->get($uri);
    }

    /** @param list<int> $ids in any order */
    private function assertWall(string $uri, array $ids): void
    {
        $listed = $this->tileIds($this->page($uri)->assertOk());
        sort($listed);
        $this->assertSame($ids, $listed, $uri);
    }

    /** @return list<int> in display order */
    private function tileIds(TestResponse $response): array
    {
        preg_match_all('/data-film="(\d+)"/', (string) $response->getContent(), $matches);

        return array_map('intval', $matches[1]);
    }

    private function tile(TestResponse $response, int $id): string
    {
        $html = (string) $response->getContent();
        $start = strpos($html, 'data-film="'.$id.'"');
        $this->assertNotFalse($start, 'No tile for film '.$id);

        return substr($html, $start, strpos($html, '</a>', $start) - $start);
    }

    /** A tile line's text as check.mjs reads it (textContent). */
    private function line(string $tile, string $class): string
    {
        $this->assertMatchesRegularExpression('/<span class="'.preg_quote($class, '/').'"[^>]*>(.*?)<\/span>\s*(<span|$)/s', $tile);
        preg_match('/<span class="'.preg_quote($class, '/').'"[^>]*>(.*?)<\/span>\s*(<span|$)/s', $tile, $match);

        return html_entity_decode(strip_tags($match[1]), ENT_QUOTES);
    }

    /** @return list<string> */
    private function sortOptions(TestResponse $response): array
    {
        preg_match('/<select aria-label="Sort films".*?<\/select>/s', (string) $response->getContent(), $select);
        preg_match_all('/<option value="[a-z]+"[^>]*>([^<]+)<\/option>/', $select[0] ?? '', $options);

        return $options[1];
    }
}
