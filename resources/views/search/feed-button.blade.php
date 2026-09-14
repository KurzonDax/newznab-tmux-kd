<div x-data="searchFeed">
    <x-button variant="secondary" size="sm" icon="fas fa-rss" @click="openFeed">RSS for this search</x-button>
    <x-modal name="search-feed">
        <x-slot:title>RSS for this search</x-slot:title>
        <div class="space-y-4 text-[13px]">
            <p>Copy this URL into your feed reader. Feeds search release names and include the selected category, maximum age and minimum size.</p>
            @if($searchState->websiteOnlyFilters() !== [])
                <p>These filters apply on the website only: {{ implode(', ', $searchState->websiteOnlyFilters()) }}.</p>
            @endif
            <div x-data="copyToClipboard">
                <x-label for="search-feed-url" value="Feed URL" />
                <div class="flex gap-2"><x-input id="search-feed-url" :value="$searchState->rssUrl($userdata)" readonly class="min-w-0 flex-1" /><x-button variant="secondary" size="sm" icon="fas fa-copy" @click="copy('search-feed-url')"><span x-show="!copied">Copy</span><span x-show="copied" x-cloak>Copied</span></x-button></div>
            </div>
        </div>
        <x-slot:footer><x-button-link :href="$searchState->rssUrl($userdata)" variant="secondary" size="sm" icon="fas fa-rss">Open RSS feed</x-button-link></x-slot:footer>
    </x-modal>
</div>
