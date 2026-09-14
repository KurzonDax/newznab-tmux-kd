<section class="card account-card">
    <h2>Profile</h2>
    <form method="POST" action="{{ route('account.profile') }}" class="account-form">
        @csrf
        <div><x-label for="username">Username</x-label><x-input id="username" name="username" :value="old('username', $user->username)" required autocomplete="username" maxlength="50" /></div>
        <div><x-label for="email">Email</x-label><x-input type="email" id="email" name="email" :value="old('email', $user->email)" required autocomplete="email" /></div>
        <div><x-label for="timezone">Timezone</x-label><x-select id="timezone" name="timezone">
            @foreach(getAvailableTimezones() as $region => $zones)
                <optgroup label="{{ $region }}">@foreach($zones as $zone)<option value="{{ $zone }}" @selected(old('timezone', $user->timezone ?? 'UTC') === $zone)>{{ str_replace('_', ' ', $zone) }}</option>@endforeach</optgroup>
            @endforeach
            <option value="UTC" @selected(old('timezone', $user->timezone ?? 'UTC') === 'UTC')>UTC</option>
        </x-select></div>
        <dl class="account-facts"><div><dt>Role</dt><dd>{{ $user->roles->pluck('name')->join(', ') }}</dd></div><div><dt>Member since</dt><dd>{{ $user->created_at?->format('M j, Y') ?? '—' }}</dd></div></dl>
        <div><x-button type="submit">Save profile</x-button></div>
    </form>
</section>
<section class="card account-card">
    <h2>Avatar</h2>
    <div class="flex items-center gap-4"><img class="h-16 w-16 rounded-full" src="https://www.gravatar.com/avatar/{{ md5(strtolower(trim($user->email))) }}?s=128&amp;d=mp" alt="Your avatar" width="64" height="64"><p>Your avatar uses your email address. <a href="https://gravatar.com" target="_blank" rel="noopener noreferrer">Change it on Gravatar <i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i></a></p></div>
</section>
