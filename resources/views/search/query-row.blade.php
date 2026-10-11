@php
    /**
     * A search's query row (issue #1050 section 3), under the filter bar and in the list fragment so it follows the
     * two cells: one removable chip per constraint the cells do not show (a neutral pill; the whole chip is one link
     * that removes its constraint and returns to page 1), then + Add filter, then "Did you mean …?" for a search with
     * words that finds three results or fewer when the search service offers another spelling. The row is always
     * rendered, 32 px high, so the pager line never moves.
     *
     * It also tells the header's scope picker what to show after the cells change in place and which scope the
     * header's search keeps (WebSearchState::headerScope()).
     *
     * @var \App\Data\WebSearchState $searchState
     * @var list<array{key: string, label: string, url: string}> $queryChips
     * @var string|null $spellSuggestion
     * @var array{choice: string, value: string} $headerScope
     */
@endphp
<div class="tv-query-row" data-query-row data-scope-choice="{{ $headerScope['choice'] }}" data-scope-value="{{ $headerScope['value'] }}">
    <div class="tv-query-chips" data-query-chips>
        @foreach($queryChips as $chip)
            <a class="tv-query-chip" href="{{ $chip['url'] }}" data-remove-constraint="{{ $chip['key'] }}" aria-label="Remove {{ $chip['label'] }}" title="Remove {{ $chip['label'] }}"><span class="tv-query-chip-label">{{ $chip['label'] }}</span><span class="tv-query-chip-x"><i class="fas fa-xmark" aria-hidden="true"></i></span></a>
        @endforeach
        @include('search.filter-menu')
        @if($spellSuggestion)
            <span class="tv-query-suggestion" data-suggestion>Did you mean <a href="{{ $searchState->suggestionUrl($spellSuggestion) }}">{{ $spellSuggestion }}</a>?</span>
        @endif
    </div>
</div>
