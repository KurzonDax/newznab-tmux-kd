<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\GenericReleaseFilters;
use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\User;
use App\Services\NNTP\NntpProviderPool;
use App\Services\Releases\ReleaseRowFacts;
use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\SearchService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\AssertsFollowWording;
use Tests\Support\AssertsNoRetiredAddress;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\InteractsWithSecondaryProviders;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

final class ReleaseBrowserControllerTest extends TestCase
{
    use AssertsFollowWording;
    use AssertsNoRetiredAddress;
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use InteractsWithSecondaryProviders;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->withoutMiddleware(TrustedDevice2FAMiddleware::class);
        $this->createReleaseSchema();
        foreach ([1000 => 'Console', 2000 => 'Movies', 3000 => 'Audio', 4000 => 'PC', 5000 => 'TV', 6000 => 'XXX', 7000 => 'Books', 1 => 'Other'] as $id => $title) {
            DB::table('root_categories')->updateOrInsert(['id' => $id], ['title' => $title]);
            DB::table('categories')->insert(['id' => $id + 30, 'title' => 'HD', 'root_categories_id' => $id]);
        }
        foreach (['2026_08_21_090000_create_release_audio_tags_table', '2026_08_27_150100_create_release_video_clips_table'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
    }

    protected function tearDown(): void
    {
        NntpProviderPool::forgetConfiguredProviders();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_browse_renders_video_summary_with_release_id_as_the_video_primary_key(): void
    {
        Schema::create('video_data', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id')->primary();
            foreach (['containerformat', 'overallbitrate', 'videoduration', 'videoformat', 'videocodec', 'videoaspect', 'videolibrary'] as $column) {
                $table->string($column)->nullable();
            }
            foreach (['videowidth', 'videoheight', 'videoframerate'] as $column) {
                $table->unsignedInteger($column)->nullable();
            }
        });
        $releaseId = $this->release('Video summary regression');
        DB::table('video_data')->insert([
            'releases_id' => $releaseId, 'videoheight' => 1080, 'videocodec' => 'x264', 'videoformat' => 'AVC',
        ]);

        // The generic list's media info chip reads the summary built on the release id (the resolution has its own place, the codec in plain words).
        $response = $this->actingAs($this->browserUser())->get('/browse/all')->assertOk()->assertSee('Video summary regression');
        $row = $this->browserRow($response->getContent(), 'Video summary regression');
        $this->assertMatchesRegularExpression('/mediainfo-badge[^>]*data-release-id="'.$releaseId.'"[^>]*>\s*<i class="fas fa-circle-info" aria-hidden="true"><\/i>\s*H\.264\s*<\/button>/', $row);
    }

    public function test_without_a_secondary_provider_no_row_shows_the_pending_chip(): void
    {
        $this->release('Above target', ['completion' => 99]);
        $this->release('No verdict', ['completion' => 80]);

        $response = $this->actingAs($this->browserUser())->get('/browse/all')->assertOk();
        $above = $this->browserRow($response->getContent(), 'Above target');
        $this->assertMatchesRegularExpression('/>\s*99% complete\s*</', $above);
        $this->assertStringNotContainsString('late headers pending', $above);
        $this->assertStringNotContainsString('repair-badge', $above);
        $noVerdict = $this->browserRow($response->getContent(), 'No verdict');
        $this->assertMatchesRegularExpression('/>\s*80% complete\s*</', $noVerdict);
        $this->assertStringNotContainsString('late headers pending', $noVerdict);
        $this->assertStringNotContainsString('repair-badge', $noVerdict);
    }

    public function test_a_secondary_provider_still_reading_the_post_keeps_the_repair_chip_pending(): void
    {
        $this->configureSecondaryProvider();
        ProductionTables::fromAuthority()->create('usenet_group_provider_cursors');
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.test', 'active' => 1]);
        $this->release('Above target', ['completion' => 99, 'groups_id' => 1, 'postdate' => '2026-09-12 23:30:00']);
        $this->secondaryPosition(1, '2026-09-13 00:30:00');

        $response = $this->actingAs($this->browserUser())->get('/browse/all')->assertOk();
        $this->assertMatchesRegularExpression('/>\s*99% complete · late headers pending\s*</', $this->browserRow($response->getContent(), 'Above target'));
    }

    public function test_release_facts_are_a_separate_block_after_the_complete_name(): void
    {
        $this->release('A short name', ['nfostatus' => 1]);
        $response = $this->actingAs($this->browserUser())->get('/browse/all')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        // the chip line follows the complete name as its own block; no chip sits inside the name
        $this->assertSame(1, $xpath->query('//a[contains(@class,"tv-release-name")]/following-sibling::div[contains(@class,"tv-chips")]')->length);
        $this->assertSame(0, $xpath->query('//a[contains(@class,"tv-release-name")]//*[contains(@class,"release-chip")]')->length);
        $this->assertSame('A short name', trim($xpath->query('//a[contains(@class,"tv-release-name")]')->item(0)->textContent));
    }

    public function test_five_release_sorts_have_distinct_meanings_and_grabs_is_gone(): void
    {
        $this->release('Zulu', ['postdate' => '2026-09-13', 'adddate' => '2026-09-10', 'grabs' => 2]);
        $this->release('Alpha', ['postdate' => '2026-09-11', 'adddate' => '2026-09-12', 'grabs' => 8]);
        $this->release('Middle', ['postdate' => '2026-09-12', 'adddate' => '2026-09-11', 'grabs' => 1]);
        $user = $this->browserUser();
        $this->actingAs($user);
        foreach (['posted' => 'Zulu', 'posted_oldest' => 'Alpha', 'newest' => 'Alpha', 'oldest' => 'Zulu', 'title' => 'Alpha'] as $sort => $first) {
            $this->postJson('/profile/update-view', ['root' => 'all', 'sort' => $sort])->assertOk();
            $response = $this->actingAs(User::query()->findOrFail($user->id))->get('/browse/all')->assertOk();
            $this->assertSame($first, $response->viewData('rows')[0]->name, $sort);
            $response->assertSee('<option value="'.$sort.'" selected', false);
        }
        $this->assertSame(['posted', 'posted_oldest', 'newest', 'oldest', 'title'], array_keys(GenericReleaseFilters::SORTS));
        foreach (['grabs', 'year', 'rating', 'artist', 'size', 'files'] as $removed) {
            $this->postJson('/profile/update-view', ['root' => 'all', 'sort' => $removed])->assertUnprocessable();
        }
        // ?sort= is not a list control: the remembered sort stands
        $this->assertSame('Alpha', $this->get('/browse/all?sort=posted')->assertOk()->viewData('rows')[0]->name);
    }

    public function test_group_filter_counts_all_matching_rows_and_bounds_the_page(): void
    {
        $user = $this->browserUser();
        DB::table('usenet_groups')->insert([['id' => 1, 'name' => 'alt.binaries.movies'], ['id' => 2, 'name' => 'alt.binaries.movies.other']]);
        for ($index = 1; $index <= 51; $index++) {
            $this->release('Movie '.$index, ['groups_id' => 1]);
        }
        $this->release('Outside group', ['groups_id' => 2]);

        $response = $this->actingAs($user)->get('/browse/all?group=alt.binaries.movies&page=2')->assertOk();
        $this->assertSame(51, $response->viewData('total'));
        $this->assertCount(1, $response->viewData('rows'));
        $this->assertSame(2, $response->viewData('filters')->page);
        $response->assertSee('Releases in alt.binaries.movies')->assertSee('Showing 51–51 of 51 releases')->assertDontSee('Outside group');
        $this->get('/browse/all?group=alt.binaries.movies&page=999')
            ->assertRedirect(route('browse.all', ['group' => 'alt.binaries.movies', 'page' => 2]));
    }

    public function test_poster_identity_filter_is_byte_exact_including_spaces_and_case(): void
    {
        $identity = ' Sender <person+tag@Host.test> ';
        $this->release('Exact identity', ['fromname' => $identity]);
        $this->release('Trimmed identity', ['fromname' => trim($identity)]);
        $this->release('Case variant', ['fromname' => strtolower($identity)]);
        $this->release('Extended identity', ['fromname' => $identity.'suffix']);

        $response = $this->actingAs($this->browserUser())->get('/browse/all?poster='.rawurlencode($identity))->assertOk();
        $this->assertSame(1, $response->viewData('total'));
        $response->assertSee('<title>Posts by '.e($identity), false)->assertSee('Exact identity')
            ->assertDontSee('Trimmed identity')->assertDontSee('Case variant')->assertDontSee('Extended identity');
    }

    public function test_canonical_roots_and_numeric_subcategories_never_fall_back_to_all_releases(): void
    {
        // the Other list reads Misc and Hashed (10 and 20), the categories the Other root holds
        DB::table('categories')->insert(['id' => 10, 'title' => 'Misc', 'root_categories_id' => 1]);
        $roots = ['other' => 10];
        foreach ($roots as $root => $categoryId) {
            $this->release($root.' release', ['categories_id' => $categoryId]);
        }
        $this->actingAs($this->browserUser());
        foreach ($roots as $root => $categoryId) {
            $this->get('/browse/'.$root.'/'.$categoryId)->assertRedirect(route('browse', ['parentCategory' => $root, 'category' => [$categoryId]]));
            foreach (['/browse/'.$root, route('browse', ['parentCategory' => $root, 'category' => [$categoryId]])] as $url) {
                $response = $this->get($url)->assertOk();
                $this->assertSame(1, $response->viewData('total'), $url);
                $response->assertSee($root.' release');
            }
        }
        foreach (['/browse/console', '/browse/console/1030', '/browse/games', '/browse/games/4030', '/browse/pc', '/browse/books', '/browse/books/7030', '/browse/audio', '/browse/audio/3030', '/browse/music'] as $url) {
            $this->get($url)->assertNotFound();
        }
        $this->get('/browse/tv')->assertRedirect(route('tv.releases'));
        $this->get('/browse/tv/5030')->assertRedirect(route('tv.releases', ['category' => [5030]]));
        $this->get('/browse/movies')->assertRedirect(route('movies.releases'));
        $this->get('/browse/movies/2030')->assertRedirect(route('movies.releases', ['category' => [2030]]));
        $this->get('/browse/movies/HD')->assertRedirect(route('movies.releases', ['category' => [2030]]));
        $this->get('/browse/movies?watching=1')->assertRedirect(route('movies.releases'));
        $this->get('/browse/movies/3030')->assertNotFound();
        $this->get('/browse/movies/9999')->assertNotFound();
        $this->get('/browse/xxx')->assertNotFound();
        $this->get('/browse/xxx/6030')->assertNotFound();
        $this->get('/browse/not-a-root')->assertNotFound();
    }

    public function test_retired_movie_pages_are_not_found(): void
    {
        $this->actingAs($this->browserUser());
        foreach (['/trending-movies', '/movie/0111161', '/movie/tt0111161', '/Movies', '/Movies/HD', '/mymovies', '/mymovies/browse', '/title/movies/0111161'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_retired_adult_pages_are_not_found(): void
    {
        $this->release('Adult release', ['categories_id' => 6030]);
        $this->actingAs($this->browserUser());
        foreach (['/XXX', '/XXX/HD%20Clips', '/browse/xxx', '/browse/adult'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_retired_book_console_and_pc_pages_are_not_found(): void
    {
        $this->actingAs($this->browserUser());
        foreach ([
            '/Books', '/Books/HD', '/Console', '/Console/HD', '/Games',
            '/browse/books', '/browse/books/7030', '/browse/books/All', '/browse/console', '/browse/console/HD',
            '/browse/games', '/browse/games/4030', '/browse/pc', '/browse/pc/HD',
            '/title/books/12', '/title/console/12', '/title/games/12', '/title/pc/12',
        ] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_retired_audio_pages_are_not_found(): void
    {
        DB::table('categories')->insert(['id' => 10, 'title' => 'Misc', 'root_categories_id' => 1]);
        $this->release('Other release', ['categories_id' => 10]);
        $this->actingAs($this->browserUser());
        foreach ([
            '/Audio', '/Audio/HD', '/browse/audio', '/browse/audio/3030', '/browse/audio/HD', '/browse/audio/All',
            '/browse/music', '/browse/Music/3030', '/title/audio/12',
            '/browse/audio?view=covers&letter=A', '/browse/audio?view=covers&_fragment=cover&cover=12',
        ] as $path) {
            $this->get($path)->assertNotFound();
        }
        foreach (['/browse/other', route('browse', ['parentCategory' => 'other', 'category' => [10]])] as $path) {
            $this->get($path)->assertOk()->assertSee('<table class="tv-feed is-shelf is-generic is-other"', false);
        }
        $this->get('/browse/other/10')->assertRedirect(route('browse', ['parentCategory' => 'other', 'category' => [10]]));
        $this->get('/browse/other?clear=1')->assertRedirect(route('browse', ['parentCategory' => 'other']));
        $this->get('/browse/other?view=covers&letter=A&year=1975&label=X')->assertOk()
            ->assertSee('<table class="tv-feed is-shelf is-generic is-other"', false)->assertDontSee('aria-label="Jump by initial"', false)
            ->assertDontSee('data-cover-tile', false)->assertDontSee('data-year-picker', false);
        $this->get('/browse/other?_fragment=cover&cover=12')->assertOk()->assertSee('<table class="tv-feed is-shelf is-generic is-other"', false);
    }

    public function test_retired_browse_pages_still_show_the_denied_page_to_a_user_without_the_root(): void
    {
        $user = $this->browserUser();
        $this->actingAs($user);
        foreach (['console' => ['/browse/console' => 'Console'], 'books' => ['/browse/books' => 'Books'], 'pc' => ['/browse/games' => 'PC', '/browse/pc' => 'PC']] as $missing => $paths) {
            $user->revokePermissionTo('view '.$missing);
            foreach ($paths as $path => $name) {
                $this->get($path)->assertForbidden()->assertViewIs('errors.category-disabled')->assertViewHas('category', $name)->assertSee($name);
            }
            $user->givePermissionTo('view '.$missing);
        }
    }

    public function test_search_and_sort_apply_before_pagination_and_the_old_browser_preferences_stay_accepted(): void
    {
        $user = $this->browserUser();
        // home, search and basket still read view, per and thumbs under all; the list itself ignores them
        $this->actingAs($user)->postJson('/profile/update-view', ['root' => 'all', 'per' => 24, 'thumbs' => true, 'sort' => 'title'])->assertOk();
        for ($index = 30; $index >= 1; $index--) {
            $this->release(sprintf('Wanted %02d', $index));
        }
        $this->release('Unwanted match', ['display_name' => 'Outside']);
        $this->release('Encoded name', ['display_name' => 'Wanted 00']);

        $response = $this->actingAs(User::query()->findOrFail($user->id))->get('/browse/all?q=Wanted')->assertOk();
        $rows = $response->viewData('rows');
        $this->assertSame(31, $response->viewData('total'));
        $this->assertCount(31, $rows);
        $this->assertSame('Wanted 00', $rows[0]->name);
        $this->assertSame('Wanted 23', $rows[23]->name);
        $response->assertSee('Showing 1–31 of 31 releases')->assertDontSee('Outside')->assertDontSee('data-shape', false);
        $this->assertSame(['per' => 24, 'thumbs' => true], array_intersect_key(User::query()->findOrFail($user->id)->releaseViewPreferences('all'), ['per' => 1, 'thumbs' => 1]));
    }

    public function test_table_renders_one_escaped_row_with_the_date_and_the_two_by_two_actions(): void
    {
        $name = '<One & "release">';
        $identity = ' Sender <person+tag@Host.test> ';
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.movies']);
        $this->release('Internal name', ['display_name' => $name, 'fromname' => $identity, 'groups_id' => 1, 'totalpart' => 12]);
        $response = $this->actingAs($this->browserUser())->get('/browse/all')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($document);
        $table = '//table[contains(@class,"tv-feed")]';
        $this->assertSame(1, $xpath->query($table.'/tbody/tr')->length);
        $headers = array_map(static fn (\DOMNode $node): string => trim($node->textContent), iterator_to_array($xpath->query($table.'/thead/tr/th')));
        $this->assertSame(['', 'Release', 'Category', 'Size', 'Posted', 'Actions'], $headers);
        $this->assertSame($name, $xpath->query($table.'//a[contains(@class,"tv-release-name")]')->item(0)->textContent);
        $this->assertSame(['tv-action tv-action-download download-nzb', 'tv-action', 'tv-action', 'tv-action tv-action-slot'],
            array_map(static fn (\DOMNode $node): string => $node->getAttribute('class'), iterator_to_array($xpath->query($table.'//div[@class="tv-actions"]/*'))));
        $this->assertSame(0, $xpath->query($table.'//*[@data-report-release-id]')->length, 'no Report button in a row');
        $this->assertSame(0, $xpath->query($table.'//a[@data-row-action="details"]')->length, 'no details button in a row');
        $this->assertSame('Posted Sep 12, 2026, 11:30 PM · Added Sep 13, 2026, 12:00 PM', $xpath->query($table.'//td[contains(@class,"tv-date")]/@title')->item(0)->nodeValue);
        $response->assertSee('>Sep 12, 2026</td>', false)
            ->assertSee(url('/browse/all').'?'.http_build_query(['group' => 'alt.binaries.movies']))
            ->assertSee(url('/browse/all').'?'.http_build_query(['poster' => $identity], '', '&', PHP_QUERY_RFC3986))
            ->assertDontSee('<One & "release">', false);
    }

    public function test_the_pager_line_and_clear_all_remain_available_on_an_empty_page(): void
    {
        $this->actingAs($this->browserUser());
        $response = $this->get('/browse/all?q=missing')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//nav[@aria-label="Pages"]')->length, 'the pager line stays; the numbered pager needs a second page');
        $this->assertSame(2, $xpath->query('//nav[@aria-label="Pages"]//span[@class="is-off"]')->length, 'both arrows off');
        $response->assertSee('Page 1 of 1')->assertSee('Showing 0 releases')->assertSee('No releases match names containing “missing”.', false)
            ->assertSee('class="pager-line-clear" data-clear-all aria-hidden="false">Clear all</a>', false)
            ->assertDontSee('data-preference="view" data-value="cards"', false)->assertDontSee('<table', false);
    }

    public function test_basket_add_and_remove_return_current_user_counts_without_changing_another_users_basket(): void
    {
        $first = $this->browserUser();
        $second = $this->browserUser();
        $this->release('One');
        $this->release('Two');
        $this->actingAs($first)->postJson('/cart/add', ['id' => md5('One').','.md5('Two')])
            ->assertOk()->assertJsonPath('cartCount', 2);
        $this->flushSession();
        $this->actingAs($second)->postJson('/cart/add', ['id' => md5('One')])
            ->assertOk()->assertJsonPath('cartCount', 1);
        $this->flushSession();
        $this->actingAs($first)->postJson('/cart/delete/'.md5('One'))
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('cartCount', 1);
        $firstPage = $this->get('/browse/all')->assertOk();
        $this->assertStringContainsString('data-cart="'.md5('One').'" aria-pressed="false"', $this->browserRow($firstPage->getContent(), 'One'));
        $this->assertStringContainsString('data-cart="'.md5('Two').'" aria-pressed="true"', $this->browserRow($firstPage->getContent(), 'Two'));
        $this->flushSession();
        $secondPage = $this->actingAs($second)->get('/browse/all')->assertOk();
        $this->assertStringContainsString('data-cart="'.md5('One').'" aria-pressed="true"', $this->browserRow($secondPage->getContent(), 'One'));
        $this->assertStringContainsString('data-cart="'.md5('Two').'" aria-pressed="false"', $this->browserRow($secondPage->getContent(), 'Two'));
    }

    public function test_shared_lists_link_a_console_release_to_its_details_page_and_show_no_book_or_pc_title_chip(): void
    {
        foreach (['genres', 'musicinfo', 'consoleinfo', 'bookinfo', 'gamesinfo'] as $table) {
            ProductionTables::fromAuthority()->create($table);
        }
        DB::table('consoleinfo')->insert(['id' => 12, 'title' => 'Console Game Title', 'platform' => 'PS5', 'releasedate' => '2024-01-01']);
        DB::table('gamesinfo')->insert(['id' => 12, 'title' => 'Computer Game Title', 'releasedate' => '2023-01-01']);
        DB::table('bookinfo')->insert(['id' => 12, 'title' => 'Printed Book Title', 'author' => 'An author', 'genre' => 'Mystery', 'publishdate' => '2022-01-01']);
        DB::table('musicinfo')->insert(['id' => 12, 'title' => 'Album Title', 'year' => '2021']);
        $poster = 'Shared poster <poster@example.test>';
        $ids = [];
        foreach (['console' => [1030, 'consoleinfo_id'], 'pc' => [4030, 'gamesinfo_id'], 'book' => [7030, 'bookinfo_id'], 'audio' => [3030, 'musicinfo_id']] as $kind => [$category, $foreignKey]) {
            $ids[$kind] = $this->release('Chip.'.$kind.'.release', ['categories_id' => $category, $foreignKey => 12, 'fromname' => $poster, 'isrenamed' => 1, 'nfostatus' => 1]);
        }
        config(['search.default' => 'chip-test', 'nntmux.mysql_search_fallback' => false]);
        $driver = Mockery::mock(SearchDriverInterface::class);
        $driver->shouldReceive('isFuzzyEnabled')->andReturn(false);
        $driver->shouldReceive('isSuggestEnabled')->andReturn(false);
        $driver->shouldReceive('isAutocompleteEnabled')->andReturn(false);
        $driver->shouldReceive('searchReleasesFiltered')->andReturn(['ids' => array_values($ids), 'total' => 4, 'fuzzy' => false, 'available' => true, 'has_more' => false]);
        app(SearchService::class)->extend('chip-test', static fn () => $driver);
        $this->actingAs($this->browserUser());

        // the generic lists print a console release's game line as plain text under the name and no book, PC or album title at all
        foreach (['/browse/all', '/browse/all?poster='.rawurlencode($poster)] as $path) {
            $response = $this->get($path)->assertOk();
            $this->assertSame(4, $response->viewData('total'), $path);
            $html = (string) $response->getContent();
            $this->assertStringContainsString('<span class="tv-game-line" data-entity="game">Console Game Title · 2024</span>', $this->browserRow($html, 'Chip.console.release'), $path);
            $this->assertStringContainsString('href="'.route('details', md5('Chip.console.release')).'"', $html, $path);
            foreach (['Printed Book Title', 'Computer Game Title', 'Album Title', 'data-chip-variant="entity"'] as $absent) {
                $this->assertStringNotContainsString($absent, $html, $path.' '.$absent);
            }
            $this->assertNoRetiredAddress($html, $path);
        }
        $response = $this->get('/search?q=Chip')->assertOk();
        $this->assertSame(4, $response->viewData('results')->total());
        $html = (string) $response->getContent();
        $this->assertShelfTitleChips($html, '/search?q=Chip', md5('Chip.console.release'));
        $this->assertNoRetiredAddress($html, '/search?q=Chip');
    }

    public function test_legacy_group_link_redirects_to_the_canonical_exact_filter(): void
    {
        $this->actingAs($this->browserUser())->get('/browse/group?g=alt.binaries.movies&per=24&page=2')
            ->assertRedirect('/browse/all?per=24&page=2&group=alt.binaries.movies');
    }

    public function test_poster_pagination_preserves_the_untrimmed_identity(): void
    {
        $identity = ' Exact <poster@Host.test> ';
        for ($index = 0; $index < 51; $index++) {
            $this->release('Exact '.$index, ['fromname' => $identity]);
        }
        $this->release('Trimmed', ['fromname' => trim($identity)]);
        $first = $this->actingAs($this->browserUser())->get(route('browse.all', ['poster' => $identity]))->assertOk();
        $next = route('browse.all', $first->viewData('filters')->query(2));
        $this->assertSame(route('browse.all', ['poster' => $identity, 'page' => 2]), $next);
        $first->assertSee('href="'.e($next).'"', false);
        $second = $this->get($next)->assertOk();
        $this->assertSame(51, $second->viewData('total'));
        $this->assertCount(1, $second->viewData('rows'));
        $second->assertSee('<title>Posts by '.e($identity), false)->assertDontSee('Trimmed');
    }

    public function test_basket_page_uses_the_shared_table_and_only_current_users_releases(): void
    {
        $user = $this->browserUser();
        $this->release('Saved release');
        $this->release('Outside basket');
        $this->actingAs($user)->postJson('/cart/add', ['id' => md5('Saved release')])->assertOk();
        $this->get('/cart/index')->assertRedirect('/basket');
        $response = $this->get('/basket')->assertOk();
        $response->assertSee('data-release-table', false)->assertSee('Saved release')->assertDontSee('Outside basket')
            ->assertSee('data-in-basket="1"', false)->assertSee('Sep 12, 2026 23:30');
        $this->assertSame(1, $response->viewData('results')->total());
    }

    public function test_search_accepts_q_and_uses_the_root_preferences_and_shared_browser(): void
    {
        config(['search.default' => 'browser-test', 'nntmux.mysql_search_fallback' => false]);
        $driver = Mockery::mock(SearchDriverInterface::class);
        $driver->shouldReceive('isFuzzyEnabled')->andReturn(false);
        $driver->shouldReceive('isSuggestEnabled')->andReturn(true);
        $driver->shouldReceive('isAutocompleteEnabled')->andReturn(false);
        $driver->shouldReceive('suggest')->with('Requested title', null)->once()->andReturn([['suggest' => 'Corrected title', 'docs' => 7]]);
        $driver->shouldReceive('searchReleasesFiltered')->once()->andReturn(['ids' => [], 'total' => 0, 'fuzzy' => false, 'available' => true, 'has_more' => false]);
        app(SearchService::class)->extend('browser-test', static fn () => $driver);
        $this->actingAs($this->browserUser())->postJson('/profile/update-view', ['root' => 'movies', 'per' => 24, 'view' => 'cards'])->assertOk();

        $response = $this->get('/search?q=Requested%20title&t=2030&subject=Old%20title&ob=size_desc&sort=title&view=cards')->assertOk();
        $response->assertSee('data-release-table', false)->assertSee('Requested title')->assertSee('No releases match.')
            ->assertDontSee('data-value="cards"', false)->assertDontSee('renamed and post-processed only');
        $this->assertSame(24, $response->viewData('results')->perPage());
        $response->assertSee('q=Corrected%20title', false)->assertDontSee('search=Corrected', false);
    }

    public function test_search_page_beyond_the_end_redirects_to_the_actual_last_page(): void
    {
        config(['search.default' => 'browser-test', 'nntmux.mysql_search_fallback' => true]);
        $driver = Mockery::mock(SearchDriverInterface::class);
        $driver->shouldReceive('searchReleasesFiltered')->once()->andReturn(['ids' => [], 'total' => 0, 'fuzzy' => false, 'available' => false]);
        app(SearchService::class)->extend('browser-test', static fn () => $driver);
        for ($index = 1; $index <= 49; $index++) {
            $this->release('Title '.$index);
        }
        $response = $this->actingAs($this->browserUser())->get('/search?q=Title&per=24&page=999')->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
        $this->assertSame('3', $parameters['page']);
        $this->assertSame('Title', $parameters['q']);
    }

    public function test_numeric_subcategory_and_all_browse_respect_personal_exclusions_and_root_permissions(): void
    {
        $user = $this->browserUser();
        $this->release('Excluded movie');
        $this->release('Allowed audio', ['categories_id' => 3030]);
        DB::table('user_excluded_categories')->insert(['users_id' => $user->id, 'categories_id' => 2030]);
        $this->actingAs($user)->get('/browse/movies/2030')->assertForbidden();
        $this->get('/browse/all')->assertOk()->assertDontSee('Excluded movie')->assertSee('Allowed audio');
        $user->revokePermissionTo('view audio');
        $this->get('/browse/audio')->assertForbidden();
    }

    public function test_table_preserves_report_indicators_without_exposing_private_staff_responses(): void
    {
        Schema::create('release_reports', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('releases_id');
            $table->string('response')->nullable();
            $table->boolean('response_is_public')->default(false);
        });
        $public = $this->release('Public report');
        $private = $this->release('Private report');
        DB::table('release_reports')->insert([
            ['releases_id' => $public, 'response' => 'Public answer', 'response_is_public' => true],
            ['releases_id' => $private, 'response' => 'Private answer', 'response_is_public' => false],
        ]);
        $response = $this->actingAs($this->browserUser())->get('/browse/all')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(2, $xpath->query('//a[@data-report-summary]')->length);
        $this->assertSame(1, $xpath->query('//a[@data-public-response]')->length);
        $response->assertDontSee('Private answer');
    }

    public function test_the_all_list_draws_no_thumbnails_whatever_the_old_toggle_says(): void
    {
        config(['nntmux_settings.covers_path' => $this->makeTempDirectory('browser-covers')]);
        $this->release('A movie');
        $this->release('B album', ['categories_id' => 3030]);
        $this->release('C adult', ['categories_id' => 6030]);
        // the generic list has no picture column and no Thumbnails toggle (SPEC 5.4): ?thumbs=1 draws nothing
        $response = $this->actingAs($this->browserUser())->get('/browse/all?thumbs=1')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(3, $xpath->query('//table[contains(@class,"tv-feed")]/tbody/tr')->length);
        $this->assertSame(0, $xpath->query('//table[contains(@class,"tv-feed")]//*[@data-shape]')->length);
        $this->assertSame(0, $xpath->query('//table[contains(@class,"tv-feed")]//img')->length);
        $response->assertDontSee('data-preference="thumbs"', false)->assertDontSee('Thumbnails');
    }

    public function test_audio_thumbnails_never_show_the_old_album_cover(): void
    {
        $covers = $this->makeTempDirectory('browser-covers');
        mkdir($covers.'/music');
        file_put_contents($covers.'/music/42.jpg', 'cover');
        config(['nntmux_settings.covers_path' => $covers]);
        foreach (['genres', 'musicinfo', 'movieinfo', 'videos'] as $table) {
            ProductionTables::fromAuthority()->create($table);
        }
        ProductionTables::fromAuthority()->create('tv_info', ['videos_id', 'publisher', 'image']);
        DB::table('musicinfo')->insert(['id' => 42, 'title' => 'Old Album Match', 'artist' => 'An artist', 'year' => '2021', 'cover' => 1]);
        $this->release('Matched album release', ['categories_id' => 3030, 'musicinfo_id' => 42, 'isrenamed' => 1, 'nfostatus' => 1]);
        $this->actingAs($this->browserUser());
        $html = (string) $this->get('/browse/all?thumbs=1')->assertOk()->assertSee('Matched album release')->getContent();
        $this->assertStringNotContainsString('/covers/music/42', $html, 'the All list');
        $this->assertStringNotContainsString('<img', $this->browserRow($html, 'Matched album release'));
        $this->assertStringNotContainsString('Old Album Match', $html, 'the old album match is never the music line');
        // the home page's Audio shelf: the square typographic tile (a disc icon, no cover picture), never the old album match
        $html = (string) $this->get('/')->assertOk()->assertSee('Matched album release')->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $tiles = $xpath->query('//section[@data-shelf="Audio"]//button[@data-tile]');
        $this->assertSame(1, $tiles->length, '/');
        $this->assertStringContainsString('is-album', $tiles->item(0)->getAttribute('class'), '/');
        $this->assertSame('Matched album release', $tiles->item(0)->getAttribute('title'), '/');
        $this->assertSame(0, $xpath->query('.//img', $tiles->item(0))->length, '/');
        $this->assertSame(1, $xpath->query('.//i[contains(@class, "fa-compact-disc")]', $tiles->item(0))->length, '/');
        $this->assertStringNotContainsString('/covers/music/42', $html, '/');
        $this->assertStringNotContainsString('Old Album Match', $html, '/');
    }

    public function test_table_sizes_use_megabytes_below_one_gigabyte(): void
    {
        $this->release('Small release', ['size' => 524288000]);
        $this->release('Large release', ['size' => 1610612736]);
        $this->actingAs($this->browserUser())->get('/browse/all')->assertOk()
            ->assertSee('<td class="tv-num tv-size">500 MB</td>', false)->assertSee('<td class="tv-num tv-size">1.50 GB</td>', false);
    }

    public function test_existing_minimum_completion_links_still_filter_the_whole_result_set(): void
    {
        $this->release('Complete release', ['completion' => 100]);
        $this->release('Incomplete release', ['completion' => 90]);
        $response = $this->actingAs($this->browserUser())->get('/browse/all?minc=95')->assertOk();
        $this->assertSame(1, $response->viewData('total'));
        $response->assertSee('Complete release')->assertDontSee('Incomplete release')
            ->assertSee('class="pager-line-clear" data-clear-all aria-hidden="false">Clear all</a>', false);
    }

    #[DataProvider('watchedRoots')]
    public function test_watching_preserves_per_title_category_choices(string $root, int $categoryId, string $table, string $key): void
    {
        ProductionTables::fromAuthority()->create('movieinfo', ['id', 'imdbid', 'title', 'year', 'genre', 'rating']);
        DB::table('categories')->insert(['id' => $categoryId + 10, 'title' => 'Excluded quality', 'root_categories_id' => $categoryId - 30]);
        $user = $this->browserUser();
        DB::table($table)->insert([
            ['users_id' => $user->id, $key => 123, 'categories' => $categoryId.'|'.($categoryId + 20)],
            ['users_id' => $user->id, $key => 456, 'categories' => null],
        ]);
        $this->release('Selected quality', ['categories_id' => $categoryId, $key => 123]);
        $this->release('Unwanted quality', ['categories_id' => $categoryId + 10, $key => 123]);
        $this->release('Unrestricted title', ['categories_id' => $categoryId + 10, $key => 456]);
        $response = $this->actingAs($user)->get('/browse/all?watching=1')->assertOk();
        $this->assertSame(2, $response->viewData('total'));
        $response->assertSee('Selected quality')->assertSee('Unrestricted title')->assertDontSee('Unwanted quality');
    }

    public static function watchedRoots(): iterable
    {
        yield 'movies' => ['movies', 2030, 'user_movies', 'imdbid'];
    }

    public function test_my_shows_browse_now_leads_to_the_tv_releases_screen(): void
    {
        $this->actingAs($this->browserUser());
        $this->get('/myshows/browse?q=Followed&watching=0')->assertRedirect('/browse/tv?q=Followed&watching=1');
        $this->get('/browse/tv?q=Followed&watching=1')->assertRedirect(route('tv.releases'));
    }

    public function test_the_table_shares_the_dto_processing_decisions(): void
    {
        $cases = [
            'Found NFO' => [1, 0, null, true], 'No NFO' => [0, 0, null, true],
            'Failed NFO' => [-9, 0, null, true], 'Skipped NFO' => [-10, 0, null, true],
            'First retry' => [-1, 0, null, false], 'Last retry' => [-8, 0, null, false],
            'Password unchecked' => [1, -1, null, false], 'Empty claim' => [1, 0, '', false],
        ];
        foreach ($cases as $name => [$nfo, $password, $claim, $done]) {
            $this->release($name, ['categories_id' => 3030, 'isrenamed' => 1, 'nfostatus' => $nfo, 'passwordstatus' => $password, 'additional_pp_claim_token' => $claim]);
        }
        // the list's rows are loaded through the shared row loader (ReleaseRowFacts), which carries the DTO's decisions
        $this->actingAs($this->browserUser());
        $ids = DB::table('releases')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $this->assertCount(count($cases), app(ReleaseRowFacts::class)->load($ids));
        foreach (app(ReleaseRowFacts::class)->load($ids) as $release) {
            $this->assertSame($cases[$release->row_data->name][3], $release->row_data->pp_done, $release->row_data->name);
        }
        $this->get('/browse/all')->assertOk()->assertSee('Found NFO');
    }

    public function test_cards_are_not_offered_for_all_other_group_or_poster_lists(): void
    {
        $this->actingAs($this->browserUser());
        $this->release('A release', ['categories_id' => 31]);
        foreach (['/browse/all', '/browse/other', '/browse/all?group=example', '/browse/all?poster=example'] as $path) {
            $this->get($path.(str_contains($path, '?') ? '&' : '?').'view=cards')->assertOk()
                ->assertSee('x-data="tvReleases"', false)->assertDontSee('data-release-cards', false)->assertDontSee('x-release-browser', false)
                ->assertDontSee('data-value="cards"', false)->assertDontSee('renamed and post-processed only');
        }
    }

    /** The browser's table row for one release. */
    private function browserRow(string $html, string $name): string
    {
        foreach (explode('<tr data-release-row', $html) as $row) {
            if (str_contains($row, '>'.e($name).'</a>')) {
                return strstr($row, '</tr>', true) ?: $row;
            }
        }
        $this->fail('No row for '.$name);
    }
}
