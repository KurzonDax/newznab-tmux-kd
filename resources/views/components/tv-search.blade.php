@props(['kind' => 'show'])
{{--
    A section's titles-and-people search (TV SPEC section 3.2, Movies SPEC 5.9): a toolbar field
    on the releases screen and the wall, right of the Releases / Shows (Films) switch. Results
    drop from the field in two groups, Shows (Films) then People; the site's top-bar search is
    separate. `kind` is "show" for TV and "film" for Movies.
--}}
@php
    [$placeholder, $searchUrl, $wallUrl, $itemUrl, $groups] = $kind === 'film'
        ? ['Search films or actors', route('movies.search'), route('movies.films'), url('/movies/film'), 'Films and people']
        : ['Search shows or actors', route('tv.search'), route('tv.shows'), url('/tv/show'), 'Shows and people'];
@endphp
<div class="tv-search" x-data="tvSearch" data-kind="{{ $kind }}" data-search-url="{{ $searchUrl }}" data-shows-url="{{ $wallUrl }}" data-show-url="{{ $itemUrl }}"
     x-on:click.outside="close" x-on:keydown="handleKey">
    <label>
        <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
        <input x-ref="field" placeholder="{{ $placeholder }}" autocomplete="off" aria-label="{{ $placeholder }}" data-part="search field"
               role="combobox" aria-autocomplete="list" aria-controls="tv-search-results" x-bind:aria-expanded="open" x-on:input="changed" x-on:focus="reopen">
    </label>
    <div class="tv-search-results" id="tv-search-results" role="listbox" aria-label="{{ $groups }}" x-ref="results" x-show="open" x-cloak x-on:click="picked" data-part="search results panel"></div>
</div>
