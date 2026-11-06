@extends('layouts.main')

@section('main_class', 'tv-page')

@push('modals')
    @include('tv.partials.dialogs')
@endpush

@php
    // One page for the list and the Previews wall (SPEC 5B): $wall picks the heading, the half of the switch and the list.
    // An admin deletes a release from its row or card (SPEC 5.11, 5B.6): the dialog, and the row's leaving handled by the list.
    $canDelete = auth()->user()?->hasRole('Admin') ?? false;
@endphp

@section('content')
<div class="tv-screen" data-part="page ground and body text" x-data="tvReleases"
     data-preference-url="{{ route('profile.update-view') }}" data-preference-root="xxx" data-filters-clock="{{ $filtersClock }}"
     data-nzb-link-base="{{ $nzbLinkBase }}" data-api-token="{{ $apiToken }}"
     x-on:click="handleClick" x-on:change="handleChange" x-on:checkbox-menu-change="applyFilter" @if($canDelete) x-on:release-deleted="releaseDeleted" @endif>
    <div class="tv-wrap" data-part="content width wrapper">
        <div class="tv-filters is-adult">
            <h1 data-part="page title">{{ $wall ? 'Adult previews' : 'Adult releases' }}</h1>
            {{-- The other half carries the current filters, name search and sort: the list component keeps its query in step with the address (SPEC 5B.1). --}}
            <x-segmented :items="['Releases' => route('adult.releases'), 'Previews' => route('adult.previews')]" :current="$wall ? 'Previews' : 'Releases'" data-view-switch />
            {{-- The name search (SPEC 5.9): narrows the list to names containing the text; never remembered. --}}
            <div class="tv-search tv-name-search">
                <label>
                    <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                    <input type="text" x-ref="nameSearch" value="{{ $filters->search }}" placeholder="Search release names" autocomplete="off" aria-label="Search release names" data-part="search field"
                           x-on:input="searchNames" x-on:keydown.escape.prevent="clearNameSearch">
                    <button type="button" @class(['tv-name-search-clear', 'is-hidden' => $filters->search === '']) x-ref="nameClear" aria-label="Clear the name search" title="Clear the name search" x-on:click="clearNameSearch" @if($filters->search === '') aria-hidden="true" tabindex="-1" @endif><i class="fas fa-xmark" aria-hidden="true"></i></button>
                </label>
            </div>
            <span class="tv-grow"></span>
            <label class="tv-sort">
                <select aria-label="Sort releases" data-part="sort dropdown" x-on:change="changeSort">
                    @foreach(\App\Data\AdultReleaseFilters::SORTS as $value => $text)
                        <option value="{{ $value }}" @selected($filters->sort->value === $value)>{{ $text }}</option>
                    @endforeach
                </select>
                <i class="fas fa-chevron-down" aria-hidden="true"></i>
            </label>
        </div>
        <div class="filter-row tv-bar-list is-adult">
            <x-filter-bar label="The release" class="is-release">
                <x-checkbox-menu cell name="category" label="Category" noun="categories" :options="$categoryMenu" :selected="$filters->categories" :exclude-other="$excludableOther" />
                <x-checkbox-menu cell name="resolution" label="Resolution" kind="resolution" :options="\App\Data\AdultReleaseFilters::resolutionOptions()" :selected="$filters->resolutions" />
                <x-checkbox-menu cell name="audio" label="Audio" noun="audio languages" :options="$audioMenu" :selected="$filters->audio" />
                <x-checkbox-menu cell single name="completion" label="Completion" any="Any completion" :options="\App\Data\AdultReleaseFilters::completionOptions()" :short="\App\Data\AdultReleaseFilters::completionCells()" :selected="$filters->completion === null ? [] : [$filters->completion]" />
            </x-filter-bar>
        </div>
        <div x-ref="list" class="tv-list-end">
            @include($wall ? 'adult.releases.wall' : 'adult.releases.list')
        </div>
    </div>
    {{-- The wall has no selection (SPEC 5B.5). --}}
    @unless($wall)
        <div class="tv-bulk" x-show="selectedCount" x-cloak>
            <span><span x-text="selectedCount"></span> selected</span>
            <button type="button" class="tv-button" x-on:click="downloadSelected"><i class="fas fa-download" aria-hidden="true"></i>Download NZBs</button>
            <button type="button" class="tv-button tv-button-secondary" x-on:click="addSelectedToCart"><i class="fas fa-cart-shopping" aria-hidden="true"></i>Add to cart</button>
            <button type="button" class="tv-button tv-button-secondary" x-on:click="clearSelection">Clear selection</button>
        </div>
    @endunless
    @if($canDelete)
        @include('adult.releases.delete-dialog')
    @endif
</div>
@endsection
