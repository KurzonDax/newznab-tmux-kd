{{--
    Follow show / Follow film (SPEC.md rule 12, 3.3, 3.4; Movies SPEC.md 5B.1): the show page's, details page's and film
    page's header button. Violet with the bookmark, solid while followed; both labels share one grid cell so pressing
    moves nothing. It opens today's picker to start following; on a followed title one click unfollows.
    watchlist-component.js then swaps data-watched, aria-pressed, the bookmark and the title between the two this button
    carries. $followRoot is the Following root (tv by default; movies for a film, keyed by its IMDb id) and $followNoun what it follows.
--}}
@php
    $followRoot ??= 'tv';
    $followNoun ??= 'show';
@endphp
<button type="button" class="tv-details-button tv-follow-show" data-watch-picker="{{ route('watchlist.picker', ['root' => $followRoot, 'id' => $showId]) }}" data-watch-key="{{ $followRoot }}:{{ $showId }}" data-watch-title="{{ $showTitle }}" data-watch-off-title="Follow this {{ $followNoun }}" data-watch-on-title="Following this {{ $followNoun }} · click to unfollow" data-watched="{{ $followed ? '1' : '0' }}" aria-pressed="{{ $followed ? 'true' : 'false' }}" title="{{ $followed ? 'Following this '.$followNoun.' · click to unfollow' : 'Follow this '.$followNoun }}"><i class="{{ $followed ? 'fas' : 'far' }} fa-bookmark" aria-hidden="true"></i><span class="tv-state-label"><span class="is-off">Follow {{ $followNoun }}</span><span class="is-on">Following {{ $followNoun }}</span></span></button>
