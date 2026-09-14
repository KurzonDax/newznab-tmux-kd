<div class="release-browser-toolbar">
    <x-input type="search" name="q" :value="$state->query" class="release-browser-search" :placeholder="'Search in '.$state->root->label()" :aria-label="'Search in '.$state->root->label()" @input.debounce.180ms="searchListing" />
    @foreach($filterOptions as $key => $options)
        @if($options !== [])
            <x-select :name="$key" :aria-label="ucfirst($key)" @change="filterListing">
                <option value="">{{ ucfirst($key) }}</option>
                @foreach($options as $option)
                    <option value="{{ $option }}" @selected(request($key) === (string) $option)>{{ $option }}</option>
                @endforeach
            </x-select>
        @endif
    @endforeach
    @if($state->hasFilters())
        <x-button variant="ghost" icon="fas fa-xmark" @click="clearFilters" aria-label="Clear filters">Clear</x-button>
    @endif
    <span class="grow"></span>
    <span class="tabular-nums whitespace-nowrap">{{ number_format($rows->total()) }} releases</span>
    <x-select name="sort" aria-label="Sort" @change="sortListing">
        @foreach($sortOptions as $key => $label)
            <option value="{{ $key }}" @selected($state->sort === $key)>{{ $label }}</option>
        @endforeach
    </x-select>
    <div class="release-browser-segment" aria-label="View">
        <button type="button" data-preference="view" data-value="table" @click="changePreference" aria-pressed="{{ $state->view !== 'cards' ? 'true' : 'false' }}"><i class="fas fa-list" aria-hidden="true"></i> Table</button>
        @if(in_array('cards', $state->availableViews(), true))
            <button type="button" data-preference="view" data-value="cards" @click="changePreference" aria-pressed="{{ $state->view === 'cards' ? 'true' : 'false' }}" title="Renamed, post-processed releases only"><i class="fas fa-table-cells-large" aria-hidden="true"></i> Cards</button>
        @endif
    </div>
    @if($state->view !== 'cards')
        <x-button variant="secondary" size="icon" icon="fas fa-image" data-preference="thumbs" :data-value="$state->thumbs ? '0' : '1'" @click="changePreference" :aria-pressed="$state->thumbs ? 'true' : 'false'" aria-label="Thumbnails" title="Thumbnails" />
    @endif
</div>
