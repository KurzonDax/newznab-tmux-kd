@extends('layouts.guest')
@section('content')
<main class="auth-page min-h-dvh flex items-center justify-center px-4 py-10">
    <div class="w-full max-w-md">
        <h1 class="text-center font-semibold mb-6">Invitation to {{ $site['title'] }}</h1>
        <section class="auth-card auth-card-body rounded-xl space-y-4">
            @if($preview)
                <h2>You've been invited</h2>
                <p><strong>{{ $preview['invited_by'] ?? 'Someone' }}</strong> has invited you to join {{ $site['title'] }}.</p>
                <dl class="account-readonly"><dt>Email</dt><dd>{{ $preview['email'] ?? 'Not specified' }}</dd><dt>Expires</dt><dd>{{ isset($preview['expires_at']) ? date('M j, Y H:i', $preview['expires_at']) : 'Not specified' }}</dd>
                    @if(isset($preview['role_name']))<dt>Role</dt><dd>{{ $preview['role_name'] }}</dd>@endif
                </dl>
                @if($preview['is_used'])
                    <p>This invitation has already been used.</p>
                    <x-button-link :href="route('login')">Sign in</x-button-link>
                @elseif($preview['expires_at'] < time())
                    <p>This invitation has expired. Ask the person who invited you for a new invitation.</p>
                    <x-button-link :href="route('contact-us')" variant="secondary">Contact support</x-button-link>
                @elseif($registrationStatus['is_closed'])
                    <p>Registrations are currently closed. {{ $registrationStatus['message'] }}</p>
                @else
                    <p>Create your account, then verify your email address to get started.</p>
                    <x-button-link :href="route('register', ['token' => $token])" icon="fas fa-user-plus">Accept invitation</x-button-link>
                    <p class="account-muted">Already have an account? <a class="text-primary-600 dark:text-primary-400" href="{{ route('login') }}">Sign in</a></p>
                @endif
            @else
                <h2>Invalid invitation</h2>
                <p>This invitation link is not valid, has expired, or has been removed.</p>
                <x-button-link :href="route('contact-us')" variant="secondary">Contact support</x-button-link>
                @if($registrationStatus['is_open'])<x-button-link :href="route('register')">Register</x-button-link>@endif
            @endif
        </section>
    </div>
</main>
@endsection
