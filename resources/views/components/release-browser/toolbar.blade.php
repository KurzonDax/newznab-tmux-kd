<div class="release-browser-toolbar">
    <div class="release-browser-filters">
    <x-input type="search" name="q" :value="$state->query" class="release-browser-search" :placeholder="'Search in '.$state->root->label()" :aria-label="'Search in '.$state->root->label()" @input.debounce.180ms="searchListing" />
    @if($state->hasFilters())
        <x-button variant="ghost" icon="fas fa-xmark" @click="clearFilters" aria-label="Clear filters">Clear</x-button>
    @endif
    </div>
    <div class="release-browser-view-controls">
    <span class="tabular-nums whitespace-nowrap">{{ number_format($rows->total()) }} releases</span>
    <x-select width="compact" name="sort" aria-label="Sort" @change="sortListing">
        @foreach($sortOptions as $key => $label)
            <option value="{{ $key }}" @selected($state->sort === $key)>{{ $label }}</option>
        @endforeach
    </x-select>
    <div class="release-browser-segment" aria-label="View">
        <button type="button" data-preference="view" data-value="table" @click="changePreference" aria-pressed="{{ $state->view === 'table' ? 'true' : 'false' }}"><i class="fas fa-list" aria-hidden="true"></i> Table</button>
    </div>
    @if($state->view === 'table')
        <x-button variant="secondary" size="icon" icon="fas fa-image" data-preference="thumbs" :data-value="$state->thumbs ? '0' : '1'" @click="changePreference" :aria-pressed="$state->thumbs ? 'true' : 'false'" aria-label="Thumbnails" title="Thumbnails" />
    @endif
    </div>
</div>
