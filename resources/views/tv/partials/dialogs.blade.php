{{-- The TV screens' dialogs (docs/proposals/tv-redesign/SPEC.md 3.5): media info, NFO, file list and the preview / sample image. --}}
<div x-data="mediainfoModal">
    <x-tv-dialog name="mediainfo">
        <x-slot:title>Media info</x-slot:title>
        <x-slot:subtitle><span x-text="releaseName"></span></x-slot:subtitle>
        <p x-show="loading" class="tv-note" role="status">Loading media info…</p>
        <div x-show="!loading" x-ref="content"></div>
    </x-tv-dialog>
</div>
<div x-data="nfoModal">
    <x-tv-dialog name="nfo">
        <x-slot:title>NFO</x-slot:title>
        <x-slot:subtitle><span x-text="releaseName"></span></x-slot:subtitle>
        <p x-show="loading" class="tv-note" role="status">Loading the NFO…</p>
        <p x-show="error && !loading" class="tv-note" role="alert">Could not load the NFO. Please try again.</p>
        <pre x-show="!loading && !error" class="tv-nfo" x-text="content"></pre>
        <x-slot:footer>
            <button type="button" class="tv-details-button is-secondary" x-on:click="copyText()" x-bind:disabled="loading || error"><i class="fas fa-copy" aria-hidden="true"></i>Copy text</button>
            <button type="button" class="tv-details-button is-secondary" x-on:click="downloadNfo()" x-bind:disabled="loading || error"><i class="fas fa-file-arrow-down" aria-hidden="true"></i>Download .nfo</button>
            <span class="tv-grow"></span>
            <a class="tv-details-button is-secondary" x-bind:href="detailsUrl()"><i class="fas fa-circle-info" aria-hidden="true"></i>Details</a>
            <a class="tv-details-button download-nzb" x-bind:href="downloadUrl()"><i class="fas fa-download" aria-hidden="true"></i>Download NZB</a>
        </x-slot:footer>
    </x-tv-dialog>
</div>
<div x-data="tvFilesDialog">
    <x-tv-dialog name="files">
        <x-slot:title>Files</x-slot:title>
        <x-slot:subtitle><span x-text="releaseName"></span></x-slot:subtitle>
        <div x-ref="content"></div>
    </x-tv-dialog>
</div>
<div x-data="tvImageDialog">
    <x-tv-dialog name="image" x-bind:class="dialogClass()">
        <x-slot:title><span x-text="title">Preview image</span></x-slot:title>
        <x-slot:subtitle><span x-text="releaseName"></span></x-slot:subtitle>
        <p x-show="failed" class="tv-note" role="alert">The image for this release was not found on the server.</p>
        <div x-show="!failed">
            <div class="tv-image-bar">
                <span x-text="dimensions"></span>
                <button type="button" class="tv-details-button is-secondary is-small" x-show="canFull" x-on:click="toggleFull()" x-bind:aria-pressed="fullPressed()" x-text="fullLabel()">Full size</button>
            </div>
            <button type="button" class="tv-image-frame" tabindex="-1" aria-hidden="true" x-on:click="toggleFull()"><img x-ref="image" x-bind:src="imageUrl" x-bind:alt="title" x-on:load="measure()" x-on:error="imageFailed()"></button>
        </div>
        <x-slot:footer>
            <span class="tv-grow"></span>
            <a class="tv-details-button is-secondary" x-bind:href="detailsUrl()"><i class="fas fa-circle-info" aria-hidden="true"></i>Details</a>
            <a class="tv-details-button download-nzb" x-bind:href="downloadUrl()"><i class="fas fa-download" aria-hidden="true"></i>Download NZB</a>
        </x-slot:footer>
    </x-tv-dialog>
</div>
