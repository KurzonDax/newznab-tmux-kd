<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Support\Facades\Auth;

trait AuthenticatesUsers
{
    use RedirectsUsers, ThrottlesLogins;

    /**
     * Get the guard to be used during authentication.
     */
    protected function guard(): StatefulGuard
    {
        return Auth::guard();
    }
}
