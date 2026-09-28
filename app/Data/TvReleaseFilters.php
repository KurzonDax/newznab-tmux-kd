<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ReleaseResolution;
use App\Enums\ReleaseSort;
use App\Enums\ReleaseSource;
use Illuminate\Http\Request;

/**
 * What the TV releases screen shows: the ticked Category / Resolution / Source / Audio values,
 * the Completion choice, the six show filters, the sort and the page. Filters live in the URL
 * and are never remembered; the sort is remembered per user and read from the view
 * preferences by the caller. The show page reads Category / Resolution / Source alone.
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

    /** The Completion menu's choices after "Any completion": URL value => [menu item, cell text, empty line]. */
    public const COMPLETIONS = [100 => ['100% only', '100%', '100% complete'], 95 => ['95% or more', '95%+', '95%+ complete']];

    /** The Audio menu's value for releases with no audio language. */
    public const AUDIO_UNKNOWN = 'unknown';

    /**
     * @param  list<int>  $categories  ticked TV sub-category ids, in menu order
     * @param  list<string>  $resolutions  ticked keys of RESOLUTIONS, in menu order
     * @param  list<string>  $sources  ticked keys of SOURCES, in menu order
     * @param  list<string>  $audio  ticked Audio values (languages.id, or AUDIO_UNKNOWN), in menu order
     * @param  int|null  $completion  a key of COMPLETIONS, the lowest completion listed
     */
    public function __construct(
        public array $categories = [],
        public array $resolutions = [],
        public array $sources = [],
        public ReleaseSort $sort = ReleaseSort::PostedNewest,
        public int $page = 1,
        public array $audio = [],
        public ?int $completion = null,
        public TvShowFilters $shows = new TvShowFilters,
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

    /**
     * The TV releases list's filters: fromRequest() plus Audio, Completion and the show filters.
     *
     * @param  list<int>  $menuCategories  the TV sub-category ids the user may see, in menu order
     * @param  list<int|string>  $audioMenu  the Audio menu's values, in menu order
     * @param  array<string, array<int|string, string|list<string>>>  $showOptions  the show menus' options by URL key
     */
    public static function forList(Request $request, array $menuCategories, mixed $savedSort, array $audioMenu, array $showOptions): self
    {
        $filters = self::fromRequest($request, $menuCategories, $savedSort);
        $completion = $request->query('completion');
        $shows = TvShowFilters::fromRequest($request, $showOptions, null);

        return new self(
            $filters->categories, $filters->resolutions, $filters->sources, $filters->sort, $filters->page,
            audio: array_values(array_intersect(array_map('strval', $audioMenu), array_map('strval', array_filter((array) $request->query('audio', []), 'is_scalar')))),
            completion: is_string($completion) && ctype_digit($completion) && array_key_exists((int) $completion, self::COMPLETIONS) ? (int) $completion : null,
            shows: $shows->withoutPerson(),
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

    /** @return array<int, string> Completion menu: URL value => item */
    public static function completionOptions(): array
    {
        return array_map(static fn (array $texts): string => $texts[0], self::COMPLETIONS);
    }

    /** @return array<int, string> Completion cell: URL value => what the cell reads */
    public static function completionCells(): array
    {
        return array_map(static fn (array $texts): string => $texts[1], self::COMPLETIONS);
    }

    /**
     * What is set, for the empty result, as the prototype's filterText(): "SD or UHD · 4K · DVD",
     * then "95%+ complete", "English or Unknown audio", "in Korean" and the other show values.
     * Several values of one menu read "A or B"; the show values are joined by " · ".
     *
     * @param  array<int, string>  $categoryMenu
     * @param  array<string, string>  $audioMenu
     * @param  array<string, array<int|string, string|list<string>>>  $showOptions  the show menus' options by URL key
     */
    public function describe(array $categoryMenu, array $audioMenu = [], array $showOptions = []): string
    {
        $resolutions = self::resolutionOptions();
        $sources = self::sourceOptions();
        $named = static fn (array $values, array $names): array => array_map(static fn (int|string $value): string => $names[$value] ?? (string) $value, $values);
        $shows = $this->shows;
        $languages = implode(' or ', $named($shows->languages, $showOptions['language'] ?? []));
        $audio = implode(' or ', $named($this->audio, $audioMenu));

        return implode(' · ', array_filter([
            implode(' or ', $named($this->categories, $categoryMenu)),
            implode(' or ', $named($this->resolutions, $resolutions)),
            implode(' or ', $named($this->sources, $sources)),
            $this->completion === null ? '' : self::COMPLETIONS[$this->completion][2],
            $audio === '' ? '' : $audio.' audio',
            $languages === '' ? '' : 'in '.$languages,
            implode(' · ', [
                ...$named($shows->genres, $showOptions['genre'] ?? []),
                ...$named($shows->decades, $showOptions['decade'] ?? []),
                ...$named($shows->networks, $showOptions['network'] ?? []),
                ...$named($shows->ratings, $showOptions['rating'] ?? []),
                ...$named($shows->statuses, $showOptions['status'] ?? []),
            ]),
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

    /** Whether any filter is set ("Clear all" shows then). */
    public function any(): bool
    {
        return $this->categories !== [] || $this->resolutions !== [] || $this->sources !== [] || $this->audio !== []
            || $this->completion !== null || $this->shows->any();
    }

    /** @return list<int> the ticked Audio languages (languages.id), Unknown left out */
    public function audioLanguages(): array
    {
        return array_values(array_map('intval', array_filter($this->audio, static fn (string $value): bool => $value !== self::AUDIO_UNKNOWN)));
    }

    public function audioUnknown(): bool
    {
        return in_array(self::AUDIO_UNKNOWN, $this->audio, true);
    }

    public function withPage(int $page): self
    {
        return new self($this->categories, $this->resolutions, $this->sources, $this->sort, $page, $this->audio, $this->completion, $this->shows);
    }

    /** @return array<string, list<int|string>|int> the URL query for this page; page 1 carries no page */
    public function query(?int $page = null): array
    {
        $page ??= $this->page;

        return array_filter([
            'category' => $this->categories, 'resolution' => $this->resolutions, 'source' => $this->sources,
            'audio' => $this->audio, 'completion' => $this->completion ?? [], ...$this->shows->query(1),
            'page' => $page > 1 ? $page : [],
        ], static fn (array|int $value): bool => $value !== []);
    }

    /** A stable key of everything that changes which releases are counted. */
    public function countKey(): string
    {
        return json_encode([$this->categories, $this->resolutionValues(), $this->sourceValues(), $this->audio, $this->completion, $this->shows->countKey()], JSON_THROW_ON_ERROR);
    }
}
