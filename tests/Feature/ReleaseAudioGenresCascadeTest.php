<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * A deleted release or audio genre takes its release_audio_genres rows with it (the SQLite
 * testing connection enforces foreign keys), and the storage migration's down() removes what
 * its up() added.
 */
final class ReleaseAudioGenresCascadeTest extends TestCase
{
    private const string MIGRATION = 'migrations/2026_10_04_000000_add_release_audio_genres.php';

    protected function setUp(): void
    {
        parent::setUp();
        ProductionTables::fromAuthority()->create('releases', ['id', 'categories_id']);
        ProductionTables::fromAuthority()->create('release_audio_tags');
        (require database_path(self::MIGRATION))->up();
    }

    public function test_deleting_a_release_leaves_none_of_its_rows_and_keeps_the_names(): void
    {
        $this->seedLinks();

        DB::table('releases')->where('id', 1)->delete();

        $this->assertSame(0, DB::table('release_audio_genres')->where('releases_id', 1)->count());
        $this->assertSame(1, DB::table('release_audio_genres')->where('releases_id', 2)->count());
        $this->assertSame(2, DB::table('audio_genres')->count());
    }

    public function test_deleting_an_audio_genre_removes_the_links_to_it(): void
    {
        $this->seedLinks();

        DB::table('audio_genres')->where('id', 1)->delete();

        $this->assertSame([[1, 2]], DB::table('release_audio_genres')->get()
            ->map(static fn (object $row): array => [(int) $row->releases_id, (int) $row->audio_genres_id])->all());
    }

    public function test_down_drops_the_tables_and_the_year_index(): void
    {
        $this->assertTrue(Schema::hasIndex('release_audio_tags', 'ix_release_audio_tags_recorded_year'));

        (require database_path(self::MIGRATION))->down();

        $this->assertFalse(Schema::hasTable('release_audio_genres'));
        $this->assertFalse(Schema::hasTable('audio_genres'));
        $this->assertFalse(Schema::hasIndex('release_audio_tags', 'ix_release_audio_tags_recorded_year'));
    }

    private function seedLinks(): void
    {
        DB::table('releases')->insert([['id' => 1, 'categories_id' => 3040], ['id' => 2, 'categories_id' => 3010]]);
        DB::table('audio_genres')->insert([['id' => 1, 'name' => 'Rock'], ['id' => 2, 'name' => 'Pop']]);
        DB::table('release_audio_genres')->insert([
            ['releases_id' => 1, 'audio_genres_id' => 1, 'position' => 0],
            ['releases_id' => 1, 'audio_genres_id' => 2, 'position' => 1],
            ['releases_id' => 2, 'audio_genres_id' => 1, 'position' => 0],
        ]);
    }
}
