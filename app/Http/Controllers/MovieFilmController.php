<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\MovieFilmPageFilters;
use App\Services\Releases\MovieFilmPage;
use App\Services\Releases\MovieReleaseRows;
use App\Services\Releases\MovieSimilarFilms;
use App\Support\TitlePageBackLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The film page: GET /movies/film/{movieinfo.id} (docs/proposals/movies-redesign/SPEC.md 5B). */
final class MovieFilmController extends BasePageController
{
    /** Where the back link leads, kept while the user moves between film pages. */
    private const BACK_KEY = 'movies_film_back';

    public function show(Request $request, MovieFilmPage $page, MovieReleaseRows $rows, MovieSimilarFilms $similar, string $movieinfoId): View|RedirectResponse
    {
        $id = (int) $movieinfoId;
        $exclusions = array_values(array_map('intval', (array) $this->userdata->categoryexclusions));
        $film = $page->header($id, $exclusions);
        abort_if($film === null, 404);
        $filters = MovieFilmPageFilters::fromRequest($request);
        $total = $filters->any() ? $page->count($id, $filters, $exclusions) : $film->releases;
        $lastPage = max(1, (int) ceil($total / MovieFilmPageFilters::PER_PAGE));
        if ($filters->page > $lastPage) {
            return redirect()->to(route('movies.film', ['movieinfoId' => $id, ...$filters->query($lastPage)]).'#releases');
        }
        $data = array_merge($this->viewData, [
            'meta_title' => $film->title,
            'film' => $film,
            'filters' => $filters,
            'total' => $total,
            'lastPage' => $lastPage,
            'rows' => $rows->load($page->pageIds($id, $filters, $exclusions), false),
            'nzbLinkBase' => url('/api/v1/api'),
            'apiToken' => (string) $this->userdata->api_token,
        ]);
        if ($request->query('_fragment') === 'list') {
            return view('movies.film.list', $data);
        }

        return view('movies.film.index', [
            ...$data,
            'back' => TitlePageBackLink::resolve($request, self::BACK_KEY,
                ['Movie releases' => route('movies.releases'), 'Films' => route('movies.films')], url('/movies/film')),
            'followed' => $page->followed($film->imdbId, (int) $this->userdata->id),
            'similar' => $similar->tiles($id, $film->yearNumber(), $exclusions),
        ]);
    }
}
