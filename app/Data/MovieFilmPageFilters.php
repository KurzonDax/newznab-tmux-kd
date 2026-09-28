<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Http\Request;

/**
 * The film page's release table (docs/proposals/movies-redesign/SPEC.md 5B.2): the ticked
 * Resolution and Source values (the list's options), the sorted column and its direction, and
 * the page. All of it lives in the URL and belongs to this page: another film opens with none
 * of it, and the sort is never remembered.
 */
final readonly class MovieFilmPageFilters
{
    public const PER_PAGE = ReleaseListFilters::PER_PAGE;

    /** The sortable headings, in table order; a first click sorts descending. */
    public const SORTS = ['resolution', 'size', 'posted'];

    /** The URL suffix of an ascending sort (`?sort=size_asc`). */
    private const ASCENDING = '_asc';

    /**
     * @param  list<string>  $resolutions  ticked keys of ReleaseListFilters::RESOLUTIONS, in menu order
     * @param  list<string>  $sources  ticked keys of ReleaseListFilters::SOURCES, in menu order
     * @param  string  $sort  one of SORTS
     */
    public function __construct(
        public array $resolutions = [],
        public array $sources = [],
        public string $sort = 'posted',
        public bool $ascending = false,
        public int $page = 1,
    ) {}

    /** The request's table state; unknown values are ignored and an unknown sort is newest posted first. */
    public static function fromRequest(Request $request): self
    {
        $ticked = static fn (string $key): array => array_map('strval', array_filter((array) $request->query($key, []), 'is_scalar'));
        $sort = $request->query('sort');
        $sort = is_string($sort) ? $sort : '';
        $ascending = str_ends_with($sort, self::ASCENDING);
        $column = $ascending ? substr($sort, 0, -strlen(self::ASCENDING)) : $sort;
        $page = $request->query('page');
        $known = in_array($column, self::SORTS, true);

        return new self(
            resolutions: array_values(array_intersect(array_keys(ReleaseListFilters::RESOLUTIONS), $ticked('resolution'))),
            sources: array_values(array_intersect(array_keys(ReleaseListFilters::SOURCES), $ticked('source'))),
            sort: $known ? $column : 'posted',
            ascending: $known && $ascending,
            page: is_string($page) && ctype_digit($page) ? max(1, (int) $page) : 1,
        );
    }

    public function withPage(int $page): self
    {
        return new self($this->resolutions, $this->sources, $this->sort, $this->ascending, $page);
    }

    /** The same sort with no filter: where "Clear all" leads. */
    public function withoutFilters(): self
    {
        return new self([], [], $this->sort, $this->ascending, 1);
    }

    /** Whether Resolution or Source is set ("Clear all" shows then). */
    public function any(): bool
    {
        return $this->resolutions !== [] || $this->sources !== [];
    }

    /** Whether the table is in its opening order, newest posted first. */
    public function isDefaultSort(): bool
    {
        return $this->sort === 'posted' && ! $this->ascending;
    }

    /** @return array<string, list<string>|int|string> the URL query for this page; page 1 and the opening order leave theirs out */
    public function query(?int $page = null): array
    {
        $page ??= $this->page;

        return array_filter([
            'resolution' => $this->resolutions,
            'source' => $this->sources,
            'sort' => $this->isDefaultSort() ? [] : $this->sort.($this->ascending ? self::ASCENDING : ''),
            'page' => $page > 1 ? $page : [],
        ], static fn (array|int|string $value): bool => $value !== []);
    }

    /** What is set, for the empty line: several values of one menu read "4K or 1080p", the menus join with " · ". */
    public function describe(): string
    {
        $named = static fn (array $values, array $names): string => implode(' or ', array_map(static fn (string $value): string => $names[$value], $values));

        return implode(' · ', array_filter([
            $named($this->resolutions, ReleaseListFilters::resolutionOptions()),
            $named($this->sources, ReleaseListFilters::sourceOptions()),
        ], static fn (string $part): bool => $part !== ''));
    }

    /** @return list<int> the stored `releases.resolution` values ticked */
    public function resolutionValues(): array
    {
        return array_map(static fn (string $key): int => ReleaseListFilters::RESOLUTIONS[$key]->value, $this->resolutions);
    }

    /** @return list<int> the stored `releases.source` values ticked; Blu-ray also matches a remux */
    public function sourceValues(): array
    {
        $values = [];
        foreach ($this->sources as $key) {
            foreach (ReleaseListFilters::SOURCES[$key] as $source) {
                $values[] = $source->value;
            }
        }

        return $values;
    }
}
