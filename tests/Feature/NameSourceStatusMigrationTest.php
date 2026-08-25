<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NameSourceStatusMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('releases');
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->tinyInteger('proc_files')->default(0);
            $table->tinyInteger('proc_uid')->default(0);
        });

        DB::table('releases')->insert([
            'id' => 1,
            'proc_files' => 1,
            'proc_uid' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('releases');

        parent::tearDown();
    }

    public function test_migration_adds_independent_pending_statuses_for_existing_releases(): void
    {
        $migration = require database_path('migrations/2026_08_25_111343_add_name_source_status_columns_to_releases_table.php');
        $migration->up();

        $release = DB::table('releases')->find(1);

        $this->assertTrue(Schema::hasColumns('releases', ['proc_xxx', 'proc_media_movie']));
        $this->assertSame(0, (int) $release->proc_xxx);
        $this->assertSame(0, (int) $release->proc_media_movie);
        $this->assertSame(1, (int) $release->proc_files);
        $this->assertSame(1, (int) $release->proc_uid);

        $migration->down();

        $this->assertFalse(Schema::hasColumn('releases', 'proc_xxx'));
        $this->assertFalse(Schema::hasColumn('releases', 'proc_media_movie'));
    }
}
