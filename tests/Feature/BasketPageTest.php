<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\AssertsFollowWording;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * The basket (issue #1051 section 1): the generic release list at /basket, holding the user's
 * basket newest posted first, with the heading row's two actions in place of the name search,
 * the filter bar and the sort menu.
 */
final class BasketPageTest extends TestCase
{
    use AssertsFollowWording;
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    private const MISC = 10;

    private const MOVIE_SD = 2030;

    private const BOOKS_EBOOK = 7020;

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
            'tv_episodes_id', 'musicinfo_id', 'consoleinfo_id', 'gamesinfo_id', 'bookinfo_id', 'anidbid', 'resolution', 'source', 'predb_id', 'is_imported']);
        foreach (['usenet_groups', 'users_releases', 'user_series', 'user_movies', 'videos', 'tv_episodes', 'movieinfo', 'consoleinfo', 'release_audio_tags', 'release_video_clips',
            'languages', 'release_audio_languages', 'release_audio_evidence', 'release_music_identifications', 'music_cover_art_lookups'] as $table) {
            $tables->create($table);
        }
        foreach (['2026_02_01_000000_create_release_reports_table', '2026_06_08_000000_add_response_fields_to_release_reports_table'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
        DB::table('root_categories')->where('id', 1)->update(['title' => 'Other']);
        DB::table('root_categories')->insert([['id' => 2000, 'title' => 'Movies', 'status' => 1], ['id' => 7000, 'title' => 'Books', 'status' => 1]]);
        foreach ([self::MISC => [1, 'Misc'], self::MOVIE_SD => [2000, 'SD'], self::BOOKS_EBOOK => [7000, 'Ebook']] as $id => [$root, $title]) {
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

    public function test_the_basket_is_the_generic_list_headed_basket_without_the_search_field_the_filter_bar_or_the_sort_menu(): void
    {
        $this->inBasket('Movie.Newer', ['postdate' => '2026-09-25 10:00:00', 'adddate' => '2026-09-01 10:00:00']);
        $this->inBasket('Misc.Older', ['postdate' => '2026-09-20 10:00:00', 'adddate' => '2026-09-25 11:00:00', 'categories_id' => self::MISC]);
        $this->inBasket('Book.Between', ['postdate' => '2026-09-24 10:00:00', 'adddate' => '2026-09-10 10:00:00', 'categories_id' => self::BOOKS_EBOOK]);
        $this->basketRelease('Not.In.The.Basket', ['postdate' => '2026-09-25 11:30:00']);
        DB::table('users_releases')->insert(['users_id' => 999, 'releases_id' => $this->basketRelease('Someone.Elses', ['postdate' => '2026-09-25 11:00:00'])]);

        $response = $this->page('/basket')->assertOk()
            ->assertSee('<h1 data-part="page title">Basket</h1>', false)
            ->assertSee('<title>Basket', false)
            ->assertSeeInOrder(['Movie.Newer', 'Book.Between', 'Misc.Older'])
            ->assertDontSee('Not.In.The.Basket')->assertDontSee('Someone.Elses')
            ->assertSee('Showing 1–3 of 3 releases')->assertSee('Page 1 of 1')
            ->assertSee('<table class="tv-feed is-shelf is-generic"', false)
            ->assertSee('<col class="tv-col-art">', false)
            ->assertDontSee('tv-list-crumbs', false)->assertDontSee('tv-name-search', false)->assertDontSee('data-part="sort dropdown"', false)
            ->assertDontSee('filter-row', false)->assertDontSee('class="filter-bar', false)->assertDontSee('x-data="checkboxMenu"', false)->assertDontSee('data-clear-all', false)
            ->assertDontSee('data-preference="per"', false)
            ->assertDontSee('style="', false);
        $html = (string) $response->getContent();
        preg_match('/<thead>.*?<\/thead>/s', $html, $head);
        $this->assertSame(['Release', 'Category', 'Size', 'Posted', 'Actions'], array_values(array_filter(array_map('trim', explode("\n", strip_tags($head[0]))))),
            'the six headings: the select box, Release, Category, Size, Posted and the actions');
        $this->assertSame(3, substr_count($html, '<tr data-release-row'));
        preg_match('/<tbody>.*?<\/tbody>/s', $html, $body);
        $this->assertStringNotContainsString('Details', $body[0], 'no Details row button');
        $this->assertStringNotContainsString('Report', $body[0], 'no Report row button');
        $this->assertSame(3, substr_count($body[0], 'class="tv-action tv-action-slot"'), 'the 2 × 2: Download and Copy link over Cart and the empty Follow slot');
        // every row is in the basket: its cart button is pressed, between Copy link and the empty Follow slot of the 2 × 2
        $this->assertSame(3, substr_count($html, 'aria-pressed="true" title="In cart · click to remove" aria-label="Remove from cart"'));
        $this->assertSame(3, substr_count($html, 'data-part="row action: download"'));
        $this->assertSame(3, substr_count($html, 'data-copy-nzb='));
        $this->assertStringNotContainsString('aria-current="true"', $html, 'no header section is marked current');
        $this->assertNoWatchWording($html, 'The basket');
    }

    public function test_the_heading_row_ends_with_empty_basket_and_download_n_nzbs_while_the_basket_holds_anything(): void
    {
        // an empty basket: the pager line, then the one sentence; neither action, and the heading row keeps its class (its height)
        $empty = (string) $this->page('/basket')->assertOk()
            ->assertSeeInOrder(['<h1 data-part="page title">Basket</h1>', 'Showing 0 releases', 'Page 1 of 1', '<p class="tv-empty" data-empty>Your basket is empty.</p>'], false)
            ->assertDontSee('Empty basket')->assertDontSee('data-basket-action', false)->assertDontSee('data-download-count', false)
            ->assertDontSee('Use the basket button on any release row.')->assertDontSee('<table', false)
            ->getContent();
        $this->assertStringContainsString('<div class="tv-filters is-basket">', $empty);

        $viewer = $this->viewer();
        $id = $this->inBasket('First.Release');
        $html = (string) $this->page('/basket')->assertOk()->getContent();
        $row = (string) preg_replace('/>\s+</', '><', $this->between($html, '<div class="tv-filters is-basket">', '<div x-ref="list"'));
        $token = csrf_token();
        $this->assertStringContainsString('<h1 data-part="page title">Basket</h1><span class="tv-grow"></span>'
            .'<form method="POST" action="'.route('basket.empty').'" data-basket-action><input type="hidden" name="_token" value="'.$token.'" autocomplete="off">'
            .'<button type="submit" class="tv-feed-button">Empty basket</button></form>'
            .'<form method="POST" action="'.route('basket.download').'" data-basket-action><input type="hidden" name="_token" value="'.$token.'" autocomplete="off">'
            .'<button type="submit" class="tv-button tv-basket-button"><i class="fas fa-download" aria-hidden="true"></i><span>Download <span data-download-count>1</span> NZBs</span></button></form></div>', $row);

        // N is the basket's count under the basket's visibility rules, written with thousands separators
        $template = (array) DB::table('releases')->where('id', $id)->first();
        unset($template['id'], $template['category_band']);
        $releases = [];
        foreach (range(1, 1233) as $index) {
            $releases[] = [...$template, 'name' => 'Bulk '.$index, 'searchname' => 'Bulk '.$index, 'guid' => md5('Bulk '.$index)];
        }
        foreach (array_chunk($releases, 100) as $chunk) {
            DB::table('releases')->insert($chunk);
        }
        DB::table('users_releases')->insertUsing(['users_id', 'releases_id'], DB::table('releases')->selectRaw('?, id', [$viewer->id])->where('name', 'like', 'Bulk %'));
        $this->inBasket('Passworded.In.The.Basket', ['passwordstatus' => 1]);
        $this->page('/basket')->assertOk()->assertSee('<span>Download <span data-download-count>1,234</span> NZBs</span>', false)->assertSee('Showing 1–50 of 1,234 releases')->assertSee('Page 1 of 25');
    }

    public function test_the_basket_shows_fifty_a_page_and_reads_nothing_but_the_page_from_its_address(): void
    {
        $viewer = $this->viewer();
        $expected = [];
        foreach (range(1, 120) as $index) {
            $postdate = Carbon::parse('2026-01-01 00:00:00')->addHours($index % 3 === 0 ? $index - 1 : $index)->toDateTimeString();
            // the date the site added it runs the other way: the basket is ordered by the posted date
            $expected[] = [$postdate, $this->inBasket('Paged '.$index, ['postdate' => $postdate, 'adddate' => Carbon::parse('2026-06-01 00:00:00')->subHours($index)->toDateTimeString(),
                'completion' => $index % 2 === 0 ? 100 : 80, 'categories_id' => $index % 2 === 0 ? self::MISC : self::MOVIE_SD])];
        }
        usort($expected, static fn (array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
        $order = array_column($expected, 1);
        foreach ([1 => [1, 50], 2 => [51, 100], 3 => [101, 120]] as $page => [$from, $to]) {
            $response = $this->page('/basket'.($page > 1 ? '?page='.$page : ''))->assertOk()->assertSee('Showing '.$from.'–'.$to.' of 120 releases')->assertSee('Page '.$page.' of 3');
            $this->assertSame(array_slice($order, $from - 1, $to - $from + 1), $this->listedIds($response), 'page '.$page);
        }
        $this->page('/basket?page=9')->assertRedirect(route('basket', ['page' => 3]));
        $this->page('/basket')->assertSee('href="'.route('basket', ['page' => 2]).'" rel="next"', false)->assertSee('aria-label="Page 3"', false)->assertSee('Go to page');

        // the lists' filters, sorts, name search and the old per-page choice mean nothing here, in the address or remembered
        DB::table('users')->where('id', $viewer->id)->update(['view_prefs' => json_encode(['all' => ['sort' => 'title', 'filters' => ['completion' => '100', 'category' => [2000]]]])]);
        $before = DB::table('users')->where('id', $viewer->id)->value('view_prefs');
        $response = $this->page('/basket?per=24&q=nothing&completion=100&category[]=2000&sort=title&watching=1&minc=100&view=cards')->assertOk()
            ->assertSee('Showing 1–50 of 120 releases')->assertDontSee('data-preference="view"', false);
        $this->assertSame(array_slice($order, 0, 50), $this->listedIds($response));
        $this->assertSame($before, DB::table('users')->where('id', $viewer->id)->value('view_prefs'), 'the basket changes no remembered filter');
        $this->assertSame([], $response->viewData('filters')->query(1));
        $this->assertSame(['page' => 2], $response->viewData('filters')->query(2));
    }

    public function test_the_basket_keeps_todays_visibility_rules(): void
    {
        $viewer = $this->viewer();
        $this->inBasket('Shown.Release');
        $this->inBasket('Passworded.Release', ['passwordstatus' => 1]);
        $this->inBasket('Excluded.Category.Release', ['categories_id' => self::BOOKS_EBOOK]);
        DB::table('user_excluded_categories')->insert(['users_id' => $viewer->id, 'categories_id' => self::BOOKS_EBOOK]);

        $this->page('/basket')->assertOk()->assertSee('Shown.Release')->assertDontSee('Passworded.Release')->assertDontSee('Excluded.Category.Release')
            ->assertSee('Showing 1–1 of 1 release')->assertSee('<span>Download <span data-download-count>1</span> NZBs</span>', false);
    }

    public function test_removing_a_release_takes_it_out_of_the_basket_the_list_and_the_count(): void
    {
        $this->inBasket('Kept.One', ['postdate' => '2026-09-25 10:00:00']);
        $this->inBasket('Removed.One', ['postdate' => '2026-09-24 10:00:00']);
        $this->inBasket('Kept.Two', ['postdate' => '2026-09-23 10:00:00']);

        // the list fragment the page redraws after a removal: the list alone, carrying the count the heading row shows
        $fragment = (string) $this->page('/basket?_fragment=list')->assertOk()->assertSee('Showing 1–3 of 3 releases')->assertSee('data-basket-total="3"', false)->getContent();
        $this->assertStringNotContainsString('<html', $fragment);
        $this->assertStringNotContainsString('tv-filters', $fragment);
        $this->assertStringContainsString('<table class="tv-feed is-shelf is-generic"', $fragment);

        // the row's cart button posts to today's route
        $this->resetGlobalComposerState();
        $this->actingAs($this->viewer())->postJson(route('cart.delete', ['id' => md5('Removed.One')]))->assertOk()->assertJson(['success' => true, 'cartCount' => 2]);

        $this->page('/basket?_fragment=list')->assertOk()->assertSeeInOrder(['Kept.One', 'Kept.Two'])->assertDontSee('Removed.One')
            ->assertSee('Showing 1–2 of 2 releases')->assertSee('data-basket-total="2"', false);
        $this->page('/basket')->assertOk()->assertDontSee('Removed.One')->assertSee('<span>Download <span data-download-count>2</span> NZBs</span>', false);

        // the last one gone: the fragment says so, and the page shows neither action
        foreach (['Kept.One', 'Kept.Two'] as $name) {
            $this->actingAs($this->viewer())->postJson(route('cart.delete', ['id' => md5($name)]))->assertOk();
        }
        $this->page('/basket?_fragment=list')->assertOk()->assertSee('Showing 0 releases')->assertSee('data-basket-total="0"', false)->assertSee('Your basket is empty.');
        // the other lists' fragments carry no basket count
        $this->page('/browse/all?_fragment=list')->assertOk()->assertDontSee('data-basket-total', false);
    }

    public function test_the_bulk_bar_offers_download_nzbs_and_clear_selection_and_no_add_to_cart(): void
    {
        $this->inBasket('A.Release');
        $html = (string) $this->page('/basket')->assertOk()->assertSee('data-select-all', false)->assertSee('data-select value="'.md5('A.Release').'"', false)->getContent();
        $bar = (string) preg_replace('/>\s+</', '><', $this->between($html, '<div class="tv-bulk" x-show="selectedCount" x-cloak>', '</div>'));
        $this->assertSame('<span><span x-text="selectedCount"></span> selected</span>'
            .'<button type="button" class="tv-button" x-on:click="downloadSelected"><i class="fas fa-download" aria-hidden="true"></i>Download NZBs</button>'
            .'<button type="button" class="tv-button tv-button-secondary" x-on:click="clearSelection">Clear selection</button>', trim($bar));
        $this->assertStringContainsString(' data-basket ', $html, 'the page tells its script it is the basket');
        // All releases keeps Add to cart
        $this->page('/browse/all')->assertOk()->assertSee('x-on:click="addSelectedToCart"', false)->assertDontSee(' data-basket ', false);
    }

    public function test_empty_basket_empties_it_and_says_so_and_the_old_cart_address_is_gone(): void
    {
        $this->inBasket('A.Release');
        $this->inBasket('B.Release');
        DB::table('users_releases')->insert(['users_id' => 999, 'releases_id' => $this->basketRelease('Someone.Elses')]);

        $this->send('/basket/empty')->assertRedirect(route('basket'))->assertSessionHas('success', 'Basket emptied.');
        $this->assertSame([999], DB::table('users_releases')->pluck('users_id')->all());
        $this->page('/basket')->assertOk()->assertSee('Basket emptied.')->assertSee('Your basket is empty.')->assertDontSee('Empty basket');

        $this->page('/cart/index')->assertNotFound();
        $this->send('/cart/index')->assertNotFound();
        $this->assertFalse(Route::has('cart.index'));
    }

    public function test_the_older_release_browser_component_and_everything_only_it_used_are_gone(): void
    {
        // issue #1051 section 3: no view, script or style of the older component remains
        foreach (File::allFiles(resource_path()) as $file) {
            $this->assertStringNotContainsString('release-browser', (string) file_get_contents($file->getPathname()), $file->getRelativePathname());
        }
        foreach ([
            'views/cart/index.blade.php', 'views/details/index.blade.php', 'views/components/release-browser.blade.php', 'views/components/release-browser',
            'views/components/release-facts.blade.php', 'views/components/origin-chip.blade.php', 'views/components/entity-chip.blade.php',
            'views/components/release-completion-chips.blade.php', 'views/components/completion-filter.blade.php',
            'views/partials/release-modals.blade.php', 'views/partials/filelist-modal.blade.php', 'views/partials/nfo-modal.blade.php', 'views/partials/preview-modal.blade.php',
            'views/partials/mediainfo-modal.blade.php', 'views/partials/image-modal.blade.php', 'views/partials/report-modal.blade.php',
            'views/partials/file-summary-controls.blade.php', 'views/partials/trailer-modal.blade.php',
            'js/alpine/components/release-browser.js', 'js/alpine/components/release-browser-component.js', 'js/alpine/components/release-details.js',
            'js/alpine/components/release-details-component.js', 'js/alpine/components/cart-page.js', 'js/alpine/components/cart-button.js',
        ] as $path) {
            $this->assertFileDoesNotExist(resource_path($path));
        }
        // the release pages keep their comments partial, the only one left in its folder
        $this->assertSame(['comments.blade.php'], array_map(static fn ($file): string => $file->getFilename(), File::files(resource_path('views/details/partials'))));
        $styles = (string) file_get_contents(resource_path('css/csp-safe.css'));
        foreach (['basket-page', 'basket-footer'] as $class) {
            $this->assertStringNotContainsString($class, $styles);
        }
        $loader = (string) file_get_contents(resource_path('js/alpine/lazy-loader.js'));
        foreach (['releaseBrowser', "'releaseDetails'", 'cartPage', 'releaseMultiOps'] as $component) {
            $this->assertStringNotContainsString($component, $loader);
        }
        $this->assertStringNotContainsString('cart-button', (string) file_get_contents(resource_path('js/alpine/index.js')));
        foreach ([app_path('View/Components/CompletionFilter.php'), app_path('Services/Releases/ReleaseReportPresentation.php'), app_path('Support/ReleaseBrowserPage.php')] as $path) {
            $this->assertFileDoesNotExist($path);
        }
        $this->assertFalse(function_exists('getReleaseCover'));
    }

    /** @return list<int> */
    private function listedIds(TestResponse $response): array
    {
        preg_match_all('/data-select value="([0-9a-f]{32})"/', (string) $response->getContent(), $matches);
        $ids = DB::table('releases')->whereIn('guid', $matches[1])->pluck('id', 'guid');

        return array_map(static fn (string $guid): int => (int) $ids[$guid], $matches[1]);
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
    private function inBasket(string $name, array $attributes = [], ?User $user = null): int
    {
        $id = $this->basketRelease($name, $attributes);
        DB::table('users_releases')->insert(['users_id' => ($user ?? $this->viewer())->id, 'releases_id' => $id]);

        return $id;
    }

    /** @param array<string, mixed> $attributes */
    private function basketRelease(string $name, array $attributes = []): int
    {
        return $this->release($name, ['passwordstatus' => 0, 'resolution' => 0, 'source' => 0, 'imdbid' => null, 'movieinfo_id' => null, 'videos_id' => 0,
            'tv_episodes_id' => 0, 'completion' => 100, 'nfostatus' => 0, 'haspreview' => 0, 'jpgstatus' => 0, 'videostatus' => 0, 'categories_id' => self::MOVIE_SD, ...$attributes]);
    }

    private function viewer(): User
    {
        return $this->user ??= $this->browserUser();
    }

    private function page(string $uri): TestResponse
    {
        $this->resetGlobalComposerState();

        return $this->actingAs($this->viewer())->get($uri);
    }

    /** @param array<string, mixed> $data */
    private function send(string $uri, array $data = []): TestResponse
    {
        $this->resetGlobalComposerState();

        return $this->actingAs($this->viewer())->post($uri, $data);
    }
}
