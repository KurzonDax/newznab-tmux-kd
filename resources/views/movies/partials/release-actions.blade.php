{{--
    The Movie releases list's four round row actions (SPEC 5.7), TV's buttons and classes: Download NZB, Copy NZB link,
    Add to cart, Follow the film. Without a film, or with $follow false (the film page's table: its header follows the film),
    an empty slot, not drawn, leaves Cart alone under Download. Follow opens
    today's picker to start following; on a followed film one click unfollows (watchlist-component.js swaps the state
    between the two wordings this button carries).
--}}
<div class="tv-actions">
    <a href="{{ route('getnzb.guid', $row->guid) }}" class="tv-action tv-action-download download-nzb" title="Download NZB" aria-label="Download NZB" @if($parts) data-part="row action: download" @endif><i class="fas fa-download" aria-hidden="true"></i></a>
    <button type="button" class="tv-action" data-copy-nzb="{{ $row->guid }}" title="Copy NZB link for SABnzbd or NZBGet" aria-label="Copy NZB link for SABnzbd or NZBGet" @if($parts) data-part="row action button" @endif><i class="fas fa-link" aria-hidden="true"></i></button>
    <button type="button" class="tv-action" data-cart="{{ $row->guid }}" aria-pressed="{{ $row->inCart ? 'true' : 'false' }}" title="{{ $row->inCart ? 'In cart · click to remove' : 'Add to cart' }}" aria-label="{{ $row->inCart ? 'Remove from cart' : 'Add to cart' }}"><i class="fas fa-cart-shopping" aria-hidden="true"></i></button>
    @if(($follow ?? true) && $row->hasFilm() && $row->imdbId !== '')
        <button type="button" class="tv-action" data-watch-picker="{{ route('watchlist.picker', ['root' => 'movies', 'id' => $row->imdbId]) }}" data-watch-key="movies:{{ $row->imdbId }}" data-watch-title="{{ $row->filmTitle }}" data-watched="{{ $row->watched ? '1' : '0' }}"
                data-watch-off-title="Follow this film" data-watch-on-title="Following this film · click to unfollow" data-watch-off-aria="Follow {{ $row->filmTitle }}" data-watch-on-aria="Unfollow {{ $row->filmTitle }}"
                title="{{ $row->watched ? 'Following this film · click to unfollow' : 'Follow this film' }}" aria-label="{{ $row->watched ? 'Unfollow ' : 'Follow ' }}{{ $row->filmTitle }}"><i class="{{ $row->watched ? 'fas' : 'far' }} fa-bookmark" aria-hidden="true"></i></button>
    @else
        <span class="tv-action tv-action-slot" aria-hidden="true"></span>
    @endif
</div>
