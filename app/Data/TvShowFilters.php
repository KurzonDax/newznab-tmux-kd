<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Http\Request;

/**
 * What the TV shows wall shows: the ticked Genre / Premiered / Language / Network / Rating /
 * Status values, the person, the sort and the page. Filters live in the URL; the sort is
 * remembered per user (view_prefs['tv']['shows_sort']) and read by the caller.
 */
final readonly class TvShowFilters
{
    public const PER_PAGE = 42;

    /** The four orders, default first; labels are the prototype's. */
    public const SORTS = ['recent' => 'Newest releases first', 'newsite' => 'Newest to the site first', 'prem' => 'Newest premiere first', 'az' => 'A to Z'];

    /** US TV Parental Guidelines in menu order; any other stored value is never an option. */
    public const RATINGS = ['TV-Y', 'TV-Y7', 'TV-G', 'TV-PG', 'TV-14', 'TV-MA'];

    /** Status menu: URL value => tv_info.status. */
    public const STATUSES = ['running' => 1, 'ended' => 2];

    /**
     * @param  list<int>  $genres  genres.id
     * @param  list<int>  $decades  first year of each decade, e.g. 2000
     * @param  list<string>  $languages  tv_info.original_language codes
     * @param  list<int>  $networks  networks.id
     * @param  list<string>  $ratings  values of RATINGS
     * @param  list<string>  $statuses  keys of STATUSES
     */
    public function __construct(
        public array $genres = [],
        public array $decades = [],
        public array $languages = [],
        public array $networks = [],
        public array $ratings = [],
        public array $statuses = [],
        public ?int $person = null,
        public string $sort = 'recent',
        public int $page = 1,
    ) {}

    /**
     * Values that are not in the user's option lists are ignored, each list keeping menu order.
     *
     * @param  array<string, array<int|string, string>>  $options  the option lists by URL key, as TvShowWall::options() returns them
     */
    public static function fromRequest(Request $request, array $options, mixed $savedSort): self
    {
        $ticked = static fn (string $key): array => array_values(array_intersect(
            array_map('strval', array_keys($options[$key] ?? [])),
            array_map('strval', array_filter((array) $request->query($key, []), 'is_scalar')),
        ));
        $person = $request->query('person');
        $page = $request->query('page');

        return new self(
            genres: array_map('intval', $ticked('genre')),
            decades: array_map('intval', $ticked('decade')),
            languages: $ticked('language'),
            networks: array_map('intval', $ticked('network')),
            ratings: $ticked('rating'),
            statuses: $ticked('status'),
            person: is_string($person) && ctype_digit($person) && (int) $person > 0 ? (int) $person : null,
            sort: is_string($savedSort) && array_key_exists($savedSort, self::SORTS) ? $savedSort : 'recent',
            page: is_string($page) && ctype_digit($page) ? max(1, (int) $page) : 1,
        );
    }

    /** Whether anything narrows the wall, the person included ("Clear all" shows then). */
    public function any(): bool
    {
        return $this->person !== null || $this->genres !== [] || $this->decades !== [] || $this->languages !== []
            || $this->networks !== [] || $this->ratings !== [] || $this->statuses !== [];
    }

    public function withoutPerson(): self
    {
        return new self($this->genres, $this->decades, $this->languages, $this->networks, $this->ratings, $this->statuses, null, $this->sort, $this->page);
    }

    /** @return list<int> tv_info.status values of the ticked statuses */
    public function statusValues(): array
    {
        return array_map(static fn (string $status): int => self::STATUSES[$status], $this->statuses);
    }

    /** @return array<string, list<int|string>|int> the URL query for this page; page 1 carries no page */
    public function query(?int $page = null): array
    {
        $page ??= $this->page;

        return array_filter([
            'genre' => $this->genres, 'decade' => $this->decades, 'language' => $this->languages, 'network' => $this->networks,
            'rating' => $this->ratings, 'status' => $this->statuses, 'person' => $this->person ?? [],
            'page' => $page > 1 ? $page : [],
        ], static fn (array|int $value): bool => $value !== []);
    }

    /** A stable key of everything that changes which shows are counted. */
    public function countKey(): string
    {
        return json_encode([$this->genres, $this->decades, $this->languages, $this->networks, $this->ratings, $this->statuses, $this->person], JSON_THROW_ON_ERROR);
    }
}
