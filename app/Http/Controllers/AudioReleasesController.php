<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\AudioReleaseFilters;
use App\Enums\BrowseRoot;
use App\Models\Category;
use App\Services\Releases\AudioReleaseList;
use App\Services\Releases\AudioReleaseRows;
use App\Services\Releases\RememberedListFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Audio releases list: GET /audio (docs/proposals/audio-redesign/SPEC.md 5), the Console
 * list's screen with the music: a square cover column, the music line, a Genre column, the music's
 * menus (Genre, Year) and the Listen chip.
 */
final class AudioReleasesController extends BasePageController
{
    public function index(Request $request, AudioReleaseList $list, AudioReleaseRows $rows): View|RedirectResponse
    {
        $exclusions = array_values(array_map('intval', (array) $this->userdata->categoryexclusions));
        $menu = [];
        $genreMenu = $list->genreMenu();
        $sort = $this->userdata->releaseViewPreferences(BrowseRoot::Audio->value)['sort'] ?? null;
        // The name search is never remembered, but a bare open's redirect to the remembered filters keeps it.
        $filters = (new RememberedListFilters(BrowseRoot::Audio->value, 'audio.releases', AudioReleaseFilters::KEYS, [AudioReleaseFilters::SEARCH]))
            ->open($request, $this->userdata, static function (Request $source) use ($list, $exclusions, $sort, $genreMenu, &$menu): AudioReleaseFilters {
                $menu = $list->categoryMenu($exclusions, AudioReleaseFilters::chosenCategories($source));

                return AudioReleaseFilters::forList($source, array_keys($menu), $sort, $genreMenu);
            });
        if ($filters instanceof RedirectResponse) {
            return $filters;
        }
        $total = $list->count($filters, $exclusions);
        $lastPage = max(1, (int) ceil($total / AudioReleaseFilters::PER_PAGE));
        if ($filters->page > $lastPage) {
            return redirect()->route('audio.releases', $filters->query($lastPage));
        }
        $genres = $list->genreMenuWith($genreMenu, $filters->genres);
        $data = array_merge($this->viewData, [
            'meta_title' => 'Audio releases',
            'heading' => 'Audio releases',
            'listRoute' => 'audio.releases',
            'listKind' => 'audio',
            'preferenceRoot' => BrowseRoot::Audio->value,
            'emptyText' => 'There are no audio releases yet.',
            'filters' => $filters,
            'matching' => $filters->describe($menu, $genres),
            'categoryMenu' => $menu,
            'excludableOther' => AudioReleaseFilters::excludableOther(array_keys($menu), Category::MUSIC_ROOT),
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
