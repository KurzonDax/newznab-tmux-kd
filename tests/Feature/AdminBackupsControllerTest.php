<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\Google2FAMiddleware;
use App\Models\User;
use App\View\Composers\GlobalDataComposer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminBackupsControllerTest extends TestCase
{
    private string $backupLocation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupLocation = $this->makeTempDirectory('nntmux-admin-backups');
        $this->createSchema();
        $this->seedSettings();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Cache::flush();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->resetGlobalComposerState();
        $this->withoutMiddleware(Google2FAMiddleware::class);
    }

    public function test_admin_page_renders_status_and_sets_from_disk(): void
    {
        $response = $this->actingAs($this->user('Admin'))->get(route('admin.backups.index'));

        $response->assertOk();
        $response->assertSee('Database Backups');
        $response->assertSee($this->backupLocation);
        $response->assertSee('No Backup sets found');
    }

    public function test_admin_page_reconciles_disk_sets_and_shows_offsite_status(): void
    {
        $setId = '20260816-020000';
        $directory = $this->backupLocation.'/'.$setId;
        mkdir($directory);
        $dump = $directory.'/full-20260816-0200.sql.gz';
        file_put_contents($dump, gzencode("backup\n"));
        $manifest = $dump.'.manifest.json';
        file_put_contents($manifest, json_encode([
            'kind' => 'full',
            'started_at' => '2026-08-16T02:00:00-05:00',
            'finished_at' => '2026-08-16T02:01:00-05:00',
            'tables' => ['settings'],
            'tiers_included' => ['important'],
            'bytes' => filesize($dump),
            'sha256' => hash_file('sha256', $dump),
            'app_version' => 'test',
            'db_server_version' => 'test',
            'set_id' => $setId,
        ], JSON_THROW_ON_ERROR));
        DB::table('database_backups')->insert([
            'kind' => 'full',
            'set_id' => $setId,
            'path' => $manifest,
            'bytes' => filesize($dump),
            'sha256' => hash_file('sha256', $dump),
            'started_at' => '2026-08-16 07:00:00',
            'finished_at' => '2026-08-16 07:01:00',
            'status' => 'successful',
            'offsite_status' => 'copied',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user('Admin'))->get(route('admin.backups.index'));

        $response->assertOk();
        $response->assertSee($setId);
        $response->assertSee('Full');
        $response->assertSee('Copied');
        $response->assertDontSee('No Backup sets found');
    }

    public function test_admin_can_save_valid_backup_settings(): void
    {
        $response = $this->actingAs($this->user('Admin'))->post(route('admin.backups.update'), [
            'backup_enabled' => '1',
            'backup_full_dow' => '6',
            'backup_full_time' => '03:15',
            'backup_daily_time' => '04:20',
            'backup_location' => $this->backupLocation,
            'backup_keep_fulls' => '6',
            'backup_pause_tmux' => '1',
            'backup_incl_working' => '0',
            'backup_dump_binary' => '',
            'backup_offsite_path' => '',
            'backup_offsite_after' => '0',
            'backup_offsite_keep' => '12',
        ]);

        $response->assertRedirect(route('admin.backups.index'));
        $response->assertSessionHas('success');
        $this->assertSame('6', DB::table('settings')->where('name', 'backup_keep_fulls')->value('value'));
        $this->assertSame('0', DB::table('settings')->where('name', 'backup_incl_working')->value('value'));
    }

    public function test_relative_backup_location_is_rejected_inline(): void
    {
        $response = $this->from(route('admin.backups.index'))
            ->actingAs($this->user('Admin'))
            ->post(route('admin.backups.update'), [
                'backup_enabled' => '1',
                'backup_full_dow' => '0',
                'backup_full_time' => '02:00',
                'backup_daily_time' => '02:00',
                'backup_location' => 'relative/backups',
                'backup_keep_fulls' => '4',
                'backup_pause_tmux' => '1',
                'backup_incl_working' => '1',
                'backup_offsite_after' => '0',
                'backup_offsite_keep' => '0',
            ]);

        $response->assertRedirect(route('admin.backups.index'));
        $response->assertSessionHasErrors('backup_location');
        $this->assertSame($this->backupLocation, DB::table('settings')->where('name', 'backup_location')->value('value'));
    }

    public function test_public_web_root_backup_location_is_rejected_inline(): void
    {
        $response = $this->from(route('admin.backups.index'))
            ->actingAs($this->user('Admin'))
            ->post(route('admin.backups.update'), $this->validSettings(['backup_location' => public_path()]));

        $response->assertRedirect(route('admin.backups.index'));
        $response->assertSessionHasErrors('backup_location');
    }

    public function test_unwritable_backup_location_is_rejected_inline(): void
    {
        $unwritable = $this->makeTempDirectory('nntmux-unwritable-backups');
        chmod($unwritable, 0o555);

        try {
            $response = $this->from(route('admin.backups.index'))
                ->actingAs($this->user('Admin'))
                ->post(route('admin.backups.update'), $this->validSettings(['backup_location' => $unwritable]));

            $response->assertRedirect(route('admin.backups.index'));
            $response->assertSessionHasErrors('backup_location');
        } finally {
            chmod($unwritable, 0o755);
        }
    }

    public function test_run_now_records_request_for_next_tick(): void
    {
        DB::table('settings')->where('name', 'backup_enabled')->update(['value' => '1']);
        $response = $this->actingAs($this->user('Admin'))->post(route('admin.backups.run', ['kind' => 'full']));

        $response->assertRedirect(route('admin.backups.index'));
        $response->assertSessionHas('success');
        $this->assertSame('full', DB::table('settings')->where('name', 'backup_run_request')->value('value'));
    }

    public function test_run_now_is_rejected_while_backups_are_disabled(): void
    {
        $response = $this->actingAs($this->user('Admin'))->post(route('admin.backups.run', ['kind' => 'daily']));

        $response->assertRedirect(route('admin.backups.index'));
        $response->assertSessionHas('error', 'Enable database backups before requesting a run.');
        $this->assertSame('', DB::table('settings')->where('name', 'backup_run_request')->value('value'));
    }

    public function test_pending_full_run_keeps_priority_over_a_daily_request(): void
    {
        DB::table('settings')->where('name', 'backup_enabled')->update(['value' => '1']);
        DB::table('settings')->where('name', 'backup_run_request')->update(['value' => 'full']);

        $this->actingAs($this->user('Admin'))
            ->post(route('admin.backups.run', ['kind' => 'daily']))
            ->assertSessionHas('success', 'Full backup requested; it will start within one minute.');

        $this->assertSame('full', DB::table('settings')->where('name', 'backup_run_request')->value('value'));
    }

    public function test_non_admin_cannot_access_backups_page(): void
    {
        $this->actingAs($this->user('User'))
            ->get(route('admin.backups.index'))
            ->assertForbidden();
    }

    private function createSchema(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name', 25)->primary();
            $table->text('value')->nullable();
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
            $table->unsignedInteger('grabs')->default(0);
            $table->unsignedInteger('invites')->default(0);
            $table->text('notes')->default('');
            $table->boolean('movieview')->default(true);
            $table->boolean('xxxview')->default(false);
            $table->boolean('musicview')->default(true);
            $table->boolean('consoleview')->default(true);
            $table->boolean('bookview')->default(true);
            $table->boolean('gameview')->default(true);
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
        Schema::create('user_activities', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('username');
            $table->string('activity_type', 50);
            $table->text('description');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('root_categories_id')->nullable();
            $table->string('title')->default('');
        });
        Schema::create('user_excluded_categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('users_id');
            $table->unsignedInteger('categories_id');
        });
        Schema::create('database_backups', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 10);
            $table->string('set_id');
            $table->text('path')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 20);
            $table->string('offsite_status', 20)->nullable();
            $table->timestamp('offsite_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    private function seedSettings(): void
    {
        DB::table('settings')->insert([
            ['name' => 'title', 'value' => 'NNTmux Test'],
            ['name' => 'home_link', 'value' => '/'],
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
            ['name' => 'backup_enabled', 'value' => '0'],
            ['name' => 'backup_full_dow', 'value' => '0'],
            ['name' => 'backup_full_time', 'value' => '02:00'],
            ['name' => 'backup_daily_time', 'value' => '02:00'],
            ['name' => 'backup_location', 'value' => $this->backupLocation],
            ['name' => 'backup_keep_fulls', 'value' => '4'],
            ['name' => 'backup_pause_tmux', 'value' => '1'],
            ['name' => 'backup_incl_working', 'value' => '1'],
            ['name' => 'backup_dump_binary', 'value' => ''],
            ['name' => 'backup_offsite_path', 'value' => ''],
            ['name' => 'backup_offsite_after', 'value' => '0'],
            ['name' => 'backup_offsite_keep', 'value' => '0'],
            ['name' => 'backup_run_request', 'value' => ''],
            ['name' => 'backup_pause_marker', 'value' => ''],
        ]);
    }

    private function user(string $roleName): User
    {
        $role = Role::findOrCreate($roleName, 'web');
        $user = User::query()->create([
            'username' => strtolower($roleName).'-'.bin2hex(random_bytes(3)),
            'email' => strtolower($roleName).'-'.bin2hex(random_bytes(3)).'@example.test',
            'password' => bcrypt('password'),
            'roles_id' => $role->id,
            'email_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function resetGlobalComposerState(): void
    {
        $reflection = new ReflectionClass(GlobalDataComposer::class);
        $property = $reflection->getProperty('resolvedData');
        $property->setValue(null, null);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function validSettings(array $overrides = []): array
    {
        return array_merge([
            'backup_enabled' => '1',
            'backup_full_dow' => '0',
            'backup_full_time' => '02:00',
            'backup_daily_time' => '02:00',
            'backup_location' => $this->backupLocation,
            'backup_keep_fulls' => '4',
            'backup_pause_tmux' => '1',
            'backup_incl_working' => '1',
            'backup_dump_binary' => '',
            'backup_offsite_path' => '',
            'backup_offsite_after' => '0',
            'backup_offsite_keep' => '0',
        ], $overrides);
    }
}
