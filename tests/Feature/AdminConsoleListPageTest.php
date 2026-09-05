<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * Coverage for the admin console list (#453).
 *
 * The release-date cell passed the raw column value to `date()`. `AdminConsoleController::index`
 * lists through the global `getRange('consoleinfo')` helper, which uses `DB::table()`, so the row
 * is a plain `stdClass` and `releasedate` is the `datetime` string straight from the column --
 * where `date()` requires an `?int`. One such row took the whole page down.
 *
 * The cells are asserted by column position rather than with `assertSee()`: #449 found that a
 * wrongly rendered cell can still contain the text a bare `assertSee()` looks for.
 */
class AdminConsoleListPageTest extends TestCase
{
    use InteractsWithAdminListPages;
    use IsolatedSqliteDatabase;

    /**
     * Column positions in a console row: cover, title, platform, publisher, release date.
     */
    private const RELEASE_DATE_COLUMN_INDEX = 4;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->createConsoleInfoTable();
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_console_list_renders_a_row_carrying_a_release_date(): void
    {
        $this->createConsoleEntries([
            ['title' => 'Dated Console Game', 'releasedate' => '2015-06-09 00:00:00'],
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.console-list'));

        $response->assertOk();
        $this->assertSame(
            '2015-06-09',
            $this->cellFor((string) $response->getContent(), 'Dated Console Game', self::RELEASE_DATE_COLUMN_INDEX)
        );
    }

    public function test_console_list_renders_a_placeholder_for_a_row_without_a_release_date(): void
    {
        $this->createConsoleEntries([
            ['title' => 'Undated Console Game', 'releasedate' => null],
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.console-list'));

        $response->assertOk();
        $this->assertSame(
            '—',
            $this->cellFor((string) $response->getContent(), 'Undated Console Game', self::RELEASE_DATE_COLUMN_INDEX)
        );
    }

    public function test_console_list_still_paginates_newest_first(): void
    {
        $this->createConsoleEntries([
            ['title' => 'Oldest Console Game', 'releasedate' => '2001-01-01 00:00:00'],
            ['title' => 'Middle Console Game', 'releasedate' => '2002-02-02 00:00:00'],
            ['title' => 'Newest Console Game', 'releasedate' => '2003-03-03 00:00:00'],
        ]);
        $admin = $this->admin();

        $firstPage = $this->actingAs($admin)->get(route('admin.console-list'));

        $firstPage->assertOk();
        $firstPage->assertSee('Newest Console Game');
        $firstPage->assertDontSee('Oldest Console Game');

        $secondPage = $this->actingAs($admin)->get(route('admin.console-list', ['page' => 2]));

        $secondPage->assertOk();
        $secondPage->assertSee('Oldest Console Game');
    }

    public function test_console_list_renders_its_empty_state(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.console-list'));

        $response->assertOk();
        $response->assertSee('No console games available');
    }

    /**
     * Rows are created oldest first; the listing orders newest first.
     *
     * @param  list<array{title: string, releasedate: string|null}>  $entries
     */
    private function createConsoleEntries(array $entries): void
    {
        $offset = DB::table('consoleinfo')->count();
        $rows = [];

        foreach (array_values($entries) as $index => $entry) {
            $rows[] = [
                'title' => $entry['title'],
                'asin' => 'asin-'.($offset + $index),
                'platform' => 'PS4',
                'publisher' => 'Publisher '.($offset + $index),
                'genres_id' => null,
                'esrb' => 'E',
                'releasedate' => $entry['releasedate'],
                'cover' => 0,
                'created_at' => now()->addMinutes($offset + $index),
                'updated_at' => now()->addMinutes($offset + $index),
            ];
        }

        DB::table('consoleinfo')->insert($rows);
    }
}
