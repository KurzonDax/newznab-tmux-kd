<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ConsoleReleaseFilters;
use App\Data\ReleaseListFilters;
use App\Models\Category;
use App\Models\Genre;
use App\Services\ConsoleService;
use App\Services\MetadataProcessing\ConsoleGenres;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The Console releases list's queries (ShelfReleaseList, docs/proposals/books-console-pc-redesign/
 * DATA-CONTRACT.md 4.2): band 1000. With Genre or Year set, the game drives the read: a derived
 * table `g` of the matching game ids joined in that order (STRAIGHT_JOIN) to their releases on the
 * per-game index, the band as `categories_id BETWEEN 1000 AND 1999`, the release filters and the
 * name search as conditions; the same cost on every page, so never mirrored. Unknown without a
 * Year adds the releases with no game, read as a second part on the same index and merged.
 * Otherwise the shelf list's release index.
 *
 * @extends ShelfReleaseList<ConsoleReleaseFilters>
 */
final class ConsoleReleaseList extends ShelfReleaseList
{
    /** The per-game index the game menus read releases from. */
    public const string GAME_INDEX = 'ix_releases_consoleinfo_cat';

    /** The band as the per-game index holds it (it has no category_band): the Console categories_id range. */
    public const array BAND_CATEGORIES = [Category::GAME_ROOT, Category::GAME_ROOT + 999];

    /** The Category menu's order (SPEC 5.2): ascending id, Other last. */
    public const CATEGORY_ORDER = [Category::GAME_NDS, Category::GAME_PSP, Category::GAME_WII, Category::GAME_XBOX, Category::GAME_XBOX360,
        Category::GAME_WIIWARE, Category::GAME_XBOX360DLC, Category::GAME_PS3, Category::GAME_3DS, Category::GAME_PSVITA, Category::GAME_WIIU,
        Category::GAME_XBOXONE, Category::GAME_PS4, Category::GAME_OTHER];

    /** The Unknown genre's id once read: false until then, null when there is none. */
    private int|false|null $unknownGenre = false;

    protected function band(): int
    {
        return Category::GAME_ROOT;
    }

    protected function cachePrefix(): string
    {
        return 'console_releases';
    }

    /** @param ConsoleReleaseFilters $filters */
    protected function countIndex(ReleaseListFilters $filters): string
    {
        return $filters->anyGame() ? self::GAME_INDEX : parent::countIndex($filters);
    }

    /**
     * @param  ConsoleReleaseFilters  $filters
     * @param  list<int>  $exclusions
     */
    public function readIndex(ReleaseListFilters $filters, array $exclusions): string
    {
        return $filters->anyGame() ? self::GAME_INDEX : parent::readIndex($filters, $exclusions);
    }

