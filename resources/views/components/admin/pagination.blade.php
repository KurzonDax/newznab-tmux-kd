{{-- Admin pagination wrapper --}}
@props([
    'paginator',
    'onEachSide' => 5,
])

@if($paginator->hasPages())
    <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900">
        {{ $paginator->onEachSide((int) $onEachSide)->links() }}
    </div>
@endif

