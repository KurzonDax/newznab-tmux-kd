<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\User;
use App\Services\Search\Contracts\SearchServiceInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
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
        $search->shouldReceive('searchReleasesFiltered')->once()->withArgs(static fn (array $criteria, int $limit, int $offset): bool => $criteria['phrases'] === ['searchname' => '"part two" -cam'] && $criteria['try_fuzzy'] === false && $criteria['web_force_fuzzy'] === false && $criteria['web_after_id'] === 0 && $offset === 0)
            ->andReturn(['ids' => [$wanted], 'total' => 1, 'fuzzy' => false, 'available' => true, 'has_more' => false]);
        $response = $this->actingAs($this->browserUser())->get('/search?'.http_build_query(['q' => '"part two" -cam', 't' => '2000']))->assertOk();
        $this->assertSame([$wanted], $response->viewData('results')->pluck('id')->all());
        $this->assertSame('"part two" -cam', $response->viewData('searchState')->parameters['q']);
    }

    public function test_index_pages_are_combined_before_global_sorting_counting_and_pagination(): void
    {
        $ids = [];
        for ($index = 1; $index <= 550; $index++) {
            $ids[] = $this->release(sprintf('Result %04d', 551 - $index));
        }
        $search = app(SearchServiceInterface::class);
        $search->shouldReceive('searchReleasesFiltered')->once()->withArgs(static fn (array $criteria): bool => $criteria['web_after_id'] === 0 && ! $criteria['web_force_fuzzy'])
            ->andReturn(['ids' => array_slice($ids, 0, 500), 'total' => 550, 'fuzzy' => false, 'has_more' => true]);
        $search->shouldReceive('searchReleasesFiltered')->once()->withArgs(static fn (array $criteria): bool => $criteria['web_after_id'] === 500 && ! $criteria['web_force_fuzzy'])
            ->andReturn(['ids' => array_slice($ids, 500), 'total' => 50, 'fuzzy' => false, 'has_more' => false]);
        $response = $this->actingAs($this->browserUser())->get('/search?q=lexical&sort=title&per=24')->assertOk();
        $this->assertSame(550, $response->viewData('results')->total());
        $this->assertSame('Result 0001', $response->viewData('results')->first()->row_data->name);
        $this->assertCount(24, $response->viewData('results')->items());
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
        foreach ([0 => [1], 1 => [2], 2 => []] as $after => $ids) {
            $search->shouldReceive('searchMoviesByFields')->once()->with(['actors' => 'Chalamet'], 500, $after)
                ->andReturn(['movieinfo_ids' => $ids, 'imdbids' => [], 'data' => []]);
        }
        $response = $this->actingAs($this->browserUser())->get('/search?q=actor:Chalamet')->assertOk();
        $this->assertEqualsCanonicalizing([$first, $second], $response->viewData('results')->pluck('id')->all());
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

    public function test_index_retrieval_applies_server_filters_and_reuses_ids_across_display_preferences(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 14)->startOfDay());
        DB::table('usenet_groups')->insert(['id' => 2, 'name' => 'alt.binaries.movies']);
        $wanted = $this->release('A lexical match', ['groups_id' => 2, 'size' => 200 * 1024 * 1024, 'postdate' => now()->subDays(10)]);
        $parameters = ['q' => 'missing-literal', 't' => 2000, 'cat' => 2030, 'group' => 'alt.binaries.movies', 'minage' => 1, 'maxage' => 30, 'minsize' => 100, 'maxsize' => 600, 'minc' => 95];
        app(SearchServiceInterface::class)->shouldReceive('searchReleasesFiltered')->once()->withArgs(static fn (array $criteria): bool => $criteria['category_ids'] === [2030] && $criteria['groups_id'] === 2 && $criteria['min_size'] === 104857600 && $criteria['max_size'] === 629145600 && $criteria['min_completion'] === 95 && $criteria['min_date'] === now()->subDays(30)->timestamp && $criteria['max_date'] === now()->subDay()->timestamp)
            ->andReturn(['ids' => [$wanted], 'total' => 1, 'fuzzy' => false, 'available' => true, 'has_more' => false]);
        $this->actingAs($this->browserUser())->get('/search?'.http_build_query($parameters))->assertOk()->assertSee('A lexical match');
        $this->get('/search?'.http_build_query([...$parameters, 'per' => 24, 'sort' => 'title']))->assertOk()->assertSee('A lexical match');
    }
}
