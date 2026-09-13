<div x-data="previewModal">
    <x-modal name="preview" width="preview" backdrop="stepBack()">
        <x-slot:title><span x-text="title">Preview image</span></x-slot:title>
        <x-slot:icon><i class="fas text-cyan-600 dark:text-cyan-400" :class="kindIcon()"></i></x-slot:icon>
        <x-slot:subtitle><span x-text="releaseName"></span></x-slot:subtitle>
        <div x-show="audioUrl" class="public-modal-audio">
            <div class="public-modal-audio-art surface-panel-alt">
                <img x-show="audioArtwork" :src="audioArtwork" :alt="audioTitle">
                <i x-show="!audioArtwork" class="fas fa-compact-disc text-gray-400 dark:text-gray-500"></i>
            </div>
            <div class="min-w-0 flex-1">
                <p class="font-semibold break-all" x-text="audioTitle"></p>
                <p class="text-xs text-gray-500 dark:text-gray-400" x-text="audioArtist"></p>
                <x-audio-preview-player dynamic class="mt-2" />
            </div>
        </div>
        <div x-show="imageUrl && !imageLoaded && !imageError && !videoPlaying && !audioUrl" class="py-8 text-center text-gray-600 dark:text-gray-400"><i class="fas fa-spinner fa-spin mr-2 text-cyan-600 dark:text-cyan-400"></i>Loading image...</div>
        <p x-show="imageUrl && imageError && !videoPlaying && !audioUrl" class="py-8 text-center text-gray-500 dark:text-gray-400" x-text="errorMessage()"></p>
        <div x-show="imageUrl && imageLoaded && !imageError && !videoPlaying && !audioUrl" class="public-modal-image-frame">
            <img :src="imageUrl" :alt="title" x-on:error="onImageError()" @load="onImageLoad()" decoding="async" fetchpriority="high">
        </div>
        <div x-show="videoPlaying" class="public-modal-image-frame">
            <video x-ref="videoPlayer" controls preload="none"><source>Your browser does not support playing this video preview.</video>
        </div>
        <div x-show="videoUrl && !videoPlaying" class="mt-4 flex justify-center">
            <x-button size="sm" icon="fas fa-play" @click="playVideo()">Play video preview</x-button>
        </div>
        <x-slot:footer>
            <x-button x-show="fullUrl && !videoUrl && !audioUrl" variant="secondary" size="sm" icon="fas fa-expand" @click="enterFullscreen()">Full size</x-button>
            <span class="flex-1"></span>
            <x-button-link variant="secondary" size="sm" ::href="detailsUrl()" icon="fas fa-circle-info">Details</x-button-link>
            <x-button-link variant="success" size="sm" ::href="downloadUrl()" icon="fas fa-download">Download NZB</x-button-link>
        </x-slot:footer>
        <x-slot:overlay><x-image-fullscreen-layer title-property="title" /></x-slot:overlay>
    </x-modal>
</div>
