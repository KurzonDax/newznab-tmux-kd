<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BrowseRoot;
use App\Services\Releases\LegacyCoverRedirect;
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

        return app(LegacyCoverRedirect::class)->redirect($request, BrowseRoot::Tv, $id);
    }

    public function showTrending(Request $request): mixed
    {
        return redirect()->route('browse', [
            ...$request->except(['parentCategory', 'id', '_token']),
            'parentCategory' => 'tv', 'view' => 'covers', 'sort' => 'grabs',
        ]);
    }
}
