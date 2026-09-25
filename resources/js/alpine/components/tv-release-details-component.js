import { renderMediaInfo } from './media-info-block.js';
import { fileTable, loadAllFiles } from './tv-files.js';
import { nextSort, sortRows } from './tv-episode-list-component.js';
import { rowActions } from './tv-row-actions.js';

export const TABS = ['overview', 'files', 'media', 'nfo', 'comments'];

/** The tab a URL hash names, else Overview. */
export function tabFromHash(hash) {
    const tab = String(hash || '').replace(/^#/, '');
    return TABS.includes(tab) ? tab : 'overview';
}

function escapeHtml(value) {
    const entities = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(value).replace(/[&<>"']/g, character => entities[character]);
}

/**
 * The TV release details page (details/tv/index.blade.php): the five tabs, loading Files, Media
 * info and NFO when first opened; the episode table's sortable headers; the header's and the
 * table's Copy NZB link and cart buttons.
 */
export function tvReleaseDetails() {
    return {
        screen: null,
        activeTab: 'overview',
        loaded: new Set(),
        sort: { key: 'size', dir: -1 },

        init() {
            this.screen = this.$el;
            this._hashChanged = () => this.selectTab(tabFromHash(window.location.hash), false);
            window.addEventListener('hashchange', this._hashChanged);
            this.selectTab(tabFromHash(window.location.hash), false);
            this.screen.querySelector('[role=tablist]')?.addEventListener('keydown', event => this.tabKey(event));
        },

        destroy() {
            window.removeEventListener('hashchange', this._hashChanged);
        },

        handleClick(event) {
            const tab = event.target.closest('[data-tab]');
            if (tab) return this.selectTab(tab.dataset.tab, true);
            const sort = event.target.closest('[data-sort]');
            if (sort) return this.sortBy(sort);
            const copy = event.target.closest('[data-copy-nzb]');
            if (copy) return this.copyLink(copy);
            const cart = event.target.closest('[data-cart]');
            if (cart) return this.toggleCart(cart);
            return undefined;
        },

        /** Arrow keys move between tabs, as the WAI tabs pattern does. */
        tabKey(event) {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const index = TABS.indexOf(this.activeTab);
            const next = { ArrowLeft: index - 1, ArrowRight: index + 1, Home: 0, End: TABS.length - 1 }[event.key];
            this.selectTab(TABS[(next + TABS.length) % TABS.length], true);
            this.screen.querySelector('[data-tab="' + this.activeTab + '"]')?.focus();
        },

        selectTab(tab, remember) {
            this.activeTab = TABS.includes(tab) ? tab : 'overview';
            if (remember) {
                const url = new URL(window.location.href);
                url.hash = this.activeTab === 'overview' ? '' : this.activeTab;
                window.history.replaceState(null, '', url.toString());
            }
            this.screen.querySelectorAll('[data-tab]').forEach(button => {
                const current = button.dataset.tab === this.activeTab;
                button.setAttribute('aria-selected', current ? 'true' : 'false');
                button.setAttribute('tabindex', current ? '0' : '-1');
            });
            this.screen.querySelectorAll('[data-details-panel]').forEach(panel => { panel.hidden = panel.id !== this.activeTab; });
            return this.load(this.activeTab);
        },

        async load(tab) {
            if (!['files', 'media', 'nfo'].includes(tab) || this.loaded.has(tab)) return;
            const content = this.screen.querySelector('[data-tab-content="' + tab + '"]');
            if ((tab === 'media' && this.screen.dataset.hasMedia !== '1') || (tab === 'nfo' && this.screen.dataset.hasNfo !== '1')) return;
            this.loaded.add(tab);
            try {
                content.innerHTML = await this.fetchTab(tab);
            } catch {
                this.loaded.delete(tab);
                content.innerHTML = '<p class="tv-note" role="alert">Could not load this tab. Open it again to retry.</p>';
            }
        },

        async fetchTab(tab) {
            const guid = this.screen.dataset.guid;
            if (tab === 'files') return fileTable((await loadAllFiles(guid)).files);
            if (tab === 'media') {
                const response = await fetch('/release/' + encodeURIComponent(this.screen.dataset.releaseId) + '/mediainfo', { headers: { Accept: 'application/json' } });
                if (!response.ok || response.redirected) throw new Error('Request failed');
                const data = await response.json();
                return data.media ? renderMediaInfo(data.media, data.resolution ?? null) : '<p class="tv-note">No media information was captured for this release.</p>';
            }
            const response = await fetch('/nfo/' + encodeURIComponent(guid) + '?modal=1', { headers: { Accept: 'text/html' } });
            if (response.status === 404) return '<p class="tv-note">No NFO was posted with this release.</p>';
            if (!response.ok || response.redirected) throw new Error('Request failed');
            const text = new DOMParser().parseFromString(await response.text(), 'text/html').querySelector('pre')?.textContent;
            if (text === undefined) throw new Error('Invalid NFO');
            return '<pre class="tv-nfo">' + escapeHtml(text) + '</pre>';
        },

        sortBy(button) {
            this.sort = nextSort(this.sort, button.dataset.sort);
            this.screen.querySelectorAll('.tv-release-table').forEach(table => {
                const body = table.tBodies[0];
                body.append(...sortRows(Array.from(body.rows), this.sort));
                table.querySelectorAll('th').forEach(cell => {
                    const sorter = cell.querySelector('[data-sort]');
                    if (sorter && sorter.dataset.sort === this.sort.key) cell.setAttribute('aria-sort', this.sort.dir > 0 ? 'ascending' : 'descending');
                    else cell.removeAttribute('aria-sort');
                });
            });
        },

        ...rowActions(),

        /** Also turns the header's cart button into "In cart" and back. */
        markCart(guids, inCart) {
            this.screen.querySelectorAll('[data-cart]').forEach(button => {
                if (!guids.includes(button.dataset.cart)) return;
                button.setAttribute('aria-pressed', inCart ? 'true' : 'false');
                if (button.hasAttribute('data-cart-label')) {
                    button.querySelector('span').textContent = inCart ? 'In cart' : 'Add to cart';
                    const icon = button.querySelector('i');
                    icon.classList.toggle('fa-check', inCart);
                    icon.classList.toggle('fa-cart-shopping', !inCart);
                    return;
                }
                button.setAttribute('title', inCart ? 'In cart · click to remove' : 'Add to cart');
                button.setAttribute('aria-label', inCart ? 'Remove from cart' : 'Add to cart');
            });
        },

        selectedGuids() {
            return [];
        },

        clearSelection() {},
    };
}
