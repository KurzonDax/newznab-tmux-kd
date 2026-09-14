<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateReleaseViewRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class ReleaseViewPreferencesController extends Controller
{
    public function __invoke(UpdateReleaseViewRequest $request): JsonResponse
    {
        $root = $request->string('root')->toString();
        $preferences = DB::transaction(function () use ($request, $root): array {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $preferences = array_replace($user->releaseViewPreferences($root), $request->preferences());
            $stored = $user->view_prefs ?? [];
            $stored[$root] = $preferences;
            $user->view_prefs = $stored;
            $user->save();

            return $preferences;
        });
        Cache::forget('composer_user_'.$request->user()->id);

        return response()->json(['success' => true, 'preferences' => $preferences]);
    }
}
