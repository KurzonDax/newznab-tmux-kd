@php
    // The Previews wall's list (docs/proposals/adult-redesign/SPEC.md 5B.2): the list's pager line and words, 40 cards a page, no selection.
    $pageUrl = fn (int $page): string => route('adult.previews', $filters->query($page));
    $byAdded = $filters->sortsByAdded();
@endphp
<x-pager-line :page="$filters->page" :last-page="$lastPage" :total="$total" :per-page="$perPage" noun="release" :url="$pageUrl"
              :clear-all="route('adult.previews', [\App\Services\Releases\RememberedListFilters::CLEAR => 1])" :filtered="$filters->any()" />
@if($rows === [])
    @php
        $matching = $filters->describe($categoryMenu, $audioMenu);
    @endphp
    <p class="tv-empty">{{ $matching === '' ? 'There are no adult releases with a picture yet.' : 'No releases match '.$matching.'.' }}</p>
@else
    <ul class="adult-wall" aria-label="Releases">
        @foreach($rows as $row)
            @include('adult.releases.card', ['row' => $row, 'byAdded' => $byAdded])
        @endforeach
    </ul>
    <x-pager :page="$filters->page" :last-page="$lastPage" :url="$pageUrl" :action="route('adult.previews')" :query="$filters->query(1)" />
@endif
