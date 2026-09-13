import { modalLifecycle } from "./modal-lifecycle.js";
/**
 * Alpine.data('nfoModal') - NFO file viewer modal
 */
import Alpine from '@alpinejs/csp';

Alpine.data('nfoModal', () => ({
    ...modalLifecycle(),
    open: false,
    content: '',
    loading: false,
    error: false,
    guid: '',
    releaseName: '',
    requestVersion: 0,

    openNfo(guid, releaseName = '') {
        if (!guid) return;
        const version = ++this.requestVersion;
        this.guid = guid;
        this.releaseName = releaseName;
        this.open = true;
        this.loading = true;
        this.error = false;
        this.content = '';

        const baseUrl = document.querySelector('meta[name="app-url"]')?.content || '';
        fetch(baseUrl + '/nfo/' + guid + '?modal=1')
            .then(response => {
                if (!response.ok) throw new Error('NFO not found');
                return response.text();
            })
            .then(html => {
                if (version !== this.requestVersion) return;
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                this.content = doc.querySelector('pre')?.textContent || doc.body.textContent;
                this.releaseName = doc.querySelector('pre')?.dataset.releaseName || this.releaseName;
                this.loading = false;
            })
            .catch(() => {
                if (version !== this.requestVersion) return;
                this.error = true;
                this.loading = false;
            });
    },

    close() {
        this.requestVersion++;
        this.open = false;
        this.content = '';
    },

    detailsUrl() { return '/details/' + encodeURIComponent(this.guid) + '#nfo'; },
    downloadUrl() { return '/getnzb/' + encodeURIComponent(this.guid); },

    async copyText() {
        try {
            await navigator.clipboard.writeText(this.content);
            window.showToast('NFO text copied', 'success');
        } catch {
            window.showToast('Could not copy NFO text', 'error');
        }
    },

    downloadNfo() {
        const url = URL.createObjectURL(new Blob([this.content], { type: 'text/plain;charset=utf-8' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = this.guid + '.nfo';
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    },

    init() {
        this.initModal();
        // Backward compat global functions
        const self = this;
        window.openNfoModal = function(guid, name) { self.openNfo(guid, name); };
        window.closeNfoModal = function() { self.close(); };

        this._nfoHashChanged = () => {
            const trigger = document.querySelector('#nfo.nfo-badge');
            if (window.location.hash !== '#nfo' || !trigger) return;
            trigger.focus();
            self.openNfo(trigger.dataset.guid, trigger.dataset.releaseDisplayName);
        };
        window.addEventListener('hashchange', this._nfoHashChanged);
        this.$nextTick(this._nfoHashChanged);

        // Document-level click delegation for NFO triggers
        document.addEventListener('click', function(e) {
            const nfoAttr = e.target.closest('[data-open-nfo]');
            if (nfoAttr) { e.preventDefault(); self.openNfo(nfoAttr.getAttribute('data-open-nfo'), nfoAttr.dataset.releaseDisplayName); return; }
            const nfoBadge = e.target.closest('.nfo-badge');
            if (nfoBadge) { e.preventDefault(); self.openNfo(nfoBadge.dataset.guid, nfoBadge.dataset.releaseDisplayName); return; }
            if (e.target.closest('[data-close-nfo-modal]')) { e.preventDefault(); self.close(); }
        });
    },

    destroy() {
        window.removeEventListener('hashchange', this._nfoHashChanged);
        this._modalTeardown?.();
    }
}));
