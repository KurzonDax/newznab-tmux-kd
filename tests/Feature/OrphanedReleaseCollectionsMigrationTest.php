<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrphanedReleaseCollectionsMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();

        Schema::create('releases', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
        });
        Schema::create('collections', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('releases_id')->nullable();
        });
    }

    public function test_migration_deletes_only_collections_referencing_missing_releases(): void
    {
        DB::table('releases')->insert(['id' => 1]);
        DB::table('collections')->insert([
            ['id' => 10, 'releases_id' => 1],
            ['id' => 20, 'releases_id' => 999],
            ['id' => 30, 'releases_id' => null],
        ]);

        $migration = require database_path(
            'migrations/2026_08_25_213606_delete_collections_referencing_missing_releases.php'
        );
        $migration->up();
        $migration->up();

        $this->assertSame([10, 30], DB::table('collections')->orderBy('id')->pluck('id')->all());
    }
}
