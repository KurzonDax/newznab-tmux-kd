<?php

declare(strict_types=1);

namespace Tests\Feature;

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
use ReflectionClass;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class SeriesControllerTest extends TestCase
{
    use IsolatedSqliteDatabase;

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
            'mail.from.address' => 'noreply@example.test',
            'mail.from.name' => 'NNTmux Tests',
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'nntmux.series_view_limit' => 20,
        ]);

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
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_my_shows_browse_leads_to_the_tv_releases_screen(): void
    {
        $this->actingAs($this->createUser());
        $this->get(route('myshows.browse'))->assertRedirect('/browse/tv?watching=1');
        $this->get('/browse/tv?watching=1')->assertRedirect(route('tv.releases'));
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
            $table->string('email');
            $table->string('password');
            $table->unsignedInteger('roles_id')->default(1);
            $table->integer('rate_limit')->default(60);
            $table->string('api_token')->nullable()->unique();
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
            $table->unique(['users_id', 'categories_id']);
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
            $table->unique(['title', 'type', 'started', 'countries_id']);
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
            $table->unique(['videos_id', 'series', 'episode', 'firstaired']);
        });

        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('searchname')->default('');
            $table->float('completion')->default(0);
            $table->string('repair_outcome')->nullable();
            $table->string('rescan_outcome')->nullable();
            $table->string('display_name')->nullable();
            $table->string('fromname')->nullable();
            $table->dateTime('postdate')->nullable();
            $table->dateTime('adddate')->nullable();
            $table->string('guid')->nullable()->unique();
            $table->unsignedInteger('categories_id')->default(Category::TV_SD);
            $table->unsignedInteger('groups_id')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->integer('totalpart')->default(0);
            $table->integer('passwordstatus')->default(0);
            $table->integer('grabs')->default(0);
            $table->integer('comments')->default(0);
            $table->unsignedInteger('videos_id')->nullable();
            $table->integer('tv_episodes_id')->nullable();
            $table->boolean('haspreview')->default(false);
            $table->boolean('jpgstatus')->default(false);
            $table->integer('nfostatus')->default(-1);
            $table->integer('isrenamed')->default(0);
            $table->string('additional_pp_claim_token')->nullable();
            $table->string('imdbid')->nullable();
            foreach (['musicinfo_id', 'consoleinfo_id', 'gamesinfo_id', 'bookinfo_id', 'anidbid'] as $column) {
                $table->integer($column)->nullable();
            }
        });

        Schema::table('releases', function (Blueprint $table): void {
            $table->integer('videostatus')->default(0);
        });
        Schema::create('release_audio_tags', function (Blueprint $table): void {
            $table->integer('releases_id')->primary();
            foreach (['album', 'album_performer', 'performer', 'genre', 'recorded_date', 'track_name', 'track_position', 'track_position_total', 'musicbrainz_album_id', 'musicbrainz_track_id', 'audio_format', 'preview_extension', 'preview_mime', 'preview_seconds'] as $column) {
                $table->string($column)->nullable();
            }
            $table->boolean('has_preview')->default(false);
            $table->boolean('has_spectrogram')->default(false);
        });
        Schema::create('release_video_clips', function (Blueprint $table): void {
            $table->integer('releases_id')->primary();
            $table->string('extension');
            $table->string('mime');
        });
        Schema::create('user_movies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('users_id');
            $table->string('imdbid');
        });
        Schema::create('users_releases', function (Blueprint $table): void {
            $table->integer('users_id');
            $table->integer('releases_id');
            $table->unique(['users_id', 'releases_id']);
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

    private function resetGlobalComposerState(): void
    {
        $reflection = new ReflectionClass(GlobalDataComposer::class);
        $property = $reflection->getProperty('resolvedData');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }
}
