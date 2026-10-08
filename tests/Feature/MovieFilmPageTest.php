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
use Tests\Support\AssertsFollowWording;
use Tests\Support\AssertsOffsiteLinks;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/** The film page, GET /movies/film/{movieinfo.id} (docs/proposals/movies-redesign/SPEC.md 5B; the film checks of prototype/check.mjs). */
final class MovieFilmPageTest extends TestCase
{
    use AssertsFollowWording;
    use AssertsOffsiteLinks;
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    private const FILM = 10;

    private const HD = 2040;

    private const SD = 2030;

    private const FOREIGN = 2010;

    private const TV_HD = 5040;

    private const DRAMA = 1;

    private const CRIME = 2;

    private const THRILLER = 3;

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
            'adddate', 'postdate', 'grabs', 'comments', 'completion', 'declaredfiles', 'nzbstatus', 'passwordstatus', 'nfostatus',
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
        foreach ([self::DRAMA => 'Drama', self::CRIME => 'Crime', self::THRILLER => 'Thriller'] as $id => $title) {
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

    public function test_the_header_carries_the_poster_title_meta_line_plot_tags_and_people(): void
    {
        $this->film(self::FILM, 'Heat', '1995', ['plot' => 'A crew of thieves and the detective after them.', 'rating' => '8.40', 'vote_count' => 9000,
            'content_rating_us' => 'R', 'original_language' => 'en']);
        $this->poster(sprintf('%07d', self::FILM));
        // TMDB's order is the stored position, not the name
        $this->genreOf(self::FILM, self::THRILLER, 1);
        $this->genreOf(self::FILM, self::CRIME, 0);
        $this->genreOf(self::FILM, self::DRAMA, 2);
        DB::table('people')->insert(['id' => 1, 'name' => 'Michael Mann']);
        DB::table('movie_people')->insert(['movieinfo_id' => self::FILM, 'people_id' => 1, 'role' => 0, 'position' => 0]);
        foreach (range(2, 15) as $person) {
            DB::table('people')->insert(['id' => $person, 'name' => 'Actor '.$person]);
            DB::table('movie_people')->insert(['movieinfo_id' => self::FILM, 'people_id' => $person, 'role' => 1, 'position' => 15 - $person]);
        }
        $this->movie(self::FILM, '2026-09-16 08:00:00', resolution: 3);
        $this->movie(self::FILM, '2026-09-10 08:00:00', resolution: 1);
        $this->movie(self::FILM, '2026-09-01 08:00:00', resolution: 0);
        $this->movie(self::FILM, '2026-09-24 08:00:00', resolution: 1, password: 1);

        $response = $this->page('/movies/film/'.self::FILM)->assertOk()->assertSee('<title>Heat', false);
        $head = $this->between($response, '<div class="tv-show-head">', '<section class="tv-film-releases"');
        $this->assertStringContainsString('<img src="'.getImageAssetUrl('movies', sprintf('%07d', self::FILM).'-cover').'" alt="Heat poster">', $head);
        $this->assertStringContainsString('<h1 data-part="film page title">Heat</h1>', $head);
        // "Year · N releases · latest <date> · best <chip>" over the releases the viewer may see
        $this->assertSame('1995 · 3 releases · latest Sep 16, 2026 · best 4K', $this->text($this->element($head, 'data-part="film meta line"')));
        $this->assertMatchesRegularExpression('/<span>best<\/span><span class="resolution-chip resolution-chip-4k"[^>]*>4K<\/span>/', $head);
        $this->assertStringContainsString('<p>A crew of thieves and the detective after them.</p>', $head);
        preg_match_all('/<a class="tv-tag" href="([^"]+)"[^>]*>([^<]+)<\/a>/', $head, $genres);
        $this->assertSame(['Crime', 'Thriller', 'Drama'], $genres[2]);
        $this->assertSame(route('movies.films', ['genre' => [self::CRIME]]), html_entity_decode($genres[1][0]));
        preg_match_all('/<span class="tv-tag tv-tag-plain"[^>]*>([^<]+)<\/span>/', $head, $plain);
        $this->assertSame(['Score 8.4', 'R', 'English'], $plain[1]);
        $this->assertLessThan(strpos($head, 'tv-tag-plain'), strrpos($head, 'class="tv-tag"'));

        $directed = $this->element($head, 'data-part="directed by line"');
        $starring = $this->element($head, 'data-part="starring line"');
        $this->assertSame('Directed by Michael Mann', $this->text($directed));
        $this->assertStringContainsString('<a href="'.route('movies.films', ['person' => 1]).'">Michael Mann</a>', $directed);
        preg_match_all('/<a href="([^"]+)">([^<]+)<\/a>/', $starring, $cast);
        $this->assertSame(array_map(static fn (int $person): string => 'Actor '.$person, range(15, 4)), $cast[2], 'The first 12 cast in TMDB\'s order.');
        $this->assertSame(route('movies.films', ['person' => 15]), $cast[1][0]);
        $this->assertStringStartsWith('Starring Actor 15, Actor 14', $this->text($starring));
        $this->assertLessThan(strpos($head, 'data-part="starring line"'), strpos($head, 'data-part="directed by line"'));
        $this->assertLessThan(strpos($head, 'tv-follow-show'), strpos($head, 'data-part="starring line"'));
        $response->assertDontSee('Search films or actors')->assertDontSee('Trailer')->assertDontSee('data-trailer-url', false);
    }

    public function test_latest_reads_hours_under_a_day_best_is_left_out_when_every_release_is_unknown_and_few_votes_say_so(): void
    {
        $this->film(self::FILM, 'Sparse', '', ['rating' => '6.1', 'vote_count' => 4]);
        $this->movie(self::FILM, '2026-09-25 10:00:00', resolution: 0);

        $head = $this->between($this->page('/movies/film/'.self::FILM)->assertOk(), '<div class="tv-show-head">', '<section class="tv-film-releases"');
        $this->assertSame('1 release · latest 2 hr ago', $this->text($this->element($head, 'data-part="film meta line"')));
        $this->assertStringNotContainsString('best', $this->element($head, 'data-part="film meta line"'));
        preg_match_all('/<span class="tv-tag tv-tag-plain"[^>]*>([^<]+)<\/span>/', $head, $plain);
        $this->assertSame(['Too few votes'], $plain[1]);
        $this->assertStringNotContainsString('data-part="directed by line"', $head);
        $this->assertStringNotContainsString('data-part="starring line"', $head);
    }

    public function test_a_film_without_artwork_gets_the_name_card_and_one_with_nothing_the_viewer_may_see_gets_the_normal_page(): void
    {
        $this->film(self::FILM, 'The Unseen', '2004', ['plot' => 'Hidden.']);
        $this->genreOf(self::FILM, self::DRAMA);
        $this->movie(self::FILM, categories: self::FOREIGN);
        $this->excludeForUser(self::FOREIGN);
        $this->similarTo(self::FILM, 20, [self::DRAMA]);

        $response = $this->page('/movies/film/'.self::FILM)->assertOk();
        $head = $this->between($response, '<div class="tv-show-head">', '<section class="tv-film-releases"');
        $this->assertStringContainsString('<div class="tv-show-card is-film"><span class="tv-tile-card-title">The Unseen</span><small>2004</small></div>', $head);
        $this->assertSame('2004 · 0 releases', $this->text($this->element($head, 'data-part="film meta line"')));
        $releases = $this->between($response, '<section class="tv-film-releases"', '</section>');
        $this->assertStringContainsString('Showing 0 releases', $releases);
        $this->assertStringContainsString('<p class="tv-empty">There are no releases of this film yet.</p>', $releases);
        $this->assertStringNotContainsString('<table', $releases);
        $this->assertSame([20], $this->similarIds($response));

        $this->page('/movies/film/999')->assertNotFound();
        $this->page('/movies/film/abc')->assertNotFound();
    }

    public function test_follow_film_opens_the_picker_to_start_following_is_pressed_while_followed_and_no_row_follows(): void
    {
        $this->film(self::FILM, 'Heat', '1995');
        $this->movie(self::FILM);
        $this->movie(self::FILM);
        $imdb = sprintf('%07d', self::FILM);
        $picker = route('watchlist.picker', ['root' => 'movies', 'id' => $imdb]);

        $response = $this->page('/movies/film/'.self::FILM)->assertOk();
        $head = (string) preg_replace('/>\s+</', '><', $this->between($response, '<div class="tv-show-head">', '<section class="tv-film-releases"'));
        $this->assertStringContainsString('<div class="tv-details-actions tv-show-actions"><button type="button" class="tv-details-button tv-follow-show" data-watch-picker="'.$picker.'" data-watch-key="movies:'.$imdb.'" data-watch-title="Heat" data-watch-off-title="Follow this film" data-watch-on-title="Following this film · click to unfollow" data-watched="0" aria-pressed="false" title="Follow this film">'
            .'<i class="far fa-bookmark" aria-hidden="true"></i><span class="tv-state-label"><span class="is-off">Follow film</span><span class="is-on">Following film</span></span></button>', $head);
        $html = (string) $response->getContent();
        $this->assertSame(1, substr_count($html, 'data-watch-picker='), 'Only the header follows the film.');
        $this->assertSame(2, substr_count($html, 'data-cart="'));
        $this->assertNoWatchWording($html, 'The film page');

        DB::table('user_movies')->insert(['users_id' => $this->user?->id, 'imdbid' => $imdb, 'categories' => '2040']);
        $followed = $this->between($this->page('/movies/film/'.self::FILM)->assertOk(), '<div class="tv-show-head">', '<section class="tv-film-releases"');
        $this->assertStringContainsString('data-watched="1" aria-pressed="true" title="Following this film · click to unfollow"><i class="fas fa-bookmark" aria-hidden="true"></i>', $followed);
        $this->assertNoWatchWording((string) $this->page('/movies/film/'.self::FILM)->getContent(), 'A followed film page');
    }

    public function test_a_film_with_no_imdb_id_has_no_follow_button_and_no_imdb_link(): void
    {
        $this->film(self::FILM, 'Keyless', '2001', ['imdbid' => '', 'tmdbid' => 77]);
        $this->movie(self::FILM);

        $response = $this->page('/movies/film/'.self::FILM)->assertOk();
        $actions = $this->between($response, '<div class="tv-details-actions tv-show-actions">', '</div>');
        $this->assertStringNotContainsString('tv-follow-show', $actions);
        $this->assertStringNotContainsString('data-watch-picker', (string) $response->getContent());
        $this->assertSame(['https://www.themoviedb.org/movie/77'], $this->assertOffsiteLinksOpenInANewTab($actions, 'A film page without an IMDb id'));
        $this->assertStringContainsString('<div class="tv-show-card is-film">', (string) $response->getContent());
    }

    public function test_imdb_always_and_tmdb_when_known_open_in_a_new_tab(): void
    {
        $this->film(self::FILM, 'Heat', '1995', ['imdbid' => '113277', 'tmdbid' => 949]);
        $this->movie(self::FILM);

        $response = $this->page('/movies/film/'.self::FILM)->assertOk();
        $actions = $this->between($response, '<div class="tv-details-actions tv-show-actions">', '</div>');
        $this->assertOffsiteLinksOpenInANewTab((string) $response->getContent(), 'The film page');
        $this->assertSame(['https://www.imdb.com/title/tt0113277/', 'https://www.themoviedb.org/movie/949'], $this->assertOffsiteLinksOpenInANewTab($actions, 'The film page header'));
        preg_match_all('/<a class="tv-details-button is-secondary" href="[^"]+" target="_blank" rel="noopener noreferrer">([A-Za-z]+)<i class="fas fa-arrow-up-right-from-square" aria-hidden="true"><\/i><span class="sr-only"> \(opens in a new tab\)<\/span><\/a>/', $actions, $links);
        $this->assertSame(['IMDb', 'TMDB'], $links[1]);
        $this->assertLessThan(strpos($actions, '>IMDb<'), strpos($actions, 'tv-follow-show'));
        $this->assertStringNotContainsString('Download', $actions);
    }

    public function test_the_outside_links_keep_the_sites_dereferrer_and_trakt_shows_when_its_id_is_known(): void
    {
        Settings::query()->updateOrInsert(['name' => 'dereferrer_link'], ['value' => 'https://deref.example/?']);
        $this->film(self::FILM, 'Heat', '1995', ['imdbid' => '113277', 'traktid' => 555]);
        $this->movie(self::FILM);

        $links = $this->assertOffsiteLinksOpenInANewTab($this->between($this->page('/movies/film/'.self::FILM), '<div class="tv-details-actions tv-show-actions">', '</div>'), 'The film page with Trakt');
        $this->assertSame(['https://deref.example/?https://www.imdb.com/title/tt0113277/', 'https://deref.example/?https://trakt.tv/movies/555'], $links);
    }

    public function test_releases_has_a_heading_and_a_bar_of_resolution_and_source_cells_with_the_lists_options(): void
    {
        $this->film(self::FILM, 'Heat');
        $this->movie(self::FILM);

        $response = $this->page('/movies/film/'.self::FILM.'?resolution[]=4k&source[]=dvd&category[]=2040')->assertOk();
        $section = $this->between($response, '<section class="tv-film-releases" id="releases" aria-labelledby="tv-film-releases-heading">', '</section>');
        $this->assertStringStartsWith('<h2 id="tv-film-releases-heading">Releases</h2>', $section);
        $this->assertStringContainsString('<div class="filter-row tv-film-bar">', $section);
        $this->assertStringContainsString('<div class="filter-bar is-release" role="group" aria-label="Filter this film&#039;s releases">', $section);
        preg_match_all('/class="checkbox-menu is-cell[^"]*"[^>]*data-name="([a-z]+)"/', $section, $cells);
        $this->assertSame(['resolution', 'source'], $cells[1]);
        foreach (['4K', '1080p', '720p', 'SD', 'WEB', 'Blu-ray', 'DVD', 'HDTV'] as $option) {
            $this->assertStringContainsString('>'.$option.'<', $section);
        }
        $this->assertMatchesRegularExpression('/class="checkbox-menu is-cell is-set"[^>]*data-name="resolution"/', $section);
        $this->assertMatchesRegularExpression('/class="checkbox-menu is-cell is-set"[^>]*data-name="source"/', $section);
        $this->assertStringNotContainsString('data-name="category"', $section);
        $this->assertStringNotContainsString('<select aria-label="Sort', (string) $response->getContent());
    }

    public function test_one_table_newest_posted_first_a_box_per_row_no_check_all_and_the_columns_without_grabs(): void
    {
        $this->film(self::FILM, 'Heat');
        $older = $this->movie(self::FILM, '2026-09-10 08:00:00', name: 'Heat.1995.1080p.BluRay-OLD');
        $newer = $this->movie(self::FILM, '2026-09-20 08:00:00', name: 'Heat.1995.1080p.BluRay-NEW', group: 'alt.binaries.movies', from: 'poster@example.com');
        $this->movie(self::FILM, '2026-09-15 08:00:00', name: 'Heat.1995.1080p.BluRay-NEW');

        $response = $this->page('/movies/film/'.self::FILM)->assertOk();
        $section = $this->between($response, '<section class="tv-film-releases"', '</section>');
        $this->assertSame(1, substr_count($section, '<table'));
        $this->assertStringContainsString('<table class="tv-release-table is-pick">', $section);
        preg_match_all('/<th[^>]*>(.*?)<\/th>/s', $section, $headers);
        $this->assertSame(['Select', 'Release', 'Resolution', 'Source', 'Size', 'Files', 'Posted', 'Actions'], array_map(fn (string $cell): string => $this->text($cell), $headers[1]));
        $this->assertStringNotContainsString('data-select-all', $section);
        $this->assertStringNotContainsString('Grabs', $section);
        $this->assertStringNotContainsString('Same name posted', $section);
        $this->assertSame(['Heat.1995.1080p.BluRay-NEW', 'Heat.1995.1080p.BluRay-NEW', 'Heat.1995.1080p.BluRay-OLD'], $this->rowNames($section));
        $this->assertSame(3, substr_count($section, '<input type="checkbox" data-select value="'));
        $this->assertStringContainsString('Showing 1–3 of 3 releases', $section);
        $this->assertStringContainsString('<a class="tv-release-name" href="'.route('details', md5('Heat.1995.1080p.BluRay-NEW'.$newer)).'"', $section);
        // the chip line leaves out the group and poster chips
        $this->assertStringNotContainsString('alt.binaries.movies', $section);
        $this->assertStringNotContainsString('a.b.movies', $section);
        $this->assertStringNotContainsString('poster@example.com', $section);
        $this->assertStringNotContainsString('tv-show-line', $section);
        // Files opens the file list; Posted shows the date, posted and added on hover
        $this->assertStringContainsString('<button type="button" class="tv-files filelist-badge" data-guid="'.md5('Heat.1995.1080p.BluRay-OLD'.$older).'" title="View file list">', $section);
        $this->assertStringContainsString('title="Posted Sep 20, 2026, 8:00 AM · Added Sep 20, 2026, 8:00 AM">Sep 20, 2026</td>', $section);
        // the 2 × 2 buttons without Follow: Download and Copy link on top, Cart alone below
        $row = $this->between($response, 'data-release-row', '</tr>');
        preg_match_all('/aria-label="([^"]+)"/', (string) strstr($row, '<div class="tv-actions">'), $buttons);
        $this->assertSame(['Download NZB', 'Copy NZB link for SABnzbd or NZBGet', 'Add to cart'], $buttons[1]);
        $this->assertStringContainsString('<span class="tv-action tv-action-slot" aria-hidden="true"></span>', $row);
        $this->assertStringNotContainsString('data-watch', $section);
        // the floating selection bar
        $response->assertSeeInOrder(['<div class="tv-bulk" x-show="selectedCount" x-cloak>', 'Download NZBs', 'Add to cart', 'Clear selection'], false);
    }

    public function test_the_largest_film_pages_at_50_with_the_bottom_pager_and_go_to_page_opening_at_the_releases_heading(): void
    {
        $this->film(self::FILM, 'Big');
        foreach (range(1, 152) as $minute) {
            $this->movie(self::FILM, sprintf('2026-09-%02d %02d:%02d:00', intdiv($minute, 60) + 1, 0, $minute % 60), name: sprintf('Big.%03d', $minute));
        }
        $film = route('movies.film', ['movieinfoId' => self::FILM]);

        $first = $this->page('/movies/film/'.self::FILM)->assertOk();
        $section = $this->between($first, '<section class="tv-film-releases"', '</section>');
        $this->assertStringContainsString('Showing 1–50 of 152 releases', $section);
        $this->assertStringContainsString('Page 1 of 4', $section);
        $this->assertCount(50, $this->rowNames($section));
        $this->assertSame('Big.152', $this->rowNames($section)[0]);
        $this->assertStringContainsString('<a href="'.$film.'?page=2#releases" rel="next" aria-label="Next page">', $section);
        $this->assertStringContainsString('<form method="GET" action="'.$film.'#releases">', $section);
        $this->assertStringContainsString('<label for="pager-go-to">Go to page</label>', $section);

        $second = $this->between($this->page('/movies/film/'.self::FILM.'?page=2')->assertOk(), '<section class="tv-film-releases"', '</section>');
        $this->assertStringContainsString('Showing 51–100 of 152 releases', $second);
        $this->assertSame('Big.102', $this->rowNames($second)[0]);
        $this->assertStringContainsString('<a href="'.$film.'#releases" rel="prev" aria-label="Previous page"', $second);

        $last = $this->between($this->page('/movies/film/'.self::FILM.'?page=4')->assertOk(), '<section class="tv-film-releases"', '</section>');
        $this->assertStringContainsString('Showing 151–152 of 152 releases', $last);
        $this->assertSame(['Big.002', 'Big.001'], $this->rowNames($last));
        $this->page('/movies/film/'.self::FILM.'?page=9&sort=size')->assertRedirect($film.'?sort=size&page=4#releases');
    }

    public function test_the_headers_sort_descending_first_worst_resolution_first_and_mark_the_sorted_heading(): void
    {
        $this->film(self::FILM, 'Heat');
        foreach ([['Uhd', 1, 40], ['Unknown', 0, 10], ['Sd', 4, 20], ['FullHd', 2, 50], ['Hd', 3, 30]] as $day => [$name, $resolution, $gigabytes]) {
            $this->movie(self::FILM, sprintf('2026-09-%02d 08:00:00', $day + 1), name: $name, resolution: $resolution, size: $gigabytes * 1073741824);
        }

        $posted = $this->between($this->page('/movies/film/'.self::FILM)->assertOk(), '<section class="tv-film-releases"', '</section>');
        $this->assertSame(['Hd', 'FullHd', 'Sd', 'Unknown', 'Uhd'], $this->rowNames($posted));
        $this->assertSame(['posted' => 'descending'], $this->sortedHeadings($posted));
        foreach (['resolution' => 'Resolution', 'size' => 'Size', 'posted' => 'Posted'] as $key => $label) {
            $this->assertStringContainsString('<button type="button" data-sort="'.$key.'">'.$label.'<i class="fas fa-sort" aria-hidden="true"></i></button>', $posted);
        }

        $orders = [
            'size' => [['FullHd', 'Uhd', 'Hd', 'Sd', 'Unknown'], 'descending'],
            'size_asc' => [['Unknown', 'Sd', 'Hd', 'Uhd', 'FullHd'], 'ascending'],
            'resolution' => [['Unknown', 'Sd', 'Hd', 'FullHd', 'Uhd'], 'descending'],
            'resolution_asc' => [['Uhd', 'FullHd', 'Hd', 'Sd', 'Unknown'], 'ascending'],
            'posted_asc' => [['Uhd', 'Unknown', 'Sd', 'FullHd', 'Hd'], 'ascending'],
            'nonsense' => [['Hd', 'FullHd', 'Sd', 'Unknown', 'Uhd'], 'descending'],
        ];
        foreach ($orders as $sort => [$names, $direction]) {
            $section = $this->between($this->page('/movies/film/'.self::FILM.'?sort='.$sort)->assertOk(), '<section class="tv-film-releases"', '</section>');
            $this->assertSame($names, $this->rowNames($section), $sort);
            $this->assertSame([str_replace('_asc', '', $sort === 'nonsense' ? 'posted' : $sort) => $direction], $this->sortedHeadings($section), $sort);
        }
    }

    public function test_resolution_and_source_narrow_the_table_return_to_page_1_and_name_the_filters_when_nothing_matches(): void
    {
        $this->film(self::FILM, 'Heat');
        $this->movie(self::FILM, '2026-09-20 08:00:00', name: 'Web1080', resolution: 2, source: 1);
        $this->movie(self::FILM, '2026-09-19 08:00:00', name: 'Bluray1080', resolution: 2, source: 2);
        $this->movie(self::FILM, '2026-09-18 08:00:00', name: 'Remux1080', resolution: 2, source: 5);
        $this->movie(self::FILM, '2026-09-17 08:00:00', name: 'Web720', resolution: 3, source: 1);
        $film = route('movies.film', ['movieinfoId' => self::FILM]);

        $both = $this->between($this->page('/movies/film/'.self::FILM.'?resolution[]=1080p&source[]=bluray&page=1')->assertOk(), '<section class="tv-film-releases"', '</section>');
        $this->assertSame(['Bluray1080', 'Remux1080'], $this->rowNames($both));
        $this->assertStringContainsString('Showing 1–2 of 2 releases', $both);
        $this->assertStringContainsString('<a href="'.$film.'#releases" class="pager-line-clear" data-clear-all aria-hidden="false">Clear all</a>', $both);
        // the header counts every visible release, never the filtered table
        $this->assertStringContainsString('4 releases', $this->between($this->page('/movies/film/'.self::FILM.'?resolution[]=1080p')->assertOk(), 'data-part="film meta line"', '</div>'));

        $none = $this->page('/movies/film/'.self::FILM.'?resolution[]=4k&resolution[]=1080p&source[]=dvd&sort=size&_fragment=list')->assertOk();
        $html = (string) $none->getContent();
        $this->assertStringNotContainsString('tv-show-head', $html);
        $this->assertStringNotContainsString('tv-similar', $html);
        $this->assertStringContainsString('Showing 0 releases', $html);
        $this->assertStringContainsString('<p class="tv-empty">No releases of this film match 4K or 1080p · DVD.</p>', $html);
        $this->assertStringContainsString('<a href="'.$film.'?sort=size#releases" class="pager-line-clear" data-clear-all', $html);

        $clear = $this->between($this->page('/movies/film/'.self::FILM)->assertOk(), '<section class="tv-film-releases"', '</section>');
        $this->assertStringContainsString('class="pager-line-clear is-hidden" data-clear-all aria-hidden="true" tabindex="-1">Clear all</a>', $clear);
    }

    public function test_only_releases_in_the_movies_categories_the_viewer_may_see_count(): void
    {
        $this->film(self::FILM, 'Heat');
        $this->movie(self::FILM, '2026-09-20 08:00:00', name: 'Visible');
        $this->movie(self::FILM, '2026-09-21 08:00:00', name: 'Passworded', password: 1);
        $this->movie(self::FILM, '2026-09-22 08:00:00', name: 'Excluded', categories: self::FOREIGN);
        $this->movie(self::FILM, '2026-09-23 08:00:00', name: 'In.TV', categories: self::TV_HD, resolution: 1);
        $this->excludeForUser(self::FOREIGN);

        $response = $this->page('/movies/film/'.self::FILM)->assertOk();
        $this->assertSame(['Visible'], $this->rowNames($this->between($response, '<section class="tv-film-releases"', '</section>')));
        $this->assertSame('2000 · 1 release · latest Sep 20, 2026 · best 1080p', $this->text($this->element((string) $response->getContent(), 'data-part="film meta line"')));

        Settings::query()->updateOrInsert(['name' => 'showpasswordedrelease'], ['value' => '1']);
        Cache::flush();
        $this->assertSame(['Passworded', 'Visible'], $this->rowNames($this->between($this->page('/movies/film/'.self::FILM)->assertOk(), '<section class="tv-film-releases"', '</section>')));
    }

    public function test_similar_films_are_the_six_best_by_the_rule_among_films_the_viewer_may_see(): void
    {
        $this->film(self::FILM, 'Heat', '1995');
        $this->genreOf(self::FILM, self::CRIME);
        $this->genreOf(self::FILM, self::DRAMA, 1);
        DB::table('people')->insert([['id' => 1, 'name' => 'Person 1'], ['id' => 2, 'name' => 'Person 2']]);
        // Person 1 directs and acts: a person counts once however many roles they hold in either film.
        DB::table('movie_people')->insert([['movieinfo_id' => self::FILM, 'people_id' => 1, 'role' => 0, 'position' => 0], ['movieinfo_id' => self::FILM, 'people_id' => 2, 'role' => 1, 'position' => 0],
            ['movieinfo_id' => self::FILM, 'people_id' => 1, 'role' => 1, 'position' => 1]]);
        $this->movie(self::FILM);
        // id => [genres, people, year]: score = 2 × genres + 3 × people − |year gap| / 10, no year term without both years.
        $candidates = [
            20 => [[self::CRIME, self::DRAMA], [1], '1995'],  // 7
            21 => [[], [1, 2], '2005'],                       // 5
            22 => [[self::DRAMA], [], ''],                    // 2, no year: no year term
            23 => [[self::CRIME, self::DRAMA], [], '1955'],   // 0
            24 => [[self::CRIME], [], '1995'],                // 2, ties with 22: the lower id first
            25 => [[self::DRAMA], [], '2000'],                // 1.5
            26 => [[], [2], '2025'],                          // 0, ties with 23: seventh, left out
            27 => [[self::DRAMA], [1], '1995'],               // 5, but nothing the viewer may see
            28 => [[], [], '1995'],                           // shares nothing
            29 => [[self::THRILLER], [], '1995'],             // shares a genre the film does not have
            30 => [[], [1], '1995'],                          // 3: person 1 directs and acts here too, counted once
            31 => [[self::CRIME, self::DRAMA], [2], '1995'],  // 7, but only in a category the viewer excludes
        ];
        foreach ($candidates as $id => [$genres, $people, $year]) {
            $this->film($id, 'Film '.$id, $year, ['rating' => '7.5', 'vote_count' => 100, 'content_rating_us' => 'R']);
            foreach ($genres as $position => $genre) {
                $this->genreOf($id, $genre, $position);
            }
            foreach ($people as $position => $person) {
                DB::table('movie_people')->insert(['movieinfo_id' => $id, 'people_id' => $person, 'role' => 1, 'position' => $position]);
            }
            $this->movie($id, categories: $id === 31 ? self::FOREIGN : self::HD, password: $id === 27 ? 1 : 0);
        }
        DB::table('movie_people')->insert(['movieinfo_id' => 30, 'people_id' => 1, 'role' => 0, 'position' => 0]);
        $this->movie(20);
        $this->excludeForUser(self::FOREIGN);

        $response = $this->page('/movies/film/'.self::FILM)->assertOk();
        $similar = $this->between($response, '<section class="tv-similar" aria-labelledby="tv-similar-heading">', '</section>');
        $this->assertStringStartsWith('<h2 id="tv-similar-heading">Similar films</h2>', $similar);
        $this->assertSame([20, 21, 30, 22, 24, 25], $this->similarIds($response));
        // the Films wall's tile
        $this->assertMatchesRegularExpression('/<a class="tv-tile is-film" href="'.preg_quote(url('/movies/film/20'), '/').'" data-film="20"\s*>/', $similar);
        $this->assertMatchesRegularExpression('/<span class="tv-tile-what"\s*>1995 · <span class="tv-tile-genre">Crime<\/span>, <span class="tv-tile-genre">Drama<\/span><\/span>/', $similar);
        $this->assertStringContainsString('<span class="tv-tile-more">7.5 · R</span>', $similar);
        $this->assertStringContainsString('<span class="tv-tile-more is-count">2 releases</span>', $similar);
        $this->assertLessThan(strpos((string) $response->getContent(), 'class="tv-similar"'), strpos((string) $response->getContent(), 'class="tv-film-releases"'));
        // The release list ends the page only without Similar films; then it carries its 70px end space.
        $response->assertSee('<div x-ref="list">', false)->assertDontSee('tv-list-end', false);

        Settings::query()->updateOrInsert(['name' => 'showpasswordedrelease'], ['value' => '1']);
        Cache::flush();
        $this->assertSame([20, 21, 27, 30, 22, 24], $this->similarIds($this->page('/movies/film/'.self::FILM)));

        $this->page('/movies/film/28')->assertOk()->assertDontSee('Similar films')->assertDontSee('tv-similar', false)
            ->assertSee('<div x-ref="list" class="tv-list-end">', false);
    }

    public function test_another_film_opens_with_its_cells_and_sort_reset(): void
    {
        $this->film(self::FILM, 'Heat', '1995');
        $this->genreOf(self::FILM, self::CRIME);
        $this->movie(self::FILM);
        $this->similarTo(self::FILM, 20, [self::CRIME]);

        $filtered = $this->page('/movies/film/'.self::FILM.'?resolution[]=4k&source[]=web&sort=size_asc')->assertOk();
        $this->assertMatchesRegularExpression('/<a class="tv-tile is-film" href="'.preg_quote(route('movies.film', ['movieinfoId' => 20]), '/').'" data-film="20"/', (string) $filtered->getContent());

        $other = $this->page('/movies/film/20')->assertOk();
        $section = $this->between($other, '<section class="tv-film-releases"', '</section>');
        $this->assertDoesNotMatchRegularExpression('/checkbox-menu is-cell is-set/', $section);
        $this->assertSame(['posted' => 'descending'], $this->sortedHeadings($section));
    }

    public function test_the_back_link_follows_the_list_or_wall_the_user_came_from_across_film_pages(): void
    {
        $this->film(self::FILM, 'Heat');
        $this->film(11, 'Ronin');
        $this->movie(self::FILM);
        $this->movie(11);
        $wall = route('movies.films', ['genre' => [self::CRIME], 'page' => 2]);

        $this->withHeader('referer', $wall)->page('/movies/film/'.self::FILM)->assertSee('class="tv-back" href="'.e($wall).'"', false)->assertSee('</i>Films</a>', false);
        $this->withHeader('referer', url('/movies/film/'.self::FILM))->page('/movies/film/11')->assertSee('class="tv-back" href="'.e($wall).'"', false)->assertSee('</i>Films</a>', false);
        $list = route('movies.releases', ['resolution' => ['4k'], 'page' => 3]);
        $this->withHeader('referer', $list)->page('/movies/film/11')->assertSee('class="tv-back" href="'.e($list).'"', false)->assertSee('</i>Movie releases</a>', false);
        $this->withHeader('referer', 'https://elsewhere.example/movies/films')->page('/movies/film/11')
            ->assertSee('class="tv-back" href="'.route('movies.releases').'"', false)->assertSee('</i>Movie releases</a>', false);
    }

    public function test_the_list_and_the_wall_lead_here_and_the_page_needs_the_movies_permission(): void
    {
        $this->film(self::FILM, 'Heat', '1995');
        $this->movie(self::FILM);

        $this->page('/movies')->assertSee('<a class="tv-show-line" href="'.route('movies.film', ['movieinfoId' => self::FILM]).'"', false);
        $this->page('/movies/films')->assertSee('href="'.route('movies.film', ['movieinfoId' => self::FILM]).'" data-film="'.self::FILM.'"', false);

        $this->user?->revokePermissionTo('view movies');
        $this->page('/movies/film/'.self::FILM)->assertForbidden();
    }

    private function film(int $id, string $title, string $year = '2000', array $attributes = []): void
    {
        DB::table('movieinfo')->insert(['id' => $id, 'imdbid' => sprintf('%07d', $id), 'title' => $title, 'year' => $year, ...$attributes]);
    }

    private function genreOf(int $film, int $genre, int $position = 0): void
    {
        DB::table('movie_genres')->insert(['movieinfo_id' => $film, 'genres_id' => $genre, 'position' => $position]);
    }

    /** A film sharing the given genres with another, with a release the viewer may see. */
    private function similarTo(int $film, int $id, array $genres): void
    {
        $this->film($id, 'Film '.$id);
        foreach ($genres as $position => $genre) {
            $this->genreOf($id, $genre, $position);
        }
        $this->movie($id);
    }

    private function movie(int $film, string $posted = '2026-09-20 10:00:00', int $categories = self::HD, int $password = 0, ?string $name = null,
        int $resolution = 2, int $source = 1, int $size = 1073741824, string $group = '', string $from = ''): int
    {
        $name ??= 'Film.'.$film.'.Release.'.($this->nextRelease + 1);
        $this->nextRelease++;
        $groupId = 0;
        if ($group !== '') {
            $groupId = (int) (DB::table('usenet_groups')->where('name', $group)->value('id') ?? DB::table('usenet_groups')->insertGetId(['name' => $group]));
        }
        $id = $this->release($name.'#'.$this->nextRelease, ['categories_id' => $categories, 'movieinfo_id' => $film, 'passwordstatus' => $password,
            'postdate' => $posted, 'adddate' => $posted, 'imdbid' => null, 'videos_id' => 0, 'tv_episodes_id' => 0, 'resolution' => $resolution,
            'source' => $source, 'size' => $size, 'groups_id' => $groupId, 'fromname' => $from]);
        DB::table('releases')->where('id', $id)->update(['name' => $name, 'searchname' => $name, 'guid' => md5($name.$id)]);

        return $id;
    }

    private function poster(string $imdbId): void
    {
        File::ensureDirectoryExists($this->covers.'/movies');
        File::put($this->covers.'/movies/'.$imdbId.'-cover.jpg', 'jpg');
    }

    private function excludeForUser(int $category): void
    {
        $this->user ??= $this->browserUser();
        DB::table('user_excluded_categories')->insert(['users_id' => $this->user->id, 'categories_id' => $category]);
    }

    private function page(string $uri, ?User $user = null): TestResponse
    {
        $this->resetGlobalComposerState();

        return $this->actingAs($user ?? $this->user ??= $this->browserUser())->get($uri);
    }

    private function between(TestResponse $response, string $from, string $to): string
    {
        $html = (string) $response->getContent();
        $start = strpos($html, $from);
        $this->assertNotFalse($start, 'Missing '.$from);
        $start += strlen($from);

        return trim(substr($html, $start, strpos($html, $to, $start) - $start));
    }

    /** The element whose opening tag carries the attribute, up to its closing </div>. */
    private function element(string $html, string $attribute): string
    {
        $at = strpos($html, $attribute);
        $this->assertNotFalse($at, 'Missing '.$attribute);
        $start = (int) strrpos(substr($html, 0, $at), '<');

        return substr($html, $start, strpos($html, '</div>', $at) - $start);
    }

    /** Text as a reader sees it (textContent with runs of white space as one). */
    private function text(string $html): string
    {
        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) preg_replace('/>\s*</', '> <', $html)), ENT_QUOTES)));
    }

    /** @return list<string> the release names in table order */
    private function rowNames(string $html): array
    {
        preg_match_all('/<a class="tv-release-name" href="[^"]+" title="[^"]*">([^<]+)<\/a>/', $html, $names);

        return array_map(static fn (string $name): string => html_entity_decode($name, ENT_QUOTES), $names[1]);
    }

    /** @return array<string, string> the sorted heading's key and direction */
    private function sortedHeadings(string $html): array
    {
        preg_match_all('/<th[^>]*aria-sort="([a-z]+)"[^>]*><button type="button" data-sort="([a-z]+)"/', $html, $sorted);

        return array_combine($sorted[2], $sorted[1]);
    }

    /** @return list<int> */
    private function similarIds(TestResponse $response): array
    {
        preg_match_all('/data-film="(\d+)"/', $this->between($response, 'class="tv-similar"', '</section>'), $ids);

        return array_map('intval', $ids[1]);
    }
}
