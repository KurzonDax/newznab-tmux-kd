@extends('layouts.main')

@section('main_class', 'tv-page')

@section('content')
<div class="tv-screen" data-part="page ground and body text" x-data="tvShows" data-preference-url="{{ route('profile.update-view') }}"
     x-on:checkbox-menu-change="applyFilter">
    <div class="tv-wrap" data-part="content width wrapper">
        <div class="tv-filters">
            <h1 data-part="page title">TV shows</h1>
            <x-segmented :items="['Releases' => route('tv.releases'), 'Shows' => route('tv.shows')]" current="Shows" />
            <x-tv-search />
            <span class="tv-grow"></span>
            <label class="tv-sort">
                <select aria-label="Sort" x-on:change="changeSort">
                    @foreach(\App\Data\TvShowFilters::SORTS as $value => $text)
                        <option value="{{ $value }}" @selected($filters->sort === $value)>{{ $text }}</option>
                    @endforeach
                </select>
                <i class="fas fa-chevron-down" aria-hidden="true"></i>
            </label>
        </div>
        <div class="filter-row tv-bar-wall">
            @include('tv.partials.show-filters', ['shows' => $filters, 'firstPart' => 'shows filter button'])
            <a href="{{ route('tv.shows') }}" @class(['tv-clear-all', 'is-hidden' => ! $filters->any()]) data-clear-all aria-hidden="{{ $filters->any() ? 'false' : 'true' }}"@unless($filters->any()) tabindex="-1"@endunless>Clear all</a>
            @if($person !== null)
                <span class="tv-person" data-part="starring chip">Starring {{ $person }}<a href="{{ route('tv.shows', $filters->withoutPerson()->query(1)) }}" data-remove-person aria-label="Remove {{ $person }}"><i class="fas fa-xmark" aria-hidden="true"></i></a></span>
            @endif
        </div>
        <div x-ref="list" class="tv-list-end">
            @include('tv.shows.list')
        </div>
    </div>
</div>
@endsection