    /**
     * Unknown without a Year: each part ordered by the sort and cut at the page's end, the two
     * merged, ordered again and sliced (DATA-CONTRACT 4.2; without the per-part limit the read
     * covers every release with no game).
     *
     * @param  ConsoleReleaseFilters  $filters
     * @param  list<int>  $exclusions
     * @return list<int>
     */
    public function pageIds(ReleaseListFilters $filters, array $exclusions, int $total): array
    {
        if (! $this->inTwoParts($filters)) {
            return parent::pageIds($filters, $exclusions, $total);
        }
        $offset = ($filters->page - 1) * ReleaseListFilters::PER_PAGE;
        $limit = min(ReleaseListFilters::PER_PAGE, $total - $offset);
        if ($limit <= 0) {
            return [];
        }
        $date = $filters->sortsByAdded() ? 'releases.adddate' : 'releases.postdate';
        $direction = $filters->ascending() ? 'asc' : 'desc';
        $part = static fn (Builder $query): Builder => $query->select(['releases.id', $date.' as sort_date'])
            ->orderBy($date, $direction)->orderBy('releases.id', $direction)->limit($offset + $limit);

        return DB::query()->fromSub($part($this->noGameReleases($filters, $exclusions))->unionAll($part($this->gameReleases($filters, $exclusions))), 'parts')
            ->orderBy('parts.sort_date', $direction)->orderBy('parts.id', $direction)->offset($offset)->limit($limit)->pluck('parts.id')
            ->map(static fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * The Genre menu (SPEC 5.8), the same for every user and kept for an hour: the type-1000
     * genres that have a game, A to Z (ties by id), the Unknown genre left out; then Unknown when
     * a band release has no game or a game has no genre (or only the Unknown genre).
     *
     * @return array<int|string, string> URL value (genres.id, or ConsoleReleaseFilters::GENRE_UNKNOWN) => name
     */
    public function genreMenu(): array
    {
        return Cache::remember($this->cachePrefix().'_genre_menu', self::MENU_SECONDS, function (): array {
            $unknown = $this->unknownGenreId();
            $menu = DB::table('genres as g')->where('g.type', Category::GAME_ROOT)
                ->when($unknown !== null, static fn (Builder $genres) => $genres->where('g.id', '<>', $unknown))
                ->whereExists(static fn (Builder $games) => $games->selectRaw('1')->from('console_genres as cg')->whereColumn('cg.genres_id', 'g.id'))
                ->orderBy('g.title')->orderBy('g.id')->get(['g.id', 'g.title'])
                ->mapWithKeys(static fn (object $genre): array => [(int) $genre->id => (string) $genre->title])->all();
            if ($this->noGameQuery()->exists() || $this->unknownGames()->exists()) {
                $menu[ConsoleReleaseFilters::GENRE_UNKNOWN] = 'Unknown';
            }

            return $menu;
        });
    }

    /**
     * The Genre menu with the ticked genres it does not list yet (it lags new genres by up to an
     * hour), in A to Z order before Unknown, so the cell names what filters the list.
     *
     * @param  array<int|string, string>  $menu  genreMenu()
     * @param  list<int|string>  $ticked  ConsoleReleaseFilters::$genres
     * @return array<int|string, string>
     */
    public function genreMenuWith(array $menu, array $ticked): array
    {
        $missing = array_values(array_diff(array_filter($ticked, 'is_int'), array_keys($menu)));
        if ($missing === []) {
            return $menu;
        }
        $unknown = array_key_exists(ConsoleReleaseFilters::GENRE_UNKNOWN, $menu) ? [ConsoleReleaseFilters::GENRE_UNKNOWN => $menu[ConsoleReleaseFilters::GENRE_UNKNOWN]] : [];
        unset($menu[ConsoleReleaseFilters::GENRE_UNKNOWN]);
        $menu += Genre::query()->whereIn('id', $missing)->pluck('title', 'id')->mapWithKeys(static fn (mixed $title, mixed $id): array => [(int) $id => (string) $title])->all();
        uksort($menu, static fn (int|string $a, int|string $b): int => [mb_strtolower($menu[$a]), $a] <=> [mb_strtolower($menu[$b]), $b]);

        return $menu + $unknown;
    }

    /**
     * @param  ConsoleReleaseFilters  $filters
     * @param  list<int>  $exclusions
     */
    protected function visible(ReleaseListFilters $filters, array $exclusions, string $index): Builder
    {
        return $index === self::GAME_INDEX ? $this->gameReleases($filters, $exclusions) : parent::visible($filters, $exclusions, $index);
    }

    /**
     * Unknown without a Year: the count is the two parts' counts added.
     *
     * @param  ConsoleReleaseFilters  $filters
     * @param  list<int>  $exclusions
     */
    protected function countVisible(ReleaseListFilters $filters, array $exclusions): int
    {
        if (! $this->inTwoParts($filters)) {
            return parent::countVisible($filters, $exclusions);
        }

        return $this->noGameReleases($filters, $exclusions)->count() + $this->gameReleases($filters, $exclusions)->count();
    }

    /**
     * The name search (SPEC 5.6): the release name, or the game's name, contains the text; the
     * game's as an IN list (DATA-CONTRACT 4.2: a LEFT JOIN with OR reads twice as long).
     */
    protected function whereSearch(Builder $query, string $search): void
    {
        $like = self::likeContaining($search);
        $query->where(static fn (Builder $either) => $either->whereRaw(self::RELEASE_NAME." LIKE ? ESCAPE '!'", [$like])
            ->orWhereIn('releases.consoleinfo_id', static fn (Builder $games) => $games->select('consoleinfo.id')->from('consoleinfo')
                ->whereRaw("consoleinfo.title LIKE ? ESCAPE '!'", [$like])));
    }

    /** Unknown is ticked and Year is not: the list is the releases with no game plus the game-led read. */
    private function inTwoParts(ConsoleReleaseFilters $filters): bool
    {
        return $filters->genreUnknown() && ! $filters->anyYear();
    }

    /**
     * The matching games (`g`) joined in that order to their Console releases on the per-game
     * index, with the release filters and the name search.
     *
     * @param  list<int>  $exclusions
     */
    private function gameReleases(ConsoleReleaseFilters $filters, array $exclusions): Builder
    {
        $games = $this->gameIds($filters);
        $query = $this->isMariaDb()
            ? DB::query()->fromRaw('('.$games->toSql().') AS g STRAIGHT_JOIN releases FORCE INDEX ('.self::GAME_INDEX.') ON releases.consoleinfo_id = g.id', $games->getBindings())
            : DB::query()->fromSub($games, 'g')->join('releases', 'releases.consoleinfo_id', '=', 'g.id');

        return $this->released($query->whereBetween('releases.categories_id', self::BAND_CATEGORIES), $filters, $exclusions);
    }

    /**
     * The Console releases with no game, on the per-game index, with the release filters and the
     * name search.
     *
     * @param  list<int>  $exclusions
     */
    private function noGameReleases(ConsoleReleaseFilters $filters, array $exclusions): Builder
    {
        return $this->released($this->noGameQuery(), $filters, $exclusions);
    }

    /**
     * Every Console release with no game: not looked up (NULL) or looked up and not found
     * (ConsoleService::CONS_NTFND).
     */
    private function noGameQuery(): Builder
    {
        $query = DB::table('releases');
        if ($this->isMariaDb()) {
            $query->forceIndex(self::GAME_INDEX);
        }

        return $query->where(static fn (Builder $none) => $none->whereNull('releases.consoleinfo_id')->orWhere('releases.consoleinfo_id', ConsoleService::CONS_NTFND))
            ->whereBetween('releases.categories_id', self::BAND_CATEGORIES);
    }

    /**
     * The ids of the games the game menus match (`id`): the ticked genres' games and, with
     * Unknown, the games with no genre; those released in a ticked decade or the typed range.
     */
    private function gameIds(ConsoleReleaseFilters $filters): Builder
    {
        $sets = [];
        if (($genres = $filters->genreIds()) !== []) {
            $sets[] = DB::table('console_genres as cg')->distinct()->select('cg.consoleinfo_id as id')->whereIn('cg.genres_id', $genres);
        }
        if ($filters->genreUnknown()) {
            $sets[] = $this->unknownGames();
        }
        $genreSet = match (count($sets)) {
            0 => null,
            1 => $sets[0],
            default => $sets[0]->union($sets[1]),
        };
        if (! $filters->anyYear() && $genreSet !== null) {
            return $genreSet;
        }
        $dated = $genreSet === null ? DB::table('consoleinfo as c')->select('c.id')
            : DB::query()->fromSub($genreSet, 'gs')->join('consoleinfo as c', 'c.id', '=', 'gs.id')->select('c.id');

        return $dated->where(static function (Builder $any) use ($filters): void {
            $released = static fn (Builder $range, int $from, int $until) => $range->where('c.releasedate', '>=', $from.'-01-01')->where('c.releasedate', '<', $until.'-01-01');
            if (($bounds = $filters->yearBounds()) !== null) {
                $released($any, $bounds[0], $bounds[1] + 1);

                return;
            }
            foreach ($filters->decades as $decade) {
                $any->orWhere(static fn (Builder $range) => $released($range, $decade, $decade + 10));
            }
        });
    }

    /** The games with no genre: `genres_id` empty or the Unknown genre, the game's first genre (ConsoleGenres::replace()). */
    private function unknownGames(): Builder
    {
        $unknown = $this->unknownGenreId();

        return DB::table('consoleinfo as c')->select('c.id')->where(static function (Builder $none) use ($unknown): void {
            $none->whereNull('c.genres_id');
            if ($unknown !== null) {
                $none->orWhere('c.genres_id', $unknown);
            }
        });
    }

    /** The Unknown genre: the lowest-id type-1000 genre titled ConsoleGenres::UNKNOWN (ConsoleGenres::genreId()); null with none. */
    private function unknownGenreId(): ?int
    {
        if ($this->unknownGenre === false) {
            $id = Genre::query()->where('type', Category::GAME_ROOT)->where('title', ConsoleGenres::UNKNOWN)->orderBy('id')->value('id');
            $this->unknownGenre = $id === null ? null : (int) $id;
        }

        return $this->unknownGenre;
    }
}
