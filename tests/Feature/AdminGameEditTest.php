<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * Coverage for the game edit form's release date (#454).
 *
 * The controller converted the submitted date to `Carbon::parse(...)->timestamp` -- a unix
 * integer -- for a `datetime` column. `GamesService::update()` typed the parameter `mixed`, so
 * unlike the console form it reached the database, where a strict-mode server rejects it.
 *
 * Every payload here is what the real form posts, values included: `id` and `genre` arrive as
 * strings, and a blank `trailerurl` arrives as null through ConvertEmptyStringsToNull against a
 * NOT NULL column. Each of those independently returned a 500.
 */
class AdminGameEditTest extends TestCase
{
    use InteractsWithAdminListPages;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->createGenresTable();
        $this->createGamesInfoTable();
        DB::table('genres')->insert(['id' => 1, 'title' => 'Action', 'type' => 1, 'disabled' => false]);
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_game_edit_stores_a_submitted_release_date(): void
    {
        $id = $this->createGameEntry('2001-01-01 13:45:00');

        $response = $this->actingAs($this->admin())->post(route('admin.game-edit'), [
            'id' => (string) $id,
            'action' => 'submit',
            'title' => 'Game Under Edit',
            'publisher' => 'A Publisher',
            'esrb' => 'Everyone',
            'genre' => '1',
            'trailerurl' => '',
            'releasedate' => '2015-06-09',
        ]);

        $response->assertRedirect(route('admin.game-list'));
        $this->assertSame('2015-06-09 00:00:00', $this->storedReleaseDate($id));
        $this->assertSame(1, (int) DB::table('gamesinfo')->where('id', $id)->value('genres_id'));
    }

    /**
     * GamesInfo casts releasedate to a date, which truncates the time on read. Writing the
     * stored value back through the model would rewrite 13:45:00 to 00:00:00 on a submission
     * that never touched the field.
     */
    public function test_game_edit_preserves_an_untouched_release_date_including_its_time(): void
    {
        $id = $this->createGameEntry('2001-01-01 13:45:00');

        $response = $this->actingAs($this->admin())->post(route('admin.game-edit'), [
            'id' => (string) $id,
            'action' => 'submit',
            'title' => 'Game Under Edit',
            'publisher' => 'A Publisher',
            'esrb' => 'Everyone',
            'genre' => '',
            'trailerurl' => '',
            'releasedate' => '',
        ]);

        $response->assertRedirect(route('admin.game-list'));
        $this->assertSame('2001-01-01 13:45:00', $this->storedReleaseDate($id));
    }

    public function test_game_edit_stores_an_empty_trailer_url_rather_than_null(): void
    {
        $id = $this->createGameEntry('2001-01-01 13:45:00');

        $this->actingAs($this->admin())->post(route('admin.game-edit'), [
            'id' => (string) $id,
            'action' => 'submit',
            'title' => 'Game Under Edit',
            'genre' => '',
            'trailerurl' => '',
            'releasedate' => '',
        ])->assertRedirect(route('admin.game-list'));

        $this->assertSame('', (string) DB::table('gamesinfo')->where('id', $id)->value('trailer'));
    }

    /**
     * gamesinfo.title is NOT NULL and GamesService::update() types it `string`.
     */
    public function test_game_edit_rejects_a_blank_title(): void
    {
        $id = $this->createGameEntry('2001-01-01 13:45:00');

        $response = $this->actingAs($this->admin())->post(route('admin.game-edit'), [
            'id' => (string) $id,
            'action' => 'submit',
            'title' => '',
            'genre' => '',
            'trailerurl' => '',
            'releasedate' => '',
        ]);

        $response->assertSessionHasErrors('title');
        $this->assertSame('Game Under Edit', (string) DB::table('gamesinfo')->where('id', $id)->value('title'));
    }

    private function storedReleaseDate(int $id): string
    {
        $stored = DB::table('gamesinfo')->where('id', $id)->value('releasedate');

        return $stored === null ? '' : Carbon::parse((string) $stored)->toDateTimeString();
    }

    private function createGameEntry(?string $releasedate): int
    {
        return (int) DB::table('gamesinfo')->insertGetId([
            'title' => 'Game Under Edit',
            'asin' => 'game-asin',
            'publisher' => 'A Publisher',
            'genres_id' => 1,
            'esrb' => 'Everyone',
            'releasedate' => $releasedate,
            'cover' => 0,
            'trailer' => 'https://example.test/trailer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
