@php
    $pageUrl = fn (int $page): string => route($listRoute, $filters->query($page));
    $byAdded = $filters->sortsByAdded();
    $chipBaseId = collect($rows)->first(fn ($row): bool => $row->hasChips())?->id;
    // Console and Audio ($listKind): a cover column under the Release heading and a Genre column after Category; Audio's cover is square.
    $kind = $listKind ?? null;
    $games = $kind !== null;
@endphp
<x-pager-line :page="$filters->page" :last-page="$lastPage" :total="$total" :per-page="\App\Data\ReleaseListFilters::PER_PAGE" noun="release" :url="$pageUrl"
              :clear-all="route($listRoute, [\App\Services\Releases\RememberedListFilters::CLEAR => 1])" :filtered="$filters->any()" />
@if($rows === [])
    <p class="tv-empty">{{ $matching === '' ? $emptyText : 'No releases match '.$matching.'.' }}</p>
@else
    {{-- No picture, Files or Grabs column (SPEC 5.4): is-shelf colours the Category, Size and date cells by class; Console and Audio add the cover and Genre. --}}
    <table class="tv-feed is-shelf" data-list-page="{{ $filters->page }}">
        @if($games)
            <colgroup><col class="tv-col-select"><col class="{{ $kind === 'audio' ? 'tv-col-cover' : 'tv-col-art' }}"><col><col class="tv-col-category"><col class="tv-col-genre"><col class="tv-col-size"><col class="tv-col-date"><col class="tv-col-actions"></colgroup>
        @else
            <colgroup><col class="tv-col-select"><col><col class="tv-col-category"><col class="tv-col-size"><col class="tv-col-date"><col class="tv-col-actions"></colgroup>
        @endif
        <thead>
            <tr>
                <th class="tv-select-all" data-part="releases table header cell"><input type="checkbox" data-select-all aria-label="Select all releases on this page"></th>
                @if($games)
                    <th colspan="2">Release</th>
                @else
                    <th>Release</th>
                @endif
                <th class="tv-category">Category</th>
                @if($games)
                    <th class="tv-genre">Genre</th>
                @endif
                <th class="tv-num">Size</th>
                <th class="tv-num">{{ $byAdded ? 'Added' : 'Posted' }}</th>
                <th><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                @include('shelf.releases.row', ['row' => $row, 'first' => $loop->first, 'chipBaseId' => $chipBaseId])
            @endforeach
        </tbody>
    </table>
    <x-pager :page="$filters->page" :last-page="$lastPage" :url="$pageUrl" :action="route($listRoute)" :query="$filters->query(1)" />
@endif
