@extends('layouts.main')

@section('main_class', 'tv-page')

@push('modals')
    @include('tv.partials.dialogs')
@endpush

@php
    /** @var \App\Data\TvShowHeader $show */
    $many = count($seasons) > 8;
    $seasonName = static fn (int $number): string => $number === 0 ? 'Specials' : 'Season '.$number;
@endphp

@section('content')
<div class="tv-screen" data-part="page ground and body text" x-data="tvEpisodeList"
     data-show="{{ $show->id }}" data-nzb-link-base="{{ $nzbLinkBase }}" data-api-token="{{ $apiToken }}"
     x-on:click="handleClick" x-on:change="handleChange" x-on:checkbox-menu-change="applyFilter">
    <div class="tv-wrap" data-part="content width wrapper">
        <a class="tv-back" href="{{ $back['url'] }}"><i class="fas fa-arrow-left" aria-hidden="true"></i>{{ $back['label'] }}</a>
        <div class="tv-show-head">
            <div class="tv-show-art" data-part="show page poster">
                @if($show->poster !== null)
                    <img src="{{ $show->poster }}" alt="{{ $show->title }} poster">
                @else
                    <div class="tv-show-card">{{ $show->title }}</div>
                @endif
            </div>
            <div>
                <h1 data-part="show page title">{{ $show->title }}</h1>
                <div class="tv-show-meta" data-part="show meta line">{{ $show->meta(count($seasons)) }}</div>
                @if($show->summary !== '')
                    <p>{{ $show->summary }}</p>
                @endif
                @if($show->genres !== [] || $show->tags !== [])
                    <div class="tv-show-tags">
                        @foreach($show->genres as $genreId => $genre)
                            <a class="tv-tag" href="{{ route('tv.shows', ['genre' => [$genreId]]) }}" @if($loop->first) data-part="genre tag (link)" @endif>{{ $genre }}</a>
                        @endforeach
                        @foreach($show->tags as $tag)
                            <span class="tv-tag tv-tag-plain" @if($loop->first) data-part="plain tag" @endif>{{ $tag }}</span>
                        @endforeach
                    </div>
                @endif
                @if($show->starring !== [])
                    <div class="tv-starring" data-part="starring line">Starring @foreach($show->starring as $personId => $name)<a href="{{ route('tv.shows', ['person' => $personId]) }}">{{ $name }}</a>{{ $loop->last ? '' : ', ' }}@endforeach</div>
                @endif
            </div>
        </div>
        <div class="tv-season-bar" data-part="season tab bar">
            <h2 class="sr-only">{{ $season === null ? 'Releases' : $seasonName($season) }}</h2>
            @if($seasons !== [])
                <nav @class(['tv-season-tabs', 'is-many' => $many]) aria-label="Seasons">
                    @if($many)
                        <span class="tv-season-label" aria-hidden="true">Season</span>
                    @endif
                    @php
                        $otherTabMarked = false;
                    @endphp
                    @foreach($seasons as $number)
                        @php
                            $current = $number === $season;
                            $markOther = ! $current && ! $otherTabMarked;
                            $otherTabMarked = $otherTabMarked || $markOther;
                        @endphp
                        <a href="{{ route('tv.show', ['videosId' => $show->id, 'season' => $number, ...$filters->query(1)]) }}" data-season="{{ $number }}" title="{{ $seasonName($number) }}" aria-label="{{ $seasonName($number) }}"
                           @if($current) aria-current="page" data-part="season tab, current" @elseif($markOther) data-part="season tab" @endif>{{ $many && $number > 0 ? $number : $seasonName($number) }}</a>
                    @endforeach
                </nav>
            @endif
            <div class="tv-season-filters">
                <x-checkbox-menu name="resolution" label="Resolution" kind="resolution" :options="\App\Data\TvReleaseFilters::resolutionOptions()" :selected="$filters->resolutions" />
                <x-checkbox-menu name="source" label="Source" :options="\App\Data\TvReleaseFilters::sourceOptions()" :selected="$filters->sources" />
            </div>
        </div>
        <div x-ref="list">
            @include('tv.show.list')
        </div>
    </div>
    <div class="tv-bulk" x-show="selectedCount" x-cloak>
        <span><span x-text="selectedCount"></span> selected</span>
        <button type="button" class="tv-button" x-on:click="downloadSelected"><i class="fas fa-download" aria-hidden="true"></i>Download NZBs</button>
        <button type="button" class="tv-button tv-button-secondary" x-on:click="addSelectedToCart"><i class="fas fa-cart-shopping" aria-hidden="true"></i>Add to cart</button>
        <button type="button" class="tv-button tv-button-secondary" x-on:click="clearSelection">Clear selection</button>
    </div>
</div>
@endsection
