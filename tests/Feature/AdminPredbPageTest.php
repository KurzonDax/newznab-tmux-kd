<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Http\Middleware\Google2FAMiddleware;
use App\Models\Predb;
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
use Tests\TestCase;

/**
 * Coverage for the admin PreDB browser (#447).
 *
 * The page had none, which is how a call to a static helper removed in 2018 survived in the
 * size column: it is only reached when the table holds a row with a non-empty `size`, so an
 * empty development database renders the empty state and looks healthy.
 *
 * The search path is deliberately exercised only through a faked `Search` facade -- it
 * delegates to Manticore, which is not reachable from the test suite.
 */
class AdminPredbPageTest extends TestCase
{
    use IsolatedSqliteDatabase;

    /**
     * Position of the size column in a PreDB row: title, category, size.
     */
    private const SIZE_COLUMN_INDEX = 2;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return [
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
            'nntmux.items_per_page' => 2,
        ]);

        Cache::flush();

        $this->createSchema();
        $this->seedSettings();
        $this->seedCategories();
        $this->resetGlobalComposerState();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->withoutMiddleware(Google2FAMiddleware::class);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Paginator::currentPageResolver(static fn (): int => 1);
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_predb_list_renders_a_row_carrying_a_size(): void
    {
        $this->createPredbEntries([
            ['title' => 'Sized.Release-GROUP', 'size' => '1073741824'],
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.predb'));

        $response->assertOk();
        $response->assertSee('Sized.Release-GROUP');
        $response->assertSee('1GB');
        $response->assertDontSee('1073741824');
    }

    public function test_predb_list_formats_each_size_with_the_shared_byte_helper(): void
    {
        $this->createPredbEntries([
            ['title' => 'Gigabyte.Release-GROUP', 'size' => '1073741824'],
            ['title' => 'Megabyte.Release-GROUP', 'size' => '5242880'],
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.predb'));

        $response->assertOk();
        $response->assertSee('1GB');
        $response->assertSee('5MB');
    }

    public function test_predb_list_shows_a_placeholder_when_a_row_has_no_size(): void
    {
        $this->createPredbEntries([
            ['title' => 'Sizeless.Release-GROUP', 'size' => null],
            ['title' => 'Zero.Release-GROUP', 'size' => '0'],
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.predb'));

        $response->assertOk();
        $response->assertDontSee('0B');
        $this->assertSame('—', $this->sizeCellFor((string) $response->getContent(), 'Sizeless.Release-GROUP'));
        $this->assertSame('—', $this->sizeCellFor((string) $response->getContent(), 'Zero.Release-GROUP'));
    }

    public function test_predb_list_links_a_matched_release(): void
    {
        $this->createPredbEntries([
            ['title' => 'Matched.Release-GROUP', 'size' => '1073741824'],
        ]);

        $predbId = (int) DB::table('predb')->value('id');
        DB::table('releases')->insert([
            'guid' => 'matched-release-guid',
            'name' => 'Matched.Release-GROUP',
            'predb_id' => $predbId,
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.predb'));

        $response->assertOk();
        $response->assertSee(url('details/matched-release-guid'));
    }

    public function test_predb_list_serves_the_requested_page_after_an_earlier_page_was_cached(): void
    {
        $this->createPredbEntries([
            ['title' => 'Predb.Newest-GROUP', 'size' => '1073741824'],
            ['title' => 'Predb.Second-GROUP', 'size' => '1073741824'],
            ['title' => 'Predb.Third-GROUP', 'size' => '1073741824'],
            ['title' => 'Predb.Oldest-GROUP', 'size' => '1073741824'],
        ]);

        $admin = $this->admin();

        $firstPage = $this->actingAs($admin)->get(route('admin.predb'));
        $firstPage->assertOk();
        $firstPage->assertSee('Predb.Newest-GROUP');
        $firstPage->assertSee('Predb.Second-GROUP');

        $secondPage = $this->actingAs($admin)->get(route('admin.predb', ['page' => 2]));

        $secondPage->assertOk();
        $secondPage->assertSee('Predb.Third-GROUP');
        $secondPage->assertSee('Predb.Oldest-GROUP');
        $secondPage->assertDontSee('Predb.Newest-GROUP');
        $secondPage->assertDontSee('Predb.Second-GROUP');
    }

    public function test_predb_pagination_footer_reports_the_requested_page_range(): void
    {
        $this->createPredbEntries([
            ['title' => 'Predb.Newest-GROUP', 'size' => '1073741824'],
            ['title' => 'Predb.Second-GROUP', 'size' => '1073741824'],
            ['title' => 'Predb.Third-GROUP', 'size' => '1073741824'],
        ]);

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.predb'))->assertOk();

        $secondPage = $this->actingAs($admin)->get(route('admin.predb', ['page' => 2]));

        $secondPage->assertOk();
        $secondPage->assertSee('Showing 3 to 3 of 3 entries');
    }

    public function test_get_all_returns_the_requested_page_rather_than_the_cached_first_page(): void
    {
        $this->createPredbEntries([
            ['title' => 'Predb.Newest-GROUP', 'size' => '1073741824'],
            ['title' => 'Predb.Second-GROUP', 'size' => '1073741824'],
            ['title' => 'Predb.Third-GROUP', 'size' => '1073741824'],
        ]);

        $this->withCurrentPage(1);
        $firstPage = Predb::getAll();

        $this->withCurrentPage(2);
        $secondPage = Predb::getAll();

        $this->assertSame(1, $firstPage->currentPage());
        $this->assertSame(2, $secondPage->currentPage());
        $this->assertSame('Predb.Newest-GROUP', $firstPage->items()[0]->title);
        $this->assertSame('Predb.Third-GROUP', $secondPage->items()[0]->title);
    }

    public function test_get_all_still_serves_a_repeated_request_from_the_cache(): void
    {
        $this->createPredbEntries([
            ['title' => 'Predb.Cached-GROUP', 'size' => '1073741824'],
        ]);

        $this->withCurrentPage(1);
        $first = Predb::getAll();

        $this->createPredbEntries([
            ['title' => 'Predb.AddedLater-GROUP', 'size' => '1073741824'],
        ]);

        $second = Predb::getAll();

        $this->assertSame(1, $first->total());
        $this->assertSame(1, $second->total(), 'A repeated request must come back from the cache.');
        $this->assertSame('Predb.Cached-GROUP', $second->items()[0]->title);
    }

    public function test_get_all_caches_each_search_term_independently(): void
    {
        $this->createPredbEntries([
            ['title' => 'Predb.Alpha-GROUP', 'size' => '1073741824'],
            ['title' => 'Predb.Beta-GROUP', 'size' => '1073741824'],
        ]);

        $ids = DB::table('predb')->orderBy('id')->pluck('id', 'title')->all();

        Search::shouldReceive('searchPredb')
            ->once()
            ->with('alpha')
            ->andReturn([$ids['Predb.Alpha-GROUP']]);
        Search::shouldReceive('searchPredb')
            ->once()
            ->with('beta')
            ->andReturn([$ids['Predb.Beta-GROUP']]);

        $this->withCurrentPage(1);

        $alpha = Predb::getAll('alpha');
        $beta = Predb::getAll('beta');

        $this->assertSame('Predb.Alpha-GROUP', $alpha->items()[0]->title);
        $this->assertSame('Predb.Beta-GROUP', $beta->items()[0]->title);
    }

    public function test_get_all_does_not_occupy_an_unnamespaced_cache_key(): void
    {
        $this->createPredbEntries([
            ['title' => 'Predb.Namespaced-GROUP', 'size' => '1073741824'],
        ]);

        $this->withCurrentPage(1);
        Predb::getAll();

        $this->assertFalse(
            Cache::has(md5('')),
            'The PreDB listing must not cache under a bare md5() of the search term.'
        );
    }

    /**
     * The rendered contents of one row's size cell.
     *
     * Asserted through the cell rather than the whole page: the row renders an unconditional
     * em-dash in its category, files and release columns too, so a bare assertSee('—') would
     * pass whatever the size column did.
     */
    private function sizeCellFor(string $html, string $title): string
    {
        $rowPattern = '/<tr[^>]*>(?:(?!<\/tr>).)*'.preg_quote($title, '/').'.*?<\/tr>/s';

        $this->assertMatchesRegularExpression($rowPattern, $html, 'No row was rendered for '.$title.'.');

        preg_match($rowPattern, $html, $row);
        preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $row[0], $cells);

        return trim(strip_tags($cells[1][self::SIZE_COLUMN_INDEX] ?? ''));
    }

    private function withCurrentPage(int $page): void
    {
        $this->app['request']->merge(['page' => $page]);
        Paginator::currentPageResolver(static fn (): int => $page);
    }

    /**
     * @param  array<int, array{title: string, size: string|null}>  $entries
     */
    private function createPredbEntries(array $entries): void
    {
        $rows = [];
        $offset = DB::table('predb')->count();

        foreach (array_values($entries) as $index => $entry) {
            $rows[] = [
                'title' => $entry['title'],
                'nfo' => null,
                'size' => $entry['size'],
                'category' => 'TV',
                'predate' => now()->subMinutes($offset + $index)->format('Y-m-d H:i:s'),
                'source' => 'test',
                'requestid' => 0,
                'groups_id' => 0,
                'nuked' => Predb::PRE_NONUKE,
                'nukereason' => null,
                'files' => '10',
                'filename' => '',
                'searched' => 0,
            ];
        }

        DB::table('predb')->insert($rows);
    }

    private function admin(): User
    {
        return $this->createUserWithRole('Admin');
    }

    private function createSchema(): void
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

        Schema::create('predb', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->default('');
            $table->string('nfo')->nullable();
            $table->string('size', 50)->nullable();
            $table->string('category')->nullable();
            $table->dateTime('predate')->nullable();
            $table->string('source', 50)->default('');
            $table->unsignedInteger('requestid')->default(0);
            $table->unsignedInteger('groups_id')->default(0);
            $table->boolean('nuked')->default(false);
            $table->string('nukereason')->nullable();
            $table->string('files', 50)->nullable();
            $table->string('filename')->default('');
            $table->boolean('searched')->default(false);
        });

        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid')->nullable();
            $table->string('name')->default('');
            $table->unsignedInteger('predb_id')->default(0);
        });
    }

    private function seedSettings(): void
    {
        DB::table('settings')->upsert([
            ['name' => 'title', 'value' => 'NNTmux Test'],
            ['name' => 'home_link', 'value' => '/'],
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
        ], ['name'], ['value']);
    }

    private function seedCategories(): void
    {
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

    private function createUserWithRole(string $roleName): User
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

    private function resetGlobalComposerState(): void
    {
        $reflection = new ReflectionClass(GlobalDataComposer::class);
        $property = $reflection->getProperty('resolvedData');
        $property->setValue(null, null);
    }
}
