{{--
    The four pipeline stages, with the one this page belongs to highlighted.

    A page that governs every stage at once (the engine) passes no stage and gets the strip
    drawn flat, which says "all of it" more honestly than highlighting one box would.
--}}
@props([
    'stages',
    'current' => null,
])

<nav aria-label="Pipeline stage" class="flex flex-wrap items-center gap-1 text-xs font-medium">
    @foreach($stages as $stage)
        @if(!$loop->first)
            <i class="fas fa-chevron-right text-gray-300 dark:text-gray-600" aria-hidden="true"></i>
        @endif
        <span @class([
            'rounded-full px-3 py-1',
            'bg-primary-600 text-white dark:bg-primary-500' => $current === $stage,
            'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' => $current !== $stage,
        ]) @if($current === $stage) aria-current="step" @endif>{{ $stage->label() }}</span>
    @endforeach
</nav>
