<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Releases\TvShowDirectory;
use Illuminate\Http\Request;

class SeriesController extends BasePageController
{
    public function index(Request $request, string $id = ''): mixed
    {
        if ($id !== '' && ctype_digit($id)) {
            $parameters = $request->except(['root', 'id', '_token']);
            if (($parameters['_fragment'] ?? null) === 'season') {
                $parameters['_fragment'] = 'releases';
            }

            return redirect()->route('title', [...$parameters, 'root' => 'tv', 'id' => $id]);
        }

        abort_if($id !== '' && preg_match('/^(0-9|[A-Z])$/i', $id) !== 1, 404);
        $directory = app(TvShowDirectory::class);
        if ($request->has('_fragment')) {
            $data = $directory->show($request, $this->userdata, $request->integer('show'));

            return view($request->input('_fragment') === 'show' ? 'series.dialog' : 'series.list', $data);
        }

        return view('series.index', [...$this->viewData, ...$directory->directory($request, $this->userdata, $id), 'meta_title' => 'TV Shows']);
    }

    public function showTrending(Request $request): mixed
    {
        return redirect()->route('browse', [
            ...$request->except(['parentCategory', 'id', '_token']),
            'parentCategory' => 'tv', 'view' => 'covers', 'sort' => 'grabs', 'trending' => 1,
        ]);
    }
}
