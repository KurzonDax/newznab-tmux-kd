@extends('layouts.main')

@section('main_class', 'tv-page')

@push('modals')
    @include('tv.partials.dialogs')
@endpush

@php
    /**
     * The Audio album page, a release whose tags name an album (docs/proposals/audio-redesign/SPEC.md
     * 5B): Console's game release page with the album in the game's place. The release name stays the
     * heading, the music line sits under it, then the chip line (no Listen: the Overview plays the
     * preview), the genre and format tags, the info lines and one button row; the release's tabs and
     * facts follow with no heading, then every release of the album and Similar releases.
     *
     * @var \App\Data\AudioReleaseRow $row
     * @var \App\Data\AudioReleaseMusic $music
     * @var string $subCategory
     * @var list<array{string, string}> $facts
     * @var list<array{string, string}> $predb
     * @var list<\App\Data\AudioTrack> $tracks
     * @var \App\Data\AudioPreview|null $preview
     * @var array{url: string, type: string}|null $clip
     * @var string $musicBrainzUrl
     * @var \App\Data\ConsoleGamePageFilters|null $table
     * @var list<\App\Data\AudioReleaseRow> $similar
     */
    $dereferrer = (string) ($site['dereferrer_link'] ?? '');
    $musicLine = array_values(array_filter([$music->year, $subCategory], static fn (string $part): bool => $part !== ''));
    $formatTag = $music->formatTag($subCategory, $row->mediaInfo);
    $totalLength = \App\Data\AudioTrack::totalLength($tracks);
@endphp

@section('content')
<div class="tv-screen tv-details" data-part="page ground and body text" x-data="movieReleaseDetails"
     data-guid="{{ $row->guid }}" data-release-id="{{ $row->id }}" data-has-media="{{ $row->mediaInfo === null ? '0' : '1' }}" data-has-nfo="{{ $row->nfo ? '1' : '0' }}" data-nzb-link-base="{{ $nzbLinkBase }}" data-api-token="{{ $apiToken }}"
     x-on:click="handleClick" x-on:submit="handleSubmit">
    <div class="tv-wrap" data-part="content width wrapper">
        <nav class="tv-crumbs" aria-label="Breadcrumb">
            <a href="{{ route('audio.releases') }}">Audio releases</a><span aria-hidden="true">›</span>
            <span>{{ $subCategory }}</span>
        </nav>
        <div class="tv-show-head">
            {{-- No audio cover is stored (SPEC 6.2): every album page shows the film page's placeholder tile with a disc icon. --}}
            <div class="tv-show-art is-square" data-part="album cover">
                <div class="tv-show-card is-film"><i class="fas fa-compact-disc" aria-hidden="true"></i><span class="tv-tile-card-title">{{ $music->album }}</span>@if($music->year !== '')<small>{{ $music->year }}</small>@endif</div>
            </div>
            <div>
                <h1 class="is-release-name" data-part="details heading">{{ $row->name }}</h1>
                <div class="tv-show-meta tv-film-meta tv-game-line" data-part="music line">
                    @if($music->artist !== '')<span>{{ $music->artist }}</span><span>–</span>@endif<b>{{ $music->album }}</b>@foreach($musicLine as $part)<span aria-hidden="true">·</span><span>{{ $part }}</span>@endforeach
                </div>
                @include('details.shelf.chips', ['row' => $row, 'clip' => $clip])
                @if($music->genres !== [] || $music->unknownGenre || $formatTag !== '')
                    <div class="tv-show-tags">
                        @foreach($music->genres as $genreId => $genre)
                            <a class="tv-tag" href="{{ route('audio.releases', ['genre' => [$genreId]]) }}">{{ $genre }}</a>
                        @endforeach
                        @if($music->unknownGenre)
                            <a class="tv-tag" href="{{ route('audio.releases', ['genre' => [\App\Data\AudioReleaseFilters::GENRE_UNKNOWN]]) }}">Unknown</a>
                        @endif
                        @if($formatTag !== '')
                            <span class="tv-tag tv-tag-plain">{{ $formatTag }}</span>
                        @endif
                    </div>
                @endif
                @if($tracks !== [])
                    <div class="tv-starring">Tracks <span class="tv-starring-value">{{ count($tracks) }}{{ $totalLength === '' ? '' : ' · '.$totalLength }}</span></div>
                @endif
                @if($music->performedBy !== '')
                    <div class="tv-starring">Performed by <span class="tv-starring-value">{{ $music->performedBy }}</span></div>
                @endif
                <div class="tv-details-actions tv-show-actions">
                    <a class="tv-details-button download-nzb" href="{{ route('getnzb.guid', $row->guid) }}" data-part="details primary button"><i class="fas fa-download" aria-hidden="true"></i>Download NZB</a>
                    <button type="button" class="tv-details-button is-secondary" data-copy-nzb="{{ $row->guid }}" data-part="details secondary button"><i class="fas fa-link" aria-hidden="true"></i>Copy NZB link</button>
                    <button type="button" class="tv-details-button is-secondary" data-cart="{{ $row->guid }}" data-cart-label aria-pressed="{{ $row->inCart ? 'true' : 'false' }}" title="{{ $row->inCart ? 'In cart · click to remove' : 'Add to cart' }}"><i class="fas fa-cart-shopping" aria-hidden="true"></i><span class="tv-state-label"><span class="is-off">Add to cart</span><span class="is-on">In cart</span></span></button>
                    @if($musicBrainzUrl !== '')
                        <a class="tv-details-button is-secondary" href="{{ $dereferrer.$musicBrainzUrl }}" target="_blank" rel="noopener noreferrer">MusicBrainz<i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i><span class="sr-only"> (opens in a new tab)</span></a>
                    @endif
                </div>
            </div>
        </div>
        <div class="tv-details-columns is-release-only">
            <div>
                @include('details.shelf.tabs', ['row' => $row, 'facts' => $facts, 'predb' => $predb, 'comments' => $comments, 'tracks' => $tracks, 'preview' => $preview])
            </div>
        </div>
        @if($table !== null)
            <section @class(['tv-siblings', 'tv-list-end' => $similar === []]) id="releases" aria-labelledby="album-releases-heading" x-ref="releases" data-film-releases>
                @include('details.audio.releases')
            </section>
        @endif
        @if($similar !== [])
            <section class="tv-siblings tv-similar-releases" aria-labelledby="similar-releases-heading" data-similar-releases>
                <h2 id="similar-releases-heading">Similar releases</h2>
                @include('details.shelf.table', ['rows' => $similar, 'current' => null, 'sortable' => 'data-similar-sort', 'sort' => 'posted', 'ascending' => false])
            </section>
        @endif
    </div>
</div>
@endsection
