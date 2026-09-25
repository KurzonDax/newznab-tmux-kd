<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\TvReleaseFilters;
use App\Models\Category;
use App\Services\Releases\TvReleaseBatches;
use App\Services\Releases\TvReleaseList;
use App\Services\Releases\TvReleaseRows;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The TV releases screen: GET /tv (docs/proposals/tv-redesign/SPEC.md section 3.1). */
final class TvReleasesController extends BasePageController
{
    public function index(Request $request, TvReleaseList $list, TvReleaseRows $rows): View|RedirectResponse
    {
        $exclusions = array_map('intval', (array) $this->userdata->categoryexclusions);
        $menu = self::categoryMenu($exclusions);
        $filters = TvReleaseFilters::fromRequest($request, array_keys($menu), $this->userdata->releaseViewPreferences('tv')['sort'] ?? null);
        $total = $list->count($filters, $exclusions);
        $lastPage = max(1, (int) ceil($total / TvReleaseFilters::PER_PAGE));
        if ($filters->page > $lastPage) {
            return redirect()->route('tv.releases', $filters->query($lastPage));
        }
        $data = array_merge($this->viewData, [
            'meta_title' => 'TV releases',
            'filters' => $filters,
            'categoryMenu' => $menu,
            'total' => $total,
            'lastPage' => $lastPage,
            'runs' => TvReleaseBatches::group($rows->load($list->pageIds($filters, $exclusions, $total), $filters->sortsByAdded())),
            'nzbLinkBase' => url('/api/v1/api'),
            'apiToken' => (string) $this->userdata->api_token,
        ]);

        return view($request->query('_fragment') === 'list' ? 'tv.releases.list' : 'tv.releases.index', $data);
    }

    /**
     * Every TV sub-category the user may see, whether or not it holds releases.
     *
     * @param  list<int>  $exclusions
     * @return array<int, string> id => title, in menu order
     */
    public static function categoryMenu(array $exclusions): array
    {
        foreach (Category::getForMenu($exclusions) as $root) {
            if ((int) $root['id'] === Category::TV_ROOT) {
                return array_column($root['categories'], 'title', 'id');
            }
        }

        return [];
    }
}
