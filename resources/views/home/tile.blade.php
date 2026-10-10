{{--
    One tile of a home shelf (docs/proposals/home-redesign/SPEC.md 3.2): always a button that opens its panel under
    the rail, never a link. A show or film wears the TV / Films wall tile's look (.tv-tile; the shared link tile is
    tv.partials.show-tile); an album is the square typographic tile; Books, Console, PC and Other show the release
    card; Adult shows the Adult list's 16:9 picture or its "No picture" tile.
--}}
@php
    /** @var \App\Data\HomeShelfTile $tile */
    $poster = in_array($tile->kind, ['show', 'film'], true);
@endphp
<button type="button" @class(['home-tile', 'tv-tile' => $poster, 'is-film' => $tile->kind === 'film', 'is-faded' => $tile->faded, 'is-album' => $tile->kind === 'album', 'is-card' => $tile->kind === 'rel', 'is-picture' => $tile->kind === 'pic'])
        data-tile data-kind="{{ $tile->kind }}" data-id="{{ $tile->id }}" aria-expanded="false" title="{{ $tile->title }}">
    <span class="tv-tile-art">
        @if($poster)
            @if($tile->art !== null)
                <img src="{{ $tile->art }}" alt="" loading="lazy">
            @elseif($tile->kind === 'film')
                <span class="tv-tile-card"><span class="tv-tile-card-title">{{ $tile->title }}</span>@if($tile->label !== '')<small>{{ $tile->label }}</small>@endif</span>
            @else
                <span class="tv-tile-card">{{ $tile->title }}</span>
            @endif
            @if($tile->badge !== '')
                <span class="home-tile-badge" data-badge>{{ $tile->badge }}</span>
            @endif
        @elseif($tile->kind === 'album')
            <span class="home-tile-card"><i class="fas fa-compact-disc" aria-hidden="true"></i>@if($tile->label !== '')<span class="home-tile-card-title">{{ $tile->label }}</span>@endif</span>
        @elseif($tile->kind === 'rel')
            <span class="home-tile-card"><span class="home-tile-card-title">{{ $tile->title }}</span>@if($tile->label !== '')<span class="home-tile-chip">{{ $tile->label }}</span>@endif</span>
        @elseif($tile->art !== null)
            <img src="{{ $tile->art }}" alt="" loading="lazy">
        @else
            <span class="home-tile-card is-no-picture"><i class="fas fa-image" aria-hidden="true"></i><small>No picture</small></span>
        @endif
    </span>
    @if($tile->kind !== 'rel')
        <b>{{ $tile->title }}</b>
    @endif
    <span class="tv-tile-what">{{ $tile->what }}</span>
</button>
