<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ReleaseSort;
use Illuminate\Http\Request;

/**
 * What the TV releases screen shows: the release filters (ReleaseListFilters), the six show
 * filters, the sort and the page. The show page reads Category / Resolution / Source alone.
 */
final readonly class TvReleaseFilters extends ReleaseListFilters
{
    /** The list's dropdown filter URL keys: the release menus, then the show menus. */
    public const KEYS = [...self::RELEASE_KEYS, ...TvShowFilters::MENU_KEYS];

    /**
     * @param  list<int>  $categories  ticked TV sub-category ids, in menu order
     * @param  list<string>  $resolutions  ticked keys of RESOLUTIONS, in menu order
     * @param  list<string>  $sources  ticked keys of SOURCES, in menu order
     * @param  list<string>  $audio  ticked Audio values (languages.id, or AUDIO_UNKNOWN), in menu order
     * @param  int|null  $completion  a key of COMPLETIONS, the lowest completion listed
     */
    public function __construct(
        array $categories = [],
        array $resolutions = [],
        array $sources = [],
        ReleaseSort $sort = ReleaseSort::PostedNewest,
        int $page = 1,
        array $audio = [],
        ?int $completion = null,
        public TvShowFilters $shows = new TvShowFilters,
    ) {
        parent::__construct($categories, $resolutions, $sources, $sort, $page, $audio, $completion);
    }

    /**
     * Unknown values, and categories outside the user's menu, are ignored.
     *
     * @param  list<int>  $menuCategories  the TV sub-category ids the user may see, in menu order
     */
    public static function fromRequest(Request $request, array $menuCategories, mixed $savedSort): self
    {
        return new self(...self::releaseArguments($request, $menuCategories, $savedSort, null));
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
        return new self(...self::releaseArguments($request, $menuCategories, $savedSort, $audioMenu),
            shows: TvShowFilters::fromRequest($request, $showOptions, null)->withoutPerson());
    }

    /**
     * What is set, for the empty result, as the prototype's filterText(): "SD or UHD · 4K · DVD",
     * then "95%+ complete", "English or Unknown audio", "in Korean" and the other show values.
     * Several values of one menu read "A or B"; the show values are joined by " · ".
     *
     * @param  array<int, string>  $categoryMenu
     * @param  array<int|string, string>  $audioMenu
     * @param  array<string, array<int|string, string|list<string>>>  $showOptions  the show menus' options by URL key
     */
    public function describe(array $categoryMenu, array $audioMenu = [], array $showOptions = []): string
    {
        $shows = $this->shows;
        $languages = implode(' or ', self::named($shows->languages, $showOptions['language'] ?? []));

        return implode(' · ', array_filter([
            ...$this->describeRelease($categoryMenu, $audioMenu),
            $languages === '' ? '' : 'in '.$languages,
            implode(' · ', [
                ...self::named($shows->genres, $showOptions['genre'] ?? []),
                ...self::named($shows->decades, $showOptions['decade'] ?? []),
                ...self::named($shows->networks, $showOptions['network'] ?? []),
                ...self::named($shows->ratings, $showOptions['rating'] ?? []),
                ...self::named($shows->statuses, $showOptions['status'] ?? []),
            ]),
        ]));
    }

    public function any(): bool
    {
        return $this->anyRelease() || $this->shows->any();
    }

    public function withPage(int $page): static
    {
        return new self($this->categories, $this->resolutions, $this->sources, $this->sort, $page, $this->audio, $this->completion, $this->shows);
    }

    /** @return array<string, list<int|string>|int> the URL query for this page; page 1 carries no page */
    public function query(?int $page = null): array
    {
        $page ??= $this->page;

        return array_filter([...$this->releaseQuery(), ...$this->shows->query(1), 'page' => $page > 1 ? $page : []],
            static fn (array|int $value): bool => $value !== []);
    }

    public function countKey(): string
    {
        return json_encode([...$this->releaseCountKey(), $this->shows->countKey()], JSON_THROW_ON_ERROR);
    }
}
