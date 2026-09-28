<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\MovieFilmWallFilters;
use App\Services\Releases\MovieFilmWall;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The Films wall, GET /movies/films (docs/proposals/movies-redesign/SPEC.md 5A). */
final class MovieFilmsController extends BasePageController
{
    public function index(Request $request, MovieFilmWall $wall): View|RedirectResponse
    {
        $exclusions = array_values(array_map('intval', (array) $this->userdata->categoryexclusions));
        $options = $wall->options();
        $filters = MovieFilmWallFilters::fromRequest($request, $options, $this->userdata->releaseViewPreferences('movies')['films_sort'] ?? null);
        $person = $filters->person === null ? null : $wall->personName($filters->person);
        if ($person === null) {
            $filters = $filters->withoutPerson();
        }
        $total = $wall->count($filters, $exclusions);
        $lastPage = max(1, (int) ceil($total / MovieFilmWallFilters::PER_PAGE));
        if ($filters->page > $lastPage) {
            return redirect()->route('movies.films', $filters->query($lastPage));
        }
        $data = array_merge($this->viewData, [
            'meta_title' => 'Films',
            'filters' => $filters,
            'options' => $options,
            'person' => $person,
            'total' => $total,
            'lastPage' => $lastPage,
            'tiles' => $wall->tiles($wall->pageIds($filters, $exclusions, $total), $filters->films->genres, $exclusions),
        ]);

        return view($request->query('_fragment') === 'list' ? 'movies.films.list' : 'movies.films.index', $data);
    }
}
