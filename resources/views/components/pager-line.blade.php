@props(['page', 'lastPage', 'total', 'perPage', 'noun', 'url'])
{{-- "Showing X–Y of N" with previous / "Page X of Y" / next: always rendered, the same size in every state. --}}
@php
    /** @var \Closure(int): string $url */
    $from = $total > 0 ? ($page - 1) * $perPage + 1 : 0;
    $to = min($page * $perPage, $total);
    $summary = $total > 0
        ? 'Showing '.number_format($from).'–'.number_format($to).' of '.number_format($total).' '.($total === 1 ? $noun : \Illuminate\Support\Str::plural($noun))
        : 'Showing 0 '.\Illuminate\Support\Str::plural($noun);
@endphp
<nav {{ $attributes->class('pager-line') }} aria-label="Pages">
    <span class="pager-line-summary" data-part="showing line">{{ $summary }}</span>
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
