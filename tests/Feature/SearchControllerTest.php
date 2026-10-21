<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\WebSearchState;
use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\User;
use App\Services\Search\Contracts\SearchServiceInterface;
use App\Support\WebSearchFields;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\AssertsImportedChip;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

final class SearchControllerTest extends TestCase
{
    use AssertsImportedChip;
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->withoutVite();
        $this->withoutMiddleware(TrustedDevice2FAMiddleware::class);
        $this->createReleaseSchema();
        foreach ([1000 => 'Console', 2000 => 'Movies', 3000 => 'Audio', 4000 => 'PC', 5000 => 'TV', 6000 => 'XXX', 7000 => 'Books', 1 => 'Other'] as $id => $title) {
            DB::table('root_categories')->updateOrInsert(['id' => $id], ['title' => $title]);
            DB::table('categories')->insert(['id' => $id + 30, 'title' => 'HD', 'root_categories_id' => $id]);
        }
        foreach (['2026_08_21_090000_create_release_audio_tags_table', '2026_08_27_150100_create_release_video_clips_table'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
        ProductionTables::fromAuthority()->create('movieinfo', ['id', 'imdbid', 'title', 'year', 'rating', 'genre', 'actors', 'director', 'plot', 'cover']);
        // the generic list's picture column reads an Audio row's stored album cover
        foreach (['release_audio_evidence', 'release_music_identifications', 'music_cover_art_lookups'] as $table) {
            ProductionTables::fromAuthority()->create($table);
        }
        config(['nntmux_settings.covers_path' => $this->makeTempDirectory('search-covers')]);
        $search = Mockery::mock(SearchServiceInterface::class);
        config(['nntmux.mysql_search_fallback' => true]);
        $search->shouldReceive('searchReleasesFiltered')->andReturn(['ids' => [], 'total' => 0, 'fuzzy' => false, 'available' => false])->byDefault();
        $search->shouldReceive('searchEntityFields')->andReturn(['ids' => [], 'keys' => [], 'available' => false, 'has_more' => false])->byDefault();
        $search->shouldReceive('isFuzzyEnabled')->andReturn(false)->byDefault();
        $search->shouldReceive('isSuggestEnabled')->andReturn(false)->byDefault();
        $this->app->instance(SearchServiceInterface::class, $search);
    }

    protected function tearDown(): void
    {
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** Issue #1050 section 1: the search address renders the generic list. */
    public function test_a_search_renders_the_generic_list_with_its_heading_cells_five_sorts_and_fifty_rows_a_page(): void
    {
        foreach (range(1, 60) as $index) {
            $this->release('Dune part '.$index, ['postdate' => now()->subHours($index)->toDateTimeString()]);
        }
        $this->release('Arrival');
        $response = $this->actingAs($this->browserUser())->get('/search?q=dune')->assertOk()
            ->assertSee('<h1 data-part="page title">Results for “dune”</h1>', false)->assertSee('<title>Results for “dune”', false)
            ->assertSee('data-preference-root="all"', false)->assertDontSee('data-filters-clock', false)
            ->assertSee('data-name="category"', false)->assertSee('data-name="completion"', false)
            ->assertSeeInOrder(['Posted: newest first', 'Posted: oldest first', 'Added: newest first', 'Added: oldest first', 'Name: A to Z'])
            ->assertSee('Showing 1–50 of 60 releases')->assertSee('Page 1 of 2')
            ->assertSee('<table class="tv-feed is-shelf is-generic"', false)
            ->assertSeeInOrder(['Dune part 1<', 'Dune part 2<', 'Dune part 50<'], false)->assertDontSee('Dune part 51<', false)->assertDontSee('Arrival<', false);
        $html = (string) $response->getContent();
        $this->assertCount(50, $this->listedIds($html));
        $head = $this->between($html, '<thead>', '</thead>');
        $this->assertSame(6, substr_count($head, '<th'));
        $this->assertSame(['Release', 'Category', 'Size', 'Posted', 'Actions'], array_values(array_filter(array_map('trim', explode("\n", strip_tags($head))))));
        // not carried from the old page: the name search field, the Thumbnails toggle, the per-page choice, the Files and Stats columns, the Details and Report buttons, the Grabs sort
        foreach (['tv-name-search', 'Search release names', 'Thumbnails', 'data-preference="thumbs"', 'name="per"', 'data-release-table', 'release-browser', 'Grabs', 'tv-crumbs', 'aria-current="true"'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, $absent);
        }
        $table = $this->between($html, '<table', '</table>');
        foreach (['Details', 'Report', 'Files', 'Stats', 'Grabs'] as $absent) {
            $this->assertStringNotContainsString($absent, $table, 'the table has no '.$absent);
        }
        $row = $this->releaseRowNamed($html, 'Dune part 1');
        $this->assertSame(7, substr_count($row, '<td'), 'select, picture, release, Category, Size, date, actions');
        $this->assertSame(3, preg_match_all('/class="tv-action(?: tv-action-download download-nzb)?" (?:data-copy-nzb|data-cart|title="Download)/', $row));
        $this->assertMatchesRegularExpression('/<input[^>]*id="header-search-input"[^>]*value="dune"/', $html);
        $this->assertStringNotContainsString('<input type="text" x-ref="nameSearch"', $html);

        $this->get('/search?q=dune&page=2')->assertOk()->assertSee('Showing 51–60 of 60 releases')->assertSee('Page 2 of 2');
        // the old page's per, thumbs, sort and ob are no longer read, and no link of the page carries them
        $old = (string) $this->get('/search?q=dune&per=24&thumbs=1&sort=title&ob=name_asc')->assertOk()->assertSee('Showing 1–50 of 60 releases')
            ->assertSeeInOrder(['Dune part 1<', 'Dune part 2<', 'Dune part 3<'], false)->getContent();
        $this->assertDoesNotMatchRegularExpression('/href="[^"]*[?&;](?:per|thumbs|sort|ob)=/', $old);
        $this->get('/search')->assertOk()->assertSee('<h1 data-part="page title">Search results</h1>', false)->assertSee('<title>Search results', false)->assertSee('Showing 1–50 of 61 releases');
        $this->get('/search?q=dune&_fragment=list')->assertOk()->assertDontSee('<html', false)->assertDontSee('tv-filters', false)->assertSee('Showing 1–50 of 60 releases');
    }

    public function test_prefix_search_renders_removable_constraints_and_unprocessed_results(): void
    {
        DB::table('movieinfo')->insert([
            ['imdbid' => '1234567', 'title' => 'Dune', 'actors' => 'Timothée Chalamet', 'director' => 'Denis Villeneuve'],
            ['imdbid' => '7654321', 'title' => 'Dune', 'actors' => 'Different Actor', 'director' => 'Denis Villeneuve'],
        ]);
        $wanted = $this->release('Obfuscated release', ['imdbid' => '1234567', 'nfostatus' => -1, 'isrenamed' => 0]);
        $this->release('Wrong cast release', ['imdbid' => '7654321']);
        $q = 'dune actor:"Chalamet" director:villeneuve';
        $response = $this->actingAs($this->browserUser())->get('/search?'.http_build_query(['q' => $q, 't' => '2000', 'view' => 'cards']))->assertOk();
        $html = (string) $response->getContent();
        $this->assertSame([$wanted], $this->listedIds($html));
        // one chip per constraint the cells do not show, the words first; a scope of roots is the Category cell's, never a chip
        $this->assertSame(['all' => '“dune”', 'actors' => 'actor: Chalamet', 'director' => 'director: villeneuve'], array_column($this->chips($html), 'label', 'key'));
        $this->assertSame(['2000'], $this->tickedRoots($html));
        $response->assertSee('data-query-chips', false)->assertDontSee('Scope:')->assertDontSee('Search Terms')->assertDontSee('Advanced Search')->assertDontSee('Search in Movies')->assertDontSee('data-value="cards"', false);
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $this->assertSame(1, (new \DOMXPath($document))->query('//input[@name="q" and not(@type="hidden")]')->length);
        foreach ($this->chips($html) as $chip) {
            $this->assertStringContainsString('class="tv-query-chip" href="'.e($chip['url']).'" data-remove-constraint="'.$chip['key'].'" aria-label="Remove '.e($chip['label']).'" title="Remove '.e($chip['label']).'"', $html);
            $this->assertStringNotContainsString('page=', $chip['url']);
        }
        $remove = array_column($this->chips($html), 'url', 'key')['actors'];
        $removed = (string) $this->get($remove)->assertOk()->assertSee('Wrong cast release')->getContent();
        $this->assertSame(['all' => '“dune”', 'director' => 'director: villeneuve'], array_column($this->chips($removed), 'label', 'key'));
        $this->assertSame(['2000'], $this->tickedRoots($removed), 'removing a chip removes only that constraint');
    }

    public function test_constraint_only_search_applies_ranges_and_each_chip_removes_one_filter(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 14)->startOfDay());
        $base = ['size' => 200 * 1024 * 1024, 'completion' => 100, 'postdate' => now()->subDays(10)];
        $wanted = $this->release('Matching constraints', $base);
        $low = $this->release('Low completion', [...$base, 'completion' => 80]);
        $this->release('Too recent', [...$base, 'postdate' => now()]);
        $this->release('Too old', [...$base, 'postdate' => now()->subDays(40)]);
        $this->release('Too small', [...$base, 'size' => 50 * 1024 * 1024]);
        $this->release('Too large', [...$base, 'size' => 700 * 1024 * 1024]);
        $this->release('Other category', [...$base, 'categories_id' => 3030]);
        $parameters = ['t' => 'movies', 'cat' => 2030, 'minage' => 1, 'maxage' => 30, 'minsize' => 100, 'maxsize' => 600, 'minc' => 95, 'page' => 1];
        $html = (string) $this->actingAs($this->browserUser())->get('/search?'.http_build_query($parameters))->assertOk()->getContent();
        $this->assertSame([$wanted], $this->listedIds($html));
        // today's order and wording, the sub-category as "Root > Sub"; the completion has no chip: its cell shows it
        $this->assertSame(['cat' => 'Category: Movies > HD', 'minage' => 'Age ≥ 1 d', 'maxage' => 'Age ≤ 30 d', 'minsize' => 'Size ≥ 100 MB', 'maxsize' => 'Size ≤ 600 MB'],
            array_column($this->chips($html), 'label', 'key'));
        $this->assertStringNotContainsString('Completion ≥', $this->between($html, 'data-query-row', 'x-data="searchFeed"'));
        $urls = array_column($this->chips($html), 'url', 'key');
        parse_str((string) parse_url($urls['maxage'], PHP_URL_QUERY), $withoutMaxAge);
        $this->assertSame(['t' => '2000', 'cat' => '2030', 'minage' => '1', 'minsize' => '100', 'maxsize' => '600', 'minc' => '95'], $withoutMaxAge);
        $this->get($urls['maxage'])->assertOk()->assertSee('Too old')->assertDontSee('Too recent');
        // an explicit Completion choice, Any included, wins over the minc link
        $any = (string) $this->get('/search?'.http_build_query([...$parameters, 'completion' => '']))->assertOk()->getContent();
        $this->assertEqualsCanonicalizing([$wanted, $low], $this->listedIds($any));
        $this->assertStringNotContainsString('minc', $this->between($any, 'data-query-row', 'x-data="searchFeed"').$this->between($any, 'class="pager-line', '</nav>'));
    }

