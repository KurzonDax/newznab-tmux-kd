{{-- The four round row actions: Download NZB, Copy NZB link, Add to cart, Follow. Without a show, or with $follow false (the show page's and details page's tables: their headers follow the show), an empty slot, not drawn, leaves Cart alone under Download. --}}
<div class="tv-actions">
    <a href="{{ route('getnzb.guid', $row->guid) }}" class="tv-action tv-action-download download-nzb" title="Download NZB" aria-label="Download NZB" @if($parts) data-part="row action: download" @endif><i class="fas fa-download" aria-hidden="true"></i></a>
    <button type="button" class="tv-action" data-copy-nzb="{{ $row->guid }}" title="Copy NZB link for SABnzbd or NZBGet" aria-label="Copy NZB link for SABnzbd or NZBGet" @if($parts) data-part="row action button" @endif><i class="fas fa-link" aria-hidden="true"></i></button>
    <button type="button" class="tv-action" data-cart="{{ $row->guid }}" aria-pressed="{{ $row->inCart ? 'true' : 'false' }}" title="{{ $row->inCart ? 'In cart · click to remove' : 'Add to cart' }}" aria-label="{{ $row->inCart ? 'Remove from cart' : 'Add to cart' }}"><i class="fas fa-cart-shopping" aria-hidden="true"></i></button>
    @if(($follow ?? true) && $row->hasShow())
        <button type="button" class="tv-action" data-watch-picker="{{ route('watchlist.picker', ['root' => 'tv', 'id' => $row->showId]) }}" data-watch-key="tv:{{ $row->showId }}" data-watch-title="{{ $row->showTitle }}" data-watched="{{ $row->watched ? '1' : '0' }}"
                data-watch-off-title="Follow this show" data-watch-on-title="Following this show · click to unfollow" data-watch-off-aria="Follow {{ $row->showTitle }}" data-watch-on-aria="Unfollow {{ $row->showTitle }}"
                title="{{ $row->watched ? 'Following this show · click to unfollow' : 'Follow this show' }}" aria-label="{{ $row->watched ? 'Unfollow ' : 'Follow ' }}{{ $row->showTitle }}"><i class="{{ $row->watched ? 'fas' : 'far' }} fa-bookmark" aria-hidden="true"></i></button>
    @else
        <span class="tv-action tv-action-slot" aria-hidden="true"></span>
    @endif
</div>
