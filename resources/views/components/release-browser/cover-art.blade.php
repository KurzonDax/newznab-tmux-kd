<span class="release-cover-art" data-has-art="{{ $cover->artwork ? '1' : '0' }}">
    @if($cover->artwork)
        <img src="{{ $cover->artwork }}" alt="" loading="lazy" x-on:error="coverArtworkFailed">
        <i class="{{ $state->root->icon() }}" aria-hidden="true"></i>
    @else
        <i class="{{ $state->root->icon() }}" data-no-artwork aria-hidden="true"></i>
    @endif
    <span class="release-cover-art-title">{{ $cover->title }}</span>
    @if($cover->artworkTag !== '')
        <span class="release-cover-art-tag">{{ $cover->artworkTag }}</span>
    @endif
</span>
