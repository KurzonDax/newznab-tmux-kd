import { fileSummaryPages } from './file-summary-pages.js';
import { renderMediaInfo } from './mediainfo-modal-component.js';

const tabs = ['overview', 'files', 'media', 'nfo', 'comments'];

export function releaseDetails() {
    return {
        ...fileSummaryPages(),
        activeTab: 'overview',
        loaded: new Set(),
        pending: new Map(),
        destroyed: false,
        detailsRoot: null,
        init() {
            this.detailsRoot = this.$el;
            this._hashChanged = () => this.selectTab(window.location.hash.slice(1));
            window.addEventListener('hashchange', this._hashChanged);
            this._hashChanged();
            this.detailsRoot.querySelectorAll('[data-details-artwork] img').forEach(image => {
                if (image.complete && image.naturalWidth === 0) this.artworkFailed({ target: image });
            });
        },
        navigateTab(event) {
            const link = event.target.closest('[data-tab]');
            if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            const tab = link.dataset.tab;
            const url = new URL(window.location.href);
            url.hash = tab;
            window.history.replaceState(null, '', url);
            this.selectTab(tab);
        },
        async selectTab(tab) {
            this.activeTab = tabs.includes(tab) ? tab : 'overview';
            this.detailsRoot.querySelectorAll('[data-details-panel]').forEach(panel => { panel.hidden = panel.id !== this.activeTab; });
            this.detailsRoot.querySelectorAll('[data-tab]').forEach(link => {
                if (link.dataset.tab === this.activeTab) link.setAttribute('aria-current', 'page');
                else link.removeAttribute('aria-current');
            });
            if (['files', 'media', 'nfo'].includes(this.activeTab)) await this.loadTab(this.activeTab);
        },
        async toggleBasket(event) {
            const button = event.currentTarget;
            if (button.disabled) return;
            const removing = button.dataset.inBasket === '1';
            button.disabled = true;
            try {
                const response = await fetch(removing ? '/cart/delete/' + encodeURIComponent(button.dataset.guid) : '/cart/add', {
                    method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                    body: JSON.stringify({ id: button.dataset.guid }),
                });
                if (!response.ok || response.redirected) throw new Error('Basket update failed');
                const result = await response.json();
                if (!result.success) throw new Error('Basket update failed');
                button.dataset.inBasket = removing ? '0' : '1';
                button.querySelector('[data-basket-label]').textContent = removing ? 'Add to basket' : 'Remove from basket';
                this.$store.cart.setCount(result.cartCount);
                window.showToast(removing ? 'Removed from basket.' : 'Added to basket.', 'success');
            } catch {
                window.showToast('Could not update your basket. Please try again.', 'error');
            } finally {
                button.disabled = false;
            }
        },
        artworkFailed(event) {
            const image = event.target;
            const artwork = image.closest('[data-details-artwork]');
            if (!artwork) return;
            const icon = document.createElement('i');
            icon.className = artwork.dataset.artworkIcon;
            icon.setAttribute('aria-hidden', 'true');
            const label = document.createElement('span');
            label.className = 'sr-only';
            label.textContent = 'No artwork';
            image.replaceWith(icon, label);
        },
        async loadTab(tab) {
            if (this.loaded.has(tab) || this.destroyed) return;
            if (tab === 'files') return this.loadFilePage(this.filesPage);
            if (this.pending.has(tab)) return this.pending.get(tab);
            const content = this.detailsRoot.querySelector('[data-tab-content="' + tab + '"]');
            const task = this.fetchTab(tab, content);
            this.pending.set(tab, task);
            await task;
            this.pending.delete(tab);
        },
        async loadFilePage(page = this.filesPage) {
            const retry = this.detailsRoot.querySelector('[data-retry-tab="files"]');
            if (retry) retry.hidden = true;
            this.loaded.delete('files');
            const loaded = await this.fetchFiles(this.detailsRoot.dataset.guid, this.detailsRoot.querySelector('[data-tab-content="files"]'), page);
            if (loaded) this.loaded.add('files');
            if (retry && !this.destroyed && !this.filesLoading && !this.loaded.has('files')) retry.hidden = false;
        },
        async fetchTab(tab, content) {
            const retry = this.detailsRoot.querySelector('[data-retry-tab="' + tab + '"]');
            if (retry) retry.hidden = true;
            content.innerHTML = '<p class="text-muted" role="status">Loading…</p>';
            try {
                const guid = encodeURIComponent(this.detailsRoot.dataset.guid);
                const urls = { media: '/release/' + encodeURIComponent(this.detailsRoot.dataset.releaseId) + '/mediainfo', nfo: '/nfo/' + guid + '?modal=1' };
                const response = await fetch(urls[tab], { headers: { Accept: tab === 'nfo' ? 'text/html' : 'application/json' } });
                if (response.redirected || (!response.ok && response.status !== 404)) throw new Error('Request failed');
                let html;
                if (tab === 'media') {
                    const data = response.status === 404 ? { media: null } : await response.json();
                    if (!Object.hasOwn(data, 'media')) throw new Error('Invalid media info');
                    html = data.media ? renderMediaInfo(data.media) : '<p class="text-muted">No media info for this release.</p>';
                } else if (tab === 'nfo') {
                    const parsed = response.status === 404 ? null : new DOMParser().parseFromString(await response.text(), 'text/html').querySelector('pre');
                    if (response.status !== 404 && !parsed) throw new Error('Invalid NFO');
                    html = '<pre class="nfo-pane">' + escapeHtml(parsed?.textContent || 'No NFO for this release.') + '</pre>';
                }
                if (this.destroyed) return;
                content.innerHTML = html;
                this.loaded.add(tab);
            } catch {
                if (!this.destroyed) {
                    content.innerHTML = '<p class="text-red-600 dark:text-red-400" role="alert">Could not load this tab.</p>';
                    if (retry) retry.hidden = false;
                }
            }
        },
        retryTab(event) {
            const button = event.target.closest('[data-retry-tab]');
            if (button) this.loadTab(button.dataset.retryTab);
        },
        destroy() {
            this.destroyed = true;
            this.cancelFiles();
            window.removeEventListener('hashchange', this._hashChanged);
        },
    };
}

function escapeHtml(value) {
    const entities = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(value).replace(/[&<>"']/g, character => entities[character]);
}

