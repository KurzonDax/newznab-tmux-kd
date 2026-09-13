<div x-data="nfoModal">
    <x-modal name="nfo" width="lg">
        <x-slot:title>NFO</x-slot:title>
        <x-slot:icon><i class="fas fa-file-lines text-yellow-600 dark:text-yellow-400"></i></x-slot:icon>
        <x-slot:subtitle><span x-text="releaseName"></span></x-slot:subtitle>
        <div x-show="loading" class="py-8 text-center text-gray-600 dark:text-gray-400"><i class="fas fa-spinner fa-spin mr-2"></i>Loading NFO...</div>
        <p x-show="error && !loading" class="py-8 text-center text-red-600 dark:text-red-400">Failed to load NFO file</p>
        <div x-show="!loading && !error" class="public-modal-nfo" x-text="content"></div>
        <x-slot:footer>
            <x-button variant="secondary" size="sm" icon="fas fa-copy" @click="copyText()" ::disabled="loading || error">Copy text</x-button>
            <x-button variant="secondary" size="sm" icon="fas fa-file-arrow-down" @click="downloadNfo()" ::disabled="loading || error">Download .nfo</x-button>
            <span class="flex-1"></span>
            <x-button-link variant="secondary" size="sm" ::href="detailsUrl()" icon="fas fa-circle-info">Details</x-button-link>
            <x-button-link variant="success" size="sm" ::href="downloadUrl()" icon="fas fa-download">Download NZB</x-button-link>
        </x-slot:footer>
    </x-modal>
</div>
