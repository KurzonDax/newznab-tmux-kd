<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class BookInfoIsbnIndexesMigrationTest extends TestCase
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

        Schema::create('bookinfo', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('isbn')->nullable();
            $table->string('ean')->nullable();
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_it_adds_and_removes_isbn_indexes_idempotently(): void
    {
        $migration = require database_path('migrations/2026_08_13_133021_add_isbn_indexes_to_bookinfo_table.php');

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasIndex('bookinfo', 'ix_bookinfo_isbn'));
        $this->assertTrue(Schema::hasIndex('bookinfo', 'ix_bookinfo_ean'));

        $migration->down();

        $this->assertFalse(Schema::hasIndex('bookinfo', 'ix_bookinfo_isbn'));
        $this->assertFalse(Schema::hasIndex('bookinfo', 'ix_bookinfo_ean'));
    }
}
