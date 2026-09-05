<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * Coverage for the music edit form (#456).
 *
 * Every payload here is what the real form posts, values included: `id`, `salesrank`, `genre`
 * and `year` arrive as strings, and a blank field arrives as null through
 * ConvertEmptyStringsToNull. A payload built from PHP integers passes against unfixed code --
 * which is how the `id` defect was missed on the first pass of #454.
 */
class AdminMusicEditTest extends TestCase
{
    use InteractsWithAdminListPages;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->createGenresTable();
        $this->createMusicInfoTable();
        DB::table('genres')->insert(['id' => 1, 'title' => 'Jazz', 'type' => 3000, 'disabled' => false]);
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /**
     * musicinfo.year is NOT NULL, and ConvertEmptyStringsToNull turns a cleared field into null.
     */
    public function test_music_edit_survives_a_blank_year(): void
    {
        $id = $this->createMusicEntry('2001-01-01 13:45:00');

        $response = $this->actingAs($this->admin())->post(route('admin.music-edit'), [
            'id' => (string) $id,
            'action' => 'submit',
            'title' => 'Album Under Edit',
            'artist' => 'An Artist',
            'publisher' => 'A Publisher',
            'year' => '',
            'tracks' => 'Track One',
            'salesrank' => '',
            'genre' => '',
            'releasedate' => '',
        ]);

        $response->assertRedirect(route('admin.music-list'));
        $this->assertSame('', (string) DB::table('musicinfo')->where('id', $id)->value('year'));
    }

    public function test_music_edit_stores_a_submitted_release_date(): void
    {
        $id = $this->createMusicEntry('2001-01-01 13:45:00');

        $response = $this->actingAs($this->admin())->post(route('admin.music-edit'), [
            'id' => (string) $id,
            'action' => 'submit',
            'title' => 'Album Under Edit',
            'artist' => 'An Artist',
            'publisher' => 'A Publisher',
            'year' => '1999',
            'tracks' => 'Track One',
            'salesrank' => '4321',
            'genre' => '1',
            'releasedate' => '2015-06-09',
        ]);

        $response->assertRedirect(route('admin.music-list'));

        $row = DB::table('musicinfo')->where('id', $id)->first();

        $this->assertSame('2015-06-09 00:00:00', Carbon::parse((string) $row->releasedate)->toDateTimeString());
        $this->assertSame('1999', (string) $row->year);
        $this->assertSame(4321, (int) $row->salesrank);
        $this->assertSame(1, (int) $row->genres_id);
    }

    public function test_music_edit_preserves_an_untouched_release_date_including_its_time(): void
    {
        $id = $this->createMusicEntry('2001-01-01 13:45:00');

        $response = $this->actingAs($this->admin())->post(route('admin.music-edit'), [
            'id' => (string) $id,
            'action' => 'submit',
            'title' => 'Album Under Edit',
            'year' => '1999',
            'salesrank' => '',
            'genre' => '',
            'releasedate' => '',
        ]);

        $response->assertRedirect(route('admin.music-list'));

        $stored = DB::table('musicinfo')->where('id', $id)->value('releasedate');

        $this->assertSame('2001-01-01 13:45:00', Carbon::parse((string) $stored)->toDateTimeString());
    }

    public function test_music_edit_clears_a_salesrank_and_genre_the_operator_blanked(): void
    {
        $id = $this->createMusicEntry('2001-01-01 13:45:00');

        $this->actingAs($this->admin())->post(route('admin.music-edit'), [
            'id' => (string) $id,
            'action' => 'submit',
            'title' => 'Album Under Edit',
            'year' => '1999',
            'salesrank' => '',
            'genre' => '',
            'releasedate' => '',
        ])->assertRedirect(route('admin.music-list'));

        $row = DB::table('musicinfo')->where('id', $id)->first();

        $this->assertNull($row->salesrank);
        $this->assertNull($row->genres_id);
    }

    /**
     * A literal "0" salesrank is the one input where the shared helper differs from the block
     * it replaced: `empty('0')` is true, so the old code stored null. nullableIntegerInput()
     * accepts it as 0, matching the console form since #454. The games form has no salesrank.
     *
     * The raw column value is asserted rather than an `(int)` cast of it, because `(int) null`
     * is also 0 -- a cast here passes against the behaviour this exists to pin.
     */
    public function test_music_edit_stores_a_zero_salesrank_the_operator_typed(): void
    {
        $id = $this->createMusicEntry('2001-01-01 13:45:00');

        $this->actingAs($this->admin())->post(route('admin.music-edit'), [
            'id' => (string) $id,
            'action' => 'submit',
            'title' => 'Album Under Edit',
            'year' => '1999',
            'salesrank' => '0',
            'genre' => '',
            'releasedate' => '',
        ])->assertRedirect(route('admin.music-list'));

        $stored = DB::table('musicinfo')->where('id', $id)->value('salesrank');

        $this->assertNotNull($stored, 'A typed 0 must be stored, not discarded as null.');
        $this->assertSame(0, (int) $stored);
    }

    private function createMusicEntry(?string $releasedate): int
    {
        return (int) DB::table('musicinfo')->insertGetId([
            'title' => 'Album Under Edit',
            'asin' => 'music-asin',
            'salesrank' => 99,
            'artist' => 'An Artist',
            'publisher' => 'A Publisher',
            'releasedate' => $releasedate,
            'year' => '2001',
            'genres_id' => 1,
            'tracks' => 'Track One',
            'cover' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
