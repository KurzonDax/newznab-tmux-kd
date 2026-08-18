<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\ClearanceMiddleware;
use App\Http\Middleware\Google2FAMiddleware;
use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\Category;
use App\Models\TvInfo;
use App\Models\User;
use App\View\Composers\GlobalDataComposer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PDO;
use ReflectionClass;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SeriesControllerTest extends TestCase
{
    private string $databasePath;

    /**
     * @var array<string, string|false>
     */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $this->databasePath = $this->makeTempPath('nntmux-series-controller-test', '.sqlite');

        $this->originalEnvironment = [
            'APP_ENV' => getenv('APP_ENV'),
            'DB_CONNECTION' => getenv('DB_CONNECTION'),
            'DB_DATABASE' => getenv('DB_DATABASE'),
        ];

        if (file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        $pdo = new PDO('sqlite:'.$this->databasePath);
        $pdo->exec('CREATE TABLE settings (name VARCHAR PRIMARY KEY, value TEXT NULL)');
        $pdo->exec("INSERT INTO settings (name, value) VALUES
            ('showpasswordedrelease', '0'),
            ('categorizeforeign', '0'),
            ('catwebdl', '0'),
            ('title', 'NNTmux Test'),
            ('home_link', '/')");

        $this->setEnvironmentValue('APP_ENV', 'testing');
        $this->setEnvironmentValue('DB_CONNECTION', 'sqlite');
        $this->setEnvironmentValue('DB_DATABASE', $this->databasePath);

        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
            'mail.from.address' => 'noreply@example.test',
            'mail.from.name' => 'NNTmux Tests',
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'nntmux.series_view_limit' => 20,
        ]);

        DB::purge();
        DB::reconnect();
        Cache::flush();

        $this->createSchema();
        $this->seedBaseData();
        $this->resetGlobalComposerState();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->withoutMiddleware([
            ClearanceMiddleware::class,
            Google2FAMiddleware::class,
            TrustedDevice2FAMiddleware::class,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->databasePath !== '' && file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();

        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironmentValue($key, $value === false ? null : $value);
        }
    }

    public function test_selected_season_renders_only_that_season_releases(): void
    {
        $user = $this->createUser();
        $videoId = $this->createShow();
        $this->createMatchedRelease($videoId, 1, 1, 'Only.Season.One.S01E01.720p-GROUP');
        $this->createMatchedRelease($videoId, 2, 1, 'Only.Season.Two.S02E01.720p-GROUP');

        $seasonOne = $this->actingAs($user)->get(route('series', ['id' => $videoId, 'season' => 1]));
        $seasonOne->assertOk();
        $seasonOne->assertSee('Only.Season.One.S01E01.720p-GROUP');
        $seasonOne->assertDontSee('Only.Season.Two.S02E01.720p-GROUP');

        $seasonTwo = $this->actingAs($user)->get(route('series', ['id' => $videoId, 'season' => 2]));
        $seasonTwo->assertOk();
        $seasonTwo->assertSee('Only.Season.Two.S02E01.720p-GROUP');
        $seasonTwo->assertDontSee('Only.Season.One.S01E01.720p-GROUP');
    }

    public function test_season_links_preserve_category_and_reset_page(): void
    {
        $user = $this->createUser();
        $videoId = $this->createShow();
        $this->createMatchedRelease($videoId, 1, 1, 'Link.Test.S01E01.720p-GROUP');
        $this->createMatchedRelease($videoId, 2, 1, 'Link.Test.S02E01.720p-GROUP');

        $response = $this->actingAs($user)->get(route('series', [
            'id' => $videoId,
            'season' => 1,
            'page' => 3,
            't' => Category::TV_SD,
            'year' => now()->year,
        ]));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('season=2', $html);
        $this->assertStringContainsString('page=1', $html);
        $this->assertStringContainsString('t=5030', $html);
        $this->assertStringContainsString('year='.now()->year, $html);
        $this->assertStringContainsString('#series-episodes', $html);
        $this->assertStringContainsString('x-data="seriesSeasonLoader"', $html);
        $this->assertStringContainsString('data-series-season-link', $html);
        $this->assertStringNotContainsString('season=2&amp;page=3', $html);
    }

    public function test_lazy_season_fragment_returns_only_selected_season_html(): void
    {
        $user = $this->createUser();
        $videoId = $this->createShow();
        $this->createMatchedRelease($videoId, 1, 1, 'Lazy.Test.S01E01.720p-GROUP');
        $this->createMatchedRelease($videoId, 2, 1, 'Lazy.Test.S02E01.720p-GROUP');

        $response = $this->actingAs($user)->getJson(route('series', [
            'id' => $videoId,
            'season' => 2,
            '_fragment' => 'season',
        ]));

        $response->assertOk();
        $response->assertJsonPath('selectedSeason', 2);

        $contentHtml = $response->json('contentHtml');
        $this->assertIsString($contentHtml);
        $this->assertStringContainsString('data-series-season-content', $contentHtml);
        $this->assertStringContainsString('Lazy.Test.S02E01.720p-GROUP', $contentHtml);
        $this->assertStringNotContainsString('Lazy.Test.S01E01.720p-GROUP', $contentHtml);
        $this->assertStringNotContainsString('<!DOCTYPE html>', $contentHtml);
        $this->assertStringNotContainsString('_fragment=season', (string) $response->json('url'));
    }

    public function test_many_releases_only_render_selected_season_page(): void
    {
        $user = $this->createUser();
        $videoId = $this->createShow();

        for ($episode = 1; $episode <= 30; $episode++) {
            $this->createMatchedRelease($videoId, 1, $episode, sprintf('Paged.Show.S01E%02d.720p-GROUP', $episode));
            $this->createMatchedRelease($videoId, 2, $episode, sprintf('Paged.Show.S02E%02d.720p-GROUP', $episode));
        }

        $response = $this->actingAs($user)->get(route('series', ['id' => $videoId, 'season' => 1]));

        $response->assertOk();
        $response->assertSee('Paged.Show.S01E01.720p-GROUP');
        $response->assertDontSee('Paged.Show.S02E01.720p-GROUP');
        $this->assertSame(20, substr_count($response->getContent(), 'series-episode-card'));
    }

    public function test_series_list_filters_premiere_year_by_decade_and_letter(): void
    {
        $user = $this->createUser();
        $mashId = $this->createShow('MASH', '1972-09-17');
        $muppetShowId = $this->createShow('Muppet Show', '1976-09-05');
        $matlockId = $this->createShow('Matlock', '1986-09-23');
        $this->createMatchedRelease($mashId, 1, 1, 'MASH.S01E01.720p-GROUP');
        $this->createMatchedRelease($muppetShowId, 1, 1, 'Muppet.Show.S01E01.720p-GROUP');
        $this->createMatchedRelease($matlockId, 1, 1, 'Matlock.S01E01.720p-GROUP');

        $response = $this->actingAs($user)->get(route('series', [
            'id' => 'M',
            'year' => '1970s',
        ]));

        $response->assertOk();
        $response->assertSee('MASH');
        $response->assertSee('Muppet Show');
        $response->assertDontSee('Matlock');
        $response->assertSee('data-year-picker', false);
        $response->assertSee('value="1970s" selected', false);
    }

    public function test_series_list_supports_single_custom_open_and_reversed_year_ranges(): void
    {
        $user = $this->createUser();
        foreach ([1989, 1990, 1992, 1993] as $year) {
            $videoId = $this->createShow('Range Show '.$year, $year.'-06-15');
            $this->createMatchedRelease($videoId, 1, 1, 'Range.Show.'.$year.'.S01E01-GROUP');
        }

        $custom = $this->actingAs($user)->get(route('series', [
            'year' => 'custom',
            'year_from' => 1990,
            'year_to' => 1992,
        ]));
        $custom->assertSee('Range Show 1990');
        $custom->assertSee('Range Show 1992');
        $custom->assertDontSee('Range Show 1989');
        $custom->assertDontSee('Range Show 1993');

        $single = $this->actingAs($user)->get(route('series', ['year' => '1992']));
        $single->assertSee('Range Show 1992');
        $single->assertDontSee('Range Show 1990');

        $reversed = $this->actingAs($user)->get(route('series', [
            'year' => 'custom',
            'year_from' => 1992,
            'year_to' => 1990,
        ]));
        $reversed->assertSee('Range Show 1990');
        $reversed->assertSee('Range Show 1992');
        $reversed->assertDontSee('Range Show 1989');
        $reversed->assertDontSee('Range Show 1993');

        $openEnded = $this->actingAs($user)->get(route('series', [
            'year' => 'custom',
            'year_from' => 1992,
            'year_to' => '',
        ]));
        $openEnded->assertSee('Range Show 1992');
        $openEnded->assertSee('Range Show 1993');
        $openEnded->assertDontSee('Range Show 1990');

        $blank = $this->actingAs($user)->get(route('series', [
            'title' => 'Range Show',
            'year' => 'custom',
            'year_from' => '',
            'year_to' => '',
        ]));
        $blank->assertSee('Range Show 1989');
        $blank->assertSee('Range Show 1993');
    }

    public function test_show_page_filters_episode_releases_by_air_year_within_the_selected_season(): void
    {
        $user = $this->createUser();
        $videoId = $this->createShow();
        $this->createMatchedRelease($videoId, 1, 1, 'Air.Year.2005.S01E01-GROUP', '2005-02-03');
        $this->createMatchedRelease($videoId, 1, 2, 'Air.Year.2006.S01E02-GROUP', '2006-02-03');
        $this->createMatchedRelease($videoId, 2, 1, 'Other.Season.2005.S02E01-GROUP', '2005-03-04');

        $response = $this->actingAs($user)->get(route('series', [
            'id' => $videoId,
            'season' => 1,
            'year' => '2005',
        ]));

        $response->assertOk();
        $response->assertSee('Air.Year.2005.S01E01-GROUP');
        $response->assertDontSee('Air.Year.2006.S01E02-GROUP');
        $response->assertDontSee('Other.Season.2005.S02E01-GROUP');
        $response->assertSee('data-year-picker', false);
        $response->assertSee('value="2005" selected', false);
        $this->assertStringContainsString('year=2005', $response->getContent());
        $this->assertStringContainsString('page=1', $response->getContent());
    }

    public function test_show_page_keeps_a_selected_season_that_has_no_releases_in_the_selected_year(): void
    {
        $user = $this->createUser();
        $videoId = $this->createShow();
        $this->createMatchedRelease($videoId, 1, 1, 'Selected.Season.2006.S01E01-GROUP', '2006-02-03');
        $this->createMatchedRelease($videoId, 2, 1, 'Other.Season.2005.S02E01-GROUP', '2005-03-04');

        $response = $this->actingAs($user)->get(route('series', [
            'id' => $videoId,
            'season' => 1,
            'year' => '2005',
        ]));

        $response->assertOk();
        $response->assertSee('No releases found on this page for the selected season.');
        $response->assertDontSee('Selected.Season.2006.S01E01-GROUP');
        $response->assertDontSee('Other.Season.2005.S02E01-GROUP');
        $response->assertViewHas('selectedSeason', 1);
    }

    public function test_show_page_keeps_the_year_picker_available_when_no_air_year_matches(): void
    {
        $user = $this->createUser();
        $videoId = $this->createShow();
        $this->createMatchedRelease($videoId, 1, 1, 'No.Match.S01E01-GROUP', '2005-02-03');

        $response = $this->actingAs($user)->get(route('series', [
            'id' => $videoId,
            'year' => '1999',
        ]));

        $response->assertOk();
        $response->assertSee('data-year-picker', false);
        $response->assertSee('No episodes/releases found for this series.');
        $response->assertDontSee('No.Match.S01E01-GROUP');
    }

    public function test_series_list_prefers_banner_then_poster_then_neutral_placeholder(): void
    {
        $user = $this->createUser();
        $bannerId = $this->createShow('Banner Show', '2020-01-01', image: true, banner: true);
        $posterId = $this->createShow('Poster Show', '2021-01-01', image: true);
        $placeholderId = $this->createShow('Placeholder Show', '2022-01-01');
        $this->createMatchedRelease($bannerId, 1, 1, 'Banner.Show.S01E01-GROUP');
        $this->createMatchedRelease($posterId, 1, 1, 'Poster.Show.S01E01-GROUP');
        $this->createMatchedRelease($placeholderId, 1, 1, 'Placeholder.Show.S01E01-GROUP');

        $coversRoot = $this->makeTempDirectory('series-list-artwork');
        config(['nntmux_settings.covers_path' => $coversRoot]);
        File::ensureDirectoryExists($coversRoot.'/tvshows');
        File::put($coversRoot.'/tvshows/'.$bannerId.'-banner.webp', 'banner');
        File::put($coversRoot.'/tvshows/'.$bannerId.'.webp', 'poster');
        File::put($coversRoot.'/tvshows/'.$posterId.'.jpg', 'poster');

        $response = $this->actingAs($user)->get(route('series', ['title' => 'Show']));

        $response->assertOk();
        $response->assertSee('/covers/tvshows/'.$bannerId.'-banner.webp', false);
        $response->assertDontSee('/covers/tvshows/'.$bannerId.'.webp', false);
        $response->assertSee('/covers/tvshows/'.$posterId.'.jpg', false);
        $response->assertSee('/assets/images/no-cover.png', false);
        $artworkByTitle = collect($response->viewData('serieslist'))
            ->flatten(1)
            ->keyBy('title');

        $this->assertSame('banner', $artworkByTitle['Banner Show']['artwork_kind']);
        $this->assertSame('poster', $artworkByTitle['Poster Show']['artwork_kind']);
        $this->assertSame('placeholder', $artworkByTitle['Placeholder Show']['artwork_kind']);
        $response->assertSee('h-12 w-auto aspect-[1000/185] rounded-md object-contain', false);
        $response->assertSee('h-16 w-auto rounded-md object-contain', false);
        $response->assertSee('flex w-full aspect-[1000/185] items-center justify-center', false);
        $response->assertSee('w-full aspect-[1000/185] rounded-md object-contain', false);
        $response->assertSee('h-full w-auto rounded-md object-contain', false);
        $response->assertDontSee('min-h-24', false);
        $response->assertDontSee('object-cover', false);
    }

    public function test_show_page_renders_available_artwork_when_the_summary_is_empty(): void
    {
        $user = $this->createUser();
        $videoId = $this->createShow('Summaryless Show', '2023-01-01', banner: true, summary: '');
        $this->createMatchedRelease($videoId, 1, 1, 'Summaryless.Show.S01E01-GROUP');

        $coversRoot = $this->makeTempDirectory('series-detail-artwork');
        config(['nntmux_settings.covers_path' => $coversRoot]);
        File::ensureDirectoryExists($coversRoot.'/tvshows');
        File::put($coversRoot.'/tvshows/'.$videoId.'-banner.webp', 'banner');

        $response = $this->actingAs($user)->get(route('series', ['id' => $videoId]));

        $response->assertOk();
        $response->assertSee('/covers/tvshows/'.$videoId.'-banner.webp', false);
    }

    public function test_marking_a_banner_available_refreshes_cached_series_list_artwork_flags(): void
    {
        $user = $this->createUser();
        $videoId = $this->createShow('Cached Artwork Show', '2023-01-01', image: true);
        $this->createMatchedRelease($videoId, 1, 1, 'Cached.Artwork.Show.S01E01-GROUP');

        $coversRoot = $this->makeTempDirectory('series-cache-artwork');
        config(['nntmux_settings.covers_path' => $coversRoot]);
        File::ensureDirectoryExists($coversRoot.'/tvshows');
        File::put($coversRoot.'/tvshows/'.$videoId.'.webp', 'poster');
        File::put($coversRoot.'/tvshows/'.$videoId.'-banner.webp', 'banner');

        $before = $this->actingAs($user)->get(route('series', ['title' => 'Cached Artwork Show']));
        $before->assertSee('/covers/tvshows/'.$videoId.'.webp', false);
        $before->assertDontSee('/covers/tvshows/'.$videoId.'-banner.webp', false);

        TvInfo::markBannerAvailable($videoId);

        $after = $this->actingAs($user)->get(route('series', ['title' => 'Cached Artwork Show']));
        $after->assertSee('/covers/tvshows/'.$videoId.'-banner.webp', false);
        $after->assertDontSee('/covers/tvshows/'.$videoId.'.webp', false);
    }

    private function createSchema(): void
    {
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->text('value')->nullable();
            });
        }

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
            $table->unsignedInteger('root_categories_id')->nullable();
            $table->integer('status')->default(1);
            $table->text('description')->nullable();
        });

        Schema::create('videos', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('type')->default(0);
            $table->string('title')->default('');
            $table->string('countries_id', 2)->nullable();
            $table->string('started')->nullable();
            $table->integer('anidb')->default(0);
            $table->string('imdb')->nullable();
            $table->integer('tmdb')->default(0);
            $table->integer('trakt')->default(0);
            $table->integer('tvdb')->default(0);
            $table->integer('tvmaze')->default(0);
            $table->integer('tvrage')->default(0);
            $table->integer('source')->default(0);
        });

        Schema::create('tv_info', function (Blueprint $table): void {
            $table->unsignedInteger('videos_id')->primary();
            $table->text('summary')->nullable();
            $table->string('publisher')->nullable();
            $table->boolean('image')->default(false);
            $table->boolean('banner')->default(false);
        });

        Schema::create('tv_episodes', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('videos_id');
            $table->integer('series')->default(0);
            $table->integer('episode')->default(0);
            $table->string('se_complete')->default('');
            $table->string('title')->default('');
            $table->string('firstaired')->nullable();
            $table->text('summary')->nullable();
        });

        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('searchname')->default('');
            $table->string('fromname')->nullable();
            $table->dateTime('postdate')->nullable();
            $table->dateTime('adddate')->nullable();
            $table->string('guid')->nullable();
            $table->unsignedInteger('categories_id')->default(Category::TV_SD);
            $table->unsignedInteger('groups_id')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->integer('totalpart')->default(0);
            $table->integer('passwordstatus')->default(0);
            $table->integer('grabs')->default(0);
            $table->integer('comments')->default(0);
            $table->unsignedInteger('videos_id')->nullable();
            $table->integer('tv_episodes_id')->nullable();
        });

        Schema::create('dnzb_failures', function (Blueprint $table): void {
            $table->unsignedInteger('release_id');
            $table->unsignedInteger('users_id');
            $table->integer('failed')->default(0);
            $table->primary(['release_id', 'users_id']);
        });

        Schema::create('user_series', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('users_id');
            $table->unsignedInteger('videos_id');
        });
    }

    private function seedBaseData(): void
    {
        DB::table('root_categories')->insert([
            'id' => Category::TV_ROOT,
            'title' => 'TV',
            'status' => 1,
        ]);

        DB::table('categories')->insert([
            'id' => Category::TV_SD,
            'title' => 'SD',
            'root_categories_id' => Category::TV_ROOT,
            'status' => 1,
            'description' => 'TV SD',
        ]);

        DB::table('roles')->insert([
            'id' => 1,
            'name' => 'User',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('permissions')->insert([
            'id' => 1,
            'name' => 'view tv',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('role_has_permissions')->insert([
            'permission_id' => 1,
            'role_id' => 1,
        ]);
    }

    private function createUser(): User
    {
        $userId = DB::table('users')->insertGetId([
            'username' => 'series-user',
            'email' => 'series@example.test',
            'password' => bcrypt('secret'),
            'roles_id' => 1,
            'api_token' => 'series-token',
            'verified' => true,
            'can_post' => true,
            'theme_preference' => 'light',
            'email_verified_at' => now(),
            'lastlogin' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userModelClass = User::class;
        DB::table('model_has_roles')->insert([
            'role_id' => 1,
            'model_type' => $userModelClass,
            'model_id' => $userId,
        ]);
        DB::table('model_has_permissions')->insert([
            'permission_id' => 1,
            'model_type' => $userModelClass,
            'model_id' => $userId,
        ]);

        return User::query()->findOrFail($userId);
    }

    private function createShow(
        string $title = 'Paged Test Show',
        string $started = '2024-01-01',
        bool $image = false,
        bool $banner = false,
        string $summary = 'A test show.',
    ): int {
        $videoId = DB::table('videos')->insertGetId([
            'type' => 0,
            'title' => $title,
            'started' => $started,
            'countries_id' => 'US',
        ]);

        DB::table('tv_info')->insert([
            'videos_id' => $videoId,
            'summary' => $summary,
            'publisher' => 'Test Network',
            'image' => $image,
            'banner' => $banner,
        ]);

        return (int) $videoId;
    }

    private function createMatchedRelease(
        int $videoId,
        int $season,
        int $episode,
        string $searchName,
        ?string $firstAired = null,
    ): void {
        $episodeId = DB::table('tv_episodes')->insertGetId([
            'videos_id' => $videoId,
            'series' => $season,
            'episode' => $episode,
            'se_complete' => sprintf('S%02dE%02d', $season, $episode),
            'title' => 'Episode '.$episode,
            'firstaired' => $firstAired ?? now()->subDays($episode)->toDateString(),
            'summary' => 'Episode summary.',
        ]);

        DB::table('releases')->insert([
            'name' => $searchName,
            'searchname' => $searchName,
            'fromname' => 'poster@example.test',
            'postdate' => now()->subMinutes($episode),
            'adddate' => now()->subMinutes($episode),
            'guid' => sha1($searchName),
            'categories_id' => Category::TV_SD,
            'groups_id' => null,
            'size' => 1024,
            'totalpart' => 1,
            'passwordstatus' => 0,
            'grabs' => 0,
            'comments' => 0,
            'videos_id' => $videoId,
            'tv_episodes_id' => $episodeId,
        ]);
    }

    private function resetGlobalComposerState(): void
    {
        $reflection = new ReflectionClass(GlobalDataComposer::class);
        $property = $reflection->getProperty('resolvedData');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    private function setEnvironmentValue(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
