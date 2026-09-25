@php
    $pageUrl = fn (int $page): string => route('tv.shows', $filters->query($page));
@endphp
<x-pager-line :page="$filters->page" :last-page="$lastPage" :total="$total" :per-page="\App\Data\TvShowFilters::PER_PAGE" noun="show" :url="$pageUrl" />
@if($tiles === [])
    <p class="tv-empty">{{ $filters->any() ? 'No shows match. Try removing one of the choices above.' : 'There are no TV shows yet.' }}</p>
@else
    <div class="tv-tiles">
        @foreach($tiles as $tile)
            <a class="tv-tile" href="{{ $tile->url }}" data-show="{{ $tile->id }}" @if($loop->first) data-part="show tile" @endif>
                <span class="tv-tile-art" @if($loop->first) data-part="show tile art" @endif>
                    @if($tile->poster !== null)
                        <img src="{{ $tile->poster }}" alt="" loading="lazy">
                    @else
                        <span class="tv-tile-card">{{ $tile->title }}</span>
                    @endif
                </span>
                <b @if($loop->first) data-part="show tile title" @endif>{{ $tile->title }}</b>
                <span class="tv-tile-what" @if($loop->first) data-part="show tile line 1" @endif>{{ $tile->line1 }}</span>
                <span class="tv-tile-more">{{ $tile->line2 }}</span>
            </a>
        @endforeach
    </div>
    <x-pager :page="$filters->page" :last-page="$lastPage" :url="$pageUrl" :action="route('tv.shows')" :query="$filters->query(1)" />
@endif
