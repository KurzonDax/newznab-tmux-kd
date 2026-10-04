@extends('layouts.main')

@section('main_class', 'tv-page')

@push('modals')
    @include('tv.partials.dialogs')
@endpush

@php
    /**
     * The release details page of a Books or PC release, of a Console release with no game
     * (docs/proposals/books-console-pc-redesign/SPEC.md 5A) and of an Audio release whose tags name no
     * album (docs/proposals/audio-redesign/SPEC.md 5A): the Adult details page's release-only form (the
     * release name as the heading, full width, no aside) without pictures and without the resolution
     * chip. An Audio release adds its MusicBrainz button, its Overview's preview, its Tracks tab and a
     * music video's Clip chip.
     *
     * @var \App\Data\ShelfReleaseRow|\App\Data\ConsoleReleaseRow|\App\Data\AudioReleaseRow $row
     * @var string $subCategory
     * @var list<array{string, string}> $facts
     * @var list<array{string, string}> $predb
     * @var list<\App\Data\ShelfReleaseRow>|list<\App\Data\ConsoleReleaseRow>|list<\App\Data\AudioReleaseRow> $similar
     */
    $dereferrer = (string) ($site['dereferrer_link'] ?? '');
    $musicBrainzUrl ??= '';
    [$listRoute, $listName] = match (\App\Enums\BrowseRoot::fromCategoryId($row->categoryId)) {
        \App\Enums\BrowseRoot::Books => ['books.releases', 'Book releases'],
        \App\Enums\BrowseRoot::Games => ['pc.releases', 'PC releases'],
        \App\Enums\BrowseRoot::Audio => ['audio.releases', 'Audio releases'],
        default => ['console.releases', 'Console releases'],
    };
@endphp

@section('content')
<div class="tv-screen tv-details" data-part="page ground and body text" x-data="movieReleaseDetails"
     data-guid="{{ $row->guid }}" data-release-id="{{ $row->id }}" data-has-media="{{ $row->mediaInfo === null ? '0' : '1' }}" data-has-nfo="{{ $row->nfo ? '1' : '0' }}" data-nzb-link-base="{{ $nzbLinkBase }}" data-api-token="{{ $apiToken }}"
     x-on:click="handleClick" x-on:submit="handleSubmit">
    <div class="tv-wrap" data-part="content width wrapper">
        <nav class="tv-crumbs" aria-label="Breadcrumb">
            <a href="{{ route($listRoute) }}">{{ $listName }}</a><span aria-hidden="true">›</span>
            <span>{{ $subCategory }}</span>
        </nav>
        <div class="tv-details-head is-release-only">
            <div>
                <h1 class="is-release-name" data-part="details heading">{{ $row->name }}</h1>
                @include('details.shelf.chips', ['row' => $row, 'clip' => $clip ?? null])
                <div class="tv-details-actions">
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
                @include('details.shelf.tabs', ['row' => $row, 'facts' => $facts, 'predb' => $predb, 'comments' => $comments, 'tracks' => $tracks ?? [], 'preview' => $preview ?? null])
            </div>
        </div>
        @if($similar !== [])
            <section class="tv-siblings tv-similar-releases" aria-labelledby="similar-releases-heading" data-similar-releases>
                <h2 id="similar-releases-heading">Similar releases</h2>
                @include('details.shelf.table', ['rows' => $similar, 'current' => null, 'sortable' => 'data-similar-sort', 'sort' => 'posted', 'ascending' => false])
            </section>
        @endif
    </div>
</div>
@endsection
