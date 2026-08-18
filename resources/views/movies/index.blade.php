@extends('layouts.main')

@push('modals')
    @include('partials.release-modals')
@endpush

@section('content')
<div class="surface-panel rounded-xl shadow-sm" x-data="moviesPage" data-movie-layout="{{ $movie_layout ?? 2 }}">
    @php
        $currentMovieCategory = !empty($categorytitle) ? $categorytitle : 'All';
        $movieCrumbs = [
            ['label' => 'Home', 'url' => url($site['home_link'] ?? '/'), 'icon' => 'fas fa-home'],
            ['label' => 'Movies', 'url' => $currentMovieCategory !== 'All' ? route('Movies') : null],
            ['label' => $currentMovieCategory],
        ];
    @endphp
    <x-breadcrumb :items="$movieCrumbs" />

    {{-- Movies Filter Section --}}
    <div class="px-6 py-5 surface-panel-alt border-b">
        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-5">
            <div class="flex flex-wrap items-center gap-4">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">
                    <i class="fas fa-film mr-2 text-primary-600 dark:text-primary-400"></i>Filter Movies
                </h2>
                <x-view-toggle
                    current-view="covers"
                    covgroup="movies"
                    :category="$categorytitle ?: 'All'"
                    parentcat="Movies"
                    :shows="false"
                />
            </div>

            <div class="flex flex-wrap gap-2">
                {{-- Layout Toggle Button --}}
                <x-button type="button"
                          @click="toggleLayout()"
                          variant="secondary"
                          class="shadow-md"
                          title="Toggle layout">
                    <i :class="layoutIcon"></i>
                    <span x-text="layoutLabel"></span>
                </x-button>

                {{-- Trending Movies Button --}}
                <a href="{{ route('trending-movies') }}"
                   class="inline-flex items-center px-4 py-2 bg-linear-to-r from-orange-500 to-red-600 text-white rounded-lg hover:from-orange-600 hover:to-red-700 transition shadow-md">
                    <i class="fas fa-fire mr-2"></i> View Trending Movies
                </a>
            </div>
        </div>

        {{-- Search Form --}}
        <form method="get" action="{{ route('Movies') }}" class="space-y-4">
            <input type="hidden" name="t" value="{{ $category }}">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="md:col-span-2">
                    <x-label for="q">Movie Search</x-label>
                    <x-input id="q"
                             name="q"
                             :value="$q ?? ''"
                             placeholder="Title, actor, director, or plot..." />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Prefixes: title:, actor:, director:, plot:
                    </p>
                </div>

                <div>
                    <x-label for="genre">Genre</x-label>
                    <x-select id="genre" name="genre">
                        <option value="">All Genres</option>
                        @if(isset($genres))
                            @foreach($genres as $gen)
                                <option value="{{ $gen }}" {{ (isset($genre) && $genre == $gen) ? 'selected' : '' }}>{{ $gen }}</option>
                            @endforeach
                        @endif
                    </x-select>
                </div>

                <div>
                    <x-label for="rating">Rating</x-label>
                    <x-select id="rating" name="rating">
                        <option value="">All Ratings</option>
                        @if(isset($ratings))
                            @foreach($ratings as $rate)
                                <option value="{{ $rate }}" {{ (isset($rating) && $rating == $rate) ? 'selected' : '' }}>{{ $rate }}+</option>
                            @endforeach
                        @endif
                    </x-select>
                </div>

                <x-year-picker :years="$years"
                               :selected="$year ?? ''"
                               :from="$year_from ?? ''"
                               :to="$year_to ?? ''" />
            </div>

            <details class="rounded-lg border border-gray-200 p-4 dark:border-gray-700" @if(($title ?? '') !== '' || ($actor ?? '') !== '' || ($director ?? '') !== '' || ($plot ?? '') !== '') open @endif>
                <summary class="cursor-pointer text-sm font-semibold text-gray-800 dark:text-gray-200">Advanced</summary>
                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <x-label for="title">Title</x-label>
                        <x-input id="title" name="title" :value="$title ?? ''" />
                    </div>
                    <div>
                        <x-label for="actor">Actor</x-label>
                        <x-input id="actor" name="actor" :value="$actor ?? ''" />
                    </div>
                    <div>
                        <x-label for="director">Director</x-label>
                        <x-input id="director" name="director" :value="$director ?? ''" />
                    </div>
                    <div>
                        <x-label for="plot">Plot</x-label>
                        <x-input id="plot" name="plot" :value="$plot ?? ''" />
                    </div>
                </div>
            </details>

            <div class="flex justify-end">
                <x-button type="submit"
                          class="shadow-md"
                          icon="fas fa-search">Search</x-button>
            </div>
        </form>
    </div>

    {{-- Movies List --}}
    @if(isset($results) && $results->count() > 0)
        <div class="px-6 py-6">
            {{-- Results Summary and Pagination --}}
            <div class="flex flex-col sm:flex-row justify-between items-center mb-6 pb-4 border-b border-gray-200 dark:border-gray-700 gap-4">
                <div class="text-sm text-gray-700 dark:text-gray-300">
                    <span class="font-medium">{{ $results->total() }}</span> movies found
                    <span class="text-gray-500 dark:text-gray-400">
                        (showing {{ $results->firstItem() }}-{{ $results->lastItem() }})
                    </span>
                </div>
                <div>
                    {{ $results->links() }}
                </div>
            </div>

            {{-- Movies Grid --}}
            <div id="moviesGrid"
                 class="movies-grid"
                 :data-layout="layout">
                @foreach($results as $result)
                    @include('movies.partials.movie-card', ['result' => $result, 'site' => $site])
                @endforeach
            </div>

            {{-- Bottom Pagination --}}
            <div class="mt-8 pt-4 border-t border-gray-200 dark:border-gray-700">
                {{ $results->links() }}
            </div>
        </div>
    @else
        <x-empty-state
            icon="fas fa-film"
            title="No Movies Found"
            message="Try adjusting your search filters or check back later for new releases."
        />
    @endif
</div>

{{-- NFO, preview, and other modals are included globally via layouts.main --}}
@endsection
