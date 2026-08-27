<div class="relative inline-block" x-data="sortDropdown" @click.outside="close()">
    <button
        type="button"
        @click="toggle"
        class="inline-flex items-center gap-2 px-3 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:focus:ring-primary-400 transition shadow-sm"
        title="Filter by how much of each release the indexer has seen"
    >
        <i class="fas fa-chart-pie text-gray-500 dark:text-gray-300"></i>
        <span>Completion: {{ $currentLabel }}</span>
        <i class="fas fa-chevron-down text-xs text-gray-400 dark:text-gray-300 transition-transform" :class="chevronClass()"></i>
    </button>

    <div x-show="open"
         x-cloak
         x-transition:enter="transition ease-out duration-100"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-75"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         class="absolute right-0 z-50 mt-2 w-56 origin-top-right rounded-lg bg-white dark:bg-gray-700 shadow-lg border border-gray-200 dark:border-gray-600 focus:outline-none">
        <div class="py-1">
            @foreach($thresholds as $threshold => $label)
                <a
                    href="{{ $thresholdUrls[$threshold] }}"
                    class="flex items-center gap-3 px-4 py-2 text-sm transition {{ $currentThreshold === $threshold ? 'bg-primary-100 dark:bg-primary-600 text-primary-800 dark:text-white font-medium' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-600' }}"
                >
                    <span class="flex-1">{{ $label }}</span>
                    @if($currentThreshold === $threshold)
                        <i class="fas fa-check text-primary-600 dark:text-primary-200"></i>
                    @endif
                </a>
            @endforeach
        </div>
    </div>
</div>
