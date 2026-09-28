<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Http\Request;

/**
 * What the Films wall shows (docs/proposals/movies-redesign/SPEC.md 5A): the film bar's values
 * (MovieFilmFilters, the wall's own, not shared with the list), the person, the sort and the page.
 * Filters live in the URL; the sort is remembered per user (view_prefs['movies']['films_sort'])
 * and read by the caller.
 */
final readonly class MovieFilmWallFilters
{
    public const int PER_PAGE = 42;

    /** The four orders, default first; labels are the prototype's. */
    public const array SORTS = ['recent' => 'Newest releases first', 'newsite' => 'Newest to the site first', 'year' => 'Newest films first', 'az' => 'A to Z'];

    public function __construct(
        public MovieFilmFilters $films = new MovieFilmFilters,
        public ?int $person = null,
        public string $sort = 'recent',
        public int $page = 1,
    ) {}

    /**
     * Values outside the menus are ignored (MovieFilmFilters::fromRequest).
     *
     * @param  array{genre: array<int, string>, rating: array<string, string>, language: array<string, string>, language_codes: array<string, list<string>>}  $options
     */
    public static function fromRequest(Request $request, array $options, mixed $savedSort): self
    {
        $person = $request->query('person');
        $page = $request->query('page');

        return new self(
            films: MovieFilmFilters::fromRequest($request, $options),
            person: is_string($person) && ctype_digit($person) && (int) $person > 0 ? (int) $person : null,
            sort: is_string($savedSort) && array_key_exists($savedSort, self::SORTS) ? $savedSort : 'recent',
            page: is_string($page) && ctype_digit($page) ? max(1, (int) $page) : 1,
        );
    }

    /** Whether anything narrows the wall, the person included ("Clear all" shows then). */
    public function any(): bool
    {
        return $this->person !== null || $this->films->any();
    }

    public function withoutPerson(): self
    {
        return new self($this->films, null, $this->sort, $this->page);
    }

    /**
     * What is set, for the empty result, as the prototype's wallFilterText():
     * "Western · 1990s · score 9+ · rated NC-17 · in French · with Ada Quill".
     *
     * @param  array<int, string>  $genres  the Genre menu
     * @param  array<string, string>  $languages  the Language menu
     */
    public function describe(array $genres, array $languages, ?string $person): string
    {
        $parts = $this->films->describe($genres, $languages);

        return implode(' · ', array_filter([$parts['genre'] ?? '', $parts['year'] ?? '', $parts['score'] ?? '', $parts['rating'] ?? '',
            $parts['language'] ?? '', $person === null ? '' : 'with '.$person], static fn (string $part): bool => $part !== ''));
    }

    /** @return array<string, list<int|string>|int> the URL query for this page; page 1 carries no page */
    public function query(?int $page = null): array
    {
        $page ??= $this->page;

        return array_filter([...$this->films->query(), 'person' => $this->person ?? [], 'page' => $page > 1 ? $page : []],
            static fn (array|int $value): bool => $value !== []);
    }

    /** A stable key of everything that changes which films are counted. */
    public function countKey(): string
    {
        return json_encode([$this->films->countKey(), $this->person], JSON_THROW_ON_ERROR);
    }
}
