{{--
    The corner control that opens the Fullscreen view. Rendered only when the
    trigger supplied a Full-size copy URL, so the back catalog -- which has only
    a thumb on disk -- never offers more pixels than it can deliver.

    Deliberately not <x-button>: it sits over a photograph and needs a
    translucent dark ground in both themes, which would mean overriding the
    variant's own bg/text utilities with no tailwind-merge to settle the clash.
--}}
<button type="button"
        x-show="fullUrl"
        x-cloak
        @click="enterFullscreen()"
        class="absolute bottom-2 right-2 inline-flex items-center justify-center h-9 w-9 rounded-md bg-gray-800/80 dark:bg-gray-950/80 text-white dark:text-gray-100 hover:bg-gray-800 dark:hover:bg-gray-950 focus:outline-none focus:ring-2 focus:ring-primary-500"
        title="View full size"
        aria-label="View full size">
    <i class="fas fa-expand"></i>
</button>
