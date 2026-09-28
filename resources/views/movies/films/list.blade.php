@php
    $pageUrl = fn (int $page): string => route('movies.films', $filters->query($page));
@endphp
<x-pager-line :page="$filters->page" :last-page="$lastPage" :total="$total" :per-page="\App\Data\MovieFilmWallFilters::PER_PAGE" noun="film" :url="$pageUrl" single fixed />
@if($tiles === [])
    @php
        $matching = $filters->describe($options['genre'], $options['language'], $person);
    @endphp
    <p class="tv-empty">{{ $matching === '' ? 'There are no films yet.' : 'No films match '.$matching.'.' }}</p>
@else
    <div class="tv-tiles">
        @foreach($tiles as $tile)
            @include('tv.partials.show-tile', ['kind' => 'film', 'parts' => $loop->first])
        @endforeach
    </div>
    <x-pager :page="$filters->page" :last-page="$lastPage" :url="$pageUrl" :action="route('movies.films')" :query="$filters->query(1)" />
@endif
