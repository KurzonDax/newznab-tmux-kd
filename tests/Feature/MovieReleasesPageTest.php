<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\MovieFilmFilters;
use App\Data\MovieReleaseFilters;
use App\Enums\ReleaseSort;
use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\Settings;
use App\Models\User;
use App\Services\Releases\MovieReleaseList;
use App\Services\Releases\ReleaseBrowseService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\AssertsFollowWording;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/** The Movie releases screen, GET /movies (issue #834; the list checks of docs/proposals/movies-redesign/prototype/check.mjs). */
final class MovieReleasesPageTest extends TestCase
{
    use AssertsFollowWording;
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    private const HD = 2040;

    private const UHD = 2045;

    private const SD = 2030;

    private const BLURAY = 2060;

    private const THREE_D = 2050;

    private const FOREIGN = 2010;

    private const OTHER = 2999;

    private const WEBDL = 2080;

    private const DRAMA = 1;

    private const COMEDY = 2;

    private const HORROR = 3;

    private const ENGLISH = 1;

    private const HINDI = 2;

    private ?User $user = null;

    private string $covers = '';

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
            'haspreview', 'jpgstatus', 'groups_id', 'fromname', 'isrenamed', 'additional_pp_claim_token', 'imdbid', 'movieinfo_id', 'videos_id',
            'tv_episodes_id', 'musicinfo_id', 'consoleinfo_id', 'gamesinfo_id', 'bookinfo_id', 'anidbid', 'resolution', 'source']);
        foreach (['usenet_groups', 'users_releases', 'user_series', 'user_movies', 'videos', 'movieinfo', 'people', 'genres', 'movie_genres',
            'movie_people', 'release_audio_tags', 'release_video_clips', 'languages', 'release_audio_languages'] as $table) {
            $tables->create($table);
        }
        DB::table('root_categories')->insert(['id' => 2000, 'title' => 'Movies', 'status' => 1]);
        foreach ([self::FOREIGN => 'Foreign', self::SD => 'SD', self::HD => 'HD', self::UHD => 'UHD', self::THREE_D => '3D', self::BLURAY => 'BluRay',
            self::WEBDL => 'WEB-DL', self::OTHER => 'Other'] as $id => $title) {
            DB::table('categories')->insert(['id' => $id, 'title' => $title, 'root_categories_id' => 2000, 'status' => 1]);
        }
        $this->film(21, '0111161', 'Glass Meridian', '1994');
        $this->film(22, '0222222', 'Salt Harbour', '2024');
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

    public function test_the_page_lists_releases_newest_first_with_the_film_line_and_releases_without_a_film(): void
    {
        $this->poster('0111161');
        $this->movie('Glass.Meridian.1994.1080p.BluRay.x264-GRP', ['movieinfo_id' => 21, 'imdbid' => '0111161', 'postdate' => '2026-09-25 10:00:00']);
        // the film is the release's movieinfo_id, whatever its imdbid says (fog clarification 2)
        $this->movie('Salt.Harbour.2024.720p.WEB.h264-GRP', ['movieinfo_id' => 22, 'imdbid' => '0000000', 'postdate' => '2026-09-20 10:00:00']);
        $this->movie('Random.Upload.Without.A.Film-GRP', ['postdate' => '2026-09-24 10:00:00']);

        $response = $this->page('/movies')->assertOk()
            ->assertSee('<h1 data-part="page title">Movie releases</h1>', false)
            ->assertSeeInOrder(['Glass.Meridian.1994', 'Random.Upload.Without.A.Film', 'Salt.Harbour.2024'])
            ->assertSee('Glass Meridian · 1994')->assertSee('Salt Harbour · 2024')
            ->assertSee('href="'.url('/movies/film/21').'" title="Go to the film"', false)
            ->assertSee('href="'.url('/movies/film/22').'" title="Go to the film"', false)
            ->assertSee('2 hr ago')->assertSee('Sep 20, 2026')
            ->assertDontSee('style="', false);
        $html = (string) $response->getContent();
        $this->assertMatchesRegularExpression('/<div class="segmented" role="group" aria-label="View">\s*<a href="'.preg_quote(route('movies.releases'), '/').'" aria-current="page"[^>]*>Releases<\/a>\s*<a href="'.preg_quote(url('/movies/films'), '/').'"[^>]*>Films<\/a>\s*<\/div>\s*<div class="tv-search"/', $html);
        $response->assertSee('placeholder="Search films or actors"', false)->assertSee('data-search-url="'.route('movies.search').'"', false)
            ->assertSee('data-show-url="'.url('/movies/film').'"', false)->assertSee('data-shows-url="'.url('/movies/films').'"', false)
            ->assertSee('data-preference-root="movies"', false);

        $noFilm = $this->rowOf($response, 'Random.Upload.Without.A.Film-GRP');
        $this->assertStringContainsString('data-nofilm', $noFilm);
        $this->assertStringNotContainsString('tv-show-line', $noFilm);
        $this->assertStringNotContainsString('data-watch-picker', $noFilm);
        $this->assertStringContainsString('tv-action-slot', $noFilm);
        $this->assertStringNotContainsString('<img', $noFilm);

        $withFilm = $this->rowOf($response, 'Glass.Meridian.1994.1080p.BluRay.x264-GRP');
        $this->assertMatchesRegularExpression('/<img src="'.preg_quote(url('/covers/movies/0111161-cover.jpg'), '/').'" alt="" loading="lazy"\s+data-part="row poster"\s*>/', $withFilm);
        $this->assertSame(['download', 'copy', 'cart', 'watch'], $this->actions($withFilm));
        $this->assertStringContainsString('data-watch-picker="'.route('watchlist.picker', ['root' => 'movies', 'id' => '0111161']).'" data-watch-key="movies:0111161" data-watch-title="Glass Meridian" data-watched="0"', $withFilm);
        $this->assertStringContainsString('title="Follow this film" aria-label="Follow Glass Meridian"><i class="far fa-bookmark" aria-hidden="true"></i></button>', $withFilm);
        $this->assertStringContainsString('data-watch-off-title="Follow this film" data-watch-on-title="Following this film · click to unfollow" data-watch-off-aria="Follow Glass Meridian" data-watch-on-aria="Unfollow Glass Meridian"', $withFilm);
        $this->assertStringContainsString('href="'.route('details', md5('Glass.Meridian.1994.1080p.BluRay.x264-GRP')).'"', $withFilm);
        $this->assertStringContainsString('data-watch-key="movies:0222222"', $this->rowOf($response, 'Salt.Harbour.2024.720p.WEB.h264-GRP'));
        $this->assertNoWatchWording($html, 'The Movie releases list');
        // only the release name is bold: the film line and chips are not strong or bold markup
        $this->assertStringNotContainsString('<b>', $withFilm);
        $this->assertStringNotContainsString('<strong', $withFilm);
    }

    public function test_the_category_menu_lists_the_visible_sub_categories_with_releases_in_the_fixed_order(): void
    {
        foreach ([self::OTHER, self::WEBDL, self::FOREIGN, self::BLURAY, self::SD, self::UHD, self::HD, self::THREE_D] as $category) {
            $this->movie('In '.$category, ['categories_id' => $category]);
        }
        $user = $this->browserUser();
        DB::table('user_excluded_categories')->insert(['users_id' => $user->id, 'categories_id' => self::THREE_D]);
        Cache::flush();

        $response = $this->page('/movies', $user)->assertOk()->assertSee('In '.self::HD)->assertDontSee('In '.self::THREE_D)
            ->assertSee('Showing 1–7 of 7 releases');
        // DVD and X265 hold nothing; 3D is excluded; WEB-DL has releases but no fixed place (fog clarification 1)
        $this->assertSame([self::HD => 'HD', self::UHD => 'UHD', self::SD => 'SD', self::BLURAY => 'BluRay', self::FOREIGN => 'Foreign',
            self::OTHER => 'Other', self::WEBDL => 'WEB-DL'], $response->viewData('categoryMenu'));
        $response->assertDontSee('data-value="'.self::THREE_D.'"', false);
        $this->assertListed('/movies?category[]='.self::OTHER, ['In '.self::OTHER], $user);
        $this->page('/movies?category[]='.self::THREE_D, $user)->assertSee('Showing 1–7 of 7 releases');
    }

    public function test_the_password_setting_decides_whether_passworded_releases_are_listed(): void
    {
        $this->movie('Clean release', ['passwordstatus' => 0]);
        $this->movie('Unchecked release', ['passwordstatus' => -1]);
        $this->movie('Passworded release', ['passwordstatus' => 1]);
        $this->movie('Broken archive', ['passwordstatus' => 2]);

        Settings::query()->updateOrInsert(['name' => 'showpasswordedrelease'], ['value' => '0']);
        Cache::flush();
        $this->page('/movies')->assertSee('Clean release')->assertSee('Unchecked release')->assertDontSee('Passworded release')
            ->assertDontSee('Broken archive')->assertSee('Showing 1–2 of 2 releases');

        Settings::query()->updateOrInsert(['name' => 'showpasswordedrelease'], ['value' => '1']);
        Cache::flush();
        $this->page('/movies')->assertSee('Passworded release')->assertSee('chip-tone-password', false)->assertDontSee('Broken archive')
            ->assertSee('Showing 1–3 of 3 releases');
    }

    public function test_every_page_says_which_rows_it_shows_and_the_mirrored_half_keeps_the_order(): void
    {
        $expected = [];
        foreach (range(1, 275) as $index) {
            $postdate = Carbon::parse('2026-01-01 00:00:00')->addHours($index % 3 === 0 ? $index - 1 : $index)->toDateTimeString();
            $id = $this->movie('Paged '.$index, ['postdate' => $postdate]);
            $expected[] = [$postdate, $id];
        }
        usort($expected, static fn (array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
        $order = array_column($expected, 1);

        // page 2 is a deep page read from the front, page 4 lies past the middle and is read mirrored, page 6 is the last
        foreach ([1 => [1, 50], 2 => [51, 100], 4 => [151, 200], 6 => [251, 275]] as $page => [$from, $to]) {
            $response = $this->page('/movies'.($page > 1 ? '?page='.$page : ''))->assertOk()
                ->assertSee('Showing '.$from.'–'.$to.' of 275 releases')->assertSee('Page '.$page.' of 6');
            $this->assertSame(array_slice($order, $from - 1, $to - $from + 1), $this->listedIds($response), 'page '.$page);
        }
        $this->page('/movies?page=9')->assertRedirect(route('movies.releases', ['page' => 6]));
        $this->page('/movies?page=6')->assertDontSee('rel="next"', false)->assertSee('rel="prev"', false);
        $this->page('/movies')->assertSee('<span class="is-off" data-part="pager arrow">', false)->assertSee('aria-label="Page 6"', false)
            ->assertSee('Go to page');

        DB::table('releases')->where('name', 'Paged 5')->update(['resolution' => 1]);
        Cache::flush();
        $this->page('/movies?resolution[]=4k&page=3')->assertRedirect(route('movies.releases', ['resolution' => ['4k']]));
        $this->page('/movies?resolution[]=4k')->assertSee('Showing 1–1 of 1 release')->assertSee('Page 1 of 1')
            ->assertSee('Paged 5')->assertDontSee('rel="prev"', false)->assertDontSee('rel="next"', false)->assertDontSee('Go to page');
        $this->page('/movies?resolution[]=4k&source[]=dvd')->assertSee('Showing 0 releases')->assertSee('Page 1 of 1')
            ->assertSee('No releases match 4K · DVD.')->assertDontSee('<table', false);
    }

    public function test_release_filters_combine_or_within_a_menu_and_and_between_menus(): void
    {
        $this->movie('UHD web', ['resolution' => 1, 'source' => 1, 'categories_id' => self::UHD]);
        $this->movie('HD web', ['resolution' => 2, 'source' => 1]);
        $this->movie('HD remux', ['resolution' => 2, 'source' => 5]);
        $this->movie('HD bluray', ['resolution' => 2, 'source' => 2]);
        $this->movie('SD dvd', ['resolution' => 4, 'source' => 3, 'categories_id' => self::SD]);
        $this->movie('Unknown everything', ['resolution' => 0, 'source' => 0]);
        $this->release('A TV show in 4K', ['categories_id' => 5040, 'resolution' => 1, 'source' => 1]);

        $this->assertListed('/movies?resolution[]=4k&resolution[]=1080p', ['HD bluray', 'HD remux', 'HD web', 'UHD web']);
        $this->assertListed('/movies?resolution[]=1080p&source[]=bluray', ['HD bluray', 'HD remux']);
        $this->assertListed('/movies?source[]=unknown&resolution[]=unknown', ['Unknown everything']);
        $this->assertListed('/movies?category[]='.self::UHD.'&category[]='.self::SD, ['SD dvd', 'UHD web']);
        $this->assertListed('/movies?category[]='.self::SD.'&resolution[]=4k', []);
        $this->assertListed('/movies?resolution[]=8k&source[]=vhs&category[]=5040', ['HD bluray', 'HD remux', 'HD web', 'SD dvd', 'UHD web', 'Unknown everything']);
        $set = $this->page('/movies?resolution[]=4k&resolution[]=1080p')->assertSee('title="Resolution: 4K, 1080p"', false);
        $this->assertSame('Resolution: 2 chosen', $this->cellText($set, 'resolution'));
        $this->assertSame(['Source: any', 'Category: any'], [$this->cellText($set, 'source'), $this->cellText($set, 'category')]);
        $this->page('/movies')->assertSeeInOrder(['HD remux', '<td>Remux</td>'], false);
    }

    public function test_the_four_sorts_order_the_list_and_the_chosen_one_is_remembered(): void
    {
        $this->movie('Posted early added late', ['postdate' => '2026-09-01 00:00:00', 'adddate' => '2026-09-24 00:00:00']);
        $this->movie('Posted late added early', ['postdate' => '2026-09-10 00:00:00', 'adddate' => '2026-09-11 00:00:00']);
        $user = $this->browserUser();

        $this->page('/movies', $user)->assertSeeInOrder(['Posted late added early', 'Posted early added late'])
            ->assertSee('<th class="tv-num">Posted</th>', false)->assertSee('<option value="posted" selected', false)
            ->assertSeeInOrder(['Posted: newest first', 'Posted: oldest first', 'Added: newest first', 'Added: oldest first']);
        foreach (['posted_oldest' => ['Posted early added late', 'Posted late added early', 'Posted'], 'newest' => ['Posted early added late', 'Posted late added early', 'Added'],
            'oldest' => ['Posted late added early', 'Posted early added late', 'Added'], 'posted' => ['Posted late added early', 'Posted early added late', 'Posted']] as $sort => [$first, $second, $column]) {
            $this->postJson('/profile/update-view', ['root' => 'movies', 'sort' => $sort])->assertOk();
            $this->page('/movies', User::query()->findOrFail($user->id))->assertSeeInOrder([$first, $second])
                ->assertSee('<th class="tv-num">'.$column.'</th>', false)->assertSee('<option value="'.$sort.'" selected', false);
        }
        $this->assertSame('posted', User::query()->findOrFail($user->id)->releaseViewPreferences('movies')['sort']);
        $this->postJson('/profile/update-view', ['root' => 'movies', 'sort' => 'grabs'])->assertUnprocessable();
    }

    public function test_a_same_film_batch_longer_than_four_collapses_to_three_rows_and_an_expander(): void
    {
        foreach (range(1, 6) as $index) {
            $this->movie('Batch '.$index, ['movieinfo_id' => 21, 'postdate' => '2026-09-20 1'.$index.':00:00']);
        }
        foreach (range(1, 4) as $index) {
            $this->movie('Short run '.$index, ['movieinfo_id' => 22, 'postdate' => '2026-09-19 1'.$index.':00:00']);
        }
        $this->movie('Later day same film', ['movieinfo_id' => 22, 'postdate' => '2026-09-18 10:00:00']);
        foreach (range(1, 5) as $index) {
            $this->movie('No film '.$index, ['postdate' => '2026-09-17 1'.$index.':00:00']);
        }

        $response = $this->page('/movies')->assertOk();
        foreach (['Batch 6', 'Batch 5', 'Batch 4'] as $name) {
            $this->assertStringNotContainsString(' hidden', $this->openingTag($response, $name), $name);
        }
        foreach (['Batch 3', 'Batch 2', 'Batch 1'] as $name) {
            $this->assertStringContainsString(' hidden', $this->openingTag($response, $name), $name);
        }
        $response->assertSee('Show 3 more from Glass Meridian posted in the same batch')->assertSee('data-label-open="Show fewer from Glass Meridian"', false)
            ->assertDontSee('more from Salt Harbour');
        foreach (['Short run 1', 'Later day same film', 'No film 1', 'No film 5'] as $name) {
            $this->assertStringNotContainsString(' hidden', $this->openingTag($response, $name), $name);
        }
        $this->assertSame(16, substr_count((string) $response->getContent(), '<tr data-release-row'), 'the page still holds every release');
    }

    public function test_the_row_has_the_chips_the_group_and_poster_pair_and_no_files_or_grabs_column(): void
    {
        DB::table('usenet_groups')->insert([['id' => 1, 'name' => 'alt.binaries.movies'], ['id' => 2, 'name' => 'misc.test']]);
        $this->movie('Middling', ['completion' => 80, 'nfostatus' => 1, 'haspreview' => 1, 'jpgstatus' => 1, 'groups_id' => 1, 'fromname' => 'Uploader <up@example.invalid>', 'grabs' => 7]);
        $this->movie('Complete release', ['completion' => 100]);

        $response = $this->page('/movies')->assertOk()
            ->assertSee('<colgroup><col class="tv-col-select"><col class="tv-col-art"><col><col class="tv-col-resolution"><col class="tv-col-source"><col class="tv-col-size"><col class="tv-col-date"><col class="tv-col-actions"></colgroup>', false)
            ->assertDontSee('<th class="tv-num">Files</th>', false)->assertDontSee('<th class="tv-num">Grabs</th>', false)
            ->assertDontSee('filelist-badge', false)->assertDontSee('7 grabs', false);
        $this->assertSame(7, substr_count(strstr((string) $response->getContent(), '</thead>', true), '<th') - 1, 'seven headers over eight columns');
        $row = $this->rowOf($response, 'Middling');
        $this->assertSame(8, substr_count($row, '<td'));
        $this->assertStringContainsString('80% complete · still repairing', $row);
        $group = route('browse.all', ['group' => 'alt.binaries.movies']);
        $this->assertMatchesRegularExpression('/<div class="tv-chips">.*chip-tone-completion-mid.*nfo-badge.*preview-badge.*sample-badge.*<span class="tv-origin-pair">\s*'
            .'<a class="tv-origin-chip" href="'.preg_quote(e($group), '/').'" title="All releases in alt\.binaries\.movies"><i class="fas fa-users" aria-hidden="true"><\/i>a\.b\.movies<\/a>\s*'
            .'<a class="tv-origin-chip tv-origin-poster" href="[^"]+" title="All posts by Uploader &lt;up@example\.invalid&gt;"><i class="fas fa-user" aria-hidden="true"><\/i><span>Uploader &lt;up@example\.invalid&gt;<\/span><\/a>\s*'
            .'<\/span>\s*<\/div>/s', $row);
        $this->assertStringNotContainsString('target=', $row);
        $this->assertStringNotContainsString('tv-chips', $this->rowOf($response, 'Complete release'));
    }

    public function test_a_release_without_a_poster_gets_a_name_card_or_the_no_poster_tile(): void
    {
        $cards = [
            "The.Killer's.Game.(2024).VFF.2160p.WEB.H265-GRP" => ["The Killer's Game", '2024'],
            'Some_Film_1999_DVDRip_XviD' => ['Some Film', '1999'],
            '1917.2019.1080p.BluRay.x264-GRP' => ['1917', '2019'],
            'SITE.Tag.Film.2021.MULTi.1080p' => ['SITE Tag Film', '2021'],
        ];
        $tiles = ['Show.Name.S01E02.2020.1080p.WEB', 'Film.2020.1080p.part01.rar', '[GRP] Film 2020 1080p', 'Film.2020.Directors.Cut', 'no-year-at-all.1080p'];
        $date = 0;
        foreach ([...array_keys($cards), ...$tiles] as $name) {
            $this->movie($name, ['postdate' => Carbon::parse('2026-09-24 00:00:00')->subMinutes(++$date)->toDateTimeString()]);
        }
        $this->movie('Matched.Without.Art.1080p', ['movieinfo_id' => 22]);

        $response = $this->page('/movies')->assertOk();
        foreach ($cards as $name => [$title, $year]) {
            $this->assertMatchesRegularExpression('/<a class="tv-placeholder is-card" href="[^"]+" tabindex="-1" aria-hidden="true">\s*<span class="tv-placeholder-title">'
                .preg_quote(e($title), '/').'<\/span>\s*<span class="tv-placeholder-label">'.$year.'<\/span>/', $this->posterCell($this->rowOf($response, $name)), $name);
        }
        foreach ($tiles as $name) {
            $this->assertMatchesRegularExpression('/<a class="tv-placeholder" href="[^"]+" tabindex="-1" aria-hidden="true">\s*<i class="fas fa-film" aria-hidden="true"><\/i>\s*<span class="tv-placeholder-label">No poster<\/span>/',
                $this->posterCell($this->rowOf($response, $name)), $name);
        }
        $this->assertMatchesRegularExpression('/<span class="tv-placeholder-title">Salt Harbour<\/span>\s*<span class="tv-placeholder-label">2024<\/span>/',
            $this->posterCell($this->rowOf($response, 'Matched.Without.Art.1080p')));
    }

    public function test_the_buttons_are_two_by_two_and_the_pressed_states_come_from_the_cart_and_followed_films(): void
    {
        $first = $this->movie('Glass.Meridian.1994.2160p', ['movieinfo_id' => 21, 'postdate' => '2026-09-24 10:00:00']);
        $this->movie('Glass.Meridian.1994.720p', ['movieinfo_id' => 21, 'postdate' => '2026-09-24 09:00:00']);
        $this->movie('Salt.Harbour.2024.1080p', ['movieinfo_id' => 22, 'postdate' => '2026-09-23 10:00:00']);
        $this->movie('Unmatched.Film.1080p', ['postdate' => '2026-09-22 10:00:00']);
        $user = $this->browserUser();
        DB::table('users_releases')->insert(['users_id' => $user->id, 'releases_id' => $first]);
        DB::table('user_movies')->insert(['users_id' => $user->id, 'imdbid' => '0111161', 'categories' => '2040']);

        $response = $this->page('/movies', $user)->assertOk();
        foreach (['Glass.Meridian.1994.2160p', 'Glass.Meridian.1994.720p'] as $name) {
            $row = $this->rowOf($response, $name);
            $this->assertStringContainsString('<div class="tv-actions">', $row);
            $this->assertSame(['download', 'copy', 'cart', 'watch'], $this->actions($row));
            $this->assertStringContainsString('data-watched="1"', $row);
            $this->assertStringContainsString('title="Following this film · click to unfollow" aria-label="Unfollow Glass Meridian"><i class="fas fa-bookmark" aria-hidden="true"></i></button>', $row);
        }
        $this->assertMatchesRegularExpression('/data-cart="[0-9a-f]{32}" aria-pressed="true" title="In cart · click to remove" aria-label="Remove from cart"/', $this->rowOf($response, 'Glass.Meridian.1994.2160p'));
        $this->assertStringContainsString('aria-pressed="false" title="Add to cart"', $this->rowOf($response, 'Glass.Meridian.1994.720p'));
        $this->assertStringContainsString('data-watched="0"', $this->rowOf($response, 'Salt.Harbour.2024.1080p'));
        $unmatched = $this->rowOf($response, 'Unmatched.Film.1080p');
        $this->assertSame(['download', 'copy', 'cart'], $this->actions($unmatched));
        $this->assertMatchesRegularExpression('/<i class="fas fa-cart-shopping" aria-hidden="true"><\/i><\/button>\s*<span class="tv-action tv-action-slot" aria-hidden="true"><\/span>\s*<\/div>/', $unmatched);
        $this->assertStringNotContainsString('report', strtolower($unmatched));
    }

    public function test_the_header_menu_sends_movies_to_the_new_list_and_marks_browse_current(): void
    {
        $this->movie('A release');
        $response = $this->page('/movies')->assertOk()
            ->assertSee('href="'.route('movies.releases').'" data-browse-root', false)
            ->assertSee('href="'.route('movies.releases', ['category' => [self::HD]]).'"', false);
        $this->assertMatchesRegularExpression('/aria-label="Browse categories"\s+aria-current="true"/', (string) $response->getContent());
        $this->assertSame('/movies', route('movies.releases', [], false));
        $this->assertSame('/movies/search', route('movies.search', [], false));
    }

    public function test_the_list_fragment_is_the_list_alone_and_the_count_is_cached_under_the_browse_version(): void
    {
        $this->movie('First release');
        $this->page('/movies')->assertSee('Showing 1–1 of 1 release');
        $this->movie('Second release', ['postdate' => '2026-09-01 00:00:00']);
        $this->page('/movies')->assertSee('Showing 1–1 of 1 release');
        ReleaseBrowseService::bumpCacheVersion();
        $this->page('/movies')->assertSee('Showing 1–2 of 2 releases');

        $fragment = $this->page('/movies?_fragment=list&resolution[]=1080p')->assertOk()->assertSee('Showing 1–2 of 2 releases')->getContent();
        $this->assertStringNotContainsString('<html', $fragment);
        $this->assertStringNotContainsString('tv-filters', $fragment);
    }

    public function test_the_filter_bar_holds_the_release_and_film_cells_and_clear_all_sits_on_the_showing_line(): void
    {
        $this->movie('A release');
        $response = $this->page('/movies')->assertOk();
        $html = (string) $response->getContent();

        $title = (string) strstr((string) strstr($html, '<div class="tv-filters">'), '<div class="filter-row tv-bar-list">', true);
        $this->assertStringNotContainsString('checkbox-menu', $title);
        $this->assertStringContainsString('data-part="sort dropdown"', $title);
        $response->assertSeeInOrder(['<div class="filter-row tv-bar-list">', '<div class="filter-bar is-release" role="group" aria-label="The release">',
            'data-name="completion"', '<div class="filter-bar is-film" role="group" aria-label="The film">', 'data-name="language"', 'x-ref="list" class="tv-list-end"'], false);
        preg_match_all('/class="checkbox-menu is-cell[^"]*"[^>]*data-name="([a-z]+)"/', $html, $cells);
        $this->assertSame(['category', 'resolution', 'source', 'audio', 'completion', 'genre', 'year', 'score', 'rating', 'language'], $cells[1]);
        $this->assertSame(['Category: any', 'Resolution: any', 'Source: any', 'Audio: any', 'Completion: any', 'Genre: any', 'Year: any', 'Score: any',
            'MPAA Rating: any', 'Language: any'], array_map(fn (string $name): string => $this->cellText($response, $name), $cells[1]));
        $this->assertSame(10, substr_count($html, '<span class="checkbox-menu-value is-any" x-ref="value">any</span>'));
        foreach (['Any category', 'Any resolution', 'Any source', 'Any audio', 'Any completion', 'Any genre', 'Any year', 'Any score', 'Any MPAA rating', 'Any language'] as $any) {
            $this->assertMatchesRegularExpression('/data-any aria-checked="true"[^>]*>(<span class="checkbox-menu-(box|dot)">.*?<\/span>)'.$any.'<\/button>/', $html, $any);
        }

        $completion = (string) strstr((string) strstr($html, 'data-name="completion"'), 'data-name="genre"', true);
        $this->assertSame(['Any completion', '100% only', '95% or more'], array_map(static fn (string $item): string => trim(strip_tags(substr($item, (int) strpos($item, '>') + 1))),
            array_slice(explode('<button type="button" class="checkbox-menu-item" role="menuitemradio"', $completion), 1)));
        $this->assertStringContainsString('data-single="true"', $completion);

        $score = (string) strstr((string) strstr($html, 'data-name="score"'), 'data-name="rating"', true);
        preg_match_all('/data-text="([^"]+)"/', $score, $scores);
        $this->assertSame(['9+', '8–8.9', '7–7.9', '6–6.9', '5–5.9', 'Under 5', 'Too few votes'], $scores[1]);

        $line = (string) strstr((string) strstr($html, '<nav class="pager-line is-fixed" aria-label="Pages">'), '</nav>', true);
        $this->assertMatchesRegularExpression('/data-part="showing line">[^<]*<\/span>\s*<a href="'.preg_quote(route('movies.releases'), '/')
            .'" class="pager-line-clear is-hidden" data-clear-all aria-hidden="true" tabindex="-1">Clear all<\/a>\s*<span class="is-off" data-part="pager arrow">/', $line);
        $this->assertSame(1, substr_count($html, 'data-clear-all'));
    }

    public function test_setting_filters_marks_their_cells_and_shows_clear_all_while_nothing_moves(): void
    {
        $this->genre(self::DRAMA, 'Drama', [21]);
        DB::table('movieinfo')->where('id', 21)->update(['content_rating_us' => 'R']);
        $this->movie('Full', ['completion' => 100, 'movieinfo_id' => 21]);
        $this->movie('Nearly', ['completion' => 96]);
        $empty = $this->page('/movies');
        $set = $this->page('/movies?completion=95&resolution[]=1080p&resolution[]=4k&genre[]='.self::DRAMA.'&decade[]=1990&decade[]=2000&score[]=9&rating[]=R')->assertOk();

        $this->assertSame('Completion: 95%+', $this->cellText($set, 'completion'));
        $this->assertSame('Year: 2 chosen', $this->cellText($set, 'year'));
        $this->assertSame('Genre: Drama', $this->cellText($set, 'genre'));
        $set->assertSee('title="Completion: 95% or more"', false)->assertSee('title="Year: 2000s, 1990s"', false)
            ->assertSee('class="pager-line-clear" data-clear-all aria-hidden="false">Clear all</a>', false);
        $this->assertMatchesRegularExpression('/class="checkbox-menu is-cell year-menu is-set"/', (string) $set->getContent());

        $shape = static fn (TestResponse $response): array => [
            preg_replace('/ is-set| is-any| is-hidden|aria-checked="[a-z]+"|aria-hidden="[a-z]+"| tabindex="-1"| title="[^"]*"|>[^<]*<|data-part="filter menu button(, set)?"/', '', (string) strstr((string) strstr((string) $response->getContent(), '<div class="filter-row'), 'x-ref="list"', true)),
            preg_replace('/ is-hidden|aria-hidden="[a-z]+"| tabindex="-1"|>[^<]*<|data-part="filter menu button(, set)?"/', '', (string) strstr((string) strstr((string) $response->getContent(), '<nav class="pager-line'), '</nav>', true)),
        ];
        $this->assertSame($shape($empty), $shape($set));
    }

    public function test_the_year_menu_offers_decades_and_a_range_and_a_range_replaces_the_decades(): void
    {
        $this->movie('A release', ['movieinfo_id' => 21]);
        $html = (string) $this->page('/movies')->assertOk()->getContent();
        $year = (string) strstr((string) strstr($html, 'data-name="year"'), 'data-name="score"', true);
        preg_match_all('/data-value="(\d+)" data-text="([^"]+)"/', $year, $decades);
        $this->assertSame(['2020s', '2010s', '2000s', '1990s', '1980s', '1970s', '1960s', '1950s', '1940s', '1930s', '1920s', '1910s', '1900s'], $decades[2]);
        $this->assertStringContainsString('role="menuitemcheckbox" data-any aria-checked="true"', $year);
        $this->assertStringContainsString('data-first="1900" data-last="2026"', $html);
        $this->assertStringContainsString('<div class="checkbox-menu-heading" x-ref="rangeHeading" data-heading="Range">Range</div>', $year);
        $this->assertMatchesRegularExpression('/<input x-ref="from" inputmode="numeric" maxlength="4" placeholder="From" aria-label="From year" autocomplete="off" value="">\s*<span>to<\/span>\s*<input x-ref="to"[^>]*placeholder="To"[^>]*>\s*<button type="submit" class="tv-button" x-ref="apply" disabled>Apply<\/button>/', $year);
        $this->assertStringNotContainsString('2019s', $year);
        $this->assertStringNotContainsString('>2024<', $year, 'no list of single years');

        $this->assertSame('Year: 1990s', $this->cellText($this->page('/movies?decade[]=1990'), 'year'));
        $range = $this->page('/movies?decade[]=1990&year_from=1980&year_to=1989');
        $this->assertSame('Year: 1980–1989', $this->cellText($range, 'year'));
        $range->assertSee('title="Year: 1980–1989"', false)->assertSee('value="1980"', false)->assertSee('value="1989"', false);
        $this->assertSame([], $range->viewData('filters')->films->decades, 'a range replaces the ticked decades');
        $this->assertSame('Year: 2024', $this->cellText($this->page('/movies?year_from=2024'), 'year'));
        foreach (['year_from=1899', 'year_from=2027', 'year_from=1990&year_to=1980', 'year_from=90', 'year_from=1990&year_to=2027'] as $refused) {
            $this->assertSame('Year: any', $this->cellText($this->page('/movies?'.$refused), 'year'), $refused);
        }
        $this->assertSame([1980, 1989], MovieFilmFilters::range('1980', '1989'));
        $this->assertSame([2024, null], MovieFilmFilters::range('2024', ''));
        $this->assertSame([null, null], MovieFilmFilters::range('1990', '1980'));
    }

    public function test_long_menus_open_with_a_search_field_and_short_ones_do_not(): void
    {
        $names = ['Action', 'Adventure', 'Animation', 'Comedy', 'Crime', 'Documentary', 'Drama', 'Family', 'Fantasy', 'History', 'Horror'];
        foreach ($names as $index => $name) {
            $this->genre($index + 1, $name, [21]);
        }
        $this->genre(99, 'Western', []);
        $this->movie('A release', ['movieinfo_id' => 21]);
        $response = $this->page('/movies')->assertOk();
        $this->assertSame(array_combine(range(1, 11), $names), $response->viewData('filmOptions')['genre'], 'genres that have a film, A to Z');
        $html = (string) $response->getContent();
        $genre = (string) strstr((string) strstr($html, 'data-name="genre"'), 'data-name="year"', true);
        $this->assertStringContainsString('<div class="checkbox-menu-panel is-searchable" role="dialog" aria-label="Genre"', $genre);
        $this->assertStringContainsString('placeholder="Search genres" aria-label="Search genres"', $genre);
        $resolution = (string) strstr((string) strstr($html, 'data-name="resolution"'), 'data-name="source"', true);
        $this->assertStringNotContainsString('checkbox-menu-search', $resolution);
    }

    public function test_the_audio_menu_lists_english_first_then_a_to_z_and_the_rating_and_language_menus_most_releases_first_for_all_users(): void
    {
        DB::table('languages')->insert([['id' => self::ENGLISH, 'name' => 'English'], ['id' => self::HINDI, 'name' => 'Hindi'], ['id' => 3, 'name' => 'Arabic'], ['id' => 4, 'name' => 'Welsh']]);
        $this->film(23, '0333333', 'Third Film', '2001', ['original_language' => 'hi', 'content_rating_us' => 'NR']);
        $this->film(24, '0444444', 'Fourth Film', '2002', ['original_language' => 'no', 'content_rating_us' => 'PG-13']);
        DB::table('movieinfo')->where('id', 21)->update(['original_language' => 'en', 'content_rating_us' => 'R']);
        DB::table('movieinfo')->where('id', 22)->update(['original_language' => 'nb', 'content_rating_us' => 'G']);
        $audio = static fn (int $release, array $languages) => DB::table('release_audio_languages')->insert(array_map(static fn (int $language): array => ['releases_id' => $release, 'languages_id' => $language], $languages));
        // Hindi on three Movies releases (one hidden from this user, one passworded), English and Arabic on two, Welsh only on a TV release
        $audio($this->movie('H1', ['movieinfo_id' => 23]), [self::HINDI, self::ENGLISH]);
        $audio($this->movie('H2', ['movieinfo_id' => 23, 'categories_id' => self::THREE_D]), [self::HINDI]);
        $audio($this->movie('H3', ['movieinfo_id' => 23, 'passwordstatus' => 1]), [self::HINDI, 3]);
        $audio($this->movie('E1', ['movieinfo_id' => 21]), [self::ENGLISH, 3]);
        $this->movie('E2', ['movieinfo_id' => 21]);
        $this->movie('Z1', ['movieinfo_id' => 22]);
        $this->movie('C1', ['movieinfo_id' => 24]);
        $this->movie('C2', ['movieinfo_id' => 24]);
        $audio($this->release('A TV show', ['categories_id' => 5040]), [4, 3]);
        $user = $this->browserUser();
        DB::table('user_excluded_categories')->insert(['users_id' => $user->id, 'categories_id' => self::THREE_D]);

        $response = $this->page('/movies', $user)->assertOk();
        // the languages present for all users (Welsh is only on a TV release): English first, then A to Z, then Unknown
        $this->assertSame([self::ENGLISH => 'English', 3 => 'Arabic', self::HINDI => 'Hindi', 'unknown' => 'Unknown'], $response->viewData('audioMenu'));
        // Hindi 3 releases, Norwegian 3 (no and nb share the name, no has more), ties by name, English 2
        $options = $response->viewData('filmOptions');
        $this->assertSame(['hi' => 'Hindi', 'no' => 'Norwegian', 'en' => 'English'], $options['language']);
        $this->assertSame(['G' => 'G', 'PG-13' => 'PG-13', 'R' => 'R', 'NR' => 'NR'], $options['rating']);
        $response->assertSeeInOrder(['data-name="audio"', '>English<', '>Arabic<', '>Hindi<', '>Unknown<', 'data-name="completion"'], false);
        $this->assertListed('/movies?language[]=no', ['C1', 'C2', 'Z1'], $user);
        $this->assertListed('/movies?audio[]='.self::HINDI, ['H1'], $user);
        $this->assertListed('/movies?audio[]=unknown', ['C1', 'C2', 'E2', 'Z1'], $user);
    }

    public function test_a_section_with_no_english_audio_lists_its_languages_a_to_z_then_unknown(): void
    {
        DB::table('languages')->insert([['id' => self::ENGLISH, 'name' => 'English'], ['id' => self::HINDI, 'name' => 'Hindi'], ['id' => 3, 'name' => 'Arabic'], ['id' => 4, 'name' => 'Welsh']]);
        $audio = static fn (int $release, array $languages) => DB::table('release_audio_languages')->insert(array_map(static fn (int $language): array => ['releases_id' => $release, 'languages_id' => $language], $languages));
        // Welsh on the most Movies releases, then Hindi, then Arabic; English only on a TV release
        $audio($this->movie('W1'), [4, self::HINDI]);
        $audio($this->movie('W2'), [4, self::HINDI]);
        $audio($this->movie('W3'), [4, 3]);
        $audio($this->release('A TV show', ['categories_id' => 5040]), [self::ENGLISH]);

        $response = $this->page('/movies')->assertOk();
        $this->assertSame([3 => 'Arabic', self::HINDI => 'Hindi', 4 => 'Welsh', 'unknown' => 'Unknown'], $response->viewData('audioMenu'));
        $response->assertSeeInOrder(['data-name="audio"', '>Arabic<', '>Hindi<', '>Welsh<', '>Unknown<', 'data-name="completion"'], false);
        // a menu cached by the old code, most releases first, is served in the new order
        Cache::put('movie_releases_audio_menu', [4 => 'Welsh', self::HINDI => 'Hindi', 3 => 'Arabic'], 3600);
        $this->assertSame([3 => 'Arabic', self::HINDI => 'Hindi', 4 => 'Welsh', 'unknown' => 'Unknown'], $this->page('/movies')->viewData('audioMenu'));
    }

    public function test_the_empty_line_names_the_chosen_audio_languages_english_first_then_a_to_z(): void
    {
        DB::table('languages')->insert([['id' => self::ENGLISH, 'name' => 'English'], ['id' => self::HINDI, 'name' => 'Hindi'], ['id' => 3, 'name' => 'Arabic']]);
        $audio = static fn (int $release, array $languages) => DB::table('release_audio_languages')->insert(array_map(static fn (int $language): array => ['releases_id' => $release, 'languages_id' => $language], $languages));
        $audio($this->movie('H1'), [self::HINDI, 3]);
        $audio($this->movie('H2'), [self::HINDI]);
        $audio($this->movie('E1'), [self::ENGLISH]);

        $query = 'resolution[]=4k&audio[]=unknown&audio[]='.self::HINDI.'&audio[]=3&audio[]='.self::ENGLISH;
        $response = $this->page('/movies?'.$query)->assertOk()->assertSee('Showing 0 releases')
            ->assertSee('No releases match 4K · English or Arabic or Hindi or Unknown audio.');
        $this->assertSame([(string) self::ENGLISH, '3', (string) self::HINDI, 'unknown'], $response->viewData('filters')->query()['audio']);
    }

    public function test_each_filter_returns_exactly_what_a_direct_predicate_returns_in_every_sort_and_page(): void
    {
        $facts = $this->filterFixture();
        $films = $this->filmFacts();
        $film = static fn (callable $test): callable => static fn (array $fact): bool => isset($films[$fact['film']]) && $test($films[$fact['film']]);
        $cases = [
            'no filter' => [new MovieReleaseFilters, static fn (array $fact): bool => true],
            'Other' => [new MovieReleaseFilters(categories: [self::OTHER]), static fn (array $fact): bool => $fact['category'] === self::OTHER],
            'everything but Other' => [new MovieReleaseFilters(categories: [self::HD, self::SD]), static fn (array $fact): bool => $fact['category'] !== self::OTHER],
            '1080p' => [new MovieReleaseFilters(resolutions: ['1080p']), static fn (array $fact): bool => $fact['resolution'] === 2],
            'Blu-ray' => [new MovieReleaseFilters(sources: ['bluray']), static fn (array $fact): bool => in_array($fact['source'], [2, 5], true)],
            'English audio' => [new MovieReleaseFilters(audio: [(string) self::ENGLISH]), static fn (array $fact): bool => in_array(self::ENGLISH, $fact['audio'], true)],
            'Unknown audio' => [new MovieReleaseFilters(audio: ['unknown']), static fn (array $fact): bool => $fact['audio'] === []],
            'Hindi or Unknown audio' => [new MovieReleaseFilters(audio: [(string) self::HINDI, 'unknown']), static fn (array $fact): bool => $fact['audio'] === [] || in_array(self::HINDI, $fact['audio'], true)],
            '100%' => [new MovieReleaseFilters(completion: 100), static fn (array $fact): bool => $fact['completion'] >= 100],
            '95%+' => [new MovieReleaseFilters(completion: 95), static fn (array $fact): bool => $fact['completion'] >= 95],
            'Genre' => [$this->films(genres: [self::DRAMA]), $film(static fn (array $f): bool => in_array(self::DRAMA, $f['genres'], true))],
            'Genre Comedy or Horror' => [$this->films(genres: [self::COMEDY, self::HORROR]), $film(static fn (array $f): bool => array_intersect([self::COMEDY, self::HORROR], $f['genres']) !== [])],
            'Decades' => [$this->films(decades: [1990, 2000]), $film(static fn (array $f): bool => $f['year'] >= 1990 && $f['year'] <= 2009)],
            'Range' => [$this->films(yearFrom: 1994, yearTo: 2013), $film(static fn (array $f): bool => $f['year'] >= 1994 && $f['year'] <= 2013)],
            'One year' => [$this->films(yearFrom: 2024), $film(static fn (array $f): bool => $f['year'] === 2024)],
            'Score 9+' => [$this->films(scores: ['9']), $film(static fn (array $f): bool => $f['band'] === '9')],
            'Score 7-8.9' => [$this->films(scores: ['7', '8']), $film(static fn (array $f): bool => in_array($f['band'], ['7', '8'], true))],
            'Under 5' => [$this->films(scores: ['low']), $film(static fn (array $f): bool => $f['band'] === 'low')],
            'Too few votes' => [$this->films(scores: ['few']), $film(static fn (array $f): bool => $f['band'] === 'few')],
            'MPAA' => [$this->films(ratings: ['R', 'PG-13']), $film(static fn (array $f): bool => in_array($f['rating'], ['R', 'PG-13'], true))],
            'Language' => [$this->films(languages: ['en']), $film(static fn (array $f): bool => $f['language'] === 'en')],
            'films and releases' => [new MovieReleaseFilters(categories: [self::HD], completion: 95, audio: [(string) self::ENGLISH], films: new MovieFilmFilters(genres: [self::DRAMA], ratings: ['R'])),
                static fn (array $fact): bool => $fact['category'] === self::HD && $fact['completion'] >= 95 && in_array(self::ENGLISH, $fact['audio'], true)
                    && isset($films[$fact['film']]) && in_array(self::DRAMA, $films[$fact['film']]['genres'], true) && $films[$fact['film']]['rating'] === 'R'],
            'films and Unknown audio' => [new MovieReleaseFilters(audio: ['unknown'], films: new MovieFilmFilters(decades: [2020])),
                static fn (array $fact): bool => $fact['audio'] === [] && isset($films[$fact['film']]) && $films[$fact['film']]['year'] >= 2020],
        ];
        $list = app(MovieReleaseList::class);
        foreach ($cases as $case => [$filters, $keep]) {
            foreach (ReleaseSort::cases() as $sort) {
                if (! array_key_exists($sort->value, MovieReleaseFilters::SORTS)) {
                    continue;
                }
                $sorted = new MovieReleaseFilters($filters->categories, $filters->resolutions, $filters->sources, $sort, 1, $filters->audio, $filters->completion, $filters->films);
                $expected = $this->expectedOrder($facts, $keep, $sorted);
                $total = $list->count($sorted, [self::THREE_D]);
                $this->assertSame(count($expected), $total, $case);
                $read = [];
                foreach (range(1, max(1, (int) ceil($total / MovieReleaseFilters::PER_PAGE))) as $page) {
                    $read = [...$read, ...$list->pageIds($sorted->withPage($page), [self::THREE_D], $total)];
                }
                $this->assertSame($expected, $read, $case.', '.$sort->value);
            }
            // a film-led read sorts the film's releases, the same cost on every page; the others need a page past the middle
            if (! str_starts_with($case, 'films and') && ! in_array($case, ['Score 9+', 'Under 5', 'One year'], true)) {
                $this->assertGreaterThan(MovieReleaseFilters::PER_PAGE, count($this->expectedOrder($facts, $keep, $filters)), $case.' reaches a page past the middle');
            }
        }
    }

    public function test_the_list_names_the_index_the_rule_chooses_for_each_filter_combination(): void
    {
        // 100 Movies releases: HD 60, SD 30, UHD 10; 1080p 70, 4K 10, 720p 20; WEB 80, Blu-ray 15, Remux 5; a TV release counts for nothing
        foreach (range(0, 99) as $index) {
            $this->movie('Counted '.$index, ['categories_id' => $index < 60 ? self::HD : ($index < 90 ? self::SD : self::UHD),
                'resolution' => $index % 10 < 7 ? 2 : ($index % 10 === 7 ? 1 : 3), 'source' => $index % 20 < 16 ? 1 : ($index % 20 < 19 ? 2 : 5)]);
        }
        foreach (range(1, 200) as $index) {
            $this->release('TV '.$index, ['categories_id' => 5040, 'resolution' => 1, 'source' => 2]);
        }
        $list = app(MovieReleaseList::class);
        $index = static fn (array $filters): string => $list->readIndex(new MovieReleaseFilters(...$filters), []);
        $added = ['sort' => ReleaseSort::AddedNewest];

        $this->assertSame('ix_releases_band_posted', $index([]));
        $this->assertSame('ix_releases_band_added', $index($added));
        $this->assertSame('ix_releases_band_posted', $index(['completion' => 95, 'audio' => ['unknown']]));
        $this->assertSame('ix_releases_band_cat_posted', $index(['categories' => [self::UHD]]));
        $this->assertSame('ix_releases_band_cat_added', $index(['categories' => [self::UHD], ...$added]));
        $this->assertSame('ix_releases_band_posted', $index(['categories' => [self::HD]]), 'HD holds more than half of the band');
        $this->assertSame('ix_releases_band_res_posted', $index(['categories' => [self::HD], 'resolutions' => ['4k']]));
        $this->assertSame('ix_releases_band_res_added', $index(['resolutions' => ['720p'], ...$added]));
        $this->assertSame('ix_releases_band_src_posted', $index(['categories' => [self::SD], 'sources' => ['bluray']]), 'Blu-ray counts its remuxes: 20 < 30');
        // any film filter drives the read from the films, whatever else is set (fog clarification 1)
        foreach ([['genres' => [self::DRAMA]], ['decades' => [1990]], ['yearFrom' => 2024], ['scores' => ['few']], ['ratings' => ['R']], ['languages' => ['en']]] as $film) {
            $this->assertSame('ix_releases_movieinfo_cat', $index(['films' => new MovieFilmFilters(...$film)]), json_encode($film, JSON_THROW_ON_ERROR));
            $this->assertSame('ix_releases_movieinfo_cat', $index(['categories' => [self::UHD], 'audio' => ['unknown'], 'films' => new MovieFilmFilters(...$film), ...$added]));
        }
    }

    public function test_the_url_carries_the_filters_and_the_empty_line_names_them(): void
    {
        $this->genre(self::HORROR, 'Horror', [21]);
        DB::table('languages')->insert([['id' => self::ENGLISH, 'name' => 'English']]);
        DB::table('movieinfo')->where('id', 21)->update(['original_language' => 'fr', 'content_rating_us' => 'R', 'rating' => '9.1', 'vote_count' => 50]);
        DB::table('release_audio_languages')->insert(['releases_id' => $this->movie('Shown', ['movieinfo_id' => 21, 'resolution' => 3]), 'languages_id' => self::ENGLISH]);
        $this->movie('No film', ['resolution' => 3]);

        $query = 'category[]='.self::HD.'&resolution[]=1080p&source[]=bluray&completion=95&audio[]='.self::ENGLISH.'&audio[]=unknown&audio[]=99'
            .'&genre[]='.self::HORROR.'&language[]=fr&decade[]=1990&score[]=9&score[]=few&rating[]=R';
        $response = $this->page('/movies?'.$query)->assertOk()->assertSee('Showing 0 releases')
            ->assertSee('No releases match HD · 1080p · Blu-ray · 95%+ complete · English or Unknown audio · Horror · in French · 1990s · score 9+ or too few votes · rated R.');
        $filters = $response->viewData('filters');
        $this->assertSame(['category' => [self::HD], 'resolution' => ['1080p'], 'source' => ['bluray'], 'audio' => [(string) self::ENGLISH, 'unknown'], 'completion' => 95,
            'genre' => [self::HORROR], 'decade' => [1990], 'score' => ['9', 'few'], 'rating' => ['R'], 'language' => ['fr'], 'page' => 2], $filters->query(2));
        $this->assertSame(['year_from' => 1980, 'year_to' => 1989], array_intersect_key($this->page('/movies?year_from=1980&year_to=1989')->viewData('filters')->query(), ['year_from' => 1, 'year_to' => 1]));
        $this->assertSame('Language: French', $this->cellText($response, 'language'));
        $this->assertListed('/movies?language[]=fr', ['Shown']);
        $this->assertListed('/movies?score[]=9', ['Shown']);
        $this->assertListed('/movies?audio[]=unknown', ['No film']);
        $this->assertListed('/movies?completion=95&resolution[]=720p', ['No film', 'Shown']);
        $this->page('/movies?year_from=2000&year_to=2010')->assertSee('No releases match 2000–2010.');
    }

    public function test_the_search_finds_films_then_people_the_user_may_see(): void
    {
        $this->film(23, '0333333', 'The Glass House', '2001');
        $this->film(24, '0444444', 'Hidden Glass', '2002');
        $this->film(25, '0555555', 'Glassworks', '2010');
        foreach ([21, 22, 23, 25] as $film) {
            $this->movie('Film '.$film, ['movieinfo_id' => $film]);
        }
        $this->movie('Only in 3D', ['movieinfo_id' => 24, 'categories_id' => self::THREE_D]);
        $this->genre(self::DRAMA, 'Drama', [21]);
        $this->genre(self::COMEDY, 'Comedy', [21]);
        $this->genre(self::HORROR, 'Horror', [21]);
        DB::table('movie_genres')->where('movieinfo_id', 21)->where('genres_id', self::DRAMA)->update(['position' => 2]);
        DB::table('movie_genres')->where('movieinfo_id', 21)->where('genres_id', self::HORROR)->update(['position' => 0]);
        DB::table('people')->insert([['id' => 1, 'name' => 'Ada Glass'], ['id' => 2, 'name' => 'Glass Onion'], ['id' => 3, 'name' => 'Hidden Glassman']]);
        $credit = static fn (int $person, int $film, int $role = 1) => DB::table('movie_people')->insert(['people_id' => $person, 'movieinfo_id' => $film, 'role' => $role, 'position' => 0]);
        foreach ([21, 22, 23, 25] as $film) {
            $credit(1, $film);
        }
        $credit(1, 21, 0);
        $credit(1, 24);
        $credit(2, 22);
        $credit(3, 24);
        $user = $this->browserUser();
        DB::table('user_excluded_categories')->insert(['users_id' => $user->id, 'categories_id' => self::THREE_D]);

        $data = $this->actingAs($user)->getJson('/movies/search?q=glass')->assertOk()->json();
        $this->assertSame(['Glass Meridian', 'Glassworks', 'The Glass House'], array_column($data['films'], 'title'));
        $this->assertSame(['id' => 21, 'title' => 'Glass Meridian', 'year' => 1994, 'genres' => ['Horror', 'Comedy'], 'poster' => null], $data['films'][0]);
        // Ada is credited twice on Glass Meridian and on a film only in the hidden 3D category: four films she may see, the first three A to Z
        $this->assertSame([['id' => 1, 'name' => 'Ada Glass', 'count' => 4, 'films' => ['Glass Meridian', 'Glassworks', 'Salt Harbour']],
            ['id' => 2, 'name' => 'Glass Onion', 'count' => 1, 'films' => ['Salt Harbour']]], $data['people']);
        $this->assertSame([], $this->actingAs($user)->getJson('/movies/search?q=g')->json('people'));
        $this->assertSame(['Salt Harbour', 'Glass Meridian', 'Glassworks', 'The Glass House'],
            array_column($this->actingAs($user)->getJson('/movies/search?q=a')->json('films'), 'title'), 'by where the text matches, then title');
        $this->assertSame(['films' => [], 'people' => []], $this->actingAs($user)->getJson('/movies/search?q=%20')->json());
    }

    public function test_the_page_and_search_need_the_movies_permission(): void
    {
        $user = $this->browserUser();
        $user->revokePermissionTo('view movies');
        $this->page('/movies', $user)->assertForbidden();
        $this->actingAs($user)->get('/movies/search?q=glass')->assertForbidden();
    }

    /** @param array<string, mixed> $attributes */
    private function movie(string $name, array $attributes = []): int
    {
        return $this->release($name, ['categories_id' => self::HD, 'passwordstatus' => 0, 'resolution' => 2, 'source' => 1, 'imdbid' => null,
            'movieinfo_id' => null, 'videos_id' => 0, 'tv_episodes_id' => 0, 'completion' => 100, 'nfostatus' => 0, 'haspreview' => 0, 'jpgstatus' => 0, ...$attributes]);
    }

    /** @param array<string, mixed> $attributes */
    private function film(int $id, string $imdbId, string $title, string $year, array $attributes = []): void
    {
        DB::table('movieinfo')->insert(['id' => $id, 'imdbid' => $imdbId, 'title' => $title, 'year' => $year, ...$attributes]);
    }

    /** @param list<int> $films */
    private function genre(int $id, string $title, array $films): void
    {
        DB::table('genres')->insert(['id' => $id, 'title' => $title, 'type' => 2000, 'disabled' => 0]);
        foreach ($films as $film) {
            DB::table('movie_genres')->insert(['movieinfo_id' => $film, 'genres_id' => $id, 'position' => 1]);
        }
    }

    private function poster(string $imdbId): void
    {
        File::ensureDirectoryExists($this->covers.'/movies');
        File::put($this->covers.'/movies/'.$imdbId.'-cover.jpg', 'jpg');
    }

    /**
     * @param  list<int>  $genres
     * @param  list<int>  $decades
     * @param  list<string>  $scores
     * @param  list<string>  $ratings
     * @param  list<string>  $languages
     */
    private function films(array $genres = [], array $decades = [], ?int $yearFrom = null, ?int $yearTo = null, array $scores = [], array $ratings = [], array $languages = []): MovieReleaseFilters
    {
        return new MovieReleaseFilters(films: new MovieFilmFilters($genres, $decades, $yearFrom, $yearTo, $scores, $ratings, $languages));
    }

    private function page(string $uri, ?User $user = null): TestResponse
    {
        $this->resetGlobalComposerState();

        return $this->actingAs($user ?? $this->user ??= $this->browserUser())->get($uri);
    }

    /** @param list<string> $names */
    private function assertListed(string $uri, array $names, ?User $user = null): void
    {
        $response = $this->page($uri, $user)->assertOk();
        $listed = DB::table('releases')->whereIn('id', $this->listedIds($response))->orderBy('name')->pluck('name')->all();
        $this->assertSame($names, $listed, $uri);
    }

    /** @return list<int> */
    private function listedIds(TestResponse $response): array
    {
        preg_match_all('/data-select value="([0-9a-f]{32})"/', (string) $response->getContent(), $matches);
        $ids = DB::table('releases')->whereIn('guid', $matches[1])->pluck('id', 'guid');

        return array_map(static fn (string $guid): int => (int) $ids[$guid], $matches[1]);
    }

    private function rowOf(TestResponse $response, string $name): string
    {
        foreach (explode('<tr data-release-row', (string) $response->getContent()) as $row) {
            if (str_contains($row, 'title="'.e($name).'"')) {
                return strstr($row, '</tr>', true) ?: $row;
            }
        }
        $this->fail('No row for '.$name);
    }

    private function posterCell(string $row): string
    {
        $cell = strstr((string) strstr($row, '<td class="tv-art">'), '<td class="tv-what">', true);
        $this->assertIsString($cell);

        return $cell;
    }

    private function openingTag(TestResponse $response, string $name): string
    {
        return strstr($this->rowOf($response, $name), '>', true);
    }

    /** @return list<string> */
    private function actions(string $row): array
    {
        preg_match_all('/class="tv-action(?: tv-action-download download-nzb)?" (?:data-copy-nzb|data-cart|data-watch-picker|title="Download)/', $row, $matches);

        return array_map(static fn (string $match): string => match (true) {
            str_contains($match, 'Download') => 'download', str_contains($match, 'copy') => 'copy',
            str_contains($match, 'cart') => 'cart', default => 'watch',
        }, $matches[0]);
    }

    /**
     * Six films over genres, years, score bands, ratings and languages: Glass Meridian (21) and
     * Salt Harbour (22) from setUp, and 23-26.
     *
     * @return array<int, array{genres: list<int>, year: int, band: string, rating: string, language: string}>
     */
    private function filmFacts(): array
    {
        return [
            21 => ['genres' => [self::DRAMA], 'year' => 1994, 'band' => '8', 'rating' => 'R', 'language' => 'en'],
            22 => ['genres' => [self::COMEDY], 'year' => 2024, 'band' => 'few', 'rating' => '', 'language' => 'fr'],
            23 => ['genres' => [self::DRAMA, self::HORROR], 'year' => 2005, 'band' => '9', 'rating' => 'PG-13', 'language' => 'en'],
            24 => ['genres' => [], 'year' => 1999, 'band' => 'low', 'rating' => 'R', 'language' => 'hi'],
            25 => ['genres' => [self::HORROR], 'year' => 2013, 'band' => 'few', 'rating' => 'NR', 'language' => 'en'],
            26 => ['genres' => [self::COMEDY], 'year' => 1987, 'band' => '7', 'rating' => 'PG-13', 'language' => 'fr'],
        ];
    }

    /**
     * 320 Movies releases over the six films and none, with an excluded category (3D), Movies > Other,
     * passworded releases, three completions, four resolutions and sources and five audio mixes; posted
     * dates with ties, added dates in another order.
     *
     * @return array<int, array{visible: bool, category: int, film: int, resolution: int, source: int, completion: int, audio: list<int>, posted: string, added: string}>
     */
    private function filterFixture(): array
    {
        $this->genre(self::DRAMA, 'Drama', [21, 23]);
        $this->genre(self::COMEDY, 'Comedy', [22, 26]);
        $this->genre(self::HORROR, 'Horror', [23, 25]);
        // 21 scores 8.2 with 500 votes; 22 scores 0 with 50 votes (too few: DATA-CONTRACT 2.2); 23 scores 9.3 with no stored count (banded by its score);
        // 24 scores 4.1 with 12 votes; 25 scores 9.8 with 3 votes (too few); 26 scores 7.0 with 10 votes
        DB::table('movieinfo')->where('id', 21)->update(['rating' => '8.2', 'vote_count' => 500, 'content_rating_us' => 'R', 'original_language' => 'en']);
        DB::table('movieinfo')->where('id', 22)->update(['rating' => '0.0', 'vote_count' => 50, 'original_language' => 'fr']);
        $this->film(23, '0333333', 'Third', '2005', ['rating' => '9.3', 'vote_count' => null, 'content_rating_us' => 'PG-13', 'original_language' => 'en']);
        $this->film(24, '0444444', 'Fourth', '1999', ['rating' => '4.1', 'vote_count' => 12, 'content_rating_us' => 'R', 'original_language' => 'hi']);
        $this->film(25, '0555555', 'Fifth', '2013', ['rating' => '9.8', 'vote_count' => 3, 'content_rating_us' => 'NR', 'original_language' => 'en']);
        $this->film(26, '0666666', 'Sixth', '1987', ['rating' => '7.0', 'vote_count' => 10, 'content_rating_us' => 'PG-13', 'original_language' => 'fr']);
        DB::table('languages')->insert([['id' => self::ENGLISH, 'name' => 'English'], ['id' => self::HINDI, 'name' => 'Hindi'], ['id' => 3, 'name' => 'Japanese']]);
        Settings::query()->updateOrInsert(['name' => 'showpasswordedrelease'], ['value' => '0']);
        $mixes = [[], [self::ENGLISH], [self::HINDI], [self::ENGLISH, self::HINDI], [], [3]];
        $facts = [];
        foreach (range(1, 320) as $index) {
            $fact = ['category' => $index % 9 === 0 ? self::THREE_D : ($index % 5 === 0 ? self::OTHER : ($index % 2 === 0 ? self::HD : self::SD)),
                'film' => [21, 22, 23, 24, 25, 26, 0][$index % 7], 'resolution' => [2, 1, 3, 4][$index % 4], 'source' => [1, 2, 5, 3][$index % 4 === 1 ? 2 : $index % 3],
                'completion' => [100, 97, 90][$index % 3], 'audio' => $mixes[$index % 6],
                'posted' => Carbon::parse('2026-01-01 00:00:00')->addHours($index % 7 === 0 ? $index - 1 : $index)->toDateTimeString(),
                'added' => Carbon::parse('2026-03-01 00:00:00')->addHours(($index * 37) % 320)->toDateTimeString()];
            $password = $index % 11 === 0 ? 1 : 0;
            $id = $this->movie('Filtered '.$index, ['categories_id' => $fact['category'], 'movieinfo_id' => $fact['film'] === 0 ? null : $fact['film'],
                'resolution' => $fact['resolution'], 'source' => $fact['source'], 'completion' => $fact['completion'],
                'passwordstatus' => $password, 'postdate' => $fact['posted'], 'adddate' => $fact['added']]);
            foreach ($fact['audio'] as $language) {
                DB::table('release_audio_languages')->insert(['releases_id' => $id, 'languages_id' => $language]);
            }
            $facts[$id] = ['visible' => $password === 0 && $fact['category'] !== self::THREE_D, ...$fact];
        }
        Cache::flush();

        return $facts;
    }

    /**
     * The visible releases the predicate keeps, in the filters' sort.
     *
     * @param  array<int, array{visible: bool, category: int, film: int, resolution: int, source: int, completion: int, audio: list<int>, posted: string, added: string}>  $facts
     * @return list<int>
     */
    private function expectedOrder(array $facts, callable $keep, MovieReleaseFilters $filters): array
    {
        $kept = array_filter($facts, static fn (array $fact): bool => $fact['visible'] && $keep($fact));
        $date = $filters->sortsByAdded() ? 'added' : 'posted';
        uksort($kept, static fn (int $a, int $b): int => [$kept[$a][$date], $a] <=> [$kept[$b][$date], $b]);
        $ids = array_keys($kept);

        return $filters->ascending() ? $ids : array_reverse($ids);
    }

    /** A filter cell's text as check.mjs reads it (textContent): "Genre: 2 chosen". */
    private function cellText(TestResponse $response, string $name): string
    {
        $this->assertMatchesRegularExpression('/data-name="'.$name.'".*?<span class="checkbox-menu-label">(.*?)<\/span><i /s', (string) $response->getContent());
        preg_match('/data-name="'.$name.'".*?<span class="checkbox-menu-label">(.*?)<\/span><i /s', (string) $response->getContent(), $match);

        return html_entity_decode(strip_tags($match[1]), ENT_QUOTES);
    }
}
