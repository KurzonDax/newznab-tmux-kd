{{-- The four round row actions: Download NZB, Copy NZB link, Add to cart, Watch this show (an invisible slot without a show). --}}
<div class="tv-actions">
    <a href="{{ route('getnzb.guid', $row->guid) }}" class="tv-action tv-action-download download-nzb" title="Download NZB" aria-label="Download NZB" @if($parts) data-part="row action: download" @endif><i class="fas fa-download" aria-hidden="true"></i></a>
    <button type="button" class="tv-action" data-copy-nzb="{{ $row->guid }}" title="Copy NZB link for SABnzbd or NZBGet" aria-label="Copy NZB link for SABnzbd or NZBGet" @if($parts) data-part="row action button" @endif><i class="fas fa-link" aria-hidden="true"></i></button>
    <button type="button" class="tv-action" data-cart="{{ $row->guid }}" aria-pressed="{{ $row->inCart ? 'true' : 'false' }}" title="{{ $row->inCart ? 'In cart · click to remove' : 'Add to cart' }}" aria-label="{{ $row->inCart ? 'Remove from cart' : 'Add to cart' }}"><i class="fas fa-cart-shopping" aria-hidden="true"></i></button>
    @if($row->hasShow())
        <button type="button" class="tv-action" data-watch-picker="{{ route('watchlist.picker', ['root' => 'tv', 'id' => $row->showId]) }}" data-watch-key="tv:{{ $row->showId }}" data-watch-title="{{ $row->showTitle }}" data-watched="{{ $row->watched ? '1' : '0' }}"
                title="{{ $row->watched ? 'On your watchlist · click to edit' : 'Watch this show' }}" aria-label="{{ $row->watched ? 'Edit Watchlist choices for ' : 'Watch ' }}{{ $row->showTitle }}"><i class="fas fa-eye" aria-hidden="true"></i></button>
    @else
        <span class="tv-action tv-action-slot" aria-hidden="true"></span>
    @endif
</div>
