<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class FixReleaseNameQueryIndexesMigrationTest extends TestCase
{
    use IsolatedSqliteDatabase;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return ['categorizeforeign' => '0', 'catwebdl' => '1'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_it_adds_only_missing_reverse_lookup_and_queue_indexes_idempotently(): void
    {
        $migration = require database_path('migrations/2026_08_04_082439_add_fix_release_name_query_indexes.php');

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasIndex('par_hashes', 'ix_par_hashes_hash_releases_id'));
        $this->assertTrue(Schema::hasIndex('release_files', 'ix_release_files_crc32_releases_id'));
        $this->assertTrue(Schema::hasIndex('predb', 'ix_predb_searched_predate_id'));

        $migration->down();

        $this->assertFalse(Schema::hasIndex('par_hashes', 'ix_par_hashes_hash_releases_id'));
        $this->assertFalse(Schema::hasIndex('release_files', 'ix_release_files_crc32_releases_id'));
        $this->assertFalse(Schema::hasIndex('predb', 'ix_predb_searched_predate_id'));
    }

    private function createSchema(): void
    {
        Schema::create('par_hashes', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('hash', 32);
            $table->primary(['releases_id', 'hash']);
        });
        Schema::create('release_files', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('name');
            $table->string('crc32')->default('');
            $table->primary(['releases_id', 'name']);
        });
        Schema::create('predb', function (Blueprint $table): void {
            $table->increments('id');
            $table->tinyInteger('searched')->default(0)->index();
            $table->dateTime('predate')->nullable()->index();
        });
    }
}
