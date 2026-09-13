<div class="max-w-4xl mx-auto">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700 mb-6">
        <x-breadcrumb :items="[
            ['label' => 'Home', 'url' => url($site['home_link'] ?? '/')],
            ['label' => 'My Movies', 'url' => url('/mymovies')],
        ]" />
        <x-page-header :title="ucfirst($type ?? 'add').' Movie to Watchlist'" icon="fas fa-film" />

        <div class="p-6">
            <div class="mb-6">
                <div class="flex items-center gap-4 mb-4">
                    <img class="rounded-lg shadow-md w-24 h-auto"
                         src="{{ getImageAssetUrl('movies', $imdbid . '-cover', url('/covers/movies/no-cover.jpg')) }}"
                         data-fallback-src="{{ url('/covers/movies/no-cover.jpg') }}"
                         loading="lazy"
                         alt="{{ e($movie['title'] ?? '') }}" />

                    <div>
                        <h4 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-1">
                            {{ ucfirst($type ?? 'add') }} "{{ e($movie['title'] ?? '') }}" to watchlist
                        </h4>
                        <p class="text-gray-600 dark:text-gray-400 text-sm">Select categories below to organize this movie in your collection.</p>
                    </div>
                </div>

                <div class="bg-primary-50 border-l-4 border-primary-500 rounded-lg p-4">
                    <div class="flex items-start">
                        <i class="fa fa-info-circle text-primary-600 dark:text-primary-400 mt-0.5 mr-3"></i>
                        <p class="text-sm text-gray-700 dark:text-gray-300">
                            Adding movies to your watchlist will notify you through your
                            <a href="{{ url("/rss/mymovies?dl=1&i={$userdata->id}&api_token={$userdata->api_token}") }}"
                               class="font-semibold text-primary-600 dark:text-primary-400 hover:text-primary-800 dark:hover:text-primary-300 underline inline-flex items-center">
                                <i class="fa fa-rss mr-1"></i>RSS Feed
                            </a>
                            when they become available.
                        </p>
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ url("mymovies?id=do{$type}") }}" id="mymovies" class="space-y-6">
                @csrf
                <input type="hidden" name="imdb" value="{{ $imdbid }}"/>
                @if(!empty($from))
                    <input type="hidden" name="from" value="{{ $from }}" />
                @endif

                <div>
                    <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-3">Choose Categories:</label>
                    <div class="flex flex-wrap gap-3" id="category-container">
                        @foreach($cat_ids ?? [] as $index => $cat_id)
                            <label class="inline-flex items-center px-4 py-2 bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-100 dark:bg-gray-800 transition-all duration-200 has-checked:bg-primary-50 has-checked:border-primary-500 has-checked:text-primary-700">
                                <input type="checkbox"
                                       id="category_{{ $cat_id }}"
                                       name="category[]"
                                       value="{{ $cat_id }}"
                                       class="mr-2 rounded text-primary-600 dark:text-primary-400 focus:ring-primary-500"
                                       @if(in_array($cat_id, $cat_selected ?? [])) checked @endif>
                                <span class="text-sm font-medium">{{ $cat_names[$cat_id] ?? '' }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="flex gap-3 pt-4 border-t border-gray-200">
                    <x-button size="lg" class="shadow-md hover:shadow-lg"
                              type="submit" name="{{ $type ?? 'add' }}"
                              icon="fa {{ ($type ?? 'add') == 'add' ? 'fa-plus' : 'fa-edit' }}">{{ ucfirst($type ?? 'add') }} Movie</x-button>
                    <x-button-link href="{{ url('/mymovies') }}"
                                   variant="secondary" size="lg" class="shadow-md hover:shadow-lg"
                                   icon="fa fa-arrow-left">Back to My Movies</x-button-link>
                </div>
            </form>
        </div>
    </div>
</div>
