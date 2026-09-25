import { fetchList, filterUrl, firstPageUrl, postJson } from './tv-list.js';

const COPIED = 'NZB link copied. It contains your API key, so only paste it into your own downloader.';

/**
 * Copies text with the Clipboard API, or with execCommand where the site is served over plain
 * http and the Clipboard API is unavailable.
 */
export async function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch {
            // fall through to the legacy path
        }
    }
    const buffer = document.createElement('textarea');
    buffer.value = text;
    buffer.setAttribute('readonly', '');
    buffer.className = 'tv-copy-buffer';
    document.body.appendChild(buffer);
    buffer.select();
    let copied = false;
    try {
        copied = document.execCommand('copy');
    } catch {
        copied = false;
    }
    buffer.remove();
    return copied;
}

/**
 * The TV releases screen (tv/releases/index.blade.php): row selection and the floating bar,
 * the same-show batch expander, cart and copy-link row actions, the sort preference, and
 * reloading the list in place when a filter menu changes.
 */
export function tvReleases() {
    return {
        selectedCount: 0,
        screen: null,
        request: null,

        init() {
            this.screen = this.$el;
            this.selectionChanged();
        },

        boxes(visibleOnly = false) {
            return Array.from(this.screen.querySelectorAll('[data-select]'))
                .filter(box => !visibleOnly || !box.closest('tr').hidden);
        },

        handleChange(event) {
            const target = event.target;
            if (target.matches('[data-select-all]')) {
                this.boxes(true).forEach(box => { box.checked = target.checked; });
                this.selectionChanged();
            } else if (target.matches('[data-select]')) {
                this.selectionChanged();
            }
        },

        handleClick(event) {
            const expander = event.target.closest('[data-expand]');
            if (expander) return this.toggleBatch(expander);
            const copy = event.target.closest('[data-copy-nzb]');
            if (copy) return this.copyLink(copy);
            const cart = event.target.closest('[data-cart]');
            if (cart) return this.toggleCart(cart);
            return undefined;
        },

        selectionChanged() {
            this.selectedCount = this.boxes().filter(box => box.checked).length;
            const header = this.screen.querySelector('[data-select-all]');
            if (!header) return;
            const visible = this.boxes(true), ticked = visible.filter(box => box.checked).length;
            header.checked = ticked > 0 && ticked === visible.length;
            header.indeterminate = ticked > 0 && ticked < visible.length;
            header.setAttribute('aria-label', header.checked ? 'Clear selection on this page' : 'Select all releases on this page');
        },

        selectedGuids() {
            return this.boxes().filter(box => box.checked).map(box => box.value);
        },

        clearSelection() {
            this.boxes().forEach(box => { box.checked = false; });
            this.selectionChanged();
        },

        toggleBatch(button) {
            const open = button.getAttribute('aria-expanded') !== 'true';
            this.screen.querySelectorAll('[data-batch]').forEach(row => {
                if (row.dataset.batch === button.dataset.expand) row.hidden = !open;
            });
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            button.querySelector('span').textContent = open ? button.dataset.labelOpen : button.dataset.labelClosed;
            this.selectionChanged();
        },

        nzbLink(guid) {
            const url = new URL(this.screen.dataset.nzbLinkBase);
            url.searchParams.set('t', 'get');
            url.searchParams.set('id', guid);
            url.searchParams.set('apikey', this.screen.dataset.apiToken);
            return url.toString();
        },

        async copyLink(button) {
            const link = this.nzbLink(button.dataset.copyNzb);
            if (!(await copyText(link))) {
                window.showToast('Could not copy. The link is: ' + link, 'error');
                return;
            }
            window.showToast(COPIED, 'success');
            const icon = button.querySelector('i');
            icon.classList.replace('fa-link', 'fa-check');
            clearTimeout(button.copiedTimer);
            button.copiedTimer = setTimeout(() => icon.classList.replace('fa-check', 'fa-link'), 1600);
        },

        markCart(guids, inCart) {
            this.screen.querySelectorAll('[data-cart]').forEach(button => {
                if (!guids.includes(button.dataset.cart)) return;
                button.setAttribute('aria-pressed', inCart ? 'true' : 'false');
                button.setAttribute('title', inCart ? 'In cart · click to remove' : 'Add to cart');
                button.setAttribute('aria-label', inCart ? 'Remove from cart' : 'Add to cart');
            });
        },

        async toggleCart(button) {
            if (button.disabled) return;
            const guid = button.dataset.cart, removing = button.getAttribute('aria-pressed') === 'true';
            button.disabled = true;
            try {
                const result = await postJson(removing ? '/cart/delete/' + encodeURIComponent(guid) : '/cart/add', { id: guid });
                if (!result.success) throw new Error('Cart update failed');
                this.markCart([guid], !removing);
                if (result.cartCount !== undefined) this.$store.cart.setCount(result.cartCount);
                window.showToast(removing ? 'Removed from cart.' : 'Added to cart.', 'success');
            } catch {
                window.showToast('Could not update your cart. Please try again.', 'error');
            } finally {
                button.disabled = false;
            }
        },

        async addSelectedToCart() {
            const guids = this.selectedGuids();
            if (!guids.length) return;
            try {
                const result = await postJson('/cart/add', { id: guids.join(',') });
                if (!result.success) throw new Error('Cart update failed');
                this.markCart(guids, true);
                if (result.cartCount !== undefined) this.$store.cart.setCount(result.cartCount);
                window.showToast(guids.length === 1 ? 'Added to cart.' : guids.length + ' releases added to cart.', 'success');
                this.clearSelection();
            } catch {
                window.showToast('Could not update your cart. Please try again.', 'error');
            }
        },

        downloadSelected() {
            const guids = this.selectedGuids();
            if (!guids.length) return;
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '/getnzb';
            form.hidden = true;
            const fields = { id: guids.join(','), zip: '1', _token: document.querySelector('meta[name="csrf-token"]')?.content ?? '' };
            Object.entries(fields).forEach(([name, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.append(input);
            });
            document.body.append(form);
            form.submit();
            form.remove();
            this.clearSelection();
        },

        async changeSort(event) {
            try {
                await postJson(this.screen.dataset.preferenceUrl, { root: 'tv', sort: event.target.value });
            } catch {
                window.showToast('Could not save your sort order. Please try again.', 'error');
                return;
            }
            window.location.assign(firstPageUrl(window.location.href).toString());
        },

        async applyFilter(event) {
            const { name, values } = event.detail;
            const url = filterUrl(window.location.href, name, values);
            window.history.replaceState(null, '', url.toString());
            await this.reloadList(url);
        },

        async reloadList(url) {
            this.request?.abort();
            const request = new AbortController();
            this.request = request;
            try {
                const html = await fetchList(url, request.signal);
                if (request.signal.aborted) return;
                this.$refs.list.innerHTML = html;
                this.selectionChanged();
            } catch (error) {
                if (error.name !== 'AbortError') window.showToast('Could not load the releases. Reload the page and try again.', 'error');
            }
        },
    };
}
