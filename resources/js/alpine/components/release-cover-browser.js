import { modalLifecycle } from './modal-lifecycle.js';

export function releaseCoverBrowser() {
    return {
        ...modalLifecycle(),
        open: false,
        coverTile: null,
        coverPanel: null,
        coverRequest: null,
        coverPageNumber: 1,
        coverPerPage: 24,

        initCovers() {
            this.coverPanel = this.browserRoot.querySelector?.('[data-cover-expansion]');
            if (!this.coverPanel) return;
            this.initModal(() => this.closeCover());
            this._coverKeydown = event => this.coverKeydown(event);
            this.browserRoot.ownerDocument.addEventListener('keydown', this._coverKeydown);
            this._coverResize = new ResizeObserver(() => this.positionCover());
            this._coverResize.observe(this.coverPanel.parentElement);
            this.browserRoot.querySelectorAll('.release-cover-art img').forEach(img => {
                if (img.complete && img.naturalWidth === 0) this.coverArtworkFailed({ currentTarget: img });
            });
        },

        destroy() {
            this.coverRequest?.abort();
            this._coverResize?.disconnect();
            this.browserRoot?.ownerDocument?.removeEventListener('keydown', this._coverKeydown);
            this._modalTeardown?.();
        },

        async openCover(event) {
            const tile = event.currentTarget.closest('[data-cover-tile]');
            if (this.coverTile === tile) { this.closeCover(); return; }
            this.closeCover(false);
            this.coverTile = tile;
            this.coverPageNumber = 1; this.coverPerPage = 24;
            tile.dataset.open = '1';
            tile.querySelector('[data-cover-open]').setAttribute('aria-expanded', 'true');
            this.coverPanel.hidden = false;
            this.positionCover();
            await this.fetchCover();
        },

        async fetchCover() {
            this.coverRequest?.abort();
            this.clearSelection();
            const request = new AbortController();
            this.coverRequest = request;
            const url = new URL(this.browserRoot.dataset.coverUrl || window.location.href);
            url.searchParams.set('view', 'covers');
            url.searchParams.set('_fragment', 'cover');
            url.searchParams.set('cover', this.coverTile.dataset.coverTile);
            url.searchParams.set('release_page', this.coverPageNumber);
            url.searchParams.set('release_per', this.coverPerPage);
            this.setCoverContent('<div class="p-4 text-sm" role="status">Loading releases…</div>');
            this.coverPanel.setAttribute('aria-busy', 'true');
            this.coverPanel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            try {
                const response = await fetch(url.toString(), {
                    signal: request.signal, headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok || response.redirected) throw new Error('Could not load releases');
                const html = await response.text();
                if (request.signal.aborted || this.coverRequest !== request) return;
                if (!html.includes('data-release-table')) throw new Error('Unexpected response');
                this.setCoverContent(html);
                this.selectionChanged();
                this.$nextTick(() => {
                    if (this.coverRequest !== request) return;
                    this.coverPanel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                    if (this.open) this.coverPanel.querySelector('.release-cover-close')?.focus();
                });
            } catch (error) {
                if (error.name === 'AbortError' || this.coverRequest !== request) return;
                this.setCoverContent(this.browserRoot.querySelector('[data-cover-error]').innerHTML);
            } finally {
                if (this.coverRequest === request) this.coverPanel.removeAttribute('aria-busy');
            }
        },

        changeCoverPage(event) {
            this.coverPageNumber = Number(event.currentTarget.dataset.coverPage);
            return this.fetchCover();
        },

        changeCoverPer(event) {
            this.coverPerPage = Number(event.target.value);
            this.coverPageNumber = 1;
            return this.fetchCover();
        },

        setCoverContent(html) {
            const Alpine = window.Alpine;
            Alpine.mutateDom(() => {
                [...this.coverPanel.children].forEach(child => Alpine.destroyTree(child));
                this.coverPanel.innerHTML = html;
                [...this.coverPanel.children].forEach(child => Alpine.initTree(child));
            });
        },

        positionCover() {
            if (!this.coverTile) return;
            const tiles = [...this.browserRoot.querySelectorAll('[data-cover-tile]')];
            const top = this.coverTile.offsetTop;
            const rowEnd = tiles.filter(tile => Math.abs(tile.offsetTop - top) < 2).at(-1);
            window.Alpine.mutateDom(() => rowEnd.after(this.coverPanel));
            const tileBounds = this.coverTile.getBoundingClientRect();
            const panelBounds = this.coverPanel.getBoundingClientRect();
            this.coverPanel.style.setProperty('--cover-pointer', (tileBounds.left + tileBounds.width / 2 - panelBounds.left) + 'px');
            this.open = window.matchMedia('(max-width: 640px)').matches;
            this.coverPanel.setAttribute('role', this.open ? 'dialog' : 'region');
            if (this.open) this.coverPanel.setAttribute('aria-modal', 'true');
            else this.coverPanel.removeAttribute('aria-modal');
        },

        closeCover(restoreFocus = true) {
            this.coverRequest?.abort();
            this.coverRequest = null;
            this.open = false;
            if (!this.coverTile) return;
            const button = this.coverTile.querySelector('[data-cover-open]');
            button.setAttribute('aria-expanded', 'false');
            delete this.coverTile.dataset.open;
            this.coverTile = null;
            this.coverPanel.hidden = true;
            this.setCoverContent('');
            this.selectionChanged();
            if (restoreFocus && button.isConnected) button.focus();
        },

        coverKeydown(event) {
            if (!this.coverTile || event.defaultPrevented) return;
            const anotherDialog = [...this.browserRoot.ownerDocument.querySelectorAll('[aria-modal="true"]')]
                .some(dialog => dialog !== this.coverPanel && dialog.getClientRects().length);
            if (anotherDialog) return;
            if (event.key === 'Escape') {
                event.preventDefault();
                this.closeCover();
                return;
            }
            if (event.target.closest('input, select, textarea, [contenteditable="true"]')) return;
            if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
            const tiles = [...this.browserRoot.querySelectorAll('[data-cover-tile]')];
            const neighbor = tiles[tiles.indexOf(this.coverTile) + (event.key === 'ArrowRight' ? 1 : -1)];
            if (!neighbor) return;
            event.preventDefault();
            this.openCover({ currentTarget: neighbor.querySelector('[data-cover-open]') });
        },

        coverArtworkFailed(event) {
            const art = event.currentTarget.closest('.release-cover-art');
            art.dataset.hasArt = '0';
            art.querySelector('i').setAttribute('data-no-artwork', '');
        },
    };
}
