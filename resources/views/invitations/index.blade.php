@extends('layouts.main')
@section('content')
<x-account-layout section="invitations">
    <section class="card account-card">
        <div class="home-section-heading"><h2>Invitations</h2>@if($invite_mode)<x-button-link :href="route('invitations.create')" size="sm" icon="fas fa-plus">Send invitation</x-button-link>@endif</div>
        @if(!$invite_mode)
            <p class="account-muted">Invitations are currently disabled on this site.</p>
        @else
            <dl class="account-view-preferences">@foreach(['sent' => 'Sent', 'used' => 'Accepted', 'pending' => 'Pending', 'expired' => 'Expired'] as $key => $label)<div><dt>{{ $label }}</dt><dd>{{ $stats[$key] ?? 0 }}</dd></div>@endforeach</dl>
            <nav class="account-invitation-tabs" aria-label="Invitation status">@foreach(['' => 'All', 'pending' => 'Pending', 'used' => 'Accepted', 'expired' => 'Expired'] as $key => $label)<a href="{{ route('account', ['section' => 'invitations', 'status' => $key]) }}" @if(($status ?? '') === $key) aria-current="page" @endif>{{ $label }}</a>@endforeach</nav>
            @forelse($invitations as $invitation)
                <article class="account-invitation">
                    <div class="min-w-0"><h3>{{ $invitation['email'] }}</h3><p class="account-muted">Sent {{ userDate('M j, Y H:i', $invitation['created_at']) }} · Expires {{ userDate('M j, Y H:i', $invitation['expires_at']) }}</p>
                        @if(isset($invitation['used_by_user']))<p class="account-muted">Accepted by {{ $invitation['used_by_user']['username'] ?? $invitation['used_by_user']['email'] }}{{ $invitation['used_at'] ? ' · '.userDate('M j, Y H:i', $invitation['used_at']) : '' }}</p>@endif
                    </div>
                    @if($invitation['used_at'])<x-badge type="success">Accepted</x-badge>@elseif(!$invitation['is_active'])<x-badge type="info">Cancelled</x-badge>@elseif($invitation['expires_at'] < time())<x-badge type="danger">Expired</x-badge>@else<x-badge type="warning">Pending</x-badge>@endif
                    @if(!$invitation['used_at'] && $invitation['expires_at'] > time() && $invitation['is_active'])
                        <div class="flex gap-2"><form method="POST" action="{{ route('invitations.resend', $invitation['id']) }}">@csrf<x-button type="submit" variant="secondary" size="sm" icon="fas fa-paper-plane">Resend</x-button></form>
                        <form method="POST" action="{{ route('invitations.destroy', $invitation['id']) }}" x-data="confirmForm" data-message="Cancel this invitation?" @submit.prevent="submit">@csrf @method('DELETE')<x-button type="submit" variant="danger" size="sm">Cancel</x-button></form></div>
                    @endif
                </article>
            @empty
                <x-empty-state icon="fas fa-envelope" title="No invitations found." message="Send an invitation to invite someone to join." />
            @endforelse
            @if(isset($pagination_links))<div class="mt-4">{!! $pagination_links !!}</div>@endif
        @endif
    </section>
</x-account-layout>
@endsection
