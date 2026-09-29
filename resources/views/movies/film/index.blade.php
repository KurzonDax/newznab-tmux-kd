@extends('layouts.main')

@section('main_class', 'tv-page')

@push('modals')
    @include('tv.partials.dialogs')
@endpush

@php
    /** @var \App\Data\MovieFilmHeader $film */
    $dereferrer = (string) ($site['dereferrer_link'] ?? '');
    $people = static fn (array $names): string => implode(', ', array_map(static fn (int $id, string $name): string => '<a href="'.e(route('movies.films', ['person' => $id])).'">'.e($name).'</a>', array_keys($names), $names));
@endphp

@section('content')
<div class="tv-screen" data-part="page ground and body text" x-data="movieFilm"
     data-nzb-link-base="{{ $nzbLinkBase }}" data-api-token="{{ $apiToken }}"
     x-on:click="handleClick" x-on:change="handleChange" x-on:checkbox-menu-change="applyFilter">
    <div class="tv-wrap" data-part="content width wrapper">
        <a class="tv-back" href="{{ $back['url'] }}"><i class="fas fa-arrow-left" aria-hidden="true"></i>{{ $back['label'] }}</a>
        <div class="tv-show-head">
            <div class="tv-show-art" data-part="film page poster">
                @if($film->poster !== null)
                    <img src="{{ $film->poster }}" alt="{{ $film->title }} poster">
                @else
                    <div class="tv-show-card is-film"><span class="tv-tile-card-title">{{ $film->title }}</span>@if($film->year !== '')<small>{{ $film->year }}</small>@endif</div>
                @endif
            </div>
            <div>
                <h1 data-part="film page title">{{ $film->title }}</h1>
                <div class="tv-show-meta tv-film-meta" data-part="film meta line">
                    @foreach($film->meta() as $part)
                        @unless($loop->first)<span aria-hidden="true">·</span>@endunless<span>{{ $part }}</span>
                    @endforeach
                    @if($film->best !== null)
                        <span aria-hidden="true">·</span><span>best</span><x-resolution-chip :resolution="$film->best" :part="false" />
                    @endif
                </div>
                @if($film->plot !== '')
                    <p>{{ $film->plot }}</p>
                @endif
                @if($film->genres !== [] || $film->tags !== [])
                    <div class="tv-show-tags">
                        @foreach($film->genres as $genreId => $genre)
                            <a class="tv-tag" href="{{ route('movies.films', ['genre' => [$genreId]]) }}" @if($loop->first) data-part="genre tag (link)" @endif>{{ $genre }}</a>
                        @endforeach
                        @foreach($film->tags as $tag)
                            <span class="tv-tag tv-tag-plain" @if($loop->first) data-part="plain tag" @endif>{{ $tag }}</span>
                        @endforeach
                    </div>
                @endif
                @if($film->directors !== [])
                    <div class="tv-starring" data-part="directed by line">Directed by {!! $people($film->directors) !!}</div>
                @endif
                @if($film->cast !== [])
                    <div class="tv-starring" data-part="starring line">Starring {!! $people($film->cast) !!}</div>
                @endif
                <div class="tv-details-actions tv-show-actions">
                    @if($film->imdbId !== '')
                        @include('tv.partials.follow-show', ['showId' => $film->imdbId, 'showTitle' => $film->title, 'followed' => $followed, 'followRoot' => 'movies', 'followNoun' => 'film'])
                    @endif
                    @foreach($film->links as $label => $url)
                        <a class="tv-details-button is-secondary" href="{{ $dereferrer.$url }}" target="_blank" rel="noopener noreferrer">{{ $label }}<i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i><span class="sr-only"> (opens in a new tab)</span></a>
                    @endforeach
                </div>
            </div>
        </div>
        <section class="tv-film-releases" id="releases" aria-labelledby="tv-film-releases-heading">
            <h2 id="tv-film-releases-heading">Releases</h2>
            <div class="filter-row tv-film-bar">
                <x-filter-bar label="Filter this film's releases" class="is-release">
                    <x-checkbox-menu cell name="resolution" label="Resolution" kind="resolution" :options="\App\Data\ReleaseListFilters::resolutionOptions()" :selected="$filters->resolutions" />
                    <x-checkbox-menu cell name="source" label="Source" :options="\App\Data\ReleaseListFilters::sourceOptions()" :selected="$filters->sources" />
                </x-filter-bar>
            </div>
            <div x-ref="list"@if($similar === []) class="tv-list-end"@endif>
                @include('movies.film.list')
            </div>
        </section>
        @if($similar !== [])
            <section class="tv-similar" aria-labelledby="tv-similar-heading">
                <h2 id="tv-similar-heading">Similar films</h2>
                <div class="tv-tiles">
                    @foreach($similar as $tile)
                        @include('tv.partials.show-tile', ['parts' => false, 'kind' => 'film'])
                    @endforeach
                </div>
            </section>
        @endif
    </div>
    <div class="tv-bulk" x-show="selectedCount" x-cloak>
        <span><span x-text="selectedCount"></span> selected</span>
        <button type="button" class="tv-button" x-on:click="downloadSelected"><i class="fas fa-download" aria-hidden="true"></i>Download NZBs</button>
        <button type="button" class="tv-button tv-button-secondary" x-on:click="addSelectedToCart"><i class="fas fa-cart-shopping" aria-hidden="true"></i>Add to cart</button>
        <button type="button" class="tv-button tv-button-secondary" x-on:click="clearSelection">Clear selection</button>
    </div>
</div>
@endsection
