<section class="card account-card">
    <h2>Password</h2>
    <form method="POST" action="{{ route('account.password') }}" class="account-form">
        @csrf
        <div><x-label for="current_password">Current password</x-label><x-input type="password" id="current_password" name="current_password" autocomplete="current-password" required /></div>
        <div><x-label for="password">New password</x-label><x-input type="password" id="password" name="password" autocomplete="new-password" required minlength="8" /><p class="account-muted">At least 8 characters, with uppercase, lowercase, a number and a symbol.</p></div>
        <div><x-label for="password_confirmation">Confirm new password</x-label><x-input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required /></div>
        <div><x-button type="submit">Update password</x-button></div>
    </form>
</section>
<section class="card account-card">
    <h2>Two-factor authentication <x-badge :type="$user->passwordSecurity?->google2fa_enable ? 'success' : 'warning'">{{ $user->passwordSecurity?->google2fa_enable ? 'Enabled' : 'Not enabled' }}</x-badge></h2>
    @if($user->passwordSecurity?->google2fa_enable)
        <form method="POST" action="{{ route('profileedit.disable2fa') }}" class="account-form">@csrf
            <div><x-label for="disable-password">Current password</x-label><x-input type="password" id="disable-password" name="current-password" autocomplete="current-password" required /></div>
            <div><x-button type="submit" variant="danger">Disable two-factor</x-button></div>
        </form>
        <h3 class="mt-4">Recovery codes</h3>
        <p class="account-muted">Each code signs you in once if you lose access to your authenticator. Generating a new set replaces the previous codes.</p>
        @if(session()->has('recovery_codes'))
            <div class="account-recovery-codes" x-data="copyToClipboard"><x-textarea id="recovery-codes" readonly rows="10" aria-label="Recovery codes">{{ implode("\n", session('recovery_codes')) }}</x-textarea><x-button variant="secondary" size="sm" @click="copy('recovery-codes')" icon="fas fa-copy">Copy codes</x-button><p class="account-muted">These codes are shown only once.</p></div>
        @else
            <p>{{ count($user->passwordSecurity->recovery_codes ?? []) }} unused codes.</p>
        @endif
        <form method="POST" action="{{ route('account.recovery-codes') }}" class="account-form mt-3">@csrf
            <div><x-label for="recovery-password">Current password</x-label><x-input type="password" id="recovery-password" name="current_password" autocomplete="current-password" required /></div>
            <div><x-button type="submit" variant="secondary">Generate recovery codes</x-button></div>
        </form>
    @elseif($user->passwordSecurity)
        <p>Scan this QR code with your authenticator, then enter its code.</p>
        <div class="account-qr">{!! $google2fa_url !!}</div>
        <form method="POST" action="{{ route('profileedit.enable2fa') }}" class="account-form">@csrf
            <div><x-label for="verify-code">Authentication code</x-label><x-input id="verify-code" name="verify-code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required /></div>
            <div><x-button type="submit">Enable two-factor</x-button></div>
        </form>
        <form method="POST" action="{{ route('profileedit.cancel2fa') }}" class="mt-3">@csrf<x-button type="submit" variant="secondary">Cancel setup</x-button></form>
    @else
        <p class="account-muted">Add a code from your authenticator app when signing in.</p>
        <form method="POST" action="{{ route('profileedit.generate2faSecret') }}">@csrf<x-button type="submit">Set up two-factor</x-button></form>
    @endif
</section>
<section class="card account-card"><h2>Passkeys</h2>@include('partials.passkeys-manage')</section>
<section class="card account-card">
    <h2>Sessions</h2><p class="account-muted">Sign out other browsers and clear remembered devices. This browser stays signed in.</p>
    <form method="POST" action="{{ route('account.sessions') }}" class="account-form">@csrf
        <div><x-label for="sessions-password">Current password</x-label><x-input type="password" id="sessions-password" name="current_password" autocomplete="current-password" required /></div>
        <div><x-button type="submit" variant="secondary">Sign out other sessions</x-button></div>
    </form>
</section>
