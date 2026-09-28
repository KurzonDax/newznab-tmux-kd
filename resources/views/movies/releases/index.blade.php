@extends('layouts.main')

@section('main_class', 'tv-page')

@push('modals')
    @include('tv.partials.dialogs')
@endpush

@section('content')
<div class="tv-screen" data-part="page ground and body text" x-data="tvReleases"
     data-preference-url="{{ route('profile.update-view') }}" data-preference-root="movies"
     data-nzb-link-base="{{ $nzbLinkBase }}" data-api-token="{{ $apiToken }}"
     x-on:click="handleClick" x-on:change="handleChange" x-on:checkbox-menu-change="applyFilter">
    <div class="tv-wrap" data-part="content width wrapper">
        <div class="tv-filters">
            <h1 data-part="page title">Movie releases</h1>
            <x-segmented :items="['Releases' => route('movies.releases'), 'Films' => route('movies.films')]" current="Releases" />
            <x-tv-search kind="film" />
            <span class="tv-grow"></span>
            <label class="tv-sort">
                <select aria-label="Sort releases" data-part="sort dropdown" x-on:change="changeSort">
                    @foreach(\App\Data\MovieReleaseFilters::SORTS as $value => $text)
                        <option value="{{ $value }}" @selected($filters->sort->value === $value)>{{ $text }}</option>
                    @endforeach
                </select>
                <i class="fas fa-chevron-down" aria-hidden="true"></i>
            </label>
        </div>
        <div class="filter-row tv-bar-list">
            <x-filter-bar label="The release" class="is-release">
                <x-checkbox-menu cell name="category" label="Category" noun="categories" :options="$categoryMenu" :selected="$filters->categories" />
                <x-checkbox-menu cell name="resolution" label="Resolution" kind="resolution" :options="\App\Data\MovieReleaseFilters::resolutionOptions()" :selected="$filters->resolutions" />
                <x-checkbox-menu cell name="source" label="Source" :options="\App\Data\MovieReleaseFilters::sourceOptions()" :selected="$filters->sources" />
                <x-checkbox-menu cell name="audio" label="Audio" noun="audio languages" :options="$audioMenu" :selected="$filters->audio" />
                <x-checkbox-menu cell single name="completion" label="Completion" any="Any completion" :options="\App\Data\MovieReleaseFilters::completionOptions()" :short="\App\Data\MovieReleaseFilters::completionCells()" :selected="$filters->completion === null ? [] : [$filters->completion]" />
            </x-filter-bar>
            @include('movies.partials.film-filters', ['options' => $filmOptions, 'films' => $filters->films])
        </div>
        <div x-ref="list">
            @include('movies.releases.list')
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
