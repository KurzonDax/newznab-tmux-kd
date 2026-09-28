{{-- A TV shows wall tile (SPEC.md 3.2), also the show page's Similar shows tiles. $parts marks the first tile's parts. --}}
<a class="tv-tile" href="{{ $tile->url }}" data-show="{{ $tile->id }}" @if($parts) data-part="show tile" @endif>
    <span class="tv-tile-art" @if($parts) data-part="show tile art" @endif>
        @if($tile->poster !== null)
            <img src="{{ $tile->poster }}" alt="" loading="lazy">
        @else
            <span class="tv-tile-card">{{ $tile->title }}</span>
        @endif
    </span>
    <b @if($parts) data-part="show tile title" @endif>{{ $tile->title }}</b>
    <span class="tv-tile-what" @if($parts) data-part="show tile line 1" @endif>{{ $tile->line1 }}</span>
    <span class="tv-tile-more">{{ $tile->line2 }}</span>
</a>
