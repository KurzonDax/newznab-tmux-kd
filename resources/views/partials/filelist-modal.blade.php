<div x-data="filelistModal">
    <x-modal name="filelist" width="lg">
        <x-slot:title>File list</x-slot:title>
        <x-slot:icon><i class="fas fa-folder-open text-primary-600 dark:text-primary-400"></i></x-slot:icon>
        <x-slot:subtitle><span x-text="releaseName"></span></x-slot:subtitle>
        @include('partials.file-summary-controls')
        <div x-ref="content" class="overflow-x-auto"></div>
        <x-button variant="secondary" size="sm" x-on:click="loadFilePage()" ::disabled="filesLoading" class="mt-2">Reload page</x-button>
    </x-modal>
</div>
