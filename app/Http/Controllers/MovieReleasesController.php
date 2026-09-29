<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\MovieReleaseFilters;
use App\Data\MovieReleaseRow;
use App\Models\Category;
use App\Services\Releases\MovieFilmSearch;
use App\Services\Releases\MovieReleaseList;
use App\Services\Releases\MovieReleaseRows;
use App\Services\Releases\ReleaseBatches;
use App\Services\Releases\RememberedListFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The Movie releases screen: GET /movies and its search, GET /movies/search (docs/proposals/movies-redesign/SPEC.md 5). */
final class MovieReleasesController extends BasePageController
{
    public function index(Request $request, MovieReleaseList $list, MovieReleaseRows $rows): View|RedirectResponse
    {
        $exclusions = $this->exclusions();
        $menu = $list->categoryMenu($exclusions);
        $audioMenu = $list->audioMenu();
        $filmOptions = $list->filmOptions();
        $sort = $this->userdata->releaseViewPreferences('movies')['sort'] ?? null;
        $filters = (new RememberedListFilters('movies', 'movies.releases', MovieReleaseFilters::KEYS))->open($request, $this->userdata,
            static fn (Request $source): MovieReleaseFilters => MovieReleaseFilters::forList($source, array_keys($menu), $sort, array_keys($audioMenu), $filmOptions));
        if ($filters instanceof RedirectResponse) {
            return $filters;
        }
        $total = $list->count($filters, $exclusions);
        $lastPage = max(1, (int) ceil($total / MovieReleaseFilters::PER_PAGE));
        if ($filters->page > $lastPage) {
            return redirect()->route('movies.releases', $filters->query($lastPage));
        }
        $data = array_merge($this->viewData, [
            'meta_title' => 'Movie releases',
            'filters' => $filters,
            'categoryMenu' => $menu,
            'excludableOther' => MovieReleaseFilters::excludableOther(array_keys($menu), Category::MOVIE_ROOT),
            'audioMenu' => $audioMenu,
            'filmOptions' => $filmOptions,
            'total' => $total,
            'lastPage' => $lastPage,
            'runs' => ReleaseBatches::group($rows->load($list->pageIds($filters, $exclusions, $total), $filters->sortsByAdded()),
                static fn (MovieReleaseRow $row): ?int => $row->filmId, static fn (MovieReleaseRow $row): string => $row->filmTitle),
            'nzbLinkBase' => url('/api/v1/api'),
            'apiToken' => (string) $this->userdata->api_token,
            'filtersClock' => RememberedListFilters::clock(),
        ]);

        return view($request->query('_fragment') === 'list' ? 'movies.releases.list' : 'movies.releases.index', $data);
    }

    public function search(Request $request, MovieFilmSearch $search): JsonResponse
    {
        $text = $request->query('q');

        return response()->json($search->find(is_string($text) ? $text : '', $this->exclusions()));
    }

    /** @return list<int> */
    private function exclusions(): array
    {
        return array_values(array_map('intval', (array) $this->userdata->categoryexclusions));
    }
}
