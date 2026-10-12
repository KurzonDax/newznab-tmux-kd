<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\View\View;

class TermsController extends BasePageController
{
    /**
     * Display the terms and conditions page.
     */
    public function terms(): View
    {
        $meta_title = config('app.name').' - Terms and conditions';

        return view('terms', compact('meta_title'));
    }
}
