<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BrowseRoot;
use App\Services\Releases\LegacyCoverRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BooksController extends BasePageController
{
    public function index(Request $request, string $id = ''): RedirectResponse
    {
        return app(LegacyCoverRedirect::class)->redirect($request, BrowseRoot::Books, $id);
    }
}
