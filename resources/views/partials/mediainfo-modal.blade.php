<!-- Media Info Modal - Alpine.js CSP Safe -->
<div x-data="mediainfoModal"
     x-show="open"
     x-cloak
     class="fixed inset-0 z-50"
     aria-labelledby="mediainfo-modal-title"
     role="dialog"
     aria-modal="true"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0">
    <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75"
         aria-hidden="true"
         @click="close()"></div>

    <div class="fixed inset-0 z-10 overflow-y-auto p-4 sm:p-6" @click.self="close()">
        <div class="flex min-h-full items-center justify-center" @click.self="close()">
            <section class="card relative flex max-h-[calc(100vh-2rem)] w-full max-w-3xl transform flex-col overflow-hidden text-left shadow-xl transition-all sm:max-h-[calc(100vh-3rem)]"
                     @click.stop
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 scale-95"
                     x-transition:enter-end="opacity-100 scale-100"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100 scale-100"
                     x-transition:leave-end="opacity-0 scale-95">
                <header class="flex shrink-0 items-start gap-4 border-b border-gray-200 px-4 py-4 dark:border-gray-700 sm:px-6">
                    <h3 class="flex min-w-0 flex-1 items-baseline gap-2 text-base font-semibold text-gray-900 dark:text-gray-100"
                        id="mediainfo-modal-title">
                        <i class="fas fa-circle-info shrink-0 text-primary-600 dark:text-primary-400" aria-hidden="true"></i>
                        <span class="min-w-0 wrap-anywhere">
                            Media Information<span x-show="releaseName"> - <span x-text="releaseName"></span></span>
                        </span>
                    </h3>
                    <button type="button"
                            x-ref="closeButton"
                            class="-mr-2 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                            aria-label="Close media information"
                            @click="close()">
                        <i class="fas fa-times" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto px-4 py-5 sm:px-6">
                    <div x-show="loading" class="flex items-center justify-center py-10 text-gray-600 dark:text-gray-400">
                        <i class="fas fa-spinner fa-spin mr-2 text-2xl text-primary-600 dark:text-primary-400" aria-hidden="true"></i>
                        <span>Loading media info...</span>
                    </div>
                    <div x-show="!loading" x-ref="content"></div>
                </div>

                <footer class="surface-panel-alt flex shrink-0 justify-end border-t px-4 py-3 sm:px-6">
                    <x-button type="button" variant="muted" size="sm" @click="close()">
                        Close
                    </x-button>
                </footer>
            </section>
        </div>
    </div>
</div>
