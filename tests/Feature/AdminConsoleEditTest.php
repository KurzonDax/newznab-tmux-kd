<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * Coverage for the console edit form's release date (#454).
 *
 * The controller converted the submitted date to `Carbon::parse(...)->timestamp` -- a unix
 * integer -- for a `datetime` column, and `ConsoleService::update()` types that parameter
 * `?string`, so the save threw a TypeError before reaching the database.
 *
 * Every payload here is what the real form posts, values included: the form submits `id`,
 * `salesrank` and `genre` as strings, and each of those reached a typed parameter uncoerced.
 * A payload that sent PHP integers instead would pass while the real form still 500s.
 */
class AdminConsoleEditTest extends TestCase
{
    use InteractsWithAdminListPages;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->createGenresTable();
        $this->createConsoleInfoTable();
        ProductionTables::fromAuthority()->create('console_genres');
        DB::table('genres')->insert(['id' => 1, 'title' => 'Action', 'type' => 1, 'disabled' => false]);
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_console_edit_stores_a_submitted_release_date(): void
    {
        $id = $this->createConsoleEntry('2001-01-01 13:45:00');

        $response = $this->actingAs($this->admin())->post(route('admin.console-edit'), [
            'id' => (string) $id,
            'action' => 'submit',
            'title' => 'Console Under Edit',
            'platform' => 'PS4',
            'publisher' => 'A Publisher',
            'esrb' => 'E',
            'salesrank' => '1234',
            'genre' => '1',
            'releasedate' => '2015-06-09',
        ]);

        $response->assertRedirect(route('admin.console-list'));
        $this->assertSame('2015-06-09 00:00:00', $this->storedReleaseDate($id));
        $this->assertSame(1234, (int) DB::table('consoleinfo')->where('id', $id)->value('salesrank'));
        $this->assertSame(1, (int) DB::table('consoleinfo')->where('id', $id)->value('genres_id'));
    }

    public function test_console_edit_preserves_an_untouched_release_date_including_its_time(): void
    {
        $id = $this->createConsoleEntry('2001-01-01 13:45:00');

        $response = $this->actingAs($this->admin())->post(route('admin.console-edit'), [
            'id' => (string) $id,
            'action' => 'submit',
            'title' => 'Console Under Edit',
            'platform' => 'PS4',
            'publisher' => 'A Publisher',
            'esrb' => 'E',
            'salesrank' => '',
            'genre' => '',
            'releasedate' => '',
        ]);

        $response->assertRedirect(route('admin.console-list'));
        $this->assertSame('2001-01-01 13:45:00', $this->storedReleaseDate($id));
    }

    public function test_console_edit_clears_a_salesrank_and_genre_the_operator_blanked(): void
    {
        $id = $this->createConsoleEntry('2001-01-01 13:45:00');

        $this->actingAs($this->admin())->post(route('admin.console-edit'), [
            'id' => (string) $id,
            'action' => 'submit',
            'title' => 'Console Under Edit',
            'salesrank' => '',
            'genre' => '',
            'releasedate' => '',
        ])->assertRedirect(route('admin.console-list'));

        $row = DB::table('consoleinfo')->where('id', $id)->first();

        $this->assertNull($row->salesrank);
        $this->assertNull($row->genres_id);
    }

    /**
     * consoleinfo.title is NOT NULL and ConsoleService::update() types it `string`, so a blank
     * title arrived as null. The form's `required` is client-side only.
     */
    public function test_console_edit_rejects_a_blank_title(): void
    {
        $id = $this->createConsoleEntry('2001-01-01 13:45:00');

        $response = $this->actingAs($this->admin())->post(route('admin.console-edit'), [
            'id' => (string) $id,
            'action' => 'submit',
            'title' => '',
            'platform' => 'PS4',
            'salesrank' => '',
            'genre' => '',
            'releasedate' => '',
        ]);

        $response->assertSessionHasErrors('title');
        $this->assertSame('Console Under Edit', (string) DB::table('consoleinfo')->where('id', $id)->value('title'));
    }

    public function test_console_edit_keeps_a_stored_pegi_rating_when_only_the_title_changes(): void
    {
        $id = $this->createConsoleEntry(null);
        DB::table('consoleinfo')->where('id', $id)->update(['esrb' => 'PEGI 16']);

        $admin = $this->admin();

        $page = $this->actingAs($admin)->get(route('admin.console-edit', ['id' => $id]));

        $page->assertOk();
        $this->assertMatchesRegularExpression('/<option value="PEGI 16"\s+selected\s*>/', $page->getContent());

        $this->actingAs($admin)->post(route('admin.console-edit'), [
            'id' => (string) $id,
            'action' => 'submit',
            'title' => 'A New Title',
            'platform' => 'PS4',
            'publisher' => 'A Publisher',
            'esrb' => 'PEGI 16',
            'salesrank' => '99',
            'genre' => '1',
            'releasedate' => '',
        ])->assertRedirect(route('admin.console-list'));

        $row = DB::table('consoleinfo')->where('id', $id)->first();

        $this->assertSame('A New Title', $row->title);
        $this->assertSame('PEGI 16', $row->esrb);
    }

