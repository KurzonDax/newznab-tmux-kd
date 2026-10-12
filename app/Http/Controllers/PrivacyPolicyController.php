<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\View\View;

class PrivacyPolicyController extends BasePageController
{
    /**
     * Display the privacy policy page.
     */
    public function privacyPolicy(): View
    {
        $meta_title = config('app.name').' - Privacy Policy';
        // Get privacy policy content from settings (if available)
        $privacy_content = $this->settings->get('privacy_policy') ?? null;

        return view('privacy-policy', compact('meta_title', 'privacy_content'));
    }
}
