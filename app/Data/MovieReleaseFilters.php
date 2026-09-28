<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ReleaseSort;
use Illuminate\Http\Request;

/**
 * What the Movie releases screen shows (docs/proposals/movies-redesign/SPEC.md 5.2): the release
 * filters (ReleaseListFilters), the film bar (MovieFilmFilters), the sort and the page.
 */
final readonly class MovieReleaseFilters extends ReleaseListFilters
{
    /**
     * @param  list<int>  $categories  ticked Movies sub-category ids, in menu order
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
        public MovieFilmFilters $films = new MovieFilmFilters,
    ) {
        parent::__construct($categories, $resolutions, $sources, $sort, $page, $audio, $completion);
    }

    /**
     * Unknown values, categories outside the user's menu and values outside the menus are ignored.
     *
     * @param  list<int>  $menuCategories  the Movies sub-category ids the user may see, in menu order
     * @param  list<int|string>  $audioMenu  the Audio menu's values, in menu order
     * @param  array{genre: array<int, string>, rating: array<string, string>, language: array<string, string>, language_codes: array<string, list<string>>}  $filmOptions  the film menus' options by URL key
     */
    public static function forList(Request $request, array $menuCategories, mixed $savedSort, array $audioMenu, array $filmOptions): self
    {
        return new self(...self::releaseArguments($request, $menuCategories, $savedSort, $audioMenu),
            films: MovieFilmFilters::fromRequest($request, $filmOptions));
    }

    /**
     * What is set, for the empty result, as the prototype's filterText():
     * "HD · 1080p · Blu-ray · 95%+ complete · English audio · Horror · in French · 1990s · score 9+ · rated R".
     *
     * @param  array<int, string>  $categoryMenu
     * @param  array<int|string, string>  $audioMenu
     * @param  array{genre: array<int, string>, language: array<string, string>}  $filmOptions
     */
    public function describe(array $categoryMenu, array $audioMenu, array $filmOptions): string
    {
        return implode(' · ', [...$this->describeRelease($categoryMenu, $audioMenu), ...$this->films->describe($filmOptions['genre'], $filmOptions['language'])]);
    }

    public function any(): bool
    {
        return $this->anyRelease() || $this->films->any();
    }

    public function withPage(int $page): static
    {
        return new self($this->categories, $this->resolutions, $this->sources, $this->sort, $page, $this->audio, $this->completion, $this->films);
    }

    /** @return array<string, list<int|string>|int> the URL query for this page; page 1 carries no page */
    public function query(?int $page = null): array
    {
        $page ??= $this->page;

        return array_filter([...$this->releaseQuery(), ...$this->films->query(), 'page' => $page > 1 ? $page : []],
            static fn (array|int $value): bool => $value !== []);
    }

    public function countKey(): string
    {
        return json_encode([...$this->releaseCountKey(), $this->films->countKey()], JSON_THROW_ON_ERROR);
    }
}
