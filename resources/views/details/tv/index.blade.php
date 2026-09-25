@extends('layouts.main')

@section('main_class', 'tv-page')

@push('modals')
    @include('tv.partials.dialogs')
@endpush

@php
    /**
     * The TV release details page (docs/proposals/tv-redesign/SPEC.md 3.4).
     *
     * @var \App\Data\TvReleaseRow $row
     * @var \App\Data\TvShowHeader|null $show
     * @var list<\App\Data\TvReleaseRow> $siblings
     * @var list<array{string, string}> $facts
     */
    $commentCount = $comments->total();
    $tabs = ['overview' => 'Overview', 'files' => 'Files ('.$row->files.')', 'media' => 'Media info', 'nfo' => 'NFO', 'comments' => 'Comments ('.$commentCount.')'];
    $mediaSummary = $row->mediaInfo === null ? null
        : ($row->resolution === \App\Enums\ReleaseResolution::Unknown || $row->mediaInfo === 'Media info' ? $row->mediaInfo : $row->resolution->label().' · '.$row->mediaInfo);
    $group = str_starts_with($row->group, 'alt.binaries.') ? 'a.b.'.substr($row->group, 13) : $row->group;
    $uploader = mb_strlen($row->uploader) > 26 ? mb_substr($row->uploader, 0, 25).'…' : $row->uploader;
@endphp

