@php
    /**
     * "RSS for this search" (issue #1050 section 4), in the redesigned lists' dialog form with the old dialog's content:
     * the sentence, the filters the feed cannot carry, the feed URL (WebSearchState::rssUrl()), a Copy button that reads
     * "Copied" for a moment and keeps its width, and the Open RSS feed link. The heading row's button opens it
     * (search-feed-open); it sits in the list fragment so the URL follows the two cells. Escape, the close button and a
     * click on the scrim close it, and focus returns to the button (modalLifecycle).
     *
     * @var \App\Data\WebSearchState $searchState
     * @var string $feedUrl
     */
    $websiteOnly = $searchState->websiteOnlyFilters();
@endphp
<div x-data="searchFeed" x-on:search-feed-open.window="openFeed">
    <x-tv-dialog name="search-feed" class="is-feed">
        <x-slot:title>RSS for this search</x-slot:title>
        <x-slot:subtitle>Copy this URL into your feed reader. Feeds search release names and include the selected category, maximum age and minimum size.</x-slot:subtitle>
        @if($websiteOnly !== [])
            <p class="tv-feed-only" data-website-only>These filters apply on the website only: {{ implode(', ', $websiteOnly) }}.</p>
        @endif
        <dl class="tv-rule-facts">
            <div><dt>Feed URL</dt><dd class="is-code" data-feed-url x-ref="feedUrl">{{ $feedUrl }}</dd></div>
        </dl>
        <div class="tv-dialog-actions">
            <button type="button" class="tv-details-button is-secondary" data-copy-feed x-ref="copy" x-on:click="copyFeed"><i class="fas fa-link" aria-hidden="true"></i><span class="tv-copy-words"><span>Copy</span><span>Copied</span></span></button>
            <a class="tv-details-button is-secondary" href="{{ $feedUrl }}"><i class="fas fa-rss" aria-hidden="true"></i>Open RSS feed</a>
        </div>
    </x-tv-dialog>
</div>
