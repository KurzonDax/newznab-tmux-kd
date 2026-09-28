<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/** The storage migration adds the film link tables and three columns, and down() removes what it added. */
final class MovieCreditsStorageMigrationTest extends TestCase
{
    private const array NEW_MOVIEINFO_COLUMNS = ['vote_count', 'content_rating_us', 'original_language'];

    protected function setUp(): void
    {
        parent::setUp();
        $tables = ProductionTables::fromAuthority();
        $tables->create('movieinfo', ['id', 'imdbid', 'title', 'year', 'genre', 'director', 'actors']);
        $tables->create('genres');
        $tables->create('people');
        $tables->create('video_people');
    }

    public function test_up_adds_the_storage_and_down_removes_it_with_the_rows_only_films_named(): void
    {
        $migration = require database_path('migrations/2026_09_27_100000_add_movie_genres_and_people.php');

        $migration->up();

        $this->assertTrue(Schema::hasColumns('movieinfo', self::NEW_MOVIEINFO_COLUMNS));
        $this->assertSame(['movieinfo_id', 'genres_id', 'position'], Schema::getColumnListing('movie_genres'));
        $this->assertSame(['movieinfo_id', 'people_id', 'role', 'position'], Schema::getColumnListing('movie_people'));
        $this->assertContains('ix_movieinfo_year', array_column(Schema::getIndexes('movieinfo'), 'name'));
        $this->assertContains('ix_people_name', array_column(Schema::getIndexes('people'), 'name'));

        DB::table('movieinfo')->insert(['id' => 1, 'imdbid' => '0137523']);
        $this->assertNull(DB::table('movieinfo')->value('vote_count'));
        $this->assertSame('', DB::table('movieinfo')->value('content_rating_us'));
        $this->assertSame('', DB::table('movieinfo')->value('original_language'));
        DB::table('genres')->insert([
            ['id' => 1, 'title' => 'Drama', 'type' => Category::MOVIE_ROOT, 'disabled' => 0],
            ['id' => 2, 'title' => 'Drama', 'type' => Category::TV_ROOT, 'disabled' => 0],
        ]);
        DB::table('people')->insert([
            ['id' => 1, 'name' => 'From Film Text', 'tmdb_id' => null],
            ['id' => 2, 'name' => 'From TMDB', 'tmdb_id' => 287],
            ['id' => 3, 'name' => 'On A Show Too', 'tmdb_id' => null],
        ]);
        DB::table('video_people')->insert(['videos_id' => 9, 'people_id' => 3, 'position' => 0]);
        DB::table('movie_genres')->insert(['movieinfo_id' => 1, 'genres_id' => 1, 'position' => 0]);
        DB::table('movie_people')->insert([
            ['movieinfo_id' => 1, 'people_id' => 1, 'role' => 0, 'position' => 0],
            ['movieinfo_id' => 1, 'people_id' => 2, 'role' => 1, 'position' => 0],
            ['movieinfo_id' => 1, 'people_id' => 3, 'role' => 1, 'position' => 1],
        ]);

        $migration->down();

        $this->assertFalse(Schema::hasTable('movie_genres'));
        $this->assertFalse(Schema::hasTable('movie_people'));
        foreach (self::NEW_MOVIEINFO_COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('movieinfo', $column));
        }
        $this->assertNotContains('ix_movieinfo_year', array_column(Schema::getIndexes('movieinfo'), 'name'));
        $this->assertNotContains('ix_people_name', array_column(Schema::getIndexes('people'), 'name'));
        $this->assertSame([2], DB::table('genres')->pluck('id')->map(intval(...))->all());
        $this->assertSame([2, 3], DB::table('people')->orderBy('id')->pluck('id')->map(intval(...))->all());
    }
}
