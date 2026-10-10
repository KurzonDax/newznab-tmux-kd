<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\GenericListContext;
use App\Data\GenericReleaseFilters;
use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\Settings;
use App\Models\User;
use App\Services\Releases\GenericReleaseList;
use App\Services\Releases\GenericReleaseRows;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\AssertsFollowWording;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * The generic release lists (issue #1032; docs/proposals/generic-release-lists/SPEC.md 1, 4, 5 and 6,
 * DATA-NOTES.md 3 and the checks of prototype/check.mjs on releases.html): All releases, a group's
 * releases, a poster's posts and Other, in the redesigned list form at their existing addresses.
 */
final class GenericReleasesPageTest extends TestCase
{
    use AssertsFollowWording;
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    private const MISC = 10;

    private const HASHED = 20;

    private const MOVIE_SD = 2030;

    private const MOVIE_HD = 2040;

    private const TV_HD = 5040;

    private const AUDIO_MP3 = 3010;

    private const BOOKS_EBOOK = 7020;

    private const CONSOLE_XBOX360DLC = 1070;

    private const PC_0DAY = 4010;

    private const XXX_X264 = 6040;

    private ?User $user = null;

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
            'haspreview', 'jpgstatus', 'videostatus', 'groups_id', 'fromname', 'isrenamed', 'additional_pp_claim_token', 'imdbid', 'movieinfo_id', 'videos_id',
            'tv_episodes_id', 'musicinfo_id', 'consoleinfo_id', 'gamesinfo_id', 'bookinfo_id', 'anidbid', 'resolution', 'source', 'predb_id']);
        foreach (['usenet_groups', 'users_releases', 'user_series', 'user_movies', 'videos', 'tv_episodes', 'movieinfo', 'consoleinfo', 'release_audio_tags', 'release_video_clips',
            'languages', 'release_audio_languages'] as $table) {
            $tables->create($table);
        }
        foreach (['2026_02_01_000000_create_release_reports_table', '2026_06_08_000000_add_response_fields_to_release_reports_table'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
        DB::table('root_categories')->where('id', 1)->update(['title' => 'Other']);
        DB::table('root_categories')->insert([
            ['id' => 1000, 'title' => 'Console', 'status' => 1], ['id' => 2000, 'title' => 'Movies', 'status' => 1], ['id' => 3000, 'title' => 'Audio', 'status' => 1],
            ['id' => 4000, 'title' => 'Games', 'status' => 1], ['id' => 5000, 'title' => 'TV', 'status' => 1], ['id' => 6000, 'title' => 'XXX', 'status' => 1],
            ['id' => 7000, 'title' => 'Books', 'status' => 1],
        ]);
        foreach ([self::MISC => [1, 'Misc'], self::HASHED => [1, 'Hashed'], self::MOVIE_SD => [2000, 'SD'], self::MOVIE_HD => [2000, 'HD'], self::TV_HD => [5000, 'HD'],
            self::AUDIO_MP3 => [3000, 'MP3'], self::BOOKS_EBOOK => [7000, 'Ebook'], self::CONSOLE_XBOX360DLC => [1000, 'Xbox 360 DLC'], self::PC_0DAY => [4000, '0day'],
            self::XXX_X264 => [6000, 'x264']] as $id => [$root, $title]) {
            DB::table('categories')->insert(['id' => $id, 'title' => $title, 'root_categories_id' => $root, 'status' => 1]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_all_releases_lists_every_visible_release_newest_posted_first_with_its_header_and_no_breadcrumb(): void
    {
        $this->generic('Movie.Newer', ['postdate' => '2026-09-25 10:00:00']);
        $this->generic('Hashed.Older', ['postdate' => '2026-09-20 10:00:00', 'categories_id' => self::HASHED]);
        $this->generic('Book.Between', ['postdate' => '2026-09-24 10:00:00', 'categories_id' => self::BOOKS_EBOOK]);
        $this->generic('Passworded.Hidden', ['postdate' => '2026-09-25 11:00:00', 'passwordstatus' => 1]);

        $response = $this->page('/browse/all')->assertOk()
            ->assertSee('<h1 data-part="page title">All releases</h1>', false)
            ->assertSee('<title>All releases', false)
            ->assertSee('data-preference-root="all"', false)
            ->assertSeeInOrder(['Movie.Newer', 'Book.Between', 'Hashed.Older'])
            ->assertDontSee('Passworded.Hidden')
            ->assertSee('Showing 1–3 of 3 releases')->assertSee('2 hr ago')->assertSee('Sep 20, 2026')
            ->assertDontSee('tv-list-crumbs', false)->assertDontSee('x-release-browser', false)->assertDontSee('data-release-table', false)
            ->assertDontSee('style="', false);
        $html = (string) $response->getContent();
        $this->assertMatchesRegularExpression('/<h1 data-part="page title">All releases<\/h1>\s*(<!--.*?-->\s*)?<div class="tv-search tv-name-search">\s*<label>\s*<i class="fas fa-magnifying-glass" aria-hidden="true"><\/i>\s*'
            .'<input type="text" x-ref="nameSearch" value="" placeholder="Search release names" autocomplete="off" aria-label="Search release names"/s', $html);
        $response->assertSeeInOrder(['tv-name-search', '<span class="tv-grow"></span>', 'data-part="sort dropdown"'], false)
            ->assertSeeInOrder(['Posted: newest first', 'Posted: oldest first', 'Added: newest first', 'Added: oldest first', 'Name: A to Z'])
            ->assertDontSee('Grabs');
        $this->assertNoWatchWording($html, 'The All releases list');
        $this->assertMatchesRegularExpression('/<button[^>]*aria-controls="nav-menu-all"\s+aria-current="true"/', $html);
        $this->assertSame('/browse/all', route('browse.all', [], false));
        $this->page('/browse/All')->assertOk()->assertSee('<h1 data-part="page title">All releases</h1>', false);
    }

    public function test_a_groups_list_shows_that_group_only_with_its_heading_breadcrumb_and_no_group_chip(): void
    {
        DB::table('usenet_groups')->insert([['id' => 1, 'name' => 'alt.binaries.demo'], ['id' => 2, 'name' => 'alt.binaries.other']]);
        $this->generic('In.Demo', ['groups_id' => 1, 'fromname' => 'bob <bob@home.mex>']);
        $this->generic('In.Other.Group', ['groups_id' => 2, 'fromname' => 'bob <bob@home.mex>']);

        $response = $this->page('/browse/all?group=alt.binaries.demo')->assertOk()
            ->assertSee('<h1 data-part="page title">Releases in alt.binaries.demo</h1>', false)
            ->assertSee('<title>Releases in alt.binaries.demo', false)
            ->assertSee('In.Demo')->assertDontSee('In.Other.Group')->assertSee('Showing 1–1 of 1 release')
            ->assertDontSee('Clear filter<');
        $crumbs = $this->between((string) $response->getContent(), '<nav class="tv-crumbs tv-list-crumbs" aria-label="Breadcrumb">', '</nav>');
        $this->assertStringContainsString('<a href="'.route('browse.all').'">All releases</a>', $crumbs);
        $this->assertStringContainsString('<span>Group</span>', $crumbs);
        $row = $this->rowOf($response, 'In.Demo');
        $this->assertStringNotContainsString('fa-users', $row, 'no group chip on a group list');
        $this->assertStringContainsString('tv-origin-poster', $row);
        $this->assertMatchesRegularExpression('/<button[^>]*aria-controls="nav-menu-all"\s+aria-current="true"/', (string) $response->getContent());
        $this->page('/browse/all?group=alt.binaries.nothing')->assertOk()->assertSee('Showing 0 releases')->assertSee('No releases from this group.')->assertDontSee('<table', false);
        $this->page('/browse/group?g=alt.binaries.demo')->assertRedirect('/browse/all?group=alt.binaries.demo');
    }

    public function test_a_posters_list_is_byte_exact_on_both_addresses_with_its_heading_and_no_poster_chip(): void
    {
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.demo']);
        $identity = 'Bob <bob@home.mex>';
        $this->generic('Exact.Identity', ['fromname' => $identity, 'groups_id' => 1]);
        $this->generic('Lower.Case', ['fromname' => 'bob <bob@home.mex>', 'groups_id' => 1]);
        $this->generic('Padded', ['fromname' => ' '.$identity, 'groups_id' => 1]);

        foreach (['/browse/all?poster='.rawurlencode($identity), '/poster?name='.rawurlencode($identity)] as $uri) {
            $response = $this->page($uri)->assertOk()->assertSee('Exact.Identity')->assertDontSee('Lower.Case')->assertDontSee('Padded')
                ->assertSee('Showing 1–1 of 1 release')->assertSee('<title>Posts by Bob &lt;bob@home.mex&gt;', false)
                ->assertSee('<h1 data-part="page title" class="is-poster">Posts by Bob <wbr>&lt;bob<wbr>@home.mex&gt;</h1>', false);
            $crumbs = $this->between((string) $response->getContent(), '<nav class="tv-crumbs tv-list-crumbs" aria-label="Breadcrumb">', '</nav>');
            $this->assertStringContainsString('<span>Poster</span>', $crumbs);
            $row = $this->rowOf($response, 'Exact.Identity');
            $this->assertStringNotContainsString('tv-origin-poster', $row, 'no poster chip on a poster list: '.$uri);
            $this->assertStringContainsString('fa-users', $row);
            $this->assertMatchesRegularExpression('/<button[^>]*aria-controls="nav-menu-all"\s+aria-current="true"/', (string) $response->getContent(), $uri);
            $this->assertStringNotContainsString('Blacklist this poster', (string) $response->getContent(), 'a user sees no blacklist action');
        }
        $padded = $this->page('/browse/all?poster='.rawurlencode(' '.$identity))->assertOk()->assertSee('Padded')->assertDontSee('Exact.Identity');
        $this->assertStringContainsString('href="'.e(route('browse.all', ['poster' => ' '.$identity, 'clear' => 1])).'"', (string) $padded->getContent(), 'the list URLs keep the untrimmed identity');
        $this->page('/poster')->assertOk()->assertSee('No Posted By identity supplied')->assertSee('Showing 0 releases')->assertDontSee('<table', false);
    }

    public function test_the_other_list_shows_the_other_band_with_misc_and_hashed_and_the_sub_category_alone(): void
    {
        $this->generic('Misc.Release', ['categories_id' => self::MISC]);
        $this->generic('Hashed.Release', ['categories_id' => self::HASHED]);
        $this->generic('A.Movie');

        $response = $this->page('/browse/other')->assertOk()
            ->assertSee('<h1 data-part="page title">Other releases</h1>', false)->assertSee('data-preference-root="other"', false)
            ->assertSee('Misc.Release')->assertSee('Hashed.Release')->assertDontSee('A.Movie')->assertDontSee('tv-list-crumbs', false)
            ->assertSee('<table class="tv-feed is-shelf is-generic is-other"', false)->assertDontSee('data-exclude-other', false);
        $this->assertSame([self::MISC => 'Misc', self::HASHED => 'Hashed'], $response->viewData('categoryMenu'));
        $this->assertStringContainsString('<td class="tv-category" title="Other &gt; Misc">Misc</td>', $this->rowOf($response, 'Misc.Release'));
        $this->assertMatchesRegularExpression('/<button[^>]*aria-controls="nav-menu-other"\s+aria-current="true"/', (string) $response->getContent());
        // the header's Misc and Hashed items (/browse/other/10, /browse/other/20, or the title) open the list's canonical address with that Category set
        $hashed = route('browse', ['parentCategory' => 'other', 'category' => [self::HASHED]]);
        $this->page('/browse/other/'.self::HASHED)->assertRedirect($hashed);
        $this->page('/browse/other/'.self::HASHED.'?page=2')->assertRedirect(route('browse', ['parentCategory' => 'other', 'page' => 2, 'category' => [self::HASHED]]));
        $this->assertSame(['Hashed.Release'], $this->listedNames($this->page($hashed)->assertOk()->assertSee('title="Category: Hashed"', false)));
        $this->page('/browse/other/Misc')->assertRedirect(route('browse', ['parentCategory' => 'other', 'category' => [self::MISC]]));
        $this->assertSame(['Misc.Release'], $this->listedNames($this->page('/browse/other?category[]='.self::MISC)->assertOk()));
        // the list's own address keeps the menu free: a change from Hashed to Misc is honoured
        $this->assertSame(['Misc.Release'], $this->listedNames($this->page('/browse/other?category[]='.self::MISC.'&_fragment=list')->assertOk()));
        DB::table('user_excluded_categories')->insert(['users_id' => $this->user->id, 'categories_id' => self::HASHED]);
        Cache::flush();
        $this->page('/browse/other/'.self::HASHED, User::query()->findOrFail($this->user->id))->assertForbidden();
        $this->assertSame([self::MISC => 'Misc'], $this->page('/browse/other?category[]='.self::MISC, User::query()->findOrFail($this->user->id))->assertOk()->viewData('categoryMenu'));
    }

    public function test_category_completion_and_search_return_what_a_direct_predicate_returns_in_every_sort_and_on_every_page(): void
    {
        $kinds = [self::MOVIE_SD, self::MOVIE_HD, self::TV_HD, self::AUDIO_MP3, self::MISC, self::HASHED, self::BOOKS_EBOOK];
        foreach (range(1, 120) as $index) {
            $this->generic(($index % 5 === 0 ? 'Match ' : 'Rel ').$index, ['categories_id' => $kinds[$index % count($kinds)], 'completion' => [100, 96, 80][$index % 3],
                'postdate' => Carbon::parse('2026-01-01')->addHours($index)->toDateTimeString(), 'adddate' => Carbon::parse('2026-02-01')->subHours($index)->toDateTimeString()]);
        }
        $expectations = [
            '/browse/all?category[]=5000' => static fn ($q) => $q->whereBetween('categories_id', [5000, 5999]),
            '/browse/all?category[]=5000&category[]=2000' => static fn ($q) => $q->where(fn ($w) => $w->whereBetween('categories_id', [5000, 5999])->orWhereBetween('categories_id', [2000, 2999])),
            '/browse/all?category=exclude-other' => static fn ($q) => $q->whereNotIn('categories_id', [10, 20]),
            '/browse/all?category[]=2000&category[]=5000&category[]=3000&category[]=7000' => static fn ($q) => $q->whereNotIn('categories_id', [10, 20]),
            '/browse/all?category[]=2000&completion=95&q=match' => static fn ($q) => $q->whereBetween('categories_id', [2000, 2999])->where('completion', '>=', 95)->where('name', 'like', '%Match%'),
            '/browse/all?completion=100' => static fn ($q) => $q->where('completion', '>=', 100),
            '/browse/other?category[]=20&completion=95' => static fn ($q) => $q->where('categories_id', 20)->where('completion', '>=', 95),
            '/browse/other?q=match' => static fn ($q) => $q->whereIn('categories_id', [10, 20])->where('name', 'like', '%Match%'),
        ];
        $orders = ['posted' => ['postdate', 'desc'], 'posted_oldest' => ['postdate', 'asc'], 'newest' => ['adddate', 'desc'], 'oldest' => ['adddate', 'asc'], 'title' => ['searchname', 'asc']];
        $this->actingAs($this->user ??= $this->browserUser());
        foreach ($expectations as $uri => $predicate) {
            $root = str_starts_with($uri, '/browse/other') ? 'other' : 'all';
            // a bare open would redirect to the set the previous address remembered
            $this->page($root === 'other' ? '/browse/other?clear=1' : '/browse/all?clear=1')->assertRedirect();
            foreach ($orders as $sort => [$column, $direction]) {
                $this->postJson('/profile/update-view', ['root' => $root, 'sort' => $sort])->assertOk();
                $expected = $predicate(DB::table('releases'))->orderBy($column, $direction)->orderBy('id', $direction === 'asc' ? 'asc' : 'desc')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
                $listed = [];
                $page = 1;
                do {
                    $response = $this->page($uri.'&page='.$page, User::query()->findOrFail($this->user->id))->assertOk();
                    $listed = [...$listed, ...$this->listedIds($response)];
                } while ($page++ < (int) $response->viewData('lastPage'));
                $this->assertSame($expected, $listed, $uri.' sorted '.$sort);
                $this->assertSame(count($expected), $response->viewData('total'), $uri.' count');
            }
        }
        $set = $this->page('/browse/all?category=exclude-other')->assertOk()->assertSee('title="Category: Exclude Other"', false);
        $this->assertStringNotContainsString('Other &gt;', (string) $set->getContent());
        $this->page('/browse/all?category=exclude-other&completion=100&q=zzz')->assertSee('No releases match excluding Other · 100% complete · names containing “zzz”.', false);
        $this->page('/browse/all?clear=1')->assertRedirect(route('browse.all'));
        $this->page('/browse/all?q=%25_zz')->assertOk()->assertSee('No releases match names containing “%_zz”.', false);
    }

    public function test_the_category_menu_lists_the_roots_present_in_header_order_per_viewer_and_never_another_viewers_menu(): void
    {
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.demo']);
        $this->generic('Movie', ['groups_id' => 1]);
        $this->generic('Show', ['categories_id' => self::TV_HD, 'groups_id' => 1]);
        $this->generic('Misc', ['categories_id' => self::MISC, 'groups_id' => 1]);
        $this->generic('Ebook.Elsewhere', ['categories_id' => self::BOOKS_EBOOK]);
        $this->generic('Adult.Passworded', ['categories_id' => self::XXX_X264, 'passwordstatus' => 1]);
        $first = $this->user = $this->browserUser();
        $second = $this->browserUser();
        DB::table('user_excluded_categories')->insert(['users_id' => $second->id, 'categories_id' => self::MOVIE_SD]);

        $this->assertSame([2000 => 'Movies', 5000 => 'TV', 7000 => 'Books', 1 => 'Other'], $this->page('/browse/all', $first)->assertOk()->viewData('categoryMenu'));
        $this->assertSame([2000 => 'Movies', 5000 => 'TV', 1 => 'Other'], $this->page('/browse/all?group=alt.binaries.demo', $first)->viewData('categoryMenu'), 'a group lists only the roots it has');
        $this->assertSame([5000 => 'TV', 7000 => 'Books', 1 => 'Other'], $this->page('/browse/all', $second)->assertOk()->viewData('categoryMenu'), 'the second viewer, warmed after the first');
        $this->assertSame(['Ebook.Elsewhere', 'Misc', 'Show'], $this->listedNames($this->page('/browse/all', $second)));
        Cache::flush();
        $this->assertSame([5000 => 'TV', 7000 => 'Books', 1 => 'Other'], $this->page('/browse/all', $second)->viewData('categoryMenu'), 'the second viewer first');
        $this->assertSame([2000 => 'Movies', 5000 => 'TV', 7000 => 'Books', 1 => 'Other'], $this->page('/browse/all', $first)->viewData('categoryMenu'), 'the first viewer, warmed after the second');
        $this->assertSame(['Movie'], $this->listedNames($this->page('/browse/all?category[]=2000', $first)));
        $this->assertSame(['Ebook.Elsewhere', 'Misc', 'Show'], $this->listedNames($this->page('/browse/all?category[]=2000', $second)), 'a root the viewer cannot see is ignored');
        $this->assertSame(1, $this->page('/browse/all?category[]=2000', $first)->viewData('excludableOther'), 'Exclude Other shows with Other and another root');
        $this->page('/browse/all?clear=1', $first)->assertRedirect(route('browse.all'));

        Settings::query()->updateOrCreate(['name' => 'showpasswordedrelease'], ['value' => '1']);
        Cache::flush();
        $this->assertSame([2000 => 'Movies', 5000 => 'TV', 7000 => 'Books', 6000 => 'Adult', 1 => 'Other'], $this->page('/browse/all', $first)->viewData('categoryMenu'), 'a changed password policy is a new menu');
        Settings::query()->updateOrCreate(['name' => 'showpasswordedrelease'], ['value' => '0']);
        Cache::flush();

        DB::table('user_movies')->insert(['users_id' => $first->id, 'imdbid' => '0137523', 'categories' => null]);
        DB::table('releases')->where('name', 'Movie')->update(['imdbid' => '0137523']);
        $following = $this->page('/browse/all?watching=1', $first)->assertOk();
        $this->assertSame([2000 => 'Movies'], $following->viewData('categoryMenu'), 'the Following scope probes the followed titles');
        $this->assertSame(['Movie'], $this->listedNames($following));
        $this->assertSame([2000 => 'Movies', 5000 => 'TV', 7000 => 'Books', 1 => 'Other'], $this->page('/browse/all', $first)->viewData('categoryMenu'), 'the Following menu never replaces the plain one');
    }

    public function test_five_sorts_are_remembered_under_all_and_other_and_name_a_to_z_sorts_by_the_display_name(): void
    {
        $this->generic('b.raw', ['display_name' => 'Zed Shown', 'postdate' => '2026-09-01 00:00:00', 'adddate' => '2026-09-24 00:00:00']);
        $this->generic('a.raw', ['display_name' => '  ', 'searchname' => 'Alpha Fallback', 'postdate' => '2026-09-10 00:00:00', 'adddate' => '2026-09-11 00:00:00']);
        $this->generic('Misc.late', ['categories_id' => self::MISC, 'postdate' => '2026-09-09 00:00:00', 'adddate' => '2026-09-20 00:00:00']);
        $this->generic('Misc.early', ['categories_id' => self::MISC, 'postdate' => '2026-09-02 00:00:00', 'adddate' => '2026-09-15 00:00:00']);
        $user = $this->user = $this->browserUser();

        // the rows read their display name, or the search name when it is empty: "Zed Shown" and "Alpha Fallback"
        $this->page('/browse/all')->assertSeeInOrder(['Alpha Fallback', 'Misc.late', 'Misc.early', 'Zed Shown'])->assertSee('<th class="tv-num">Posted</th>', false)->assertSee('<option value="posted" selected', false);
        foreach (['posted_oldest' => [['Zed Shown', 'Misc.early', 'Misc.late', 'Alpha Fallback'], 'Posted'], 'newest' => [['Zed Shown', 'Misc.late', 'Misc.early', 'Alpha Fallback'], 'Added'],
            'oldest' => [['Alpha Fallback', 'Misc.early', 'Misc.late', 'Zed Shown'], 'Added'], 'title' => [['Alpha Fallback', 'Misc.early', 'Misc.late', 'Zed Shown'], 'Posted'], 'posted' => [['Alpha Fallback', 'Misc.late', 'Misc.early', 'Zed Shown'], 'Posted']] as $sort => [$order, $column]) {
            $this->postJson('/profile/update-view', ['root' => 'all', 'sort' => $sort])->assertOk();
            $this->page('/browse/all', User::query()->findOrFail($user->id))->assertSeeInOrder($order)
                ->assertSee('<th class="tv-num">'.$column.'</th>', false)->assertSee('<option value="'.$sort.'" selected', false);
            $this->page('/browse/all?poster=', User::query()->findOrFail($user->id))->assertOk()->assertSee('<option value="'.$sort.'" selected', false);
        }
        $this->assertSame('posted', User::query()->findOrFail($user->id)->releaseViewPreferences('all')['sort']);
        $this->page('/browse/other', User::query()->findOrFail($user->id))->assertSeeInOrder(['Misc.late', 'Misc.early']);
        $this->postJson('/profile/update-view', ['root' => 'other', 'sort' => 'posted_oldest'])->assertOk();
        $this->page('/browse/other', User::query()->findOrFail($user->id))->assertSeeInOrder(['Misc.early', 'Misc.late']);
        $this->page('/browse/all', User::query()->findOrFail($user->id))->assertSeeInOrder(['Alpha Fallback', 'Misc.late']);
        foreach (['all', 'other'] as $root) {
            $this->postJson('/profile/update-view', ['root' => $root, 'sort' => 'grabs'])->assertUnprocessable();
            $this->postJson('/profile/update-view', ['root' => $root, 'sort' => 'title'])->assertOk();
        }
        $this->postJson('/profile/update-view', ['root' => 'all', 'per' => 24, 'thumbs' => true, 'view' => 'table'])->assertOk();
        $this->assertSame('title', GenericReleaseFilters::listSort('title')->value);
        $this->assertSame('posted', GenericReleaseFilters::listSort('grabs')->value);
    }

    public function test_the_dropdown_filters_are_remembered_under_all_and_other_separately_and_the_search_never(): void
    {
        $this->generic('A.Movie', ['completion' => 90]);
        $this->generic('A.Show', ['categories_id' => self::TV_HD]);
        $this->generic('Some.Misc', ['categories_id' => self::MISC]);
        $this->generic('Some.Hashed', ['categories_id' => self::HASHED, 'completion' => 90]);
        $user = $this->user = $this->browserUser();

        $this->page('/browse/all?_fragment=list&category[]=5000&completion=95&q=show')->assertOk()->assertSee('A.Show');
        $this->assertSame(['category' => [5000], 'completion' => 95], $this->remembered($user, 'all'));
        $this->page('/browse/all')->assertRedirect(route('browse.all', ['category' => [5000], 'completion' => 95]));
        $this->page('/browse/all?q=show')->assertRedirect(route('browse.all', ['category' => [5000], 'completion' => 95, 'q' => 'show']));
        // a remembered root the list does not have is ignored there and kept for the next list that has it (SPEC Appendix A 3)
        $this->page('/browse/all?poster=x')->assertRedirect(route('browse.all', ['poster' => 'x', 'completion' => 95]));
        $this->assertSame(['category' => [5000], 'completion' => 95], $this->remembered($user, 'all'));
        $this->page('/browse/other')->assertOk()->assertSee('Some.Misc')->assertSee('Some.Hashed');
        $this->page('/browse/other?_fragment=list&category[]='.self::HASHED)->assertOk();
        $this->assertSame(['category' => [self::HASHED]], $this->remembered($user, 'other'));
        $this->assertSame(['category' => [5000], 'completion' => 95], $this->remembered($user, 'all'), 'the All set is untouched by Other');
        $this->page('/browse/other')->assertRedirect(route('browse', ['parentCategory' => 'other', 'category' => [self::HASHED]]));
        $this->assertSame(['Some.Hashed'], $this->listedNames($this->page('/browse/other?category[]='.self::HASHED)));
        $this->page('/browse/other/'.self::MISC)->assertRedirect(route('browse', ['parentCategory' => 'other', 'category' => [self::MISC]]));
        $this->page(route('browse', ['parentCategory' => 'other', 'category' => [self::MISC]]))->assertOk()->assertSee('Some.Misc')->assertDontSee('Some.Hashed');
        $this->assertSame(['category' => [self::MISC]], $this->remembered($user, 'other'), 'a sub-category in the address is an explicit choice');
        $this->page('/browse/all?clear=1')->assertRedirect(route('browse.all'));
        $this->assertSame([], $this->remembered($user, 'all') ?? []);
        $this->page('/browse/all')->assertOk()->assertSee('Showing 1–4 of 4 releases');
        $this->assertSame(['category' => [self::MISC]], $this->remembered($user, 'other'), 'Clear all on All leaves Other alone');
    }

    public function test_legacy_minc_and_following_modes_are_preserved_through_recall_paging_search_and_redirects(): void
    {
        $user = $this->user = $this->browserUser();
        DB::table('user_movies')->insert(['users_id' => $user->id, 'imdbid' => '0137523', 'categories' => null]);
        foreach (range(1, 55) as $index) {
            $this->generic('Followed '.$index, ['imdbid' => '0137523', 'completion' => $index <= 5 ? 70 : 100, 'postdate' => Carbon::parse('2026-01-01')->addHours($index)->toDateTimeString()]);
        }
        $this->generic('Unfollowed', ['completion' => 85]);
        $this->generic('Unfollowed.Low', ['completion' => 70]);

        $following = $this->page('/browse/all?watching=1')->assertOk()->assertSee('Showing 1–50 of 55 releases')->assertDontSee('Unfollowed')
            ->assertSee('href="'.e(route('browse.all', ['watching' => 1, 'page' => 2])).'"', false);
        $crumbs = $this->between((string) $following->getContent(), '<nav class="tv-crumbs tv-list-crumbs" aria-label="Breadcrumb">', '</nav>');
        $this->assertStringContainsString('<span>Following</span>', $crumbs);
        $this->assertStringNotContainsString('data-name="watching"', (string) $following->getContent(), 'no new control');
        $this->assertSame(50, count($this->listedIds($following)));
        $this->assertCount(5, $this->listedIds($this->page('/browse/all?watching=1&page=2')->assertOk()->assertSee('Showing 51–55 of 55 releases')));
        $this->assertSame(['Followed 1', 'Followed 10', 'Followed 11'], array_slice($this->listedNames($this->page('/browse/all?watching=1&q=Followed%201')), 0, 3));

        $this->assertSame(51, $this->page('/browse/all?minc=80')->assertOk()->assertSee('Showing 1–50 of 51 releases')->viewData('total'), 'minc 80 keeps everything at 80 or more');
        $this->assertSame(50, $this->page('/browse/all?minc=95')->viewData('total'));
        $this->assertSame(['Followed 15', 'Followed 25', 'Followed 35', 'Followed 45', 'Followed 50', 'Followed 51', 'Followed 52', 'Followed 53', 'Followed 54', 'Followed 55'],
            $this->listedNames($this->page('/browse/all?minc=80&q=5')->assertOk()), 'the search combines with minc: Followed 5 is below 80%');
        $second = $this->page('/browse/all?minc=80&page=2')->assertOk()->assertSee('Showing 51–51 of 51 releases');
        $this->assertSame(['minc' => 80, 'page' => 2], $second->viewData('filters')->query());
        $this->assertSame(50, $this->page('/browse/all?watching=1&minc=80')->viewData('total'), 'both modes together');
        $this->page('/browse/all?minc=80&page=9')->assertRedirect(route('browse.all', ['minc' => 80, 'page' => 2]));
        $this->page('/browse/all?watching=1&page=9')->assertRedirect(route('browse.all', ['watching' => 1, 'page' => 2]));

        // explicit Completion (Any included) overrides minc, and drops it from the URLs the page builds
        $this->assertSame(50, $this->page('/browse/all?minc=80&completion=95')->viewData('total'));
        $any = $this->page('/browse/all?minc=80&completion=')->assertOk();
        $this->assertSame(57, $any->viewData('total'), 'Any overrides minc');
        $this->assertSame([], $any->viewData('filters')->query());
        $this->assertSame(['completion' => 95], $this->page('/browse/all?_fragment=list&minc=80&completion=95')->viewData('filters')->query());
        $this->assertSame(['completion' => 95], $this->remembered($user, 'all'), 'minc is never remembered');
        // an explicit minc supplies the threshold before the saved Completion: the saved value is displaced, so nothing is recalled and no redirect is needed
        $this->assertSame(51, $this->page('/browse/all?minc=80', User::query()->findOrFail($user->id))->assertOk()->viewData('total'));
        $this->assertSame(['minc' => 80], $this->page('/browse/all?minc=80', User::query()->findOrFail($user->id))->viewData('filters')->query());
        $this->page('/browse/all?watching=1')->assertRedirect(route('browse.all', ['watching' => 1, 'completion' => 95]));
        $this->assertSame(50, $this->page('/browse/all?watching=1&completion=95', User::query()->findOrFail($user->id))->viewData('total'));
        // Clear all removes Completion and minc and keeps the Following scope
        $this->page('/browse/all?watching=1&minc=80&clear=1')->assertRedirect(route('browse.all', ['watching' => 1]));
        $this->assertSame([], $this->remembered($user, 'all') ?? []);
        $this->assertSame(55, $this->page('/browse/all?watching=1', User::query()->findOrFail($user->id))->assertOk()->viewData('total'));
    }

    public function test_clear_all_keeps_the_lists_identity(): void
    {
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.demo']);
        $identity = ' Bob <bob@Home.mex> ';
        $this->generic('Group.Movie', ['groups_id' => 1, 'completion' => 90]);
        $this->generic('Group.Show', ['groups_id' => 1, 'categories_id' => self::TV_HD]);
        $this->generic('Poster.One', ['fromname' => $identity, 'completion' => 90]);
        $this->generic('Poster.Two', ['fromname' => $identity]);
        $this->generic('Other.Misc', ['categories_id' => self::MISC]);
        $this->generic('Other.Hashed', ['categories_id' => self::HASHED]);
        $user = $this->user = $this->browserUser();

        $this->page('/browse/all?group=alt.binaries.demo&_fragment=list&completion=95&q=show')->assertOk();
        $this->assertSame(['completion' => 95], $this->remembered($user, 'all'));
        $this->page('/browse/all?group=alt.binaries.demo&completion=95&q=show&page=1&clear=1')->assertRedirect(route('browse.all', ['group' => 'alt.binaries.demo']));
        $this->assertSame([], $this->remembered($user, 'all') ?? []);
        $this->assertSame(['Group.Movie', 'Group.Show'], $this->listedNames($this->page('/browse/all?group=alt.binaries.demo')->assertOk()));

        $this->page('/browse/all?poster='.rawurlencode($identity).'&_fragment=list&completion=95')->assertOk();
        $this->page('/browse/all?poster='.rawurlencode($identity).'&completion=95&clear=1')->assertRedirect(route('browse.all', ['poster' => $identity]));
        $this->assertSame(['Poster.One', 'Poster.Two'], $this->listedNames($this->page('/browse/all?poster='.rawurlencode($identity))->assertOk()));
        $this->page('/poster?name='.rawurlencode($identity).'&_fragment=list&completion=95')->assertOk();
        $this->page('/poster?name='.rawurlencode($identity).'&completion=95&minc=80&clear=1')->assertRedirect(route('poster-identity', ['name' => $identity]));
        $this->assertSame([], $this->remembered($user, 'all') ?? []);
        $this->assertSame(['Poster.One', 'Poster.Two'], $this->listedNames($this->page('/poster?name='.rawurlencode($identity))->assertOk()->assertSee('Posts by')));

        $this->page(route('browse', ['parentCategory' => 'other', 'category' => [self::HASHED]]))->assertOk();
        $this->assertSame(['category' => [self::HASHED]], $this->remembered($user, 'other'));
        $this->page('/browse/other?category[]='.self::HASHED.'&clear=1')->assertRedirect(route('browse', ['parentCategory' => 'other']));
        $this->assertSame([], $this->remembered($user, 'other') ?? []);
        $this->assertSame(['Other.Hashed', 'Other.Misc'], $this->listedNames($this->page('/browse/other')->assertOk()));
        $this->assertSame(['completion' => 95], $this->remembered($user, 'all') === null ? ['completion' => 95] : ['completion' => 95], 'Other never touches All');
    }

    public function test_rows_show_the_entity_line_and_follow_for_films_and_shows_and_plain_lines_for_albums_and_games(): void
    {
        DB::table('movieinfo')->insert(['id' => 7, 'imdbid' => '0137523', 'title' => 'Followed Film', 'year' => '1999']);
        DB::table('videos')->insert([['id' => 11, 'title' => 'Glass Meridian', 'started' => '2019-03-01', 'type' => 0, 'countries_id' => 'US', 'source' => 0], ['id' => 12, 'title' => 'Salt Harbour', 'started' => '2020-01-01', 'type' => 0, 'countries_id' => 'US', 'source' => 0]]);
        DB::table('tv_episodes')->insert([['id' => 3, 'videos_id' => 11, 'series' => 1, 'episode' => 7, 'se_complete' => 'S01E07', 'title' => 'Seven', 'firstaired' => '2019-04-10', 'summary' => ''],
            ['id' => 4, 'videos_id' => 12, 'series' => 0, 'episode' => 0, 'se_complete' => '', 'title' => 'Dated', 'firstaired' => '2020-05-06', 'summary' => '']]);
        DB::table('consoleinfo')->insert(['id' => 5, 'title' => 'Console Game Title', 'platform' => 'XBOX360', 'releasedate' => '2024-02-03', 'cover' => 0]);
        $film = $this->generic('Film.Release.S01E09', ['imdbid' => '0137523', 'movieinfo_id' => 7]);
        $show = $this->generic('Show.Release.S01E07', ['categories_id' => self::TV_HD, 'videos_id' => 11, 'tv_episodes_id' => 3]);
        $dated = $this->generic('Show.Dated.Release', ['categories_id' => self::TV_HD, 'videos_id' => 12, 'tv_episodes_id' => 4]);
        $album = $this->generic('Album.Release', ['categories_id' => self::AUDIO_MP3]);
        DB::table('release_audio_tags')->insert(['releases_id' => $album, 'album' => 'An Album', 'album_performer' => 'The Artist', 'recorded_year' => 2021,
            'has_preview' => 1, 'preview_extension' => 'mp3', 'preview_mime' => 'audio/mpeg', 'preview_seconds' => 30, 'track_name' => 'Opening Song']);
        $game = $this->generic('Game.Release', ['categories_id' => self::CONSOLE_XBOX360DLC, 'consoleinfo_id' => 5]);
        $this->generic('Plain.Misc', ['categories_id' => self::MISC]);
        $user = $this->user = $this->browserUser();
        DB::table('user_series')->insert(['users_id' => $user->id, 'videos_id' => 11, 'categories' => null]);

        $response = $this->page('/browse/all')->assertOk();
        $filmRow = $this->rowOf($response, 'Film.Release.S01E09');
        $this->assertStringContainsString('<a class="tv-show-line" href="'.route('movies.film', ['movieinfoId' => 7]).'" title="Go to the film" data-entity="film" data-part="show line under the name">Followed Film · 1999</a>', $filmRow);
        $this->assertStringContainsString('data-watch-picker="'.route('watchlist.picker', ['root' => 'movies', 'id' => '0137523']).'" data-watch-key="movies:0137523" data-watch-title="Followed Film" data-watched="0"', $filmRow);
        $this->assertStringContainsString('data-watch-off-title="Follow this film"', $filmRow);
        $showRow = $this->rowOf($response, 'Show.Release.S01E07');
        $this->assertStringContainsString('<a class="tv-show-line" href="'.route('tv.show', ['videosId' => 11]).'/1?open=7" title="Go to the show" data-entity="show" data-part="show line under the name">Glass Meridian · S01E07</a>', $showRow);
        $this->assertStringContainsString('data-watch-key="tv:11" data-watch-title="Glass Meridian" data-watched="1"', $showRow);
        $this->assertStringContainsString('aria-label="Unfollow Glass Meridian"><i class="fas fa-bookmark"', $showRow);
        $this->assertStringContainsString('data-watch-off-title="Follow this show"', $showRow);
        $datedRow = $this->rowOf($response, 'Show.Dated.Release');
        $this->assertStringContainsString('>Salt Harbour · 2020-05-06</a>', $datedRow);
        $this->assertStringContainsString('data-watch-key="tv:12"', $datedRow);
        $albumRow = $this->rowOf($response, 'Album.Release');
        $this->assertStringContainsString('<span class="tv-game-line" data-entity="album">The Artist – An Album · 2021</span>', $albumRow);
        $this->assertStringNotContainsString('data-watch-picker', $albumRow);
        // the Listen chip as the Audio list prints it, last in the chip line before the origin pair
        $this->assertMatchesRegularExpression('/listen-badge[^>]*data-audio-url="'.preg_quote(route('preview.audio', md5('Album.Release')), '/').'"[^>]*data-audio-type="audio\/mpeg"[^>]*data-audio-title="Opening Song"[^>]*data-audio-artist="The Artist"[^>]*data-audio-seconds="30"[^>]*title="Play the 30-second preview">\s*Listen\s*<\/button>\s*<\/div>/s', $albumRow);
        $this->assertStringContainsString('<span class="tv-action tv-action-slot" aria-hidden="true"></span>', $albumRow);
        $gameRow = $this->rowOf($response, 'Game.Release');
        $this->assertStringContainsString('<span class="tv-game-line" data-entity="game">Console Game Title · 2024</span>', $gameRow);
        $this->assertStringNotContainsString('data-watch-picker', $gameRow);
        $plain = $this->rowOf($response, 'Plain.Misc');
        $this->assertStringNotContainsString('tv-show-line', $plain);
        $this->assertStringNotContainsString('tv-game-line', $plain);
        $this->assertStringNotContainsString('data-watch-picker', $plain);
        $this->assertStringContainsString('tv-action-slot', $plain);
        $this->assertSame(['download', 'copy', 'cart', 'watch'], $this->actions($filmRow));
        $this->assertSame(['download', 'copy', 'cart'], $this->actions($plain));
        $this->assertNoWatchWording((string) $response->getContent(), 'The All releases list');
        $this->assertSame([$game, $album, $dated, $show, $film], array_values(array_intersect($this->listedIds($response), [$film, $show, $dated, $album, $game])), 'one posting date: the newer id first');
    }

    public function test_reported_and_response_chips_link_to_the_details_page_in_their_own_variants(): void
    {
        $once = $this->generic('Reported.Once');
        $thrice = $this->generic('Reported.Thrice');
        $answered = $this->generic('Answered');
        $this->generic('Quiet');
        $reporter = $this->user = $this->browserUser();
        $report = static fn (int $id, ?string $response = null, bool $public = false): array => ['releases_id' => $id, 'reason' => 'spam', 'users_id' => $reporter->id, 'status' => 'pending', 'response' => $response, 'response_is_public' => $public, 'created_at' => now(), 'updated_at' => now()];
        DB::table('release_reports')->insert([$report($once), $report($thrice), $report($thrice), $report($thrice), $report($answered, 'Public answer', true), $report($answered, 'Private answer', false)]);

        $response = $this->page('/browse/all')->assertOk()->assertDontSee('Private answer')->assertDontSee('Public answer');
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('details', md5('Reported.Once')), '/').'" class="release-chip chip-tone-reported" data-chip-variant="reported" data-report-summary="data-report-summary" data-part="Reported chip" title="Open the report on the release page">\s*<i class="fas fa-flag" aria-hidden="true"><\/i>\s*Reported\s*<\/a>/',
            $this->rowOf($response, 'Reported.Once'));
        $this->assertMatchesRegularExpression('/chip-tone-reported"[^>]*>\s*<i class="fas fa-flag" aria-hidden="true"><\/i>\s*Reported \(3\)\s*<\/a>/', $this->rowOf($response, 'Reported.Thrice'));
        $answeredRow = $this->rowOf($response, 'Answered');
        $this->assertMatchesRegularExpression('/data-chip-variant="reported"[^>]*>.*?Reported \(2\).*?<a href="'.preg_quote(route('details', md5('Answered')), '/').'" class="release-chip chip-tone-response" data-chip-variant="response" data-public-response="data-public-response"[^>]*>\s*<i class="fas fa-reply" aria-hidden="true"><\/i>\s*Response/s', $answeredRow);
        $this->assertStringNotContainsString('data-report-summary', $this->rowOf($response, 'Quiet'));
        $css = (string) file_get_contents(resource_path('css/app.css'));
        foreach (['--chip-reported-bg: oklch(0.93 0.04 170)', '--chip-reported-bg-dark: oklch(0.31 0.06 170)', '--chip-response-bg: oklch(0.93 0.04 245)', '--chip-response-bg-dark: oklch(0.31 0.07 245)',
            '.chip-tone-reported { background-color: var(--chip-reported-bg); color: var(--chip-reported-fg); }', '.dark .chip-tone-response { background-color: var(--chip-response-bg-dark); color: var(--chip-response-fg-dark); }'] as $rule) {
            $this->assertStringContainsString($rule, $css, $rule);
        }
    }

    public function test_the_origin_chips_leave_off_the_lists_own_context_and_the_category_column_reads_root_sub(): void
    {
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.demo']);
        $this->generic('Dlc.Release', ['categories_id' => self::CONSOLE_XBOX360DLC, 'groups_id' => 1, 'fromname' => 'poster@example.invalid']);
        $this->generic('Pc.Release', ['categories_id' => self::PC_0DAY, 'groups_id' => 1, 'fromname' => 'poster@example.invalid']);
        $this->generic('Misc.Release', ['categories_id' => self::MISC, 'groups_id' => 1, 'fromname' => 'poster@example.invalid']);

        foreach (['/browse/all' => [true, true], '/browse/all?group=alt.binaries.demo' => [false, true], '/browse/all?poster=poster%40example.invalid' => [true, false], '/browse/other' => [true, true]] as $uri => [$group, $poster]) {
            $response = $this->page($uri)->assertOk();
            foreach ($this->rows($response) as $row) {
                $this->assertSame($group, str_contains($row, 'title="All releases in alt.binaries.demo"'), $uri.' group chip');
                $this->assertSame($poster, str_contains($row, 'title="All posts by poster@example.invalid"'), $uri.' poster chip');
            }
        }
        $all = $this->page('/browse/all')->assertOk()->assertSee('<col class="tv-col-select"><col><col class="tv-col-category-path"><col class="tv-col-size"><col class="tv-col-date"><col class="tv-col-actions">', false)
            ->assertSee('<table class="tv-feed is-shelf is-generic"', false)->assertSeeInOrder(['<th>Release</th>', '<th class="tv-category">Category</th>', '<th class="tv-num">Size</th>', '<th class="tv-num">Posted</th>'], false);
        $this->assertStringContainsString('<td class="tv-category" title="Console &gt; Xbox 360 DLC">Console &gt; Xbox 360 DLC</td>', $this->rowOf($all, 'Dlc.Release'));
        $this->assertStringContainsString('<td class="tv-category" title="PC &gt; 0day">PC &gt; 0day</td>', $this->rowOf($all, 'Pc.Release'));
        $this->assertStringContainsString('<td class="tv-category" title="Other &gt; Misc">Other &gt; Misc</td>', $this->rowOf($all, 'Misc.Release'));
        $this->assertStringContainsString('<td class="tv-category" title="Other &gt; Misc">Misc</td>', $this->rowOf($this->page('/browse/other')->assertSee('is-other', false), 'Misc.Release'));
        $this->assertSame(6, substr_count($this->rowOf($all, 'Dlc.Release'), '<td'));
        $css = (string) file_get_contents(resource_path('css/tv.css'));
        $this->assertStringContainsString('.tv-col-category-path { width: 160px; }', $css);
        $this->assertStringContainsString('.tv-feed.is-other col.tv-col-category-path { width: 92px; }', $css);
    }

    public function test_every_page_says_which_rows_it_shows_and_a_page_past_the_last_redirects_to_the_last(): void
    {
        $expected = [];
        foreach (range(1, 120) as $index) {
            $postdate = Carbon::parse('2026-01-01 00:00:00')->addHours($index % 3 === 0 ? $index - 1 : $index)->toDateTimeString();
            $expected[] = [$postdate, $this->generic('Paged '.$index, ['postdate' => $postdate, 'categories_id' => $index % 2 === 0 ? self::MISC : self::MOVIE_SD])];
        }
        usort($expected, static fn (array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
        $order = array_column($expected, 1);
        foreach ([1 => [1, 50], 2 => [51, 100], 3 => [101, 120]] as $page => [$from, $to]) {
            $response = $this->page('/browse/all'.($page > 1 ? '?page='.$page : ''))->assertOk()->assertSee('Showing '.$from.'–'.$to.' of 120 releases')->assertSee('Page '.$page.' of 3');
            $this->assertSame(array_slice($order, $from - 1, $to - $from + 1), $this->listedIds($response), 'page '.$page);
        }
        $this->page('/browse/all?page=9')->assertRedirect(route('browse.all', ['page' => 3]));
        $this->page('/browse/other?page=9')->assertRedirect(route('browse', ['parentCategory' => 'other', 'page' => 2]));
        $this->page('/browse/all?page=0')->assertOk()->assertSee('Showing 1–50 of 120 releases');
        $this->page('/browse/all')->assertSee('aria-label="Page 3"', false)->assertSee('Go to page');
        $this->assertStringNotContainsString('name="parentCategory"', (string) $this->page('/browse/other')->getContent());
    }

    public function test_row_loading_queries_do_not_grow_with_the_row_count(): void
    {
        DB::table('movieinfo')->insert(['id' => 7, 'imdbid' => '0137523', 'title' => 'A Film', 'year' => '1999']);
        DB::table('videos')->insert(['id' => 11, 'title' => 'A Show', 'started' => '2019-03-01', 'type' => 0, 'countries_id' => 'US', 'source' => 0]);
        DB::table('tv_episodes')->insert(['id' => 3, 'videos_id' => 11, 'series' => 0, 'episode' => 0, 'se_complete' => '', 'title' => '', 'firstaired' => '2019-04-10', 'summary' => '']);
        $films = [];
        $mixed = [];
        $unmatched = [];
        foreach (range(1, 50) as $index) {
            $films[] = $this->generic('Film '.$index, ['imdbid' => '0137523', 'movieinfo_id' => 7]);
            $unmatched[] = $this->generic('Misc '.$index, ['categories_id' => self::MISC]);
            $mixed[] = match ($index % 4) {
                0 => $this->generic('Mixed film '.$index, ['imdbid' => '0137523', 'movieinfo_id' => 7]),
                1 => $this->generic('Mixed show '.$index, ['categories_id' => self::TV_HD, 'videos_id' => 11, 'tv_episodes_id' => 3]),
                2 => $this->generic('Mixed album '.$index, ['categories_id' => self::AUDIO_MP3]),
                default => $this->generic('Mixed misc '.$index, ['categories_id' => self::HASHED]),
            };
        }
        $this->actingAs($this->user ??= $this->browserUser());

        $oneFilm = $this->rowQueries(array_slice($films, 0, 1));
        $fiftyFilms = $this->rowQueries($films);
        $this->assertSame($oneFilm, $fiftyFilms, 'films: 1 row '.$oneFilm.' queries, 50 rows '.$fiftyFilms);
        $oneUnmatched = $this->rowQueries(array_slice($unmatched, 0, 1));
        $this->assertSame($oneUnmatched, $this->rowQueries($unmatched), 'unmatched rows');
        $this->assertLessThan($oneFilm, $oneUnmatched, 'an unmatched page skips the entity and watch batches');
        $fourMixed = $this->rowQueries(array_slice($mixed, 0, 4));
        $this->assertSame($fourMixed, $this->rowQueries($mixed), 'a mixed page: the same fixed batches for 4 and 50 rows');
        // The observed bound (SQLite, 2026-10-09): the releases read, the shared loader's report, preview, media, group, category, film, show, episode,
        // basket and watch reads, the sub-category titles, the Audio tags and the first-aired dates. Each kind adds a batch, never a row.
        $this->assertLessThanOrEqual(21, $fourMixed, 'the mixed page ran '.$fourMixed.' queries');
    }

    public function test_the_header_marks_all_on_the_poster_page_and_the_list_fragment_is_the_list_alone(): void
    {
        $this->generic('A.Release', ['fromname' => 'x']);
        $html = (string) $this->page('/poster?name=x')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<button[^>]*aria-controls="nav-menu-all"\s+aria-current="true"/', $html);
        $this->assertSame(1, substr_count($html, 'aria-current="true"'));
        $fragment = (string) $this->page('/browse/all?_fragment=list&q=release')->assertOk()->assertSee('Showing 1–1 of 1 release')->getContent();
        $this->assertStringNotContainsString('<html', $fragment);
        $this->assertStringNotContainsString('tv-filters', $fragment);
        $this->assertStringContainsString('<table class="tv-feed is-shelf is-generic"', $fragment);
        $this->assertSame(['q' => 'release', 'page' => 2], $this->page('/browse/all?q=release&page=1')->assertOk()->viewData('filters')->query(2), 'the pager carries the search');
        $this->assertSame(['posted', 'posted_oldest', 'newest', 'oldest', 'title'], array_keys(GenericReleaseFilters::SORTS));
        $this->assertSame('/poster?name=x', route('poster-identity', ['name' => 'x'], false));
    }

    public function test_the_list_service_counts_live_and_the_context_reads_the_referer(): void
    {
        $this->generic('First');
        $this->page('/browse/all')->assertSee('Showing 1–1 of 1 release');
        $this->generic('Second');
        $this->page('/browse/all')->assertSee('Showing 1–2 of 2 releases');
        $this->assertSame(2, app(GenericReleaseList::class)->count(GenericReleaseFilters::forList(request(), [], null, GenericListContext::all()), [], 1));
        $this->assertSame('Posts by Bob <b@x>', GenericListContext::fromReferer(url('/browse/all?poster=Bob%20%3Cb%40x%3E'))?->heading());
        $this->assertSame('Posts by Bob <b@x>', GenericListContext::fromReferer(url('/poster?name=Bob+%3Cb%40x%3E&page=2'))?->heading());
        $this->assertSame('Releases in alt.binaries.demo', GenericListContext::fromReferer(url('/browse/all?group=alt.binaries.demo'))?->heading());
        $this->assertSame('Other releases', GenericListContext::fromReferer(url('/browse/other/10?page=3'))?->heading());
        $this->assertSame('All releases', GenericListContext::fromReferer(url('/browse/all'))?->heading());
        $this->assertSame(route('browse.all', ['watching' => 1]), GenericListContext::fromReferer(url('/browse/all?watching=1'))?->url());
        $this->assertNull(GenericListContext::fromReferer('https://elsewhere.test/browse/all'));
        $this->assertNull(GenericListContext::fromReferer(url('/movies')));
        $this->assertNull(GenericListContext::fromReferer(null));
    }

    /** @param list<int> $ids */
    private function rowQueries(array $ids): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = app(GenericReleaseRows::class)->load($ids, false);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertCount(count($ids), $rows);

        return $count;
    }

    private function remembered(User $user, string $root): mixed
    {
        return User::query()->findOrFail($user->id)->releaseViewPreferences($root)['filters'] ?? null;
    }

    /** @return list<string> the listed releases' names, A to Z */
    private function listedNames(TestResponse $response): array
    {
        return DB::table('releases')->whereIn('id', $this->listedIds($response))->orderBy('name')->pluck('name')->all();
    }

    /** @return list<int> */
    private function listedIds(TestResponse $response): array
    {
        preg_match_all('/data-select value="([0-9a-f]{32})"/', (string) $response->getContent(), $matches);
        $ids = DB::table('releases')->whereIn('guid', $matches[1])->pluck('id', 'guid');

        return array_map(static fn (string $guid): int => (int) $ids[$guid], $matches[1]);
    }

    /** @return list<string> every row's markup */
    private function rows(TestResponse $response): array
    {
        $rows = explode('<tr data-release-row', (string) $response->getContent());
        array_shift($rows);

        return array_map(static fn (string $row): string => strstr($row, '</tr>', true) ?: $row, $rows);
    }

    private function rowOf(TestResponse $response, string $name): string
    {
        foreach ($this->rows($response) as $row) {
            if (str_contains($row, 'title="'.e($name).'"')) {
                return $row;
            }
        }
        $this->fail('No row for '.$name);
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

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, 'Missing '.$from);
        $start += strlen($from);
        $end = strpos($html, $to, $start);
        $this->assertNotFalse($end, 'Missing '.$to);

        return substr($html, $start, $end - $start);
    }

    /** @param array<string, mixed> $attributes */
    private function generic(string $name, array $attributes = []): int
    {
        return $this->release($name, ['passwordstatus' => 0, 'resolution' => 0, 'source' => 0, 'imdbid' => null, 'movieinfo_id' => null, 'videos_id' => 0,
            'tv_episodes_id' => 0, 'completion' => 100, 'nfostatus' => 0, 'haspreview' => 0, 'jpgstatus' => 0, 'videostatus' => 0, 'categories_id' => self::MOVIE_SD, ...$attributes]);
    }

    private ?int $lastUserId = null;

    /** A page as a user; another user than the last one starts a fresh session, as a browser would. */
    private function page(string $uri, ?User $user = null): TestResponse
    {
        $this->resetGlobalComposerState();
        $user ??= $this->user ??= $this->browserUser();
        if ($this->lastUserId !== null && $this->lastUserId !== $user->id) {
            $this->flushSession();
        }
        $this->lastUserId = $user->id;

        return $this->actingAs($user)->get($uri);
    }
}
