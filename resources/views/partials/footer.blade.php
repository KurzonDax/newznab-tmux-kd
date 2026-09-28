<div class="container mx-auto px-4 py-3">
    <nav class="public-footer-links" aria-label="Site links">
        <a href="{{ url('/forum') }}">Forum</a>
        <a href="{{ route('apihelp') }}">API</a>
        <a href="{{ route('apiv2help') }}">API v2</a>
        <a href="{{ route('rsshelp') }}">RSS feeds</a>
        <a href="{{ route('status') }}">Status</a>
        @if($isadmin)<a href="{{ url('/admin') }}">Admin</a>@endif
        @auth
            @php($userRole = auth()->user()->roles->first()?->name ?? 'user')
            @if($userRole !== 'Admin')
                <a href="https://simplegate.space/apps/3MjgKvosMZtc2sSxiRBwadDCn1zA/pos" target="_blank" rel="noopener noreferrer">{{ $userRole === 'User' ? 'Upgrade Your Account' : 'Extend Your Account' }}<span class="sr-only"> (opens in a new tab)</span></a>
            @endif
        @endauth
    </nav>
    @if($usefulLinks->isNotEmpty())
        <details class="public-footer-useful">
            <summary>Useful Links</summary>
            @foreach($usefulLinks as $link)
                <div class="public-footer-useful-entry">
                    @if($link->url)
                        <a href="{{ $link->resolved_url }}" @if($link->is_external_url) target="_blank" rel="noopener noreferrer" @endif>{{ $link->title }}@if($link->is_external_url)<span class="sr-only"> (opens in a new tab)</span>@endif</a>
                    @else
                        <span>{{ $link->title }}</span>
                    @endif
                    @if($link->body)
                        <div class="useful-link-content">{!! html_entity_decode(trim($link->body, '\'"')) !!}</div>
                    @endif
                </div>
            @endforeach
        </details>
    @endif
    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
        <p class="text-(--text-muted) dark:text-(--text-muted-dark)">
            &copy; {{ now()->year }}
            <a href="https://github.com/NNTmux/newznab-tmux" target="_blank" rel="noopener noreferrer" class="text-primary-700 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-300 transition">NNTmux<span class="sr-only"> (opens in a new tab)</span></a>
        </p>

        <div class="flex items-center gap-4">
            <a href="{{ url('/terms-and-conditions') }}" class="text-(--text-muted) dark:text-(--text-muted-dark) hover:text-(--text-default) dark:hover:text-(--text-default-dark) transition">Terms</a>
            <a href="{{ url('/privacy-policy') }}" class="text-(--text-muted) dark:text-(--text-muted-dark) hover:text-(--text-default) dark:hover:text-(--text-default-dark) transition">Privacy</a>
            <a href="{{ route('contact-us') }}" class="text-(--text-muted) dark:text-(--text-muted-dark) hover:text-(--text-default) dark:hover:text-(--text-default-dark) transition">Contact</a>
        </div>

        <div class="flex items-center gap-3">
            <a href="https://github.com/NNTmux/newznab-tmux" target="_blank" rel="noopener noreferrer" class="text-(--text-muted) dark:text-(--text-muted-dark) hover:text-(--text-default) dark:hover:text-(--text-default-dark) transition" title="GitHub">
                <i class="fab fa-github text-lg" aria-hidden="true"></i><span class="sr-only">GitHub (opens in a new tab)</span>
            </a>
            <a href="{{ url('/rss') }}" class="text-(--text-muted) dark:text-(--text-muted-dark) hover:text-(--text-default) dark:hover:text-(--text-default-dark) transition" title="RSS Feeds">
                <i class="fas fa-rss text-lg"></i>
            </a>
        </div>
    </div>
</div>

