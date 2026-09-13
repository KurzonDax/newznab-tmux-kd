<div x-data="filelistModal">
    <x-modal name="filelist" width="lg">
        <x-slot:title>File list</x-slot:title>
        <x-slot:icon><i class="fas fa-folder-open text-primary-600 dark:text-primary-400"></i></x-slot:icon>
        <x-slot:subtitle><span x-text="releaseName"></span></x-slot:subtitle>
        <div x-show="loading" class="py-8 text-center text-gray-600 dark:text-gray-400"><i class="fas fa-spinner fa-spin mr-2"></i>Loading file list...</div>
        <div x-show="!loading" x-ref="content"></div>
    </x-modal>
</div>
