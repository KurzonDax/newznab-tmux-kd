/**
 * The TV screens' own dialogs (tv/partials/dialogs.blade.php): the file list, the preview /
 * sample image and Listen. The media info and NFO dialogs reuse the site's mediainfoModal and nfoModal
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
 * image toggles too. A Clip chip (data-video-url) opens it with a video player instead, which,
 * as today's preview modal, fetches nothing until play is pressed; the player is built here and
 * removed on close. An Adult row's picture (data-picture: preview or sample) opens the dialog of
 * its row's matching chip, which takes focus so closing returns there; a Ctrl-, Cmd- or
 * Shift-click follows the picture's link to the details page as a browser does. A trigger with
 * data-open-full (the Adult details page's Sample picture) opens straight in the Full size state
 * when the image is larger than the dialog; its image stays hidden until it is measured and laid
 * out so, and a failed load shows the failed note at once.
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
        video: false,
        openFull: false,
        measuring: false,

        show(trigger) {
            const sample = trigger.classList.contains('sample-badge');
            this.guid = trigger.dataset.guid || '';
            this.title = trigger.dataset.imageTitle || (sample ? 'Sample image' : 'Preview image');
            this.releaseName = trigger.dataset.releaseDisplayName || '';
            this.dimensions = '';
            this.canFull = false;
            this.full = false;
            this.failed = false;
            this.removePlayer();
            this.video = Boolean(trigger.dataset.videoUrl);
            this.openFull = !this.video && trigger.dataset.openFull !== undefined;
            this.measuring = this.openFull;
            this.imageUrl = this.video ? '' : trigger.dataset.fullUrl || trigger.dataset.imageUrl || '';
            this.open = true;
            this.$nextTick(() => {
                if (this.video) return this.addPlayer(trigger.dataset.videoUrl, trigger.dataset.videoType || '');
                const image = this.$refs.image;
                if (image?.complete && image.naturalWidth) this.measure();
                return undefined;
            });
        },

        addPlayer(url, type) {
            const player = document.createElement('video');
            player.controls = true;
            player.preload = 'none';
            player.tabIndex = 0;
            const source = document.createElement('source');
            source.src = url;
            if (type) source.type = type;
            player.append(source);
            this.$refs.player?.replaceChildren(player);
        },

        removePlayer() {
            const player = this.$refs.player?.querySelector('video');
            if (!player) return;
            player.pause();
            player.replaceChildren();
            player.load();
            this.$refs.player.replaceChildren();
        },

        showImage() {
            return !this.failed && !this.video;
        },

        /** Runs when the image has loaded: its natural size, and whether it is larger than shown. */
        measure() {
            const image = this.$refs.image;
            if (!image || !image.naturalWidth) {
                this.measuring = false;
                return;
            }
            this.dimensions = image.naturalWidth + ' × ' + image.naturalHeight;
            window.requestAnimationFrame(() => {
                this.canFull = image.naturalWidth > image.clientWidth + 1 || image.naturalHeight > image.clientHeight + 1;
                if (this.openFull && this.canFull) this.full = true;
                this.measuring = false;
            });
        },

        imageFailed() {
            this.failed = true;
            this.measuring = false;
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
            return 'tv-dialog tv-image-dialog' + (this.canFull ? ' can-full' : '') + (this.full ? ' is-full' : '') + (this.measuring ? ' is-measuring' : '');
        },

        detailsUrl() { return '/details/' + encodeURIComponent(this.guid); },
        downloadUrl() { return '/getnzb/' + encodeURIComponent(this.guid); },

        close() {
            this.open = false;
            this.full = false;
            this.openFull = false;
            this.measuring = false;
            this.imageUrl = '';
            this.removePlayer();
            this.video = false;
        },

        init() {
            this.initModal();
            this._click = event => {
                const picture = event.target.closest('[data-picture]');
                if (picture) return this.showPicture(event, picture);
                const trigger = event.target.closest('.preview-badge, .sample-badge, .clip-badge');
                if (!trigger) return undefined;
                event.preventDefault();
                return this.show(trigger);
            };
            document.addEventListener('click', this._click);
        },

        showPicture(event, picture) {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.button > 0) return;
            const chip = picture.closest('[data-release-row]')?.querySelector(picture.dataset.picture === 'sample' ? '.sample-badge' : '.preview-badge');
            if (!chip) return;
            event.preventDefault();
            chip.focus();
            this.show(chip);
        },

        destroy() {
            this._modalTeardown?.();
            this.removePlayer();
            document.removeEventListener('click', this._click);
        },
    };
}

/**
 * The Listen dialog (docs/proposals/audio-redesign/SPEC.md 5.10), opened by an Audio row's Listen
 * chip (`.listen-badge`): the track title with the artist under it, then the browser's own audio
 * player, built here as the image dialog builds its video player and played at once. Closing
 * pauses, empties and removes the player, so the sound stops.
 */
export function tvListenDialog() {
    return {
        ...modalLifecycle(),
        open: false,
        releaseName: '',
        trackTitle: '',
        artist: '',

        show(trigger) {
            const data = trigger.dataset;
            this.removePlayer();
            this.releaseName = data.releaseDisplayName || '';
            this.trackTitle = data.audioTitle || '';
            this.artist = data.audioArtist || '';
            this.open = true;
            this.$nextTick(() => this.addPlayer(data.audioUrl || '', data.audioType || '', data.audioSeconds || ''));
        },

        addPlayer(url, type, seconds) {
            const player = document.createElement('audio');
            player.controls = true;
            player.preload = 'auto';
            player.tabIndex = 0;
            player.setAttribute('aria-label', (seconds ? seconds + '-second preview of ' : 'Preview of ') + this.releaseName);
            const source = document.createElement('source');
            source.src = url;
            if (type) source.type = type;
            player.append(source);
            this.$refs.player?.replaceChildren(player);
            // A browser may refuse to start the sound; the player then waits for its own play button.
            player.play?.()?.catch?.(() => {});
        },

        removePlayer() {
            const player = this.$refs.player?.querySelector('audio');
            if (!player) return;
            player.pause();
            player.replaceChildren();
            player.load();
            this.$refs.player.replaceChildren();
        },

        /** The track title, else nothing: the artist shows only under a title. */
        showTrack() {
            return this.trackTitle !== '';
        },

        showArtist() {
            return this.trackTitle !== '' && this.artist !== '';
        },

        close() {
            this.open = false;
            this.removePlayer();
        },

        init() {
            this.initModal();
            this._click = event => {
                const trigger = event.target.closest('.listen-badge');
                if (!trigger) return undefined;
                event.preventDefault();
                return this.show(trigger);
            };
            document.addEventListener('click', this._click);
        },

        destroy() {
            this._modalTeardown?.();
            this.removePlayer();
            document.removeEventListener('click', this._click);
        },
    };
}
