<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\GenericListContext;
use App\Data\GenericReleaseFilters;
use App\Data\WebSearchState;
use App\Enums\BrowseRoot;
use App\Models\Category;
use App\Services\Releases\GenericReleaseRows;
use App\Services\Releases\WebReleaseSearch;
use App\Services\Search\Contracts\SearchServiceInterface;
use Illuminate\Http\Request;

/**
 * Search results (issue #1050): the generic release list (GenericReleasesController's list form)
 * in the search context. A search differs from All releases in its heading, in having no name
 * search field, in its Category cell (the search's scope `t`: every root the viewer may see, never
 * probed) and Completion cell living in the address only (nothing is remembered, nothing recalled),
 * in the query row under the filter bar, the "RSS for this search" button, the index's reach and
 * the "Search unavailable" state. The sort is the All releases list's remembered sort.
 */
class SearchController extends BasePageController
{
    public function __construct(
        private readonly WebReleaseSearch $releases,
        private readonly GenericReleaseRows $rows,
        private readonly SearchServiceInterface $searchService,
    ) {
        parent::__construct();
    }

    public function search(Request $request): mixed
    {
        $addingFilter = $request->filled('filter');
        $fragment = $request->query('_fragment') === 'list';
        $user = $this->userdata;
        $search = WebSearchState::fromRequest($request);
        if ($addingFilter) {
            return redirect()->to($search->url());
        }
        $sort = GenericReleaseFilters::listSort($user->releaseViewPreferences(GenericListContext::ALL)['sort'] ?? null);
        $results = $this->releases->paginate($search, $user, $sort);
        if ($results->available && $search->page > $results->lastPage) {
            return redirect()->to($search->url(['page' => (string) $results->lastPage]));
        }
        $words = $search->words();
        $spellSuggestion = null;
        if ($results->available && $words !== '' && $results->total <= 3 && $this->searchService->isSuggestEnabled()) {
            $suggestions = $this->searchService->suggest($words);
            usort($suggestions, static fn (array $a, array $b): int => (int) $b['docs'] <=> (int) $a['docs']);
            $spellSuggestion = ($suggestions[0]['suggest'] ?? $words) !== $words ? $suggestions[0]['suggest'] : null;
        }

        $roots = self::viewableRoots(array_values(array_map('intval', (array) $user->categoryexclusions)));
        $menu = array_map(static fn (array $root): string => $root['label'], $roots);
        $filters = GenericReleaseFilters::forSearch($search, array_keys($menu), $sort)->withPage($results->page);
        $context = $filters->context;
        $chips = $search->chips();
        // What is set, for the empty result: the query row's chips, then the cells. A legacy scope's chip already names it.
        $cells = $search->customScope() ? GenericReleaseFilters::forSearch($search, [], $sort) : $filters;
        $matching = implode(' · ', array_filter([...array_column($chips, 'label'), $cells->describe($menu)], static fn (string $part): bool => $part !== ''));

        return view($fragment ? 'generic.releases.list' : 'generic.releases.index', [...$this->viewData,
            'meta_title' => $context->heading(),
            'heading' => $context->heading(),
            'context' => $context,
            'listRoute' => $context->route,
            'preferenceRoot' => $context->preferenceRoot(),
            'emptyText' => $context->emptyText(),
            'filters' => $filters,
            'matching' => $matching,
            'categoryMenu' => $menu,
            'excludableOther' => GenericReleaseFilters::excludableRoot(array_keys($menu)),
            'total' => $results->total,
            'lastPage' => $results->lastPage,
            'rows' => $results->ids === [] ? [] : $this->rows->load($results->ids, $filters->sortsByAdded()),
            'nzbLinkBase' => url('/api/v1/api'),
            'apiToken' => (string) $user->api_token,
            'filtersClock' => 0,
            'posterIdentity' => '', 'blacklistRule' => null, 'blacklistPreview' => null, 'blacklistPreviewToken' => null, 'sweep' => null,
            'results' => $results,
            'searchState' => $search,
            'queryChips' => $chips,
            'spellSuggestion' => $spellSuggestion,
            'feedUrl' => $search->rssUrl($user),
            'headerScope' => $search->headerScope(array_keys($menu)),
            'addFilters' => ['cat' => 'Sub-category', 'age' => 'Age', 'size' => 'Size range', ...($search->admitsMovies($roots) ? WebSearchState::MOVIE_FIELDS : [])],
            'subCategories' => $search->admittedSubCategories($roots),
            'lastvisit' => $user->lastlogin,
        ]);
    }

    /**
     * The root categories the viewer may see, in the header's order with the header's labels, each
     * with its sub-categories the viewer may see: the Category cell's menu and + Add filter's
     * Sub-category list. Unlike the lists' menus it is not probed: a search's scope may name a root
     * that holds no match.
     *
     * @param  list<int>  $exclusions
     * @return array<int, array{label: string, categories: array<int, string>}>
     */
    private static function viewableRoots(array $exclusions): array
    {
        $visible = [];
        foreach (Category::getForMenu($exclusions) as $root) {
            $visible[(int) $root['id']] = array_column($root['categories'], 'title', 'id');
        }
        $roots = [];
        foreach (BrowseRoot::headerOrder() as $root) {
            if (isset($visible[$root->categoryId()])) {
                $roots[(int) $root->categoryId()] = ['label' => $root->label(), 'categories' => $visible[$root->categoryId()]];
            }
        }

        return $roots;
    }
}
