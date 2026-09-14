@blaze(fold: true)
@props(['controlSize' => 'md', 'width' => 'field'])
<select {{ $attributes->merge(['class' => 'ui-control ui-field '.($controlSize === 'sm' ? 'ui-control-sm' : 'ui-control-md').($width === 'compact' ? ' ui-select-compact' : ' w-full').' rounded-lg border border-gray-300 bg-white px-3 py-2 text-gray-900 shadow-sm transition focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100']) }}>
    {{ $slot }}
</select>
