<button {{ $attributes->merge(['class' => 'bg-primary-500 hover:bg-primary-400 dark:bg-primary-600 dark:hover:bg-primary-500 text-white font-semibold px-3 py-2 rounded-md inline-block transition-colors']) }}>
    {{ $slot }}
</button>
