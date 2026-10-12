{{-- Admin card wrapper with consistent styling --}}

<div {{ $attributes->merge(['class' => 'bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700']) }}>
    {{ $slot }}
</div>
