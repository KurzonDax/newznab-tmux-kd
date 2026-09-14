            <!-- Movie Information -->
            @if(!empty($movie))
                @php
                    $movieData = is_object($movie) ? get_object_vars($movie) : $movie;
                    $movieTitle = $movieData['title'] ?? ($movie->title ?? null);
                    $movieYear = $movieData['year'] ?? ($movie->year ?? null);
                    $movieTagline = $movieData['tagline'] ?? ($movie->tagline ?? null);
                    $movieRating = $movieData['rating'] ?? ($movie->rating ?? null);
                    $moviePlot = $movieData['plot'] ?? ($movie->plot ?? null);
                    $movieGenre = $movieData['genre'] ?? ($movie->genre ?? null);
                    $movieDirector = $movieData['director'] ?? ($movie->director ?? null);
                    $movieActors = $movieData['actors'] ?? ($movie->actors ?? null);
                    $movieLanguage = $movieData['language'] ?? ($movie->language ?? null);
                @endphp
                <div class="details-metadata-section">
                    <h3 class="text-[13px] font-semibold text-gray-800 dark:text-gray-200 mb-4 flex items-center">
                        <i class="fas fa-film mr-2 text-primary-600 dark:text-primary-400"></i> Movie Information
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        @if(!empty($movieTitle))
                            <div>
                                <dt class="text-[11px] font-medium text-gray-600 dark:text-gray-400">Title</dt>
                                <dd class="mt-1 text-[13px] text-gray-900 dark:text-gray-100 font-semibold">{{ $movieTitle }}</dd>
                            </div>
                        @endif
                        @if(!empty($movieYear))
                            <div>
                                <dt class="text-[11px] font-medium text-gray-600 dark:text-gray-400">Year</dt>
                                <dd class="mt-1 text-[13px] text-gray-900 dark:text-gray-100">{{ $movieYear }}</dd>
                            </div>
                        @endif
                        @if(!empty($movieTagline))
                            <div class="md:col-span-2">
                                <dt class="text-[11px] font-medium text-gray-600 dark:text-gray-400">Tagline</dt>
                                <dd class="mt-1 text-[13px] text-gray-700 dark:text-gray-300 italic">"{{ $movieTagline }}"</dd>
                            </div>
                        @endif
                        @if(!empty($movieRating))
                            <div>
                                <dt class="text-[11px] font-medium text-gray-600 dark:text-gray-400">IMDB Rating</dt>
                                <dd class="mt-1 text-[13px] text-gray-900 dark:text-gray-100">
                                    <span class="inline-flex items-center">
                                        <i class="fas fa-star text-yellow-500 dark:text-yellow-400 mr-1"></i>
                                        {{ $movieRating }}/10
                                    </span>
                                </dd>
                            </div>
                        @endif
                        @if(!empty($moviePlot))
                            <div class="md:col-span-2">
                                <dt class="text-[11px] font-medium text-gray-600 dark:text-gray-400">Plot Synopsis</dt>
                                <dd class="mt-1 text-[13px] text-gray-700 dark:text-gray-300 leading-relaxed">{{ $moviePlot }}</dd>
                            </div>
                        @endif
                        @if(!empty($movieGenre))
                            <div class="md:col-span-2">
                                <dt class="text-[11px] font-medium text-gray-600 dark:text-gray-400">Genre</dt>
                                <dd class="mt-1 text-[13px] text-gray-900 dark:text-gray-100">{!! $movieGenre !!}</dd>
                            </div>
                        @endif
                        @if(!empty($movieDirector))
                            <div>
                                <dt class="text-[11px] font-medium text-gray-600 dark:text-gray-400">Director</dt>
                                <dd class="mt-1 text-[13px] text-gray-900 dark:text-gray-100">{!! $movieDirector !!}</dd>
                            </div>
                        @endif
                        @if(!empty($movieActors))
                            <div class="md:col-span-2">
                                <dt class="text-[11px] font-medium text-gray-600 dark:text-gray-400">Cast</dt>
                                <dd class="mt-1 text-[13px] text-gray-900 dark:text-gray-100">{!! $movieActors !!}</dd>
                            </div>
                        @endif
                        @if(!empty($movieLanguage))
                            <div>
                                <dt class="text-[11px] font-medium text-gray-600 dark:text-gray-400">Language</dt>
                                <dd class="mt-1 text-[13px] text-gray-900 dark:text-gray-100">{{ $movieLanguage }}</dd>
                            </div>
                        @endif
                    </div>
                    @if(!empty($movieTrailerUrl))
                        <div class="mt-4"><x-button variant="secondary" size="sm" icon="fas fa-play" :data-trailer-url="$movieTrailerUrl">Trailer</x-button></div>
                    @endif
                </div>
            @endif
