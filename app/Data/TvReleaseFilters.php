<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ReleaseResolution;
use App\Enums\ReleaseSort;
use App\Enums\ReleaseSource;
use Illuminate\Http\Request;

/**
 * What the TV releases screen shows: the ticked Category / Resolution / Source values,
 * the sort and the page. Filters live in the URL and are never remembered; the sort is
 * remembered per user and read from the view preferences by the caller.
 */
final readonly class TvReleaseFilters
{
    public const PER_PAGE = 50;

    /** Menu order and URL values of the Resolution menu. */
    public const RESOLUTIONS = ['4k' => ReleaseResolution::Uhd, '1080p' => ReleaseResolution::FullHd, '720p' => ReleaseResolution::Hd, 'sd' => ReleaseResolution::Sd, 'unknown' => ReleaseResolution::Unknown];

    /** Menu order and URL values of the Source menu; Blu-ray also matches a remux. */
    public const SOURCES = ['web' => [ReleaseSource::Web], 'bluray' => [ReleaseSource::BluRay, ReleaseSource::Remux], 'dvd' => [ReleaseSource::Dvd], 'hdtv' => [ReleaseSource::Hdtv], 'unknown' => [ReleaseSource::Unknown]];

    /** The four orders, default first; labels are the prototype's. */
    public const SORTS = ['posted' => 'Posted: newest first', 'posted_oldest' => 'Posted: oldest first', 'newest' => 'Added: newest first', 'oldest' => 'Added: oldest first'];

    /**
     * @param  list<int>  $categories  ticked TV sub-category ids, in menu order
     * @param  list<string>  $resolutions  ticked keys of RESOLUTIONS, in menu order
     * @param  list<string>  $sources  ticked keys of SOURCES, in menu order
     */
    public function __construct(
        public array $categories = [],
        public array $resolutions = [],
        public array $sources = [],
        public ReleaseSort $sort = ReleaseSort::PostedNewest,
        public int $page = 1,
    ) {}

    /**
     * Unknown values, and categories outside the user's menu, are ignored.
     *
     * @param  list<int>  $menuCategories  the TV sub-category ids the user may see, in menu order
     */
    public static function fromRequest(Request $request, array $menuCategories, mixed $savedSort): self
    {
        $ticked = static fn (string $key): array => array_map('strval', array_filter((array) $request->query($key, []), 'is_scalar'));
        $categories = array_values(array_intersect($menuCategories, array_map('intval', $ticked('category'))));
        $page = $request->query('page');

        return new self(
            categories: $categories,
            resolutions: array_values(array_intersect(array_keys(self::RESOLUTIONS), $ticked('resolution'))),
            sources: array_values(array_intersect(array_keys(self::SOURCES), $ticked('source'))),
            sort: self::sort($savedSort),
            page: is_string($page) && ctype_digit($page) ? max(1, (int) $page) : 1,
        );
    }

    public static function sort(mixed $value): ReleaseSort
    {
        return is_string($value) && array_key_exists($value, self::SORTS) ? ReleaseSort::from($value) : ReleaseSort::PostedNewest;
    }

    /** @return array<string, string> Resolution menu: URL value => label */
    public static function resolutionOptions(): array
    {
        return array_map(static fn (ReleaseResolution $resolution): string => $resolution->label(), self::RESOLUTIONS);
    }

    /** @return array<string, string> Source menu: URL value => label */
    public static function sourceOptions(): array
    {
        return array_map(static fn (array $sources): string => $sources[0]->label(), self::SOURCES);
    }

    /**
     * What is ticked, for the empty result: "SD or UHD · 4K · DVD".
     *
     * @param  array<int, string>  $categoryMenu
     */
    public function describe(array $categoryMenu): string
    {
        $resolutions = self::resolutionOptions();
        $sources = self::sourceOptions();

        return implode(' · ', array_filter([
            implode(' or ', array_map(static fn (int $id): string => $categoryMenu[$id], $this->categories)),
            implode(' or ', array_map(static fn (string $key): string => $resolutions[$key], $this->resolutions)),
            implode(' or ', array_map(static fn (string $key): string => $sources[$key], $this->sources)),
        ]));
    }

    /** @return list<int> */
    public function resolutionValues(): array
    {
        return array_map(static fn (string $key): int => self::RESOLUTIONS[$key]->value, $this->resolutions);
    }

    /** @return list<int> */
    public function sourceValues(): array
    {
        $values = [];
        foreach ($this->sources as $key) {
            foreach (self::SOURCES[$key] as $source) {
                $values[] = $source->value;
            }
        }

        return $values;
    }

    public function sortsByAdded(): bool
    {
        return in_array($this->sort, [ReleaseSort::AddedNewest, ReleaseSort::AddedOldest], true);
    }

    public function ascending(): bool
    {
        return in_array($this->sort, [ReleaseSort::PostedOldest, ReleaseSort::AddedOldest], true);
    }

    public function withPage(int $page): self
    {
        return new self($this->categories, $this->resolutions, $this->sources, $this->sort, $page);
    }

    /** @return array<string, list<int|string>|int> the URL query for this page; page 1 carries no page */
    public function query(?int $page = null): array
    {
        $page ??= $this->page;

        return array_filter([
            'category' => $this->categories, 'resolution' => $this->resolutions, 'source' => $this->sources,
            'page' => $page > 1 ? $page : [],
        ], static fn (array|int $value): bool => $value !== []);
    }

    /** A stable key of everything that changes which releases are counted. */
    public function countKey(): string
    {
        return json_encode([$this->categories, $this->resolutionValues(), $this->sourceValues()], JSON_THROW_ON_ERROR);
    }
}
