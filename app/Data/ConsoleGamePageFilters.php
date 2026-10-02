<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Http\Request;

/**
 * "All N releases of this game" on the Console release page of a release with a game
 * (docs/proposals/books-console-pc-redesign/SPEC.md 5B): the sorted column, its direction and
 * the page, as MovieFilmPageFilters without its Resolution and Source values. All of it lives in
 * the URL, and the sort is never remembered.
 */
final readonly class ConsoleGamePageFilters
{
    public const PER_PAGE = ReleaseListFilters::PER_PAGE;

    /** The sortable headings, in table order. */
    public const SORTS = ['category', 'size', 'posted'];

    /** The URL suffix of an ascending sort (`?sort=size_asc`). */
    private const ASCENDING = '_asc';

    /** @param string $sort one of SORTS */
    public function __construct(
        public string $sort = 'posted',
        public bool $ascending = false,
        public int $page = 1,
    ) {}

    /** The request's table state: `?sort=<key>` descending, `?sort=<key>_asc` ascending; an unknown sort is newest posted first. */
    public static function fromRequest(Request $request): self
    {
        $sort = $request->query('sort');
        $sort = is_string($sort) ? $sort : '';
        $ascending = str_ends_with($sort, self::ASCENDING);
        $column = $ascending ? substr($sort, 0, -strlen(self::ASCENDING)) : $sort;
        $page = $request->query('page');
        $known = in_array($column, self::SORTS, true);

        return new self(
            sort: $known ? $column : 'posted',
            ascending: $known && $ascending,
            page: is_string($page) && ctype_digit($page) ? max(1, (int) $page) : 1,
        );
    }

    /** Whether the table is in its opening order, newest posted first. */
    public function isDefaultSort(): bool
    {
        return $this->sort === 'posted' && ! $this->ascending;
    }

    /** @return array<string, int|string> the URL query for this page; page 1 and the opening order leave theirs out */
    public function query(?int $page = null): array
    {
        $page ??= $this->page;

        return array_filter([
            'sort' => $this->isDefaultSort() ? [] : $this->sort.($this->ascending ? self::ASCENDING : ''),
            'page' => $page > 1 ? $page : [],
        ], static fn (array|int|string $value): bool => $value !== []);
    }
}
