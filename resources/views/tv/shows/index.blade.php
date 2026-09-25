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
        </div>
        <div class="tv-show-filters">
            @if($person !== null)
                <span class="tv-person" data-part="starring chip">Starring {{ $person }}<a href="{{ route('tv.shows', $filters->withoutPerson()->query(1)) }}" aria-label="Remove {{ $person }}"><i class="fas fa-xmark" aria-hidden="true"></i></a></span>
            @endif
            <x-checkbox-menu name="genre" label="Genre" any="Any genre" summary="count" fixed part="shows filter button" :options="$options['genre']" :selected="$filters->genres" />
            <x-checkbox-menu name="decade" label="Premiered" any="Any decade" summary="count" fixed :part="false" :options="$options['decade']" :selected="$filters->decades" />
            <x-checkbox-menu name="language" label="Language" any="Any language" summary="count" fixed :part="false" :options="$options['language']" :selected="$filters->languages" />
            <x-checkbox-menu name="network" label="Network" any="Any network" summary="count" fixed :part="false" :options="$options['network']" :selected="$filters->networks" />
            <x-checkbox-menu name="rating" label="Rating" any="Any rating" summary="count" fixed :part="false" :options="$options['rating']" :selected="$filters->ratings" />
            <x-checkbox-menu name="status" label="Status" any="Any status" summary="count" fixed :part="false" :options="$options['status']" :selected="$filters->statuses" />
            <a href="{{ route('tv.shows') }}" @class(['tv-clear-all', 'is-hidden' => ! $filters->any()]) data-clear-all aria-hidden="{{ $filters->any() ? 'false' : 'true' }}"@unless($filters->any()) tabindex="-1"@endunless>Clear all</a>
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
        <div x-ref="list">
            @include('tv.shows.list')
        </div>
    </div>
</div>
@endsection
