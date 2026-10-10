@php
    /**
     * The generic lists' list fragment (docs/proposals/generic-release-lists/SPEC.md 5.3, 5.4): the pager line, on a
     * poster's list with the blacklist sweep's status in its reserved slot between the count and Clear all; the table
     * with the "Root > Sub" Category column (the sub-category alone, 92 px, on Other); the bottom pager. Clear all
     * keeps the list's identity. The sweep-specific empty line appears only after a confirmed complete sweep that left
     * no release of the exact poster (issue #1032 correction 1).
     *
     * @var \App\Data\GenericListContext $context
     * @var \App\Data\GenericReleaseFilters $filters
     * @var list<\App\Data\GenericReleaseRow> $rows
     * @var array<string, mixed>|null $sweep
     */
    $pageUrl = fn (int $page): string => route($listRoute, $filters->query($page));
    $byAdded = $filters->sortsByAdded();
    $chipBaseId = collect($rows)->first(fn ($row): bool => $row->hasChips())?->id;
    $sweep ??= null;
    $swept = ($sweep['state'] ?? null) === \App\Services\PosterIdentityBrowserContext::COMPLETE && $total === 0;
    // The chip naming the list's own context is left off every row (SPEC 5.5).
    $originOmits = $context->isGroup() ? 'group' : ($context->isPoster() ? 'poster' : null);
    $pagerQuery = array_diff_key($filters->query(1), ['parentCategory' => true]);
@endphp
<x-pager-line :page="$filters->page" :last-page="$lastPage" :total="$total" :per-page="\App\Data\ReleaseListFilters::PER_PAGE" noun="release" :url="$pageUrl"
              :clear-all="route($listRoute, [...$context->routeParameters(), \App\Services\Releases\RememberedListFilters::CLEAR => 1])" :filtered="$filters->any()">
    @if($context->isPoster() && $context->key !== '' && (auth()->user()?->hasRole('Admin') ?? false))
        <x-slot:status>@include('generic.releases.sweep-status')</x-slot:status>
    @endif
</x-pager-line>
@if($rows === [])
    <p class="tv-empty" data-empty>{{ $swept ? 'No releases remain: the blacklist sweep removed them.' : ($matching === '' ? $emptyText : 'No releases match '.$matching.'.') }}</p>
@else
    <table @class(['tv-feed is-shelf is-generic', 'is-other' => $context->isOther()]) data-list-page="{{ $filters->page }}">
        <colgroup><col class="tv-col-select"><col><col class="tv-col-category-path"><col class="tv-col-size"><col class="tv-col-date"><col class="tv-col-actions"></colgroup>
        <thead>
            <tr>
                <th class="tv-select-all" data-part="releases table header cell"><input type="checkbox" data-select-all aria-label="Select all releases on this page"></th>
                <th>Release</th>
                <th class="tv-category">Category</th>
                <th class="tv-num">Size</th>
                <th class="tv-num">{{ $byAdded ? 'Added' : 'Posted' }}</th>
                <th><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                @include('generic.releases.row', ['row' => $row, 'first' => $loop->first, 'chipBaseId' => $chipBaseId, 'originOmits' => $originOmits])
            @endforeach
        </tbody>
    </table>
    <x-pager :page="$filters->page" :last-page="$lastPage" :url="$pageUrl" :action="route($listRoute, $context->isOther() ? ['parentCategory' => 'other'] : [])" :query="$pagerQuery" />
@endif
