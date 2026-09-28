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
        <div class="home-section-heading"><h2>Following</h2><a href="{{ url('/watchlist') }}">View all <i class="fas fa-arrow-right" aria-hidden="true"></i></a></div>
        @forelse($homeWatched as $release)
            @php($row = $release->row_data)
            <div class="home-watch-row"><div><a class="home-watch-title" href="{{ $row->entity?->titleUrl() ?? route('details', $row->guid) }}">{{ $row->entity?->title ?? $row->name }}</a><a class="home-watch-release" href="{{ route('details', $row->guid) }}">{{ $row->name }}</a></div><span class="account-muted">{{ $row->added }}</span></div>
        @empty
            <p class="account-muted">Follow a movie or show to see its latest release here.</p>
        @endforelse
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
