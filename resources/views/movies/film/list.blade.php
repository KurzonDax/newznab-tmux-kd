@php
    /**
     * The film page's releases under the filter bar (SPEC 5B.2): the Showing line with Clear all
     * and the page arrows, one table (a select box per row and no check-all box; sortable
     * Resolution / Size / Posted headings, the first click descending; no Grabs column; the
     * releases list's 2 × 2 buttons without Follow, which lives in the header), the bottom pager.
     * Every page link opens at the Releases heading.
     *
     * @var \App\Data\MovieFilmHeader $film
     * @var \App\Data\MovieFilmPageFilters $filters
     * @var list<\App\Data\MovieReleaseRow> $rows
     */
    $filmUrl = static fn (array $query): string => route('movies.film', ['movieinfoId' => $film->id, ...$query]).'#releases';
    $pageUrl = static fn (int $page): string => $filmUrl($filters->query($page));
    $sortable = ['resolution' => 'Resolution', 'size' => 'Size', 'posted' => 'Posted'];
    $heading = static fn (string $key): string => '<button type="button" data-sort="'.$key.'">'.e($sortable[$key]).'<i class="fas fa-sort" aria-hidden="true"></i></button>';
    $sorted = static fn (string $key): string => $filters->sort === $key ? ' aria-sort="'.($filters->ascending ? 'ascending' : 'descending').'"' : '';
    $chipPart = static fn (string $name): ?string => null;
@endphp
<x-pager-line :page="$filters->page" :last-page="$lastPage" :total="$total" :per-page="\App\Data\MovieFilmPageFilters::PER_PAGE" noun="release" :url="$pageUrl"
              :clear-all="$filmUrl($filters->withoutFilters()->query())" :filtered="$filters->any()" />
@if($rows === [])
    <p class="tv-empty">{{ $filters->any() ? 'No releases of this film match '.$filters->describe().'.' : 'There are no releases of this film yet.' }}</p>
@else
    <table class="tv-release-table is-pick">
        <colgroup><col class="tv-col-select"><col><col class="tv-col-resolution"><col class="tv-col-source"><col class="tv-col-size"><col class="tv-col-files"><col class="tv-col-posted"><col class="tv-col-actions"></colgroup>
        <thead>
            <tr>
                <th class="tv-select-cell"><span class="sr-only">Select</span></th>
                <th>Release</th>
                <th{!! $sorted('resolution') !!}>{!! $heading('resolution') !!}</th>
                <th>Source</th>
                <th class="tv-num"{!! $sorted('size') !!}>{!! $heading('size') !!}</th>
                <th class="tv-num">Files</th>
                <th class="tv-num"{!! $sorted('posted') !!}>{!! $heading('posted') !!}</th>
                <th><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr data-release-row>
                    <td class="tv-select-cell"><input type="checkbox" data-select value="{{ $row->guid }}" aria-label="Select {{ $row->name }}"></td>
                    <td class="tv-what">
                        <a class="tv-release-name" href="{{ route('details', $row->guid) }}" title="{{ $row->name }}">{{ $row->name }}</a>
                        @include('tv.partials.release-chips', ['clip' => null])
                    </td>
                    <td><x-resolution-chip :resolution="$row->resolution" :part="false" /></td>
                    <td class="tv-nowrap">{{ $row->source }}</td>
                    <td class="tv-num">{{ $row->size }}</td>
                    <td class="tv-num"><button type="button" class="tv-files filelist-badge" data-guid="{{ $row->guid }}" title="View file list">{{ $row->files }}</button></td>
                    <td class="tv-num" title="{{ $row->dateTitle }}">{{ $row->postedOn }}</td>
                    <td>
                        @include('movies.partials.release-actions', ['parts' => false, 'follow' => false])
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <x-pager :page="$filters->page" :last-page="$lastPage" :url="$pageUrl" :action="route('movies.film', ['movieinfoId' => $film->id]).'#releases'" :query="$filters->query(1)" />
@endif
