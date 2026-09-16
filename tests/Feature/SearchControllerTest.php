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
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class SearchControllerTest extends TestCase
{
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
        foreach (['2026_09_14_011408_add_view_prefs_to_users_table', '2026_08_21_090000_create_release_audio_tags_table', '2026_08_27_150100_create_release_video_clips_table'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
        Schema::create('movieinfo', function (Blueprint $table): void {
            $table->increments('id');
            foreach (['imdbid', 'title', 'year', 'rating', 'genre', 'actors', 'director', 'plot'] as $column) {
                $table->string($column)->nullable();
            }
            $table->boolean('cover')->default(false);
        });
        $search = Mockery::mock(SearchServiceInterface::class);
        config(['nntmux.mysql_search_fallback' => true]);
        $search->shouldReceive('searchReleasesFiltered')->andReturn(['ids' => [], 'total' => 0, 'fuzzy' => false, 'available' => false])->byDefault();
        $search->shouldReceive('searchEntityFields')->andReturn(['ids' => [], 'keys' => [], 'available' => false, 'has_more' => false])->byDefault();
        $search->shouldReceive('searchMoviesByFields')->andReturn(['imdbids' => [], 'movieinfo_ids' => [], 'data' => []])->byDefault();
        $search->shouldReceive('isFuzzyEnabled')->andReturn(false)->byDefault();
        $search->shouldReceive('isSuggestEnabled')->andReturn(false)->byDefault();
        $search->shouldReceive('isAutocompleteEnabled')->andReturn(false)->byDefault();
        $this->app->instance(SearchServiceInterface::class, $search);
    }

    protected function tearDown(): void
    {
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
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
        $this->assertSame([$wanted], $response->viewData('results')->pluck('id')->all());
        $response->assertSee('data-query-chips', false)->assertSee('actor: Chalamet')->assertSee('director: villeneuve')->assertSee('Scope: Movies')
            ->assertSee('Obfuscated release')->assertSee('data-release-table', false)->assertDontSee('Search Terms')->assertDontSee('Advanced Search')
            ->assertDontSee('data-value="cards"', false)->assertDontSee('Search in Movies');
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//input[@name="q" and not(@type="hidden")]')->length);
        $remove = $xpath->query('//a[@data-remove-constraint="actors"]')->item(0)?->attributes?->getNamedItem('href')?->nodeValue;
        $this->assertNotNull($remove);
        $this->get($remove)->assertOk()->assertSee('Wrong cast release')->assertSee('director: villeneuve')->assertDontSee('actor: Chalamet');
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
        $parameters = ['t' => 'movies', 'cat' => 2030, 'minage' => 1, 'maxage' => 30, 'minsize' => 100, 'maxsize' => 600, 'minc' => 95];
        $response = $this->actingAs($this->browserUser())->get('/search?'.http_build_query($parameters))->assertOk();
        $this->assertSame([$wanted], $response->viewData('results')->pluck('id')->all());
        $response->assertSee('Age ≤ 30 d')->assertSee('Age ≥ 1 d')->assertSee('Size ≥ 100 MB')->assertSee('Size ≤ 600 MB')->assertSee('Completion ≥ 95%')->assertSee('Category: Movies · HD');
        $chips = collect($response->viewData('queryChips'))->keyBy('key');
        $withoutCompletion = $this->get($chips['minc']['url'])->assertOk();
        $this->assertEqualsCanonicalizing([$wanted, $low], $withoutCompletion->viewData('results')->pluck('id')->all());
        $this->assertCount(7, $chips);
        $this->get($chips['maxage']['url'])->assertOk()->assertSee('Too old')->assertDontSee('Too recent');
    }

    public function test_add_filter_form_replaces_a_field_and_preserves_the_other_constraints(): void
    {
        DB::table('movieinfo')->insert(['imdbid' => '1234567', 'title' => 'Dune', 'actors' => 'Chalamet', 'director' => 'Villeneuve']);
        $wanted = $this->release('Unfinished movie', ['imdbid' => '1234567']);
        $response = $this->actingAs($this->browserUser())->get('/search?'.http_build_query([
            'q' => 'dune actor:wrong director:villeneuve', 't' => '2000', 'maxage' => '30',
            'filter' => 'actors', 'filter_value' => 'Chalamet', 'page' => '8',
        ]))->assertRedirect();
        $response = $this->get($response->headers->get('Location'))->assertOk();
        $this->assertSame([$wanted], $response->viewData('results')->pluck('id')->all());
        $response->assertSee('actor: Chalamet')->assertSee('director: villeneuve')->assertSee('Age ≤ 30 d')->assertSee('+ Add filter');
        $this->assertArrayNotHasKey('filter', $response->viewData('searchState')->parameters);
        $this->assertSame(1, $response->viewData('results')->currentPage());
    }

    public function test_index_search_keeps_phrase_and_exclusion_syntax_when_rebuilding_chips(): void
    {
        $wanted = $this->release('Lexical hit without literal words');
        $this->release('part two cam');
        $this->release('part other two');
        $search = app(SearchServiceInterface::class);
        $search->shouldReceive('searchReleasesFiltered')->once()->withArgs(static fn (array $criteria, int $limit, int $offset): bool => $criteria['phrases'] === ['searchname' => '"part two" -cam'] && $criteria['try_fuzzy'] === false && $criteria['web_force_fuzzy'] === false && $offset === 0)
            ->andReturn(['ids' => [$wanted], 'total' => 1, 'fuzzy' => false, 'available' => true, 'has_more' => false]);
        $response = $this->actingAs($this->browserUser())->get('/search?'.http_build_query(['q' => '"part two" -cam', 't' => '2000']))->assertOk();
        $this->assertSame([$wanted], $response->viewData('results')->pluck('id')->all());
        $this->assertSame('"part two" -cam', $response->viewData('searchState')->parameters['q']);
    }

    /** @return iterable<string, array{int, int, int, int}> */
    public static function largePages(): iterable
    {
        yield 'first' => [1, 24, 0, 24];
        yield 'middle' => [57, 48, 2688, 48];
        yield 'last full' => [100, 100, 9900, 100];
        yield 'last partial' => [417, 24, 9984, 16];
    }

    #[DataProvider('largePages')]
    public function test_large_search_fetches_only_the_requested_page_and_preserves_exact_total(int $page, int $per, int $offset, int $limit): void
    {
        $ids = [];
        for ($i = 0; $i < $limit; $i++) {
            $ids[] = $this->release('Result '.$i);
        }
        app(SearchServiceInterface::class)->shouldReceive('searchReleasesFiltered')->once()
            ->withArgs(static fn (array $criteria, int $requestedLimit, int $requestedOffset): bool => $requestedLimit === $limit && $requestedOffset === $offset && $criteria['sort_field'] === 'sort_name')
            ->andReturn(['ids' => $ids, 'total' => 1000000, 'available' => true, 'has_more' => true]);
        $this->actingAs($this->browserUser());
        DB::enableQueryLog();
        $response = $this->get('/search?q=lexical&sort=title&per='.$per.'&page='.$page)->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            if (str_contains($query['query'], 'from "releases"')) {
                preg_match_all('/(?:"id"|"releases_id") in \(([^)]+)\)/i', $query['query'], $lists);
                foreach ($lists[1] as $list) {
                    $this->assertLessThanOrEqual($per, count(explode(',', $list)));
                }
            }
        }
        $this->assertSame(1000000, $response->viewData('results')->total());
        $this->assertSame($page, $response->viewData('results')->currentPage());
        $this->assertCount($limit, $response->viewData('results')->items());
        $response->assertSee('narrower search');
    }

    public function test_repeated_quoted_prefixes_round_trip_without_losing_scope_or_growing_groups(): void
    {
        $user = $this->browserUser();
        $state = WebSearchState::fromRequest(new Request(['q' => '1080p actor:"Hugh Jackman" actor:"Patrick Stewart" director:(scorsese | nolan)']), $user);
        $canonical = $state->parameters['q'];
        for ($i = 0; $i < 3; $i++) {
            $state = WebSearchState::fromRequest(new Request(['q' => $state->parameters['q']]), $user);
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
        $this->actingAs($this->browserUser())->get('/search?poster=%20user%40example.com')->assertOk();
    }

    public function test_memory_does_not_grow_with_reported_match_count(): void
    {
        $ids = [];
        for ($i = 0; $i < 24; $i++) {
            $ids[] = $this->release('Memory fixture '.$i);
        }
        app(SearchServiceInterface::class)->shouldReceive('searchReleasesFiltered')->times(3)
            ->andReturn(['ids' => $ids, 'total' => 1000000, 'available' => true], ['ids' => $ids, 'total' => 1000000, 'available' => true], ['ids' => $ids, 'total' => 1000000000, 'available' => true]);
        $this->actingAs($this->browserUser())->get('/search?q=memory&per=24')->assertOk();
        $peaks = [];
        for ($i = 0; $i < 2; $i++) {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $start = memory_get_usage();
            $response = $this->get('/search?q=memory&per=24')->assertOk();
            $this->assertCount(24, $response->viewData('results'));
            $peaks[] = memory_get_peak_usage() - $start;
            unset($response);
        }
        $this->assertLessThan($peaks[0] + 2 * 1024 * 1024, $peaks[1]);
    }

    public function test_outage_has_no_zero_count_and_no_deep_page_redirect(): void
    {
        config(['nntmux.mysql_search_fallback' => false]);
        $response = $this->actingAs($this->browserUser())->get('/search?q=Dune&page=999')->assertOk();
        $response->assertSee('Search unavailable')->assertDontSee('data-browser-pager', false);
        $this->assertFalse($response->viewData('results')->available);
        $this->assertCount(0, $response->viewData('results'));
    }

    public function test_page_window_honors_the_configured_driver_limit(): void
    {
        config(['search.default' => 'manticore', 'search.drivers.manticore.max_matches' => 50]);
        app(SearchServiceInterface::class)->shouldReceive('searchReleasesFiltered')->once()
            ->withArgs(static fn (array $criteria, int $limit, int $offset): bool => $limit === 2 && $offset === 48)
            ->andReturn(['ids' => [], 'total' => 1000000, 'available' => true]);
        $response = $this->actingAs($this->browserUser())->get('/search?q=Dune&per=24&page=999')->assertRedirect();
        $this->assertStringContainsString('page=3', $response->headers->get('Location'));
    }

    public function test_page_beyond_the_reachable_window_clamps_before_index_retrieval(): void
    {
        app(SearchServiceInterface::class)->shouldReceive('searchReleasesFiltered')->once()
            ->withArgs(static fn (array $criteria, int $limit, int $offset): bool => $limit === 16 && $offset === 9984)
            ->andReturn(['ids' => [], 'total' => 1000000, 'available' => true]);
        $response = $this->actingAs($this->browserUser())->get('/search?q=Dune&per=24&page=999')->assertRedirect();
        $this->assertStringContainsString('page=417', $response->headers->get('Location'));
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
        $this->assertSame([$wanted], $response->viewData('results')->pluck('id')->all());
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
        $response = $this->actingAs($this->browserUser())->get('/search?q=actor:Chalamet')->assertOk();
        $this->assertEqualsCanonicalizing([$first, $second], $response->viewData('results')->pluck('id')->all());
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

    public function test_search_feed_uses_existing_api_parameters_and_explains_website_only_filters(): void
    {
        $user = $this->browserUser();
        $response = $this->actingAs($user)->get('/search?q=dune%20actor:Chalamet&t=2000&minsize=100&maxsize=600&maxage=30&minc=95')->assertOk();
        $response->assertSee('RSS for this search')->assertSee('These filters apply on the website only:')->assertSee('Completion ≥ 95%');
        parse_str(parse_url($response->viewData('searchState')->rssUrl($user), PHP_URL_QUERY), $parameters);
        $this->assertSame(['t' => 'search', 'apikey' => $user->api_token, 'o' => 'xml', 'q' => 'dune', 'cat' => '2000', 'maxage' => '30', 'minsize' => '104857600'], $parameters);
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
        Schema::create('anidb_info', function (Blueprint $table): void {
            $table->integer('anidbid');
            $table->date('startdate')->nullable();
        });
        Schema::create('anidb_titles', function (Blueprint $table): void {
            $table->integer('anidbid');
            $table->string('title');
            $table->string('lang');
            $table->string('type');
        });
        DB::table('musicinfo')->insert(['id' => 1, 'title' => 'In Rainbows', 'artist' => 'Radiohead']);
        DB::table('anidb_info')->insert(['anidbid' => 12]);
        DB::table('anidb_titles')->insert(['anidbid' => 12, 'title' => 'Radiohead Adventure', 'lang' => 'en', 'type' => 'main']);
        $audio = $this->release('Obfuscated album', ['categories_id' => 3030, 'musicinfo_id' => 1]);
        $anime = $this->release('Obfuscated anime', ['categories_id' => 5030, 'anidbid' => 12]);
        $user = $this->browserUser();
        $response = $this->actingAs($user)->get('/search?q=Radiohead')->assertOk();
        $this->assertEqualsCanonicalizing([$audio, $anime], $response->viewData('results')->pluck('id')->all());
        $this->get('/search?q=Radiohead&t=audio')->assertOk()->assertSee('Obfuscated album')->assertDontSee('Obfuscated anime');
        $user->revokePermissionTo('view audio');
        Cache::forget(User::categoryExclusionCacheKey($user->id));
        $this->get('/search?q=Radiohead')->assertOk()->assertDontSee('Obfuscated album')->assertSee('Obfuscated anime');
    }

    public function test_legacy_inputs_keep_constraints_and_explicit_sort_wins_over_old_ordering(): void
    {
        DB::table('movieinfo')->insert(['imdbid' => '1234567', 'title' => 'Dune Part Two']);
        $old = $this->release('A release', ['imdbid' => '1234567', 'adddate' => '2026-09-10 00:00:00']);
        $new = $this->release('Z release', ['imdbid' => '1234567', 'adddate' => '2026-09-13 00:00:00']);
        $this->release('Wrong title', ['imdbid' => '7654321']);
        $parameters = ['q' => 'Dune', 'search' => 'ignored', 'search_type' => 'adv', 'title' => 'Part Two', 'searchadvcat' => '2000', 'sort' => 'newest', 'ob' => 'name_asc'];
        $response = $this->actingAs($this->browserUser())->get('/search?'.http_build_query($parameters))->assertOk();
        $this->assertSame([$new, $old], $response->viewData('results')->pluck('id')->all());
        $response->assertSee('title: Part Two')->assertDontSee('Advanced Search')->assertDontSee('ignored');
        $this->assertArrayNotHasKey('search_type', $response->viewData('searchState')->parameters);
        $this->get('/search?'.http_build_query(['q' => ['bad'], 't' => ['2000'], 'maxage' => ['bad'], 'minsize' => '-1', 'page' => ['1']]))->assertOk();
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
        app(SearchServiceInterface::class)->shouldReceive('searchMoviesByFields')->never();
        $response = $this->actingAs($this->browserUser())->get('/search?q=dune%20-cam')->assertOk();
        $this->assertEqualsCanonicalizing([$dune, $both], $response->viewData('results')->pluck('id')->all());
        $response = $this->get('/search?q='.rawurlencode('(dune | arrival) -(cam | ts)'))->assertOk();
        $this->assertEqualsCanonicalizing([$dune, $arrival, $both], $response->viewData('results')->pluck('id')->all());
    }

    public function test_index_retrieval_applies_server_filters_for_each_page(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 14)->startOfDay());
        DB::table('usenet_groups')->insert(['id' => 2, 'name' => 'alt.binaries.movies']);
        $wanted = $this->release('A lexical match', ['groups_id' => 2, 'size' => 200 * 1024 * 1024, 'postdate' => now()->subDays(10)]);
        $parameters = ['q' => 'missing-literal', 't' => 2000, 'cat' => 2030, 'group' => 'alt.binaries.movies', 'minage' => 1, 'maxage' => 30, 'minsize' => 100, 'maxsize' => 600, 'minc' => 95];
        app(SearchServiceInterface::class)->shouldReceive('searchReleasesFiltered')->twice()->withArgs(static fn (array $criteria): bool => $criteria['category_ids'] === [2030] && $criteria['groups_id'] === 2 && $criteria['min_size'] === 104857600 && $criteria['max_size'] === 629145600 && $criteria['min_completion'] === 95 && $criteria['min_date'] === now()->subDays(30)->timestamp && $criteria['max_date'] === now()->subDay()->timestamp)
            ->andReturn(['ids' => [$wanted], 'total' => 1, 'fuzzy' => false, 'available' => true, 'has_more' => false]);
        $this->actingAs($this->browserUser())->get('/search?'.http_build_query($parameters))->assertOk()->assertSee('A lexical match');
        $this->get('/search?'.http_build_query([...$parameters, 'per' => 24, 'sort' => 'title']))->assertOk()->assertSee('A lexical match');
    }
}
