<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\AdultReleaseFilters;
use App\Models\Category;
use App\Services\Releases\AdultReleaseList;
use App\Services\Releases\AdultReleaseRows;
use App\Services\Releases\RememberedListFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The Adult releases screen: GET /adult (docs/proposals/adult-redesign/SPEC.md 5). */
final class AdultReleasesController extends BasePageController
{
    public function index(Request $request, AdultReleaseList $list, AdultReleaseRows $rows): View|RedirectResponse
    {
        $exclusions = array_values(array_map('intval', (array) $this->userdata->categoryexclusions));
        $menu = [];
        $audioMenu = $list->audioMenu();
        $sort = $this->userdata->releaseViewPreferences('xxx')['sort'] ?? null;
        // The name search is never remembered, but a bare open's redirect to the remembered filters keeps it.
        $filters = (new RememberedListFilters('xxx', 'adult.releases', AdultReleaseFilters::KEYS, [AdultReleaseFilters::SEARCH]))->open($request, $this->userdata,
            static function (Request $source) use ($list, $exclusions, $sort, $audioMenu, &$menu): AdultReleaseFilters {
                $menu = $list->categoryMenu($exclusions, AdultReleaseFilters::chosenCategories($source));

                return AdultReleaseFilters::forList($source, array_keys($menu), $sort, array_keys($audioMenu));
            });
        if ($filters instanceof RedirectResponse) {
            return $filters;
        }
        $total = $list->count($filters, $exclusions);
        $lastPage = max(1, (int) ceil($total / AdultReleaseFilters::PER_PAGE));
        if ($filters->page > $lastPage) {
            return redirect()->route('adult.releases', $filters->query($lastPage));
        }
        $data = array_merge($this->viewData, [
            'meta_title' => 'Adult releases',
            'filters' => $filters,
            'categoryMenu' => $menu,
            'excludableOther' => AdultReleaseFilters::excludableOther(array_keys($menu), Category::XXX_ROOT),
            'audioMenu' => $audioMenu,
            'total' => $total,
            'lastPage' => $lastPage,
            'rows' => $rows->load($list->pageIds($filters, $exclusions, $total), $filters->sortsByAdded()),
            'nzbLinkBase' => url('/api/v1/api'),
            'apiToken' => (string) $this->userdata->api_token,
            'filtersClock' => RememberedListFilters::clock(),
        ]);

        return view($request->query('_fragment') === 'list' ? 'adult.releases.list' : 'adult.releases.index', $data);
    }
}
