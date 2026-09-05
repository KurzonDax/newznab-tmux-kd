<?php

declare(strict_types=1);

namespace Tests\Support\Admin;

use App\Http\Middleware\Google2FAMiddleware;
use App\Models\User;
use App\View\Composers\GlobalDataComposer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\IsolatedSqliteDatabase;

/**
 * Shared setup for feature tests that render an admin list page.
 *
 * Pairs with {@see IsolatedSqliteDatabase}, which supplies the pre-bootstrap
 * `settings` table. Every admin page renders the same layout, so every such test needs the
 * same roles, users, permissions, settings, content and category tables before it can add
 * the one table its own page reads. A class using this trait calls
 * {@see self::bootAdminListPage()} from `setUp()` after `bootIsolatedDatabase()`, and
 * {@see self::tearDownAdminListPage()} from `tearDown()`.
 */
trait InteractsWithAdminListPages
{
    /**
     * @param  int  $itemsPerPage  Page size for the run, kept small so a handful of rows paginate.
     */
    protected function bootAdminListPage(int $itemsPerPage = 2): void
    {
        config([
            'mail.from.address' => 'noreply@example.test',
            'mail.from.name' => 'NNTmux Tests',
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'nntmux.items_per_page' => $itemsPerPage,
        ]);

        Cache::flush();

        $this->createAdminLayoutSchema();
        $this->seedAdminLayoutData();
        $this->resetGlobalComposerState();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->withoutMiddleware(Google2FAMiddleware::class);
    }

    protected function tearDownAdminListPage(): void
    {
        Cache::flush();
        Paginator::currentPageResolver(static fn (): int => 1);
    }

    protected function admin(): User
    {
        return $this->createUserWithRole('Admin');
    }

    /**
     * Drive the paginator's page resolution outside an HTTP request.
     *
     * The resolver is global state; {@see self::tearDownAdminListPage()} returns it to page one.
     */
    protected function withCurrentPage(int $page): void
    {
        $this->app['request']->merge(['page' => $page]);
        Paginator::currentPageResolver(static fn (): int => $page);
    }

    protected function createUserWithRole(string $roleName): User
    {
        $role = Role::query()->firstOrCreate(
            [
                'name' => $roleName,
                'guard_name' => 'web',
            ],
            [
                'rate_limit' => 60,
                'isdefault' => $roleName === 'User',
                'defaultinvites' => 1,
            ]
        );

        /** @var User $user */
        $user = User::withoutEvents(fn () => User::query()->create([
            'username' => strtolower($roleName).'_'.Str::random(8),
            'email' => Str::random(12).'@example.test',
            'password' => bcrypt('password'),
            'roles_id' => $role->id,
            'rate_limit' => 60,
            'api_token' => Str::random(32),
            'verified' => true,
            'email_verified_at' => now(),
            'lastlogin' => now(),
        ]));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->assignRole($role);

        return $user->fresh();
    }

    /**
     * The rendered contents of one cell in the row carrying a given piece of text.
     *
     * Admin list cells are asserted by column position rather than with `assertSee()`, because
     * a wrongly rendered cell can still contain the text a bare `assertSee()` looks for -- an
     * Eloquent model echoes as JSON, which carries its own field values (#449).
     */
    protected function cellFor(string $html, string $rowText, int $columnIndex): string
    {
        $rowPattern = '/<tr[^>]*>(?:(?!<\/tr>).)*'.preg_quote($rowText, '/').'.*?<\/tr>/s';

        $this->assertMatchesRegularExpression($rowPattern, $html, 'No row was rendered for '.$rowText.'.');

        preg_match($rowPattern, $html, $row);
        preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $row[0], $cells);

        return trim(strip_tags($cells[1][$columnIndex] ?? ''));
    }

    protected function createGenresTable(): void
    {
        Schema::create('genres', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->integer('type')->nullable();
            $table->boolean('disabled')->default(false);
        });
    }

    protected function createConsoleInfoTable(): void
    {
        Schema::create('consoleinfo', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->string('asin', 128)->nullable();
            $table->string('url', 1000)->nullable();
            $table->unsignedInteger('salesrank')->nullable();
            $table->string('platform')->nullable();
            $table->string('publisher')->nullable();
            $table->integer('genres_id')->nullable();
            $table->string('esrb')->nullable();
            $table->dateTime('releasedate')->nullable();
            $table->string('review', 3000)->nullable();
            $table->boolean('cover')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Mirrors the production column set, including `trailer` being NOT NULL.
     */
    protected function createGamesInfoTable(): void
    {
        Schema::create('gamesinfo', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->string('asin', 128)->nullable();
            $table->string('url', 1000)->nullable();
            $table->string('publisher')->nullable();
            $table->integer('genres_id')->nullable();
            $table->string('esrb')->nullable();
            $table->dateTime('releasedate')->nullable();
            $table->string('review', 3000)->nullable();
            $table->boolean('cover')->default(false);
            $table->boolean('backdrop')->default(false);
            $table->string('trailer', 1000)->default('');
            $table->string('classused', 10)->default('steam');
            $table->timestamps();
        });
    }

    protected function resetGlobalComposerState(): void
    {
        $reflection = new ReflectionClass(GlobalDataComposer::class);
        $property = $reflection->getProperty('resolvedData');
        $property->setValue(null, null);
    }

    /**
     * The tables the shared admin layout and its view composer read on every page.
     */
    protected function createAdminLayoutSchema(): void
    {
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->text('value')->nullable();
            });
        }

        Schema::create('content', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->default('');
            $table->string('url', 2000)->nullable();
            $table->text('body')->nullable();
            $table->string('metadescription', 1000)->default('');
            $table->string('metakeywords', 1000)->default('');
            $table->integer('contenttype')->default(2);
            $table->integer('status')->default(1);
            $table->integer('ordinal')->nullable();
            $table->integer('role')->default(0);
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
            $table->integer('rate_limit')->default(60);
            $table->boolean('isdefault')->default(false);
            $table->unsignedInteger('defaultinvites')->default(0);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
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
            $table->string('timezone', 50)->default('UTC');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('lastlogin')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
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

        Schema::create('user_excluded_categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('users_id');
            $table->unsignedInteger('categories_id');
        });

        Schema::create('root_categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->default('');
            $table->integer('status')->default(1);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->default('');
            $table->unsignedInteger('root_categories_id')->nullable();
            $table->text('description')->nullable();
            $table->integer('status')->default(1);
            $table->timestamps();
        });

        Schema::create('user_activities', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('username');
            $table->string('activity_type', 50);
            $table->text('description');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    protected function seedAdminLayoutData(): void
    {
        DB::table('settings')->upsert([
            ['name' => 'title', 'value' => 'NNTmux Test'],
            ['name' => 'home_link', 'value' => '/'],
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
        ], ['name'], ['value']);

        DB::table('root_categories')->insert([
            'id' => 1,
            'title' => 'General',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('categories')->insert([
            'id' => 1,
            'title' => 'General',
            'root_categories_id' => 1,
            'description' => 'General category',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
