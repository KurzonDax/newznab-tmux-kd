<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReleaseRepairTargetMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('releases');
        Schema::dropIfExists('settings');

        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value')->nullable();
        });
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('repair_outcome', 16)->nullable();
            $table->string('rescan_outcome', 16)->nullable();
        });

        DB::table('settings')->insert(['name' => 'completionpercent', 'value' => '97']);
        DB::table('releases')->insert([
            ['id' => 1, 'repair_outcome' => 'repaired', 'rescan_outcome' => 'repaired'],
            ['id' => 2, 'repair_outcome' => 'failed', 'rescan_outcome' => 'retry-pending'],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('releases');
        Schema::dropIfExists('settings');

        parent::tearDown();
    }

    public function test_existing_repaired_outcomes_are_stamped_at_the_current_target(): void
    {
        $paths = glob(database_path('migrations/*_add_repair_target_completion_to_releases_table.php')) ?: [];

        $this->assertCount(1, $paths);

        $migration = require $paths[0];
        $this->assertInstanceOf(Migration::class, $migration);
        $migration->up();

        $repaired = DB::table('releases')->find(1);
        $unrepaired = DB::table('releases')->find(2);

        $this->assertSame(97.0, (float) $repaired->repair_target_completion);
        $this->assertSame(97.0, (float) $repaired->rescan_target_completion);
        $this->assertNull($unrepaired->repair_target_completion);
        $this->assertNull($unrepaired->rescan_target_completion);

        $migration->down();

        $this->assertFalse(Schema::hasColumn('releases', 'repair_target_completion'));
        $this->assertFalse(Schema::hasColumn('releases', 'rescan_target_completion'));
    }
}
