<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ConsoleGame;
use App\Data\ConsoleGamePageFilters;
use App\Services\MetadataProcessing\ConsoleGameDetails;
use App\Services\MetadataProcessing\ConsoleGenres;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The game's reads for the Console release page of a release with a game
 * (docs/proposals/books-console-pc-redesign/DATA-CONTRACT.md 4.3), as MovieFilmPage is for a film:
 * the game in one query, its genres, companies, game modes and player perspectives in one
 * `UNION ALL` query, and "All N releases of this game" from the game's releases on the per-game
 * index (`consoleinfo_id = ?`, the Console categories, the password setting and the viewer's
 * excluded categories).
 */
final class ConsoleGamePage
{
    /** The list kinds of the `UNION ALL` read: the companies' `console_companies.role`, then kinds no role uses. */
    private const KIND_DEVELOPER = ConsoleGameDetails::DEVELOPER;

    private const KIND_PUBLISHER = ConsoleGameDetails::PUBLISHER;

    private const KIND_GENRE = 100;

    private const KIND_MODE = 101;

    private const KIND_PERSPECTIVE = 102;

    public function __construct(private readonly ReleaseBrowseService $releases) {}

    /**
     * The game, or null for an unknown one. A game with no release the viewer may see still has
     * one (0 releases).
     *
     * @param  list<int>  $exclusions
     */
    public function game(int $id, array $exclusions): ?ConsoleGame
    {
        $game = DB::table('consoleinfo')->where('id', $id)
            ->first(['id', 'title', 'asin', 'releasedate', 'review', 'esrb', 'url', 'cover', 'storyline', 'critic_score', 'user_score', 'website']);
        if ($game === null) {
            return null;
        }
        $lists = $this->lists($id);
        $date = $game->releasedate === null ? '' : substr((string) $game->releasedate, 0, 10);
        $asin = (string) $game->asin;
        $url = trim((string) $game->url);

        return new ConsoleGame(
            id: $id,
            title: (string) $game->title,
            year: preg_match('/^\d{4}/', $date) === 1 ? substr($date, 0, 4) : '',
            releaseDate: preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : '',
            summary: trim((string) $game->review),
            storyline: trim((string) $game->storyline),
            criticScore: $game->critic_score === null ? null : (int) $game->critic_score,
            userScore: $game->user_score === null ? null : (int) $game->user_score,
            ageRating: trim((string) $game->esrb),
            // The lookup stores IGDB's game id in asin; a row left by the old Amazon lookup keeps an Amazon ASIN and URL.
            igdbUrl: ctype_digit($asin) && (int) $asin > 0 && self::isWebAddress($url) ? $url : '',
            website: self::isWebAddress(trim((string) $game->website)) ? trim((string) $game->website) : '',
            cover: (int) $game->cover === 1 ? getImageAssetUrl('console', (string) $id, null) : null,
            genres: $lists[self::KIND_GENRE],
            developers: array_values($lists[self::KIND_DEVELOPER]),
            publishers: array_values($lists[self::KIND_PUBLISHER]),
            gameModes: array_values($lists[self::KIND_MODE]),
            perspectives: array_values($lists[self::KIND_PERSPECTIVE]),
            releases: $this->count($id, $exclusions),
        );
    }

    /**
     * The game's releases the viewer may see.
     *
     * @param  list<int>  $exclusions
     */
    public function count(int $id, array $exclusions): int
    {
        return $this->visible($id, $exclusions)->count();
    }

    /**
     * The ids of the requested page in the table's order.
     *
     * @param  list<int>  $exclusions
     * @return list<int>
     */
    public function pageIds(int $id, ConsoleGamePageFilters $filters, array $exclusions): array
    {
        return $this->ordered($id, $filters, $exclusions)->offset(($filters->page - 1) * ConsoleGamePageFilters::PER_PAGE)
            ->limit(ConsoleGamePageFilters::PER_PAGE)->pluck('id')->map(static fn (mixed $release): int => (int) $release)->all();
    }

    /**
     * The page of the table, in its order, that holds a release: where "All N releases of this
     * game" opens; null when the viewer may not see it.
     *
     * @param  list<int>  $exclusions
     */
    public function pageHolding(int $id, int $releaseId, ConsoleGamePageFilters $filters, array $exclusions): ?int
    {
        $rank = $this->ordered($id, $filters, $exclusions)->pluck('id')->search(static fn (mixed $release): bool => (int) $release === $releaseId);

        return $rank === false ? null : intdiv((int) $rank, ConsoleGamePageFilters::PER_PAGE) + 1;
    }

