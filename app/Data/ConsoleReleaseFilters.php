<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ReleaseSort;
use App\Models\Category;
use App\Models\Genre;
use App\Services\MetadataProcessing\ConsoleGenres;
use Illuminate\Http\Request;

/**
 * What the Console releases list shows (docs/proposals/books-console-pc-redesign/SPEC.md 5.2, 5.6,
 * 5.8 and 5.9): the shelf filters (Category, Completion, the name search, the sort and the page)
 * and the game's menus, Genre and Year. Year is either ticked decades (back to DECADE_FLOOR) or a
 * typed range (From alone is one year), never both, read as the Movies Year menu reads it.
 */
final readonly class ConsoleReleaseFilters extends ShelfReleaseFilters
{
    /** The list's dropdown filter URL keys: the shelf keys, then the game's (the Movies Genre and Year keys). */
    public const KEYS = [...ShelfReleaseFilters::KEYS, 'genre', 'decade', 'year_from', 'year_to'];

    /** The Genre menu's value for releases with no game and games with no genre. */
    public const GENRE_UNKNOWN = 'unknown';

    /** The earliest decade the Year menu offers (the maintainer's call, 2026-10-01); the range keeps MovieFilmFilters::FIRST_YEAR. */
    public const int DECADE_FLOOR = 1990;

    /**
     * @param  list<int>  $categories  ticked sub-category ids, in menu order
     * @param  int|null  $completion  a key of COMPLETIONS, the lowest completion listed
     * @param  bool  $excludeOther  the Category filter is the Exclude Other mode (ReleaseListFilters)
     * @param  string  $search  the name search, trimmed; '' when there is none
     * @param  list<int|string>  $genres  ticked genres.id (type 1000) and GENRE_UNKNOWN, in menu order
     * @param  list<int>  $decades  ticked decades (1990 for the 1990s), newest first
     * @param  int|null  $yearFrom  the range's first year; with it, $decades is empty
     * @param  int|null  $yearTo  the range's last year; null reads From alone as one year
     */
    public function __construct(
        array $categories = [],
        ReleaseSort $sort = ReleaseSort::PostedNewest,
        int $page = 1,
        ?int $completion = null,
        bool $excludeOther = false,
        string $search = '',
        public array $genres = [],
        public array $decades = [],
        public ?int $yearFrom = null,
        public ?int $yearTo = null,
    ) {
        parent::__construct($categories, $sort, $page, $completion, $excludeOther, $search);
    }

    /**
     * The filters in a request. A genre id is kept when it is a type-1000 `genres` row, read from
     * the table rather than the hour-cached Genre menu, which lags new genres; Unknown is kept only
     * when the menu offers it. A decade before DECADE_FLOOR and a range outside
     * MovieFilmFilters::FIRST_YEAR to the current year are ignored; a range replaces ticked decades.
     *
     * @param  list<int>  $menuCategories  the sub-category ids the menu lists, in menu order
     * @param  array<int|string, string>  $genreMenu  the Genre menu (ConsoleReleaseList::genreMenu())
     */
    public static function forList(Request $request, array $menuCategories, mixed $savedSort, array $genreMenu): self
    {
        $ticked = static fn (string $key): array => array_map('strval', array_filter((array) $request->query($key, []), 'is_scalar'));
        [$from, $to] = MovieFilmFilters::range($request->query('year_from'), $request->query('year_to'));

        return new self(...self::shelfArguments($request, $menuCategories, $savedSort, Category::GAME_ROOT),
            genres: self::validGenres($ticked('genre'), $genreMenu),
            decades: $from === null ? array_map('intval', array_values(array_intersect(array_map('strval', array_keys(self::decadeOptions())), $ticked('decade')))) : [],
            yearFrom: $from,
            yearTo: $to,
        );
    }

    /** @return array<int, string> the Year menu's decades, newest first: 2020 => "2020s" … 1990 => "1990s" */
    public static function decadeOptions(): array
    {
        $decades = [];
        for ($decade = intdiv(MovieFilmFilters::lastYear(), 10) * 10; $decade >= self::DECADE_FLOOR; $decade -= 10) {
            $decades[$decade] = $decade.'s';
        }

        return $decades;
    }

    /** @return list<int> the ticked genres.id values, Unknown left out */
    public function genreIds(): array
    {
        return array_values(array_filter($this->genres, 'is_int'));
    }

    public function genreUnknown(): bool
    {
        return in_array(self::GENRE_UNKNOWN, $this->genres, true);
    }

    /** Whether a Year value is set (decades or a range). */
    public function anyYear(): bool
    {
        return $this->decades !== [] || $this->yearFrom !== null;
    }

    /** Whether a game menu (Genre or Year) is set: the game drives the read. */
    public function anyGame(): bool
    {
        return $this->genres !== [] || $this->anyYear();
    }

    /** @return array{0: int, 1: int}|null the range's first and last year; From alone is that one year */
    public function yearBounds(): ?array
    {
        return $this->yearFrom === null ? null : [$this->yearFrom, $this->yearTo ?? $this->yearFrom];
    }

    /**
     * What is set, for the empty result, in the prototype's order: "Wii or PS3 · Fighting or
     * Unknown · 1990s or 2000s · 95%+ complete · release or game names containing “text”".
     *
     * @param  array<int, string>  $categoryMenu
     * @param  array<int|string, string>  $genreNames  the Genre menu's names, and any other ticked genre's
     */
    public function describe(array $categoryMenu, array $genreNames): string
    {
        $parts = array_values(array_filter([
            $this->excludesOther() ? 'excluding Other' : implode(' or ', self::named($this->categories, $categoryMenu)),
            implode(' or ', self::named($this->genres, $genreNames)),
            $this->yearFrom !== null ? MovieFilmFilters::rangeLabel($this->yearFrom, $this->yearTo) : implode(' or ', array_map(static fn (int $decade): string => $decade.'s', $this->decades)),
            $this->completion === null ? '' : self::COMPLETIONS[$this->completion][2],
        ], static fn (string $part): bool => $part !== ''));

        return $this->describedWithSearch($parts, 'release or game names');
    }

    public function any(): bool
    {
        return $this->anyWithSearch($this->anyRelease() || $this->anyGame());
    }

    public function withPage(int $page): static
    {
        return new self(categories: $this->categories, sort: $this->sort, page: $page, completion: $this->completion, excludeOther: $this->excludeOther,
            search: $this->search, genres: $this->genres, decades: $this->decades, yearFrom: $this->yearFrom, yearTo: $this->yearTo);
    }

    /** @return array<string, list<int|string>|int|string> the URL query for this page; page 1 carries no page */
    public function query(?int $page = null): array
    {
        return $this->queryWithSearch([...$this->releaseQuery(), 'genre' => $this->genres, 'decade' => $this->decades, 'year_from' => $this->yearFrom ?? [],
            'year_to' => $this->yearTo ?? []], $page ?? $this->page);
    }

    public function countKey(): string
    {
        return $this->countKeyWithSearch([...$this->releaseCountKey(), $this->genres, $this->decades, $this->yearBounds()]);
    }

    /**
     * The ticked Genre values in menu order: the menu's genres, then type-1000 genres the cached
     * menu does not list yet (A to Z), then Unknown (the Unknown genre's own id included).
     *
     * @param  list<string>  $ticked
     * @param  array<int|string, string>  $genreMenu
     * @return list<int|string>
     */
    private static function validGenres(array $ticked, array $genreMenu): array
    {
        $requested = array_values(array_unique(array_map('intval', array_filter($ticked, static fn (string $value): bool => ctype_digit($value)))));
        $rows = $requested === [] ? collect() : Genre::query()->where('type', Category::GAME_ROOT)->whereIn('id', $requested)->orderBy('title')->orderBy('id')
            ->get(['id', 'title']);
        // The Unknown genre is never a menu genre of its own (SPEC 5.8): its id reads as the Unknown option.
        $unknown = $rows->contains(static fn (Genre $genre): bool => $genre->title === ConsoleGenres::UNKNOWN);
        $valid = $rows->reject(static fn (Genre $genre): bool => $genre->title === ConsoleGenres::UNKNOWN)->map(static fn (Genre $genre): int => (int) $genre->id)->values()->all();
        $menuIds = array_values(array_filter(array_keys($genreMenu), 'is_int'));
        $genres = [...array_values(array_intersect($menuIds, $valid)), ...array_values(array_diff($valid, $menuIds))];
        if (($unknown || in_array(self::GENRE_UNKNOWN, $ticked, true)) && array_key_exists(self::GENRE_UNKNOWN, $genreMenu)) {
            $genres[] = self::GENRE_UNKNOWN;
        }

        return $genres;
    }
}
