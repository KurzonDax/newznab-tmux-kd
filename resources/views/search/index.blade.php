@extends('layouts.main')

@push('modals')
    @include('partials.release-modals')
@endpush

@section('content')
<div class="search-results-page">
    <x-breadcrumb :items="[['label' => 'Search']]" />
    <x-page-header :title="$meta_title">
        <x-slot:actions>@include('search.feed-button')</x-slot:actions>
    </x-page-header>
    <x-release-browser :rows="$results" :state="$browserState" :toolbar="false" :pager="$results->available" :empty-title="$results->available ? 'No releases match.' : 'Search unavailable'" :clear-url="$searchState->clearUrl()">
        <x-slot:beforePager>
            @if($results->available && $results->reachableTotal !== null && $results->total() > $results->reachableTotal)
                <p class="text-muted p-4">More results are available. Use a narrower search to reach the remaining results.</p>
            @endif
            <div class="search-query-chips" data-query-chips>
                @foreach($queryChips as $chip)
                    <x-chip variant="primary" :href="$chip['url']" data-remove-constraint="{{ $chip['key'] }}" aria-label="Remove {{ $chip['label'] }}">{{ $chip['label'] }} <i class="fas fa-xmark" aria-hidden="true"></i></x-chip>
                @endforeach
                @include('search.filter-menu')
                @if($queryChips !== [])
                    <x-button-link :href="$searchState->clearUrl()" variant="ghost" size="sm">Clear</x-button-link>
                @endif
                @if($spellSuggestion)
                    <span class="search-suggestion">Did you mean <a href="{{ $searchState->suggestionUrl($spellSuggestion) }}">{{ $spellSuggestion }}</a>?</span>
                @endif
                <span class="grow"></span>
                <x-select width="compact" name="sort" aria-label="Sort" @change="sortListing">
                    @foreach(\App\Enums\ReleaseSort::options() as $key => $label)
                        <option value="{{ $key }}" @selected($browserState->sort === $key)>{{ $label }}</option>
                    @endforeach
                </x-select>
                <x-button variant="secondary" size="icon" icon="fas fa-image" data-preference="thumbs" :data-value="$browserState->thumbs ? '0' : '1'" @click="changePreference" :aria-pressed="$browserState->thumbs ? 'true' : 'false'" aria-label="Thumbnails" />
            </div>
        </x-slot:beforePager>
    </x-release-browser>
</div>
@endsection
