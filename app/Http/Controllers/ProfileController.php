<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateThemeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class ProfileController extends BasePageController
{
    public function show(Request $request): RedirectResponse
    {
        return redirect()->route('account');
    }

    public function edit(Request $request): RedirectResponse
    {
        return redirect()->route('account', $request->input('action') === 'newapikey' ? ['section' => 'api'] : []);
    }

    public function destroy(Request $request): RedirectResponse
    {
        return redirect()->route('account', ['section' => 'privacy']);
    }

    public function updateTheme(UpdateThemeRequest $request): JsonResponse
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'User not authenticated'], 401);
        }

        if ($request->has('theme_preference')) {
            $user->theme_preference = $request->input('theme_preference');
        }
        $user->save();

        // Keep the composer's cached per-user data in sync with theme changes
        Cache::forget('composer_user_'.$user->id);

        return response()->json([
            'success' => true,
            'theme_preference' => $user->theme_preference,
        ]);
    }
}
