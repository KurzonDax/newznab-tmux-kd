@php
    /**
     * + Add filter (issue #1050 section 3): a menu in the list menus' form that lists the filter kinds; picking one
     * replaces the list with that filter's form under a back row naming it. Sub-category is a searchable single-choice
     * list of "Root > Sub" (each a link that applies it); Age and Size range take a minimum and a maximum in the form
     * of the Movies list's year range; a movie field takes words. A form submits to the search, which adds or replaces
     * that constraint and returns to page 1; a value that is not a positive number is ignored. The menu closes on
     * Escape, a click outside and focus leaving it, and always opens on its list (searchFilters).
     *
     * @var \App\Data\WebSearchState $searchState
     * @var array<string, string> $addFilters kind => name: cat, age, size and, while the scope admits Movies, the four movie fields
     * @var array<int, string> $subCategories sub-category id => "Root > Sub", those the scope admits
     */
    $terms = $searchState->terms->indexTerms();
    $ranges = ['age' => 'days', 'size' => 'MB'];
@endphp
<span class="tv-add-filter" x-data="searchFilters" data-add-filter x-on:keydown.escape.window="closeAndFocus" x-on:focusout="focusLeft" x-on:click.outside="close">
    <button type="button" class="tv-add-filter-button" x-ref="button" x-on:click="toggle" aria-haspopup="true" x-bind:aria-expanded="open"><i class="fas fa-plus" aria-hidden="true"></i>Add filter</button>
    <div class="checkbox-menu-panel tv-add-filter-panel" x-ref="panel" x-show="open" x-cloak>
        <div role="menu" aria-label="Add filter" x-show="onList">
            @foreach($addFilters as $kind => $name)
                <button type="button" class="checkbox-menu-item" role="menuitem" data-kind="{{ $kind }}" x-on:click="pick">{{ $name }}</button>
            @endforeach
        </div>
        @foreach($addFilters as $kind => $name)
            <div @class(['tv-add-filter-form', 'is-list' => $kind === 'cat']) role="dialog" aria-label="{{ $name }}" data-filter-form="{{ $kind }}" x-show="kind === '{{ $kind }}'" x-cloak>
                <div class="tv-add-filter-head">
                    <button type="button" class="tv-action" data-back x-on:click="back" aria-label="Back to the list of filters" title="Back"><i class="fas fa-arrow-left" aria-hidden="true"></i></button>
                    <span>{{ $name }}</span>
                </div>
                @if($kind === 'cat')
                    <div class="checkbox-menu-search"><i class="fas fa-magnifying-glass" aria-hidden="true"></i><input type="text" data-filter-search x-on:input="narrow" placeholder="Search categories" aria-label="Search categories" autocomplete="off"></div>
                    <div role="menu" aria-label="{{ $name }}">
                        @foreach($subCategories as $id => $path)
                            <a class="checkbox-menu-item" role="menuitemradio" href="{{ $searchState->url(['cat' => (string) $id]) }}" data-sub-category="{{ $id }}" data-text="{{ $path }}" aria-checked="{{ ($searchState->parameters['cat'] ?? '') === (string) $id ? 'true' : 'false' }}"><span class="checkbox-menu-dot"></span>{{ $path }}</a>
                        @endforeach
                    </div>
                @else
                    <form @class(['year-menu-range', 'is-text' => ! isset($ranges[$kind])]) action="{{ route('search') }}" method="GET">
                        @foreach($searchState->parameters as $key => $value)
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endforeach
                        <input type="hidden" name="filter" value="{{ $kind }}">
                        @isset($ranges[$kind])
                            <input name="filter_value" inputmode="decimal" placeholder="Min" aria-label="Minimum ({{ $ranges[$kind] }})" value="{{ $searchState->parameters['min'.$kind] ?? '' }}" autocomplete="off">
                            <span>to</span>
                            <input name="filter_max" inputmode="decimal" placeholder="Max" aria-label="Maximum ({{ $ranges[$kind] }})" value="{{ $searchState->parameters['max'.$kind] ?? '' }}" autocomplete="off">
                            <span>{{ $ranges[$kind] }}</span>
                        @else
                            <input name="filter_value" aria-label="Words to match" value="{{ $terms[$kind] ?? '' }}" autocomplete="off">
                        @endisset
                        <button type="submit" class="tv-button">Apply</button>
                    </form>
                @endif
            </div>
        @endforeach
    </div>
</span>
