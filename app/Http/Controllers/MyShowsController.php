<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class MyShowsController extends BasePageController
{
    public function show(Request $request): mixed
    {
        $action = $this->scalarInput($request, 'action');
        $id = $this->scalarInput($request, 'id');
        if ($action === 'browse') {
            return $this->browse($request);
        }
        if (in_array($action, ['add', 'edit', 'doadd', 'doedit', 'delete'], true) && ctype_digit($id) && (int) $id > 0) {
            return redirect()->route('tv.show', ['videosId' => (int) $id]);
        }

        return redirect()->route('watchlist', ['tab' => 'tv']);
    }

    public function browse(Request $request): mixed
    {
        return redirect()->route('browse', [
            ...$request->except(['parentCategory', 'id', 'watching']),
            'parentCategory' => 'tv', 'watching' => 1,
        ]);
    }
}
