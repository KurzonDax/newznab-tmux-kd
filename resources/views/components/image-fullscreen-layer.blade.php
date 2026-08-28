@props(['titleProperty' => 'title'])

{{--
    The Fullscreen view (CONTEXT.md): the release's Full-size copy fitted
    entirely inside the viewport, entered from the corner control on the modal
    image and exited back to the modal it came from.

    Both image modals use this, so the alt-text binding differs: pass the name
    of the component property holding the caption.

    The scrim stays near-black in both themes on purpose -- it exists to put a
    photograph against a neutral ground, not to render a surface -- but it says
    so at the source rather than relying on rescue CSS.
--}}
<div x-show="fullscreen"
     x-cloak
     class="fixed inset-0 z-20 flex items-center justify-center bg-gray-900/95 dark:bg-gray-950/95 p-4"
     @click.self="exitFullscreen()"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0">
    <img :src="fullUrl"
         :alt="{{ $titleProperty }}"
         decoding="async"
         class="max-w-full max-h-full object-contain">

    <button type="button"
            @click="exitFullscreen()"
            class="absolute top-4 right-4 inline-flex items-center justify-center h-10 w-10 rounded-md bg-gray-800/80 dark:bg-gray-950/80 text-white dark:text-gray-100 hover:bg-gray-800 dark:hover:bg-gray-950 focus:outline-none focus:ring-2 focus:ring-primary-500"
            title="Exit full size"
            aria-label="Exit full size">
        <i class="fas fa-compress text-lg"></i>
    </button>
</div>
