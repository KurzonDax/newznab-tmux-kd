{{--
    A wall tile: the TV shows wall (TV SPEC.md 3.2) and the show page's Similar shows (TvShowTile), and
    the Films wall (Movies SPEC.md 5A.3, MovieFilmTile, $kind 'film'): the film's name card adds the
    year, its first grey line keeps each genre on one line and a third line counts its releases.
    $parts marks the first tile's parts.
--}}
@php
    $kind ??= 'show';
    $film = $kind === 'film';
@endphp
<a @class(['tv-tile', 'is-film' => $film]) href="{{ $tile->url }}" data-{{ $kind }}="{{ $tile->id }}" @if($parts) data-part="{{ $kind }} tile" @endif>
    <span class="tv-tile-art" @if($parts) data-part="{{ $kind }} tile art" @endif>
        @if($tile->poster !== null)
            <img src="{{ $tile->poster }}" alt="" loading="lazy">
        @elseif($film)
            <span class="tv-tile-card"><span class="tv-tile-card-title">{{ $tile->title }}</span>@if($tile->year !== '')<small>{{ $tile->year }}</small>@endif</span>
        @else
            <span class="tv-tile-card">{{ $tile->title }}</span>
        @endif
    </span>
    <b @if($parts) data-part="{{ $kind }} tile title" @endif>{{ $tile->title }}</b>
    @if($film)
        <span class="tv-tile-what" @if($parts) data-part="film tile line 1" @endif>{{ $tile->year }}{{ $tile->year !== '' && $tile->genres !== [] ? ' · ' : '' }}@foreach($tile->genres as $genre)@unless($loop->first), @endunless<span class="tv-tile-genre">{{ $genre }}</span>@endforeach</span>
        <span class="tv-tile-more">{{ $tile->scoreLine }}</span>
        <span class="tv-tile-more is-count">{{ $tile->releaseCount }}</span>
    @else
        <span class="tv-tile-what" @if($parts) data-part="show tile line 1" @endif>{{ $tile->line1 }}</span>
        <span class="tv-tile-more">{{ $tile->line2 }}</span>
    @endif
</a>
