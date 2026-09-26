@extends('layouts.main')
@push('modals')
    @include('partials.release-modals')
@endpush
@section('content')
<div class="home-dashboard">
    <x-breadcrumb :items="[['label' => 'Home']]" />
    <x-page-header title="Home" />
    <section class="home-section">
        <div class="home-section-heading"><h2>Latest releases</h2><a href="{{ route('browse.all') }}">Browse all <i class="fas fa-arrow-right" aria-hidden="true"></i></a></div>
        <x-release-browser :rows="$latest" :state="$latestState" :toolbar="false" :pager="false" empty-title="No releases yet." empty-message="New releases will appear here as they finish processing." />
    </section>
    <section class="card home-section home-watchlist">
        <div class="home-section-heading"><h2>Watchlist</h2><a href="{{ url('/watchlist') }}">View Watchlist <i class="fas fa-arrow-right" aria-hidden="true"></i></a></div>
        @forelse($homeWatched as $release)
            @php($row = $release->row_data)
            <div class="home-watch-row"><div><a class="home-watch-title" href="{{ $row->entity?->titleUrl() ?? route('details', $row->guid) }}">{{ $row->entity?->title ?? $row->name }}</a><a class="home-watch-release" href="{{ route('details', $row->guid) }}">{{ $row->name }}</a></div><span class="account-muted">{{ $row->added }}</span></div>
        @empty
            <p class="account-muted">Follow a movie or show to see its latest release here.</p>
        @endforelse
    </section>
    <section class="home-section home-trending">
        <div class="home-section-heading"><h2>Trending this week</h2><div>@can('view movies')<a href="{{ route('trending-movies') }}">Movies</a>@endcan</div></div>
        @if($homeTrending)
            <x-release-browser :rows="$homeTrending" :state="$trendingState" :toolbar="false" :pager="false" :data-cover-url="route('browse', ['parentCategory' => $trendingState->root->value, 'view' => 'covers', 'sort' => 'grabs'])" empty-title="No trending titles yet." empty-message="Titles downloaded this week will appear here." />
        @else
            <div class="card p-4 account-muted">No trending titles available for your categories.</div>
        @endif
    </section>
    @foreach($content as $item)
        <article class="card home-content surface-prose">
            @if(filled($item->title))<h2>{{ $item->title }}</h2>@endif
            @if(isset($item->body)){!! html_entity_decode(trim($item->body, '\'"')) !!}@endif
            @if(filled($item->metadescription))<p class="account-muted">{{ $item->metadescription }}</p>@endif
        </article>
    @endforeach
</div>
@endsection
