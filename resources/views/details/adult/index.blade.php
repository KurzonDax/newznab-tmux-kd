@extends('layouts.main')

@section('main_class', 'tv-page')

@push('modals')
    @include('tv.partials.dialogs')
@endpush

@php
    /**
     * The Adult release details page (docs/proposals/adult-redesign/SPEC.md 5A): the Movies details
     * page's form for a release with no film (the release name as the heading, full width, no aside),
     * without the source chip or Follow, with the preview and the sample side by side on the Overview.
     * A preview whose release has a clip plays it with its poster; the sample opens at full size.
     *
     * @var \App\Data\AdultReleaseRow $row
     * @var string $subCategory
     * @var int|null $clipSeconds
     * @var list<array{string, string}> $facts
     * @var list<array{string, string}> $predb
     * @var list<\App\Data\AdultReleaseRow> $similar
     */
    $commentCount = $comments->total();
    $tabs = ['overview' => 'Overview', 'files' => $row->hasFileCount() ? 'Files ('.$row->filesShown().')' : 'Files', 'media' => 'Media info', 'nfo' => 'NFO', 'comments' => 'Comments ('.$commentCount.')'];
    // The resolution has its own chip, so the media chip reads the media summary alone; the poster chip shows the full name (the prototype).
    $mediaSummary = $row->mediaInfo;
    $previewThumb = $row->preview['thumb'] ?? null;
    $sampleThumb = $row->sample['thumb'] ?? null;
    // The shared Preview chip names its data-part through $chipPart; this page names none.
    $chipPart = static fn (string $name): ?string => null;
@endphp

