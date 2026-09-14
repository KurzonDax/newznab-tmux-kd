<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BrowseRoot;
use App\Models\RootCategory;
use App\Models\User;
use App\Models\UserDownload;
use App\Models\UserRequest;
use App\Rules\ValidEmailDomain;
use App\Services\Auth\ExpireWebLogins;
use App\Services\Auth\TwoFactorRecoveryCodes;
use App\Services\Gdpr\GdprRetentionService;
use App\Support\PermissionSyncHelper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use PragmaRX\Google2FALaravel\Facade as Google2FA;

final class AccountController extends BasePageController
{
    public function show(Request $request): View
    {
        $section = $request->query('section', 'profile');
        $section = in_array($section, ['profile', 'appearance', 'security', 'api', 'downloads', 'privacy', 'invitations'], true) ? $section : 'profile';
        if ($section === 'privacy') {
            return app(PrivacyCenterController::class)->index(app(GdprRetentionService::class));
        }
        if ($section === 'invitations') {
            return app(InvitationController::class)->index($request);
        }
        $user = $this->userdata;
        $data = ['user' => $user, 'section' => $section, 'meta_title' => 'Account'];
        if ($section === 'security') {
            $user->loadMissing(['passkeys', 'passwordSecurity']);
            $data['google2fa_url'] = $user->passwordSecurity && ! $user->passwordSecurity->google2fa_enable
                ? Google2FA::getQRCodeInline(config('app.name'), $user->email, $user->passwordSecurity->google2fa_secret) : '';
        }
        if ($section === 'appearance' || $section === 'api') {
            $data['roots'] = BrowseRoot::cases();
            $data['categoriesWithSubs'] = RootCategory::with(['categories' => fn ($query) => $query->where('status', 1)->orderBy('title')])->where('status', 1)->orderBy('title')->get();
            $data['userExcludedCategories'] = $user->excludedCategories()->pluck('categories_id')->all();
        }
        if ($section === 'api' || $section === 'downloads') {
            $data['apiRequests'] = UserRequest::getApiRequests($user->id);
            $data['downloads'] = UserDownload::getDownloadRequests($user->id);
            $role = $user->roles->first();
            $data['apiLimit'] = (int) ($role?->getAttribute('apirequests') ?? 0);
            $data['downloadLimit'] = (int) ($role?->getAttribute('downloadrequests') ?? 0);
        }
        if ($section === 'downloads') {
            $data['recentDownloads'] = UserDownload::query()->where('users_id', $user->id)->with('release')->orderByDesc('timestamp')->paginate(20)->withQueryString();
        }

        return view('account.index', [...$this->viewData, ...$data]);
    }

    public function profile(Request $request, ValidEmailDomain $emailDomain): RedirectResponse
    {
        $user = $request->user();
        $values = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:50', 'alpha_dash', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id), ...($user->email !== $request->input('email') ? [$emailDomain] : [])],
            'timezone' => ['required', 'timezone:all'],
        ]);
        $emailChanged = $user->email !== $values['email'];
        $user->fill($values)->save();
        $this->forgetUser($user);
        if ($emailChanged && ! $user->hasRole('Admin')) {
            $user->resetEmailVerification();
            $user->sendEmailVerificationNotification();
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('info', 'Verify your new email address before signing in.');
        }

        return $this->saved('profile');
    }

    public function password(Request $request): RedirectResponse
    {
        $values = $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'regex:/^(?=.*?[A-Z])(?=.*?[a-z])(?=.*?[0-9])(?=.*?[#?!@$%^&*-]).{8,}$/'],
        ]);
        $user = $request->user();
        $user->forceFill(['password' => Hash::make($values['password'])])->save();
        $request->session()->put('password_hash_'.Auth::getDefaultDriver(), $user->password);

        return $this->saved('security', 'Password updated.');
    }

    public function categories(Request $request): RedirectResponse
    {
        $request->validate([
            'excluded_categories' => ['sometimes', 'array'],
            'excluded_categories.*' => ['integer', 'distinct', Rule::exists('categories', 'id')],
            ...array_fill_keys(['viewmovies', 'viewtv', 'viewaudio', 'viewconsole', 'viewpc', 'viewbooks', 'viewadult', 'viewother'], ['sometimes', 'accepted']),
        ]);
        $user = $request->user();
        PermissionSyncHelper::syncUserPermissions($user, $request);
        $user->syncExcludedCategories($request->input('excluded_categories', []));
        Cache::forget(User::categoryExclusionCacheKey($user->id));
        $this->forgetUser($user);

        return $this->saved('appearance');
    }

    public function apiKey(Request $request): RedirectResponse
    {
        User::updateRssKey($request->user()->id);
        $this->forgetUser($request->user());

        return $this->saved('api', 'API key regenerated. Update your apps and RSS feeds.');
    }

    public function sessions(Request $request, ExpireWebLogins $sessions): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'string', 'current_password']]);
        $sessions->expireForUser($request, $request->user());

        return $this->saved('security', 'Other sessions signed out.');
    }

    public function recoveryCodes(Request $request, TwoFactorRecoveryCodes $recovery): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'string', 'current_password']]);

        return $this->saved('security', 'Recovery codes generated. Save them somewhere safe.')
            ->with('recovery_codes', $recovery->generate($request->user()));
    }

    private function saved(string $section, string $message = 'Account changes saved.'): RedirectResponse
    {
        return redirect()->route('account', ['section' => $section])->with('success', $message);
    }

    private function forgetUser(User $user): void
    {
        Cache::forget('composer_user_'.$user->id);
    }
}
