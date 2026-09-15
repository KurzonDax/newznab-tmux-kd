<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Models\User;
use App\Support\SiteViewSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AdminDataComposer
{
    /**
     * Bind lightweight admin data to the view.
     */
    public function compose(View $view): void
    {
        $user = Auth::user();
        $isNntmuxUser = $user instanceof User;

        $view->with([
            'serverroot' => url('/'),
            'site' => app(SiteViewSettings::class)->converted(),
            'userdata' => $user,
            'loggedin' => $user !== null,
            'isadmin' => $isNntmuxUser && $user->hasRole('Admin'),
            'ismod' => $isNntmuxUser && $user->hasRole('Moderator'),
            'userTheme' => $isNntmuxUser ? ($user->theme_preference ?? 'light') : 'light',
            'userColorScheme' => $isNntmuxUser ? ($user->color_scheme ?? 'blue') : 'blue',
        ]);
    }
}
