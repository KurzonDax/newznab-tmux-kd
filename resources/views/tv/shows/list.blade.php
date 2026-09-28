@php
    $pageUrl = fn (int $page): string => route('tv.shows', $filters->query($page));
@endphp
<x-pager-line :page="$filters->page" :last-page="$lastPage" :total="$total" :per-page="\App\Data\TvShowFilters::PER_PAGE" noun="show" :url="$pageUrl" />
@if($tiles === [])
    <p class="tv-empty">No shows match. Try removing one of the choices above.</p>
@else
    <div class="tv-tiles">
        @foreach($tiles as $tile)
            @include('tv.partials.show-tile', ['parts' => $loop->first])
        @endforeach
    </div>
    <x-pager :page="$filters->page" :last-page="$lastPage" :url="$pageUrl" :action="route('tv.shows')" :query="$filters->query(1)" />
@endif
