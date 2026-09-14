@extends('layouts.main')

@section('content')
@php
    $row = $release->row_data;
    $entity = $row->entity;
    $root = \App\Enums\BrowseRoot::fromCategoryId((int) $release->categories_id);
    $commentCount = isset($comments) ? $comments->total() : $row->comments;
@endphp
<div class="release-detail-page" x-data="releaseDetails" x-on:click="retryTab" x-on:error.capture="artworkFailed" data-guid="{{ $row->guid }}" data-release-id="{{ $row->id }}">
    <nav class="title-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ url('/browse/'.$root->value) }}">{{ $root->label() }}</a><span aria-hidden="true">›</span>
        @if($entity?->titleUrl())<a href="{{ $entity->titleUrl() }}">{{ $entity->title }}</a><span aria-hidden="true">›</span>@endif
        <span>{{ $release->sub_category ?? $row->category }}</span>
    </nav>
    @include('details.partials.header')
    <div class="details-columns">
        <div class="details-main">
            <nav class="details-tabs" aria-label="Release details" x-on:click="navigateTab">
                @foreach(['overview' => 'Overview', 'files' => 'Files ('.$row->files.')', 'media' => 'Media info', 'nfo' => 'NFO', 'comments' => 'Comments ('.$commentCount.')'] as $key => $label)
                    <a href="#{{ $key }}" data-tab="{{ $key }}">{{ $label }}</a>
                @endforeach
            </nav>
            <section id="overview" class="card details-panel details-overview" data-details-panel>
                @include('details.partials.preview-images')
                @include('details.partials.audio-preview')
                @include('details.partials.movie-info')
                @include('details.partials.tv-info')
                @include('details.partials.music-info')
                @include('details.partials.game-info')
                @include('details.partials.console-info')
                @include('details.partials.book-info')
                @include('details.partials.anime-info')
                @include('details.partials.predb-info')
                @include('details.partials.password-info')
                <dl class="details-values">
                    <div><dt>Group</dt><dd><x-origin-chip kind="group" :value="$row->group" :href="route('browse.all', ['group' => $row->group])" /></dd></div>
                    <div><dt>Poster</dt><dd><x-origin-chip kind="poster" :value="$row->poster" :href="route('browse.all', ['poster' => $row->poster])" /></dd></div>
                    <div><dt>Password status</dt><dd>{{ (int) $release->passwordstatus < 0 ? 'Not checked' : ($row->passworded ? 'Detected' : 'None detected') }}</dd></div>
                </dl>
                @include('details.partials.reports')
            </section>
            <section id="files" class="card details-panel" data-details-panel>
                <h2>Files ({{ $row->files }})</h2>
                <div data-tab-content="files"><p class="text-muted">Open this tab to load the file list.</p><noscript><a href="{{ url('/api/release/'.$row->guid.'/filelist') }}">View file list</a></noscript></div>
                <x-button variant="secondary" size="sm" class="mt-2" data-retry-tab="files" hidden>Try again</x-button>
            </section>
            <section id="media" class="card details-panel" data-details-panel>
                <h2>Media info</h2>
                <noscript><a href="{{ url('/release/'.$row->id.'/mediainfo') }}">View media info</a></noscript><div data-tab-content="media">@if($row->has_media_info)<p class="text-muted">Open this tab to load media info.</p>@else<p class="text-muted">No media info for this release.</p>@endif</div>
                <x-button variant="secondary" size="sm" class="mt-2" data-retry-tab="media" hidden>Try again</x-button>
            </section>
            <section id="nfo" class="card details-panel" data-details-panel>
                <h2>NFO</h2>
                <div data-tab-content="nfo">@if($row->nfo)<p class="text-muted">Open this tab to load the NFO.</p><noscript><a href="{{ url('/nfo/'.$row->guid) }}">View NFO</a></noscript>@else<pre class="nfo-pane">No NFO for this release.</pre>@endif</div>
                <x-button variant="secondary" size="sm" class="mt-2" data-retry-tab="nfo" hidden>Try again</x-button>
            </section>
            <section id="comments" class="card details-panel" data-details-panel>
                @include('details.partials.comments')
            </section>
        </div>
        @include('details.partials.related')
    </div>
</div>
@endsection

@push('modals')
    @include('partials.release-modals')
    @if(!empty($movieTrailerUrl))@include('partials.trailer-modal')@endif
@endpush
