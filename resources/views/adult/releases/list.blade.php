@php
    $pageUrl = fn (int $page): string => route('adult.releases', $filters->query($page));
    $byAdded = $filters->sortsByAdded();
    $chipBaseId = collect($rows)->first(fn ($row): bool => $row->hasChips())?->id;
@endphp
<x-pager-line :page="$filters->page" :last-page="$lastPage" :total="$total" :per-page="\App\Data\AdultReleaseFilters::PER_PAGE" noun="release" :url="$pageUrl"
              :clear-all="route('adult.releases', [\App\Services\Releases\RememberedListFilters::CLEAR => 1])" :filtered="$filters->any()" />
@if($rows === [])
    @php
        $matching = $filters->describe($categoryMenu, $audioMenu);
    @endphp
    <p class="tv-empty">{{ $matching === '' ? 'There are no adult releases yet.' : 'No releases match '.$matching.'.' }}</p>
@else
    {{-- No Source column (SPEC 5.4): Size is the fifth cell and the date the sixth, so is-adult keeps Size in ink and the date dim. --}}
    <table class="tv-feed is-adult" data-list-page="{{ $filters->page }}">
        <colgroup><col class="tv-col-select"><col class="tv-col-picture"><col><col class="tv-col-resolution"><col class="tv-col-size"><col class="tv-col-date"><col class="tv-col-actions"></colgroup>
        <thead>
            <tr>
                <th class="tv-select-all" data-part="releases table header cell"><input type="checkbox" data-select-all aria-label="Select all releases on this page"></th>
                <th colspan="2">Release</th>
                <th>Resolution</th>
                <th class="tv-num">Size</th>
                <th class="tv-num">{{ $byAdded ? 'Added' : 'Posted' }}</th>
                <th><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                @include('adult.releases.row', ['row' => $row, 'first' => $loop->first, 'chipBaseId' => $chipBaseId])
            @endforeach
        </tbody>
    </table>
    <x-pager :page="$filters->page" :last-page="$lastPage" :url="$pageUrl" :action="route('adult.releases')" :query="$filters->query(1)" />
@endif
