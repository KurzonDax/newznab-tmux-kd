{{--
    The TV section's shows-and-people search (SPEC section 3.2): a toolbar field on the TV
    releases screen and the shows wall, right of the Releases / Shows switch. Results drop
    from the field in two groups, Shows then People; the site's top-bar search is separate.
--}}
<div class="tv-search" x-data="tvSearch" data-search-url="{{ route('tv.search') }}" data-shows-url="{{ route('tv.shows') }}" data-show-url="{{ url('/tv/show') }}"
     x-on:click.outside="close" x-on:keydown="handleKey">
    <label>
        <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
        <input x-ref="field" placeholder="Search shows or actors" autocomplete="off" aria-label="Search shows or actors" data-part="search field"
               role="combobox" aria-autocomplete="list" aria-controls="tv-search-results" x-bind:aria-expanded="open" x-on:input="changed" x-on:focus="reopen">
    </label>
    <div class="tv-search-results" id="tv-search-results" x-ref="results" x-show="open" x-cloak x-on:click="picked" data-part="search results panel"></div>
</div>
