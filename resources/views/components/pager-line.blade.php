@props(['page', 'lastPage', 'total', 'perPage', 'noun', 'url', 'clearAll' => null, 'filtered' => false, 'single' => false, 'fixed' => false])
{{--
    "Showing X–Y of N" with previous / "Page X of Y" / next: always rendered, the same size in every state.
    With `clearAll` (the unfiltered URL) the line holds "Clear all" in a fixed slot left of the arrows,
    hidden but keeping its place while nothing is `filtered`, and the page text has a fixed width.
    `fixed` gives the page text its fixed width without the slot. With `single`, one result reads
    "Showing 1 film" rather than "Showing 1–1 of 1 film" (the Films wall's wording). A `status` slot
    (a poster list's blacklist sweep, generic-release-lists SPEC 5.3) sits between the count and Clear all.
--}}
@php
    /** @var \Closure(int): string $url */
    $from = $total > 0 ? ($page - 1) * $perPage + 1 : 0;
    $to = min($page * $perPage, $total);
    $summary = match (true) {
        $single && $total === 1 => 'Showing 1 '.$noun,
        $total > 0 => 'Showing '.number_format($from).'–'.number_format($to).' of '.number_format($total).' '.($total === 1 ? $noun : \Illuminate\Support\Str::plural($noun)),
        default => 'Showing 0 '.\Illuminate\Support\Str::plural($noun),
    };
@endphp
<nav {{ $attributes->class(['pager-line', 'is-fixed' => $clearAll !== null || $fixed]) }} aria-label="Pages">
    <span class="pager-line-summary" data-part="showing line">{{ $summary }}</span>
    @isset($status)
        <span class="pager-line-status">{{ $status }}</span>
    @endisset
    @if($clearAll !== null)
        <a href="{{ $clearAll }}" @class(['pager-line-clear', 'is-hidden' => ! $filtered]) data-clear-all aria-hidden="{{ $filtered ? 'false' : 'true' }}"@unless($filtered) tabindex="-1"@endunless>Clear all</a>
    @endif
    @if($page > 1)
        <a href="{{ $url($page - 1) }}" rel="prev" aria-label="Previous page" data-part="pager arrow"><i class="fas fa-arrow-left" aria-hidden="true"></i></a>
    @else
        <span class="is-off" data-part="pager arrow"><i class="fas fa-arrow-left" aria-hidden="true"></i></span>
    @endif
    <span class="pager-line-page" data-part="page x of y">Page {{ number_format($page) }} of {{ number_format($lastPage) }}</span>
    @if($page < $lastPage)
        <a href="{{ $url($page + 1) }}" rel="next" aria-label="Next page"><i class="fas fa-arrow-right" aria-hidden="true"></i></a>
    @else
        <span class="is-off"><i class="fas fa-arrow-right" aria-hidden="true"></i></span>
    @endif
</nav>
