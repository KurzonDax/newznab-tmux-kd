@props(['page', 'lastPage', 'url', 'action', 'query' => []])
{{-- Numbered pagination with "Go to page"; omitted when everything fits on one page. --}}
@php
    /** @var \Closure(int): string $url */
    $numbers = collect([1, 2, $lastPage - 1, $lastPage, $page - 2, $page - 1, $page, $page + 1, $page + 2])
        ->filter(fn (int $number): bool => $number >= 1 && $number <= $lastPage)->unique()->sort()->values();
@endphp
@if($lastPage > 1)
    <nav {{ $attributes->class('pager-full') }} aria-label="Pages">
        @if($page > 1)
            <a href="{{ $url($page - 1) }}" rel="prev"><i class="fas fa-arrow-left" aria-hidden="true"></i>Previous</a>
        @else
            <span class="is-off">Previous</span>
        @endif
        @foreach($numbers as $number)
            @if(! $loop->first && $number - $numbers[$loop->index - 1] > 1)
                <span class="pager-full-gap">…</span>
            @endif
            @if($number === $page)
                <span class="pager-full-current" aria-current="page" data-part="bottom pager current page">{{ $number }}</span>
            @else
                <a href="{{ $url($number) }}" aria-label="Page {{ $number }}">{{ $number }}</a>
            @endif
        @endforeach
        @if($page < $lastPage)
            <a href="{{ $url($page + 1) }}" rel="next">Next<i class="fas fa-arrow-right" aria-hidden="true"></i></a>
        @else
            <span class="is-off">Next</span>
        @endif
        <form method="GET" action="{{ $action }}">
            @foreach($query as $key => $values)
                @foreach((array) $values as $value)
                    <input type="hidden" name="{{ is_array($values) ? $key.'[]' : $key }}" value="{{ $value }}">
                @endforeach
            @endforeach
            <label for="pager-go-to">Go to page</label><input id="pager-go-to" name="page" inputmode="numeric" autocomplete="off">
        </form>
    </nav>
@endif
