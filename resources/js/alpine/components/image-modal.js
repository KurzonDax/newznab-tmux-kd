/**
 * Alpine.data('imageModal') - Image preview modal (details page)
 *
 * Two stages: the modal itself (the display thumb), and the Fullscreen view of
 * the release's Full-size copy. The fullscreen control only appears when a
 * trigger supplied a full URL, so the back catalog -- which has no Full-size
 * copy on disk -- never offers more pixels than it can deliver.
 */
import Alpine from '@alpinejs/csp';

Alpine.data('imageModal', () => ({
    open: false,
    fullscreen: false,
    imageUrl: '',
    fullUrl: '',
    imageTitle: 'Image Preview',

    openModal(url, title, fullUrl) {
        this.imageUrl = url || '';
        this.fullUrl = fullUrl || '';
        this.imageTitle = title || 'Image Preview';
        this.fullscreen = false;
        this.open = true;
    },

    enterFullscreen() {
        if (this.fullUrl) { this.fullscreen = true; }
    },

    exitFullscreen() {
        this.fullscreen = false;
    },

    close() {
        this.fullscreen = false;
        this.open = false;
    },

    /** Escape and backdrop clicks step back one layer at a time. */
    stepBack() {
        if (this.fullscreen) { this.exitFullscreen(); return; }
        this.close();
    },

    init() {
        const self = this;
        window.openImageModal = function(url, title, fullUrl) { self.openModal(url, title, fullUrl); };
        window.closeImageModal = function() { self.close(); };

        // Document-level click delegation for image modal triggers
        document.addEventListener('click', function(e) {
            const trigger = e.target.closest('.image-modal-trigger');
            if (trigger) { e.preventDefault(); self.openModal(trigger.dataset.imageUrl, trigger.dataset.imageTitle, trigger.dataset.fullUrl); return; }
            if (e.target.closest('[data-close-image-modal]')) { e.preventDefault(); self.close(); }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && self.open) self.stepBack();
        });
    }
}));
