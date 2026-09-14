@extends('layouts.main')
@section('content')
<div class="watchlist-page" x-data="watchlistPage" data-watchlist-root="{{ $root->value }}">
    <x-breadcrumb :items="[['label' => 'Watchlist']]" />
    <x-page-header title="Watchlist">
        <x-slot:actions>
            <x-button-link :href="route('browse', ['parentCategory' => $root->value, 'watching' => 1])" variant="secondary" size="sm" icon="fas fa-list">View releases from these</x-button-link>
            <div x-data="copyToClipboard">
                <input id="watchlist-rss-url" type="text" readonly tabindex="-1" aria-label="Watchlist RSS URL" class="sr-only" value="{{ url('/rss/'.($root === \App\Enums\BrowseRoot::Movies ? 'mymovies' : 'myshows')).'?'.http_build_query(['api_token' => $userdata->api_token]) }}">
                <x-button variant="secondary" size="sm" icon="fas fa-rss" @click="copy('watchlist-rss-url')" aria-label="Copy RSS feed URL">RSS</x-button>
            </div>
        </x-slot:actions>
    </x-page-header>
    <section class="card">
        <div class="watchlist-tabs">
            @foreach(['movies' => 'My Movies', 'tv' => 'My Shows'] as $tab => $label)
                @if($userdata->getDirectPermissions()->contains('name', 'view '.$tab))
                    <a href="{{ route('watchlist', ['tab' => $tab]) }}" @if($root->value === $tab) aria-current="page" @endif>{{ $label }} · <span data-watch-count="{{ $tab }}">{{ $counts[$tab] }}</span></a>
                @endif
            @endforeach
            <form action="{{ route('watchlist') }}" method="GET" @submit.prevent="findTitles">
                <input type="hidden" name="tab" value="{{ $root->value }}">
                <x-input control-size="sm" name="q" type="search" value="{{ $find }}" :placeholder="'Find a '.($root === \App\Enums\BrowseRoot::Movies ? 'movie' : 'show').' to add…'" aria-label="Find a title to add" @input.debounce.300ms="findTitles" />
            </form>
        </div>
        <p class="px-4 text-sm text-red-600 dark:text-red-400" x-text="error" x-show="error" x-cloak role="alert"></p>
        <div data-watchlist-lists x-ref="lists">@include('watchlist.lists')</div>
    </section>
</div>
@endsection