@section('content')
<div class="tv-screen tv-details" data-part="page ground and body text" x-data="tvReleaseDetails"
     data-guid="{{ $row->guid }}" data-release-id="{{ $row->id }}" data-has-media="{{ $row->mediaInfo === null ? '0' : '1' }}" data-has-nfo="{{ $row->nfo ? '1' : '0' }}" data-nzb-link-base="{{ $nzbLinkBase }}" data-api-token="{{ $apiToken }}"
     x-on:click="handleClick">
    <div class="tv-wrap" data-part="content width wrapper">
        <nav class="tv-crumbs" aria-label="Breadcrumb">
            <a href="{{ route('tv.releases') }}">TV releases</a><span aria-hidden="true">›</span>
            @if($show !== null)
                <a href="{{ $row->showUrl }}">{{ $show->title }}</a><span aria-hidden="true">›</span>
            @endif
            <span>{{ $facts[0][1] }}</span>
        </nav>
        <div @class(['tv-details-head', 'is-release-only' => $show === null])>
            @if($show !== null)
                <a class="tv-details-art" href="{{ route('tv.show', ['videosId' => $show->id]) }}" data-part="details poster">
                    @if($show->poster !== null)
                        <img src="{{ $show->poster }}" alt="{{ $show->title }} poster">
                    @else
                        <span class="tv-show-card">{{ $show->title }}</span>
                    @endif
                </a>
            @endif
            <div>
                @if($show !== null)
                    <h1 data-part="details heading">{{ $heading }}</h1>
                    <div class="tv-details-name" data-part="details release name">{{ $row->name }}</div>
                @else
                    <h1 class="is-release-name" data-part="details heading">{{ $row->name }}</h1>
                @endif
                <div class="tv-chips tv-details-chips">
                    <x-resolution-chip :resolution="$row->resolution" :part="false" />
                    <span class="tv-source-chip">{{ $row->source }}</span>
                    @if($row->completion !== null)
                        <x-chip :variant="'completion-'.$row->completion['band']"
                                :title="$row->completion['percent'].'% of this release\'s articles were seen by the indexer. '.($row->completion['repairing'] ? 'The site may still recover more of it.' : 'Repair has finished: this is as complete as it will get.')">{{ $row->completion['percent'] }}% complete{{ $row->completion['repairing'] ? ' · still repairing' : '' }}</x-chip>
                    @endif
                    @if($row->passworded)
                        <x-chip variant="password" icon="fas fa-lock">Password</x-chip>
                    @endif
                    @if($mediaSummary !== null)
                        <x-chip variant="media" action icon="fas fa-circle-info" class="mediainfo-badge" :data-release-id="$row->id" :data-release-display-name="$row->name" title="View media info">{{ $mediaSummary }}</x-chip>
                    @endif
                    @if($row->nfo)
                        <x-chip variant="nfo" action class="nfo-badge" :data-guid="$row->guid" :data-release-display-name="$row->name" title="View NFO">NFO</x-chip>
                    @endif
                    @if($row->preview !== null)
                        <x-chip variant="preview" action class="preview-badge" :data-guid="$row->guid" :data-release-display-name="$row->name" :data-image-url="$row->preview['thumb'] ?? ''"
                                :data-full-url="$row->preview['full']" data-image-title="Preview image" title="View preview image">Preview</x-chip>
                    @endif
                    @if($row->sample !== null)
                        <x-chip variant="sample" action class="sample-badge" :data-guid="$row->guid" :data-release-display-name="$row->name" :data-image-url="$row->sample['thumb'] ?? ''"
                                :data-full-url="$row->sample['full']" data-image-title="Sample image" title="View sample image">Sample</x-chip>
                    @endif
                </div>
                @if($row->group !== '' || $row->uploader !== '')
                    <div class="tv-chips tv-details-origin">
                        @if($row->group !== '')
                            <a class="tv-origin-chip" href="{{ route('browse.all', ['group' => $row->group]) }}" title="All releases in {{ $row->group }}"><i class="fas fa-users" aria-hidden="true"></i>{{ $group }}</a>
                        @endif
                        @if($row->uploader !== '')
                            <a class="tv-origin-chip" href="{{ route('browse.all', ['poster' => $row->uploader]) }}" title="All posts by {{ $row->uploader }}"><i class="fas fa-user" aria-hidden="true"></i>{{ $uploader }}</a>
                        @endif
                    </div>
                @endif
                <div class="tv-details-actions">
                    <a class="tv-details-button download-nzb" href="{{ route('getnzb.guid', $row->guid) }}" data-part="details primary button"><i class="fas fa-download" aria-hidden="true"></i>Download NZB</a>
                    <button type="button" class="tv-details-button is-secondary" data-copy-nzb="{{ $row->guid }}" data-part="details secondary button"><i class="fas fa-link" aria-hidden="true"></i>Copy NZB link</button>
                    <button type="button" class="tv-details-button is-secondary" data-cart="{{ $row->guid }}" data-cart-label aria-pressed="{{ $row->inCart ? 'true' : 'false' }}"><i @class(['fas', 'fa-check' => $row->inCart, 'fa-cart-shopping' => ! $row->inCart]) aria-hidden="true"></i><span>{{ $row->inCart ? 'In cart' : 'Add to cart' }}</span></button>
                    @if($show !== null)
                        <button type="button" class="tv-details-button is-secondary" data-watch-picker="{{ route('watchlist.picker', ['root' => 'tv', 'id' => $show->id]) }}" data-watch-key="tv:{{ $show->id }}" data-watch-title="{{ $show->title }}" data-watched="{{ $row->watched ? '1' : '0' }}"><i class="fas fa-eye" aria-hidden="true"></i>Watch show</button>
                    @endif
                </div>
            </div>
        </div>
        <div @class(['tv-details-columns', 'is-release-only' => $show === null])>
            <div>
                <div class="tv-details-tabs" role="tablist" aria-label="Release details">
                    @foreach($tabs as $key => $label)
                        <button type="button" role="tab" id="tab-{{ $key }}" data-tab="{{ $key }}" aria-controls="{{ $key }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}" tabindex="{{ $loop->first ? '0' : '-1' }}"
                                @if($loop->index < 2) data-part="{{ $loop->first ? 'tab, current' : 'tab' }}" @endif>{{ $label }}</button>
                    @endforeach
                </div>
                <section id="overview" class="tv-details-panel" role="tabpanel" aria-labelledby="tab-overview" data-details-panel>
                    @if($row->preview !== null && $row->preview['thumb'] !== null)
                        <button type="button" class="tv-details-preview preview-badge" data-guid="{{ $row->guid }}" data-release-display-name="{{ $row->name }}" data-image-url="{{ $row->preview['thumb'] }}"
                                data-full-url="{{ $row->preview['full'] }}" data-image-title="Preview image" aria-label="View preview image"><img src="{{ $row->preview['thumb'] }}" alt="Preview image"></button>
                    @endif
                    @if($aired !== '' || ($show !== null && $show->summary !== ''))
                        <div class="tv-details-episode">
                            @if($aired !== '')<span>Aired {{ $aired }}</span>@endif
                            @if($show !== null && $show->summary !== '')<span>{{ $show->summary }}</span>@endif
                        </div>
                    @endif
                    <dl class="tv-details-facts">
                        @foreach($facts as [$label, $value])
                            <div><dt @if($loop->first) data-part="facts label" @endif>{{ $label }}</dt><dd @if($loop->first) data-part="facts value" @endif>{{ $value }}</dd></div>
                        @endforeach
                    </dl>
                </section>
                <section id="files" class="tv-details-panel" role="tabpanel" aria-labelledby="tab-files" data-details-panel hidden>
                    <div data-tab-content="files"><p class="tv-note">Loading the file list…</p></div>
                </section>
                <section id="media" class="tv-details-panel" role="tabpanel" aria-labelledby="tab-media" data-details-panel hidden>
                    <div data-tab-content="media">@if($row->mediaInfo === null)<p class="tv-note">No media information was captured for this release.</p>@else<p class="tv-note">Loading media info…</p>@endif</div>
                </section>
                <section id="nfo" class="tv-details-panel" role="tabpanel" aria-labelledby="tab-nfo" data-details-panel hidden>
                    <div data-tab-content="nfo">@if($row->nfo)<p class="tv-note">Loading the NFO…</p>@else<p class="tv-note">No NFO was posted with this release.</p>@endif</div>
                </section>
                <section id="comments" class="tv-details-panel tv-details-comments" role="tabpanel" aria-labelledby="tab-comments" data-details-panel hidden>
                    @include('details.partials.comments')
                </section>
            </div>
            @if($show !== null)
                <aside class="tv-about">
                    <h2 data-part="about the show heading">About the show</h2>
                    @if($about !== '')
                        <div class="tv-about-meta">{{ $about }}</div>
                    @endif
                    @if($show->genres !== [] || $showTags !== [])
                        <div class="tv-show-tags">
                            @foreach($show->genres as $genreId => $genre)
                                <a class="tv-tag" href="{{ route('tv.shows', ['genre' => [$genreId]]) }}">{{ $genre }}</a>
                            @endforeach
                            @foreach($showTags as $tag)
                                <span class="tv-tag tv-tag-plain">{{ $tag }}</span>
                            @endforeach
                        </div>
                    @endif
                    @if($show->starring !== [])
                        <div class="tv-about-starring">Starring @foreach($show->starring as $personId => $name)<a href="{{ route('tv.shows', ['person' => $personId]) }}">{{ $name }}</a>{{ $loop->last ? '' : ', ' }}@endforeach</div>
                    @endif
                    <a class="tv-about-link" href="{{ $showLink['url'] }}">{{ $showLink['label'] }}</a>
                </aside>
            @endif
        </div>
        @if($siblings !== [])
            <section class="tv-siblings" x-ref="siblings">
                <h2 data-part="episode releases heading">{{ count($siblings) > 1 ? 'All '.count($siblings).' releases of this '.$siblingKind : 'The only release of this '.$siblingKind }}</h2>
                @include('tv.show.releases', ['rows' => $siblings, 'pick' => false, 'parts' => false, 'current' => $row->guid])
            </section>
        @endif
    </div>
</div>
@endsection
