<div x-data="imageModal">
    <x-modal name="image" width="xl" backdrop="stepBack()">
        <x-slot:title><span x-text="imageTitle">Preview image</span></x-slot:title>
        <x-slot:icon><i class="fas fa-image text-cyan-600 dark:text-cyan-400"></i></x-slot:icon>
        <x-slot:subtitle><span x-text="releaseName"></span></x-slot:subtitle>
        <div class="public-modal-image-frame"><img :src="imageUrl" :alt="imageTitle" decoding="async"></div>
        <x-slot:footer>
            <x-button x-show="fullUrl" variant="secondary" size="sm" icon="fas fa-expand" @click="enterFullscreen()">Full size</x-button>
            <span class="flex-1"></span>
            <x-button-link x-show="guid" variant="secondary" size="sm" ::href="detailsUrl()" icon="fas fa-circle-info">Details</x-button-link>
            <x-button-link x-show="guid" variant="success" size="sm" ::href="downloadUrl()" icon="fas fa-download">Download NZB</x-button-link>
        </x-slot:footer>
        <x-slot:overlay><x-image-fullscreen-layer title-property="imageTitle" /></x-slot:overlay>
    </x-modal>
</div>
