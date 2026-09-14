<div class="release-browser-actions">
    <a data-row-action="download" href="{{ route('getnzb.guid', $row->guid) }}" class="download-nzb release-action release-action-download" title="Download NZB" aria-label="Download NZB"><i class="fas fa-download" aria-hidden="true"></i></a>
    <a data-row-action="details" href="{{ route('details', $row->guid) }}" class="release-action release-action-primary" title="Details" aria-label="Details"><i class="fas fa-info" aria-hidden="true"></i></a>
    <button type="button" data-row-action="basket" data-guid="{{ $row->guid }}" data-in-basket="{{ $row->in_basket ? '1' : '0' }}" @click="toggleBasket" class="release-action release-action-muted" title="{{ $row->in_basket ? 'In basket · click to remove' : 'Add to basket' }}" aria-label="{{ $row->in_basket ? 'Remove from basket' : 'Add to basket' }}"><i class="fas fa-shopping-basket" aria-hidden="true"></i></button>
    <button type="button" data-row-action="report" data-report-release-id="{{ $row->id }}" data-release-display-name="{{ $row->name }}" class="report-trigger release-action release-action-muted" title="Report release" aria-label="Report release"><i class="fas fa-flag" aria-hidden="true"></i></button>
    @if($entity && in_array($entity->root, ['movies', 'tv'], true))
        @php
            $watchUrl = $entity->root === 'movies'
                ? url('/mymovies').'?'.http_build_query(['id' => $row->watched ? 'edit' : 'add', 'imdb' => $entity->id])
                : url('/myshows').'?'.http_build_query(['action' => $row->watched ? 'edit' : 'add', 'id' => $entity->id]);
        @endphp
        <a data-row-action="watch" href="{{ $watchUrl }}" class="release-action release-action-muted" data-watched="{{ $row->watched ? '1' : '0' }}" title="{{ $row->watched ? 'On your watchlist · click to edit' : 'Watch '.$entity->title }}" aria-label="Watch {{ $entity->title }}"><i class="{{ $row->watched ? 'fas' : 'far' }} fa-heart" aria-hidden="true"></i></a>
    @endif
</div>