    public function test_console_edit_preselects_the_stored_genre(): void
    {
        $id = $this->createTwoGenreGame();

        $page = $this->actingAs($this->admin())->get(route('admin.console-edit', ['id' => $id]))->assertOk();

        $this->assertMatchesRegularExpression('/<option value="2"\s+selected\s*>\s*Shooter/', $page->getContent());
        $this->assertDoesNotMatchRegularExpression('/<option value="3"\s+selected/', $page->getContent());
    }

    public function test_console_edit_shows_the_stored_release_date(): void
    {
        $id = $this->createConsoleEntry('2015-06-09 00:00:00');

        $page = $this->actingAs($this->admin())->get(route('admin.console-edit', ['id' => $id]))->assertOk();

        $this->assertSame('2015-06-09', $this->releaseDateInputValue($page->getContent()));
    }

    public function test_console_edit_shows_an_empty_release_date_when_none_is_stored(): void
    {
        $id = $this->createConsoleEntry(null);

        $page = $this->actingAs($this->admin())->get(route('admin.console-edit', ['id' => $id]))->assertOk();

        $this->assertSame('', $this->releaseDateInputValue($page->getContent()));
    }

    public function test_console_edit_keeps_every_genre_when_the_stored_genre_is_posted(): void
    {
        $id = $this->createTwoGenreGame();

        $this->postGenre($id, '2');

        $this->assertSame([[2, 0], [3, 1]], $this->genreRows($id));
        $this->assertSame(2, (int) DB::table('consoleinfo')->where('id', $id)->value('genres_id'));
        $this->assertSame('A New Title', (string) DB::table('consoleinfo')->where('id', $id)->value('title'));
    }

    public function test_console_edit_makes_a_changed_genre_the_only_genre(): void
    {
        $id = $this->createTwoGenreGame();

        $this->postGenre($id, '4');

        $this->assertSame([[4, 0]], $this->genreRows($id));
        $this->assertSame(4, (int) DB::table('consoleinfo')->where('id', $id)->value('genres_id'));
    }

    public function test_console_edit_removes_every_genre_when_the_genre_is_cleared(): void
    {
        $id = $this->createTwoGenreGame();

        $this->postGenre($id, '');

        $this->assertSame([], $this->genreRows($id));
        $this->assertNull(DB::table('consoleinfo')->where('id', $id)->value('genres_id'));
    }

    /** A game the lookup stored with the genres Shooter (2) and Adventure (3); Puzzle (4) is unused. */
    private function createTwoGenreGame(): int
    {
        foreach ([2 => 'Shooter', 3 => 'Adventure', 4 => 'Puzzle'] as $genreId => $title) {
            DB::table('genres')->insert(['id' => $genreId, 'title' => $title, 'type' => 1000, 'disabled' => false]);
        }
        $id = $this->createConsoleEntry(null);
        DB::table('consoleinfo')->where('id', $id)->update(['genres_id' => 2]);
        DB::table('console_genres')->insert([
            ['consoleinfo_id' => $id, 'genres_id' => 2, 'position' => 0],
            ['consoleinfo_id' => $id, 'genres_id' => 3, 'position' => 1],
        ]);

        return $id;
    }

    private function postGenre(int $id, string $genre): void
    {
        $this->actingAs($this->admin())->post(route('admin.console-edit'), [
            'id' => (string) $id,
            'action' => 'submit',
            'title' => 'A New Title',
            'platform' => 'PS4',
            'publisher' => 'A Publisher',
            'esrb' => 'E',
            'salesrank' => '99',
            'genre' => $genre,
            'releasedate' => '',
        ])->assertRedirect(route('admin.console-list'));
    }

    /** @return list<array{int, int}> */
    private function genreRows(int $id): array
    {
        return DB::table('console_genres')->where('consoleinfo_id', $id)->orderBy('position')->get(['genres_id', 'position'])
            ->map(static fn (object $row): array => [(int) $row->genres_id, (int) $row->position])->all();
    }

    private function releaseDateInputValue(string $html): string
    {
        $this->assertSame(1, preg_match('/<input type="date"\s+id="releasedate"\s+name="releasedate"\s+value="([^"]*)"/', $html, $match));

        return $match[1];
    }

    private function storedReleaseDate(int $id): string
    {
        $stored = DB::table('consoleinfo')->where('id', $id)->value('releasedate');

        return $stored === null ? '' : Carbon::parse((string) $stored)->toDateTimeString();
    }

    private function createConsoleEntry(?string $releasedate): int
    {
        return (int) DB::table('consoleinfo')->insertGetId([
            'title' => 'Console Under Edit',
            'asin' => 'console-asin',
            'salesrank' => 99,
            'platform' => 'PS4',
            'publisher' => 'A Publisher',
            'genres_id' => 1,
            'esrb' => 'E',
            'releasedate' => $releasedate,
            'cover' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
