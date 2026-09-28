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
                @foreach($title->links as $label => $url)
                    <x-button-link :href="($site['dereferrer_link'] ?? '').$url" variant="secondary" size="sm" target="_blank" rel="noopener noreferrer">{{ $label }} <i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i><span class="sr-only"> (opens in a new tab)</span></x-button-link>
                @endforeach
            </div>
            <dl class="title-metadata">
                @foreach($title->metadata as $label => $value)<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach
            </dl>
            @if($title->overview !== '')<p class="title-plot">{{ $title->overview }}</p>@endif
            @if($title->tracks !== [])<div class="title-tracks"><h2>Tracks</h2><ol>@foreach($title->tracks as $track)<li>{{ $track }}</li>@endforeach</ol></div>@endif
            <dl class="title-stats">
                <div><dt>Releases</dt><dd>{{ number_format($releaseCount) }}</dd></div>
                @if($latestRelease)<div><dt>Latest</dt><dd>{{ userDateDiffForHumans($latestRelease) }}</dd></div>@endif
                @if($title->root === \App\Enums\BrowseRoot::Audio && $bestQuality)<div><dt>Best</dt><dd>{{ $bestQuality }}</dd></div>@endif
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
@endpush
