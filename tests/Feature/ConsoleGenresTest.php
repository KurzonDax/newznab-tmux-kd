<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Http\Controllers\Api\RSS;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * A console game's genres are `console_genres` rows, one per genre in IGDB's order, and
 * `consoleinfo.genres_id` holds the first. The fill migration splits the combined titles
 * (`Shooter,Adventure`) earlier lookups stored as one genre.
 */
final class ConsoleGenresTest extends TestCase
{
    private const string FOUR_X = '4X (explore, expand, exploit, and exterminate)';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['consoleinfo', 'genres', 'console_genres'] as $table) {
            ProductionTables::fromAuthority()->create($table);
        }
    }

    public function test_the_fill_splits_a_combined_title_into_one_row_per_genre(): void
    {
        $game = $this->game($this->genre('Shooter,Adventure'));

        $this->fill();

        $this->assertSame(['Shooter', 'Adventure'], $this->titles($game));
        $this->assertSame($this->genreIds('Shooter'), [(int) DB::table('consoleinfo')->where('id', $game)->value('genres_id')]);
        $this->assertSame([], $this->genreIds('Shooter,Adventure'));
    }

    public function test_the_fill_links_games_on_duplicate_genre_rows_to_one_row(): void
    {
        $first = $this->game($this->genre('Action'));
        $second = $this->game($this->genre('Action'));

        $this->fill();

        $action = $this->genreIds('Action');
        $this->assertCount(1, $action);
        foreach ([$first, $second] as $game) {
            $this->assertSame(['Action'], $this->titles($game));
            $this->assertSame($action[0], (int) DB::table('consoleinfo')->where('id', $game)->value('genres_id'));
        }
    }

    public function test_the_fill_keeps_the_4x_theme_as_one_genre(): void
    {
        $game = $this->game($this->genre(self::FOUR_X));
        $combined = $this->game($this->genre('Strategy|'.self::FOUR_X));

        $this->fill();

        $this->assertSame([self::FOUR_X], $this->titles($game));
        $this->assertSame(['Strategy', self::FOUR_X], $this->titles($combined));
    }

    public function test_running_the_fill_again_changes_nothing(): void
    {
        $this->game($this->genre('Shooter,Adventure'));
        $this->game($this->genre('Action'));
        $this->game($this->genre('Action'));
        $this->game($this->genre(self::FOUR_X));
        $this->fill();
        $state = $this->state();

        $this->fill();

        $this->assertSame($state, $this->state());
    }

    public function test_rss_reads_every_console_genre_in_order(): void
    {
        foreach (['releases', 'categories', 'root_categories', 'usenet_groups', 'musicinfo', 'movieinfo', 'tv_episodes', 'bookinfo', 'settings'] as $table) {
            ProductionTables::fromAuthority()->create($table);
        }
        Search::spy();
        DB::table('root_categories')->insert(['id' => 1000, 'title' => 'Console']);
        DB::table('categories')->insert(['id' => 1030, 'title' => 'PS5', 'root_categories_id' => 1000]);
        $shooter = $this->genre('Shooter');
        $adventure = $this->genre('Adventure');
        $game = $this->game($shooter);
        DB::table('console_genres')->insert([
            ['consoleinfo_id' => $game, 'genres_id' => $adventure, 'position' => 1],
            ['consoleinfo_id' => $game, 'genres_id' => $shooter, 'position' => 0],
        ]);
        DB::table('releases')->insert(['id' => 1, 'guid' => 'console-release', 'searchname' => 'A.Game.PS5', 'categories_id' => 1030,
            'consoleinfo_id' => $game, 'passwordstatus' => 0, 'postdate' => '2026-10-01 00:00:00', 'adddate' => '2026-10-01 00:00:00']);

        $rows = app(RSS::class)->getRss([1030], 0, 0);

        $this->assertCount(1, $rows);
        $this->assertSame('Shooter,Adventure', $rows[0]->co_genre);
    }

    private function fill(): void
    {
        (require database_path('migrations/2026_10_01_000100_fill_console_genres.php'))->up();
    }

    private function genre(string $title): int
    {
        return (int) DB::table('genres')->insertGetId(['title' => $title, 'type' => 1000, 'disabled' => 0]);
    }

    private function game(int $genreId): int
    {
        return (int) DB::table('consoleinfo')->insertGetId(['title' => 'Game', 'genres_id' => $genreId, 'cover' => 0]);
    }

    /** @return list<string> */
    private function titles(int $game): array
    {
        return DB::table('console_genres as cg')->join('genres as g', 'g.id', '=', 'cg.genres_id')
            ->where('cg.consoleinfo_id', $game)->orderBy('cg.position')->pluck('g.title')->all();
    }

    /** @return list<int> */
    private function genreIds(string $title): array
    {
        return DB::table('genres')->where('type', 1000)->where('title', $title)->orderBy('id')->pluck('id')->map(intval(...))->all();
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function state(): array
    {
        $rows = static fn (string $table, array $order): array => DB::table($table)->orderBy($order[0])->when(isset($order[1]), static fn ($query) => $query->orderBy($order[1]))
            ->get()->map(static fn (object $row): array => (array) $row)->all();

        return [
            'genres' => $rows('genres', ['id']),
            'consoleinfo' => $rows('consoleinfo', ['id']),
            'console_genres' => $rows('console_genres', ['consoleinfo_id', 'position']),
        ];
    }
}
