<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * Coverage for the admin games list (#449).
 *
 * The list paginates in `GamesService::getRange()` but rendered no paginator, capping it at
 * the first page. The search backend is never reached here: Manticore is not available in the
 * test suite, so `Search::isAvailable()` is faked false and the `LIKE` fallback is exercised.
 */
class AdminGameListPageTest extends TestCase
{
    use InteractsWithAdminListPages;
    use IsolatedSqliteDatabase;

    /**
     * Column positions in a games row: id, title, publisher, genre, esrb, release date.
     */
    private const GENRE_COLUMN_INDEX = 3;

    private const RELEASE_DATE_COLUMN_INDEX = 5;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->createGameSchema();
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_game_list_renders_one_paginator_reaching_the_second_page(): void
    {
        $this->createGames(['First Game', 'Second Game', 'Third Game']);

        $response = $this->actingAs($this->admin())->get(route('admin.game-list'));

        $response->assertOk();
        $this->assertSame(
            1,
            substr_count((string) $response->getContent(), 'aria-label="Pagination Navigation"'),
            'The games list must render exactly one paginator.'
        );
        $response->assertSee('page=2', false);
    }

    public function test_game_list_second_page_shows_the_rows_the_first_page_did_not(): void
    {
        $this->createGames(['First Game', 'Second Game', 'Third Game']);
        $admin = $this->admin();

        $firstPage = $this->actingAs($admin)->get(route('admin.game-list'));
        $firstPage->assertOk();
        $firstPage->assertDontSee('First Game');

        $secondPage = $this->actingAs($admin)->get(route('admin.game-list', ['page' => 2]));

        $secondPage->assertOk();
        $secondPage->assertSee('First Game');
        $secondPage->assertDontSee('Third Game');
    }

    public function test_game_list_pagination_keeps_the_active_search(): void
    {
        Search::shouldReceive('isAvailable')->andReturnFalse();

        $this->createGames(['Match One', 'Match Two', 'Match Three', 'Other Title']);
        $admin = $this->admin();

        $firstPage = $this->actingAs($admin)->get(route('admin.game-list', ['gamesearch' => 'Match']));

        $firstPage->assertOk();
        $firstPage->assertSee('gamesearch=Match', false);
        $firstPage->assertSee('page=2', false);
        $firstPage->assertDontSee('Other Title');

        $secondPage = $this->actingAs($admin)
            ->get(route('admin.game-list', ['gamesearch' => 'Match', 'page' => 2]));

        $secondPage->assertOk();
        $secondPage->assertSee('Match One');
        $secondPage->assertDontSee('Other Title');
    }

    public function test_game_list_renders_no_paginator_for_a_single_page(): void
    {
        $this->createGames(['Only Game']);

        $response = $this->actingAs($this->admin())->get(route('admin.game-list'));

        $response->assertOk();
        $response->assertSee('Only Game');
        $response->assertDontSee('aria-label="Pagination Navigation"', false);
    }

    /**
     * Asserted through the cells rather than the whole page: `$game['genre']` resolves the
     * genre *relation*, and a model echoes as JSON -- which happens to contain the genre title,
     * so a bare assertSee() would pass on the very output this guards against.
     */
    public function test_game_list_renders_a_row_carrying_a_release_date_and_a_genre(): void
    {
        $this->createGames(['Only Game']);
        $seededDate = Carbon::parse(
            (string) DB::table('gamesinfo')->where('title', 'Only Game')->value('releasedate')
        )->format('Y-m-d');

        $response = $this->actingAs($this->admin())->get(route('admin.game-list'));

        $response->assertOk();
        $content = (string) $response->getContent();

        $this->assertSame('Action', $this->cellFor($content, 'Only Game', self::GENRE_COLUMN_INDEX));
        $this->assertSame($seededDate, $this->cellFor($content, 'Only Game', self::RELEASE_DATE_COLUMN_INDEX));
    }

    /**
     * The rendered contents of one cell in the row for a given game.
     */
    private function cellFor(string $html, string $title, int $columnIndex): string
    {
        $rowPattern = '/<tr[^>]*>(?:(?!<\/tr>).)*'.preg_quote($title, '/').'.*?<\/tr>/s';

        $this->assertMatchesRegularExpression($rowPattern, $html, 'No row was rendered for '.$title.'.');

        preg_match($rowPattern, $html, $row);
        preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $row[0], $cells);

        return trim(strip_tags($cells[1][$columnIndex] ?? ''));
    }

    public function test_game_list_renders_the_empty_state_without_a_paginator(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.game-list'));

        $response->assertOk();
        $response->assertSee('No games found');
        $response->assertDontSee('aria-label="Pagination Navigation"', false);
    }

    /**
     * Newest first, so the rows are created oldest first to make page order predictable.
     *
     * @param  list<string>  $titles
     */
    private function createGames(array $titles): void
    {
        $offset = DB::table('gamesinfo')->count();
        $rows = [];

        foreach (array_values($titles) as $index => $title) {
            $rows[] = [
                'title' => $title,
                'asin' => 'asin-'.($offset + $index),
                'publisher' => 'Publisher '.($offset + $index),
                'genres_id' => 1,
                'esrb' => 'Everyone',
                'releasedate' => now()->subDays(10)->format('Y-m-d H:i:s'),
                'cover' => 1,
                'created_at' => now()->addMinutes($offset + $index),
                'updated_at' => now()->addMinutes($offset + $index),
            ];
        }

        DB::table('gamesinfo')->insert($rows);
    }

    private function createGameSchema(): void
    {
        Schema::create('genres', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->integer('type')->nullable();
            $table->boolean('disabled')->default(false);
        });

        Schema::create('gamesinfo', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->string('asin', 128)->nullable();
            $table->string('url', 1000)->nullable();
            $table->string('publisher')->nullable();
            $table->integer('genres_id')->nullable();
            $table->string('esrb')->nullable();
            $table->dateTime('releasedate')->nullable();
            $table->string('review', 3000)->nullable();
            $table->boolean('cover')->default(false);
            $table->boolean('backdrop')->default(false);
            $table->string('trailer', 1000)->default('');
            $table->string('classused', 10)->default('steam');
            $table->timestamps();
        });

        DB::table('genres')->insert([
            'id' => 1,
            'title' => 'Action',
            'type' => 1,
            'disabled' => false,
        ]);
    }
}
