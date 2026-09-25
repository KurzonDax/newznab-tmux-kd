/**
 * The TV screens' own dialogs (tv/partials/dialogs.blade.php): the file list and the preview /
 * sample image. The media info and NFO dialogs reuse the site's mediainfoModal and nfoModal
 * components in the TV dialog frame. Every dialog closes with X, Escape and a click outside and
 * returns focus to what opened it (modalLifecycle).
 */
import { modalLifecycle } from './modal-lifecycle.js';
import { fileTable, loadAllFiles } from './tv-files.js';

/** The file list dialog, opened by any `.filelist-badge` (the file count in a release row). */
export function tvFilesDialog() {
    return {
        ...modalLifecycle(),
        open: false,
        releaseName: '',
        request: null,

        show(guid, name) {
            this.request?.abort();
            const request = new AbortController();
            this.request = request;
            this.releaseName = name || '';
            this.open = true;
            this.$refs.content.innerHTML = '<p class="tv-note" role="status">Loading the file list…</p>';
            return loadAllFiles(guid, request.signal)
                .then(({ name: loaded, files }) => {
                    if (request.signal.aborted) return;
                    this.releaseName = this.releaseName || loaded;
                    this.$refs.content.innerHTML = fileTable(files);
                })
                .catch(() => {
                    if (request.signal.aborted) return;
                    this.$refs.content.innerHTML = '<p class="tv-note" role="alert">Could not load the file list. Please try again.</p>';
                });
        },

        close() {
            this.request?.abort();
            this.request = null;
            this.open = false;
            this.$refs.content.innerHTML = '';
        },

        init() {
            this.initModal();
            this._click = event => {
                const badge = event.target.closest('.filelist-badge');
                if (!badge) return;
                event.preventDefault();
                const row = badge.closest('[data-release-row]');
                this.show(badge.dataset.guid, badge.dataset.releaseDisplayName || row?.querySelector('.tv-release-name')?.textContent.trim() || '');
            };
            document.addEventListener('click', this._click);
        },

        destroy() {
            this._modalTeardown?.();
            document.removeEventListener('click', this._click);
        },
    };
}

/**
 * The preview / sample image dialog: the full-size copy when one is on disk, else the thumb; its
 * pixel size; and a Full size button, offered only when the image is larger than shown, that
 * grows the dialog to the window at real pixels and turns into "Fit to window". Clicking the
 * image toggles too.
 */
export function tvImageDialog() {
    return {
        ...modalLifecycle(),
        open: false,
        title: 'Preview image',
        releaseName: '',
        guid: '',
        imageUrl: '',
        dimensions: '',
        canFull: false,
        full: false,
        failed: false,

        show(trigger) {
            const sample = trigger.classList.contains('sample-badge');
            this.guid = trigger.dataset.guid || '';
            this.title = trigger.dataset.imageTitle || (sample ? 'Sample image' : 'Preview image');
            this.releaseName = trigger.dataset.releaseDisplayName || '';
            this.dimensions = '';
            this.canFull = false;
            this.full = false;
            this.failed = false;
            this.imageUrl = trigger.dataset.fullUrl || trigger.dataset.imageUrl || '';
            this.open = true;
            this.$nextTick(() => {
                const image = this.$refs.image;
                if (image?.complete && image.naturalWidth) this.measure();
            });
        },

        /** Runs when the image has loaded: its natural size, and whether it is larger than shown. */
        measure() {
            const image = this.$refs.image;
            if (!image || !image.naturalWidth) return;
            this.dimensions = image.naturalWidth + ' × ' + image.naturalHeight;
            window.requestAnimationFrame(() => {
                this.canFull = image.naturalWidth > image.clientWidth + 1 || image.naturalHeight > image.clientHeight + 1;
            });
        },

        imageFailed() {
            this.failed = true;
        },

        toggleFull() {
            if (!this.canFull) return;
            this.full = !this.full;
        },

        fullLabel() {
            return this.full ? 'Fit to window' : 'Full size';
        },

        fullPressed() {
            return this.full ? 'true' : 'false';
        },

        dialogClass() {
            return 'tv-dialog tv-image-dialog' + (this.canFull ? ' can-full' : '') + (this.full ? ' is-full' : '');
        },

        detailsUrl() { return '/details/' + encodeURIComponent(this.guid); },
        downloadUrl() { return '/getnzb/' + encodeURIComponent(this.guid); },

        close() {
            this.open = false;
            this.full = false;
            this.imageUrl = '';
        },

        init() {
            this.initModal();
            this._click = event => {
                const trigger = event.target.closest('.preview-badge, .sample-badge');
                if (!trigger) return;
                event.preventDefault();
                this.show(trigger);
            };
            document.addEventListener('click', this._click);
        },

        destroy() {
            this._modalTeardown?.();
            document.removeEventListener('click', this._click);
        },
    };
}
