<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\Release;
use App\Models\User;
use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\DTO\ReleaseSearchQuery;
use App\Services\Search\DTO\SearchPage;
use App\Services\Search\SearchService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class ReleaseBrowserControllerTest extends TestCase
{
    use InteractsWithAdminListPages;
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
        foreach (['2026_09_14_011408_add_view_prefs_to_users_table', '2026_08_21_090000_create_release_audio_tags_table', '2026_08_27_150100_create_release_video_clips_table'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_group_filter_counts_all_matching_rows_and_bounds_the_page(): void
    {
        $user = $this->browserUser();
        DB::table('usenet_groups')->insert([['id' => 1, 'name' => 'alt.binaries.movies'], ['id' => 2, 'name' => 'alt.binaries.movies.other']]);
        for ($index = 1; $index <= 49; $index++) {
            $this->release('Movie '.$index, ['groups_id' => 1]);
        }
        $this->release('Outside group', ['groups_id' => 2]);

        $response = $this->actingAs($user)->get('/browse/all?group=alt.binaries.movies&per=24&page=2')->assertOk();
        $page = $response->viewData('results');
        $this->assertSame(49, $page->total());
        $this->assertCount(24, $page->items());
        $this->assertSame(2, $page->currentPage());
        $response->assertSee('Releases in alt.binaries.movies')->assertDontSee('Outside group');
        $this->get('/browse/all?group=alt.binaries.movies&per=24&page=999')
            ->assertRedirect('/browse/all?group=alt.binaries.movies&per=24&page=3');
    }

    public function test_poster_identity_filter_is_byte_exact_including_spaces_and_case(): void
    {
        $identity = ' Sender <person+tag@Host.test> ';
        $this->release('Exact identity', ['fromname' => $identity]);
        $this->release('Trimmed identity', ['fromname' => trim($identity)]);
        $this->release('Case variant', ['fromname' => strtolower($identity)]);
        $this->release('Extended identity', ['fromname' => $identity.'suffix']);

        $response = $this->actingAs($this->browserUser())->get('/browse/all?poster='.rawurlencode($identity))->assertOk();
        $this->assertSame(1, $response->viewData('results')->total());
        $response->assertSee('Posts by '.$identity)->assertSee('Exact identity')
            ->assertDontSee('Trimmed identity')->assertDontSee('Case variant')->assertDontSee('Extended identity');
    }

    public function test_canonical_roots_and_numeric_subcategories_never_fall_back_to_all_releases(): void
    {
        $roots = ['console' => 1030, 'movies' => 2030, 'audio' => 3030, 'games' => 4030, 'tv' => 5030, 'xxx' => 6030, 'books' => 7030, 'other' => 31];
        foreach ($roots as $root => $categoryId) {
            $this->release($root.' release', ['categories_id' => $categoryId]);
        }
        $this->actingAs($this->browserUser());
        foreach ($roots as $root => $categoryId) {
            foreach (['/browse/'.$root, '/browse/'.$root.'/'.$categoryId] as $url) {
                $response = $this->get($url)->assertOk();
                $this->assertSame(1, $response->viewData('results')->total(), $url);
                $response->assertSee($root.' release');
            }
        }
        $this->get('/browse/movies/3030')->assertNotFound();
        $this->get('/browse/movies/9999')->assertNotFound();
        $this->get('/browse/not-a-root')->assertNotFound();
    }

    public function test_search_and_sort_apply_before_pagination_and_explicit_preferences_override_saved_values(): void
    {
        $user = $this->browserUser();
        $this->actingAs($user)->postJson('/profile/update-view', ['root' => 'all', 'per' => 24, 'thumbs' => true])->assertOk();
        for ($index = 30; $index >= 1; $index--) {
            $this->release(sprintf('Wanted %02d', $index));
        }
        $this->release('Unwanted match', ['display_name' => 'Outside']);
        $this->release('Encoded name', ['display_name' => 'Wanted 00']);

        $response = $this->get('/browse/all?q=Wanted&sort=title')->assertOk();
        $page = $response->viewData('results');
        $this->assertSame(31, $page->total());
        $this->assertCount(24, $page->items());
        $this->assertSame('Wanted 00', $page->items()[0]->row_data->name);
        $this->assertSame('Wanted 23', $page->items()[23]->row_data->name);
        $this->assertTrue($response->viewData('browserState')->thumbs);
        $override = $this->get('/browse/all?q=Wanted&sort=title&per=48&thumbs=0')->assertOk();
        $this->assertCount(31, $override->viewData('results')->items());
        $this->assertFalse($override->viewData('browserState')->thumbs);
    }

    public function test_table_renders_one_escaped_row_with_separate_dates_and_four_native_actions(): void
    {
        $name = '<One & "release">';
        $identity = ' Sender <person+tag@Host.test> ';
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.movies']);
        $id = $this->release('Internal name', ['display_name' => $name, 'fromname' => $identity, 'groups_id' => 1, 'totalpart' => 12]);
        $response = $this->actingAs($this->browserUser())->get('/browse/all')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $table = '//table[@data-release-table]';
        $this->assertSame(1, $xpath->query($table.'/tbody/tr')->length);
        $headers = array_map(static fn (\DOMNode $node): string => trim($node->textContent), iterator_to_array($xpath->query($table.'/thead/tr/th')));
        $this->assertSame(['Select all', 'Release', 'Category', 'Size', 'Files', 'Added', 'Posted', 'Stats', 'Actions'], $headers);
        $this->assertSame($name, $xpath->query($table.'//a[@data-release-title]')->item(0)->textContent);
        $this->assertSame(4, $xpath->query($table.'//*[@data-row-action]')->length);
        $this->assertSame(1, $xpath->query($table.'//button[@data-report-release-id="'.$id.'"]')->length);
        $this->assertSame($name, $xpath->evaluate('string('.$table.'//button[@data-report-release-id="'.$id.'"]/@data-release-display-name)'));
        $response->assertSee('Sep 12, 2026 23:30')
            ->assertSee(url('/browse/all').'?'.http_build_query(['group' => 'alt.binaries.movies']))
            ->assertSee(url('/browse/all').'?'.http_build_query(['poster' => $identity], '', '&', PHP_QUERY_RFC3986))
            ->assertDontSee('<One & "release">', false);
    }

    public function test_toolbar_and_both_pagers_remain_available_on_empty_and_last_pages(): void
    {
        $this->actingAs($this->browserUser());
        $response = $this->get('/browse/all?q=missing&per=24')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(2, $xpath->query('//nav[@aria-label="Release pages"]')->length);
        $this->assertSame(4, $xpath->query('//nav[@aria-label="Release pages"]//button[@disabled]')->length);
        $this->assertSame(6, $xpath->query('//nav[@aria-label="Release pages"]//button[@data-preference="per"]')->length);
        $response->assertSee('Page 1 of 1')->assertSee('0 releases')
            ->assertSee('Search in All releases')->assertSee('No releases match.')
            ->assertSee('Clear filters')->assertDontSee('data-preference="view" data-value="cards"', false);
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
        $firstPage = $this->get('/browse/all?sort=title')->assertOk();
        $this->assertFalse($firstPage->viewData('results')->items()[0]->row_data->in_basket);
        $this->flushSession();
        $secondPage = $this->actingAs($second)->get('/browse/all?sort=title')->assertOk();
        $this->assertTrue($secondPage->viewData('results')->items()[0]->row_data->in_basket);
    }

    public function test_movie_filters_use_linked_metadata_before_counting_and_offer_only_available_values(): void
    {
        Schema::create('movieinfo', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('imdbid');
            $table->string('title');
            $table->string('year');
            $table->string('genre');
            $table->string('rating');
            $table->boolean('cover')->default(false);
        });
        DB::table('movieinfo')->insert([
            ['imdbid' => '1234567', 'title' => 'Recent drama', 'year' => '2026', 'genre' => 'Drama, Mystery', 'rating' => '8.2'],
            ['imdbid' => '1234568', 'title' => 'Old drama', 'year' => '2020', 'genre' => 'Drama', 'rating' => '9.0'],
            ['imdbid' => '1234569', 'title' => 'Another genre', 'year' => '2026', 'genre' => 'Melodrama', 'rating' => '7.0'],
        ]);
        $this->release('Matched', ['imdbid' => '1234567']);
        $this->release('Older', ['imdbid' => '1234568']);
        $this->release('Different genre', ['imdbid' => '1234569']);
        $this->release('Unmatched');
        $response = $this->actingAs($this->browserUser())->get('/browse/movies?year=2026&genre=Drama')->assertOk();
        $this->assertSame(1, $response->viewData('results')->total());
        $response->assertSee('Matched')->assertDontSee('Older')->assertDontSee('Different genre')->assertDontSee('Unmatched');
        $response->assertSee('value="2020"', false)->assertSee('value="Mystery"', false)
            ->assertDontSee('aria-label="Network"', false);
        $sorted = $this->get('/browse/movies?sort=rating')->assertOk();
        $this->assertSame('Older', $sorted->viewData('results')->items()[0]->row_data->name);
        $this->assertSame(5, substr_count($response->getContent(), 'data-row-action='));
    }

    public function test_tv_filters_use_year_and_network_and_watching_is_scoped_to_the_current_user(): void
    {
        Schema::create('videos', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->date('started');
        });
        Schema::create('tv_info', function (Blueprint $table): void {
            $table->unsignedInteger('videos_id')->primary();
            $table->string('publisher');
        });
        DB::table('videos')->insert([
            ['id' => 1, 'title' => 'Followed show', 'started' => '2026-01-01'],
            ['id' => 2, 'title' => 'Another show', 'started' => '2026-01-01'],
            ['id' => 3, 'title' => 'Older show', 'started' => '2020-01-01'],
        ]);
        DB::table('tv_info')->insert([
            ['videos_id' => 1, 'publisher' => 'Network A'], ['videos_id' => 2, 'publisher' => 'Network B'], ['videos_id' => 3, 'publisher' => 'Network A'],
        ]);
        foreach ([1, 2, 3] as $id) {
            $this->release('Episode '.$id, ['categories_id' => 5030, 'videos_id' => $id]);
        }
        $user = $this->browserUser();
        DB::table('user_series')->insert([
            ['users_id' => $user->id, 'videos_id' => 1], ['users_id' => $user->id + 1, 'videos_id' => 2],
        ]);
        $response = $this->actingAs($user)->get('/browse/tv?year=2026&network=Network%20A')->assertOk();
        $this->assertSame(1, $response->viewData('results')->total());
        $response->assertSee('Episode 1')->assertDontSee('Episode 2')->assertDontSee('Episode 3')
            ->assertDontSee('aria-label="Genre"', false)->assertDontSee('value="rating"', false);
        $watching = $this->get('/browse/tv?watching=1')->assertOk();
        $this->assertSame(1, $watching->viewData('results')->total());
        $watching->assertSee('Episode 1')->assertDontSee('Episode 2');
    }

    #[DataProvider('metadataRoots')]
    public function test_other_root_filters_apply_to_metadata_and_adult_posted_year(string $root, int $categoryId, string $table, string $foreignKey, array $first, array $second, string $filters): void
    {
        $this->createGenresTable();
        $this->createMusicInfoTable();
        $this->createConsoleInfoTable();
        $this->createBookInfoTable();
        DB::table('genres')->insert(['id' => 1, 'title' => 'Adventure', 'type' => 1]);
        if ($table !== '') {
            DB::table($table)->insert(['id' => 1, 'title' => 'First title', ...$first]);
            DB::table($table)->insert(['id' => 2, 'title' => 'Second title', ...$second]);
        }
        $this->release('Matching release', ['categories_id' => $categoryId, ...($foreignKey === '' ? ['postdate' => '2026-09-13 12:00:00'] : [$foreignKey => 1])]);
        $this->release('Nonmatching release', ['categories_id' => $categoryId, ...($foreignKey === '' ? ['postdate' => '2020-09-13 12:00:00'] : [$foreignKey => 2])]);
        $response = $this->actingAs($this->browserUser())->get('/browse/'.$root.'?'.$filters)->assertOk();
        $this->assertSame(1, $response->viewData('results')->total());
        $response->assertSee('Matching release')->assertDontSee('Nonmatching release');
        if ($root === 'audio') {
            $sorted = $this->get('/browse/audio?sort=artist')->assertOk();
            $this->assertSame('Nonmatching release', $sorted->viewData('results')->items()[0]->row_data->name);
        }
    }

    /** @return iterable<string, array{string, int, string, string, array<string, mixed>, array<string, mixed>, string}> */
    public static function metadataRoots(): iterable
    {
        yield 'audio label and year' => ['audio', 3030, 'musicinfo', 'musicinfo_id', ['year' => '2026', 'publisher' => 'Label A', 'artist' => 'Zebra', 'genres_id' => 1], ['year' => '2020', 'publisher' => 'Label B', 'artist' => 'Alpha'], 'year=2026&label=Label%20A&genre=Adventure'];
        yield 'console platform and publisher' => ['console', 1030, 'consoleinfo', 'consoleinfo_id', ['releasedate' => '2026-01-01', 'platform' => 'Switch', 'publisher' => 'Studio A', 'genres_id' => 1], ['releasedate' => '2020-01-01', 'platform' => 'PS5', 'publisher' => 'Studio B'], 'year=2026&platform=Switch&publisher=Studio%20A&genre=Adventure'];
        yield 'books author and genre' => ['books', 7030, 'bookinfo', 'bookinfo_id', ['publishdate' => '2026-01-01', 'author' => 'Writer A', 'genre' => 'Adventure'], ['publishdate' => '2020-01-01', 'author' => 'Writer B', 'genre' => 'History'], 'year=2026&author=Writer%20A&genre=Adventure'];
        yield 'adult year is posted year' => ['xxx', 6030, '', '', [], [], 'year=2026'];
    }

    public function test_legacy_group_link_redirects_to_the_canonical_exact_filter(): void
    {
        $this->actingAs($this->browserUser())->get('/browse/group?g=alt.binaries.movies&per=24&page=2')
            ->assertRedirect('/browse/all?per=24&page=2&group=alt.binaries.movies');
    }

    public function test_poster_pagination_preserves_the_untrimmed_identity(): void
    {
        $identity = ' Exact <poster@Host.test> ';
        for ($index = 0; $index < 25; $index++) {
            $this->release('Exact '.$index, ['fromname' => $identity]);
        }
        $this->release('Trimmed', ['fromname' => trim($identity)]);
        $first = $this->actingAs($this->browserUser())->get(route('browse.all', ['poster' => $identity, 'per' => 24]))->assertOk();
        $second = $this->get($first->viewData('results')->nextPageUrl())->assertOk();
        $this->assertSame(25, $second->viewData('results')->total());
        $this->assertCount(1, $second->viewData('results')->items());
        $second->assertSee('Posts by '.$identity)->assertDontSee('Trimmed');
    }

    public function test_basket_page_uses_the_shared_table_and_only_current_users_releases(): void
    {
        $user = $this->browserUser();
        $this->release('Saved release');
        $this->release('Outside basket');
        $this->actingAs($user)->postJson('/cart/add', ['id' => md5('Saved release')])->assertOk();
        $response = $this->get('/cart/index')->assertOk();
        $response->assertSee('data-release-table', false)->assertSee('Saved release')->assertDontSee('Outside basket')
            ->assertSee('data-in-basket="1"', false)->assertSee('Sep 12, 2026 23:30');
        $this->assertSame(1, $response->viewData('results')->total());
    }

    public function test_legacy_adult_navigation_opens_the_canonical_subcategory_table(): void
    {
        $this->release('Adult release', ['categories_id' => 6030]);
        $this->release('Movie release');
        $this->actingAs($this->browserUser())->get('/XXX/HD?t=6030&per=24&parentCategory=movies&id=2030')
            ->assertRedirect('/browse/xxx/6030?per=24');
        $this->get('/browse/xxx/6030?per=24')->assertOk()->assertViewIs('browse.index')
            ->assertSee('data-release-table', false)->assertSee('Adult release')->assertDontSee('Movie release');
    }

    public function test_search_accepts_q_and_uses_the_root_preferences_and_shared_browser(): void
    {
        config(['search.default' => 'browser-test', 'nntmux.mysql_search_fallback' => false]);
        $driver = Mockery::mock(SearchDriverInterface::class);
        $driver->shouldReceive('isAvailable')->andReturn(true);
        $driver->shouldReceive('isSuggestEnabled')->andReturn(true);
        $driver->shouldReceive('isAutocompleteEnabled')->andReturn(false);
        $driver->shouldReceive('suggest')->with('Requested title', null)->once()->andReturn([['suggest' => 'Corrected title', 'docs' => 7]]);
        $driver->shouldReceive('searchReleasePage')->once()->with(Mockery::on(static fn (ReleaseSearchQuery $query): bool => $query->phrases === ['searchname' => 'Requested title'] && $query->limit === 24 && $query->categoryIds === [2030] && $query->sortField === 'searchname' && $query->sortDirection === 'asc'))
            ->andReturn(new SearchPage([], 0, false, 'browser-test'));
        app(SearchService::class)->extend('browser-test', static fn () => $driver);
        $this->actingAs($this->browserUser())->postJson('/profile/update-view', ['root' => 'movies', 'per' => 24])->assertOk();

        $response = $this->get('/search?q=Requested%20title&t=2030&subject=Old%20title&ob=size_desc&sort=title')->assertOk();
        $response->assertSee('data-release-table', false)->assertSee('Requested title')->assertSee('No releases match.');
        $this->assertSame(24, $response->viewData('results')->perPage());
        $response->assertSee('q=Corrected%20title', false)->assertDontSee('search=Corrected', false);
    }

    public function test_search_page_beyond_the_end_redirects_to_the_actual_last_page(): void
    {
        config(['search.default' => 'browser-test', 'nntmux.mysql_search_fallback' => false]);
        $driver = Mockery::mock(SearchDriverInterface::class);
        $driver->shouldReceive('isAvailable')->andReturn(true);
        $driver->shouldReceive('searchReleasePage')->once()->andReturn(new SearchPage([], 49, false, 'browser-test'));
        app(SearchService::class)->extend('browser-test', static fn () => $driver);

        $this->actingAs($this->browserUser())->get('/search?q=Title&per=24&page=999')
            ->assertRedirect('/search?q=Title&per=24&page=3');
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

    public function test_table_thumbnails_use_each_rows_root_shape_and_a_placeholder_for_missing_artwork(): void
    {
        config(['nntmux_settings.covers_path' => $this->makeTempDirectory('browser-covers')]);
        $this->release('A movie');
        $this->release('B album', ['categories_id' => 3030]);
        $this->release('C adult', ['categories_id' => 6030]);
        $response = $this->actingAs($this->browserUser())->get('/browse/all?thumbs=1&sort=title')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $shapes = array_map(static fn (\DOMNode $node): string => $node->nodeValue, iterator_to_array($xpath->query('//table[@data-release-table]//*[@data-shape]/@data-shape')));
        $this->assertSame(['tall', 'square', 'wide'], $shapes);
        $this->assertSame(0, $xpath->query('//table[@data-release-table]//img')->length);
    }

    public function test_table_sizes_use_megabytes_below_one_gigabyte(): void
    {
        $this->release('Small release', ['size' => 524288000]);
        $this->release('Large release', ['size' => 1610612736]);
        $this->actingAs($this->browserUser())->get('/browse/all')->assertOk()
            ->assertSee('500.00 MB')->assertSee('1.50 GB');
    }

    public function test_existing_minimum_completion_links_still_filter_the_whole_result_set(): void
    {
        $this->release('Complete release', ['completion' => 100]);
        $this->release('Incomplete release', ['completion' => 90]);
        $response = $this->actingAs($this->browserUser())->get('/browse/all?minc=95')->assertOk();
        $this->assertSame(1, $response->viewData('results')->total());
        $response->assertSee('Complete release')->assertDontSee('Incomplete release')->assertSee('Clear filters');
    }

    #[DataProvider('watchedRoots')]
    public function test_watching_preserves_per_title_category_choices(string $root, int $categoryId, string $table, string $key): void
    {
        Schema::create('movieinfo', function (Blueprint $table): void {
            $table->increments('id');
            foreach (['imdbid', 'title', 'year', 'genre', 'rating'] as $column) {
                $table->string($column)->nullable();
            }
        });
        Schema::create('videos', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->string('started')->nullable();
        });
        Schema::create('tv_info', function (Blueprint $table): void {
            $table->integer('videos_id');
            $table->string('publisher')->nullable();
        });
        DB::table('categories')->insert(['id' => $categoryId + 10, 'title' => 'Excluded quality', 'root_categories_id' => $categoryId - 30]);
        $user = $this->browserUser();
        DB::table($table)->insert([
            ['users_id' => $user->id, $key => 123, 'categories' => $categoryId.'|'.($categoryId + 20)],
            ['users_id' => $user->id, $key => 456, 'categories' => null],
        ]);
        $this->release('Selected quality', ['categories_id' => $categoryId, $key => 123]);
        $this->release('Unwanted quality', ['categories_id' => $categoryId + 10, $key => 123]);
        $this->release('Unrestricted title', ['categories_id' => $categoryId + 10, $key => 456]);
        $response = $this->actingAs($user)->get('/browse/'.$root.'?watching=1')->assertOk();
        $this->assertSame(2, $response->viewData('results')->total());
        $response->assertSee('Selected quality')->assertSee('Unrestricted title')->assertDontSee('Unwanted quality');
        if ($root === 'tv') {
            $this->get('/myshows/browse')->assertOk()->assertSee('Selected quality')->assertDontSee('Unwanted quality');
        }
    }

    public static function watchedRoots(): iterable
    {
        yield 'movies' => ['movies', 2030, 'user_movies', 'imdbid'];
        yield 'tv' => ['tv', 5030, 'user_series', 'videos_id'];
    }

    private function browserUser(): User
    {
        $user = $this->createUserWithRole('User');
        foreach (['movies', 'audio', 'console', 'books', 'adult', 'pc', 'tv', 'other'] as $root) {
            $permission = Permission::findOrCreate('view '.$root, 'web');
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    private function release(string $name, array $attributes = []): int
    {
        $values = Release::factory()->raw([
            'fromname' => '', 'name' => $name, 'searchname' => $name, 'guid' => md5($name), 'categories_id' => 2030,
            'adddate' => '2026-09-13 12:00:00', 'postdate' => '2026-09-12 23:30:00',
            ...$attributes,
        ]);

        return DB::table('releases')->insertGetId(array_intersect_key($values, array_flip(Schema::getColumnListing('releases'))));
    }

    private function createReleaseSchema(): void
    {
        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            foreach (['name', 'searchname', 'guid', 'display_name', 'fromname', 'imdbid', 'additional_pp_claim_token', 'repair_outcome', 'rescan_outcome'] as $column) {
                $table->string($column)->nullable();
            }
            foreach (['categories_id', 'groups_id', 'videos_id', 'tv_episodes_id', 'musicinfo_id', 'consoleinfo_id', 'gamesinfo_id', 'bookinfo_id', 'anidbid'] as $column) {
                $table->integer($column)->nullable();
            }
            foreach (['totalpart', 'grabs', 'comments', 'passwordstatus', 'nfostatus', 'haspreview', 'jpgstatus', 'videostatus', 'isrenamed'] as $column) {
                $table->integer($column)->default(0);
            }
            $table->bigInteger('size')->default(524288000);
            $table->float('completion')->default(100);
            $table->dateTime('adddate')->nullable();
            $table->dateTime('postdate')->nullable();
        });
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('users_releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('users_id');
            $table->integer('releases_id');
            $table->timestamps();
            $table->unique(['users_id', 'releases_id']);
        });
        Schema::create('user_movies', function (Blueprint $table): void {
            $table->integer('users_id');
            $table->string('imdbid');
            $table->string('categories')->nullable();
        });
        Schema::create('user_series', function (Blueprint $table): void {
            $table->integer('users_id');
            $table->integer('videos_id');
            $table->string('categories')->nullable();
        });
    }
}
