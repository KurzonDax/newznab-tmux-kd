<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BrowseRoot;
use App\Services\Releases\LegacyCoverRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GamesController extends BasePageController
{
    public function show(Request $request, string $id = ''): RedirectResponse
    {
        return app(LegacyCoverRedirect::class)->redirect($request, BrowseRoot::Games, $id);
    }
}
