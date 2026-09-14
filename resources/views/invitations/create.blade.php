@extends('layouts.main')
@section('content')
<x-account-layout section="invitations">
    <section class="card account-card">
        <div class="home-section-heading"><h2>Send invitation</h2><a href="{{ route('account', ['section' => 'invitations']) }}">Back to invitations</a></div>
        @if(!$invite_mode)<p class="account-muted">Invitations are currently disabled on this site.</p>
        @elseif(!$can_send_invites)<p class="account-muted">You have no invitations available.</p>
        @else
            <p class="account-muted">{{ $user_invites_left }} of {{ $user_invites_total }} invitations available · {{ $user_invites_pending }} pending.</p>
            <form method="POST" action="{{ route('invitations.store') }}" class="account-form mt-4">@csrf
                <div><x-label for="email">Email address</x-label><x-input type="email" name="email" id="email" :value="old('email')" required autocomplete="email" /></div>
                <div><x-label for="expiry_days">Expires after</x-label><x-select name="expiry_days" id="expiry_days">@foreach([1, 3, 7, 14, 30] as $days)<option value="{{ $days }}" @selected((int) old('expiry_days', 7) === $days)>{{ $days }} {{ $days === 1 ? 'day' : 'days' }}</option>@endforeach</x-select></div>
                @if(!empty($user_roles))<div><x-label for="role">Role</x-label><x-select name="role" id="role"><option value="">System default</option>@foreach($user_roles as $roleId => $roleName)<option value="{{ $roleId }}" @selected((string) old('role') === (string) $roleId)>{{ $roleName }}</option>@endforeach</x-select></div>@endif
                <p class="account-muted">The recipient will receive an email with a secure invitation link. You can track or cancel it from Invitations.</p>
                <div><x-button type="submit" icon="fas fa-paper-plane">Send invitation</x-button></div>
            </form>
        @endif
    </section>
</x-account-layout>
@endsection
