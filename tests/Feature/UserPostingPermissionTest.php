<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class UserPostingPermissionTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        Cache::flush();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        // Pre-upgrade users projection: columns and keys from mariadb-schema.sql.
        // Only the real migration may supply can_post.
        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('username', 50);
            $table->string('email');
            $table->string('password');
            $table->integer('roles_id')->default(1);
            $table->string('api_token', 64)->unique();
            $table->integer('grabs')->default(0);
            $table->integer('invites')->default(0);
            $table->string('notes')->nullable();
            foreach (['movieview', 'xxxview', 'musicview', 'consoleview', 'bookview', 'gameview'] as $column) {
                $table->integer($column)->default(1);
            }
            $table->integer('rate_limit')->default(60);
            $table->boolean('verified')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('user_activities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('username');
            $table->string('activity_type', 50);
            $table->text('description');
            $table->json('metadata')->nullable();
            $table->boolean('is_permanent')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
            $table->integer('apirequests')->default(1000);
            $table->integer('downloadrequests')->default(100);
            $table->integer('rate_limit')->default(60);
        });
        DB::table('roles')->insert(['id' => 1, 'name' => 'User', 'guard_name' => 'web']);

        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedInteger('role_id');
            $table->string('model_type');
            $table->unsignedInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
        });
        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->unsignedInteger('permission_id');
            $table->string('model_type');
            $table->unsignedInteger('model_id');
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });
        Schema::create('categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('root_categories_id');
        });
        Schema::create('user_excluded_categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('users_id');
            $table->unsignedInteger('categories_id');
            $table->unique(['users_id', 'categories_id']);
        });
        foreach (['user_requests', 'user_downloads'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('users_id');
                $table->timestamp('timestamp')->nullable();
            });
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_migrated_users_are_refused_by_default_and_admitted_only_with_posting_permission(): void
    {
        $id = DB::table('users')->insertGetId([
            'username' => 'posting-user', 'email' => 'posting@example.test',
            'password' => 'unused', 'api_token' => 'posting-token',
            'verified' => true, 'email_verified_at' => now(),
        ]);

        $this->applyPostingMigration();

        $this->post('/api/v1/api', ['t' => 'nzbadd', 'apikey' => 'posting-token'])
            ->assertUnauthorized()
            ->assertSee('<error code="102" description="Insufficient privileges/not authorized"/>', false);
        $this->postJson('/api/v2/nzbadd', ['api_token' => 'posting-token'])
            ->assertForbidden()
            ->assertExactJson(['error' => 'Insufficient privileges/not authorized']);

        $this->assertFalse(User::findOrFail($id)->can_post);
        DB::table('users')->where('id', $id)->update(['can_post' => true]);
        Cache::flush();

        // Missing-upload validation proves both handlers passed their permission gate.
        $this->post('/api/v1/api', ['t' => 'nzbadd', 'apikey' => 'posting-token'])
            ->assertBadRequest()
            ->assertSee('<error code="200" description="Missing parameter (nzb file is required)"/>', false);
        $this->postJson('/api/v2/nzbadd', ['api_token' => 'posting-token'])
            ->assertBadRequest()
            ->assertExactJson(['error' => 'Missing parameter (nzb file is required)']);
    }

    public function test_factory_creates_a_posting_user_after_migration(): void
    {
        $this->applyPostingMigration();

        $user = User::factory()->create();

        $this->assertTrue($user->refresh()->can_post);
        $this->assertTrue(User::canPost($user->id));
    }

    public function test_reapplying_migration_preserves_existing_permissions_and_rollback_removes_column(): void
    {
        $this->applyPostingMigration();
        $user = User::factory()->create();

        $this->applyPostingMigration();

        $this->assertTrue($user->refresh()->can_post);
        (require database_path('migrations/2026_09_17_170000_add_can_post_to_users_table.php'))->down();
        $this->assertFalse(Schema::hasColumn('users', 'can_post'));
        $this->assertSame($user->username, $user->refresh()->username);
    }

    private function applyPostingMigration(): void
    {
        (require database_path('migrations/2026_09_17_170000_add_can_post_to_users_table.php'))->up();
    }
}
