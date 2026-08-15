<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UsenetGroupObfuscatedRoutingMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('usenet_groups');
        Schema::dropIfExists('root_categories');

        Schema::create('root_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });

        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('backfill_target')->default(1);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('usenet_groups');
        Schema::dropIfExists('root_categories');

        parent::tearDown();
    }

    public function test_migration_defaults_foreign_key_null_on_delete_and_rollback(): void
    {
        $migration = require database_path('migrations/2026_08_15_171915_add_obfuscated_name_routing_to_usenet_groups_table.php');
        $migration->up();

        $groupId = DB::table('usenet_groups')->insertGetId(['name' => 'alt.binaries.test']);
        $group = DB::table('usenet_groups')->where('id', $groupId)->first();

        $this->assertSame(0, $group->route_obfuscated_names);
        $this->assertNull($group->obfuscated_default_root_categories_id);

        $rootCategoryId = DB::table('root_categories')->insertGetId(['title' => 'Movies']);
        DB::table('usenet_groups')->where('id', $groupId)->update([
            'obfuscated_default_root_categories_id' => $rootCategoryId,
        ]);

        DB::table('root_categories')->where('id', $rootCategoryId)->delete();

        $this->assertNull(
            DB::table('usenet_groups')->where('id', $groupId)->value('obfuscated_default_root_categories_id')
        );

        $migration->down();

        $this->assertFalse(Schema::hasColumn('usenet_groups', 'route_obfuscated_names'));
        $this->assertFalse(Schema::hasColumn('usenet_groups', 'obfuscated_default_root_categories_id'));
    }
}
