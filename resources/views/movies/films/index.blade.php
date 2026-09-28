@extends('layouts.main')

@section('main_class', 'tv-page')

@section('content')
<div class="tv-screen" data-part="page ground and body text" x-data="tvShows" data-preference-url="{{ route('profile.update-view') }}"
     data-preference-root="movies" data-sort-key="films_sort" data-noun="films" x-on:checkbox-menu-change="applyFilter">
    <div class="tv-wrap" data-part="content width wrapper">
        <div class="tv-filters">
            <h1 data-part="page title">Films</h1>
            <x-segmented :items="['Releases' => route('movies.releases'), 'Films' => route('movies.films')]" current="Films" />
            <x-tv-search kind="film" />
            <span class="tv-grow"></span>
            <label class="tv-sort">
                <select aria-label="Sort films" data-part="sort dropdown" x-on:change="changeSort">
                    @foreach(\App\Data\MovieFilmWallFilters::SORTS as $value => $text)
                        <option value="{{ $value }}" @selected($filters->sort === $value)>{{ $text }}</option>
                    @endforeach
                </select>
                <i class="fas fa-chevron-down" aria-hidden="true"></i>
            </label>
        </div>
        <div class="filter-row tv-bar-wall">
            @include('movies.partials.film-filters', ['options' => $options, 'films' => $filters->films])
            <a href="{{ route('movies.films') }}" @class(['tv-clear-all', 'is-hidden' => ! $filters->any()]) data-clear-all aria-hidden="{{ $filters->any() ? 'false' : 'true' }}"@unless($filters->any()) tabindex="-1"@endunless>Clear all</a>
            @if($person !== null)
                <span class="tv-person" data-part="person chip">Films with {{ $person }}<a href="{{ route('movies.films', $filters->withoutPerson()->query(1)) }}" data-remove-person aria-label="Stop showing only films with {{ $person }}"><i class="fas fa-xmark" aria-hidden="true"></i></a></span>
            @endif
        </div>
        <div x-ref="list">
            @include('movies.films.list')
        </div>
    </div>
</div>
@endsection
