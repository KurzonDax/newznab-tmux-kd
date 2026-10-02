<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ReleaseSort;
use Illuminate\Http\Request;

/**
 * What the Books, Console and PC releases lists share (docs/proposals/books-console-pc-redesign/SPEC.md
 * 5.2 and 5.6): the Category and Completion filters, the name search, the sort and the page. These
 * lists have no Resolution, Source or Audio filter; the name search travels in the URL (SEARCH) so
 * the pager and a reload keep it, and is never remembered (RememberedListFilters).
 */
abstract readonly class ShelfReleaseFilters extends ReleaseListFilters
{
    /** The lists' dropdown filter URL keys. */
    public const KEYS = ['category', 'completion'];

    /** The name search's URL key. */
    public const SEARCH = 'q';

    /**
     * @param  list<int>  $categories  ticked sub-category ids, in menu order
     * @param  int|null  $completion  a key of COMPLETIONS, the lowest completion listed
     * @param  bool  $excludeOther  the Category filter is the Exclude Other mode (ReleaseListFilters)
     * @param  string  $search  the name search, trimmed; '' when there is none
     */
    public function __construct(
        array $categories = [],
        ReleaseSort $sort = ReleaseSort::PostedNewest,
        int $page = 1,
        ?int $completion = null,
        bool $excludeOther = false,
        public string $search = '',
    ) {
        parent::__construct($categories, [], [], $sort, $page, [], $completion, $excludeOther);
    }

    /**
     * The shared filters in a request, as constructor arguments. Unknown values and categories
     * outside the user's menu are ignored, and so are a Resolution, Source or Audio value: these
     * lists have no such filter. The Audio menu passed on is empty, not null, since
     * releaseArguments() drops Completion without one.
     *
     * @param  list<int>  $menuCategories  the sub-category ids the menu lists, in menu order
     * @param  int  $root  the list's root category, whose Other "Exclude Other" leaves out
     * @return array{categories: list<int>, sort: ReleaseSort, page: int, completion: ?int, excludeOther: bool, search: string}
     */
    protected static function shelfArguments(Request $request, array $menuCategories, mixed $savedSort, int $root): array
    {
        $release = self::releaseArguments($request, $menuCategories, $savedSort, [], $root);
        $search = $request->query(self::SEARCH);

        return [
            'categories' => $release['categories'],
            'sort' => $release['sort'],
            'page' => $release['page'],
            'completion' => $release['completion'],
            'excludeOther' => $release['excludeOther'],
            'search' => is_string($search) ? trim($search) : '',
        ];
    }

    /**
     * What is set, for the empty result, as the prototype's filterText(), the name search last:
     * "Comics · 95%+ complete · names containing “text”".
     *
     * @param  list<string>  $parts  the filters' parts, in the bar's order
     */
    protected function describedWithSearch(array $parts): string
    {
        if ($this->search !== '') {
            $parts[] = 'names containing “'.$this->search.'”';
        }

        return implode(' · ', $parts);
    }

    /** Whether a filter or the name search is set; Clear all empties both. */
    protected function anyWithSearch(bool $filterSet): bool
    {
        return $filterSet || $this->search !== '';
    }

    /**
     * The URL query of the filters, the name search and the page; page 1 carries no page.
     *
     * @param  array<string, list<int|string>|int|string>  $filters
     * @return array<string, list<int|string>|int|string>
     */
    protected function queryWithSearch(array $filters, int $page): array
    {
        return array_filter([...$filters, self::SEARCH => $this->search === '' ? [] : $this->search, 'page' => $page > 1 ? $page : []],
            static fn (array|int|string $value): bool => $value !== []);
    }

    /** @param list<mixed> $parts the filters' part of the count key */
    protected function countKeyWithSearch(array $parts): string
    {
        return json_encode([...$parts, $this->search], JSON_THROW_ON_ERROR);
    }
}
