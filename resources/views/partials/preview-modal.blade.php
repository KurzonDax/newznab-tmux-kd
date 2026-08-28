<!-- Preview/Sample Image Modal - Alpine.js CSP Safe -->
<div x-data="previewModal"
     x-show="open"
     x-cloak
     class="fixed inset-0 z-50 overflow-y-auto"
     aria-labelledby="preview-modal-title"
     role="dialog"
     aria-modal="true"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0">

    <!-- Background overlay -->
    <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75 transition-opacity"
         aria-hidden="true"
         @click="close()"></div>

    <!-- Modal panel container -->
    <div class="fixed inset-0 z-10 overflow-y-auto" @click.self="close()">
        <div class="flex min-h-full items-center justify-center p-4 text-center sm:p-0" @click.self="close()">
            <div class="relative transform overflow-hidden rounded-2xl bg-white dark:bg-gray-800 text-left shadow-xl transition-all sm:my-8 w-auto max-w-[90vw]"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 scale-95"
                 x-transition:enter-end="opacity-100 scale-100"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100 scale-100"
                 x-transition:leave-end="opacity-0 scale-95">
            <div class="bg-white dark:bg-gray-800 px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100" id="preview-modal-title" x-text="title">
                        Preview Image
                    </h3>
                    <button type="button" class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300" @click="close()">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>

                <x-audio-preview-player dynamic class="mb-4" />

                <!-- Loading state -->
                <div x-show="imageUrl && !imageLoaded && !imageError && !videoPlaying" class="flex items-center justify-center py-8">
                    <i class="fas fa-spinner fa-spin text-2xl mr-2 text-purple-600 dark:text-purple-400"></i>
                    <span class="text-gray-600 dark:text-gray-400">Loading image...</span>
                </div>

                <!-- Error state -->
                <div x-show="imageUrl && imageError && !videoPlaying" class="text-center py-8">
                    <i class="fas fa-image text-3xl text-gray-400 dark:text-gray-500"></i>
                    <p class="text-gray-500 dark:text-gray-400 mt-2" x-text="errorMessage()">Image not available</p>
                </div>

                <!-- Image -->
                <div x-show="imageUrl && imageLoaded && !imageError && !videoPlaying" class="flex justify-center">
                    <div class="relative inline-block">
                        <img :src="imageUrl"
                             :alt="title"
                             x-on:error="onImageError()"
                             @load="onImageLoad()"
                             decoding="async"
                             fetchpriority="high"
                             class="max-w-full max-h-[80vh] rounded-lg shadow-lg">

                        <!-- Fullscreen view: offered only where a Full-size copy exists -->
                        <button type="button"
                                x-show="fullUrl"
                                x-cloak
                                @click="enterFullscreen()"
                                class="absolute bottom-2 right-2 inline-flex items-center justify-center h-9 w-9 rounded-md bg-gray-900/70 text-white hover:bg-gray-900/90 focus:outline-none focus:ring-2 focus:ring-primary-500"
                                title="View full size"
                                aria-label="View full size">
                            <i class="fas fa-expand"></i>
                        </button>
                    </div>
                </div>

                <!-- Video: replaces the image in the same space once play is
                     pressed; no src until then, so no bytes are fetched. -->
                <div x-show="videoPlaying" class="flex justify-center">
                    <video x-ref="videoPlayer"
                           controls
                           preload="none"
                           class="max-w-full max-h-[80vh] rounded-lg shadow-lg">
                        <source>
                        Your browser does not support playing this video preview.
                    </video>
                </div>

                <!-- Media control: only rendered when a playable video artifact exists -->
                <div x-show="videoUrl && !videoPlaying" class="mt-4 flex justify-center">
                    <x-button type="button" icon="fas fa-play" @click="playVideo()">
                        Play video preview
                    </x-button>
                </div>
            </div>
            <div class="bg-gray-50 dark:bg-gray-700 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                <button type="button"
                        @click="close()"
                        class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 dark:border-gray-600 shadow-sm px-4 py-2 bg-white dark:bg-gray-600 text-base font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                    Close
                </button>
            </div>
            </div>
        </div>
    </div>

    <!-- Fullscreen view: the Full-size copy fitted entirely inside the viewport -->
    <div x-show="fullscreen"
         x-cloak
         class="fixed inset-0 z-20 flex items-center justify-center bg-gray-950/95 p-4"
         @click.self="exitFullscreen()"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
        <img :src="fullUrl"
             :alt="title"
             decoding="async"
             class="max-w-full max-h-full object-contain">

        <button type="button"
                @click="exitFullscreen()"
                class="absolute top-4 right-4 inline-flex items-center justify-center h-10 w-10 rounded-md bg-gray-900/70 text-white hover:bg-gray-900/90 focus:outline-none focus:ring-2 focus:ring-primary-500"
                title="Exit full size"
                aria-label="Exit full size">
            <i class="fas fa-compress text-lg"></i>
        </button>
    </div>
</div>
