{{-- Admin data table with consistent thead styling --}}
@props([
    'sticky' => false,
])

<div class="overflow-x-auto overscroll-x-contain">
    <table {{ $attributes->merge(['class' => 'min-w-full table-auto divide-y divide-gray-200 dark:divide-gray-700']) }}>
        @if(isset($head))
            <thead @class([
                'bg-gray-50 dark:bg-gray-900',
                'sticky top-0 z-10' => $sticky,
            ])>
                <tr>
                    {{ $head }}
                </tr>
            </thead>
        @endif
        <tbody @class([
            'bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700',
            '[&_td]:px-4 [&_td]:py-3 [&_td]:text-sm',
        ])>
            {{ $slot }}
        </tbody>
    </table>
</div>
