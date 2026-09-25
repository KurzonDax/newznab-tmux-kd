@php
    $pageUrl = fn (int $page): string => route('tv.releases', $filters->query($page));
    $byAdded = $filters->sortsByAdded();
    $chipBaseId = collect($runs)->flatMap(fn (array $run): array => array_slice($run['rows'], 0, $run['collapsible'] ? \App\Services\Releases\TvReleaseBatches::SHOWN : null))->first(fn ($row): bool => $row->hasChips())?->id;
@endphp
<x-pager-line :page="$filters->page" :last-page="$lastPage" :total="$total" :per-page="\App\Data\TvReleaseFilters::PER_PAGE" noun="release" :url="$pageUrl" />
@if($runs === [])
    @php
        $matching = $filters->describe($categoryMenu);
    @endphp
    <p class="tv-empty">{{ $matching === '' ? 'There are no TV releases yet.' : 'Nothing matches '.$matching.'.' }}</p>
@else
    <table class="tv-feed" data-list-page="{{ $filters->page }}">
        <colgroup><col class="tv-col-select"><col class="tv-col-art"><col><col class="tv-col-resolution"><col class="tv-col-source"><col class="tv-col-size"><col class="tv-col-files"><col class="tv-col-date"><col class="tv-col-grabs"><col class="tv-col-actions"></colgroup>
        <thead>
            <tr>
                <th class="tv-select-all" data-part="releases table header cell"><input type="checkbox" data-select-all aria-label="Select all releases on this page"></th>
                <th colspan="2">Release</th>
                <th>Resolution</th>
                <th>Source</th>
                <th class="tv-num">Size</th>
                <th class="tv-num">Files</th>
                <th class="tv-num">{{ $byAdded ? 'Added' : 'Posted' }}</th>
                <th class="tv-num">Grabs</th>
                <th><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            @foreach($runs as $run)
                @foreach($run['rows'] as $row)
                    @include('tv.releases.row', ['row' => $row, 'batch' => $run['collapsible'] && $loop->index >= \App\Services\Releases\TvReleaseBatches::SHOWN ? $run['key'] : null, 'first' => $loop->parent->first && $loop->first, 'chipBaseId' => $chipBaseId])
                @endforeach
                @if($run['collapsible'])
                    @php
                        $more = 'Show '.(count($run['rows']) - \App\Services\Releases\TvReleaseBatches::SHOWN).' more from '.$run['show'].' posted in the same batch';
                    @endphp
                    <tr class="tv-batch">
                        <td colspan="10"><div><button type="button" data-expand="{{ $run['key'] }}" aria-expanded="false" data-label-closed="{{ $more }}" data-label-open="Show fewer from {{ $run['show'] }}" data-part="batch expander"><i class="fas fa-chevron-down" aria-hidden="true"></i><span>{{ $more }}</span></button></div></td>
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>
    <x-pager :page="$filters->page" :last-page="$lastPage" :url="$pageUrl" :action="route('tv.releases')" :query="$filters->query(1)" />
@endif
