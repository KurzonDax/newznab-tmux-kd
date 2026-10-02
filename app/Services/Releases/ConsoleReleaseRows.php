<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ConsoleReleaseRow;
use App\Services\MetadataProcessing\ConsoleGenres;
use Illuminate\Support\Facades\DB;

/**
 * Loads a page of Console release ids into display rows: the shelf row (ShelfReleaseRows) plus the
 * release's game, read by `releases.consoleinfo_id` for the whole page in one query
 * (docs/proposals/books-console-pc-redesign/DATA-CONTRACT.md 4.2): its name, the year it came out,
 * its cover and its genre titles in `console_genres.position` order joined with ", " in SQL (a
 * genre name may hold commas, ConsoleGenres::FOUR_X, so the joined text is never split).
 */
final class ConsoleReleaseRows
{
    public function __construct(private readonly ShelfReleaseRows $shelf) {}

    /**
     * @param  list<int>  $ids  in display order
     * @return list<ConsoleReleaseRow>
     */
    public function load(array $ids, bool $byAdded): array
    {
        $rows = $this->shelf->rowArguments($ids, $byAdded, ['consoleinfo_id']);
        $gameIds = array_values(array_unique(array_filter(array_map(static fn (array $row): int => (int) $row['release']->consoleinfo_id, $rows),
            static fn (int $id): bool => $id > 0)));
        $games = $gameIds === [] ? collect() : DB::table('consoleinfo as c')->whereIn('c.id', $gameIds)
            ->select(['c.id', 'c.title', 'c.releasedate', 'c.cover'])->selectRaw(ConsoleGenres::titlesSql('c.id', ', ').' AS genres')->get()->keyBy('id');

        return array_map(static function (array $row) use ($games): ConsoleReleaseRow {
            $game = $games->get((int) $row['release']->consoleinfo_id);

            return new ConsoleReleaseRow(...[...$row['facts'], ...($game === null ? [] : [
                'gameId' => (int) $game->id,
                'gameTitle' => (string) $game->title,
                'gameYear' => $game->releasedate === null ? '' : substr((string) $game->releasedate, 0, 4),
                'cover' => (int) $game->cover === 1 ? getImageAssetUrl('console', (string) $game->id) : null,
                'genres' => (string) $game->genres,
            ])]);
        }, $rows);
    }
}
