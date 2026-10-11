<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ReleaseSort;
use App\Models\Category;
use App\Support\ReleaseCompletion;
use Illuminate\Http\Request;

/**
 * What the generic release lists show (docs/proposals/generic-release-lists/SPEC.md 5.2, 5.6):
 * Category (root categories on the All, group and poster lists; Misc and Hashed on Other) and
 * Completion, the name search, one of five sorts, the page, the list's identity (the context) and
 * today's `?minc=N` links, honoured as a completion threshold that an explicit Completion value
 * (Any included) overrides and that is never remembered.
 *
 * On a search (issue #1050) the Category cell is the search's scope and the two cells live in the
 * search's address: forSearch() reads them from the search, and the page URLs are the search's own.
 *
 * The basket (issue #1051) has no filter, no name search and one order, newest posted first:
 * forBasket() reads the page alone.
 */
final readonly class GenericReleaseFilters extends ShelfReleaseFilters
{
    /** The five orders, default first (SPEC 5.1): the four shared ones, then Name: A to Z (ReleaseSort::Name). */
    public const SORTS = ReleaseListFilters::SORTS + ['title' => 'Name: A to Z'];

    /** The legacy minimum-completion key kept from today's addresses. */
    public const MINC = ReleaseCompletion::REQUEST_KEY;

    /**
     * @param  list<int>  $categories  ticked root ids (or Misc / Hashed on Other), in menu order
     * @param  int  $minCompletion  the `?minc=N` threshold, 0 when none or when Completion is explicit
     */
    public function __construct(
        public GenericListContext $context,
        array $categories = [],
        ReleaseSort $sort = ReleaseSort::PostedNewest,
        int $page = 1,
        int|string|null $completion = null,
        bool $excludeOther = false,
        string $search = '',
        public int $minCompletion = 0,
    ) {
        parent::__construct($categories, $sort, $page, $completion, $excludeOther, $search);
    }

    /**
     * The filters in a request. Ticked values outside the menu are dropped. On the All, group
     * and poster lists the Category filter may be the Exclude Other mode: `category=exclude-other`,
     * or every root the menu lists but Other ticked by hand. A `completion` key in the URL (empty
     * for Any) is explicit and wins over `minc`; otherwise a valid `minc` supplies the threshold.
     *
     * @param  list<int>  $menuValues  the ids the Category menu lists, in menu order
     */
    public static function forList(Request $request, array $menuValues, mixed $savedSort, GenericListContext $context): self
    {
        $arguments = self::shelfArguments($request, $menuValues, $savedSort, Category::OTHER_ROOT);
        $other = $context->isOther() ? null : self::excludableRoot($menuValues);
        $allButOther = $other === null ? [] : array_values(array_diff($menuValues, [$other]));
        $ticked = array_values(array_intersect($menuValues, self::chosenCategories($request)));
        $excludeOther = $other !== null && ($request->query('category') === self::EXCLUDE_OTHER || $ticked === $allButOther);
        $explicitCompletion = $request->query->has('completion');
        $minc = $explicitCompletion ? 0 : ReleaseCompletion::normalizeThreshold($request->query(self::MINC));

        return new self(
            context: $context,
            categories: $excludeOther ? $allButOther : $ticked,
            sort: self::listSort($savedSort),
            page: $arguments['page'],
            completion: $explicitCompletion ? $arguments['completion'] : null,
            excludeOther: $excludeOther,
            search: $arguments['search'],
            minCompletion: $minc,
        );
    }

    /**
     * A search's filters: the Category cell ticks the scope's roots that the menu lists (Exclude
     * Other when they are every root but Other), the Completion cell reads the search's choice and
     * a `minc` link is honoured as on All releases. Nothing is remembered: the context carries the
     * search's whole address.
     *
     * @param  list<int>  $menuValues  the roots the Category cell lists, in menu order
     * @param  ReleaseSort  $sort  one of the five sorts (listSort()): the All releases list's remembered sort
     */
    public static function forSearch(WebSearchState $search, array $menuValues, ReleaseSort $sort): self
    {
        $cell = $search->scopeCell($menuValues);

        return new self(
            context: GenericListContext::search($search->words(), $search->parameters),
            categories: $cell['categories'],
            sort: $sort,
            page: $search->page,
            completion: $search->completion(),
            excludeOther: $cell['excludeOther'],
            minCompletion: $search->completion() === null ? $search->legacyThreshold() : 0,
        );
    }

    /** The basket's page: nothing is filtered, searched or remembered, and the order is the lists' default, newest posted first. */
    public static function forBasket(Request $request): self
    {
        $page = $request->query('page');

        return new self(context: GenericListContext::basket(), page: is_string($page) && ctype_digit($page) ? max(1, (int) $page) : 1);
    }

    /** One of the five sorts; anything else is Posted: newest first. */
    public static function listSort(mixed $value): ReleaseSort
    {
        return is_string($value) && array_key_exists($value, self::SORTS) ? ReleaseSort::from($value) : ReleaseSort::PostedNewest;
    }

    /**
     * The Other root when the Category menu shows "Exclude Other" (SPEC 5.2): it lists Other and
     * at least one other root; null otherwise, and never on the Other list.
     *
     * @param  list<int>  $menuValues
     */
    public static function excludableRoot(array $menuValues): ?int
    {
        return in_array(Category::OTHER_ROOT, $menuValues, true) && count($menuValues) > 1 ? Category::OTHER_ROOT : null;
    }

    /**
     * The completion threshold applied: the Completion choice, else the legacy `minc`; null for
     * none, and for Imported only (an explicit choice that is no threshold).
     */
    public function threshold(): ?int
    {
        if ($this->completion !== null) {
            return $this->completionThreshold();
        }

        return $this->minCompletion > 0 ? $this->minCompletion : null;
    }

    public function sortsByName(): bool
    {
        return $this->sort === ReleaseSort::Name;
    }

    /**
     * What is set, for the empty result (the prototype's filterText()): the Category or "excluding
     * Other", the completion, then the name search.
     *
     * @param  array<int, string>  $categoryMenu
     */
    public function describe(array $categoryMenu): string
    {
        $parts = $this->describeRelease($categoryMenu, []);
        if ($this->completion === null && $this->minCompletion > 0) {
            $parts[] = $this->minCompletion.'%+ complete';
        }

        return $this->describedWithSearch($parts);
    }

    /**
     * Whether Clear all has something to clear: a dropdown filter, the legacy threshold or the name
     * search, never the list's identity; on a search anything its address sets, the words included.
     */
    public function any(): bool
    {
        if ($this->context->isSearch()) {
            return $this->context->parameters !== [];
        }

        return $this->anyWithSearch($this->anyRelease() || $this->minCompletion > 0);
    }

    public function withPage(int $page): static
    {
        return new self(context: $this->context, categories: $this->categories, sort: $this->sort, page: $page, completion: $this->completion,
            excludeOther: $this->excludeOther, search: $this->search, minCompletion: $this->minCompletion);
    }

    /**
     * The URL query for this page: the filters, the name search, the page, the list's identity
     * and the legacy threshold while no Completion is explicit (choosing one drops it). A search's
     * is its own address, the scope as written, with the page.
     *
     * @return array<string, list<int|string>|int|string>
     */
    public function query(?int $page = null): array
    {
        if ($this->context->isSearch()) {
            $page ??= $this->page;

            return [...$this->context->parameters, ...($page > 1 ? ['page' => $page] : [])];
        }
        $filters = $this->releaseQuery();
        if ($this->completion === null && $this->minCompletion > 0) {
            $filters[self::MINC] = $this->minCompletion;
        }

        return [...$this->context->routeParameters(), ...$this->queryWithSearch($filters, $page ?? $this->page)];
    }

    public function countKey(): string
    {
        return $this->countKeyWithSearch([...$this->releaseCountKey(), $this->context->kind, $this->context->key, $this->context->watching, $this->minCompletion]);
    }
}
