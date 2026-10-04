@php
    /**
     * "All N releases of this album" (docs/proposals/audio-redesign/SPEC.md 5B.6), the section's content
     * and the fragment a sort change or another page loads (?_fragment=releases): 50 a page, the
     * Showing line and the bottom pager when there is more than one page. A page link names its page;
     * a sort change names none, so the table returns to the page holding this release.
     *
     * @var \App\Data\AudioReleaseRow $row
     * @var \App\Data\ConsoleGamePageFilters $table
     * @var list<\App\Data\AudioReleaseRow> $tableRows
     */
    // The sort alone (page 1 leaves its page out): every page link adds its own.
    $sortQuery = $table->query(1);
    $pageUrl = static fn (int $page): string => route('details', ['guid' => $row->guid, ...$sortQuery, 'page' => $page]).'#releases';
@endphp
<h2 id="album-releases-heading">{{ $tableTotal > 1 ? 'All '.number_format($tableTotal).' releases of this album' : 'The only release of this album' }}</h2>
@if($tableLastPage > 1)
    <x-pager-line :page="$table->page" :last-page="$tableLastPage" :total="$tableTotal" :per-page="\App\Data\ConsoleGamePageFilters::PER_PAGE" noun="release" :url="$pageUrl" fixed />
@endif
@include('details.shelf.table', ['rows' => $tableRows, 'current' => $row->guid, 'sortable' => 'data-sort', 'sort' => $table->sort, 'ascending' => $table->ascending])
<x-pager :page="$table->page" :last-page="$tableLastPage" :url="$pageUrl" :action="route('details', ['guid' => $row->guid]).'#releases'" :query="$sortQuery" />
