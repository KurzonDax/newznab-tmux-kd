@props(['rows', 'state', 'toolbar' => true, 'pager' => true, 'filterOptions' => [], 'sortOptions' => ['newest' => 'Newest release', 'title' => 'Title A–Z'], 'emptyTitle' => 'No releases match.', 'emptyIcon' => null, 'emptyMessage' => null])

<section {{ $attributes->class(['release-browser card']) }} x-data="releaseBrowser"
         data-basket-only="{{ $state->basketOnly ? '1' : '0' }}" data-root="{{ $state->root->value }}" data-per="{{ $state->per }}"
         data-thumbs="{{ $state->thumbs ? '1' : '0' }}" data-last-page="{{ $rows->lastPage() }}">
    @if($toolbar)
        @include('components.release-browser.toolbar')
    @endif
    @if($pager)
        @if($state->hasLetters())
            <nav class="release-cover-letters" aria-label="Jump by initial">
                @foreach(['#', ...range('A', 'Z')] as $letter)
                    <button type="button" data-letter="{{ $letter }}" aria-pressed="{{ $state->letter === $letter ? 'true' : 'false' }}" @click="jumpLetter">{{ $letter }}</button>
                @endforeach
                <span class="text-muted ml-auto">Jump by initial · sorts by title</span>
            </nav>
        @endif
        @include('components.release-browser.pager')
    @endif
    @if($state->view === 'covers')
        @include('components.release-browser.covers')
    @elseif($state->view === 'cards')
        <div class="release-browser-cards" data-release-cards role="list">
            @foreach($rows as $release)
                @include('components.release-browser.card', ['row' => $release->row_data])
            @endforeach
        </div>
    @else
        @include('components.release-browser.table')
    @endif
    @if($rows->isEmpty())
        <div data-browser-empty>
            <x-empty-state :icon="$emptyIcon ?? $state->root->icon()" :title="$emptyTitle" :message="$emptyMessage" />
            @if($state->hasFilters())
                <div class="flex justify-center pb-6"><x-button variant="secondary" icon="fas fa-xmark" @click="clearFilters">Clear filters</x-button></div>
            @endif
        </div>
    @endif
    @if($pager)
        @include('components.release-browser.pager')
    @endif
    <div class="release-browser-bulk card" x-show="selectedCount" x-cloak>
        <strong><span x-text="selectedCount"></span> selected</strong>
        <span class="grow"></span>
        <x-button variant="secondary" size="sm" icon="fas fa-shopping-basket" @click="addSelectedToBasket">Add to basket</x-button>
        <x-button variant="success" size="sm" icon="fas fa-download" @click="downloadSelected">Download <span x-text="selectedCount"></span> NZBs</x-button>
        <x-button variant="ghost" size="sm" @click="clearSelection">Clear</x-button>
    </div>
</section>
