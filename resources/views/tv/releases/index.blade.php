@extends('layouts.main')

@section('main_class', 'tv-page')

@push('modals')
    @include('partials.release-modals')
@endpush

@section('content')
<div class="tv-screen" data-part="page ground and body text" x-data="tvReleases"
     data-preference-url="{{ route('profile.update-view') }}"
     data-nzb-link-base="{{ $nzbLinkBase }}" data-api-token="{{ $apiToken }}"
     x-on:click="handleClick" x-on:change="handleChange" x-on:checkbox-menu-change="applyFilter">
    <div class="tv-wrap" data-part="content width wrapper">
        <div class="tv-filters">
            <h1 data-part="page title">TV releases</h1>
            <x-segmented :items="['Releases' => route('tv.releases'), 'Shows' => url('/tv/shows')]" current="Releases" />
            {{-- The shows-and-people search field (#778) sits here, right of the switch. --}}
            <span class="tv-grow"></span>
            <x-checkbox-menu name="category" label="Category" :options="$categoryMenu" :selected="$filters->categories" />
            <x-checkbox-menu name="resolution" label="Resolution" kind="resolution" :options="\App\Data\TvReleaseFilters::resolutionOptions()" :selected="$filters->resolutions" />
            <x-checkbox-menu name="source" label="Source" :options="\App\Data\TvReleaseFilters::sourceOptions()" :selected="$filters->sources" />
            <label class="tv-sort">
                <select aria-label="Sort releases" data-part="sort dropdown" x-on:change="changeSort">
                    @foreach(\App\Data\TvReleaseFilters::SORTS as $value => $text)
                        <option value="{{ $value }}" @selected($filters->sort->value === $value)>{{ $text }}</option>
                    @endforeach
                </select>
                <i class="fas fa-chevron-down" aria-hidden="true"></i>
            </label>
        </div>
        <div x-ref="list">
            @include('tv.releases.list')
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
