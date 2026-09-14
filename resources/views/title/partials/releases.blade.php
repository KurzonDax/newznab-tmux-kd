<div data-title-releases data-selected-season="{{ $selectedSeason }}">
    @if($yearFilter)
        <div class="title-quality text-muted">Air year: {{ $yearFilter }} <a href="{{ $clearYearUrl }}" data-title-page class="text-primary-600 dark:text-primary-400">Clear year filter</a></div>
    @endif
    @if(count($qualities) > 1)
        <div class="card title-quality" role="group" aria-label="{{ $title->root === \App\Enums\BrowseRoot::Audio ? 'Format' : 'Quality' }}">
            <span class="text-muted">{{ $title->root === \App\Enums\BrowseRoot::Audio ? 'Format' : 'Quality' }}:</span>
            <button type="button" class="title-quality-chip" data-title-quality="" aria-pressed="{{ $activeQualities === [] ? 'true' : 'false' }}">All</button>
            @foreach($qualities as $quality)<button type="button" class="title-quality-chip" data-title-quality="{{ $quality }}" aria-pressed="{{ in_array($quality, $activeQualities, true) ? 'true' : 'false' }}">{{ $quality }}</button>@endforeach
        </div>
    @endif
    <x-release-browser :rows="$results" :state="$browserState" :toolbar="false" :pager="false" empty-title="No releases for this title.">
        <x-slot:heading>
            @if($title->root === \App\Enums\BrowseRoot::Tv && $seasons !== [])
                <nav class="title-season-tabs" aria-label="Seasons">
                    @foreach($seasons as $season)<a href="{{ $season['url'] }}" data-title-page @if($season['number'] === $selectedSeason) aria-current="page" @endif>{{ $season['label'] }} <span>{{ $season['count'] }}</span></a>@endforeach
                </nav>
                <div class="title-season-header"><strong>{{ $selectedSeason === 0 ? 'Specials' : 'Season '.$selectedSeason }}</strong><span class="text-muted">{{ $episodeCount }} {{ Str::plural('episode', $episodeCount) }} @if($selectedPackCount > 0) · {{ $selectedPackCount }} {{ Str::plural('season pack', $selectedPackCount) }} @endif · {{ $results->total() }} {{ Str::plural('release', $results->total()) }}</span><span class="grow"></span><x-button variant="secondary" size="sm" icon="far fa-square-check" x-on:click="selectTitleSeason" :data-season-guids="json_encode($seasonGuids)">Select season</x-button></div>
            @endif
        </x-slot:heading>
    </x-release-browser>
    @if($results->hasPages())
        <nav class="card title-pagination" aria-label="Release pages"><span>Page {{ $results->currentPage() }} of {{ $results->lastPage() }} · {{ $results->total() }} releases</span><span class="grow"></span>
            @if($results->previousPageUrl())<a href="{{ $results->previousPageUrl() }}" data-title-page>‹ Previous</a>@endif
            @foreach(range(max(1, $results->currentPage() - 2), min($results->lastPage(), $results->currentPage() + 2)) as $page)<a href="{{ $results->url($page) }}" data-title-page @if($page === $results->currentPage()) aria-current="page" @endif>{{ $page }}</a>@endforeach
            @if($results->nextPageUrl())<a href="{{ $results->nextPageUrl() }}" data-title-page>Next ›</a>@endif
        </nav>
    @endif
</div>
