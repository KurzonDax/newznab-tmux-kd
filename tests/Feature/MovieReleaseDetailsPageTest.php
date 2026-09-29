<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\User;
use App\Services\Releases\ReleaseSearchService;
use App\Services\Search\DTO\ReleaseSearchQuery;
use App\Services\Search\DTO\SearchPage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Mockery;
use ReflectionMethod;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\AssertsFollowWording;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/** The Movies release details page, GET /details/{guid} for a Movies release (docs/proposals/movies-redesign/SPEC.md 5C; the details checks of prototype/check.mjs). */
final class MovieReleaseDetailsPageTest extends TestCase
{
    use AssertsFollowWording;
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    private const FILM = 10;

    private const OTHER_FILM = 11;

    private const HD = 2040;

    private const SD = 2030;

    private const TV_HD = 5040;

    private const GB = 1073741824;

    private ?User $user = null;

    private string $covers = '';

    private int $nextRelease = 0;

    /** @var list<array{int, string, list<int>, int|null}> searchSimilarMovies' calls: release id, name, exclusions, film left out */
    private array $similarCalls = [];

    /** @var list<int> the ids searchSimilarMovies answers with */
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
            'adddate', 'postdate', 'grabs', 'comments', 'completion', 'repair_outcome', 'rescan_outcome', 'passwordstatus', 'nfostatus',
            'haspreview', 'jpgstatus', 'videostatus', 'groups_id', 'fromname', 'isrenamed', 'additional_pp_claim_token', 'imdbid', 'movieinfo_id',
            'videos_id', 'tv_episodes_id', 'musicinfo_id', 'consoleinfo_id', 'gamesinfo_id', 'bookinfo_id', 'anidbid', 'predb_id', 'resolution', 'source']);
        foreach (['usenet_groups', 'users_releases', 'user_series', 'user_movies', 'videos', 'movieinfo', 'people', 'genres', 'movie_genres',
            'movie_people', 'release_audio_tags', 'release_video_clips', 'languages', 'release_audio_languages', 'releases_groups', 'release_regexes',
            'release_comments', 'release_nfos', 'video_data', 'audio_data', 'release_subtitles', 'media_infos', 'media_info_probes', 'media_info_tracks',
            'predb', 'release_tv_episodes', 'tv_episodes', 'tv_info', 'networks', 'video_genres', 'video_people'] as $table) {
            $tables->create($table);
        }
        DB::table('root_categories')->insert([['id' => 2000, 'title' => 'Movies', 'status' => 1], ['id' => 5000, 'title' => 'TV', 'status' => 1]]);
        DB::table('categories')->insert([
            ['id' => self::SD, 'title' => 'SD', 'root_categories_id' => 2000, 'status' => 1],
            ['id' => self::HD, 'title' => 'HD', 'root_categories_id' => 2000, 'status' => 1],
            ['id' => self::TV_HD, 'title' => 'HD', 'root_categories_id' => 5000, 'status' => 1],
        ]);
        DB::table('usenet_groups')->insert(['id' => 99, 'name' => 'alt.binaries.example.movies']);
        DB::table('movieinfo')->insert(['id' => self::FILM, 'imdbid' => '0113277', 'title' => 'Heat', 'year' => '1995', 'tagline' => 'A Los Angeles crime saga',
            'plot' => 'A group of high-end professional thieves start to feel the heat from the LAPD.', 'rating' => '8.30', 'vote_count' => 7000,
            'content_rating_us' => 'R', 'original_language' => 'en']);
        DB::table('movieinfo')->insert(['id' => self::OTHER_FILM, 'imdbid' => '0100000', 'title' => 'Heat Wave', 'year' => '2022', 'plot' => '']);
        foreach ([1 => 'Crime', 2 => 'Drama'] as $id => $title) {
            DB::table('genres')->insert(['id' => $id, 'title' => $title, 'type' => 2000, 'disabled' => 0]);
            DB::table('movie_genres')->insert(['movieinfo_id' => self::FILM, 'genres_id' => $id, 'position' => $id]);
        }
        DB::table('people')->insert(['id' => 1, 'name' => 'Michael Mann']);
        DB::table('movie_people')->insert(['movieinfo_id' => self::FILM, 'people_id' => 1, 'role' => 0, 'position' => 0]);
        foreach (range(2, 11) as $person) {
            DB::table('people')->insert(['id' => $person, 'name' => 'Actor '.$person]);
            DB::table('movie_people')->insert(['movieinfo_id' => self::FILM, 'people_id' => $person, 'role' => 1, 'position' => $person]);
        }
        $this->covers = $this->makeTempDirectory('movie-details-covers');
        config(['nntmux_settings.covers_path' => $this->covers]);
        $search = Mockery::mock(ReleaseSearchService::class)->makePartial();
        $search->shouldReceive('searchSimilarMovies')->andReturnUsing(function (int $id, string $name, array $exclusions, ?int $film): array {
            $this->similarCalls[] = [$id, $name, $exclusions, $film];

            return $this->similarIds;
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

    public function test_the_header_has_the_crumbs_poster_heading_chips_and_four_buttons_and_no_report_edit_or_failure_line(): void
    {
        $this->poster('0113277');
        $id = $this->movie(self::FILM, name: 'Heat.1995.1080p.BluRay.x264-GRP', resolution: 2, source: 2, fromname: 'paperboat <pb@example.invalid>');
        DB::table('releases')->where('id', $id)->update(['grabs' => 38, 'nfostatus' => 1, 'completion' => 94]);
        DB::table('release_nfos')->insert(['releases_id' => $id, 'nfo' => 'NFO']);

        $response = $this->details($id)->assertOk()->assertViewIs('details.movies.index');
        $html = (string) $response->getContent();
        $filmUrl = route('movies.film', ['movieinfoId' => self::FILM]);

        $response->assertSeeInOrder(['<nav class="tv-crumbs" aria-label="Breadcrumb">', '<a href="'.route('movies.releases').'">Movie releases</a>',
            '<a href="'.$filmUrl.'">Heat</a>', '<span>Movies &gt; HD</span>'], false)
            ->assertSee('<a class="tv-details-art" href="'.$filmUrl.'" tabindex="-1" aria-hidden="true" data-part="details poster">', false)
            ->assertSee('<h1 data-part="details heading"><a href="'.$filmUrl.'">Heat</a> · 1995</h1>', false)
            ->assertSee('<div class="tv-details-name" data-part="details release name">Heat.1995.1080p.BluRay.x264-GRP</div>', false)
            ->assertSeeInOrder(['tv-details-chips', 'resolution-chip-1080', '<span class="tv-source-chip">Blu-ray</span>', '94% complete', 'nfo-badge', 'tv-details-origin',
                'href="'.route('browse.all', ['group' => 'alt.binaries.example.movies']).'"', 'a.b.example.movies',
                'href="'.route('browse.all', ['poster' => 'paperboat <pb@example.invalid>']).'"'], false)
            ->assertDontSee('Report')->assertDontSee('Edit release')->assertDontSee('reported download failure')->assertDontSee('Trailer')
            ->assertDontSee('Search films or actors')->assertDontSee('style="', false);
        $this->assertSame(['Download NZB', 'Copy NZB link', 'Add to cart', 'Follow film'], $this->buttons($html));
        $this->assertSame(['Overview', 'Files (1)', 'Media info', 'NFO', 'Comments (0)'], $this->tabs($html));
        $this->assertNoWatchWording($html, 'The Movies details page');
        $response->assertSee('x-data="movieReleaseDetails"', false)->assertSee('x-on:submit="handleSubmit"', false)->assertSee('x-data="tvImageDialog"', false);
    }

    public function test_the_header_buttons_are_the_tv_details_page_s_download_coral_copy_and_cart_neutral_follow_film_violet(): void
    {
        $id = $this->movie(self::FILM);
        $guid = $this->guid($id);
        $picker = route('watchlist.picker', ['root' => 'movies', 'id' => '0113277']);

        $actions = $this->between($this->details($id)->assertOk(), '<div class="tv-details-actions">', '</div>');
        $this->assertSame('<a class="tv-details-button download-nzb" href="'.route('getnzb.guid', $guid).'" data-part="details primary button"><i class="fas fa-download" aria-hidden="true"></i>Download NZB</a>'
            .'<button type="button" class="tv-details-button is-secondary" data-copy-nzb="'.$guid.'" data-part="details secondary button"><i class="fas fa-link" aria-hidden="true"></i>Copy NZB link</button>'
            .'<button type="button" class="tv-details-button is-secondary" data-cart="'.$guid.'" data-cart-label aria-pressed="false" title="Add to cart"><i class="fas fa-cart-shopping" aria-hidden="true"></i>'
            .'<span class="tv-state-label"><span class="is-off">Add to cart</span><span class="is-on">In cart</span></span></button>'
            .'<button type="button" class="tv-details-button tv-follow-show" data-watch-picker="'.$picker.'" data-watch-key="movies:0113277" data-watch-title="Heat" data-watch-off-title="Follow this film" data-watch-on-title="Following this film · click to unfollow" data-watched="0" aria-pressed="false" title="Follow this film">'
            .'<i class="far fa-bookmark" aria-hidden="true"></i><span class="tv-state-label"><span class="is-off">Follow film</span><span class="is-on">Following film</span></span></button>',
            (string) preg_replace('/>\s+</', '><', $actions));

        DB::table('users_releases')->insert(['users_id' => $this->user()->id, 'releases_id' => $id]);
        DB::table('user_movies')->insert(['users_id' => $this->user()->id, 'imdbid' => '0113277', 'categories' => '2040']);
        $pressed = $this->between($this->details($id), '<div class="tv-details-actions">', '</div>');
        $this->assertStringContainsString('data-cart-label aria-pressed="true" title="In cart · click to remove"><i class="fas fa-cart-shopping" aria-hidden="true"></i>', $pressed);
        $this->assertStringContainsString('data-watched="1" aria-pressed="true" title="Following this film · click to unfollow"><i class="fas fa-bookmark" aria-hidden="true"></i>', $pressed);
        $this->assertNoWatchWording((string) $this->details($id)->getContent(), 'A followed Movies details page');
    }

    public function test_overview_shows_the_preview_the_tagline_in_quotes_and_the_plot_then_the_facts_and_the_predb_block_only_with_a_match(): void
    {
        $id = $this->movie(self::FILM, name: 'Heat.1995.720p.WEB-DL', resolution: 3, source: 1);
        DB::table('releases')->where('id', $id)->update(['haspreview' => 1, 'grabs' => 5]);
        File::ensureDirectoryExists($this->covers.'/preview');
        File::put($this->covers.'/preview/'.$this->guid($id).'_thumb.jpg', 'jpg');

        $overview = $this->between($this->details($id), 'aria-labelledby="tab-overview" data-details-panel>', '<section id="files"');
        $this->assertStringContainsString('class="tv-details-preview preview-badge"', $overview);
        $this->assertSame(['tv-details-preview', 'tv-details-tagline', 'tv-details-plot', 'tv-details-facts'], $this->classesInOrder($overview, ['tv-details-preview', 'tv-details-tagline', 'tv-details-plot', 'tv-details-facts']));
        $this->assertStringContainsString('<b class="tv-details-tagline">“A Los Angeles crime saga”</b>', $overview);
        $this->assertStringContainsString('<p class="tv-details-plot">A group of high-end professional thieves start to feel the heat from the LAPD.</p>', $overview);
        $this->assertSame(['Category' => 'Movies &gt; HD', 'Size' => '1.00 GB', 'Files' => '1', 'Completion' => '100%', 'Posted' => 'Sep 20, 2026, 10:00 AM',
            'Added' => 'Sep 20, 2026, 11:00 AM', 'Grabs' => '5', 'Group' => 'alt.binaries.example.movies', 'Poster' => '—', 'Password status' => 'None detected'], $this->facts($overview));
        $this->assertStringNotContainsString('PreDB', $overview);

        DB::table('predb')->insert(['id' => 5, 'title' => 'Heat.1995.720p.WEB-DL-GRP', 'source' => 'abgx', 'predate' => '2026-09-19 08:30:00', 'category' => '']);
        DB::table('releases')->where('id', $id)->update(['predb_id' => 5]);
        $withPre = $this->between($this->details($id), 'aria-labelledby="tab-overview" data-details-panel>', '<section id="files"');
        $predb = $this->between($this->details($id), '<section class="tv-details-predb" aria-labelledby="predb-heading">', '</section>');
        $this->assertStringContainsString('<h3 id="predb-heading">PreDB</h3>', $predb);
        $this->assertSame(['Title' => 'Heat.1995.720p.WEB-DL-GRP', 'Source' => 'abgx', 'Pre date' => 'Sep 19, 2026, 8:30 AM'], $this->facts($predb));
        $this->assertStringContainsString('<div class="is-wide"><dt>Title</dt>', (string) preg_replace('/>\s+</', '><', $predb));
        $this->assertGreaterThan(strpos($withPre, 'tv-details-facts'), strpos($withPre, 'tv-details-predb'));
    }

    public function test_about_the_film_has_genre_links_plain_tags_directed_by_the_first_eight_starring_and_the_film_page_link(): void
    {
        $id = $this->movie(self::FILM);

        $about = $this->between($this->details($id), '<aside class="tv-about">', '</aside>');
        $this->assertStringContainsString('<h2 data-part="about the film heading">About the film</h2>', $about);
        preg_match_all('/<a class="tv-tag" href="([^"]+)">([^<]+)<\/a>/', $about, $genres);
        $this->assertSame(['Crime', 'Drama'], $genres[2]);
        $this->assertSame([route('movies.films', ['genre' => [1]]), route('movies.films', ['genre' => [2]])], array_map('html_entity_decode', $genres[1]));
        preg_match_all('/<span class="tv-tag tv-tag-plain">([^<]+)<\/span>/', $about, $tags);
        $this->assertSame(['Score 8.3', 'R', 'English'], $tags[1]);
        preg_match_all('/<div class="tv-about-starring">(Directed by|Starring) (.*?)<\/div>/s', $about, $lines);
        $this->assertSame(['Directed by', 'Starring'], $lines[1]);
        preg_match_all('/<a href="([^"]+)">([^<]+)<\/a>/', $lines[2][1], $starring);
        $this->assertSame(['Actor 2', 'Actor 3', 'Actor 4', 'Actor 5', 'Actor 6', 'Actor 7', 'Actor 8', 'Actor 9'], $starring[2]);
        $this->assertSame(route('movies.films', ['person' => 2]), html_entity_decode($starring[1][0]));
        $this->assertStringContainsString('<a href="'.e(route('movies.films', ['person' => 1])).'">Michael Mann</a>', $lines[2][0]);
        $this->assertStringContainsString('<a class="tv-about-link" href="'.route('movies.film', ['movieinfoId' => self::FILM]).'">Film page</a>', $about);
    }

    public function test_all_n_releases_lists_the_viewers_releases_of_the_film_this_one_tinted_without_select_boxes_grabs_or_follow(): void
    {
        $current = $this->movie(self::FILM, '2026-09-20 10:00:00', name: 'Current.1080p');
        $other = $this->movie(self::FILM, '2026-09-21 10:00:00', name: 'Other.720p.WEB', resolution: 3, size: 41943040);
        DB::table('releases')->where('id', $other)->update(['completion' => 93]);
        $this->movie(self::FILM, '2026-09-22 10:00:00', categories: self::SD, name: 'Excluded.SD');
        $this->movie(self::FILM, '2026-09-23 10:00:00', password: 2, name: 'Passworded.1080p');
        $this->movie(self::OTHER_FILM, name: 'Different.Film');
        $this->excludeForUser(self::SD);

        $response = $this->details($current)->assertOk();
        $table = $this->between($response, '<section class="tv-siblings tv-list-end" id="releases" aria-labelledby="film-releases-heading" x-ref="releases" data-film-releases>', '</section>');
        $this->assertStringContainsString('<h2 id="film-releases-heading" data-part="film releases heading">All 2 releases of this film</h2>', $table);
        $this->assertSame([$other, $current], $this->rowIds($table));
        $this->assertSame(['Other.720p.WEB'], $this->linkedNames($table));
        $this->assertSeeOrder($table, ['Other.720p.WEB', '93% complete', 'resolution-chip-720', '<td class="tv-nowrap">WEB</td>', '<td class="tv-num">40 MB</td>', 'Current.1080p']);
        $this->assertMatchesRegularExpression('/<tr class="is-current"\s+aria-current="true"\s+data-release-row[^>]*>\s*<td class="tv-what"\s+data-part="current release row"\s*>\s*<span class="tv-release-name" title="Current.1080p">Current.1080p<\/span>\s*<div class="tv-this-release" data-part="current release label">The release on this page<\/div>/', $table);
        $this->assertSame(['Release', 'Resolution', 'Source', 'Size', 'Files', 'Posted', 'Actions'], $this->headings($table));
        $this->assertSame(['posted' => 'descending'], $this->sortedHeadings($table, 'data-sort'));
        foreach (['type="checkbox"', 'Grabs', 'data-watch', 'Same name posted', 'tv-show-line', 'pager-line', 'pager-full'] as $absent) {
            $this->assertStringNotContainsString($absent, $table);
        }
        $this->assertSame(2, substr_count($table, 'tv-action tv-action-slot'));

        DB::table('releases')->where('id', $other)->delete();
        $this->assertStringContainsString('>The only release of this film</h2>', (string) $this->details($current)->getContent());
    }

    public function test_the_table_pages_at_50_opening_on_the_page_holding_this_release_and_a_sort_returns_to_it(): void
    {
        $ids = [];
        foreach (range(1, 60) as $day) {
            // posted newest first puts the lowest day last; size ascends with the day
            $ids[$day] = $this->movie(self::FILM, Carbon::parse('2026-07-01 10:00:00')->addDays($day)->toDateTimeString(), size: $day * self::GB);
        }
        $current = $ids[5];

        $opening = $this->details($current)->assertOk();
        $table = $this->between($opening, 'data-film-releases>', '</section>');
        $this->assertStringContainsString('All 60 releases of this film', $table);
        $this->assertStringContainsString('Showing 51–60 of 60 releases', $table);
        $this->assertStringContainsString('<span class="pager-line-page" data-part="page x of y">Page 2 of 2</span>', $table);
        $this->assertStringContainsString('class="pager-line is-fixed"', $table);
        $this->assertStringContainsString('aria-current="true"', $table);
        $this->assertSame(array_reverse(array_slice($ids, 0, 10)), $this->rowIds($table));
        $this->assertStringContainsString('href="'.e(route('details', ['guid' => $this->guid($current), 'page' => 1]).'#releases').'"', $table);
        $this->assertStringContainsString('<form method="GET" action="'.route('details', ['guid' => $this->guid($current)]).'#releases">', $table);

        $first = $this->between($this->page('/details/'.$this->guid($current).'?page=1'), 'data-film-releases>', '</section>');
        $this->assertStringContainsString('Page 1 of 2', $first);
        $this->assertStringNotContainsString('aria-current="true"', $first);
        $this->assertCount(50, $this->rowIds($first));

        // largest first puts day 5 at rank 56: page 2 again; smallest first at rank 5: page 1
        $bySize = $this->between($this->page('/details/'.$this->guid($current).'?sort=size'), 'data-film-releases>', '</section>');
        $this->assertStringContainsString('Page 2 of 2', $bySize);
        $this->assertSame(['size' => 'descending'], $this->sortedHeadings($bySize, 'data-sort'));
        $this->assertStringContainsString('href="'.e(route('details', ['guid' => $this->guid($current), 'sort' => 'size', 'page' => 1]).'#releases').'"', $bySize);
        $smallest = $this->page('/details/'.$this->guid($current).'?sort=size_asc&_fragment=releases')->assertOk()->assertViewIs('details.movies.releases');
        $fragment = (string) $smallest->getContent();
        $this->assertStringStartsWith('<h2 id="film-releases-heading"', trim($fragment));
        $this->assertStringContainsString('Page 1 of 2', $fragment);
        $this->assertSame(array_slice($ids, 0, 50), $this->rowIds($fragment));
        $this->assertStringContainsString('aria-current="true"', $fragment);
        $this->assertStringNotContainsString('tv-details-head', $fragment);

        $this->page('/details/'.$this->guid($current).'?page=9')->assertOk()->assertSee('Page 2 of 2');
    }

    public function test_a_release_the_viewer_may_not_see_is_refused_and_left_out_of_its_film_table(): void
    {
        $visible = $this->movie(self::FILM, '2026-09-21 10:00:00', name: 'Visible.1080p');
        $hidden = $this->movie(self::FILM, '2026-09-20 10:00:00', categories: self::SD, name: 'Hidden.SD');
        $this->excludeForUser(self::SD);

        $this->details($hidden)->assertForbidden()->assertViewIs('errors.category-disabled')->assertViewHas('category', 'Movies - SD')
            ->assertSee('Movies - SD is hidden in your account preferences.')->assertDontSee('Hidden.SD');

        $table = $this->between($this->details($visible)->assertOk(), 'data-film-releases>', '</section>');
        $this->assertStringContainsString('The only release of this film', $table);
        $this->assertSame([$visible], $this->rowIds($table));
    }

    public function test_similar_releases_search_without_the_film_and_show_each_row_s_film_line_or_no_section(): void
    {
        $current = $this->movie(self::FILM, name: 'Heat.1995.1080p.BluRay');
        $wave = $this->movie(self::OTHER_FILM, '2026-09-22 10:00:00', name: 'Heat.Wave.2022.1080p');
        $loose = $this->release('Heat.Something.Else.1080p', ['categories_id' => self::HD, 'movieinfo_id' => null, 'postdate' => '2026-09-21 10:00:00', 'videos_id' => 0, 'tv_episodes_id' => 0]);
        $this->similarIds = [$wave, $loose];

        $response = $this->details($current)->assertOk();
        $this->assertSame([[$current, 'Heat.1995.1080p.BluRay', [], self::FILM]], $this->similarCalls);
        $similar = $this->between($response, '<section class="tv-siblings tv-similar-releases" aria-labelledby="similar-releases-heading" data-similar-releases>', '</section>');
        $this->assertStringContainsString('<h2 id="similar-releases-heading">Similar releases</h2>', $similar);
        $this->assertSame([$wave, $loose], $this->rowIds($similar));
        $this->assertSame(['Heat.Wave.2022.1080p', 'Heat.Something.Else.1080p'], $this->linkedNames($similar));
        $this->assertSame(1, substr_count($similar, 'tv-show-line'));
        $this->assertStringContainsString('<a class="tv-show-line" href="'.url('/movies/film/'.self::OTHER_FILM).'" title="Go to the film">Heat Wave · 2022</a>', $similar);
        $this->assertSame(['posted' => 'descending'], $this->sortedHeadings($similar, 'data-similar-sort'));
        $this->assertStringContainsString('data-size="1073741824" data-posted=', $similar);
        foreach (['type="checkbox"', 'Grabs', 'data-watch', 'is-current', 'pager'] as $absent) {
            $this->assertStringNotContainsString($absent, $similar);
        }
        $this->assertSeeInOrderOf($response, ['data-film-releases', 'data-similar-releases']);
        // The releases end the page only without Similar releases; then they carry its 70px end space.
        $response->assertSee('<section class="tv-siblings" id="releases"', false)->assertDontSee('tv-list-end', false);

        $this->similarIds = [];
        $this->details($current)->assertOk()->assertDontSee('Similar releases')
            ->assertSee('<section class="tv-siblings tv-list-end" id="releases"', false);
    }

    public function test_a_release_with_no_matched_film_has_its_name_as_the_heading_and_none_of_the_film_s_parts_but_keeps_similar_releases(): void
    {
        $id = $this->release('Some.Film.2019.1080p.WEB-DL', ['categories_id' => self::HD, 'movieinfo_id' => null, 'videos_id' => 0, 'tv_episodes_id' => 0,
            'postdate' => '2026-09-20 10:00:00', 'groups_id' => 99]);
        $match = $this->movie(self::OTHER_FILM, name: 'Some.Film.2019.720p');
        $this->similarIds = [$match];

        $response = $this->details($id)->assertOk();
        $html = (string) $response->getContent();
        $response->assertSee('<h1 class="is-release-name" data-part="details heading">Some.Film.2019.1080p.WEB-DL</h1>', false)
            ->assertSee('<div class="tv-details-head is-release-only">', false)->assertSee('<div class="tv-details-columns is-release-only">', false)
            ->assertDontSee('tv-details-art', false)->assertDontSee('About the film')->assertDontSee('data-film-releases', false)->assertDontSee('data-watch-picker', false)
            ->assertSee('Similar releases');
        $this->assertSame(1, substr_count($this->between($response, '<nav class="tv-crumbs" aria-label="Breadcrumb">', '</nav>'), '<a '));
        $this->assertSame(['Download NZB', 'Copy NZB link', 'Add to cart'], $this->buttons($html));
        $this->assertSame([[$id, 'Some.Film.2019.1080p.WEB-DL', [], null]], $this->similarCalls);
        $this->page('/details/'.$this->guid($id).'?_fragment=releases')->assertNotFound();
    }

    public function test_a_release_with_a_video_clip_has_the_clip_chip_opening_the_image_dialog_with_the_player(): void
    {
        $id = $this->movie(self::FILM);
        $this->details($id)->assertDontSee('clip-badge', false);

        DB::table('releases')->where('id', $id)->update(['videostatus' => 1, 'jpgstatus' => 1]);
        DB::table('release_video_clips')->insert(['releases_id' => $id, 'extension' => 'webm', 'mime' => 'video/webm']);
        $chips = $this->between($this->details($id), '<div class="tv-chips tv-details-chips">', '</div>');
        $this->assertSeeOrder($chips, ['sample-badge', 'clip-badge']);
        $this->assertMatchesRegularExpression('/<button[^>]*class="[^"]*clip-badge[^"]*"[^>]*data-video-url="'.preg_quote(route('preview.video', $this->guid($id)), '/').'"[^>]*data-video-type="video\/webm"[^>]*data-image-title="Video preview"[^>]*title="Watch video preview"[^>]*>\s*Clip\s*<\/button>/', $chips);
    }

    public function test_the_search_passes_the_film_left_out_to_the_index_and_the_fallback_sql_leaves_it_out(): void
    {
        $this->app->forgetInstance(ReleaseSearchService::class);
        $current = $this->movie(self::FILM, name: 'Heat.1995.1080p');
        $same = $this->movie(self::FILM, name: 'Heat.1995.720p');
        $other = $this->movie(self::OTHER_FILM, name: 'Heat.Wave.2022');
        $loose = $this->release('Heat.Loose.1080p', ['categories_id' => self::HD, 'movieinfo_id' => null, 'videos_id' => 0, 'tv_episodes_id' => 0]);
        $queries = [];
        Search::shouldReceive('isAvailable')->andReturn(true);
        Search::shouldReceive('searchReleasePage')->andReturnUsing(function (ReleaseSearchQuery $query) use (&$queries): SearchPage {
            $queries[] = $query;

            return new SearchPage(ids: [], total: 0, fuzzy: false, driver: 'manticore');
        });
        $service = app(ReleaseSearchService::class);
        $this->assertSame([], $service->searchSimilarMovies($current, 'Heat.1995.1080p', [], self::FILM));
        $service->searchSimilarMovies($current, 'Heat.1995.1080p', [], null);
        $this->assertSame([self::FILM, null], array_map(static fn (ReleaseSearchQuery $query): ?int => $query->excludedMovieinfoId, $queries));
        $this->assertSame(['searchname' => getSimilarName('Heat.1995.1080p')], $queries[0]->phrases);
        $this->assertSame([self::SD, self::HD], $queries[0]->categoryIds);
        $this->assertSame((int) config('nntmux.items_per_page'), $queries[0]->limit);

        $where = new ReflectionMethod($service, 'buildSearchWhereClause');
        $ids = [$current, $same, $other, $loose];
        $leftOut = $where->invoke($service, $ids, -1, '', '', -1, -1, -1, [], [2000], 0, 0, self::FILM);
        $this->assertStringContainsString('(r.movieinfo_id IS NULL OR r.movieinfo_id <> '.self::FILM.')', $leftOut);
        $found = static fn (string $sql): array => array_map(static fn (object $row): int => (int) $row->id, DB::select('SELECT r.id FROM releases r '.$sql.' ORDER BY r.id'));
        $this->assertSame([$other, $loose], $found($leftOut));
        $this->assertSame($ids, $found($where->invoke($service, $ids, -1, '', '', -1, -1, -1, [], [2000], 0, 0)));
    }

    /** Another root keeps today's page: DetailsControllerTest. */
    public function test_a_tv_release_keeps_the_tv_page(): void
    {
        $movie = $this->movie(self::FILM);
        $tv = $this->release('Show.S01E01.1080p', ['categories_id' => self::TV_HD, 'videos_id' => 0, 'tv_episodes_id' => 0]);

        $this->details($movie)->assertViewIs('details.movies.index');
        $this->details($tv)->assertViewIs('details.tv.index');
    }

    public function test_comments_still_post_to_the_details_url_and_return_to_the_comments_tab(): void
    {
        $id = $this->movie(self::FILM);
        $url = '/details/'.$this->guid($id);

        $this->actingAs($this->user())->post($url, ['txtAddComment' => 'Works well.'])->assertRedirect($url.'#comments');
        $response = $this->details($id)->assertSee('Works well.');
        $this->assertSame('Comments (1)', $this->tabs((string) $response->getContent())[4]);
    }

    private function movie(int $film, string $posted = '2026-09-20 10:00:00', int $categories = self::HD, int $password = 0, ?string $name = null,
        int $resolution = 2, int $source = 1, int $size = self::GB, string $fromname = ''): int
    {
        $number = ++$this->nextRelease;

        return $this->release($name ?? 'Film.'.$film.'.Release.'.$number, ['categories_id' => $categories, 'movieinfo_id' => $film, 'passwordstatus' => $password,
            'postdate' => $posted, 'adddate' => Carbon::parse($posted)->addHour()->toDateTimeString(), 'imdbid' => null, 'videos_id' => 0, 'tv_episodes_id' => 0,
            'resolution' => $resolution, 'source' => $source, 'size' => $size, 'groups_id' => 99, 'fromname' => $fromname, 'guid' => md5('movie release '.$number),
            'completion' => 100, 'totalpart' => 1]);
    }

    private function poster(string $imdbId): void
    {
        File::ensureDirectoryExists($this->covers.'/movies');
        File::put($this->covers.'/movies/'.$imdbId.'-cover.jpg', 'jpg');
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

    /** @return list<string> the release names that link to their details */
    private function linkedNames(string $html): array
    {
        preg_match_all('/<a class="tv-release-name" href="[^"]+" title="[^"]*">([^<]+)<\/a>/', $html, $names);

        return $names[1];
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

    /**
     * @param  list<string>  $classes
     * @return list<string> the classes in the order they first appear
     */
    private function classesInOrder(string $html, array $classes): array
    {
        $at = array_filter(array_combine($classes, array_map(static fn (string $class): int|false => strpos($html, 'class="'.$class), $classes)), static fn (int|false $position): bool => $position !== false);
        asort($at);

        return array_keys($at);
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

    /** @param list<string> $needles */
    private function assertSeeInOrderOf(TestResponse $response, array $needles): void
    {
        $this->assertSeeOrder((string) $response->getContent(), $needles);
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
