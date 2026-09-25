<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\TvShowFilters;
use App\Services\Releases\TvShowSearch;
use App\Services\Releases\TvShowWall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The TV shows wall, GET /tv/shows, and the TV search, GET /tv/search (docs/proposals/tv-redesign/SPEC.md section 3.2). */
final class TvShowsController extends BasePageController
{
    public function index(Request $request, TvShowWall $wall): View|RedirectResponse
    {
        $exclusions = $this->exclusions();
        $options = $wall->options($exclusions);
        $filters = TvShowFilters::fromRequest($request, $options, $this->userdata->releaseViewPreferences('tv')['shows_sort'] ?? null);
        $person = $filters->person === null ? null : $wall->personName($filters->person);
        if ($person === null) {
            $filters = $filters->withoutPerson();
        }
        $total = $wall->count($filters, $exclusions);
        $lastPage = max(1, (int) ceil($total / TvShowFilters::PER_PAGE));
        if ($filters->page > $lastPage) {
            return redirect()->route('tv.shows', $filters->query($lastPage));
        }
        $data = array_merge($this->viewData, [
            'meta_title' => 'TV shows',
            'filters' => $filters,
            'options' => $options,
            'person' => $person,
            'total' => $total,
            'lastPage' => $lastPage,
            'tiles' => $wall->tiles($wall->pageIds($filters, $exclusions, $total)),
        ]);

        return view($request->query('_fragment') === 'list' ? 'tv.shows.list' : 'tv.shows.index', $data);
    }

    public function search(Request $request, TvShowSearch $search): JsonResponse
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
