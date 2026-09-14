<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BrowseRoot;
use App\Services\Releases\LegacyCoverRedirect;
use Illuminate\Http\Request;

class MovieController extends BasePageController
{
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
        return redirect()->route('title', [
            ...$request->except(['root', 'id', 'imdbid', '_token']),
            'root' => 'movies', 'id' => preg_replace('/^tt/i', '', $imdbid),
        ]);
    }

    public function showTrending(Request $request): mixed
    {
        return redirect()->route('browse', [
            ...$request->except(['parentCategory', 'id', '_token']),
            'parentCategory' => 'movies', 'view' => 'covers', 'sort' => 'grabs', 'trending' => 1,
        ]);
    }
}
