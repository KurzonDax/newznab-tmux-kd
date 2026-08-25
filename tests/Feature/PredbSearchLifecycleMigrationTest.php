<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class PredbSearchLifecycleMigrationTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        Schema::create('predb', function (Blueprint $table): void {
            $table->increments('id');
            $table->tinyInteger('searched')->default(0);
            $table->dateTime('predate')->nullable();
        });
        DB::table('predb')->insert(['searched' => 0, 'predate' => '2026-08-01 00:00:00']);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_it_adds_the_pacing_timestamp_and_eligibility_index_with_safe_defaults(): void
    {
        $paths = glob(database_path('migrations/*_add_predb_search_lifecycle_to_predb_table.php'));
        $this->assertCount(1, $paths);
        $migration = require $paths[0];

        $migration->up();

        $this->assertTrue(Schema::hasColumn('predb', 'next_predb_search_at'));
        $this->assertTrue(Schema::hasIndex('predb', 'ix_predb_search_lifecycle'));
        $this->assertNull(DB::table('predb')->value('next_predb_search_at'));

        $migration->down();

        $this->assertFalse(Schema::hasIndex('predb', 'ix_predb_search_lifecycle'));
        $this->assertFalse(Schema::hasColumn('predb', 'next_predb_search_at'));
    }
}
