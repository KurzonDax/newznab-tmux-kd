@php
    $current = $rows->currentPage();
    $last = $rows->lastPage();
    $pageNumbers = range(max(1, $current - 2), min($last, $current + 2));
@endphp
<nav class="release-browser-pager" aria-label="Release pages">
    <span class="tabular-nums">Page {{ number_format($current) }} of {{ number_format($last) }} · {{ number_format($rows->total()) }} releases
        @if($state->view === 'cards')
            · renamed and post-processed only
            @if(($rows->hiddenCount ?? 0) > 0)
                ({{ number_format($rows->hiddenCount) }} not shown)
            @endif
        @endif
    </span>
    <div class="release-browser-segment" aria-label="Per page">
        <span>Per page</span>
        @foreach([24, 48, 100] as $perPage)
            <button type="button" data-preference="per" data-value="{{ $perPage }}" @click="changePreference" aria-pressed="{{ $state->per === $perPage ? 'true' : 'false' }}">{{ $perPage }}</button>
        @endforeach
    </div>
    <span class="grow"></span>
    <div class="release-browser-pages">
        @if($current === 1)
            <button type="button" disabled aria-label="Previous page">‹</button>
        @else
            <a href="{{ $rows->url($current - 1) }}" aria-label="Previous page">‹</a>
        @endif
        @if($current > 3)
            <a href="{{ $rows->url(1) }}">1</a><span aria-hidden="true">…</span>
        @endif
        @foreach($pageNumbers as $pageNumber)
            <a href="{{ $rows->url($pageNumber) }}" @if($current === $pageNumber) aria-current="page" @endif>{{ $pageNumber }}</a>
        @endforeach
        @if($current < $last - 2)
            <span aria-hidden="true">…</span><a href="{{ $rows->url($last) }}">{{ $last }}</a>
        @endif
        @if($current === $last)
            <button type="button" disabled aria-label="Next page">›</button>
        @else
            <a href="{{ $rows->url($current + 1) }}" aria-label="Next page">›</a>
        @endif
    </div>
    <x-input type="text" inputmode="numeric" control-size="sm" class="release-browser-goto" placeholder="Go to page" aria-label="Go to page" @keydown.enter.prevent="goToPage" />
</nav>
