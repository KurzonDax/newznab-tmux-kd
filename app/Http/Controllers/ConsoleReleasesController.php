<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\ConsoleReleaseFilters;
use App\Enums\BrowseRoot;
use App\Models\Category;
use App\Services\Releases\ConsoleReleaseList;
use App\Services\Releases\ConsoleReleaseRows;
use App\Services\Releases\RememberedListFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Console releases list: GET /console (docs/proposals/books-console-pc-redesign/SPEC.md 5), the
 * Book list's screen with the game: a cover column, the game line, a Genre column and the game's
 * menus (Genre, Year).
 */
final class ConsoleReleasesController extends BasePageController
{
    public function index(Request $request, ConsoleReleaseList $list, ConsoleReleaseRows $rows): View|RedirectResponse
    {
        $exclusions = array_values(array_map('intval', (array) $this->userdata->categoryexclusions));
        $menu = [];
        $genreMenu = $list->genreMenu();
        $sort = $this->userdata->releaseViewPreferences(BrowseRoot::Console->value)['sort'] ?? null;
        // The name search is never remembered, but a bare open's redirect to the remembered filters keeps it.
        $filters = (new RememberedListFilters(BrowseRoot::Console->value, 'console.releases', ConsoleReleaseFilters::KEYS, [ConsoleReleaseFilters::SEARCH]))
            ->open($request, $this->userdata, static function (Request $source) use ($list, $exclusions, $sort, $genreMenu, &$menu): ConsoleReleaseFilters {
                $menu = $list->categoryMenu($exclusions, ConsoleReleaseFilters::chosenCategories($source));

                return ConsoleReleaseFilters::forList($source, array_keys($menu), $sort, $genreMenu);
            });
        if ($filters instanceof RedirectResponse) {
            return $filters;
        }
        $total = $list->count($filters, $exclusions);
        $lastPage = max(1, (int) ceil($total / ConsoleReleaseFilters::PER_PAGE));
        if ($filters->page > $lastPage) {
            return redirect()->route('console.releases', $filters->query($lastPage));
        }
        $genres = $list->genreMenuWith($genreMenu, $filters->genres);
        $data = array_merge($this->viewData, [
            'meta_title' => 'Console releases',
            'heading' => 'Console releases',
            'listRoute' => 'console.releases',
            'preferenceRoot' => BrowseRoot::Console->value,
            'emptyText' => 'There are no console releases yet.',
            'filters' => $filters,
            'matching' => $filters->describe($menu, $genres),
            'categoryMenu' => $menu,
            'excludableOther' => ConsoleReleaseFilters::excludableOther(array_keys($menu), Category::GAME_ROOT),
            'genreMenu' => $genres,
            'total' => $total,
            'lastPage' => $lastPage,
            'rows' => $rows->load($list->pageIds($filters, $exclusions, $total), $filters->sortsByAdded()),
            'nzbLinkBase' => url('/api/v1/api'),
            'apiToken' => (string) $this->userdata->api_token,
            'filtersClock' => RememberedListFilters::clock(),
        ]);

        return view($request->query('_fragment') === 'list' ? 'shelf.releases.list' : 'shelf.releases.index', $data);
    }
}
