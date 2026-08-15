<textarea {{ $attributes->merge(['type' => 'text', 'class' => 'px-3 py-1 border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:border-primary-500 dark:focus:border-primary-400 focus:ring-primary-500 dark:focus:ring-primary-400 rounded-md border shadow-sm transition-colors']) }}>{{ $slot }}</textarea>

