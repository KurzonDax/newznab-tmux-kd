@extends('layouts.main')

@section('main_class', 'tv-page')

@push('modals')
    @include('tv.partials.dialogs')
@endpush

@section('content')
@php
    // Console adds the game and Audio the music ($listKind): the name search also reads the game's name (the tags' album and
    // artists), and the game's (music's) panel follows the release's. Books and PC pass no kind.
    $kind = $listKind ?? null;
    $games = $kind !== null;
    $search = match ($kind) {
        'console' => ['Search releases or games', 'Search release and game names'],
        'audio' => ['Search releases, artists or albums', 'Search release names, artists and albums'],
        default => ['Search release names', 'Search release names'],
    };
@endphp
<div class="tv-screen" data-part="page ground and body text" x-data="tvReleases"
     data-preference-url="{{ route('profile.update-view') }}" data-preference-root="{{ $preferenceRoot }}" data-filters-clock="{{ $filtersClock }}"
     data-nzb-link-base="{{ $nzbLinkBase }}" data-api-token="{{ $apiToken }}"
     x-on:click="handleClick" x-on:change="handleChange" x-on:checkbox-menu-change="applyFilter">
    <div class="tv-wrap" data-part="content width wrapper">
        <div class="tv-filters">
            <h1 data-part="page title">{{ $heading }}</h1>
            {{-- The name search (SPEC 5.6): narrows the list to names (on Console, release or game names; on Audio, release names, artists or albums) containing the text; never remembered. --}}
            <div class="tv-search tv-name-search">
                <label>
                    <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                    <input type="text" x-ref="nameSearch" value="{{ $filters->search }}" placeholder="{{ $search[0] }}" autocomplete="off" aria-label="{{ $search[1] }}" data-part="search field"
                           x-on:input="searchNames" x-on:keydown.escape.prevent="clearNameSearch">
                    <button type="button" @class(['tv-name-search-clear', 'is-hidden' => $filters->search === '']) x-ref="nameClear" aria-label="Clear the name search" title="Clear the name search" x-on:click="clearNameSearch" @if($filters->search === '') aria-hidden="true" tabindex="-1" @endif><i class="fas fa-xmark" aria-hidden="true"></i></button>
                </label>
            </div>
            <span class="tv-grow"></span>
            <label class="tv-sort">
                <select aria-label="Sort releases" data-part="sort dropdown" x-on:change="changeSort">
                    @foreach(\App\Data\ReleaseListFilters::SORTS as $value => $text)
                        <option value="{{ $value }}" @selected($filters->sort->value === $value)>{{ $text }}</option>
                    @endforeach
                </select>
                <i class="fas fa-chevron-down" aria-hidden="true"></i>
            </label>
        </div>
        {{-- Two release cells (SPEC 5.2), each as wide as a Movie releases release-bar cell; Console adds the game's two cells in their own panel, Audio the music's. --}}
        <div class="filter-row tv-bar-list is-shelf">
            <x-filter-bar label="The release" class="is-release">
                <x-checkbox-menu cell name="category" label="Category" noun="categories" :options="$categoryMenu" :selected="$filters->categories" :exclude-other="$excludableOther" />
                <x-checkbox-menu cell single name="completion" label="Completion" any="Any completion" :options="\App\Data\ReleaseListFilters::completionOptions()" :short="\App\Data\ReleaseListFilters::completionCells()" :selected="$filters->completion === null ? [] : [$filters->completion]" />
            </x-filter-bar>
            @if($games)
                <x-filter-bar :label="$kind === 'audio' ? 'The music' : 'The game'" class="is-game">
                    <x-checkbox-menu cell name="genre" label="Genre" any="Any genre" noun="genres" :options="$genreMenu" :selected="$filters->genres" />
                    <x-year-menu :decades="$kind === 'audio' ? \App\Data\AudioReleaseFilters::decadeOptions() : \App\Data\ConsoleReleaseFilters::decadeOptions()" :selected="$filters->decades" :from="$filters->yearFrom" :to="$filters->yearTo" />
                </x-filter-bar>
            @endif
        </div>
        <div x-ref="list" class="tv-list-end">
            @include('shelf.releases.list')
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
