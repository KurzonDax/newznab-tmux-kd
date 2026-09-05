<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MovieInfo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * Coverage for the admin movie list (#449).
 *
 * Two defects compounded here: `MovieInfo::getAll()` cached its paginator under a bare
 * `md5($search)`, so the first page fetched for a term was replayed for every later page,
 * and the view rendered no paginator at all -- which is why nothing noticed.
 */
class AdminMovieListPageTest extends TestCase
{
    use InteractsWithAdminListPages;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->createMovieSchema();

        // The row links out through the site's dereferrer.
        DB::table('settings')->upsert(
            [['name' => 'dereferrer_link', 'value' => '']],
            ['name'],
            ['value']
        );
        $this->resetGlobalComposerState();
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_get_all_returns_the_requested_page_rather_than_the_cached_first_page(): void
    {
        $this->createMovies(['First Movie', 'Second Movie', 'Third Movie']);

        $this->withCurrentPage(1);
        $firstPage = MovieInfo::getAll();

        $this->withCurrentPage(2);
        $secondPage = MovieInfo::getAll();

        $this->assertSame(1, $firstPage->currentPage());
        $this->assertSame(2, $secondPage->currentPage());
        $this->assertSame('First Movie', $firstPage->items()[0]->title);
        $this->assertSame('Third Movie', $secondPage->items()[0]->title);
    }

    public function test_get_all_does_not_occupy_an_unnamespaced_cache_key(): void
    {
        $this->createMovies(['Namespaced Movie']);

        $this->withCurrentPage(1);
        MovieInfo::getAll();

        $this->assertFalse(
            Cache::has(md5('')),
            'The movie listing must not cache under a bare md5() of the search term.'
        );
    }

    public function test_get_all_caches_each_search_term_independently(): void
    {
        $this->createMovies(['Alpha Movie', 'Beta Movie']);

        $this->withCurrentPage(1);

        $alpha = MovieInfo::getAll('Alpha');
        $beta = MovieInfo::getAll('Beta');

        $this->assertSame(1, $alpha->total());
        $this->assertSame(1, $beta->total());
        $this->assertSame('Alpha Movie', $alpha->items()[0]->title);
        $this->assertSame('Beta Movie', $beta->items()[0]->title);
    }

    public function test_get_all_still_serves_a_repeated_request_from_the_cache(): void
    {
        $this->createMovies(['Cached Movie']);

        $this->withCurrentPage(1);
        $first = MovieInfo::getAll();

        $this->createMovies(['Added Later Movie']);

        $second = MovieInfo::getAll();

        $this->assertSame(1, $first->total());
        $this->assertSame(1, $second->total(), 'A repeated request must come back from the cache.');
        $this->assertSame('Cached Movie', $second->items()[0]->title);
    }

    public function test_get_all_matches_a_title_substring_or_an_exact_imdb_id(): void
    {
        $this->createMovies(['The Matrix', 'Casablanca']);
        $matrixImdbId = (string) DB::table('movieinfo')->where('title', 'The Matrix')->value('imdbid');

        $this->withCurrentPage(1);

        $byTitle = MovieInfo::getAll('Matri');
        $byImdbId = MovieInfo::getAll($matrixImdbId);

        $this->assertSame(1, $byTitle->total());
        $this->assertSame('The Matrix', $byTitle->items()[0]->title);
        $this->assertSame(1, $byImdbId->total());
        $this->assertSame('The Matrix', $byImdbId->items()[0]->title);
    }

    public function test_movie_list_renders_one_paginator_reaching_the_second_page(): void
    {
        $this->createMovies(['First Movie', 'Second Movie', 'Third Movie']);

        $response = $this->actingAs($this->admin())->get(route('admin.movie-list'));

        $response->assertOk();
        $response->assertSee('First Movie');
        $response->assertSee('Second Movie');
        $response->assertDontSee('Third Movie');
        $this->assertSame(
            1,
            substr_count((string) $response->getContent(), 'aria-label="Pagination Navigation"'),
            'The movie list must render exactly one paginator.'
        );
        $response->assertSee('page=2', false);
    }

    public function test_movie_list_second_page_shows_the_rows_the_first_page_did_not(): void
    {
        $this->createMovies(['First Movie', 'Second Movie', 'Third Movie']);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.movie-list'))->assertOk();

        $secondPage = $this->actingAs($admin)->get(route('admin.movie-list', ['page' => 2]));

        $secondPage->assertOk();
        $secondPage->assertSee('Third Movie');
        $secondPage->assertDontSee('First Movie');
    }

    public function test_movie_list_pagination_keeps_the_active_search(): void
    {
        $this->createMovies(['Match One', 'Match Two', 'Match Three', 'Other Film']);
        $admin = $this->admin();

        $firstPage = $this->actingAs($admin)->get(route('admin.movie-list', ['moviesearch' => 'Match']));

        $firstPage->assertOk();
        $firstPage->assertSee('moviesearch=Match', false);
        $firstPage->assertSee('page=2', false);

        $secondPage = $this->actingAs($admin)
            ->get(route('admin.movie-list', ['moviesearch' => 'Match', 'page' => 2]));

        $secondPage->assertOk();
        $secondPage->assertSee('Match Three');
        $secondPage->assertDontSee('Other Film');
    }

    public function test_movie_list_renders_no_paginator_for_a_single_page(): void
    {
        $this->createMovies(['Only Movie']);

        $response = $this->actingAs($this->admin())->get(route('admin.movie-list'));

        $response->assertOk();
        $response->assertSee('Only Movie');
        $response->assertDontSee('aria-label="Pagination Navigation"', false);
    }

    public function test_movie_list_renders_the_empty_state_without_a_paginator(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.movie-list'));

        $response->assertOk();
        $response->assertSee('No movies found');
        $response->assertDontSee('aria-label="Pagination Navigation"', false);
    }

    public function test_a_blank_movie_search_still_lists_everything(): void
    {
        $this->createMovies(['First Movie', 'Second Movie']);

        $response = $this->actingAs($this->admin())->get(route('admin.movie-list', ['moviesearch' => '']));

        $response->assertOk();
        $response->assertSee('First Movie');
        $response->assertSee('Second Movie');
    }

    /**
     * @param  list<string>  $titles
     */
    private function createMovies(array $titles): void
    {
        $offset = DB::table('movieinfo')->count();
        $rows = [];

        foreach (array_values($titles) as $index => $title) {
            $rows[] = [
                'imdbid' => str_pad((string) ($offset + $index + 1), 7, '0', STR_PAD_LEFT),
                'title' => $title,
                'year' => '2001',
                'rating' => '7.5',
                'genre' => 'Drama',
                'cover' => 1,
                'backdrop' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('movieinfo')->insert($rows);
    }

    private function createMovieSchema(): void
    {
        Schema::create('movieinfo', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('imdbid', 100)->unique();
            $table->unsignedInteger('tmdbid')->default(0);
            $table->unsignedInteger('traktid')->default(0);
            $table->string('title')->default('');
            $table->string('tagline', 1024)->default('');
            $table->string('rating', 4)->default('');
            $table->string('rtrating', 10)->default('');
            $table->string('plot', 1024)->default('');
            $table->string('year', 4)->default('');
            $table->string('genre', 64)->default('');
            $table->string('type', 32)->default('');
            $table->string('director', 64)->default('');
            $table->string('actors', 2000)->default('');
            $table->string('language', 64)->default('');
            $table->boolean('cover')->default(false);
            $table->boolean('backdrop')->default(false);
            $table->string('trailer')->default('');
            $table->timestamps();
        });
    }
}
