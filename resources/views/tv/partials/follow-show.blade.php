{{--
    Follow show (SPEC.md rule 12, 3.3, 3.4): the show page's and details page's header button. Violet with the
    bookmark, solid while followed; both labels share one grid cell so pressing moves nothing. It opens today's
    picker to start following; on a followed show one click unfollows. watchlist-component.js then swaps data-watched,
    aria-pressed, the bookmark and the title between the two this button carries.
--}}
<button type="button" class="tv-details-button tv-follow-show" data-watch-picker="{{ route('watchlist.picker', ['root' => 'tv', 'id' => $showId]) }}" data-watch-key="tv:{{ $showId }}" data-watch-title="{{ $showTitle }}" data-watch-off-title="Follow this show" data-watch-on-title="Following this show · click to unfollow" data-watched="{{ $followed ? '1' : '0' }}" aria-pressed="{{ $followed ? 'true' : 'false' }}" title="{{ $followed ? 'Following this show · click to unfollow' : 'Follow this show' }}"><i class="{{ $followed ? 'fas' : 'far' }} fa-bookmark" aria-hidden="true"></i><span class="tv-state-label"><span class="is-off">Follow show</span><span class="is-on">Following show</span></span></button>
