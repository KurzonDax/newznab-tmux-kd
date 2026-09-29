<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ReleaseResolution;
use App\Enums\ReleaseSort;
use App\Enums\ReleaseSource;
use App\Models\Category;
use Illuminate\Http\Request;

/**
 * The release filters every section's releases list shares (TV and Movies): the ticked
 * Category / Resolution / Source / Audio values, the Completion choice, the sort and the page.
 * Filters live in the URL; the list's last dropdown filters and the sort are remembered per user
 * in the view preferences (RememberedListFilters, issue #881; the sort is read by the caller).
 * Each section adds its own title filters (the show bar, the film bar).
 */
abstract readonly class ReleaseListFilters
{
    public const PER_PAGE = 50;

    /** The release filters' URL keys: the dropdown menus every releases list has. */
    public const RELEASE_KEYS = ['category', 'resolution', 'source', 'audio', 'completion'];

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
     * The Category filter's "Exclude Other" mode in the URL (?category=exclude-other) and the
     * remembered filters: every sub-category the user's Category menu lists except the root's
     * Other, resolved when the filters are read (issue #886).
     */
    public const EXCLUDE_OTHER = 'exclude-other';

    /**
     * @param  list<int>  $categories  ticked sub-category ids, in menu order (Exclude Other's resolved)
     * @param  list<string>  $resolutions  ticked keys of RESOLUTIONS, in menu order
     * @param  list<string>  $sources  ticked keys of SOURCES, in menu order
     * @param  list<string>  $audio  ticked Audio values (languages.id, or AUDIO_UNKNOWN), in menu order
     * @param  int|null  $completion  a key of COMPLETIONS, the lowest completion listed
     * @param  bool  $excludeOther  the Category filter is the Exclude Other mode: it applies while
     *                              $categories holds its ids, and sleeps (filters nothing, stays in
     *                              the URL) while the menu shows no Exclude Other item
     */
    public function __construct(
        public array $categories = [],
        public array $resolutions = [],
        public array $sources = [],
        public ReleaseSort $sort = ReleaseSort::PostedNewest,
        public int $page = 1,
        public array $audio = [],
        public ?int $completion = null,
        public bool $excludeOther = false,
    ) {}

    /** The same filters on another page. */
    abstract public function withPage(int $page): static;

    /** Whether any filter is set ("Clear all" shows then). */
    abstract public function any(): bool;

    /** @return array<string, list<int|string>|int|string> the URL query for this page; page 1 carries no page */
    abstract public function query(?int $page = null): array;

    /** A stable key of everything that changes which releases are counted. */
    abstract public function countKey(): string;

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
     * The root's Other sub-category when the Category menu shows the "Exclude Other" item: the
     * menu lists Other and at least one other sub-category; null otherwise.
     *
     * @param  list<int>  $menuCategories  the sub-category ids the menu lists
     */
    public static function excludableOther(array $menuCategories, int $root): ?int
    {
        $other = Category::otherForRootCategory($root);

        return $other !== null && in_array($other, $menuCategories, true) && count($menuCategories) > 1 ? $other : null;
    }

    /**
     * The release filters in a request, as constructor arguments. Unknown values, categories
     * outside the user's menu and Audio values outside the Audio menu are ignored; without an
     * Audio menu, Audio and Completion are left out. With a $root, the Category filter may be
     * the Exclude Other mode: set in the URL, or ticked by hand as every sub-category the menu
     * lists but Other. Its ids are resolved from the menu, none while the menu has no Exclude
     * Other item (the mode sleeps).
     *
     * @param  list<int>  $menuCategories  the sub-category ids the user may see, in menu order
     * @param  list<int|string>|null  $audioMenu  the Audio menu's values, in menu order
     * @param  int|null  $root  the list's root category, whose Other the mode excludes; null: no mode
     * @return array{categories: list<int>, resolutions: list<string>, sources: list<string>, sort: ReleaseSort, page: int, audio: list<string>, completion: ?int, excludeOther: bool}
     */
    protected static function releaseArguments(Request $request, array $menuCategories, mixed $savedSort, ?array $audioMenu, ?int $root = null): array
    {
        $ticked = static fn (string $key): array => array_map('strval', array_filter((array) $request->query($key, []), 'is_scalar'));
        $page = $request->query('page');
        $completion = $request->query('completion');
        $categories = array_values(array_intersect($menuCategories, array_map('intval', $ticked('category'))));
        $other = $root === null ? null : self::excludableOther($menuCategories, $root);
        $allButOther = $other === null ? [] : array_values(array_diff($menuCategories, [$other]));
        $excludeOther = $root !== null && ($request->query('category') === self::EXCLUDE_OTHER || ($allButOther !== [] && $categories === $allButOther));

        return [
            'categories' => $excludeOther ? $allButOther : $categories,
            'resolutions' => array_values(array_intersect(array_keys(self::RESOLUTIONS), $ticked('resolution'))),
            'sources' => array_values(array_intersect(array_keys(self::SOURCES), $ticked('source'))),
            'sort' => self::sort($savedSort),
            'page' => is_string($page) && ctype_digit($page) ? max(1, (int) $page) : 1,
            'audio' => $audioMenu === null ? [] : array_values(array_intersect(array_map('strval', $audioMenu), $ticked('audio'))),
            'completion' => $audioMenu !== null && is_string($completion) && ctype_digit($completion) && array_key_exists((int) $completion, self::COMPLETIONS) ? (int) $completion : null,
            'excludeOther' => $excludeOther,
        ];
    }

    /**
     * What the release filters set, for the empty result, as the prototypes' filterText():
     * "SD or UHD", "4K", "DVD", "95%+ complete", "English or Unknown audio".
     *
     * @param  array<int, string>  $categoryMenu
     * @param  array<int|string, string>  $audioMenu
     * @return list<string>
     */
    protected function describeRelease(array $categoryMenu, array $audioMenu): array
    {
        $audio = implode(' or ', self::named($this->audio, $audioMenu));

        return array_values(array_filter([
            $this->excludesOther() ? 'excluding Other' : implode(' or ', self::named($this->categories, $categoryMenu)),
            implode(' or ', self::named($this->resolutions, self::resolutionOptions())),
            implode(' or ', self::named($this->sources, self::sourceOptions())),
            $this->completion === null ? '' : self::COMPLETIONS[$this->completion][2],
            $audio === '' ? '' : $audio.' audio',
        ], static fn (string $part): bool => $part !== ''));
    }

    /**
     * @param  list<int|string>  $values
     * @param  array<int|string, string|list<string>>  $names
     * @return list<string>
     */
    protected static function named(array $values, array $names): array
    {
        return array_map(static fn (int|string $value): string => is_string($names[$value] ?? null) ? $names[$value] : (string) $value, $values);
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

    /** Whether the Exclude Other mode applies: set, and not sleeping. */
    public function excludesOther(): bool
    {
        return $this->excludeOther && $this->categories !== [];
    }

    /** Whether a release filter is set; a sleeping Exclude Other is not. */
    public function anyRelease(): bool
    {
        return $this->categories !== [] || $this->resolutions !== [] || $this->sources !== [] || $this->audio !== [] || $this->completion !== null;
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

    /** @return array<string, list<int|string>|int|string> the release filters' URL query */
    protected function releaseQuery(): array
    {
        return array_filter([
            'category' => $this->excludeOther ? self::EXCLUDE_OTHER : $this->categories, 'resolution' => $this->resolutions, 'source' => $this->sources,
            'audio' => $this->audio, 'completion' => $this->completion ?? [],
        ], static fn (array|int|string $value): bool => $value !== []);
    }

    /** @return list<mixed> the release filters' part of countKey() */
    protected function releaseCountKey(): array
    {
        return [$this->categories, $this->resolutionValues(), $this->sourceValues(), $this->audio, $this->completion, $this->excludesOther()];
    }
}
