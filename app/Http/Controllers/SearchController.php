<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\WebSearchState;
use App\Services\Releases\WebReleaseSearch;
use App\Services\Search\Contracts\SearchServiceInterface;
use Illuminate\Http\Request;

class SearchController extends BasePageController
{
    public function __construct(private readonly WebReleaseSearch $releases, private readonly SearchServiceInterface $searchService)
    {
        parent::__construct();
    }

    public function search(Request $request): mixed
    {
        $addingFilter = $request->filled('filter');
        $search = WebSearchState::fromRequest($request, $this->userdata);
        if ($addingFilter) {
            return redirect()->to($search->url());
        }
        $results = $this->releases->paginate($search, $this->userdata);
        if ($search->browser->page > $results->lastPage()) {
            return redirect()->to($search->url(['page' => (string) $results->lastPage()]));
        }
        $words = $search->terms->indexTerms()['all'] ?? '';
        $spellSuggestion = null;
        if ($words !== '' && $results->total() <= 3 && $this->searchService->isSuggestEnabled()) {
            $suggestions = $this->searchService->suggest($words);
            usort($suggestions, static fn (array $a, array $b): int => (int) $b['docs'] <=> (int) $a['docs']);
            $spellSuggestion = ($suggestions[0]['suggest'] ?? $words) !== $words ? $suggestions[0]['suggest'] : null;
        }

        return view('search.index', [...$this->viewData,
            'results' => $results, 'browserState' => $search->browser, 'searchState' => $search,
            'queryChips' => $search->chips(), 'spellSuggestion' => $spellSuggestion,
            'meta_title' => $words === '' ? 'Search results' : 'Results for “'.$words.'”',
            'lastvisit' => $this->userdata->lastlogin,
        ]);
    }
}
