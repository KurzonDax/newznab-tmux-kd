<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\Google2FAMiddleware;
use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\User;
use App\Services\BlacklistSweepService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class AdminBlacklistSweepControllerTest extends TestCase
{
    use IsolatedSqliteDatabase;

    private string $sweepDirectory;

    private BlacklistSweepService $sweeps;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        ]);

        Cache::flush();
        $this->createSchema();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->withoutMiddleware([
            Google2FAMiddleware::class,
            TrustedDevice2FAMiddleware::class,
        ]);

        $this->sweepDirectory = $this->makeTempDirectory('admin-blacklist-sweeps');
        $this->sweeps = new BlacklistSweepService($this->sweepDirectory);
        app()->instance(BlacklistSweepService::class, $this->sweeps);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_sweep_routes_are_admin_and_two_factor_protected(): void
    {
        $middleware = Route::getRoutes()->getByName('admin.binaryblacklist-sweep.start')?->gatherMiddleware() ?? [];
        $this->assertContains('role:Admin', $middleware);
        $this->assertContains('2fa', $middleware);

        $this->actingAs($this->userWithRole('User'))
            ->postJson(route('admin.binaryblacklist-sweep.start'), ['mode' => 'dry-run'])
            ->assertForbidden();
    }

    public function test_admin_can_start_dry_run_poll_status_and_cannot_start_a_second_run(): void
    {
        Process::fake(fn () => Process::result(output: getmypid()."\n"));
        $admin = $this->userWithRole('Admin');

        $started = $this->actingAs($admin)
            ->postJson(route('admin.binaryblacklist-sweep.start'), ['mode' => 'dry-run'])
            ->assertAccepted()
            ->assertJsonPath('run.mode', 'dry-run')
            ->assertJsonPath('run.rule_id', null);
        $this->assertArrayNotHasKey('log_path', $started->json('run'));

        $this->actingAs($admin)
            ->postJson(route('admin.binaryblacklist-sweep.start'), ['mode' => 'delete'])
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'A blacklist sweep is already running.']);

        $internal = $this->sweeps->status();
        file_put_contents($internal['current']['log_path'], "Would be deleting: Blacklist [1]: Match\n");

        $status = $this->actingAs($admin)
            ->getJson(route('admin.binaryblacklist-sweep.status'))
            ->assertOk()
            ->assertJsonPath('running', true)
            ->assertJsonPath('current.matched_count', 1);
        $this->assertArrayNotHasKey('log_path', $status->json('current'));
    }

    public function test_admin_can_start_a_per_rule_delete_and_validation_rejects_unknown_rules(): void
    {
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $commands[] = $process->command;

            return Process::result(output: getmypid()."\n");
        });
        $admin = $this->userWithRole('Admin');
        $ruleId = DB::table('binaryblacklist')->insertGetId([
            'groupname' => 'alt.binaries.*',
            'regex' => 'example',
            'description' => 'Example rule',
            'status' => 1,
            'optype' => 1,
            'msgcol' => 1,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.binaryblacklist-sweep.start'), ['mode' => 'delete', 'rule_id' => $ruleId])
            ->assertAccepted()
            ->assertJsonPath('run.mode', 'delete')
            ->assertJsonPath('run.rule_id', $ruleId);

        $this->assertStringContainsString('--blacklist-id='.$ruleId, $commands[0]);
        $this->assertStringContainsString('--delete', $commands[0]);

        $this->sweeps->complete((string) $this->sweeps->status()['current']['id'], 0);
        $this->actingAs($admin)
            ->postJson(route('admin.binaryblacklist-sweep.start'), ['mode' => 'dry-run', 'rule_id' => 999999])
            ->assertUnprocessable();
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $id = DB::table('users')->insertGetId([
            'username' => strtolower($roleName).'-'.bin2hex(random_bytes(3)),
            'email' => bin2hex(random_bytes(3)).'@example.test',
            'password' => bcrypt('secret'),
            'roles_id' => $roleName === 'Admin' ? 2 : 1,
            'api_token' => bin2hex(random_bytes(16)),
            'verified' => true,
            'can_post' => true,
            'theme_preference' => 'light',
            'email_verified_at' => now(),
            'lastlogin' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::query()->findOrFail($id);
        $user->assignRole($role);

        return $user;
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
            $table->string('session_token')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('lastlogin')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('root_categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
        });
        Schema::create('categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->unsignedInteger('root_categories_id')->nullable();
        });
        Schema::create('user_excluded_categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('users_id');
            $table->unsignedInteger('categories_id');
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
}