    /** Whether a stored outside link is a web address (http or https): only those become IGDB and Website buttons. */
    private static function isWebAddress(string $url): bool
    {
        return preg_match('#^https?://#i', $url) === 1;
    }

    /**
     * The game's genres, developers, publishers, game modes and player perspectives in one
     * `UNION ALL` query, each part tagged with its kind and ordered by its position. The Unknown
     * genre (stored when IGDB lists none) is left out.
     *
     * @return array<int, array<int, string>> kind => (item id => name), in position order
     */
    private function lists(int $id): array
    {
        $part = static fn (string $links, string $names, string $key, string $kind): Builder => DB::table($links.' as l')
            ->join($names.' as n', 'n.id', '=', 'l.'.$key)->where('l.consoleinfo_id', $id)
            ->selectRaw($kind.' AS kind, l.position AS position, n.id AS item_id, n.name AS name');
        $genres = DB::table('console_genres as l')->join('genres as n', 'n.id', '=', 'l.genres_id')->where('l.consoleinfo_id', $id)
            ->where('n.title', '<>', ConsoleGenres::UNKNOWN)
            ->selectRaw(self::KIND_GENRE.' AS kind, l.position AS position, n.id AS item_id, n.title AS name');
        $rows = $genres
            ->unionAll($part('console_companies', 'companies', 'companies_id', 'l.role')->whereIn('l.role', [self::KIND_DEVELOPER, self::KIND_PUBLISHER]))
            ->unionAll($part('console_game_modes', 'game_modes', 'game_modes_id', (string) self::KIND_MODE))
            ->unionAll($part('console_player_perspectives', 'player_perspectives', 'player_perspectives_id', (string) self::KIND_PERSPECTIVE))
            ->orderBy('kind')->orderBy('position')->get();
        $lists = array_fill_keys([self::KIND_DEVELOPER, self::KIND_PUBLISHER, self::KIND_GENRE, self::KIND_MODE, self::KIND_PERSPECTIVE], []);
        foreach ($rows as $row) {
            $lists[(int) $row->kind][(int) $row->item_id] = (string) $row->name;
        }

        return $lists;
    }

    /**
     * The table's order: the sorted column, then newest posted first, then the higher id. Category
     * orders by the sub-category's place in the Console list's Category menu; one the order does
     * not list (an admin's own) comes after the last listed one.
     *
     * @param  list<int>  $exclusions
     */
    private function ordered(int $id, ConsoleGamePageFilters $filters, array $exclusions): Builder
    {
        $direction = $filters->ascending ? 'asc' : 'desc';
        $query = $this->visible($id, $exclusions);
        match ($filters->sort) {
            'category' => $query->orderByRaw(self::categoryOrder().' '.$direction)->orderByDesc('postdate'),
            'size' => $query->orderBy('size', $direction)->orderByDesc('postdate'),
            default => $query->orderBy('postdate', $direction),
        };

        return $query->orderByDesc('id');
    }

    /** `CASE categories_id WHEN <id> THEN <position> … ELSE <the order's length> END` over ConsoleReleaseList::CATEGORY_ORDER. */
    private static function categoryOrder(): string
    {
        $order = ConsoleReleaseList::CATEGORY_ORDER;
        $when = implode(' ', array_map(static fn (int $category, int $position): string => 'WHEN '.$category.' THEN '.$position, $order, array_keys($order)));

        return 'CASE categories_id '.$when.' ELSE '.count($order).' END';
    }

    /** @param list<int> $exclusions */
    private function visible(int $id, array $exclusions): Builder
    {
        $query = DB::table('releases');
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $query->forceIndex(ConsoleReleaseList::GAME_INDEX);
        }
        $query->where('consoleinfo_id', $id)->whereBetween('categories_id', ConsoleReleaseList::BAND_CATEGORIES)
            ->whereRaw('passwordstatus '.$this->releases->showPasswords());
        if ($exclusions !== []) {
            $query->whereNotIn('categories_id', $exclusions);
        }

        return $query;
    }
}
