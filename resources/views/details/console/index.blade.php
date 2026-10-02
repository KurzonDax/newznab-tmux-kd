@extends('layouts.main')

@section('main_class', 'tv-page')

@push('modals')
    @include('tv.partials.dialogs')
@endpush

@php
    /**
     * The Console release page of a release with a game (docs/proposals/books-console-pc-redesign/SPEC.md
     * 5B): a release page laid out like the Movies film page. The release name stays the heading, the
     * game sits under it, then the film page's body (summary, storyline, tags, info lines, one button
     * row); the release's tabs and facts follow with no heading, then every release of the game and
     * Similar releases.
     *
     * @var \App\Data\ConsoleReleaseRow $row
     * @var \App\Data\ConsoleGame $game
     * @var string $subCategory
     * @var list<array{string, string}> $facts
     * @var list<array{string, string}> $predb
     * @var \App\Data\ConsoleGamePageFilters|null $table
     * @var list<\App\Data\ConsoleReleaseRow> $similar
     */
    $dereferrer = (string) ($site['dereferrer_link'] ?? '');
    $gameLine = array_values(array_filter([$game->year, $subCategory], static fn (string $part): bool => $part !== ''));
    $tags = $game->tags();
    $links = array_filter(['IGDB' => $game->igdbUrl, 'Website' => $game->website], static fn (string $url): bool => $url !== '');
@endphp

@section('content')
<div class="tv-screen tv-details" data-part="page ground and body text" x-data="movieReleaseDetails"
     data-guid="{{ $row->guid }}" data-release-id="{{ $row->id }}" data-has-media="{{ $row->mediaInfo === null ? '0' : '1' }}" data-has-nfo="{{ $row->nfo ? '1' : '0' }}" data-nzb-link-base="{{ $nzbLinkBase }}" data-api-token="{{ $apiToken }}"
     x-on:click="handleClick" x-on:submit="handleSubmit">
    <div class="tv-wrap" data-part="content width wrapper">
        <nav class="tv-crumbs" aria-label="Breadcrumb">
            <a href="{{ route('console.releases') }}">Console releases</a><span aria-hidden="true">›</span>
            <span>{{ $subCategory }}</span>
        </nav>
        <div class="tv-show-head">
            <div class="tv-show-art" data-part="game cover">
                @if($game->cover !== null)
                    <img src="{{ $game->cover }}" alt="{{ $game->title }} cover">
                @else
                    <div class="tv-show-card is-film"><span class="tv-tile-card-title">{{ $game->title }}</span>@if($game->year !== '')<small>{{ $game->year }}</small>@endif</div>
                @endif
            </div>
            <div>
                <h1 class="is-release-name" data-part="details heading">{{ $row->name }}</h1>
                <div class="tv-show-meta tv-film-meta tv-game-line" data-part="game line">
                    <b>{{ $game->title }}</b>@foreach($gameLine as $part)<span aria-hidden="true">·</span><span>{{ $part }}</span>@endforeach
                </div>
                @include('details.shelf.chips', ['row' => $row])
                @if($game->summary !== '')
                    <p>{{ $game->summary }}</p>
                @endif
                @if($game->storyline !== '')
                    <p class="tv-storyline"><b>Storyline</b> {{ $game->storyline }}</p>
                @endif
                @if($game->genres !== [] || $tags !== [])
                    <div class="tv-show-tags">
                        @foreach($game->genres as $genreId => $genre)
                            <a class="tv-tag" href="{{ route('console.releases', ['genre' => [$genreId]]) }}">{{ $genre }}</a>
                        @endforeach
                        @foreach($tags as $tag)
                            <span class="tv-tag tv-tag-plain">{{ $tag }}</span>
                        @endforeach
                    </div>
                @endif
                @foreach($game->infoLines() as $label => $value)
                    <div class="tv-starring">{{ $label }} <span class="tv-starring-value">{{ $value }}</span></div>
                @endforeach
                <div class="tv-details-actions tv-show-actions">
                    <a class="tv-details-button download-nzb" href="{{ route('getnzb.guid', $row->guid) }}" data-part="details primary button"><i class="fas fa-download" aria-hidden="true"></i>Download NZB</a>
                    <button type="button" class="tv-details-button is-secondary" data-copy-nzb="{{ $row->guid }}" data-part="details secondary button"><i class="fas fa-link" aria-hidden="true"></i>Copy NZB link</button>
                    <button type="button" class="tv-details-button is-secondary" data-cart="{{ $row->guid }}" data-cart-label aria-pressed="{{ $row->inCart ? 'true' : 'false' }}" title="{{ $row->inCart ? 'In cart · click to remove' : 'Add to cart' }}"><i class="fas fa-cart-shopping" aria-hidden="true"></i><span class="tv-state-label"><span class="is-off">Add to cart</span><span class="is-on">In cart</span></span></button>
                    @foreach($links as $label => $url)
                        <a class="tv-details-button is-secondary" href="{{ $dereferrer.$url }}" target="_blank" rel="noopener noreferrer">{{ $label }}<i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i><span class="sr-only"> (opens in a new tab)</span></a>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="tv-details-columns is-release-only">
            <div>
                @include('details.shelf.tabs', ['row' => $row, 'facts' => $facts, 'predb' => $predb, 'comments' => $comments])
            </div>
        </div>
        @if($table !== null)
            <section @class(['tv-siblings', 'tv-list-end' => $similar === []]) id="releases" aria-labelledby="game-releases-heading" x-ref="releases" data-film-releases>
                @include('details.console.releases')
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
