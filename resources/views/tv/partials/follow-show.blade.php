{{--
    Follow show (SPEC.md rule 12, 3.3, 3.4): the show page's and details page's header button. Violet with the
    bookmark, solid while followed; both labels share one grid cell so pressing moves nothing. It opens today's
    picker (watchlist-component.js), which marks data-watched after a save.
--}}
<button type="button" class="tv-details-button tv-follow-show" data-watch-picker="{{ route('watchlist.picker', ['root' => 'tv', 'id' => $showId]) }}" data-watch-key="tv:{{ $showId }}" data-watch-title="{{ $showTitle }}" data-watched="{{ $followed ? '1' : '0' }}" title="{{ $followed ? 'Following this show · click to unfollow' : 'Follow this show' }}"><i class="far fa-bookmark" aria-hidden="true"></i><span class="tv-state-label"><span class="is-off">Follow show</span><span class="is-on">Following show</span></span></button>