    public function test_add_filter_form_replaces_a_field_and_preserves_the_other_constraints(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 14)->startOfDay());
        DB::table('movieinfo')->insert(['imdbid' => '1234567', 'title' => 'Dune', 'actors' => 'Chalamet', 'director' => 'Villeneuve']);
        $wanted = $this->release('Unfinished movie', ['imdbid' => '1234567']);
        $response = $this->actingAs($this->browserUser())->get('/search?'.http_build_query([
            'q' => 'dune actor:wrong director:villeneuve', 't' => '2000', 'maxage' => '30',
            'filter' => 'actors', 'filter_value' => 'Chalamet', 'page' => '8',
        ]))->assertRedirect();
        $response = $this->get($response->headers->get('Location'))->assertOk();
        $html = (string) $response->getContent();
        $this->assertSame([$wanted], $this->listedIds($html));
        $this->assertSame(['all' => '“dune”', 'actors' => 'actor: Chalamet', 'director' => 'director: villeneuve', 'maxage' => 'Age ≤ 30 d'], array_column($this->chips($html), 'label', 'key'));
        $response->assertSee('Add filter');
        $this->assertArrayNotHasKey('filter', $response->viewData('searchState')->parameters);
        $this->assertSame(1, $response->viewData('filters')->page);
    }

    /** Issue #1050 section 3: + Add filter's kinds, its forms, and what applying one does. */
    public function test_add_filter_lists_the_general_filters_and_the_movie_fields_while_the_scope_admits_movies_and_each_form_applies_its_chip(): void
    {
        DB::table('categories')->insert(['id' => 2040, 'title' => 'SD', 'root_categories_id' => 2000]);
        $this->actingAs($this->browserUser());
        $general = ['cat' => 'Sub-category', 'age' => 'Age', 'size' => 'Size range'];
        $movie = ['actors' => 'Actor', 'director' => 'Director', 'title' => 'Movie title', 'plot' => 'Movie plot'];
        $everyRootButOther = '2000,5000,3000,7000,1000,4000,6000';
        foreach (['' => true, '&t=5000' => false, '&t=2000,5000' => true, '&t='.$everyRootButOther => true, '&t=3000,1' => false] as $scope => $movies) {
            $this->assertSame($movies ? $general + $movie : $general, $this->addFilterKinds((string) $this->get('/search?q=dune'.$scope)->assertOk()->getContent()), 'scope "'.$scope.'"');
        }
        $html = (string) $this->get('/search?q=dune&minage=3&maxage=30&minsize=1.5')->assertOk()->getContent();
        $menu = $this->between($html, 'data-add-filter', 'x-data="searchFeed"');
        // the menu's form: a button, then the list of kinds; it closes on Escape, a click outside and focus leaving it (searchFilters)
        $this->assertStringContainsString('x-data="searchFilters"', $html);
        foreach (['x-on:keydown.escape.window="closeAndFocus"', 'x-on:focusout="focusLeft"', 'x-on:click.outside="close"', '<i class="fas fa-plus" aria-hidden="true"></i>Add filter</button>', 'role="menu" aria-label="Add filter" x-show="onList"'] as $part) {
            $this->assertStringContainsString($part, $html);
        }
        // each form sits under a back row naming it; a set filter opens on its values
        $age = $this->filterForm($menu, 'age');
        $this->assertStringContainsString('aria-label="Back to the list of filters"', $age);
        $this->assertMatchesRegularExpression('/<span>Age<\/span>/', $age);
        $this->assertMatchesRegularExpression('/<input name="filter_value" inputmode="decimal" placeholder="Min" aria-label="Minimum \(days\)" value="3"[^>]*>\s*<span>to<\/span>\s*<input name="filter_max" inputmode="decimal" placeholder="Max" aria-label="Maximum \(days\)" value="30"[^>]*>\s*<span>days<\/span>\s*<button type="submit" class="tv-button">Apply<\/button>/', $age);
        $size = $this->filterForm($menu, 'size');
        $this->assertMatchesRegularExpression('/aria-label="Minimum \(MB\)" value="1.5".*aria-label="Maximum \(MB\)" value="".*<span>MB<\/span>/s', $size);
        foreach (array_keys($movie) as $field) {
            $this->assertMatchesRegularExpression('/<input name="filter_value" aria-label="Words to match" value=""[^>]*>\s*<button type="submit" class="tv-button">Apply<\/button>/', $this->filterForm($menu, $field), $field);
        }
        $this->assertStringContainsString('aria-label="Words to match" value="Blunt"', $this->filterForm((string) $this->get('/search?q=dune%20actor:Blunt')->getContent(), 'actors'));
        // every form carries the search and names its kind; none carries a page
        $this->assertSame(7, substr_count($menu, 'data-filter-form='));
        $this->assertSame(6, substr_count($menu, '<input type="hidden" name="q" value="dune">'));
        $this->assertStringNotContainsString('name="page"', $menu);

        // applying adds or replaces that chip and returns to page 1
        $this->get('/search?q=dune&minage=3&page=4&filter=age&filter_value=7&filter_max=')->assertRedirect(route('search', ['q' => 'dune', 'minage' => '7']));
        $this->get('/search?q=dune&t=2000,5000&completion=95&filter=size&filter_value=100&filter_max=600')
            ->assertRedirect(route('search', ['q' => 'dune', 't' => '2000,5000', 'minsize' => '100', 'maxsize' => '600', 'completion' => '95']));
        $this->get('/search?q=dune&filter=plot&filter_value=desert%20planet')->assertRedirect(route('search', ['q' => 'dune plot:(desert planet)']));
        // a value that is not a positive number is ignored
        foreach (['filter=age&filter_value=abc&filter_max=-3', 'filter=size&filter_value=0&filter_max=big', 'filter=age&filter_value=1.5&filter_max='] as $invalid) {
            $this->get('/search?q=dune&'.$invalid)->assertRedirect(route('search', ['q' => 'dune']));
        }

        // Sub-category: a searchable single-choice list of "Root > Sub", limited to the ticked roots when any are ticked
        $every = ['2030' => 'Movies > HD', '2040' => 'Movies > SD', '5030' => 'TV > HD', '3030' => 'Audio > HD', '7030' => 'Books > HD', '1030' => 'Console > HD', '4030' => 'PC > HD', '6030' => 'Adult > HD',
            '1' => 'Other > General', '31' => 'Other > HD'];
        $sub = $this->filterForm($menu, 'cat');
        $this->assertStringContainsString('placeholder="Search categories" aria-label="Search categories"', $sub);
        $this->assertSame($every, $this->subCategories($sub));
        $ticked = $this->filterForm((string) $this->get('/search?q=dune&t=2000,5000&cat=2040')->getContent(), 'cat');
        $this->assertSame(['2030' => 'Movies > HD', '2040' => 'Movies > SD', '5030' => 'TV > HD'], $this->subCategories($ticked));
        $this->assertMatchesRegularExpression('/role="menuitemradio" href="'.preg_quote(e(route('search', ['q' => 'dune', 't' => '2000,5000', 'cat' => '2030'])), '/').'" data-sub-category="2030" data-text="Movies &gt; HD" aria-checked="false"/', $ticked);
        $this->assertStringContainsString('data-sub-category="2040" data-text="Movies &gt; SD" aria-checked="true"', $ticked);
        $applied = (string) $this->get(route('search', ['q' => 'dune', 't' => '2000,5000', 'cat' => '2030']))->assertOk()->getContent();
        $this->assertSame(['all' => '“dune”', 'cat' => 'Category: Movies > HD'], array_column($this->chips($applied), 'label', 'key'));
        // no Group, Poster or Completion entry: those arrive only in a search's address
        $this->assertStringNotContainsString('data-kind="group"', $html);
        $this->assertStringNotContainsString('data-kind="poster"', $html);
        $this->assertStringNotContainsString('data-kind="minc"', $html);
    }

    /** Issue #1050 section 1: the Category cell is the search's scope. */
    public function test_the_category_cell_lists_every_viewable_root_in_header_order_and_reads_and_writes_the_scope(): void
    {
        $movie = $this->release('Scope movie');
        $show = $this->release('Scope show', ['categories_id' => 5030]);
        $album = $this->release('Scope album', ['categories_id' => 3030]);
        $misc = $this->release('Scope misc', ['categories_id' => 31]);
        $user = $this->browserUser();
        $html = (string) $this->actingAs($user)->get('/search?q=scope')->assertOk()->getContent();
        // every root the user may view, in the header's order, whether or not it holds a match; Exclude Other as on All releases
        $this->assertSame(['2000' => 'Movies', '5000' => 'TV', '3000' => 'Audio', '7000' => 'Books', '1000' => 'Console', '4000' => 'PC', '6000' => 'Adult', '1' => 'Other'], $this->categoryMenu($html));
        $this->assertStringContainsString('data-exclude-other="1" data-mode="exclude-other" aria-checked="false"', $html);
        $this->assertStringContainsString('data-scope-param="t"', $html);
        $this->assertStringNotContainsString('data-scope-custom', $html);
        $this->assertSame([], $this->tickedRoots($html));
        $this->assertSame('any', $this->cellText($html, 'category'));
        $this->assertEqualsCanonicalizing([$movie, $show, $album, $misc], $this->listedIds($html));
        $this->assertSame(['All', '0'], $this->headerScope($html));

        // several roots may be ticked; the rows narrow to them, and the pager and the chips keep the scope as written
        foreach (range(1, 55) as $index) {
            $this->release('Scope filler '.$index, ['categories_id' => 5030]);
        }
        $two = (string) $this->get('/search?q=scope&t=2000,5000')->assertOk()->assertSee('Showing 1–50 of 57 releases')->getContent();
        $this->assertSame(['2000', '5000'], $this->tickedRoots($two));
        $this->assertSame('2 chosen', $this->cellText($two, 'category'));
        $this->assertNotContains($album, $this->listedIds($two));
        $this->assertNotContains($misc, $this->listedIds($two));
        $this->assertStringContainsString('href="'.e(route('search', ['q' => 'scope', 't' => '2000,5000', 'page' => 2])).'"', $two);
        $this->assertStringContainsString('<input type="hidden" name="t" value="2000,5000">', $this->between($two, 'class="pager-full"', '</nav>'));
        $this->assertSame(['All', '0'], $this->headerScope($two), 'the header shows All unless exactly one root is ticked');
        $this->assertStringContainsString('data-scope-choice="0" data-scope-value="0"', $two);
        $one = (string) $this->get('/search?q=scope&t=tv')->assertOk()->getContent();
        $this->assertSame(['5000'], $this->tickedRoots($one));
        $this->assertSame('TV', $this->cellText($one, 'category'));
        $this->assertSame(['TV', '5000'], $this->headerScope($one));
        $this->assertStringContainsString('data-scope-choice="5000" data-scope-value="5000"', $one);
        $this->assertSame(['TV', '5000'], $this->headerScope((string) $this->get('/search?q=scope&t=5000,5000')->assertOk()->getContent()), 'one root written twice is one ticked root');

        // every root but Other is the Exclude Other mode
        $excluding = (string) $this->get('/search?q=scope%20-filler&t=2000,5000,3000,7000,1000,4000,6000')->assertOk()->getContent();
        $this->assertSame('Exclude Other', $this->cellText($excluding, 'category'));
        $this->assertStringContainsString('data-exclude-other="1" data-mode="exclude-other" aria-checked="true"', $excluding);
        $this->assertEqualsCanonicalizing([$movie, $show, $album], $this->listedIds($excluding));

        // a root the user may not view is not listed
        $user->revokePermissionTo('view audio');
        Cache::forget(User::categoryExclusionCacheKey($user->id));
        $this->resetGlobalComposerState();
        $hidden = (string) $this->get('/search?q=scope%20-filler')->assertOk()->getContent();
        $this->assertSame(['2000' => 'Movies', '5000' => 'TV', '7000' => 'Books', '1000' => 'Console', '4000' => 'PC', '6000' => 'Adult', '1' => 'Other'], $this->categoryMenu($hidden));
        $this->assertEqualsCanonicalizing([$movie, $show, $misc], $this->listedIds($hidden));
    }

    /** Issue #1050 sections 1 and 5: the Completion cell on a search, Imported only among its choices. */
    public function test_the_completion_cell_offers_imported_only_and_honours_a_minc_link_as_all_releases_does(): void
    {
        $full = $this->release('Cell complete', ['completion' => 100]);
        $most = $this->release('Cell mostly', ['completion' => 96, 'is_imported' => 1]);
        $some = $this->release('Cell partly', ['completion' => 85]);
        $imported = $this->release('Cell imported', ['completion' => 40, 'is_imported' => 1]);
        $this->release('Other imported', ['is_imported' => 1]);
        $this->actingAs($this->browserUser());
        $any = (string) $this->get('/search?q=cell')->assertOk()->getContent();
        $this->assertSame([['', 'Any completion'], ['100', '100% only'], ['95', '95% or more'], ['imported', 'Imported only']], $this->completionMenu($any));
        $this->assertSame('any', $this->cellText($any, 'completion'));
        $this->assertEqualsCanonicalizing([$full, $most, $some, $imported], $this->listedIds($any));
        $this->assertEqualsCanonicalizing([$full], $this->listedIds((string) $this->get('/search?q=cell&completion=100')->assertOk()->getContent()));
        $ninetyFive = (string) $this->get('/search?q=cell&completion=95')->assertOk()->getContent();
        $this->assertEqualsCanonicalizing([$full, $most], $this->listedIds($ninetyFive));
        $this->assertSame('95%+', $this->cellText($ninetyFive, 'completion'));

        // Imported only: one choice of the menu, at any completion; every row is imported and matches the search
        $only = (string) $this->get('/search?q=cell&completion=imported')->assertOk()->getContent();
        $this->assertEqualsCanonicalizing([$most, $imported], $this->listedIds($only));
        $this->assertImportedOnlyChosenLast($only);
        $this->assertStringContainsString('href="'.e(route('search')).'" class="pager-line-clear"', $only);
        $this->get('/search?q=cell%20-mostly%20-imported&completion=imported&t=2000')->assertOk()->assertSee('No releases match “cell -mostly -imported” · Movies · imported only.');

        // a minc link is honoured as a threshold with no chip, kept by the page's links, and dropped by an explicit Completion
        $minc = (string) $this->get('/search?q=cell&minc=80')->assertOk()->getContent();
        $this->assertEqualsCanonicalizing([$full, $most, $some], $this->listedIds($minc));
        $this->assertSame('any', $this->cellText($minc, 'completion'));
        $this->assertSame(['all' => '“cell”'], array_column($this->chips($minc), 'label', 'key'));
        $this->assertStringContainsString('minc=80', array_column($this->chips($minc), 'url', 'key')['all']);
        $this->get('/search?q=cell%20-complete%20-mostly%20-partly&minc=80')->assertOk()->assertSee('No releases match “cell -complete -mostly -partly” · 80%+ complete.');
        $this->assertEqualsCanonicalizing([$most, $imported], $this->listedIds((string) $this->get('/search?q=cell&minc=80&completion=imported')->assertOk()->getContent()));
        $this->assertEqualsCanonicalizing([$full, $most, $some, $imported], $this->listedIds((string) $this->get('/search?q=cell&minc=80&completion=')->assertOk()->getContent()));
    }

    /** Issue #1050 section 5: Imported only reaches the index as its own criterion, and a threshold as today's. */
    public function test_an_index_backed_search_filters_on_the_imported_flag_or_the_completion_threshold(): void
    {
        $this->actingAs($this->browserUser());
        $search = app(SearchServiceInterface::class);
        $search->shouldReceive('searchReleasesFiltered')->once()
            ->withArgs(static fn (array $criteria): bool => ($criteria['imported_only'] ?? null) === true && ! array_key_exists('min_completion', $criteria))
            ->andReturn(['ids' => [], 'total' => 0, 'available' => true]);
        $this->get('/search?q=dune&completion=imported&minc=80')->assertOk()->assertSee('No releases match “dune” · imported only.');
        $search->shouldReceive('searchReleasesFiltered')->once()
            ->withArgs(static fn (array $criteria): bool => ($criteria['min_completion'] ?? null) === 95 && ! array_key_exists('imported_only', $criteria))
            ->andReturn(['ids' => [], 'total' => 0, 'available' => true]);
        $this->get('/search?q=dune&completion=95')->assertOk();
        $search->shouldReceive('searchReleasesFiltered')->once()
            ->withArgs(static fn (array $criteria): bool => ($criteria['min_completion'] ?? null) === 80 && ! array_key_exists('imported_only', $criteria))
            ->andReturn(['ids' => [], 'total' => 0, 'available' => true]);
        $this->get('/search?q=dune&minc=80')->assertOk();
        $search->shouldReceive('searchReleasesFiltered')->once()
            ->withArgs(static fn (array $criteria): bool => ! array_key_exists('min_completion', $criteria) && ! array_key_exists('imported_only', $criteria))
            ->andReturn(['ids' => [], 'total' => 0, 'available' => true]);
        $this->get('/search?q=dune&minc=80&completion=')->assertOk();
    }

    /** Issue #1050 section 1: a search's cells live in its address only. */
    public function test_a_search_never_applies_the_lists_remembered_filters_and_never_changes_them(): void
    {
        $movie = $this->release('Memory movie', ['completion' => 90]);
        $show = $this->release('Memory show', ['categories_id' => 5030]);
        $user = $this->browserUser();
        $remembered = static fn (): mixed => User::query()->findOrFail($user->id)->releaseViewPreferences('all')['filters'] ?? null;
        $this->actingAs($user)->get('/browse/all?_fragment=list&category[]=5000&completion=95')->assertOk();
        $this->assertSame(['category' => [5000], 'completion' => 95], $remembered());

        $html = (string) $this->get('/search?q=memory')->assertOk()->getContent();
        $this->assertEqualsCanonicalizing([$movie, $show], $this->listedIds($html), 'a bare search is not redirected to the remembered filters and is not narrowed by them');
        $this->assertSame([], $this->tickedRoots($html));
        $this->assertSame('any', $this->cellText($html, 'completion'));
        $this->get('/search?q=memory&t=2000&completion=100')->assertOk();
        $this->get('/search?q=memory&t=2000&completion=100&_fragment=list&_filters_at=99999999999999')->assertOk();
        $this->get('/search?clear=1')->assertOk();
        $this->assertSame(['category' => [5000], 'completion' => 95], $remembered(), 'a search leaves the remembered All releases filters alone');
    }

    /** Issue #1050 section 1: the list's five sorts, remembered with the All releases list's sort. */
    public function test_a_search_is_sorted_by_the_all_releases_lists_remembered_sort(): void
    {
        $this->release('Sorted b', ['postdate' => '2026-09-10 00:00:00', 'adddate' => '2026-09-13 00:00:00']);
        $this->release('Sorted a', ['postdate' => '2026-09-12 00:00:00', 'adddate' => '2026-09-11 00:00:00']);
        $this->release('Sorted c', ['postdate' => '2026-09-11 00:00:00', 'adddate' => '2026-09-12 00:00:00']);
        $user = $this->browserUser();
        $this->actingAs($user)->get('/search?q=sorted&sort=title')->assertOk()->assertSeeInOrder(['Sorted a<', 'Sorted c<', 'Sorted b<'], false)
            ->assertSee('<option value="posted" selected', false)->assertSee('<th class="tv-num">Posted</th>', false);
        foreach (['posted_oldest' => ['b', 'c', 'a'], 'newest' => ['b', 'c', 'a'], 'oldest' => ['a', 'c', 'b'], 'title' => ['a', 'b', 'c']] as $sort => $order) {
            $this->postJson('/profile/update-view', ['root' => 'all', 'sort' => $sort])->assertOk();
            $this->actingAs(User::query()->findOrFail($user->id))->get('/search?q=sorted')->assertOk()
                ->assertSeeInOrder(array_map(static fn (string $name): string => 'Sorted '.$name.'<', $order), false)
                ->assertSee('<option value="'.$sort.'" selected', false)->assertSee('<th class="tv-num">'.(in_array($sort, ['newest', 'oldest'], true) ? 'Added' : 'Posted').'</th>', false);
        }
        // the index is asked for the same order
        $search = app(SearchServiceInterface::class);
        foreach (['posted' => ['postdate_ts', 'desc'], 'posted_oldest' => ['postdate_ts', 'asc'], 'newest' => ['adddate_ts', 'desc'], 'oldest' => ['adddate_ts', 'asc'], 'title' => ['sort_name', 'asc']] as $sort => [$field, $direction]) {
            $this->postJson('/profile/update-view', ['root' => 'all', 'sort' => $sort])->assertOk();
            $search->shouldReceive('searchReleasesFiltered')->once()
                ->withArgs(static fn (array $criteria, int $limit, int $offset): bool => $criteria['sort_field'] === $field && $criteria['sort_dir'] === $direction && $limit === 50 && $offset === 0)
                ->andReturn(['ids' => [], 'total' => 0, 'available' => true]);
            $this->actingAs(User::query()->findOrFail($user->id))->get('/search?q=sorted&sort=grabs&ob=name_asc')->assertOk();
        }
    }

    /** @return iterable<string, array{array<string, string>, list<int>, string, list<string>, list<string>}> */
    public static function legacyScopes(): iterable
    {
        yield 'one sub-category' => [['t' => '2040'], [2040], 'Scope: Movies · SD', [], ['Legacy movie sd']];
        yield 'several sub-categories' => [['t' => '2040,5030'], [2040, 5030], 'Scope: Movies · SD, TV · HD', [], ['Legacy movie sd', 'Legacy show hd']];
        yield 'a root and a sub-category' => [['t' => '5000,2040'], [2040, 5030], 'Scope: TV, Movies · SD', ['5000'], ['Legacy movie sd', 'Legacy show hd']];
        yield 'the legacy advanced search category' => [['searchadvcat' => '2040'], [2040], 'Scope: Movies · SD', [], ['Legacy movie sd']];
    }

    /**
     * Issue #1050 section 3, legacy scope compatibility: a scope naming a sub-category keeps its exact matching on the
     * index and on the database fallback, with and without a separate `cat`; the Scope chip names it whole and the
     * cell reads "Custom scope".
     *
     * @param  array<string, string>  $scope
     * @param  list<int>  $categories
     * @param  list<string>  $tickedRoots
     * @param  list<string>  $names
     */
    #[DataProvider('legacyScopes')]
    public function test_a_legacy_scope_keeps_its_exact_matching_its_scope_chip_and_the_custom_scope_cell(array $scope, array $categories, string $chip, array $tickedRoots, array $names): void
    {
        DB::table('categories')->insert(['id' => 2040, 'title' => 'SD', 'root_categories_id' => 2000]);
        foreach (['Legacy movie hd' => 2030, 'Legacy movie sd' => 2040, 'Legacy show hd' => 5030, 'Legacy album hd' => 3030] as $name => $category) {
            $this->release($name, ['categories_id' => $category]);
        }
        foreach (range(1, 55) as $index) {
            $this->release('Legacy filler '.$index, ['categories_id' => 2040, 'postdate' => '2026-01-01 00:00:00']);
        }
        $user = $this->browserUser();
        $written = $scope['t'] ?? $scope['searchadvcat'];
        sort($categories);

        // the database fallback: the union of the scope's categories
        $html = (string) $this->actingAs($user)->get('/search?'.http_build_query(['q' => 'legacy -filler', ...$scope]))->assertOk()->getContent();
        $this->assertEqualsCanonicalizing($names, $this->listedNames($html));

        // the Scope chip follows the words and names the entire scope; the cell reads "Custom scope" and ticks only the roots the scope names itself
        $this->assertSame(['all' => '“legacy -filler”', 't' => $chip], array_column($this->chips($html), 'label', 'key'));
        $this->assertSame('Custom scope', $this->cellText($html, 'category'));
        $this->assertSame($tickedRoots, $this->tickedRoots($html));
        $this->assertStringContainsString('data-scope-custom', $html);
        $this->assertStringContainsString('data-cell-text="true"', $html);
        $this->assertStringContainsString('<div class="checkbox-menu-note" id="category-menu-note">Choosing roots replaces the custom scope.</div>', $html);
        $this->assertMatchesRegularExpression('/data-any aria-checked="false" x-on:click="clear">.*?Any category/s', $this->between($html, 'data-name="category"', 'data-name="completion"'));
        $this->assertStringContainsString('data-exclude-other="1" data-mode="exclude-other" aria-checked="false"', $html);
        // the header's picker reads All and keeps the scope until another is picked
        $this->assertSame(['All', $written], $this->headerScope($html));
        $this->assertStringContainsString('data-scope-choice="0" data-scope-value="'.$written.'"', $html);

        // removing the chip clears only the scope and returns to page 1; a separate cat and the other constraints remain
        $withCat = ['q' => 'legacy', ...$scope, 'cat' => '2040', 'maxage' => '9000', 'completion' => '100'];
        $narrowed = (string) $this->get('/search?'.http_build_query([...$withCat, 'page' => 2]))->assertOk()->getContent();
        $this->assertSame(['all' => '“legacy”', 't' => $chip, 'cat' => 'Category: Movies > SD', 'maxage' => 'Age ≤ 9000 d'], array_column($this->chips($narrowed), 'label', 'key'));
        $this->assertSame(route('search', ['q' => 'legacy', 'cat' => '2040', 'maxage' => '9000', 'completion' => '100']), array_column($this->chips($narrowed), 'url', 'key')['t']);
        // the scope's union intersected with the separate cat
        $this->assertSame(56, substr_count((string) $this->get('/search?'.http_build_query($withCat))->getContent().$narrowed, 'data-release-row'));
        $this->assertSame([], $this->listedIds((string) $this->get('/search?'.http_build_query(['q' => 'legacy', ...$scope, 'cat' => '3030']))->assertOk()->getContent()), 'a cat outside the scope matches nothing');

        // paging, another filter and the reloaded list keep the scope exactly as written
        $paged = (string) $this->get('/search?'.http_build_query(['q' => 'legacy', ...$scope]))->assertOk()->getContent();
        $this->assertStringContainsString('href="'.e(route('search', ['q' => 'legacy', 't' => $written, 'page' => 2])).'"', $paged);
        $this->assertStringContainsString('<input type="hidden" name="t" value="'.$written.'">', $this->between($paged, 'class="pager-full"', '</nav>'));
        $this->assertSame(6, substr_count($this->between($paged, 'data-add-filter', 'pager-line'), '<input type="hidden" name="t" value="'.$written.'">'), 'every Add filter form carries it');
        $fragment = (string) $this->get('/search?'.http_build_query(['q' => 'legacy', 't' => $written, 'completion' => '100', '_fragment' => 'list']))->assertOk()->getContent();
        $this->assertSame($chip, array_column($this->chips($fragment), 'label', 'key')['t']);
        $this->assertStringContainsString('href="'.e(route('search', ['q' => 'legacy', 't' => $written, 'completion' => '100', 'page' => 2])).'"', $fragment);
        // Add filter reads what the scope admits: its sub-categories, and the movie fields while one of them is a Movies category
        $this->assertSame(array_intersect_key(['2040' => 'Movies > SD', '5030' => 'TV > HD'], array_flip(array_map('strval', $categories))), $this->subCategories($this->filterForm($paged, 'cat')));
        $this->assertArrayHasKey('actors', $this->addFilterKinds($paged));
        // the feed URL is the existing builder's: the separate cat when there is one, else the scope as written
        parse_str((string) parse_url($this->feedUrl($paged), PHP_URL_QUERY), $feed);
        $this->assertSame(['t' => 'search', 'apikey' => $user->api_token, 'o' => 'xml', 'q' => 'legacy', 'cat' => $written], $feed);
        parse_str((string) parse_url($this->feedUrl($narrowed), PHP_URL_QUERY), $feedWithCat);
        $this->assertSame('2040', $feedWithCat['cat']);
        // the chip names the scope in the empty result; "Custom scope" is not repeated
        $this->get('/search?'.http_build_query(['q' => 'nothing', ...$scope, 'completion' => '95']))->assertOk()->assertSee('No releases match “nothing” · '.$chip.' · 95%+ complete.')->assertDontSee('Custom scope ·');
        // choosing roots replaces the scope: the address then holds roots only, the chip is gone and the cell reads them
        $replaced = (string) $this->get('/search?q=legacy&t=5000')->assertOk()->getContent();
        $this->assertArrayNotHasKey('t', array_column($this->chips($replaced), 'label', 'key'));
        $this->assertSame('TV', $this->cellText($replaced, 'category'));
        $this->assertStringNotContainsString('data-scope-custom', $replaced);
        $this->assertStringNotContainsString('checkbox-menu-note', $replaced);
    }

    /**
     * The index is asked for the same categories as the database fallback reads: the scope's union, and its
     * intersection with a separate `cat`.
     *
     * @param  array<string, string>  $scope
     * @param  list<int>  $categories
     * @param  list<string>  $tickedRoots
     * @param  list<string>  $names
     */
    #[DataProvider('legacyScopes')]
    public function test_a_legacy_scope_reaches_the_index_as_the_same_union_of_categories(array $scope, array $categories, string $chip, array $tickedRoots, array $names): void
    {
        DB::table('categories')->insert(['id' => 2040, 'title' => 'SD', 'root_categories_id' => 2000]);
        sort($categories);
        $asked = [];
        app(SearchServiceInterface::class)->shouldReceive('searchReleasesFiltered')->twice()->andReturnUsing(static function (array $criteria) use (&$asked): array {
            $ids = $criteria['category_ids'];
            sort($ids);
            $asked[] = $ids;

            return ['ids' => [], 'total' => 0, 'available' => true];
        });
        $this->actingAs($this->browserUser())->get('/search?'.http_build_query(['q' => 'legacy', ...$scope]))->assertOk();
        $this->get('/search?'.http_build_query(['q' => 'legacy', ...$scope, 'cat' => '2040']))->assertOk();
        $this->assertSame([$categories, [2040]], $asked);
        // a cat outside the scope leaves no category: the index is not asked at all
        $this->get('/search?'.http_build_query(['q' => 'legacy', ...$scope, 'cat' => '3030']))->assertOk()->assertSee('No releases match');
    }

    public function test_a_legacy_scope_outside_movies_offers_no_movie_field(): void
    {
        $this->actingAs($this->browserUser());
        $this->assertSame(['cat' => 'Sub-category', 'age' => 'Age', 'size' => 'Size range'], $this->addFilterKinds((string) $this->get('/search?q=dune&t=5030,3000')->assertOk()->getContent()));
        $this->assertSame(['5030' => 'TV > HD', '3030' => 'Audio > HD'], $this->subCategories($this->filterForm((string) $this->get('/search?q=dune&t=5030,3000')->getContent(), 'cat')));
    }

    public function test_index_search_keeps_phrase_and_exclusion_syntax_when_rebuilding_chips(): void
    {
        $wanted = $this->release('Lexical hit without literal words');
        $this->release('part two cam');
        $this->release('part other two');
        $search = app(SearchServiceInterface::class);
        $search->shouldReceive('searchReleasesFiltered')->once()->withArgs(static fn (array $criteria, int $limit, int $offset): bool => $criteria['phrases'] === ['searchname' => '"part two" -cam'] && $criteria['try_fuzzy'] === false && $criteria['web_force_fuzzy'] === false && $limit === 50 && $offset === 0)
            ->andReturn(['ids' => [$wanted], 'total' => 1, 'fuzzy' => false, 'available' => true, 'has_more' => false]);
        $response = $this->actingAs($this->browserUser())->get('/search?'.http_build_query(['q' => '"part two" -cam', 't' => '2000']))->assertOk()
            ->assertSee('<h1 data-part="page title">Results for “&quot;part two&quot; -cam”</h1>', false);
        $this->assertSame([$wanted], $this->listedIds((string) $response->getContent()));
        $this->assertSame('"part two" -cam', $response->viewData('searchState')->parameters['q']);
    }

    /** @return iterable<string, array{int, int, int, int, int}> the page asked for, the index's reach, the offset and limit asked of it, the last page */
    public static function largePages(): iterable
    {
        yield 'first' => [1, 10000, 0, 50, 200];
        yield 'middle' => [57, 10000, 2800, 50, 200];
        yield 'last reachable' => [200, 10000, 9950, 50, 200];
        yield 'last, partly reachable' => [3, 120, 100, 20, 3];
    }

    /** Issue #1050 section 1, Reach: an index-backed search stops paging at the last page the index can reach and says so. */
    #[DataProvider('largePages')]
    public function test_large_search_fetches_only_the_requested_page_stops_at_the_index_reach_and_shows_the_note(int $page, int $reach, int $offset, int $limit, int $lastPage): void
    {
        config(['search.default' => 'manticore', 'search.drivers.manticore.max_matches' => $reach]);
        $ids = [];
        for ($i = 0; $i < $limit; $i++) {
            $ids[] = $this->release('Result '.$i);
        }
        app(SearchServiceInterface::class)->shouldReceive('searchReleasesFiltered')->once()
            ->withArgs(static fn (array $criteria, int $requestedLimit, int $requestedOffset): bool => $requestedLimit === $limit && $requestedOffset === $offset && $criteria['sort_field'] === 'postdate_ts')
            ->andReturn(['ids' => $ids, 'total' => 1000000, 'available' => true, 'has_more' => true]);
        $this->actingAs($this->browserUser());
        DB::enableQueryLog();
        $response = $this->get('/search?q=lexical&page='.$page)->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            if (str_contains($query['query'], 'from "releases"')) {
                preg_match_all('/(?:"id"|"releases_id") in \(([^)]+)\)/i', $query['query'], $lists);
                foreach ($lists[1] as $list) {
                    $this->assertLessThanOrEqual(50, count(explode(',', $list)));
                }
            }
        }
        $html = (string) $response->getContent();
        $this->assertCount($limit, $this->listedIds($html));
        $response->assertSee('of 1,000,000 releases')->assertSee('Page '.number_format($page).' of '.number_format($lastPage));
        // the note sits in the pager line's status slot, between the count and Clear all
        $line = $this->between($html, 'class="pager-line', '</nav>');
        $this->assertMatchesRegularExpression('/pager-line-summary.*<span class="pager-line-status"><span class="tv-sweep-slot"><span class="tv-sweep" role="status" data-search-reach>More results are available\. Use a narrower search to reach the remaining results\.<\/span><\/span><\/span>.*data-clear-all/s', $line);
        if ($page === $lastPage) {
            $this->assertStringNotContainsString('rel="next"', $html);
            $this->assertStringNotContainsString('aria-label="Page '.($lastPage + 1).'"', $html);
        }
    }

    public function test_a_search_within_the_index_reach_shows_no_note_and_a_page_past_it_opens_the_last_reachable_page(): void
    {
        $ids = [];
        for ($i = 0; $i < 20; $i++) {
            $ids[] = $this->release('Reach '.$i);
        }
        $search = app(SearchServiceInterface::class);
        $search->shouldReceive('searchReleasesFiltered')->once()->withArgs(static fn (array $criteria, int $limit, int $offset): bool => $limit === 50 && $offset === 100)
            ->andReturn(['ids' => $ids, 'total' => 120, 'available' => true]);
        $this->actingAs($this->browserUser())->get('/search?q=reach&page=3')->assertOk()->assertSee('Showing 101–120 of 120 releases')->assertSee('Page 3 of 3')
            ->assertDontSee('More results are available')->assertDontSee('data-search-reach', false);
        // past the reach the index is asked for its last reachable page, and the address moves there
        $search->shouldReceive('searchReleasesFiltered')->once()->withArgs(static fn (array $criteria, int $limit, int $offset): bool => $limit === 50 && $offset === 9950)
            ->andReturn(['ids' => [], 'total' => 1000000, 'available' => true]);
        $this->get('/search?q=reach&t=2000&page=999')->assertRedirect(route('search', ['q' => 'reach', 't' => '2000', 'page' => 200]));
        config(['search.default' => 'manticore', 'search.drivers.manticore.max_matches' => 120]);
        $search->shouldReceive('searchReleasesFiltered')->once()->withArgs(static fn (array $criteria, int $limit, int $offset): bool => $limit === 20 && $offset === 100)
            ->andReturn(['ids' => [], 'total' => 1000000, 'available' => true]);
        $this->get('/search?q=reach&page=999')->assertRedirect(route('search', ['q' => 'reach', 'page' => 3]));
    }

    public function test_repeated_quoted_prefixes_round_trip_without_losing_scope_or_growing_groups(): void
    {
        $state = WebSearchState::fromRequest(new Request(['q' => '1080p actor:"Hugh Jackman" actor:"Patrick Stewart" director:(scorsese | nolan)']));
        $canonical = $state->parameters['q'];
        for ($i = 0; $i < 3; $i++) {
            $state = WebSearchState::fromRequest(new Request(['q' => $state->parameters['q']]));
            $this->assertSame($canonical, $state->parameters['q']);
            $this->assertSame('1080p', $state->terms->freeText());
            $this->assertSame('("Hugh Jackman" "Patrick Stewart")', $state->terms->indexTerms()['actors']);
        }
    }

    public function test_entity_exclusions_suppress_fuzzy_even_when_free_text_has_none(): void
    {
        app(SearchServiceInterface::class)->shouldReceive('isFuzzyEnabled')->andReturn(true);
        app(SearchServiceInterface::class)->shouldReceive('searchEntityFields')->once()
            ->andReturn(['ids' => [9], 'keys' => ['0123456'], 'available' => true, 'has_more' => false]);
        app(SearchServiceInterface::class)->shouldReceive('searchReleasesFiltered')->once()
            ->withArgs(static fn (array $criteria): bool => ! $criteria['web_force_fuzzy'])
            ->andReturn(['ids' => [], 'total' => 0, 'available' => true]);
        $this->actingAs($this->browserUser())->get('/search?'.http_build_query(['q' => 'wolvrine actor:(Jackman -Stewart)']))->assertOk();
    }

    public function test_poster_filter_preserves_whitespace_from_the_original_http_query(): void
    {
        app(SearchServiceInterface::class)->shouldReceive('searchReleasesFiltered')->once()
            ->withArgs(static fn (array $criteria): bool => $criteria['poster'] === ' user@example.com')
            ->andReturn(['ids' => [], 'total' => 0, 'available' => true]);
        $html = (string) $this->actingAs($this->browserUser())->get('/search?poster=%20user%40example.com')->assertOk()->getContent();
        // a Poster chip still shows, and can be removed, when a search arrives with one
        $this->assertSame(['poster' => 'Poster:  user@example.com'], array_column($this->chips($html), 'label', 'key'));
        $this->assertSame(route('search'), $this->chips($html)[0]['url']);
    }

    public function test_memory_does_not_grow_with_reported_match_count(): void
    {
        $ids = [];
        for ($i = 0; $i < 50; $i++) {
            $ids[] = $this->release('Memory fixture '.$i);
        }
        app(SearchServiceInterface::class)->shouldReceive('searchReleasesFiltered')->times(3)
            ->andReturn(['ids' => $ids, 'total' => 1000000, 'available' => true], ['ids' => $ids, 'total' => 1000000, 'available' => true], ['ids' => $ids, 'total' => 1000000000, 'available' => true]);
        $this->actingAs($this->browserUser())->get('/search?q=memory')->assertOk();
        $peaks = [];
        for ($i = 0; $i < 2; $i++) {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $start = memory_get_usage();
            $response = $this->get('/search?q=memory')->assertOk();
            $this->assertCount(50, $response->viewData('rows'));
            $peaks[] = memory_get_peak_usage() - $start;
            unset($response);
        }
        $this->assertLessThan($peaks[0] + 2 * 1024 * 1024, $peaks[1]);
    }

    /** Issue #1050 section 1, Search down: the heading row, the filter bar and the query row, then "Search unavailable"; no pager. */
    public function test_outage_reads_search_unavailable_with_no_count_no_pager_and_no_deep_page_redirect(): void
    {
        config(['nntmux.mysql_search_fallback' => false]);
        $this->release('Dune unreachable');
        $response = $this->actingAs($this->browserUser())->get('/search?q=Dune&t=2000&page=999')->assertOk();
        $html = (string) $response->getContent();
        $response->assertSeeInOrder(['<h1 data-part="page title">Results for “Dune”</h1>', 'data-search-feed-button', 'data-part="sort dropdown"', 'data-name="category"', 'data-name="completion"', 'data-query-row', 'data-add-filter',
            '<p class="tv-empty" data-empty>Search unavailable</p>'], false);
        foreach (['pager-line', 'pager-full', 'Showing ', 'data-clear-all', '<table', 'Dune unreachable', 'No releases match'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, $absent);
        }
        $this->assertSame(['all' => '“Dune”'], array_column($this->chips($html), 'label', 'key'));
        $this->assertFalse($response->viewData('results')->available);
        $this->assertSame([], $response->viewData('rows'));
    }

    /** Issue #1050 section 5: the unavailable state also covers a database fallback that is on but does not succeed. */
    public function test_an_unsuccessful_database_fallback_reads_search_unavailable(): void
    {
        $this->release('Dune unreachable');
        $this->actingAs($this->browserUser())->get('/search?q=Dune')->assertOk()->assertSee('Showing 1–1 of 1 release');
        // the fallback's own read of `releases` fails
        Schema::table('releases', static function (Blueprint $table): void {
            $table->dropColumn('completion');
        });
        $response = $this->get('/search?q=Dune&minc=80&page=4')->assertOk()->assertSee('<p class="tv-empty" data-empty>Search unavailable</p>', false)
            ->assertSee('data-query-row', false)->assertDontSee('pager-line', false)->assertDontSee('Dune unreachable');
        $this->assertFalse($response->viewData('results')->available);
    }

    /** Issue #1050 section 5: a successful database fallback is a normal result, counted and paged by the database. */
    public function test_a_successful_database_fallback_renders_normal_results_pagination_and_the_normal_empty_state(): void
    {
        config(['search.default' => 'manticore', 'search.drivers.manticore.max_matches' => 50]);
        foreach (range(1, 120) as $index) {
            $this->release('Fallback '.$index, ['is_imported' => $index % 2, 'postdate' => now()->subHours($index)->toDateTimeString()]);
        }
        $this->actingAs($this->browserUser());
        // not capped by the unavailable index's reach
        $third = $this->get('/search?q=fallback&page=3')->assertOk()->assertSee('Showing 101–120 of 120 releases')->assertSee('Page 3 of 3')
            ->assertDontSee('Search unavailable')->assertDontSee('More results are available');
        $this->assertTrue($third->viewData('results')->available);
        $this->assertSame(array_map(static fn (int $index): string => 'Fallback '.$index, range(101, 120)), $this->listedNames((string) $third->getContent(), false));
        $this->get('/search?q=fallback&page=9')->assertRedirect(route('search', ['q' => 'fallback', 'page' => 3]));
        // Imported only is a condition on releases.is_imported
        $imported = (string) $this->get('/search?q=fallback&completion=imported&page=2')->assertOk()->assertSee('Showing 51–60 of 60 releases')->getContent();
        $this->assertSame(10, substr_count($imported, 'data-release-row'));
        $this->assertSame(10, substr_count($imported, 'data-chip-variant="imported"'), 'every row is imported');
        // zero matches: the normal empty-result state, with the pager line and Clear all
        $this->get('/search?q=nomatch&completion=imported')->assertOk()->assertSee('Showing 0 releases')->assertSee('<p class="tv-empty" data-empty>No releases match “nomatch” · imported only.</p>', false)
            ->assertSee('data-clear-all', false)->assertDontSee('Search unavailable');
    }

    /** Issue #1050 section 3: "Did you mean …?" under today's rule, after + Add filter, in the query row. */
    public function test_did_you_mean_follows_add_filter_for_a_search_with_words_that_finds_three_results_or_fewer(): void
    {
        $search = app(SearchServiceInterface::class);
        $search->shouldReceive('isSuggestEnabled')->andReturn(true);
        $search->shouldReceive('suggest')->with('dnue')->andReturn([['suggest' => 'done', 'distance' => 2, 'docs' => 3], ['suggest' => 'dune', 'distance' => 1, 'docs' => 40]]);
        $search->shouldReceive('suggest')->with('dune')->andReturn([['suggest' => 'dune', 'distance' => 0, 'docs' => 40]]);
        foreach (range(1, 4) as $index) {
            $this->release('Dune '.$index);
        }
        $this->release('Dnue typo');
        $html = (string) $this->actingAs($this->browserUser())->get('/search?q=dnue%20actor:nobody&t=2000&maxage=30')->assertOk()->getContent();
        $row = $this->between($html, 'data-query-row', 'pager-line');
        $this->assertMatchesRegularExpression('/data-remove-constraint="maxage".*data-add-filter.*<\/span>\s*<span class="tv-query-suggestion" data-suggestion>Did you mean <a href="'.preg_quote(e(route('search', ['q' => 'dune actor:nobody', 't' => '2000', 'maxage' => '30'])), '/').'">dune<\/a>\?<\/span>\s*<\/div>\s*<\/div>/s', $row);
        // no suggestion: more than three results, the same spelling, no words, or an index that cannot answer
        $this->get('/search?q=dune')->assertOk()->assertDontSee('Did you mean');
        $search->shouldReceive('suggest')->with('typo')->andReturn([['suggest' => 'typo', 'distance' => 0, 'docs' => 1]]);
        $this->get('/search?q=typo')->assertOk()->assertSee('Showing 1–1 of 1 release')->assertDontSee('Did you mean');
        $this->get('/search?t=2000&maxage=1')->assertOk()->assertDontSee('Did you mean');
        config(['nntmux.mysql_search_fallback' => false]);
        $this->get('/search?q=dnue')->assertOk()->assertSee('Search unavailable')->assertDontSee('Did you mean');
        config(['nntmux.mysql_search_fallback' => true]);
        // the row is always there and keeps its height, so the pager line does not move when the suggestion appears
        $css = (string) file_get_contents(resource_path('css/tv.css'));
        $this->assertStringContainsString('.tv-query-row { display: flex; min-height: 32px; margin-top: 12px; }', $css);
        $this->assertStringContainsString('.tv-query-suggestion { margin-left: 6px; color: var(--tv-dim); font-size: 14px; line-height: 32px; white-space: nowrap; }', $css);
        $this->assertStringContainsString('data-query-row', (string) $this->get('/search')->getContent());
    }

    public function test_movie_field_keys_and_free_text_are_sent_together_to_the_release_index(): void
    {
        $wanted = $this->release('Selected film', ['imdbid' => '0123456']);
        app(SearchServiceInterface::class)->shouldReceive('searchEntityFields')->once()
            ->with('movies', ['actors' => '"Hugh Jackman"', 'director' => 'scorsese'], 'imdbid', 500, 0)
            ->andReturn(['ids' => [987], 'keys' => ['0123456'], 'available' => true, 'has_more' => false]);
        app(SearchServiceInterface::class)->shouldReceive('searchReleasesFiltered')->once()
            ->withArgs(static fn (array $criteria): bool => $criteria['entity_filters'] === ['imdbid' => ['0123456']] && $criteria['phrases'] === ['searchname' => '1080p -cam'])
            ->andReturn(['ids' => [$wanted], 'total' => 1, 'available' => true]);
        $this->actingAs($this->browserUser())->get('/search?'.http_build_query(['q' => '1080p -cam actor:"Hugh Jackman" director:scorsese']))->assertOk()->assertSee('Selected film');
    }

    public function test_fuzzy_matching_only_runs_after_all_exact_results_are_empty(): void
    {
        $wanted = $this->release('Fuzzy match');
        $search = app(SearchServiceInterface::class);
        $search->shouldReceive('isFuzzyEnabled')->andReturn(true);
        $search->shouldReceive('searchReleasesFiltered')->once()->withArgs(static fn (array $criteria): bool => ! $criteria['web_force_fuzzy'])
            ->andReturn(['ids' => [], 'total' => 0, 'fuzzy' => false, 'available' => true]);
        $search->shouldReceive('searchReleasesFiltered')->once()->withArgs(static fn (array $criteria): bool => $criteria['web_force_fuzzy'])
            ->andReturn(['ids' => [$wanted], 'total' => 1, 'fuzzy' => true, 'available' => true, 'has_more' => false]);
        $response = $this->actingAs($this->browserUser())->get('/search?q=typo')->assertOk();
        $this->assertSame([$wanted], $this->listedIds((string) $response->getContent()));
    }

    public function test_movie_index_pages_and_sql_fallback_preserve_structured_matching(): void
    {
        DB::table('movieinfo')->insert([
            ['id' => 1, 'imdbid' => '1234567', 'title' => 'First movie', 'actors' => 'Different spelling'],
            ['id' => 2, 'imdbid' => '7654321', 'title' => 'Second movie', 'actors' => 'Different spelling'],
        ]);
        $first = $this->release('First indexed movie', ['imdbid' => '1234567']);
        $second = $this->release('Second indexed movie', ['imdbid' => '7654321']);
        $search = app(SearchServiceInterface::class);
        $search->shouldReceive('searchEntityFields')->once()->with('movies', ['actors' => 'Chalamet'], 'imdbid', 500, 0)
            ->andReturn(['ids' => [1, 2], 'keys' => ['1234567', '7654321'], 'available' => true, 'has_more' => false]);
        $response = $this->actingAs($this->browserUser())->get('/search?q=actor:Chalamet')->assertOk()
            ->assertSee('<h1 data-part="page title">Search results</h1>', false);
        $this->assertEqualsCanonicalizing([$first, $second], $this->listedIds((string) $response->getContent()));
    }

    public function test_movie_index_outage_resolves_the_same_entity_keys_from_sql(): void
    {
        DB::table('movieinfo')->insert(['imdbid' => '0123456', 'title' => 'Film', 'actors' => 'Hugh Jackman', 'director' => 'Martin Scorsese']);
        $wanted = $this->release('Indexed film', ['imdbid' => '0123456']);
        app(SearchServiceInterface::class)->shouldReceive('searchEntityFields')->once()
            ->andReturn(['ids' => [], 'keys' => [], 'available' => false, 'has_more' => false]);
        app(SearchServiceInterface::class)->shouldReceive('searchReleasesFiltered')->once()
            ->withArgs(static fn (array $criteria): bool => $criteria['entity_filters'] === ['imdbid' => ['0123456']] && $criteria['phrases'] === ['searchname' => '1080p -cam'])
            ->andReturn(['ids' => [$wanted], 'total' => 1, 'available' => true]);
        $this->actingAs($this->browserUser())->get('/search?'.http_build_query(['q' => '1080p -cam actor:"Hugh Jackman" director:scorsese']))->assertOk()->assertSee('Indexed film');
    }

    public function test_registered_show_prefix_resolves_entity_keys_without_release_candidates(): void
    {
        $registry = app(WebSearchFields::class);
        $registry->register('show', 'tvshows', 'title', 'videos', 'id', 'videos_id');
        $wanted = $this->release('Unrecognizable title', ['videos_id' => 42]);
        app(SearchServiceInterface::class)->shouldReceive('searchEntityFields')->with('tvshows', ['title' => 'Expanse'], 'id', 500, 0)->once()
            ->andReturn(['ids' => [42], 'keys' => [42], 'available' => true, 'has_more' => false]);
        app(SearchServiceInterface::class)->shouldReceive('searchReleasesFiltered')->once()
            ->withArgs(static fn (array $criteria): bool => $criteria['entity_filters'] === ['videos_id' => [42]])
            ->andReturn(['ids' => [$wanted], 'total' => 1, 'available' => true]);
        $this->actingAs($this->browserUser())->get('/search?q=show:Expanse')->assertOk()->assertSee('Unrecognizable title');
    }

    /** Issue #1050 section 4: the feed dialog in the redesigned lists' dialog form, its content unchanged. */
    public function test_rss_for_this_search_opens_a_dialog_with_the_existing_feed_url_and_names_the_website_only_filters(): void
    {
        $user = $this->browserUser();
        $response = $this->actingAs($user)->get('/search?q=dune%20actor:Chalamet&t=2000&minsize=100&maxsize=600&maxage=30&minc=95&poster=someone')->assertOk();
        $html = (string) $response->getContent();
        $url = $response->viewData('searchState')->rssUrl($user);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parameters);
        $this->assertSame(['t' => 'search', 'apikey' => $user->api_token, 'o' => 'xml', 'q' => 'dune', 'cat' => '2000', 'maxage' => '30', 'minsize' => '104857600'], $parameters);
        // the button sits in the heading row before the sort menu and opens the dialog
        $response->assertSeeInOrder(['<span class="tv-grow"></span>', '<button type="button" class="tv-feed-button" data-search-feed-button x-on:click="$dispatch(\'search-feed-open\')"><i class="fas fa-rss" aria-hidden="true"></i>RSS for this search</button>', 'data-part="sort dropdown"'], false);
        $dialog = $this->between($html, 'x-data="searchFeed"', 'class="pager-line');
        $this->assertStringContainsString('x-on:search-feed-open.window="openFeed"', $html);
        foreach (['class="tv-scrim"', 'role="dialog" aria-modal="true" aria-labelledby="search-feed-dialog-title"', '<h2 id="search-feed-dialog-title"', 'RSS for this search',
            '<p>Copy this URL into your feed reader. Feeds search release names and include the selected category, maximum age and minimum size.</p>',
            '<p class="tv-feed-only" data-website-only>These filters apply on the website only: actor: Chalamet, Size ≤ 600 MB, Poster: someone, Completion ≥ 95%.</p>',
            '<dt>Feed URL</dt><dd class="is-code" data-feed-url x-ref="feedUrl">'.e($url).'</dd>', 'data-copy-feed x-ref="copy" x-on:click="copyFeed"', '<span class="tv-copy-words"><span>Copy</span><span>Copied</span></span>',
            'href="'.e($url).'"><i class="fas fa-rss" aria-hidden="true"></i>Open RSS feed</a>', 'title="Close (Esc)" aria-label="Close"'] as $part) {
            $this->assertStringContainsString($part, $dialog);
        }
        $this->assertSame($url, $this->feedUrl($html));
        // Imported only and a chosen threshold are named as website-only; nothing is named when every filter travels in the feed
        $this->get('/search?q=dune&completion=imported&minage=2')->assertOk()->assertSee('These filters apply on the website only: Age ≥ 2 d, Imported only.');
        $this->get('/search?q=dune&completion=100')->assertOk()->assertSee('These filters apply on the website only: Completion ≥ 100%.');
        $this->get('/search?q=dune&t=2000&maxage=30&minsize=100&group=alt.binaries.none')->assertOk()->assertDontSee('These filters apply on the website only');
        // the dialog travels with the list fragment, so its URL follows the cells; the button's width holds while it reads Copied and the URL wraps
        $fragment = (string) $this->get('/search?q=dune&t=5000&_fragment=list')->assertOk()->getContent();
        parse_str((string) parse_url($this->feedUrl($fragment), PHP_URL_QUERY), $changed);
        $this->assertSame('5000', $changed['cat']);
        $css = (string) file_get_contents(resource_path('css/tv.css'));
        foreach (['.tv-copy-words { display: grid; }', '.tv-copy-words > span { grid-area: 1 / 1; }', '.tv-copy-words > span:last-child, .is-copied .tv-copy-words > span:first-child { visibility: hidden; }', 'overflow-wrap: anywhere; }'] as $rule) {
            $this->assertStringContainsString($rule, $css);
        }
    }

    public function test_search_matches_album_artists_and_anime_titles_and_honors_permissions(): void
    {
        Schema::create('musicinfo', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->string('artist');
            $table->string('year')->nullable();
            $table->boolean('cover')->default(false);
        });
        ProductionTables::fromAuthority()->create('anidb_info', ['anidbid', 'startdate']);
        ProductionTables::fromAuthority()->create('anidb_titles');
        DB::table('musicinfo')->insert(['id' => 1, 'title' => 'In Rainbows', 'artist' => 'Radiohead']);
        DB::table('anidb_info')->insert(['anidbid' => 12]);
        DB::table('anidb_titles')->insert(['anidbid' => 12, 'title' => 'Radiohead Adventure', 'lang' => 'en', 'type' => 'main']);
        $audio = $this->release('Obfuscated album', ['categories_id' => 3030, 'musicinfo_id' => 1]);
        $anime = $this->release('Obfuscated anime', ['categories_id' => 5030, 'anidbid' => 12]);
        $user = $this->browserUser();
        $response = $this->actingAs($user)->get('/search?q=Radiohead')->assertOk();
        $this->assertEqualsCanonicalizing([$audio, $anime], $this->listedIds((string) $response->getContent()));
        $this->get('/search?q=Radiohead&t=audio')->assertOk()->assertSee('Obfuscated album')->assertDontSee('Obfuscated anime');
        $user->revokePermissionTo('view audio');
        Cache::forget(User::categoryExclusionCacheKey($user->id));
        $this->get('/search?q=Radiohead')->assertOk()->assertDontSee('Obfuscated album')->assertSee('Obfuscated anime');
    }

    public function test_legacy_inputs_keep_constraints_and_the_old_sort_inputs_are_no_longer_read(): void
    {
        DB::table('movieinfo')->insert(['imdbid' => '1234567', 'title' => 'Dune Part Two']);
        $old = $this->release('A release', ['imdbid' => '1234567', 'postdate' => '2026-09-10 00:00:00']);
        $new = $this->release('Z release', ['imdbid' => '1234567', 'postdate' => '2026-09-13 00:00:00']);
        $this->release('Wrong title', ['imdbid' => '7654321']);
        $parameters = ['q' => 'Dune', 'search' => 'ignored', 'search_type' => 'adv', 'title' => 'Part Two', 'searchadvcat' => '2000', 'sort' => 'oldest', 'ob' => 'name_asc'];
        $response = $this->actingAs($this->browserUser())->get('/search?'.http_build_query($parameters))->assertOk();
        $html = (string) $response->getContent();
        $this->assertSame([$new, $old], $this->listedIds($html), 'Posted: newest first, the list\'s default, whatever sort and ob say');
        $this->assertSame(['all' => '“Dune”', 'title' => 'title: Part Two'], array_column($this->chips($html), 'label', 'key'));
        $this->assertSame(['2000'], $this->tickedRoots($html), 'the legacy advanced search category is the scope');
        $response->assertDontSee('Advanced Search')->assertDontSee('ignored');
        $this->assertSame(['q' => 'Dune title:(Part Two)', 't' => '2000'], $response->viewData('searchState')->parameters);
        $this->get('/search?'.http_build_query(['q' => ['bad'], 't' => ['2000'], 'maxage' => ['bad'], 'minsize' => '-1', 'page' => ['1'], 'completion' => ['95']]))->assertOk();
    }

    public function test_entity_matches_do_not_bypass_exclusions_or_change_free_text_or_semantics(): void
    {
        DB::table('movieinfo')->insert([
            ['imdbid' => '1111111', 'title' => 'Dune'], ['imdbid' => '2222222', 'title' => 'Arrival'], ['imdbid' => '3333333', 'title' => 'Dune Arrival'],
        ]);
        $dune = $this->release('Unknown one', ['imdbid' => '1111111']);
        $arrival = $this->release('Unknown two', ['imdbid' => '2222222']);
        $both = $this->release('Unknown three', ['imdbid' => '3333333']);
        $this->release('Dune.CAM', ['imdbid' => '1111111']);
        $response = $this->actingAs($this->browserUser())->get('/search?q=dune%20-cam')->assertOk();
        $this->assertEqualsCanonicalizing([$dune, $both], $this->listedIds((string) $response->getContent()));
        $response = $this->get('/search?q='.rawurlencode('(dune | arrival) -(cam | ts)'))->assertOk();
        $this->assertEqualsCanonicalizing([$dune, $arrival, $both], $this->listedIds((string) $response->getContent()));
    }

    public function test_index_retrieval_applies_server_filters_for_each_page(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 14)->startOfDay());
        DB::table('usenet_groups')->insert(['id' => 2, 'name' => 'alt.binaries.movies']);
        $wanted = $this->release('A lexical match', ['groups_id' => 2, 'size' => 200 * 1024 * 1024, 'postdate' => now()->subDays(10)]);
        $parameters = ['q' => 'missing-literal', 't' => 2000, 'cat' => 2030, 'group' => 'alt.binaries.movies', 'minage' => 1, 'maxage' => 30, 'minsize' => 100, 'maxsize' => 600, 'minc' => 95];
        app(SearchServiceInterface::class)->shouldReceive('searchReleasesFiltered')->twice()->withArgs(static fn (array $criteria): bool => $criteria['category_ids'] === [2030] && $criteria['groups_id'] === 2 && $criteria['min_size'] === 104857600 && $criteria['max_size'] === 629145600 && $criteria['min_completion'] === 95 && $criteria['min_date'] === now()->subDays(30)->timestamp && $criteria['max_date'] === now()->subDay()->timestamp)
            ->andReturn(['ids' => [$wanted], 'total' => 1, 'fuzzy' => false, 'available' => true, 'has_more' => false]);
        $html = (string) $this->actingAs($this->browserUser())->get('/search?'.http_build_query($parameters))->assertOk()->assertSee('A lexical match')->getContent();
        // a Group chip still shows, last but for the poster's, when a search arrives with one
        $this->assertSame(['all', 'cat', 'minage', 'maxage', 'minsize', 'maxsize', 'group'], array_column($this->chips($html), 'key'));
        $this->assertSame('Group: alt.binaries.movies', $this->chips($html)[6]['label']);
        $this->get('/search?'.http_build_query([...$parameters, 'per' => 24, 'sort' => 'title']))->assertOk()->assertSee('A lexical match');
    }

    /** Issue #1050 section 5: search rows show the Imported chip through the shared chip line. */
    public function test_a_search_result_of_an_imported_release_ends_its_own_chips_with_the_imported_chip(): void
    {
        DB::table('usenet_groups')->insert(['id' => 2, 'name' => 'alt.binaries.movies']);
        $this->release('Imported search result', ['is_imported' => 1, 'nfostatus' => 1, 'jpgstatus' => 1, 'groups_id' => 2, 'fromname' => 'poster@example.invalid']);
        $this->release('Imported lone search result', ['is_imported' => 1, 'nfostatus' => 0]);
        $this->release('Indexed search result', ['nfostatus' => 1]);

        $html = (string) $this->actingAs($this->browserUser())->get('/search?'.http_build_query(['q' => 'search result']))->assertOk()->getContent();
        $row = $this->releaseRowNamed($html, 'Imported search result');
        $this->assertImportedChip($row, 'A search result');
        $this->assertImportedChipPlaced($row, ['nfo-badge', 'sample-badge'], ['title="All releases in alt.binaries.movies"', 'title="All posts by poster@example.invalid"'], 'A search result');
        $this->assertLoneImportedChipLine($this->releaseRowNamed($html, 'Imported lone search result'), 'A search result with no other chip');
        $this->assertNoImportedChip($this->releaseRowNamed($html, 'Indexed search result'), 'A search result that was not imported');
        $this->assertStringNotContainsString('release-browser', $html);
    }

    /** Issue #1050 section 2: the picture column is on the search results. */
    public function test_search_results_show_the_picture_column(): void
    {
        DB::table('movieinfo')->insert(['id' => 7, 'imdbid' => '0137523', 'title' => 'Poster Film', 'year' => '1999']);
        $this->release('Pictured film', ['imdbid' => '0137523', 'movieinfo_id' => 7]);
        $this->release('Pictured preview', ['categories_id' => 6030, 'haspreview' => 1]);
        $this->release('Pictured nothing', ['categories_id' => 4030]);
        foreach (['movies/0137523-cover', 'preview/'.md5('Pictured preview').'_thumb'] as $file) {
            File::ensureDirectoryExists(dirname(config('nntmux_settings.covers_path').'/'.$file));
            File::put(config('nntmux_settings.covers_path').'/'.$file.'.jpg', 'jpg');
        }
        $html = (string) $this->actingAs($this->browserUser())->get('/search?q=pictured')->assertOk()
            ->assertSee('<col class="tv-col-select"><col class="tv-col-art"><col>', false)->assertSee('<th colspan="2">Release</th>', false)->getContent();
        $this->assertMatchesRegularExpression('/<td class="tv-art">\s*<a href="'.preg_quote(route('movies.film', ['movieinfoId' => 7]), '/').'" tabindex="-1" aria-hidden="true" data-title="Poster Film">\s*<img src="'.preg_quote(url('/covers/movies/0137523-cover.jpg'), '/').'"/', $this->releaseRowNamed($html, 'Pictured film'));
        $this->assertMatchesRegularExpression('/<td class="tv-art is-picture">\s*<a href="[^"]+" tabindex="-1" aria-hidden="true" data-picture="preview" title="View the image preview">/', $this->releaseRowNamed($html, 'Pictured preview'));
        // the picture opens the image dialog through the row's Preview chip, as the Adult list's does (tvImageDialog)
        $this->assertStringContainsString('preview-badge', $this->releaseRowNamed($html, 'Pictured preview'));
        $this->assertMatchesRegularExpression('/<td class="tv-art">\s*<\/td>/', $this->releaseRowNamed($html, 'Pictured nothing'));
    }

    /** Issue #1056 Section C6: the results page is not handed the viewer's last visit, which nothing on it reads. */
    public function test_a_search_is_not_handed_the_viewers_last_visit(): void
    {
        $this->release('Dune visited');
        $this->actingAs($this->browserUser())->get('/search?q=Dune')->assertOk()->assertSee('Dune visited')
            ->assertViewMissing('lastvisit')->assertViewHas('subCategories');
    }

    /** @return list<int> the listed releases' ids, in the page's order */
    private function listedIds(string $html): array
    {
        preg_match_all('/data-select value="([0-9a-f]{32})"/', $html, $matches);
        $ids = DB::table('releases')->whereIn('guid', $matches[1])->pluck('id', 'guid');

        return array_map(static fn (string $guid): int => (int) $ids[$guid], $matches[1]);
    }

    /** @return list<string> the listed releases' names, A to Z, or in the page's order */
    private function listedNames(string $html, bool $sorted = true): array
    {
        $names = DB::table('releases')->whereIn('id', $this->listedIds($html))->pluck('name', 'id');
        $listed = array_map(static fn (int $id): string => (string) $names[$id], $this->listedIds($html));
        if ($sorted) {
            sort($listed);
        }

        return $listed;
    }

    /** @return list<array{key: string, label: string, url: string}> the query row's chips, in order */
    private function chips(string $html): array
    {
        preg_match_all('/<a class="tv-query-chip" href="([^"]*)" data-remove-constraint="([^"]*)"[^>]*><span class="tv-query-chip-label">(.*?)<\/span>/s', $html, $matches, PREG_SET_ORDER);

        return array_map(static fn (array $match): array => ['key' => $match[2], 'label' => html_entity_decode($match[3], ENT_QUOTES), 'url' => html_entity_decode($match[1], ENT_QUOTES)], $matches);
    }

    /** @return array<string, string> the Category cell's options, value => label, in menu order */
    private function categoryMenu(string $html): array
    {
        preg_match_all('/data-value="([^"]*)" data-text="([^"]*)"/', $this->between($html, 'data-name="category"', 'data-name="completion"'), $matches);

        return array_combine($matches[1], $matches[2]);
    }

    /** @return list<string> the roots the Category cell ticks */
    private function tickedRoots(string $html): array
    {
        preg_match_all('/data-value="([^"]*)" data-text="[^"]*" aria-checked="true"/', $this->between($html, 'data-name="category"', 'data-name="completion"'), $matches);

        return $matches[1];
    }

    /** @return list<array{string, string}> the Completion cell's items: value and text */
    private function completionMenu(string $html): array
    {
        $menu = (string) strstr((string) strstr($html, 'data-name="completion"'), '</div>', true);
        preg_match_all('/role="menuitemradio"(?: data-any| data-value="([^"]*)" data-text="[^"]*")[^>]*>(?:<span[^>]*><\/span>)?([^<]*)</', $menu, $items, PREG_SET_ORDER);

        return array_map(static fn (array $item): array => [$item[1], trim($item[2])], $items);
    }

    /** What a filter cell reads. */
    private function cellText(string $html, string $name): string
    {
        $this->assertSame(1, preg_match('/<span class="checkbox-menu-value[^"]*" x-ref="value">([^<]*)<\/span>/', (string) strstr($html, 'data-name="'.$name.'"'), $match));

        return html_entity_decode($match[1], ENT_QUOTES);
    }

    /** @return array{string, string} the header scope picker's label and the scope its search form sends */
    private function headerScope(string $html): array
    {
        $this->assertSame(1, preg_match('/<input type="hidden" name="t" value="([^"]*)" x-ref="searchScope">/', $html, $value));
        $this->assertSame(1, preg_match('/<span x-ref="scopeLabel">([^<]*)<\/span>/', $html, $label));

        return [$label[1], $value[1]];
    }

    /** @return array<string, string> + Add filter's kinds, kind => name, in menu order */
    private function addFilterKinds(string $html): array
    {
        preg_match_all('/role="menuitem" data-kind="([^"]*)" x-on:click="pick">([^<]*)</', $html, $matches);

        return array_combine($matches[1], $matches[2]);
    }

    /** The form (or list) + Add filter shows for a kind. */
    private function filterForm(string $html, string $kind): string
    {
        $this->assertSame(1, preg_match('/<div class="tv-add-filter-form[^"]*" role="dialog" aria-label="[^"]*" data-filter-form="'.$kind.'" x-show="kind === \''.$kind.'\'" x-cloak>(.*?)(?=<div class="tv-add-filter-form|<\/div>\s*<\/span>)/s', $html, $match), 'No form for '.$kind);

        return $match[1];
    }

    /** @return array<string, string> the Sub-category list's choices, id => "Root > Sub", in order */
    private function subCategories(string $form): array
    {
        preg_match_all('/data-sub-category="([^"]*)" data-text="([^"]*)"/', $form, $matches);

        return array_map(static fn (string $text): string => html_entity_decode($text, ENT_QUOTES), array_combine($matches[1], $matches[2]));
    }

    /** The feed URL the dialog shows. */
    private function feedUrl(string $html): string
    {
        $this->assertSame(1, preg_match('/data-feed-url x-ref="feedUrl">([^<]*)<\/dd>/', $html, $match));

        return html_entity_decode($match[1], ENT_QUOTES);
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
}
