<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\BookPcReleaseFilters;
use App\Enums\BrowseRoot;
use App\Models\Category;
use App\Services\Releases\BookReleaseList;
use App\Services\Releases\PcReleaseList;
use App\Services\Releases\RememberedListFilters;
use App\Services\Releases\ShelfReleaseList;
use App\Services\Releases\ShelfReleaseRows;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Book and PC releases lists: GET /books and GET /pc
 * (docs/proposals/books-console-pc-redesign/SPEC.md 5). PC is the Books screen on PC data.
 */
final class ShelfReleasesController extends BasePageController
{
    public function books(Request $request, BookReleaseList $list, ShelfReleaseRows $rows): View|RedirectResponse
    {
        return $this->bookPcList($request, $list, $rows, BrowseRoot::Books, 'books.releases', Category::BOOKS_ROOT, 'Book releases', 'There are no book releases yet.');
    }

    public function pc(Request $request, PcReleaseList $list, ShelfReleaseRows $rows): View|RedirectResponse
    {
        return $this->bookPcList($request, $list, $rows, BrowseRoot::Games, 'pc.releases', Category::PC_ROOT, 'PC releases', 'There are no PC releases yet.');
    }

    /**
     * @param  ShelfReleaseList<BookPcReleaseFilters>  $list
     * @param  BrowseRoot  $preferenceRoot  the view-preference root the sort and the filters are remembered under
     */
    private function bookPcList(Request $request, ShelfReleaseList $list, ShelfReleaseRows $rows, BrowseRoot $preferenceRoot, string $route, int $root,
        string $heading, string $emptyText): View|RedirectResponse
    {
        $exclusions = array_values(array_map('intval', (array) $this->userdata->categoryexclusions));
        $menu = [];
        $sort = $this->userdata->releaseViewPreferences($preferenceRoot->value)['sort'] ?? null;
        // The name search is never remembered, but a bare open's redirect to the remembered filters keeps it.
        $filters = (new RememberedListFilters($preferenceRoot->value, $route, BookPcReleaseFilters::KEYS, [BookPcReleaseFilters::SEARCH]))->open($request, $this->userdata,
            static function (Request $source) use ($list, $exclusions, $sort, $root, &$menu): BookPcReleaseFilters {
                $menu = $list->categoryMenu($exclusions, BookPcReleaseFilters::chosenCategories($source));

                return BookPcReleaseFilters::forList($source, array_keys($menu), $sort, $root);
            });
        if ($filters instanceof RedirectResponse) {
            return $filters;
        }
        $total = $list->count($filters, $exclusions);
        $lastPage = max(1, (int) ceil($total / BookPcReleaseFilters::PER_PAGE));
        if ($filters->page > $lastPage) {
            return redirect()->route($route, $filters->query($lastPage));
        }
        $data = array_merge($this->viewData, [
            'meta_title' => $heading,
            'heading' => $heading,
            'listRoute' => $route,
            'preferenceRoot' => $preferenceRoot->value,
            'emptyText' => $emptyText,
            'filters' => $filters,
            'matching' => $filters->describe($menu),
            'categoryMenu' => $menu,
            'excludableOther' => BookPcReleaseFilters::excludableOther(array_keys($menu), $root),
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
