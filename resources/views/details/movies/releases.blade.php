@php
    /**
     * "All N releases of this film" (SPEC 5C.4), the section's content and the fragment a sort change
     * or another page loads (?_fragment=releases): 50 a page, the Showing line and the bottom pager
     * when there is more than one page. A page link names its page; a sort change names none, so the
     * table returns to the page holding this release.
     *
     * @var \App\Data\MovieReleaseRow $row
     * @var \App\Data\MovieFilmPageFilters $table
     * @var list<\App\Data\MovieReleaseRow> $tableRows
     */
    $sortQuery = $table->isDefaultSort() ? [] : ['sort' => $table->sort.($table->ascending ? '_asc' : '')];
    $pageUrl = static fn (int $page): string => route('details', ['guid' => $row->guid, ...$sortQuery, 'page' => $page]).'#releases';
@endphp
<h2 id="film-releases-heading" data-part="film releases heading">{{ $tableTotal > 1 ? 'All '.number_format($tableTotal).' releases of this film' : 'The only release of this film' }}</h2>
@if($tableLastPage > 1)
    <x-pager-line :page="$table->page" :last-page="$tableLastPage" :total="$tableTotal" :per-page="\App\Data\MovieFilmPageFilters::PER_PAGE" noun="release" :url="$pageUrl" fixed />
@endif
@include('details.movies.table', ['rows' => $tableRows, 'current' => $row->guid, 'filmLine' => false, 'sortable' => 'data-sort', 'sort' => $table->sort, 'ascending' => $table->ascending])
<x-pager :page="$table->page" :last-page="$tableLastPage" :url="$pageUrl" :action="route('details', ['guid' => $row->guid]).'#releases'" :query="$sortQuery" />
