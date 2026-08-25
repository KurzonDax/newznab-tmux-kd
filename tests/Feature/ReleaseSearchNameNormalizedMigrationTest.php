<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ReleaseSearchNameNormalizedMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('releases');
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('searchname');
            $table->unsignedBigInteger('size')->default(0);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('releases');

        parent::tearDown();
    }

    public function test_migration_backfills_extended_normalized_identity_and_index(): void
    {
        DB::table('releases')->insert([
            ['id' => 1, 'searchname' => '[10/88] "ReleaseName.part009.rar" yEnc', 'size' => 1000],
            ['id' => 2, 'searchname' => 'Already.Clean', 'size' => 2000],
        ]);

        $migration = require database_path('migrations/2026_08_25_171914_add_searchname_normalized_to_releases_table.php');
        $migration->up();

        $this->assertSame(
            ['ReleaseName', 'Already.Clean'],
            DB::table('releases')->orderBy('id')->pluck('searchname_normalized')->all(),
        );

        $indexes = collect(Schema::getIndexes('releases'))->keyBy('name');
        $this->assertTrue($indexes->has('ix_releases_searchname_normalized_size'));
        $this->assertSame(
            ['searchname_normalized', 'size'],
            $indexes['ix_releases_searchname_normalized_size']['columns'],
        );
    }
}
