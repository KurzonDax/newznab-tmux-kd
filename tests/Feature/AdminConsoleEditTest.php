<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\IsolatedSqliteDatabase;
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
