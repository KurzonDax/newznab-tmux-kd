<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class MyMoviesController extends BasePageController
{
    public function show(Request $request): mixed
    {
        $action = $this->scalarInput($request, 'id');
        $id = $this->scalarInput($request, 'imdb');
        if ($action === 'browse') {
            return $this->browse($request);
        }
        if (in_array($action, ['add', 'edit', 'doadd', 'doedit', 'delete'], true) && ctype_digit($id) && (int) $id > 0) {
            return redirect()->route('title', ['root' => 'movies', 'id' => $id, 'watch' => 1]);
        }

        return redirect()->route('watchlist', ['tab' => 'movies']);
    }

    public function browse(Request $request): mixed
    {
        return redirect()->route('browse', [
            ...$request->except(['parentCategory', 'id', 'watching']),
            'parentCategory' => 'movies', 'watching' => 1,
        ]);
    }
}
