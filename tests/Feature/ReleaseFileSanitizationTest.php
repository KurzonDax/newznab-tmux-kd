<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\ReleaseFile;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class ReleaseFileSanitizationTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        Schema::create('releases', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
        });
        Schema::create('release_files', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('name');
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->boolean('passworded')->default(false);
            $table->string('crc32')->default('');
            $table->primary(['releases_id', 'name']);
        });
        Schema::create('par_hashes', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('hash', 32);
            $table->primary(['releases_id', 'hash']);
        });

        DB::table('releases')->insert(['id' => 1]);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_par2_release_file_name_is_scrubbed_before_insert(): void
    {
        Search::shouldReceive('updateRelease')->once()->with(1);

        $inserted = ReleaseFile::addReleaseFiles(
            1,
            "Example\x00.Movie\xED\xBD\xBF.mkv\x7F",
            1024,
            1_788_600_000,
            0,
        );

        $storedName = DB::table('release_files')->value('name');
        $this->assertSame(1, $inserted);
        $this->assertSame('Example.Movie.mkv', $storedName);
        $this->assertTrue(mb_check_encoding($storedName, 'UTF-8'));
    }

    public function test_par2_release_file_name_with_no_printable_characters_is_not_inserted(): void
    {
        Search::shouldReceive('updateRelease')->never();

        $inserted = ReleaseFile::addReleaseFiles(1, "\x00\x1F\x7F\xED\xBD\xBF", 1024, 1_788_600_000, 0);

        $this->assertSame(0, $inserted);
        $this->assertSame(0, DB::table('release_files')->count());
    }
}
