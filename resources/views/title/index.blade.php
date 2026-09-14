@extends('layouts.main')

@section('content')
<div class="title-overview" x-data="titleOverview">
    <nav class="title-breadcrumb" aria-label="Breadcrumb"><a href="{{ url('/browse/'.$title->root->value) }}">{{ $title->root->label() }}</a> <span aria-hidden="true">›</span> {{ $title->entity->title }}</nav>
    <section class="card title-hero">
        <div class="title-artwork" data-root="{{ $title->root->value }}" data-has-art="{{ $title->entity->artwork ? '1' : '0' }}">
            @if($title->entity->artwork)<img src="{{ $title->entity->artwork }}" alt="" x-on:error="artworkFailed">@endif
            <div class="title-artwork-placeholder" @if(!$title->entity->artwork) data-no-artwork @endif><i class="{{ $title->root->icon() }}" aria-hidden="true"></i><span>{{ $title->entity->title }}</span></div>
        </div>
        <div class="title-body">
            <h1>{{ $title->entity->title }} @if($title->subtitle !== '')<span>{{ $title->subtitle }}</span>@endif</h1>
            <div class="title-actions">
                @if($watchUrl)
                    <x-watch-button :root="$title->root->value" :id="$title->entity->id" :title="$title->entity->title" :watched="$watched" />
                @endif
                @foreach($title->links as $label => $url)
                    <x-button-link :href="($site['dereferrer_link'] ?? '').$url" variant="secondary" size="sm" target="_blank" rel="noopener noreferrer">{{ $label }} <i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i></x-button-link>
                @endforeach
                @if($title->trailerUrl)<x-button variant="secondary" size="sm" icon="fas fa-play" :data-trailer-url="$title->trailerUrl">Trailer</x-button>@endif
            </div>
            @if($watchUrl)
                <div class="title-followed text-muted" data-watch-summary="{{ $title->root->value }}:{{ $title->entity->id }}" @if(!$watched) hidden @endif>On your {{ $title->root === \App\Enums\BrowseRoot::Movies ? 'My Movies' : 'My Shows' }} for:
                    <span data-watch-categories>@foreach($watchCategories as $watchCategory)<span class="title-watched-category">{{ $watchCategory }}</span>@endforeach</span>
                    <x-watch-button :root="$title->root->value" :id="$title->entity->id" :title="$title->entity->title" :watched="$watched" label="Edit" />
                    <x-watch-button :root="$title->root->value" :id="$title->entity->id" :title="$title->entity->title" :watched="$watched" :remove="true" label="Remove" />
                </div>
            @endif
            <dl class="title-metadata">
                @foreach($title->metadata as $label => $value)@continue($label === 'Cast')<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach
                @if($title->root === \App\Enums\BrowseRoot::Tv && count($seasons) > 0)<div><dt>Seasons</dt><dd>{{ count(array_filter($seasons, fn ($season) => $season['number'] > 0)) }}</dd></div>@endif
            </dl>
            @if($title->overview !== '')<p class="title-plot">{{ $title->overview }}</p>@endif
            @if(!empty($title->metadata['Cast']))<dl class="title-cast"><div><dt>Cast</dt><dd>{{ $title->metadata['Cast'] }}</dd></div></dl>@endif
            @if($title->tracks !== [])<div class="title-tracks"><h2>Tracks</h2><ol>@foreach($title->tracks as $track)<li>{{ $track }}</li>@endforeach</ol></div>@endif
            <dl class="title-stats">
                <div><dt>Releases</dt><dd>{{ number_format($releaseCount) }}</dd></div>
                @if($latestRelease)<div><dt>Latest</dt><dd>{{ userDateDiffForHumans($latestRelease) }}</dd></div>@endif
                @if($title->root === \App\Enums\BrowseRoot::Tv)<div><dt>Season packs</dt><dd>{{ $seasonPackCount }}</dd></div>
                @elseif(in_array($title->root, [\App\Enums\BrowseRoot::Movies, \App\Enums\BrowseRoot::Audio], true) && $bestQuality)<div><dt>Best</dt><dd>{{ $bestQuality }}</dd></div>@endif
            </dl>
        </div>
    </section>
    <h2 class="title-releases-heading">Releases</h2>
    <p class="text-muted text-sm" x-show="loading" x-cloak role="status">Loading releases…</p>
    <p class="text-red-600 dark:text-red-400 text-sm" x-show="error" x-cloak role="alert" x-text="error"></p>
    <div x-ref="releases" x-on:click="navigateReleases">
        @include('title.partials.releases')
    </div>
</div>
@endsection

@push('modals')
    @include('partials.release-modals')
    @if($title->trailerUrl)@include('partials.trailer-modal')@endif
@endpush
