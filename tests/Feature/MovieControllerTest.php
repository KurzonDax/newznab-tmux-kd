<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Http\Middleware\ClearanceMiddleware;
use App\Http\Middleware\Google2FAMiddleware;
use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\Category;
use App\Models\User;
use App\View\Composers\GlobalDataComposer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use ReflectionClass;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class MovieControllerTest extends TestCase
{
    use IsolatedSqliteDatabase;
    use MockeryPHPUnitIntegration;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return [
            'showpasswordedrelease' => '0',
            'categorizeforeign' => '0',
            'catwebdl' => '0',
            'title' => 'NNTmux Test',
            'home_link' => '/',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'mail.from.address' => 'noreply@example.test',
            'mail.from.name' => 'NNTmux Tests',
            'nntmux.items_per_cover_page' => 50,
            'nntmux.cache_expiry_medium' => 5,
            'nntmux.cache_expiry_long' => 5,
        ]);

        Cache::flush();
        $this->createSchema();
        $this->seedBaseData();
        $this->seedMovies();
        $this->resetGlobalComposerState();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->withoutMiddleware([
            ClearanceMiddleware::class,
            Google2FAMiddleware::class,
            TrustedDevice2FAMiddleware::class,
        ]);

        Search::shouldReceive('isAvailable')->byDefault()->andReturn(true);
        Search::shouldReceive('searchMoviesByFields')->byDefault()->andReturn([
            'imdbids' => [], 'movieinfo_ids' => [], 'data' => [],
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /**
     * A basic feature test example.
     */
    public function test_example(): void
    {
        $response = $this->get('/');

        $response->assertStatus(302);
    }

    public function test_genre_filter_uses_sql_when_the_movie_index_is_empty(): void
    {
        $response = $this->actingAs($this->createUser())->get(route('Movies', ['genre' => 'Drama']));

        $response->assertOk();
        $response->assertSee('The Dark Knight');
        $response->assertDontSee('The Matrix');
    }

    public function test_rating_filter_treats_integer_rows_as_a_numeric_minimum(): void
    {
        $response = $this->actingAs($this->createUser())->get(route('Movies', ['rating' => 7]));

        $response->assertOk();
        $response->assertSee('The Terminal');
        $response->assertSee('The Matrix');
        $response->assertDontSee('Low Rated Movie');
    }

    public function test_plain_words_use_all_movie_text_fields_with_sql_fallback(): void
    {
        $response = $this->actingAs($this->createUser())->get(route('Movies', ['q' => 'nolan batman']));

        $response->assertOk();
        $response->assertSee('The Dark Knight');
        $response->assertDontSee('Inception');
    }

    public function test_partial_words_use_sql_fallback_when_the_index_has_no_match(): void
    {
        $response = $this->actingAs($this->createUser())->get(route('Movies', ['q' => 'matri']));

        $response->assertOk();
        $response->assertSee('The Matrix');
        $response->assertDontSee('The Dark Knight');
    }

    public function test_prefixed_terms_are_anded_and_limited_to_their_fields(): void
    {
        $response = $this->actingAs($this->createUser())->get(route('Movies', [
            'q' => 'actor:"tom hanks" director:spielberg',
        ]));

        $response->assertOk();
        $response->assertSee('The Terminal');
        $response->assertDontSee('The Dark Knight');
    }

    public function test_plot_prefix_only_matches_plot(): void
    {
        $response = $this->actingAs($this->createUser())->get(route('Movies', ['q' => 'plot:heist']));

        $response->assertOk();
        $response->assertSee('Heat');
        $response->assertDontSee('Heist Director');
    }

    public function test_advanced_fields_are_anded(): void
    {
        $response = $this->actingAs($this->createUser())->get(route('Movies', [
            'director' => 'Nolan', 'actor' => 'Bale',
        ]));

        $response->assertOk();
        $response->assertSee('The Dark Knight');
        $response->assertDontSee('Inception');
    }

    public function test_year_picker_supports_decades_custom_ranges_and_single_years(): void
    {
        $user = $this->createUser();

        $decade = $this->actingAs($user)->get(route('Movies', ['year' => '1970s']));
        $decade->assertOk();
        $decade->assertSee('Jaws');
        $decade->assertSee('Alien');
        $decade->assertDontSee('The Shining');

        $custom = $this->actingAs($user)->get(route('Movies', [
            'year' => 'custom', 'year_from' => 1970, 'year_to' => 1975,
        ]));
        $custom->assertOk();
        $custom->assertSee('Jaws');
        $custom->assertDontSee('Alien');

        $single = $this->actingAs($user)->get(route('Movies', ['year' => '1999']));
        $single->assertOk();
        $single->assertSee('The Matrix');
        $single->assertDontSee('Jaws');
    }

    public function test_filter_form_preserves_category_and_replaces_release_search(): void
    {
        $response = $this->actingAs($this->createUser())->get(route('Movies', ['id' => 'HD']));

        $response->assertOk();
        $response->assertSee('name="t" value="2040"', false);
        $response->assertSee('name="q"', false);
        $response->assertSee('Advanced');
        $response->assertSee('value="1970s"', false);
        $response->assertSee('value="custom"', false);
        $response->assertDontSee('Search in Movies');
    }

    private function createSchema(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name')->default('web');
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name')->default('web');
            $table->timestamps();
        });
        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedInteger('role_id');
            $table->string('model_type');
            $table->unsignedInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
        });
        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->unsignedInteger('permission_id');
            $table->string('model_type');
            $table->unsignedInteger('model_id');
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });
        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->unsignedInteger('permission_id');
            $table->unsignedInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('username');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedInteger('roles_id')->default(1);
            $table->integer('rate_limit')->default(60);
            $table->string('api_token')->nullable();
            $table->boolean('verified')->default(true);
            $table->boolean('can_post')->default(true);
            $table->string('theme_preference', 10)->default('light');
            $table->string('color_scheme', 10)->default('blue');
            $table->integer('movie_layout')->default(2);
            $table->string('session_token')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('lastlogin')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('user_excluded_categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('users_id');
            $table->unsignedInteger('categories_id');
        });
        Schema::create('content', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->default('');
            $table->string('url')->nullable();
            $table->text('body')->nullable();
            $table->text('metadescription')->nullable();
            $table->text('metakeywords')->nullable();
            $table->integer('contenttype')->default(1);
            $table->integer('status')->default(1);
            $table->integer('ordinal')->nullable();
            $table->integer('role')->default(0);
        });
        Schema::create('root_categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->default('');
            $table->integer('status')->default(1);
        });
        Schema::create('categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->default('');
            $table->unsignedInteger('parentid')->nullable();
            $table->unsignedInteger('root_categories_id')->nullable();
            $table->integer('status')->default(1);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('minsizetoformrelease')->default(0);
            $table->unsignedBigInteger('maxsizetoformrelease')->default(0);
        });
        Schema::create('movieinfo', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('imdbid')->unique();
            $table->unsignedInteger('tmdbid')->default(0);
            $table->unsignedInteger('traktid')->default(0);
            $table->string('title')->default('');
            $table->string('year', 4)->default('');
            $table->string('rating', 4)->default('');
            $table->text('plot')->default('');
            $table->string('genre')->default('');
            $table->string('director')->default('');
            $table->text('actors')->default('');
            $table->boolean('cover')->default(false);
            $table->timestamps();
        });
        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('searchname')->default('');
            $table->float('completion')->default(0);
            $table->string('repair_outcome')->nullable();
            $table->string('rescan_outcome')->nullable();
            $table->string('display_name')->nullable();
            $table->dateTime('postdate')->nullable();
            $table->dateTime('adddate')->nullable();
            $table->string('guid')->nullable();
            $table->unsignedInteger('categories_id')->default(Category::MOVIE_HD);
            $table->unsignedBigInteger('size')->default(0);
            $table->integer('passwordstatus')->default(0);
            $table->integer('haspreview')->default(0);
            $table->integer('videostatus')->default(0);
            $table->string('imdbid')->nullable();
        });

        Schema::create('release_audio_tags', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id')->primary();
            $table->string('audio_format', 50)->nullable();
            $table->unsignedTinyInteger('has_preview')->default(0);
            $table->string('preview_extension', 8)->nullable();
            $table->string('preview_mime', 32)->nullable();
            $table->unsignedSmallInteger('preview_seconds')->nullable();
            $table->unsignedTinyInteger('has_spectrogram')->default(0);
        });

        Schema::create('release_video_clips', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id')->primary();
            $table->string('extension', 8);
            $table->string('mime', 32);
        });
    }

    private function seedBaseData(): void
    {
        DB::table('root_categories')->insert(['id' => Category::MOVIE_ROOT, 'title' => 'Movies', 'status' => 1]);
        DB::table('categories')->insert([
            ['id' => Category::MOVIE_HD, 'title' => 'HD', 'root_categories_id' => Category::MOVIE_ROOT, 'status' => 1, 'description' => 'Movie HD'],
            ['id' => Category::MOVIE_SD, 'title' => 'SD', 'root_categories_id' => Category::MOVIE_ROOT, 'status' => 1, 'description' => 'Movie SD'],
        ]);
        DB::table('roles')->insert(['id' => 1, 'name' => 'User', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permissions')->insert(['id' => 1, 'name' => 'view movies', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('role_has_permissions')->insert(['permission_id' => 1, 'role_id' => 1]);
    }

    private function seedMovies(): void
    {
        $this->createMovie('0468569', 'The Dark Knight', '2008', '9', 'Action, Drama', 'Christopher Nolan', 'Christian Bale, Heath Ledger', 'Batman faces the Joker.');
        $this->createMovie('1375666', 'Inception', '2010', '8', 'Action, Sci-Fi', 'Christopher Nolan', 'Leonardo DiCaprio', 'A thief enters dreams.');
        $this->createMovie('0133093', 'The Matrix', '1999', '8', 'Action, Sci-Fi', 'Lana Wachowski', 'Keanu Reeves', 'A hacker discovers the Matrix.');
        $this->createMovie('0362227', 'The Terminal', '2004', '7', 'Comedy, Drama', 'Steven Spielberg', 'Tom Hanks', 'A traveler lives in an airport.');
        $this->createMovie('0113277', 'Heat', '1995', '8', 'Crime, Drama', 'Michael Mann', 'Al Pacino, Robert De Niro', 'A detective pursues a bank heist crew.');
        $this->createMovie('9990001', 'Heist Director', '2001', '6', 'Documentary', 'Heist', 'Someone Else', 'A portrait of a filmmaker.');
        $this->createMovie('9990002', 'Low Rated Movie', '2018', '6', 'Comedy', 'Example Director', 'Example Actor', 'A deliberately low-rated movie.');
        $this->createMovie('0073195', 'Jaws', '1975', '8', 'Adventure, Drama', 'Steven Spielberg', 'Roy Scheider', 'A shark terrorizes a beach town.');
        $this->createMovie('0078748', 'Alien', '1979', '8', 'Horror, Sci-Fi', 'Ridley Scott', 'Sigourney Weaver', 'A creature stalks a spaceship.');
        $this->createMovie('0081505', 'The Shining', '1980', '8', 'Drama, Horror', 'Stanley Kubrick', 'Jack Nicholson', 'A haunted hotel.');
    }

    private function createMovie(string $imdbId, string $title, string $year, string $rating, string $genre, string $director, string $actors, string $plot): void
    {
        DB::table('movieinfo')->insert([
            'imdbid' => $imdbId, 'title' => $title, 'year' => $year, 'rating' => $rating,
            'genre' => $genre, 'director' => $director, 'actors' => $actors, 'plot' => $plot,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('releases')->insert([
            'searchname' => str_replace(' ', '.', $title).'.'.$year.'.1080p-GROUP',
            'postdate' => now(), 'adddate' => now(), 'guid' => sha1($imdbId),
            'categories_id' => Category::MOVIE_HD, 'size' => 1024, 'passwordstatus' => 0,
            'haspreview' => 0, 'imdbid' => $imdbId,
        ]);
    }

    private function createUser(): User
    {
        $userId = DB::table('users')->insertGetId([
            'username' => 'movie-user', 'email' => 'movies-'.uniqid().'@example.test',
            'password' => bcrypt('secret'), 'roles_id' => 1, 'api_token' => 'movie-token',
            'verified' => true, 'can_post' => true, 'theme_preference' => 'light',
            'color_scheme' => 'blue', 'movie_layout' => 2, 'email_verified_at' => now(),
            'lastlogin' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('model_has_roles')->insert(['role_id' => 1, 'model_type' => User::class, 'model_id' => $userId]);
        DB::table('model_has_permissions')->insert(['permission_id' => 1, 'model_type' => User::class, 'model_id' => $userId]);

        return User::query()->findOrFail($userId);
    }

    private function resetGlobalComposerState(): void
    {
        $reflection = new ReflectionClass(GlobalDataComposer::class);
        $property = $reflection->getProperty('resolvedData');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }
}
