<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BlacklistConstants;
use App\Http\Middleware\Google2FAMiddleware;
use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\Category;
use App\Models\Release;
use App\Models\RootCategory;
use App\Models\Settings;
use App\Models\User;
use App\Services\BlacklistSweepService;
use App\Services\Releases\ReleaseBrowseService;
use App\View\Composers\GlobalDataComposer;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class PosterIdentityControllerTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'nntmux.items_per_page' => 2,
            'nntmux.max_pager_results' => 500000,
            'nntmux.cache_expiry_short' => 1,
            'nntmux.cache_expiry_medium' => 1,
        ]);

        $this->registerSqliteFunction(
            'REGEXP',
            static fn (?string $pattern, ?string $value): int => @preg_match('/'.($pattern ?? '').'/i', $value ?? '') === 1 ? 1 : 0,
            2,
        );
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
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_poster_identity_page_matches_exact_identity_and_paginates_newest_first(): void
    {
        $user = $this->verifiedUser();
        $identity = 'user <user@x.localdomain>';

        $this->release('Newest exact', $identity, '2026-08-03 12:00:00');
        $this->release('Middle exact', $identity, '2026-08-02 12:00:00');
        $this->release('Oldest exact', $identity, '2026-08-01 12:00:00');
        $this->release('Look alike', 'user <user@2.localdomain>', '2026-08-04 12:00:00');
        $this->release('Case variant', 'user <USER@x.localdomain>', '2026-08-05 12:00:00');

        $firstPage = $this->actingAs($user)->get(route('poster-identity', ['name' => $identity]));

        $firstPage->assertOk();
        $firstPage->assertSeeInOrder(['Newest exact', 'Middle exact']);
        $firstPage->assertDontSee('Oldest exact');
        $firstPage->assertDontSee('Look alike');
        $firstPage->assertDontSee('Case variant');
        $firstPage->assertSee('name=user%20%3Cuser%40x.localdomain%3E', false);
        $firstPage->assertSee('All releases from this poster');
        $firstPage->assertSee('bg-primary-100', false);
        $firstPage->assertDontSee('bg-indigo-100', false);

        $secondPage = $this->actingAs($user)->get(route('poster-identity', ['name' => $identity, 'page' => 2]));
        $secondPage->assertOk();
        $secondPage->assertSee('Oldest exact');
        $secondPage->assertDontSee('Look alike');
        $secondPage->assertDontSee('Case variant');
    }

    public function test_poster_identity_page_respects_category_exclusions_and_renders_empty_state(): void
    {
        $user = $this->verifiedUser();
        DB::table('user_excluded_categories')->insert([
            'users_id' => $user->id,
            'categories_id' => 2030,
        ]);
        $this->release('Excluded release', 'excluded@example.test', '2026-08-03 12:00:00');

        $this->actingAs($user)
            ->get(route('poster-identity', ['name' => 'excluded@example.test']))
            ->assertOk()
            ->assertDontSee('Excluded release')
            ->assertSee('No releases found');

        $this->actingAs($user)
            ->get(route('poster-identity'))
            ->assertOk()
            ->assertSee('No releases found');
    }

    public function test_poster_identity_page_requires_authentication_and_verification(): void
    {
        $this->get('/poster?name=poster%40example.test')->assertRedirect(route('login'));

        $unverified = $this->user(false);
        $this->actingAs($unverified)
            ->get('/poster?name=poster%40example.test')
            ->assertRedirect(route('verification.notice'));
    }

    public function test_only_admins_see_the_poster_identity_blacklist_control(): void
    {
        $identity = 'poster@example.test';
        $this->release('Poster release', $identity, '2026-08-03 12:00:00');

        $user = $this->verifiedUser();
        $this->actingAs($user)
            ->get(route('poster-identity', ['name' => $identity]))
            ->assertOk()
            ->assertDontSee('Blacklist this poster');
        $this->actingAs($user)
            ->post(route('admin.poster-identity.blacklist'), ['name' => $identity])
            ->assertForbidden();

        $this->flushSession();
        $this->actingAs($this->verifiedUser('Admin'))
            ->get(route('poster-identity', ['name' => $identity]))
            ->assertOk()
            ->assertSee('Blacklist this poster');
    }

    public function test_admin_sees_the_matching_enabled_posted_by_rule_instead_of_an_add_control(): void
    {
        $identity = 'poster@example.test';
        $this->release('Poster release', $identity, '2026-08-03 12:00:00', 'alt.binaries.movies');
        $ruleId = DB::table('binaryblacklist')->insertGetId([
            'groupname' => '^alt\.binaries\.movies$',
            'regex' => 'poster@example\.test',
            'description' => 'Existing broader rule',
            'status' => BlacklistConstants::BLACKLIST_ENABLED,
            'optype' => BlacklistConstants::OPTYPE_BLACKLIST,
            'msgcol' => BlacklistConstants::BLACKLIST_FIELD_FROM,
        ]);

        $this->actingAs($this->verifiedUser('Admin'))
            ->get(route('poster-identity', ['name' => $identity]))
            ->assertOk()
            ->assertSee('Blacklisted (rule #'.$ruleId.')')
            ->assertSee(route('admin.binaryblacklist-edit', ['id' => $ruleId]), false)
            ->assertDontSee('Blacklist this poster');
    }

    public function test_blacklist_confirmation_shows_the_exact_read_only_rule_and_optional_sweep(): void
    {
        $identity = 'poster+tag/user@example.test';
        $this->release('Movie release', $identity, '2026-08-03 12:00:00', 'alt.binaries.movies');
        $this->release('TV release', $identity, '2026-08-02 12:00:00', 'alt.binaries.tv');
        $admin = $this->verifiedUser('Admin');

        $response = $this->actingAs($admin)
            ->get(route('poster-identity', ['name' => $identity]))
            ->assertOk()
            ->assertSee('^poster\+tag\/user@example\.test$')
            ->assertSee('Posted By · Type: Black · Status: enabled')
            ->assertSee('^(?:alt\.binaries\.movies|alt\.binaries\.tv)$')
            ->assertSee('Poster identity blocked from poster page by '.$admin->username)
            ->assertSee("Also permanently remove this poster's 2 existing releases now", false)
            ->assertSee('name="delete_releases"', false)
            ->assertDontSee('name="regex"', false)
            ->assertDontSee('name="groupname"', false);

        $modal = $this->htmlElement($response->getContent(), '//*[@x-show="confirmationOpen"]');
        $this->assertNotNull($modal);
        $this->assertTrue($this->hasAlpineDataAncestor($modal, 'posterIdentityBlacklist'));
    }

    public function test_admin_can_create_the_exact_enabled_posted_by_rule_without_starting_a_sweep(): void
    {
        Process::fake(fn () => Process::result(output: getmypid()."\n"));
        $sweeps = new BlacklistSweepService($this->makeTempDirectory('poster-identity-no-sweep'));
        app()->instance(BlacklistSweepService::class, $sweeps);
        $identity = 'poster+tag/user@example.test';
        $this->release('Movie release', $identity, '2026-08-03 12:00:00', 'alt.binaries.movies');
        $this->release('TV release', $identity, '2026-08-02 12:00:00', 'alt.binaries.tv');
        $admin = $this->verifiedUser('Admin');
        $previewToken = $this->blacklistPreviewToken($admin, $identity);

        $response = $this->actingAs($admin)->post(route('admin.poster-identity.blacklist'), [
            'name' => $identity,
            'preview_token' => $previewToken,
        ]);

        $ruleId = (int) DB::table('binaryblacklist')->value('id');
        $response
            ->assertRedirect(route('poster-identity', ['name' => $identity]))
            ->assertSessionHas('success', 'Rule #'.$ruleId.' added · sweep not started');
        $this->assertDatabaseHas('binaryblacklist', [
            'id' => $ruleId,
            'groupname' => '^(?:alt\.binaries\.movies|alt\.binaries\.tv)$',
            'regex' => '^poster\+tag\/user@example\.test$',
            'description' => 'Poster identity blocked from poster page by '.$admin->username,
            'status' => BlacklistConstants::BLACKLIST_ENABLED,
            'optype' => BlacklistConstants::OPTYPE_BLACKLIST,
            'msgcol' => BlacklistConstants::BLACKLIST_FIELD_FROM,
        ]);
        $this->assertFalse($sweeps->status()['running']);
        $this->assertNull($sweeps->status()['current']);
    }

    public function test_tampered_regex_and_group_values_are_rejected(): void
    {
        $identity = 'poster@example.test';
        $this->release('Poster release', $identity, '2026-08-03 12:00:00', 'alt.binaries.movies');

        $admin = $this->verifiedUser('Admin');
        $previewToken = $this->blacklistPreviewToken($admin, $identity);

        $this->actingAs($admin)
            ->postJson(route('admin.poster-identity.blacklist'), [
                'name' => $identity,
                'preview_token' => $previewToken,
                'regex' => '.*',
                'groupname' => '.*',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['regex', 'groupname']);

        $this->assertDatabaseCount('binaryblacklist', 0);

        $this->actingAs($admin)
            ->postJson(route('admin.poster-identity.blacklist'), [
                'name' => $identity,
                'preview_token' => $previewToken.'tampered',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['preview_token']);

        $this->assertDatabaseCount('binaryblacklist', 0);
    }

    public function test_disabled_generated_rule_is_reenabled_instead_of_duplicated(): void
    {
        $identity = 'poster@example.test';
        $this->release('Poster release', $identity, '2026-08-03 12:00:00', 'alt.binaries.movies');
        $ruleId = DB::table('binaryblacklist')->insertGetId([
            'groupname' => '^alt\.binaries\.old$',
            'regex' => '^poster@example\.test$',
            'description' => 'Poster identity blocked from poster page by previous-admin',
            'status' => 0,
            'optype' => BlacklistConstants::OPTYPE_BLACKLIST,
            'msgcol' => BlacklistConstants::BLACKLIST_FIELD_FROM,
        ]);
        $admin = $this->verifiedUser('Admin');
        $previewToken = $this->blacklistPreviewToken($admin, $identity);

        $this->actingAs($admin)
            ->post(route('admin.poster-identity.blacklist'), [
                'name' => $identity,
                'preview_token' => $previewToken,
            ])
            ->assertRedirect(route('poster-identity', ['name' => $identity]));

        $this->assertDatabaseCount('binaryblacklist', 1);
        $this->assertDatabaseHas('binaryblacklist', [
            'id' => $ruleId,
            'status' => BlacklistConstants::BLACKLIST_ENABLED,
            'groupname' => '^(?:alt\.binaries\.movies)$',
            'description' => 'Poster identity blocked from poster page by '.$admin->username,
        ]);
    }

    public function test_repeated_submission_does_not_duplicate_an_enabled_matching_rule(): void
    {
        $identity = 'poster@example.test';
        $this->release('Poster release', $identity, '2026-08-03 12:00:00', 'alt.binaries.movies');
        $admin = $this->verifiedUser('Admin');
        $previewToken = $this->blacklistPreviewToken($admin, $identity);

        $this->actingAs($admin)->post(route('admin.poster-identity.blacklist'), [
            'name' => $identity,
            'preview_token' => $previewToken,
        ]);
        $ruleId = (int) DB::table('binaryblacklist')->value('id');
        $this->actingAs($admin)
            ->post(route('admin.poster-identity.blacklist'), [
                'name' => $identity,
                'preview_token' => $previewToken,
            ])
            ->assertSessionHas('success', 'Rule #'.$ruleId.' added · sweep not started');

        $this->assertDatabaseCount('binaryblacklist', 1);
    }

    public function test_disabled_hand_written_exact_rule_is_not_modified(): void
    {
        $identity = 'poster@example.test';
        $this->release('Poster release', $identity, '2026-08-03 12:00:00', 'alt.binaries.movies');
        $handWrittenRuleId = DB::table('binaryblacklist')->insertGetId([
            'groupname' => '^alt\.binaries\.movies$',
            'regex' => '^poster@example\.test$',
            'description' => 'Hand-written exact rule',
            'status' => 0,
            'optype' => BlacklistConstants::OPTYPE_BLACKLIST,
            'msgcol' => BlacklistConstants::BLACKLIST_FIELD_FROM,
        ]);

        $admin = $this->verifiedUser('Admin');

        $this->actingAs($admin)
            ->post(route('admin.poster-identity.blacklist'), [
                'name' => $identity,
                'preview_token' => $this->blacklistPreviewToken($admin, $identity),
            ])
            ->assertRedirect(route('poster-identity', ['name' => $identity]));

        $this->assertDatabaseCount('binaryblacklist', 2);
        $this->assertDatabaseHas('binaryblacklist', [
            'id' => $handWrittenRuleId,
            'status' => 0,
            'description' => 'Hand-written exact rule',
        ]);
    }

    public function test_checked_confirmation_starts_a_single_rule_delete_sweep_and_shows_its_status(): void
    {
        Process::fake(fn () => Process::result(output: getmypid()."\n"));
        $sweeps = new BlacklistSweepService($this->makeTempDirectory('poster-identity-sweeps'));
        app()->instance(BlacklistSweepService::class, $sweeps);
        $identity = 'poster@example.test';
        $this->release('Poster release', $identity, '2026-08-03 12:00:00', 'alt.binaries.movies');
        $admin = $this->verifiedUser('Admin');
        $previewToken = $this->blacklistPreviewToken($admin, $identity);

        $response = $this->actingAs($admin)->post(route('admin.poster-identity.blacklist'), [
            'name' => $identity,
            'preview_token' => $previewToken,
            'delete_releases' => '1',
        ]);

        $ruleId = (int) DB::table('binaryblacklist')->value('id');
        $response
            ->assertRedirect(route('poster-identity', ['name' => $identity]))
            ->assertSessionHas('success', 'Rule #'.$ruleId.' added · sweep started');
        $status = $sweeps->status();
        $this->assertTrue($status['running']);
        $this->assertSame('delete', $status['current']['mode']);
        $this->assertSame($ruleId, $status['current']['rule_id']);

        $this->get(route('poster-identity', ['name' => $identity]))
            ->assertOk()
            ->assertSee('x-data="blacklistSweep"', false)
            ->assertSee('Sweep controls are disabled while this run finishes.');
    }

    public function test_rule_is_saved_when_another_sweep_holds_the_runner(): void
    {
        Process::fake(fn () => Process::result(output: getmypid()."\n"));
        $sweeps = new BlacklistSweepService($this->makeTempDirectory('poster-identity-locked-sweeps'));
        app()->instance(BlacklistSweepService::class, $sweeps);
        $sweeps->start('dry-run');
        $identity = 'poster@example.test';
        $this->release('Poster release', $identity, '2026-08-03 12:00:00', 'alt.binaries.movies');
        $admin = $this->verifiedUser('Admin');
        $previewToken = $this->blacklistPreviewToken($admin, $identity);

        $response = $this->actingAs($admin)->post(route('admin.poster-identity.blacklist'), [
            'name' => $identity,
            'preview_token' => $previewToken,
            'delete_releases' => '1',
        ]);

        $ruleId = (int) DB::table('binaryblacklist')->value('id');
        $response
            ->assertRedirect(route('poster-identity', ['name' => $identity]))
            ->assertSessionHas('success', 'Rule #'.$ruleId.' added · sweep could not start')
            ->assertSessionMissing('poster_identity_blacklist_sweep_started');
        $this->assertDatabaseHas('binaryblacklist', [
            'id' => $ruleId,
            'status' => BlacklistConstants::BLACKLIST_ENABLED,
        ]);
        $this->assertNull($sweeps->status()['current']['rule_id']);
    }

    public function test_confirmation_saves_the_group_scope_that_was_displayed(): void
    {
        $identity = 'poster@example.test';
        $this->release('Movie release', $identity, '2026-08-03 12:00:00', 'alt.binaries.movies');
        $admin = $this->verifiedUser('Admin');
        $previewToken = $this->blacklistPreviewToken($admin, $identity);

        $this->release('Later TV release', $identity, '2026-08-04 12:00:00', 'alt.binaries.tv');

        $this->actingAs($admin)->post(route('admin.poster-identity.blacklist'), [
            'name' => $identity,
            'preview_token' => $previewToken,
        ])->assertRedirect(route('poster-identity', ['name' => $identity]));

        $this->assertDatabaseHas('binaryblacklist', [
            'groupname' => '^(?:alt\.binaries\.movies)$',
        ]);
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

    public function test_poster_identity_rows_include_spectrogram_availability_without_per_release_queries(): void
    {
        $identity = 'audio-poster@example.test';
        $withSpectrogram = $this->release('Audio with spectrogram', $identity, '2026-08-03 12:00:00');
        $withoutSpectrogram = $this->release('Audio without spectrogram', $identity, '2026-08-02 12:00:00');
        DB::table('release_audio_tags')->insert([
            ['releases_id' => $withSpectrogram->id, 'has_spectrogram' => 1],
            ['releases_id' => $withoutSpectrogram->id, 'has_spectrogram' => 0],
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $rows = app(ReleaseBrowseService::class)->getPosterIdentityReleases($identity, 20);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([true, false], $rows->map(
            static fn (Release $release): bool => (bool) $release->has_spectrogram,
        )->all());

        $audioTagQueries = array_filter(
            $queries,
            static fn (array $query): bool => str_contains(strtolower($query['query']), 'release_audio_tags'),
        );
        $this->assertCount(1, $audioTagQueries);
    }

    private function verifiedUser(string $roleName = 'User'): User
    {
        $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $permission = Permission::query()->firstOrCreate(['name' => 'view movies', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        $user = $this->user();
        if ($roleName === 'Admin') {
            DB::table('users')->where('id', $user->id)->update(['roles_id' => 2]);
            $user->roles_id = 2;
        }
        $user->assignRole($role);
        $user->givePermissionTo($permission);

        return $user;
    }

    private function blacklistPreviewToken(User $admin, string $identity): string
    {
        $response = $this->actingAs($admin)->get(route('poster-identity', ['name' => $identity]));
        $response->assertOk();

        $input = $this->htmlElement($response->getContent(), '//input[@name="preview_token"]');
        $this->assertNotNull($input);

        return $input->getAttribute('value');
    }

    private function htmlElement(string $html, string $query): ?DOMElement
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $element = (new DOMXPath($document))->query($query)?->item(0);

        return $element instanceof DOMElement ? $element : null;
    }

    private function hasAlpineDataAncestor(DOMElement $element, string $component): bool
    {
        for ($ancestor = $element->parentElement; $ancestor !== null; $ancestor = $ancestor->parentElement) {
            if ($ancestor->getAttribute('x-data') === $component) {
                return true;
            }
        }

        return false;
    }

    private function release(string $name, string $identity, string $postdate, ?string $groupName = null): Release
    {
        $groupId = $groupName === null
            ? null
            : DB::table('usenet_groups')->where('name', $groupName)->value('id')
                ?? DB::table('usenet_groups')->insertGetId(['name' => $groupName]);
        $id = DB::table('releases')->insertGetId([
            'name' => $name,
            'searchname' => $name,
            'fromname' => $identity,
            'postdate' => $postdate,
            'adddate' => $postdate,
            'guid' => sha1($name.$identity.$postdate),
            'categories_id' => 2030,
            'groups_id' => $groupId,
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
            $table->float('completion')->default(0);
            $table->string('repair_outcome')->nullable();
            $table->string('rescan_outcome')->nullable();
            $table->string('display_name')->nullable();
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
            $table->integer('videostatus')->default(0);
            $table->index(['fromname', 'postdate'], 'ix_releases_fromname_postdate');
        });
        Schema::create('release_audio_tags', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id')->primary();
            $table->unsignedTinyInteger('has_spectrogram')->default(0);
        });
        Schema::create('release_video_clips', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id')->primary();
            $table->string('extension', 8);
            $table->string('mime', 32);
        });
        Schema::create('binaryblacklist', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('groupname');
            $table->text('regex');
            $table->string('description')->nullable();
            $table->integer('status')->default(1);
            $table->integer('optype')->default(1);
            $table->integer('msgcol')->default(1);
            $table->timestamp('last_activity')->nullable();
        });
    }

    private function resetGlobalComposerState(): void
    {
        $property = (new ReflectionClass(GlobalDataComposer::class))->getProperty('resolvedData');
        $property->setValue(null, null);
    }
}
