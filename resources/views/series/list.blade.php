<section data-show-list data-kind="{{ $kind }}" data-season="{{ $season }}" data-episode="{{ $episodeId }}" data-page="{{ $results->currentPage() }}" data-per="{{ $results->perPage() }}">
    @if($kind === 'season')
        <details data-pack-section><summary>Full season releases · {{ $packCount }}</summary><div data-pack-list>@if($packCount)<button type="button" data-expand-packs>View {{ $packCount }} full season releases</button>@else<p>No full season releases.</p>@endif</div></details>
        <h3>Episodes · {{ $results->total() }}</h3>
        <div data-episode-list>
            @foreach($results as $episode)
                <details data-episode-section="{{ $episode->id }}">
                    <summary>{{ sprintf('S%02dE%02d', $episode->series, $episode->episode) }} · {{ $episode->title ?? '' }} · {{ $episode->release_count }} releases</summary>
                    <div data-episode-releases>@if($episode->release_count)<button type="button" data-expand-episode="{{ $episode->id }}">View {{ $episode->release_count }} releases</button>@else<p>No releases available.</p>@endif</div>
                </details>
            @endforeach
        </div>
    @else
        <h3>{{ $kind === 'packs' ? 'Full season releases' : 'Episode releases' }} · {{ $results->total() }}</h3>
        @foreach($results as $release)@include('components.release-browser.panel')@endforeach
    @endif
    <nav class="tv-directory-pager" aria-label="{{ $kind === 'season' ? 'Episode' : 'Release' }} pages">
        <span>Page {{ $results->currentPage() }} of {{ $results->lastPage() }} · {{ $results->total() }} {{ $kind === 'season' ? 'episodes' : 'releases' }}</span>
        <button type="button" data-list-page="{{ $results->currentPage() - 1 }}" @disabled($results->onFirstPage())>Previous</button>
        <button type="button" data-list-page="{{ $results->currentPage() + 1 }}" @disabled(!$results->hasMorePages())>Next</button>
        <x-select width="compact" data-list-per aria-label="Items per page">@foreach([24,48,100] as $per)<option value="{{ $per }}" @selected($results->perPage() === $per)>{{ $per }}</option>@endforeach</x-select>
    </nav>
</section>
