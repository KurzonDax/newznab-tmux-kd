<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\BookPcReleaseFilters;
use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\User;
use App\Services\Releases\BookReleaseList;
use App\Services\Releases\ReleaseBrowseService;
use App\Services\Releases\ShelfReleaseRows;
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
 * The Book and PC releases lists, GET /books and GET /pc (issue #932;
 * docs/proposals/books-console-pc-redesign/SPEC.md 1, 4 and 5, DATA-CONTRACT.md 4.1 and 4.5 and
 * the list checks of prototype/check.mjs on books.html and pc.html).
 */
final class ShelfReleasesPageTest extends TestCase
{
    use AssertsFollowWording;
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    private const MAGAZINES = 7010;

    private const EBOOK = 7020;

    private const COMICS = 7030;

    private const TECHNICAL = 7040;

    private const FOREIGN = 7060;

    private const BOOKS_OTHER = 7999;

    private const ZERO_DAY = 4010;

    private const ISO = 4020;

    private const PC_GAMES = 4050;

    private const PC_OTHER = 4999;

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
            'tv_episodes_id', 'musicinfo_id', 'consoleinfo_id', 'gamesinfo_id', 'bookinfo_id', 'anidbid', 'resolution', 'source']);
        foreach (['usenet_groups', 'users_releases', 'user_series', 'user_movies', 'videos', 'movieinfo', 'release_audio_tags', 'release_video_clips',
            'languages', 'release_audio_languages'] as $table) {
            $tables->create($table);
        }
        DB::table('root_categories')->insert([['id' => 7000, 'title' => 'Books', 'status' => 1], ['id' => 4000, 'title' => 'PC', 'status' => 1],
            ['id' => 6000, 'title' => 'XXX', 'status' => 1]]);
        foreach ([self::BOOKS_OTHER => 'Other', self::FOREIGN => 'Foreign', self::TECHNICAL => 'Technical', self::COMICS => 'Comics', self::EBOOK => 'Ebook',
            self::MAGAZINES => 'Magazines'] as $id => $title) {
            DB::table('categories')->insert(['id' => $id, 'title' => $title, 'root_categories_id' => 7000, 'status' => 1]);
        }
        foreach ([self::PC_OTHER => 'Phone-Other', self::PC_GAMES => 'Games', self::ISO => 'ISO', self::ZERO_DAY => '0day'] as $id => $title) {
            DB::table('categories')->insert(['id' => $id, 'title' => $title, 'root_categories_id' => 4000, 'status' => 1]);
        }
        DB::table('categories')->insert(['id' => 6040, 'title' => 'x264', 'root_categories_id' => 6000, 'status' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_the_book_list_shows_the_books_band_only_newest_posted_first_with_its_header(): void
    {
        $this->book('Book.Newer', ['postdate' => '2026-09-25 10:00:00']);
        $this->book('Book.Older', ['postdate' => '2026-09-20 10:00:00']);
        $this->book('Book.Other.Upload', ['postdate' => '2026-09-24 10:00:00', 'categories_id' => self::BOOKS_OTHER]);
        $this->pc('A.PC.Program', ['postdate' => '2026-09-25 11:00:00']);
        $this->release('Adult.Scene', ['categories_id' => 6040, 'postdate' => '2026-09-25 11:30:00']);

        $response = $this->page('/books')->assertOk()
            ->assertSee('<h1 data-part="page title">Book releases</h1>', false)
            ->assertSee('<title>Book releases', false)
            ->assertSee('data-preference-root="books"', false)
            ->assertSeeInOrder(['Book.Newer', 'Book.Other.Upload', 'Book.Older'])
            ->assertDontSee('A.PC.Program')->assertDontSee('Adult.Scene')
            ->assertSee('Showing 1–3 of 3 releases')->assertSee('2 hr ago')->assertSee('Sep 20, 2026')
            ->assertDontSee('style="', false);
        $html = (string) $response->getContent();
        $this->assertMatchesRegularExpression('/<h1 data-part="page title">Book releases<\/h1>\s*(<!--.*?-->\s*)?<div class="tv-search tv-name-search">\s*<label>\s*<i class="fas fa-magnifying-glass" aria-hidden="true"><\/i>\s*'
            .'<input type="text" x-ref="nameSearch" value="" placeholder="Search release names" autocomplete="off" aria-label="Search release names"/s', $html);
        $response->assertSeeInOrder(['tv-name-search', '<span class="tv-grow"></span>', 'data-part="sort dropdown"'], false);
        $this->assertNoWatchWording($html, 'The Book releases list');
        $this->assertSame('/books', route('books.releases', [], false));
    }

    public function test_the_pc_list_shows_the_pc_band_only_newest_posted_first_with_its_header(): void
    {
        $this->pc('PC.Newer', ['postdate' => '2026-09-25 10:00:00']);
        $this->pc('PC.Older', ['postdate' => '2026-09-20 10:00:00', 'categories_id' => self::PC_OTHER]);
        $this->book('A.Book');

        $response = $this->page('/pc')->assertOk()
            ->assertSee('<h1 data-part="page title">PC releases</h1>', false)
            ->assertSee('<title>PC releases', false)
            ->assertSee('data-preference-root="games"', false)
            ->assertSeeInOrder(['PC.Newer', 'PC.Older'])
            ->assertDontSee('A.Book')
            ->assertSee('Showing 1–2 of 2 releases');
        $this->assertNoWatchWording((string) $response->getContent(), 'The PC releases list');
        $this->assertSame('/pc', route('pc.releases', [], false));
    }

    public function test_every_page_says_which_rows_it_shows_and_the_mirrored_half_keeps_the_order(): void
    {
        $expected = [];
        foreach (range(1, 275) as $index) {
            $postdate = Carbon::parse('2026-01-01 00:00:00')->addHours($index % 3 === 0 ? $index - 1 : $index)->toDateTimeString();
            $id = $this->book('Paged '.$index, ['postdate' => $postdate]);
            $expected[] = [$postdate, $id];
        }
        $this->pc('A.PC.Program');
        usort($expected, static fn (array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
        $order = array_column($expected, 1);

        // page 2 is read from the front, page 4 lies past the middle and is read mirrored, page 6 is the last
        foreach ([1 => [1, 50], 2 => [51, 100], 4 => [151, 200], 6 => [251, 275]] as $page => [$from, $to]) {
            $response = $this->page('/books'.($page > 1 ? '?page='.$page : ''))->assertOk()
                ->assertSee('Showing '.$from.'–'.$to.' of 275 releases')->assertSee('Page '.$page.' of 6');
            $this->assertSame(array_slice($order, $from - 1, $to - $from + 1), $this->listedIds($response), 'page '.$page);
        }
        $list = app(BookReleaseList::class);
        $filters = new BookPcReleaseFilters(page: 4);
        $this->assertSame(array_slice($order, 150, 50), $list->pageIds($filters, [], 275), 'the mirrored read is the unmirrored order');
        $this->page('/books?page=9')->assertRedirect(route('books.releases', ['page' => 6]));
        $this->page('/pc?page=3')->assertRedirect(route('pc.releases'));
        foreach (['/books?page=0', '/books?page=two'] as $uri) {
            $first = $this->page($uri)->assertOk()->assertSee('Showing 1–50 of 275 releases');
            $this->assertSame(array_slice($order, 0, 50), $this->listedIds($first), $uri);
        }
        $this->page('/books')->assertSee('aria-label="Page 6"', false)->assertSee('Go to page');
    }

    public function test_category_and_completion_combine_and_no_other_filter_exists(): void
    {
        $this->book('Comic complete', ['categories_id' => self::COMICS, 'resolution' => 1, 'source' => 1]);
        $this->book('Comic 96', ['categories_id' => self::COMICS, 'completion' => 96]);
        $this->book('Ebook 90', ['completion' => 90]);
        $this->book('Magazine complete', ['categories_id' => self::MAGAZINES]);

        $this->assertListed('/books?category[]='.self::COMICS, ['Comic 96', 'Comic complete']);
        $this->assertListed('/books?category[]='.self::COMICS.'&category[]='.self::EBOOK, ['Comic 96', 'Comic complete', 'Ebook 90']);
        $this->assertListed('/books?completion=100', ['Comic complete', 'Magazine complete']);
        $this->assertListed('/books?completion=95', ['Comic 96', 'Comic complete', 'Magazine complete']);
        $this->assertListed('/books?completion=95&category[]='.self::COMICS.'&category[]='.self::EBOOK, ['Comic 96', 'Comic complete']);
        // no Resolution, Source or Audio filter: their values in the URL change nothing
        $this->assertListed('/books?_fragment=list&resolution[]=4k&source[]=bluray&audio[]=unknown', ['Comic 96', 'Comic complete', 'Ebook 90', 'Magazine complete']);
        $filters = $this->page('/books?_fragment=list&resolution[]=4k&source[]=bluray&audio[]=1')->viewData('filters');
        $this->assertSame([[], [], []], [$filters->resolutions, $filters->sources, $filters->audio]);

        foreach (['/books', '/pc'] as $uri) {
            $response = $this->page($uri)->assertOk()->assertDontSee('data-name="resolution"', false)->assertDontSee('data-name="audio"', false)
                ->assertDontSee('data-name="source"', false)->assertDontSee('assword', false);
            preg_match_all('/class="checkbox-menu is-cell[^"]*"[^>]*data-name="([a-z]+)"/', (string) $response->getContent(), $cells);
            $this->assertSame(['category', 'completion'], $cells[1], $uri);
            $this->assertSame(['Category: any', 'Completion: any'], array_map(fn (string $name): string => $this->cellText($response, $name), $cells[1]));
            $response->assertSeeInOrder(['<div class="filter-row tv-bar-list is-shelf">', '<div class="filter-bar is-release" role="group" aria-label="The release">',
                'data-name="category"', 'data-name="completion"', 'x-ref="list" class="tv-list-end"'], false);
        }
    }

    public function test_the_category_menu_lists_the_sub_categories_with_releases_in_id_order_and_keeps_a_chosen_empty_one(): void
    {
        foreach ([self::BOOKS_OTHER, self::COMICS, self::MAGAZINES, self::FOREIGN] as $category) {
            $this->book('In '.$category, ['categories_id' => $category]);
        }
        foreach ([self::PC_OTHER, self::PC_GAMES, self::ZERO_DAY] as $category) {
            $this->pc('In '.$category, ['categories_id' => $category]);
        }
        $user = $this->user = $this->browserUser();

        $this->assertSame([self::MAGAZINES => 'Magazines', self::COMICS => 'Comics', self::FOREIGN => 'Foreign', self::BOOKS_OTHER => 'Other'],
            $this->page('/books')->assertOk()->viewData('categoryMenu'));
        $this->assertSame([self::ZERO_DAY => '0day', self::PC_GAMES => 'Games', self::PC_OTHER => 'Phone-Other'], $this->page('/pc')->assertOk()->viewData('categoryMenu'));

        $chosen = $this->page('/books?category[]='.self::TECHNICAL)->assertOk()->assertSee('Showing 0 releases')->assertSee('No releases match Technical.')
            ->assertDontSee('<table', false);
        $this->assertSame([self::MAGAZINES => 'Magazines', self::COMICS => 'Comics', self::TECHNICAL => 'Technical', self::FOREIGN => 'Foreign', self::BOOKS_OTHER => 'Other'],
            $chosen->viewData('categoryMenu'));
        $this->assertSame('Category: Technical', $this->cellText($chosen, 'category'));
        $this->assertMatchesRegularExpression('/data-value="'.self::TECHNICAL.'"[^>]*aria-checked="true"/', (string) $chosen->getContent());

        // a hidden sub-category is dropped
        DB::table('user_excluded_categories')->insert(['users_id' => $user->id, 'categories_id' => self::EBOOK]);
        Cache::flush();
        $this->page('/books?category[]='.self::EBOOK, User::query()->findOrFail($user->id))->assertOk()->assertSee('Showing 1–4 of 4 releases');
        $this->assertArrayNotHasKey(self::EBOOK, $this->page('/books?category[]='.self::EBOOK, User::query()->findOrFail($user->id))->viewData('categoryMenu'));
    }

    public function test_exclude_other_lists_every_category_but_other_and_is_remembered_as_a_mode(): void
    {
        $this->book('In Ebook');
        $user = $this->user = $this->browserUser();
        $this->page('/books')->assertOk()->assertDontSee('data-exclude-other', false);
        $this->book('In Comics', ['categories_id' => self::COMICS]);
        $this->book('In Other', ['categories_id' => self::BOOKS_OTHER]);
        $this->pc('In 0day');
        $this->pc('In Phone-Other', ['categories_id' => self::PC_OTHER]);
        Cache::flush();

        $this->page('/books')->assertOk()->assertSeeInOrder(['data-any', 'Any category', 'data-exclude-other', 'Exclude Other', 'checkbox-menu-rule', 'data-value="'.self::EBOOK.'"'], false);
        $set = $this->page('/books?category=exclude-other')->assertOk()->assertSee('title="Category: Exclude Other"', false);
        $this->assertSame(['In Comics', 'In Ebook'], $this->listedNames($set));
        $this->assertSame(['category' => 'exclude-other'], $this->remembered($user, 'books'));
        $this->page('/books?category=exclude-other&completion=100&q=zzz')->assertSee('No releases match excluding Other · 100% complete · names containing “zzz”.', false);

        $pc = $this->page('/pc?category=exclude-other')->assertOk()->assertSee('data-exclude-other', false);
        $this->assertSame(['In 0day'], $this->listedNames($pc));
        $this->assertSame(['category' => 'exclude-other'], $this->remembered($user, 'games'));
    }

    public function test_the_name_search_matches_the_display_name_or_else_the_search_name_with_wildcards_literal(): void
    {
        $this->pc('raw.name.one', ['display_name' => 'Shown Suite One', 'searchname' => 'hidden words']);
        $this->pc('raw.name.two', ['display_name' => '  ', 'searchname' => 'Fallback Suite Two']);
        $this->pc('raw.name.three', ['display_name' => '100%_Real!Deal', 'searchname' => 'x']);
        $this->pc('raw.name.four', ['display_name' => '100 Real Deal', 'searchname' => 'x', 'categories_id' => self::ISO]);
        $this->book('A Suite of books');

        $this->assertListed('/pc?q=suite', ['raw.name.one', 'raw.name.two']);
        $this->assertListed('/pc?q=hidden', []);
        $this->assertListed('/pc?q=%25_Real', ['raw.name.three']);
        $this->assertListed('/pc?q=l!D', ['raw.name.three']);
        $this->assertListed('/pc?q=100', ['raw.name.four', 'raw.name.three']);
        $this->page('/pc?q=100')->assertSee('Showing 1–2 of 2 releases');
        $this->assertListed('/pc?q=100&category[]='.self::ISO, ['raw.name.four']);
        $this->page('/pc?_fragment=list&q=100')->assertSee('Showing 1–2 of 2 releases');

        $set = $this->page('/pc?q=Suite')->assertOk()->assertSee('value="Suite"', false)
            ->assertSee('class="pager-line-clear" data-clear-all aria-hidden="false">Clear all</a>', false);
        $this->assertTrue($set->viewData('filters')->any());
        $this->page('/pc?q=zzqqxx&category[]='.self::ZERO_DAY)->assertSee('No releases match 0day · names containing “zzqqxx”.', false)
            ->assertDontSee('<table', false);
        $this->page('/pc?clear=1&q=Suite')->assertRedirect(route('pc.releases'));
    }

    public function test_the_name_search_is_carried_by_the_pager_and_never_remembered(): void
    {
        foreach (range(1, 60) as $index) {
            $this->book('Novel '.$index, ['postdate' => Carbon::parse('2026-01-01')->addHours($index)->toDateTimeString(), 'completion' => 99]);
        }
        $this->book('Unrelated', ['completion' => 99]);
        $user = $this->user = $this->browserUser();
        $this->page('/books?_fragment=list&completion=95')->assertOk();
        $this->assertSame(['completion' => 95], $this->remembered($user, 'books'));

        $this->page('/books?q=Novel')->assertRedirect(route('books.releases', ['completion' => 95, 'q' => 'Novel']));
        $opened = $this->opened('/books?q=Novel')->assertOk()->assertSee('Showing 1–50 of 60 releases')
            ->assertSee('href="'.e(route('books.releases', ['completion' => 95, 'q' => 'Novel', 'page' => 2])).'"', false);
        $this->assertSame('Novel', $opened->viewData('filters')->search);
        $this->page('/books?_fragment=list&completion=95&q=Novel')->assertOk();
        $this->assertSame(['completion' => 95], $this->remembered($user, 'books'));

        $second = $this->page('/books?completion=95&q=Novel&page=2')->assertOk()->assertSee('Showing 51–60 of 60 releases');
        $this->assertNotContains('Unrelated', $this->listedNames($second));
        $this->assertSame(['completion' => 95, 'q' => 'Novel', 'page' => 2], $second->viewData('filters')->query());
        $this->page('/books?clear=1')->assertRedirect(route('books.releases'));
        $this->assertSame([], $this->remembered($user, 'books') ?? []);
        $this->page('/books')->assertOk();
    }

    public function test_each_row_shows_its_name_category_size_date_and_buttons_and_no_picture_or_files(): void
    {
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.e-book']);
        $this->book('Comic.With.Everything', ['categories_id' => self::COMICS, 'haspreview' => 1, 'jpgstatus' => 1, 'videostatus' => 1, 'resolution' => 2,
            'nfostatus' => 1, 'completion' => 97, 'grabs' => 7, 'totalpart' => 12, 'groups_id' => 1, 'fromname' => 'Uploader <up@example.invalid>', 'size' => 2.5 * 1073741824]);
        $this->pc('Zero.Day.Tool', ['haspreview' => 1, 'jpgstatus' => 1]);

        $response = $this->page('/books')->assertOk()
            ->assertSee('<table class="tv-feed is-shelf"', false)
            ->assertSee('<colgroup><col class="tv-col-select"><col><col class="tv-col-category"><col class="tv-col-size"><col class="tv-col-date"><col class="tv-col-actions"></colgroup>', false)
            ->assertSeeInOrder(['<th>Release</th>', '<th class="tv-category">Category</th>', '<th class="tv-num">Size</th>', '<th class="tv-num">Posted</th>'], false)
            ->assertDontSee('<th class="tv-num">Files</th>', false)->assertDontSee('<th class="tv-num">Grabs</th>', false)->assertDontSee('Resolution');
        $this->assertSame(6, substr_count(strstr((string) $response->getContent(), '</thead>', true), '<th') - 1, 'six headers over six columns');
        $row = $this->rowOf($response, 'Comic.With.Everything');
        $this->assertSame(6, substr_count($row, '<td'));
        $this->assertStringContainsString('<a class="tv-release-name" href="'.e(route('details', md5('Comic.With.Everything'))).'" title="Comic.With.Everything"', $row);
        $this->assertStringContainsString('<td class="tv-category" title="Books &gt; Comics">Comics</td>', $row);
        $this->assertStringContainsString('<td class="tv-num tv-size">2.50 GB</td>', $row);
        $this->assertMatchesRegularExpression('/<td class="tv-num tv-date" title="Posted [^"]+ · Added [^"]+">Sep 12, 2026<\/td>/', $row);
        $this->assertSame(['download', 'copy', 'cart'], $this->actions($row));
        $this->assertMatchesRegularExpression('/97% complete.*nfo-badge.*<span class="tv-origin-pair">/s', $row);
        foreach (['preview-badge', 'sample-badge', 'clip-badge', '<img', 'data-picture', 'No picture', 'resolution-chip', '1080p', 'filelist-badge', 'data-watch-picker'] as $absent) {
            $this->assertStringNotContainsString($absent, $row, $absent);
        }
        $this->assertStringContainsString('<td class="tv-category" title="PC &gt; 0day">0day</td>', $this->rowOf($this->page('/pc'), 'Zero.Day.Tool'));
        $this->assertStringNotContainsString('tv-chips', $this->rowOf($this->page('/pc'), 'Zero.Day.Tool'));
    }

    public function test_a_page_reads_its_sub_category_titles_in_one_query(): void
    {
        $this->book('Only release', ['categories_id' => self::COMICS]);
        $this->assertSame(1, $this->categoryTitleQueries('/books'));
        foreach (range(1, 60) as $index) {
            $this->book('Release '.$index, ['categories_id' => [self::COMICS, self::EBOOK, self::MAGAZINES, self::BOOKS_OTHER][$index % 4]]);
        }
        Cache::flush();
        $this->assertSame(1, $this->categoryTitleQueries('/books'));
        $this->assertSame(50, count($this->listedIds($this->page('/books'))));
    }

    public function test_the_file_count_is_shown_only_when_one_is_stored(): void
    {
        $without = $this->book('No.Count', ['totalpart' => 0]);
        $with = $this->book('With.Count', ['totalpart' => 37]);
        [$withRow, $withoutRow] = app(ShelfReleaseRows::class)->load([$with, $without], false);

        $this->assertFalse($withoutRow->hasFileCount());
        $this->assertSame('—', $withoutRow->filesShown());
        $this->assertTrue($withRow->hasFileCount());
        $this->assertSame('37', $withRow->filesShown());
        $this->assertSame([null, null], [$withRow->preview, $withRow->sample]);
        $this->assertSame(['Ebook', 'Books > Ebook'], [$withRow->category, $withRow->categoryPath]);
    }

    public function test_the_empty_results_name_what_is_set_or_say_the_band_has_no_release(): void
    {
        $this->page('/books')->assertOk()->assertSee('There are no book releases yet.')->assertDontSee('<table', false);
        $this->page('/pc')->assertOk()->assertSee('There are no PC releases yet.')->assertDontSee('<table', false);
        $this->book('A comic', ['categories_id' => self::COMICS, 'completion' => 90]);
        $this->book('An ebook', ['completion' => 90]);
        Cache::flush();
        $this->page('/books?category[]='.self::COMICS.'&category[]='.self::EBOOK.'&completion=100&q=%20Lost%20')
            ->assertSee('No releases match Ebook or Comics · 100% complete · names containing “Lost”.', false);
    }

    public function test_the_sort_and_the_filters_are_remembered_under_books_and_games(): void
    {
        $this->book('Posted early added late', ['postdate' => '2026-09-01 00:00:00', 'adddate' => '2026-09-24 00:00:00']);
        $this->book('Posted late added early', ['postdate' => '2026-09-10 00:00:00', 'adddate' => '2026-09-11 00:00:00']);
        $this->pc('PC posted early added late', ['postdate' => '2026-09-01 00:00:00', 'adddate' => '2026-09-24 00:00:00']);
        $this->pc('PC posted late added early', ['postdate' => '2026-09-10 00:00:00', 'adddate' => '2026-09-11 00:00:00']);
        $user = $this->user = $this->browserUser();

        $this->page('/books')->assertSeeInOrder(['Posted late added early', 'Posted early added late'])->assertSee('<th class="tv-num">Posted</th>', false);
        $this->postJson('/profile/update-view', ['root' => 'books', 'sort' => 'newest'])->assertOk();
        $this->page('/books', User::query()->findOrFail($user->id))->assertSeeInOrder(['Posted early added late', 'Posted late added early'])
            ->assertSee('<th class="tv-num">Added</th>', false)->assertSee('<option value="newest" selected', false);
        $this->assertSame('newest', User::query()->findOrFail($user->id)->releaseViewPreferences('books')['sort']);
        $this->page('/pc', User::query()->findOrFail($user->id))->assertSeeInOrder(['PC posted late added early', 'PC posted early added late']);
        $this->postJson('/profile/update-view', ['root' => 'games', 'sort' => 'posted_oldest'])->assertOk();
        $this->page('/pc', User::query()->findOrFail($user->id))->assertSeeInOrder(['PC posted early added late', 'PC posted late added early']);
        $this->assertSame('posted_oldest', User::query()->findOrFail($user->id)->releaseViewPreferences('games')['sort']);
        $this->postJson('/profile/update-view', ['root' => 'books', 'sort' => 'grabs'])->assertUnprocessable();
        $this->postJson('/profile/update-view', ['root' => 'pc', 'sort' => 'newest'])->assertUnprocessable();

        $this->page('/books?_fragment=list&category[]='.self::EBOOK.'&completion=95', User::query()->findOrFail($user->id))->assertOk();
        $this->assertSame(['category' => [self::EBOOK], 'completion' => 95], $this->remembered($user, 'books'));
        $this->page('/pc?_fragment=list&completion=100', User::query()->findOrFail($user->id))->assertOk();
        $this->assertSame(['completion' => 100], $this->remembered($user, 'games'));
        $this->assertNull(User::query()->findOrFail($user->id)->releaseViewPreferences('pc')['filters'] ?? null);
        $this->page('/books', User::query()->findOrFail($user->id))->assertRedirect(route('books.releases', ['category' => [self::EBOOK], 'completion' => 95]));
        $this->page('/pc', User::query()->findOrFail($user->id))->assertRedirect(route('pc.releases', ['completion' => 100]));
        $this->page('/pc?clear=1', User::query()->findOrFail($user->id))->assertRedirect(route('pc.releases'));
        $this->page('/pc', User::query()->findOrFail($user->id))->assertOk();
    }

    public function test_the_book_list_needs_the_books_permission(): void
    {
        $user = $this->browserUser();
        $user->revokePermissionTo('view books');
        $this->page('/books', $user)->assertForbidden()->assertSee('Books is hidden in your account preferences.');
        $this->page('/pc', $user)->assertOk();
    }

    public function test_the_pc_list_needs_the_pc_permission(): void
    {
        $user = $this->browserUser();
        $user->revokePermissionTo('view pc');
        $this->page('/pc', $user)->assertForbidden()->assertSee('PC is hidden in your account preferences.');
        $this->page('/books', $user)->assertOk();
    }

    public function test_the_header_menus_send_books_and_pc_to_the_new_lists_and_mark_them_current(): void
    {
        $this->book('A book');
        $this->pc('A program');
        foreach (['/books' => ['books.releases', 'books', [self::MAGAZINES, self::BOOKS_OTHER]], '/pc' => ['pc.releases', 'games', [self::ZERO_DAY, self::PC_OTHER]]] as $uri => [$route, $root, $categories]) {
            $response = $this->page($uri)->assertOk()
                ->assertSee('href="'.route('books.releases').'" class="public-menu-root">All Books</a>', false)
                ->assertSee('href="'.route('pc.releases').'" class="public-menu-root">All PC</a>', false)
                ->assertDontSee('href="'.url('/browse/books').'"', false)->assertDontSee('href="'.url('/browse/games').'"', false);
            foreach ($categories as $category) {
                $response->assertSee('href="'.e(route($route, ['category' => [$category]])).'"', false);
            }
            $this->assertMatchesRegularExpression('/<button[^>]*aria-controls="nav-menu-'.$root.'"\s+aria-current="true"/', (string) $response->getContent(), $uri);
            $this->assertSame(1, substr_count((string) $response->getContent(), 'aria-current="true"'), $uri);
        }
    }

    public function test_the_list_fragment_is_the_list_alone_and_the_counts_are_cached_per_list(): void
    {
        $this->book('First release');
        $this->page('/books')->assertSee('Showing 1–1 of 1 release');
        $this->book('Second release', ['postdate' => '2026-09-01 00:00:00']);
        $this->page('/books')->assertSee('Showing 1–1 of 1 release');
        ReleaseBrowseService::bumpCacheVersion();
        $this->page('/books')->assertSee('Showing 1–2 of 2 releases');
        $this->pc('A program');
        $this->page('/pc')->assertSee('Showing 1–1 of 1 release');

        $fragment = $this->page('/books?_fragment=list&q=release')->assertOk()->assertSee('Showing 1–2 of 2 releases')->getContent();
        $this->assertStringNotContainsString('<html', $fragment);
        $this->assertStringNotContainsString('tv-filters', $fragment);
        $this->assertStringContainsString('<table class="tv-feed is-shelf"', $fragment);
        $this->assertNotSame((new BookPcReleaseFilters(search: 'a'))->countKey(), (new BookPcReleaseFilters(search: 'b'))->countKey());
        $this->assertNotSame((new BookPcReleaseFilters)->countKey(), (new BookPcReleaseFilters(categories: [self::EBOOK], excludeOther: true))->countKey());
        $this->assertTrue(Cache::has('book_releases_value_counts'));
        $this->assertTrue(Cache::has('pc_releases_value_counts'));
        $this->assertFalse(Cache::has('adult_releases_value_counts'));
    }

    public function test_without_a_secondary_provider_the_chip_shows_no_pending_suffix(): void
    {
        $this->book('Above target', ['completion' => 99]);
        $this->book('No verdict', ['completion' => 80]);

        $response = $this->page('/books')->assertOk();
        $above = $this->rowOf($response, 'Above target');
        $this->assertMatchesRegularExpression('/>\s*99% complete\s*</', $above);
        $this->assertStringNotContainsString('late headers pending', $above);
        $noVerdict = $this->rowOf($response, 'No verdict');
        $this->assertMatchesRegularExpression('/>\s*80% complete\s*</', $noVerdict);
        $this->assertStringNotContainsString('late headers pending', $noVerdict);
    }

    private function categoryTitleQueries(string $uri): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->page($uri)->assertOk();
        $count = collect(DB::getQueryLog())->filter(static fn (array $query): bool => preg_match('/^select "title", "id" from "categories" where "id" in/', $query['query']) === 1)->count();
        DB::disableQueryLog();

        return $count;
    }

    /** The response after the one redirect a bare open answers with while filters are remembered (#881). */
    private function opened(string $uri): TestResponse
    {
        $response = $this->page($uri);

        return $response->isRedirect() ? $this->page((string) $response->headers->get('Location')) : $response;
    }

    private function remembered(User $user, string $root): mixed
    {
        return User::query()->findOrFail($user->id)->releaseViewPreferences($root)['filters'] ?? null;
    }

    /** @param list<string> $names */
    private function assertListed(string $uri, array $names, ?User $user = null): void
    {
        $response = $this->page($uri, $user)->assertOk();
        $this->assertSame($names, $this->listedNames($response), $uri);
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

    private function rowOf(TestResponse $response, string $name): string
    {
        foreach (explode('<tr data-release-row', (string) $response->getContent()) as $row) {
            if (str_contains($row, 'title="'.e($name).'"')) {
                return strstr($row, '</tr>', true) ?: $row;
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

    /** A filter cell's text as check.mjs reads it (textContent): "Category: 2 chosen". */
    private function cellText(TestResponse $response, string $name): string
    {
        $this->assertMatchesRegularExpression('/data-name="'.$name.'".*?<span class="checkbox-menu-label">(.*?)<\/span><i /s', (string) $response->getContent());
        preg_match('/data-name="'.$name.'".*?<span class="checkbox-menu-label">(.*?)<\/span><i /s', (string) $response->getContent(), $match);

        return html_entity_decode(strip_tags($match[1]), ENT_QUOTES);
    }

    /** @param array<string, mixed> $attributes */
    private function book(string $name, array $attributes = []): int
    {
        return $this->shelfRelease($name, ['categories_id' => self::EBOOK, ...$attributes]);
    }

    /** @param array<string, mixed> $attributes */
    private function pc(string $name, array $attributes = []): int
    {
        return $this->shelfRelease($name, ['categories_id' => self::ZERO_DAY, ...$attributes]);
    }

    /** @param array<string, mixed> $attributes */
    private function shelfRelease(string $name, array $attributes): int
    {
        return $this->release($name, ['passwordstatus' => 0, 'resolution' => 0, 'source' => 0, 'imdbid' => null, 'movieinfo_id' => null, 'videos_id' => 0,
            'tv_episodes_id' => 0, 'completion' => 100, 'nfostatus' => 0, 'haspreview' => 0, 'jpgstatus' => 0, 'videostatus' => 0, ...$attributes]);
    }

    private function page(string $uri, ?User $user = null): TestResponse
    {
        $this->resetGlobalComposerState();

        return $this->actingAs($user ?? $this->user ??= $this->browserUser())->get($uri);
    }
}