@section('content')
<div class="tv-screen tv-details" data-part="page ground and body text" x-data="movieReleaseDetails"
     data-guid="{{ $row->guid }}" data-release-id="{{ $row->id }}" data-has-media="{{ $row->mediaInfo === null ? '0' : '1' }}" data-has-nfo="{{ $row->nfo ? '1' : '0' }}" data-nzb-link-base="{{ $nzbLinkBase }}" data-api-token="{{ $apiToken }}"
     x-on:click="handleClick" x-on:submit="handleSubmit">
    <div class="tv-wrap" data-part="content width wrapper">
        <nav class="tv-crumbs" aria-label="Breadcrumb">
            <a href="{{ route('adult.releases') }}">Adult releases</a><span aria-hidden="true">›</span>
            <span>{{ $subCategory }}</span>
        </nav>
        <div class="tv-details-head is-release-only">
            <div>
                <h1 class="is-release-name" data-part="details heading">{{ $row->name }}</h1>
                <div class="tv-chips tv-details-chips">
                    <x-resolution-chip :resolution="$row->resolution" :part="false" />
                    @if($row->completion !== null)
                        <x-chip :variant="'completion-'.$row->completion['band']"
                                :title="$row->completion['percent'].'% of this release\'s articles were seen by the indexer. '.($row->completion['repairing'] ? 'The site may still recover more of it.' : 'The site will not try to recover more of it.')">{{ $row->completion['percent'] }}% complete{{ $row->completion['repairing'] ? ' · late headers pending' : '' }}</x-chip>
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
                    @include('tv.partials.preview-chip', ['clip' => $row->clip])
                    @if($row->sample !== null)
                        <x-chip variant="sample" action class="sample-badge" :data-guid="$row->guid" :data-release-display-name="$row->name" :data-image-url="$row->sample['thumb'] ?? ''"
                                :data-full-url="$row->sample['full']" data-image-title="Sample image" title="View sample image">Sample</x-chip>
                    @endif
                </div>
                @if($row->group !== '' || $row->uploader !== '')
                    <div class="tv-chips tv-details-origin">
                        @if($row->group !== '')
                            <a class="tv-origin-chip" href="{{ route('browse.all', ['group' => $row->group]) }}" title="All releases in {{ $row->group }}"><i class="fas fa-users" aria-hidden="true"></i>{{ $row->groupLabel() }}</a>
                        @endif
                        @if($row->uploader !== '')
                            <a class="tv-origin-chip" href="{{ route('browse.all', ['poster' => $row->uploader]) }}" title="All posts by {{ $row->uploader }}"><i class="fas fa-user" aria-hidden="true"></i>{{ $row->uploader }}</a>
                        @endif
                    </div>
                @endif
                <div class="tv-details-actions">
                    <a class="tv-details-button download-nzb" href="{{ route('getnzb.guid', $row->guid) }}" data-part="details primary button"><i class="fas fa-download" aria-hidden="true"></i>Download NZB</a>
                    <button type="button" class="tv-details-button is-secondary" data-copy-nzb="{{ $row->guid }}" data-part="details secondary button"><i class="fas fa-link" aria-hidden="true"></i>Copy NZB link</button>
                    <button type="button" class="tv-details-button is-secondary" data-cart="{{ $row->guid }}" data-cart-label aria-pressed="{{ $row->inCart ? 'true' : 'false' }}" title="{{ $row->inCart ? 'In cart · click to remove' : 'Add to cart' }}"><i class="fas fa-cart-shopping" aria-hidden="true"></i><span class="tv-state-label"><span class="is-off">Add to cart</span><span class="is-on">In cart</span></span></button>
                </div>
            </div>
        </div>
        <div class="tv-details-columns is-release-only">
            <div>
                <div class="tv-details-tabs" role="tablist" aria-label="Release details">
                    @foreach($tabs as $key => $label)
                        <button type="button" role="tab" id="tab-{{ $key }}" data-tab="{{ $key }}" aria-controls="{{ $key }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}" tabindex="{{ $loop->first ? '0' : '-1' }}"
                                @if($loop->index < 2) data-part="{{ $loop->first ? 'tab, current' : 'tab' }}" @endif>{{ $label }}</button>
                    @endforeach
                </div>
                <section id="overview" class="tv-details-panel" role="tabpanel" aria-labelledby="tab-overview" data-details-panel>
                    @if($previewThumb !== null || $sampleThumb !== null)
                        {{-- The pictures (SPEC 5A.3): the preview plays the clip when there is one, else opens its image; the sample opens at full size. --}}
                        <div class="tv-details-pictures">
                            @if($previewThumb !== null)
                                @if($row->clip !== null)
                                    <button type="button" class="tv-details-preview has-clip preview-badge" data-guid="{{ $row->guid }}" data-release-display-name="{{ $row->name }}"
                                            data-video-url="{{ $row->clip['url'] }}" data-video-type="{{ $row->clip['type'] }}" @if($row->clip['poster'] !== null) data-poster-url="{{ $row->clip['poster'] }}" @endif
                                            data-image-title="Video preview" aria-label="{{ $clipSeconds === null ? 'Preview, play the video preview' : 'Preview, play the '.$clipSeconds.'-second video preview' }}">
                                        <img src="{{ $previewThumb }}" alt="Preview image">
                                        <span class="tv-details-play" aria-hidden="true"><i class="fas fa-play"></i></span>
                                        <span class="tv-details-picture-label" aria-hidden="true">Preview</span>
                                        @if($clipSeconds !== null)
                                            <span class="tv-details-picture-label is-clip" aria-hidden="true"><i class="fas fa-play" aria-hidden="true"></i> {{ $clipSeconds }} s</span>
                                        @endif
                                    </button>
                                @else
                                    <button type="button" class="tv-details-preview preview-badge" data-guid="{{ $row->guid }}" data-release-display-name="{{ $row->name }}"
                                            data-image-url="{{ $previewThumb }}" data-full-url="{{ $row->preview['full'] }}" data-image-title="Image preview" aria-label="View the image preview">
                                        <img src="{{ $previewThumb }}" alt="Preview image">
                                        <span class="tv-details-picture-label" aria-hidden="true">Preview</span>
                                    </button>
                                @endif
                            @endif
                            @if($sampleThumb !== null)
                                <button type="button" class="tv-details-preview sample-badge" data-guid="{{ $row->guid }}" data-release-display-name="{{ $row->name }}"
                                        data-image-url="{{ $sampleThumb }}" data-full-url="{{ $row->sample['full'] }}" data-image-title="Sample image" data-open-full aria-label="View sample image at full size">
                                    <img src="{{ $sampleThumb }}" alt="Sample image">
                                    <span class="tv-details-picture-label" aria-hidden="true">Sample</span>
                                </button>
                            @endif
                        </div>
                    @endif
                    <dl class="tv-details-facts">
                        @foreach($facts as [$label, $value])
                            <div><dt @if($loop->first) data-part="facts label" @endif>{{ $label }}</dt><dd @if($loop->first) data-part="facts value" @endif>{{ $value }}</dd></div>
                        @endforeach
                    </dl>
                    @if($predb !== [])
                        <section class="tv-details-predb" aria-labelledby="predb-heading">
                            <h3 id="predb-heading">PreDB</h3>
                            <dl class="tv-details-facts">
                                @foreach($predb as [$label, $value])
                                    <div @class(['is-wide' => $label === 'Title'])><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                                @endforeach
                            </dl>
                        </section>
                    @endif
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
        </div>
        @if($similar !== [])
            <section class="tv-siblings tv-similar-releases" aria-labelledby="similar-releases-heading" data-similar-releases>
                <h2 id="similar-releases-heading">Similar releases</h2>
                @include('details.adult.table', ['rows' => $similar])
            </section>
        @endif
    </div>
</div>
@endsection
