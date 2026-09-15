@extends('layouts.main')
@section('content')
<div x-data="tvShowDirectory" class="tv-directory" data-directory-url="{{ route('series') }}">
    <x-page-header title="TV Shows" />
    <form action="{{ route('series') }}" method="GET" class="card tv-directory-toolbar">
        <x-input name="title" :value="request('title')" placeholder="Search TV shows" aria-label="Show title" />
        <x-year-picker :selected="request('year', '')" :from="request('year_from', '')" :to="request('year_to', '')" />
        <x-select width="compact" name="network" aria-label="Network"><option value="">Network</option>@foreach($networks as $network)<option @selected(request('network') === $network)>{{ $network }}</option>@endforeach</x-select>
        <x-select width="compact" name="available" aria-label="Availability"><option value="0">All stored shows</option><option value="1" @selected(request()->boolean('available'))>With available releases</option></x-select>
        <label><input type="checkbox" name="watching" value="1" @checked(request()->boolean('watching'))> Only shows I follow</label>
        <input type="hidden" name="per" value="{{ $shows->perPage() }}">
        <input type="hidden" name="initial" value="{{ $initial }}">
        <x-button type="submit">Apply</x-button><x-button-link :href="route('series')" variant="secondary">Reset</x-button-link>
    </form>
    <nav class="tv-directory-initials" aria-label="Show initial">
        @foreach(['' => 'All', '#' => '#', ...array_combine(range('A', 'Z'), range('A', 'Z'))] as $value => $label)
            <a href="{{ route('series', [...request()->except(['page', 'id']), 'initial' => $value]) }}" @if($initial === (string) $value) aria-current="true" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    <div class="card tv-directory-grid">
        @forelse($shows as $show)
            <article class="tv-directory-card">
                <div class="tv-directory-poster">
                    <button type="button" data-show-id="{{ $show->id }}" @click="openShow" aria-label="Open {{ $show->title }}">
                        @if($show->artwork)<img src="{{ $show->artwork }}" alt="" loading="lazy">@else<i class="fas fa-tv" aria-hidden="true"></i>@endif
                    </button>
                    <x-watch-button root="tv" :id="$show->id" :title="$show->title" :watched="$show->watched" kind="heart" />
                </div>
                <div class="tv-directory-copy">
                    <button type="button" data-show-id="{{ $show->id }}" @click="openShow" class="tv-directory-title">{{ $show->title }}</button>
                    <div>{{ substr((string) ($show->started ?? ''), 0, 4) }}</div><div>{{ $show->genre ?? '' }}</div>
                    <div class="tv-directory-badges">
                        @if($show->publisher)<x-chip>{{ $show->publisher }}</x-chip>@endif
                        <x-chip>{{ $show->seasons }} seasons</x-chip>
                        @if(!empty($show->status))<x-chip :variant="$show->status === 'Continuing' ? 'success' : 'default'" :icon="$show->status === 'Continuing' ? 'fas fa-play' : 'fas fa-stop'">{{ $show->status }}</x-chip>@endif
                    </div>
                    <x-chip :variant="$show->release_count ? 'success' : 'default'" :icon="$show->release_count ? 'fas fa-check' : 'fas fa-minus'">{{ $show->release_count ? 'Releases available' : 'No releases available' }}</x-chip>
                </div>
            </article>
        @empty
            <x-empty-state title="No shows match." icon="fas fa-tv" />
        @endforelse
    </div>
    <nav class="tv-directory-pager" aria-label="TV show pages">
        <span>{{ $shows->total() }} shows · Page {{ $shows->currentPage() }} of {{ $shows->lastPage() }}</span>
        @if($shows->previousPageUrl())<a href="{{ $shows->previousPageUrl() }}">Previous</a>@endif
        @if($shows->nextPageUrl())<a href="{{ $shows->nextPageUrl() }}">Next</a>@endif
        @foreach([24,48,100] as $per)<a href="{{ route('series', [...request()->except(['page', 'id']), 'initial' => $initial, 'per' => $per]) }}" @if($shows->perPage() === $per) aria-current="true" @endif>{{ $per }}</a>@endforeach
    </nav>
    <x-modal name="tv-show" width="show" close="close" data-preserve-modal>
        <x-slot:title>TV Show</x-slot:title>
        <p x-show="loading" role="status">Loading show…</p><p x-show="error" x-text="error" role="alert"></p>
        <div x-ref="showBody" @click="navigate" @change="changePageSize"></div>
    </x-modal>
</div>
@endsection
@push('modals')
    @include('partials.release-modals')
@endpush
