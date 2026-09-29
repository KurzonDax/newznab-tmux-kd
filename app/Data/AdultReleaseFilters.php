<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ReleaseSort;
use App\Models\Category;
use Illuminate\Http\Request;

/**
 * What the Adult releases screen shows (docs/proposals/adult-redesign/SPEC.md 5.2 and 5.9): the
 * release filters without Source (ReleaseListFilters), the name search, the sort and the page.
 * The name search travels in the URL (SEARCH) so the pager and a reload keep it; it is not a
 * dropdown filter, so it is never remembered (RememberedListFilters).
 */
final readonly class AdultReleaseFilters extends ReleaseListFilters
{
    /** The list's dropdown filter URL keys: the release menus without Source. */
    public const KEYS = ['category', 'resolution', 'audio', 'completion'];

    /** The name search's URL key. */
    public const SEARCH = 'q';

    /**
     * @param  list<int>  $categories  ticked Adult sub-category ids, in menu order
     * @param  list<string>  $resolutions  ticked keys of RESOLUTIONS, in menu order
     * @param  list<string>  $audio  ticked Audio values (languages.id, or AUDIO_UNKNOWN), in menu order
     * @param  int|null  $completion  a key of COMPLETIONS, the lowest completion listed
     * @param  bool  $excludeOther  the Category filter is the Exclude Other mode (ReleaseListFilters)
     * @param  string  $search  the name search, trimmed; '' when there is none
     */
    public function __construct(
        array $categories = [],
        array $resolutions = [],
        ReleaseSort $sort = ReleaseSort::PostedNewest,
        int $page = 1,
        array $audio = [],
        ?int $completion = null,
        bool $excludeOther = false,
        public string $search = '',
    ) {
        parent::__construct($categories, $resolutions, [], $sort, $page, $audio, $completion, $excludeOther);
    }

    /**
     * Unknown values, categories outside the user's menu, values outside the menus and a Source
     * filter are ignored.
     *
     * @param  list<int>  $menuCategories  the Adult sub-category ids the menu lists, in menu order
     * @param  list<int|string>  $audioMenu  the Audio menu's values, in menu order
     */
    public static function forList(Request $request, array $menuCategories, mixed $savedSort, array $audioMenu): self
    {
        $release = self::releaseArguments($request, $menuCategories, $savedSort, $audioMenu, Category::XXX_ROOT);
        unset($release['sources']);
        $search = $request->query(self::SEARCH);

        return new self(...$release, search: is_string($search) ? trim($search) : '');
    }

    /**
     * What is set, for the empty result, as the prototype's filterText():
     * "x264 · 1080p · 95%+ complete · English audio · names containing “text”".
     *
     * @param  array<int, string>  $categoryMenu
     * @param  array<int|string, string>  $audioMenu
     */
    public function describe(array $categoryMenu, array $audioMenu): string
    {
        $parts = $this->describeRelease($categoryMenu, $audioMenu);
        if ($this->search !== '') {
            $parts[] = 'names containing “'.$this->search.'”';
        }

        return implode(' · ', $parts);
    }

    /** Whether a filter or the name search is set; Clear all empties both. */
    public function any(): bool
    {
        return $this->anyRelease() || $this->search !== '';
    }

    public function withPage(int $page): static
    {
        return new self(categories: $this->categories, resolutions: $this->resolutions, sort: $this->sort, page: $page, audio: $this->audio,
            completion: $this->completion, excludeOther: $this->excludeOther, search: $this->search);
    }

    /** @return array<string, list<int|string>|int|string> the URL query for this page; page 1 carries no page */
    public function query(?int $page = null): array
    {
        $page ??= $this->page;

        return array_filter([...$this->releaseQuery(), self::SEARCH => $this->search === '' ? [] : $this->search, 'page' => $page > 1 ? $page : []],
            static fn (array|int|string $value): bool => $value !== []);
    }

    public function countKey(): string
    {
        return json_encode([...$this->releaseCountKey(), $this->search], JSON_THROW_ON_ERROR);
    }
}
