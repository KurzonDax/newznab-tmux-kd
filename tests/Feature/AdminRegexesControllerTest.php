<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\Google2FAMiddleware;
use App\Models\Release;
use App\Models\User;
use App\View\Composers\GlobalDataComposer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class AdminRegexesControllerTest extends TestCase
{
    use IsolatedSqliteDatabase;

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
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_admin_regex_test_pages_render_get_forms(): void
    {
        $this->actingAs($this->createUserWithRole('Admin'));

        foreach (['collection', 'release_naming'] as $type) {
            $this->get('/admin/'.$type.'_regexes-test')
                ->assertOk()
                ->assertSee('method="GET"', false)
                ->assertSee('Test Regex');
        }
    }

    public function test_admin_regex_edit_pages_accept_numeric_string_query_ids(): void
    {
        $admin = $this->createUserWithRole('Admin');

        $releaseNamingRegexId = DB::table('release_naming_regexes')->insertGetId([
            'group_regex' => 'alt\\.binaries\\.tv',
            'regex' => '/(?P<name>Example\.Show)/i',
            'description' => 'Release naming regex description',
            'ordinal' => 10,
            'status' => 1,
        ]);

        $categoryRegexId = DB::table('category_regexes')->insertGetId([
            'group_regex' => 'alt\\.binaries\\.movies',
            'regex' => '/movie/i',
            'description' => 'Category regex description',
            'ordinal' => 20,
            'categories_id' => 1,
            'status' => 1,
        ]);

        $collectionRegexId = DB::table('collection_regexes')->insertGetId([
            'group_regex' => 'alt\\.binaries\\.multimedia',
            'regex' => '/collection/i',
            'description' => 'Collection regex description',
            'ordinal' => 30,
            'status' => 1,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.release_naming_regexes-edit', ['id' => (string) $releaseNamingRegexId]))
            ->assertOk()
            ->assertSee('Release Naming Regex Edit')
            ->assertSee('Release naming regex description')
            ->assertSee('value="'.$releaseNamingRegexId.'"', false);

        $this->actingAs($admin)
            ->get(route('admin.category_regexes-edit', ['id' => (string) $categoryRegexId]))
            ->assertOk()
            ->assertSee('Category Regex Edit')
            ->assertSee('Category regex description')
            ->assertSee('value="'.$categoryRegexId.'"', false);

        $this->actingAs($admin)
            ->get(route('admin.collection_regexes-edit', ['id' => (string) $collectionRegexId]))
            ->assertOk()
            ->assertSee('Collections Regex Edit')
            ->assertSee('Collection regex description')
            ->assertSee('value="'.$collectionRegexId.'"', false);
    }

    public function test_admin_regex_edit_pages_return_404_for_invalid_query_ids(): void
    {
        $admin = $this->createUserWithRole('Admin');

        $this->actingAs($admin)
            ->get(route('admin.release_naming_regexes-edit', ['id' => 'invalid']))
            ->assertNotFound();

        $this->actingAs($admin)
            ->get(route('admin.category_regexes-edit', ['id' => 'invalid']))
            ->assertNotFound();

        $this->actingAs($admin)
            ->get(route('admin.collection_regexes-edit', ['id' => 'invalid']))
            ->assertNotFound();
    }

    public function test_admin_regex_list_pages_decode_entity_encoded_regexes_without_double_escaping(): void
    {
        $admin = $this->createUserWithRole('Admin');
        $rawRegex = '/^(?P<name>.+?) - "(?P<title>.+?)"$/i';
        $entityEncodedRegex = '/^(?P&lt;name&gt;.+?) - &quot;(?P&lt;title&gt;.+?)&quot;$/i';
        $scriptTag = '<'.'script>alert("x")</'.'script>';
        $htmlLookingRegex = '/^(?P&lt;name&gt;.+?)'.$scriptTag.'$/i';

        foreach (['release_naming_regexes', 'collection_regexes', 'category_regexes'] as $table) {
            $this->insertRegexFixture($table, $rawRegex, 'Raw regex fixture');
            $this->insertRegexFixture($table, $entityEncodedRegex, 'Entity encoded regex fixture');
            $this->insertRegexFixture($table, $htmlLookingRegex, 'HTML-looking regex fixture');
        }

        foreach ([
            route('admin.release_naming_regexes-list'),
            route('admin.collection_regexes-list'),
            route('admin.category_regexes-list'),
        ] as $url) {
            $response = $this->actingAs($admin)->get($url);

            $response->assertOk()
                ->assertSee(e($rawRegex), false)
                ->assertSee(e(html_entity_decode($entityEncodedRegex, ENT_QUOTES | ENT_HTML5, 'UTF-8')), false)
                ->assertSee(e(html_entity_decode($htmlLookingRegex, ENT_QUOTES | ENT_HTML5, 'UTF-8')), false)
                ->assertDontSee('&amp;quot;', false)
                ->assertDontSee('&amp;lt;', false)
                ->assertDontSee('&amp;gt;', false)
                ->assertDontSee($scriptTag, false);
        }
    }

    public function test_admin_regex_edit_pages_decode_entity_encoded_regexes_without_double_escaping(): void
    {
        $admin = $this->createUserWithRole('Admin');
        $entityEncodedRegex = '/^(?P&lt;name&gt;.+?) - &quot;(?P&lt;title&gt;.+?)&quot;$/i';
        $expectedRenderedRegex = e(html_entity_decode($entityEncodedRegex, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $releaseNamingRegexId = $this->insertRegexFixture('release_naming_regexes', $entityEncodedRegex, 'Release naming encoded regex');
        $collectionRegexId = $this->insertRegexFixture('collection_regexes', $entityEncodedRegex, 'Collection encoded regex');
        $categoryRegexId = $this->insertRegexFixture('category_regexes', $entityEncodedRegex, 'Category encoded regex');

        foreach ([
            route('admin.release_naming_regexes-edit', ['id' => (string) $releaseNamingRegexId]),
            route('admin.collection_regexes-edit', ['id' => (string) $collectionRegexId]),
            route('admin.category_regexes-edit', ['id' => (string) $categoryRegexId]),
        ] as $url) {
            $this->actingAs($admin)
                ->get($url)
                ->assertOk()
                ->assertSee($expectedRenderedRegex, false)
                ->assertDontSee('&amp;quot;', false)
                ->assertDontSee('&amp;lt;', false)
                ->assertDontSee('&amp;gt;', false);
        }
    }

    public function test_regex_test_inputs_are_validated_before_scanning_candidates(): void
    {
        $this->actingAs($this->createUserWithRole('Admin'));
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        DB::table('usenet_groups')->insert(['name' => 'alt.binaries.example']);

        foreach (['collection' => ['limit' => 1000], 'release_naming' => ['showlimit' => 1000, 'querylimit' => 500000]] as $type => $limits) {
            $url = '/admin/'.$type.'_regexes-test';
            $valid = ['group' => 'alt.binaries.example', 'regex' => '/(?<name>example)/'];
            foreach ($limits as $field => $maximum) {
                foreach ([0, -1, '1.5', 'no', $maximum + 1, ['1']] as $value) {
                    $this->getJson($url.'?'.http_build_query($valid + [$field => $value]))
                        ->assertUnprocessable()->assertJsonValidationErrors($field);
                }
            }
            foreach (['group' => ['unknown', ['bad'], ''], 'regex' => ['/[/', ['bad'], '']] as $field => $values) {
                foreach ($values as $value) {
                    $this->getJson($url.'?'.http_build_query(array_replace($valid, [$field => $value])))
                        ->assertUnprocessable()->assertJsonValidationErrors($field);
                }
            }
            $this->get($url.'?'.http_build_query($valid + [array_key_first($limits) => 0]))
                ->assertRedirect($url)->assertSessionHasErrors(array_key_first($limits));
            $this->get($url)->assertOk()->assertSee('must be between 1 and');
        }
    }

    public function test_collection_regex_tests_each_selected_binary_once_in_id_order(): void
    {
        $this->createCandidateSchema();
        DB::table('collections')->insert([
            ['id' => 1, 'groups_id' => 1, 'fromname' => 'poster', 'collectionhash' => 'old'],
            ['id' => 2, 'groups_id' => 2, 'fromname' => 'other', 'collectionhash' => 'other'],
        ]);
        $rows = [];
        for ($id = 1; $id <= 1200; $id++) {
            $rows[] = ['id' => $id, 'collections_id' => $id % 2 ? 2 : 1,
                'name' => $id % 4 === 0 ? 'Café - 02' : 'unmatched',
                'binaryhash' => 'duplicate', 'totalparts' => 1, 'currentparts' => 1];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('binaries')->insert($chunk);
        }
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, '"binaries"')) {
                $queries[] = $query->sql;
            }
        });
        $response = $this->actingAs($this->createUserWithRole('Admin'))
            ->get('/admin/collection_regexes-test?'.http_build_query([
                'group' => 'alt.binaries.example', 'regex' => '/(?<a>Café) - (?<b>02)/u', 'limit' => 550,
            ]))->assertOk()->assertSee('550 binaries tested; 275 matched');
        $data = $response->viewData('data');
        $this->assertSame(range(2, 1100, 2), array_column($data, 'binaryID'));
        $this->assertFalse($data[0]['match']);
        $this->assertSame('Café02', $data[1]['name']);
        $this->assertTrue($data[1]['match']);
        $this->assertCount(2, $queries);
        $this->assertStringContainsString('limit 500', $queries[0]);
        $this->assertStringContainsString('limit 50', $queries[1]);
    }

    public function test_naming_regex_limits_candidates_and_reports_why_scanning_stopped(): void
    {
        $this->createCandidateSchema();
        $rows = [];
        for ($id = 1; $id <= 1200; $id++) {
            $rows[] = ['id' => $id, 'groups_id' => $id % 2 ? 2 : 1,
                'name' => $id % 200 === 0 ? 'Café - 02 - 99' : 'unmatched', 'searchname' => 'Old name'];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('releases')->insert($chunk);
        }
        $hydrated = 0;
        Event::listen('eloquent.retrieved: '.Release::class, function () use (&$hydrated): void {
            $hydrated++;
        });
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, '"releases"')) {
                $queries[] = $query->sql;
            }
        });
        $this->actingAs($this->createUserWithRole('Admin'));
        $input = ['group' => 'alt.binaries.example', 'regex' => '/(?<b>Café) - (?<a>02) - (?<reqid>99)/u'];
        $url = '/admin/release_naming_regexes-test?';
        $response = $this->get($url.http_build_query($input + ['showlimit' => 3]))
            ->assertOk()->assertSee('300 releases tested; 3 matches shown')->assertSee('Stopped at the result limit');
        $this->assertSame([200, 400, 600], array_column($response->viewData('data'), 'releaseID'));
        $this->assertSame('02Café', $response->viewData('data')[0]['newName']);
        $this->assertSame('Old name', $response->viewData('data')[0]['oldName']);
        $this->get($url.http_build_query($input + ['querylimit' => 250]))
            ->assertOk()->assertSee('250 releases tested; 2 matches shown')->assertSee('Stopped at the candidate limit');
        $this->get($url.http_build_query($input))
            ->assertOk()->assertSee('600 releases tested; 6 matches shown')->assertSee('Exhausted the group');
        $this->assertSame(0, $hydrated);
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/limit (?:500|250|[1-9][0-9]?)$/', $query);
            $this->assertStringNotContainsString('offset', $query);
        }
    }

    public function test_regex_test_pages_show_zero_matches_and_control_pcre_runtime_errors(): void
    {
        $this->createCandidateSchema();
        DB::table('collections')->insert(['id' => 1, 'groups_id' => 1, 'fromname' => 'poster', 'collectionhash' => 'old']);
        DB::table('binaries')->insert(['id' => 1, 'collections_id' => 1, 'name' => str_repeat('a', 100).'b',
            'binaryhash' => 'hash', 'totalparts' => 1, 'currentparts' => 1]);
        DB::table('releases')->insert(['id' => 1, 'groups_id' => 1, 'name' => str_repeat('a', 100).'b', 'searchname' => 'Original']);
        $this->actingAs($this->createUserWithRole('Admin'));
        foreach (['collection', 'release_naming'] as $type) {
            $url = '/admin/'.$type.'_regexes-test';
            $input = ['group' => 'alt.binaries.example', 'regex' => '/(?<name>never)/'];
            $this->get($url.'?'.http_build_query($input))->assertOk()
                ->assertSee($type === 'collection' ? '1 binaries tested; 0 matched' : '1 releases tested; 0 matches shown');
            $input['group'] = 'alt.binaries.other';
            $this->get($url.'?'.http_build_query($input))->assertOk()
                ->assertSee($type === 'collection' ? '0 binaries tested; 0 matched' : '0 releases tested; 0 matches shown');
            $input = ['group' => 'alt.binaries.example', 'regex' => '/(*NO_JIT)(*LIMIT_MATCH=10)^(?<name>a+)+$/'];
            $this->getJson($url.'?'.http_build_query($input))
                ->assertUnprocessable()->assertJsonValidationErrors('regex');
            $this->get($url.'?'.http_build_query($input))->assertRedirect($url)->assertSessionHasErrors('regex');
            $this->get($url)->assertOk()->assertSee('Backtrack limit exhausted')->assertDontSee('Test Results:');
        }
        $this->assertSame('Original', DB::table('releases')->value('searchname'));
    }

    private function createCandidateSchema(): void
    {
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        DB::table('usenet_groups')->insert([
            ['id' => 1, 'name' => 'alt.binaries.example'],
            ['id' => 2, 'name' => 'alt.binaries.other'],
        ]);
        Schema::create('collections', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('groups_id');
            $table->string('fromname');
            $table->binary('collectionhash');
        });
        Schema::create('binaries', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('collections_id');
            $table->text('name');
            $table->integer('totalparts');
            $table->integer('currentparts');
            $table->binary('binaryhash');
        });
        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('groups_id');
            $table->text('name');
            $table->text('searchname');
        });
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

        Schema::create('user_excluded_categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('users_id');
            $table->unsignedInteger('categories_id');
        });

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
            $table->timestamps();
        });

        Schema::create('release_naming_regexes', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('group_regex');
            $table->text('regex');
            $table->text('description')->nullable();
            $table->integer('ordinal')->default(0);
            $table->integer('status')->default(1);
        });

        Schema::create('category_regexes', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('group_regex');
            $table->text('regex');
            $table->text('description')->nullable();
            $table->integer('ordinal')->default(0);
            $table->unsignedInteger('categories_id');
            $table->integer('status')->default(1);
        });

        Schema::create('collection_regexes', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('group_regex');
            $table->text('regex');
            $table->text('description')->nullable();
            $table->integer('ordinal')->default(0);
            $table->integer('status')->default(1);
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

    private function insertRegexFixture(string $table, string $regex, string $description): int
    {
        $data = [
            'group_regex' => 'alt\\.binaries\\.example',
            'regex' => $regex,
            'description' => $description,
            'ordinal' => 10,
            'status' => 1,
        ];

        if ($table === 'category_regexes') {
            $data['categories_id'] = 1;
        }

        return (int) DB::table($table)->insertGetId($data);
    }

    private function resetGlobalComposerState(): void
    {
        $reflection = new ReflectionClass(GlobalDataComposer::class);
        $property = $reflection->getProperty('resolvedData');
        $property->setValue(null, null);
    }
}
