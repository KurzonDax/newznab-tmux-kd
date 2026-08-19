<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\Google2FAMiddleware;
use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\Category;
use App\Models\Release;
use App\Models\RootCategory;
use App\Models\Settings;
use App\Models\User;
use App\View\Composers\GlobalDataComposer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use ReflectionClass;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PosterControllerTest extends TestCase
{
    private string $databasePath;

    /**
     * @var array<string, string|false>
     */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $this->databasePath = $this->makeTempPath('nntmux-poster-controller-test', '.sqlite');
        $this->originalEnvironment = [
            'APP_ENV' => getenv('APP_ENV'),
            'DB_CONNECTION' => getenv('DB_CONNECTION'),
            'DB_DATABASE' => getenv('DB_DATABASE'),
        ];

        $pdo = new PDO('sqlite:'.$this->databasePath);
        $pdo->exec('CREATE TABLE settings (name VARCHAR PRIMARY KEY, value TEXT NULL)');
        $pdo->exec("INSERT INTO settings (name, value) VALUES ('categorizeforeign', '0'), ('catwebdl', '0')");

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
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'nntmux.items_per_page' => 2,
            'nntmux.max_pager_results' => 500000,
            'nntmux.cache_expiry_short' => 1,
            'nntmux.cache_expiry_medium' => 1,
        ]);

        DB::purge();
        DB::reconnect();
        Cache::flush();
        $this->createSchema();
        $this->resetGlobalComposerState();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->withoutMiddleware([
            Google2FAMiddleware::class,
            TrustedDevice2FAMiddleware::class,
        ]);

        Settings::query()->updateOrCreate(['name' => 'showpasswordedrelease'], ['value' => '0']);
        Settings::query()->updateOrCreate(['name' => 'title'], ['value' => 'NNTmux Test']);
        Settings::query()->updateOrCreate(['name' => 'home_link'], ['value' => '/']);

        $root = RootCategory::query()->create(['id' => 2000, 'title' => 'Movies']);
        Category::query()->create(['id' => 2030, 'title' => 'SD', 'root_categories_id' => $root->id]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironmentValue($key, $value === false ? null : $value);
        }
    }

    public function test_poster_page_matches_exact_identity_and_paginates_newest_first(): void
    {
        $user = $this->verifiedUser();
        $identity = 'user <user@x.localdomain>';

        $this->release('Newest exact', $identity, '2026-08-03 12:00:00');
        $this->release('Middle exact', $identity, '2026-08-02 12:00:00');
        $this->release('Oldest exact', $identity, '2026-08-01 12:00:00');
        $this->release('Look alike', 'user <user@2.localdomain>', '2026-08-04 12:00:00');
        $this->release('Case variant', 'user <USER@x.localdomain>', '2026-08-05 12:00:00');

        $firstPage = $this->actingAs($user)->get(route('poster', ['name' => $identity]));

        $firstPage->assertOk();
        $firstPage->assertSeeInOrder(['Newest exact', 'Middle exact']);
        $firstPage->assertDontSee('Oldest exact');
        $firstPage->assertDontSee('Look alike');
        $firstPage->assertDontSee('Case variant');
        $firstPage->assertSee('name=user%20%3Cuser%40x.localdomain%3E', false);
        $firstPage->assertSee('All releases from this poster');
        $firstPage->assertSee('bg-primary-100', false);
        $firstPage->assertDontSee('bg-indigo-100', false);

        $secondPage = $this->actingAs($user)->get(route('poster', ['name' => $identity, 'page' => 2]));
        $secondPage->assertOk();
        $secondPage->assertSee('Oldest exact');
        $secondPage->assertDontSee('Look alike');
        $secondPage->assertDontSee('Case variant');
    }

    public function test_poster_page_respects_category_exclusions_and_renders_empty_state(): void
    {
        $user = $this->verifiedUser();
        DB::table('user_excluded_categories')->insert([
            'users_id' => $user->id,
            'categories_id' => 2030,
        ]);
        $this->release('Excluded release', 'excluded@example.test', '2026-08-03 12:00:00');

        $this->actingAs($user)
            ->get(route('poster', ['name' => 'excluded@example.test']))
            ->assertOk()
            ->assertDontSee('Excluded release')
            ->assertSee('No releases found');

        $this->actingAs($user)
            ->get(route('poster'))
            ->assertOk()
            ->assertSee('No releases found');
    }

    public function test_poster_page_requires_authentication_and_verification(): void
    {
        $this->get('/poster?name=poster%40example.test')->assertRedirect(route('login'));

        $unverified = $this->user(false);
        $this->actingAs($unverified)
            ->get('/poster?name=poster%40example.test')
            ->assertRedirect(route('verification.notice'));
    }

    public function test_poster_identity_query_uses_the_composite_index(): void
    {
        $plan = DB::select(
            'EXPLAIN QUERY PLAN SELECT id FROM releases WHERE fromname = ? ORDER BY postdate DESC',
            ['poster@example.test'],
        );

        $this->assertStringContainsString(
            'ix_releases_fromname_postdate',
            implode(' ', array_map(static fn (object $row): string => (string) $row->detail, $plan)),
        );
    }

    private function verifiedUser(): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'User', 'guard_name' => 'web']);
        $permission = Permission::query()->firstOrCreate(['name' => 'view movies', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        $user = $this->user();
        $user->assignRole($role);
        $user->givePermissionTo($permission);

        return $user;
    }

    private function release(string $name, string $identity, string $postdate): Release
    {
        $id = DB::table('releases')->insertGetId([
            'name' => $name,
            'searchname' => $name,
            'fromname' => $identity,
            'postdate' => $postdate,
            'adddate' => $postdate,
            'guid' => sha1($name.$identity.$postdate),
            'categories_id' => 2030,
            'groups_id' => null,
            'size' => 1024,
            'totalpart' => 1,
        ]);

        return Release::query()->findOrFail($id);
    }

    private function user(bool $verified = true): User
    {
        $id = DB::table('users')->insertGetId([
            'username' => 'poster-user-'.bin2hex(random_bytes(3)),
            'email' => bin2hex(random_bytes(3)).'@example.test',
            'password' => bcrypt('secret'),
            'roles_id' => 1,
            'api_token' => bin2hex(random_bytes(16)),
            'verified' => $verified,
            'can_post' => true,
            'theme_preference' => 'light',
            'email_verified_at' => $verified ? now() : null,
            'lastlogin' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
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
            $table->string('title');
            $table->integer('status')->default(1);
            $table->timestamps();
        });
        Schema::create('categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->unsignedInteger('root_categories_id')->nullable();
            $table->integer('status')->default(1);
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('searchname');
            $table->string('fromname')->nullable();
            $table->dateTime('postdate')->nullable();
            $table->dateTime('adddate')->nullable();
            $table->string('guid');
            $table->unsignedInteger('categories_id');
            $table->unsignedInteger('groups_id')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->integer('totalpart')->default(0);
            $table->integer('passwordstatus')->default(0);
            $table->integer('grabs')->default(0);
            $table->integer('comments')->default(0);
            $table->unsignedInteger('videos_id')->nullable();
            $table->boolean('haspreview')->default(false);
            $table->boolean('jpgstatus')->default(false);
            $table->boolean('nfostatus')->default(false);
            $table->index(['fromname', 'postdate'], 'ix_releases_fromname_postdate');
        });
    }

    private function resetGlobalComposerState(): void
    {
        $property = (new ReflectionClass(GlobalDataComposer::class))->getProperty('resolvedData');
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
