import { fetchList, filterUrl, firstPageUrl, postJson } from './tv-list.js';
import { rowActions } from './tv-row-actions.js';

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

        ...rowActions(),

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
