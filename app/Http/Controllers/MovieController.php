<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BrowseRoot;
use App\Services\MovieBrowseService;
use App\Services\MovieService;
use App\Services\Releases\LegacyCoverRedirect;
use Illuminate\Http\Request;

class MovieController extends BasePageController
{
    protected MovieBrowseService $movieBrowseService;

    protected MovieService $movieService;

    public function __construct(MovieBrowseService $movieBrowseService, MovieService $movieService)
    {
        parent::__construct();
        $this->movieBrowseService = $movieBrowseService;
        $this->movieService = $movieService;
    }

    /**
     * @throws \Exception
     */
    public function showMovies(Request $request, string $id = ''): mixed
    {
        if ($request->filled('imdb') && is_string($request->input('imdb'))) {
            return redirect()->route('movie.view', $request->input('imdb'));
        }

        return app(LegacyCoverRedirect::class)->redirect($request, BrowseRoot::Movies, $id);
    }

    /**
     * Show a single movie with all its releases
     *
     * @throws \Exception
     */
    public function showMovie(Request $request, string $imdbid): mixed
    {
        // Get movie info
        $movieInfo = $this->movieService->getMovieInfo($imdbid);

        if (! $movieInfo) {
            return redirect()->route('Movies')->with('error', 'Movie not found');
        }

        // Convert Eloquent model to array
        $movieArray = $movieInfo->toArray();

        // Ensure we have at least the basic fields
        if (empty($movieArray['title'])) {
            $movieArray['title'] = 'Unknown Title';
        }
        if (empty($movieArray['imdbid'])) {
            $movieArray['imdbid'] = $imdbid;
        }

        // Only process fields if they exist and are not empty
        if (! empty($movieArray['genre'])) {
            $movieArray['genre'] = makeFieldLinks($movieArray, 'genre', 'movies');
        }
        if (! empty($movieArray['actors'])) {
            $movieArray['actors'] = makeFieldLinks($movieArray, 'actors', 'movies');
        }
        if (! empty($movieArray['director'])) {
            $movieArray['director'] = makeFieldLinks($movieArray, 'director', 'movies');
        }

        // Add cover image URL using helper function
        $movieArray['cover'] = getReleaseCover($movieArray);

        // Get all releases for this movie directly (no limit)
        $releases = $this->movieBrowseService->getMovieReleases($imdbid, (array) $this->userdata->categoryexclusions);

        $this->viewData = array_merge($this->viewData, [
            'movie' => $movieArray,
            'releases' => $releases,
            'meta_title' => ($movieArray['title'] ?? 'Movie').' - Movie Details',
            'meta_keywords' => 'movie,details,releases',
            'meta_description' => 'View all releases for '.($movieArray['title'] ?? 'this movie'),
        ]);

        return view('movies.viewmoviefull', $this->viewData);
    }

    public function showTrending(Request $request): mixed
    {
        return redirect()->route('browse', [
            ...$request->except(['parentCategory', 'id', '_token']),
            'parentCategory' => 'movies', 'view' => 'covers', 'sort' => 'grabs',
        ]);
    }
}
