import { fetchList, filterUrl, firstPageUrl, paramsUrl, postJson } from './tv-list.js';
import { rowActions } from './tv-row-actions.js';

/**
 * A section's releases screen (tv/releases/index.blade.php, movies/releases/index.blade.php):
 * row selection and the floating bar, the same-title batch expander, cart and copy-link row
 * actions, the sort preference (for the root in data-preference-root, TV by default), and
 * reloading the list in place when a filter menu changes. The server remembers the filters each
 * reload carries (issue #881); a reload sends when it was made, counted from the page's
 * data-filters-clock, so a slower earlier reload never replaces a later change. The Adult list's
 * name search (docs/proposals/adult-redesign/SPEC.md 5.9) reloads the list the same way after a
 * 180 ms pause, with the text in the URL (q) and the page dropped; focus and caret stay in the
 * field, which sits above the list that is replaced.
 */
export function tvReleases() {
    return {
        selectedCount: 0,
        screen: null,
        request: null,
        loadedAt: 0,
        lastStamp: 0,
        nameTimer: null,

        init() {
            this.screen = this.$el;
            this.loadedAt = Date.now();
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

        destroy() {
            clearTimeout(this.nameTimer);
            this.request?.abort();
        },

        async changeSort(event) {
            try {
                await postJson(this.screen.dataset.preferenceUrl, { root: this.screen.dataset.preferenceRoot ?? 'tv', sort: event.target.value });
            } catch {
                window.showToast('Could not save your sort order. Please try again.', 'error');
                return;
            }
            window.location.assign(firstPageUrl(window.location.href).toString());
        },

        /** A menu's change: {name, values, single}, or {params} setting several URL keys at once (the Year menu). */
        async applyFilter(event) {
            const { name, values, single, params } = event.detail;
            const url = params ? paramsUrl(window.location.href, params) : filterUrl(window.location.href, name, values, single);
            window.history.replaceState(null, '', url.toString());
            await this.reloadList(url);
        },

        async reloadList(url) {
            this.request?.abort();
            const request = new AbortController();
            this.request = request;
            try {
                const html = await fetchList(this.stamped(url), request.signal);
                if (request.signal.aborted) return;
                this.$refs.list.innerHTML = html;
                this.selectionChanged();
            } catch (error) {
                if (error.name !== 'AbortError') window.showToast('Could not load the releases. Reload the page and try again.', 'error');
            }
        },

        /** The name search's input: shows its clear button and reloads the list after today's 180 ms pause. */
        searchNames(event) {
            const value = event.target.value;
            this.showNameClear(value !== '');
            clearTimeout(this.nameTimer);
            this.nameTimer = setTimeout(() => this.applyNameSearch(value), 180);
        },

        /** The clear button and Escape: empties the field, keeps focus in it and reloads the list at once. */
        clearNameSearch() {
            const field = this.$refs.nameSearch;
            if (!field) return undefined;
            clearTimeout(this.nameTimer);
            const had = field.value !== '' || new URL(window.location.href).searchParams.has('q');
            field.value = '';
            field.focus();
            this.showNameClear(false);
            return had ? this.applyNameSearch('') : undefined;
        },

        showNameClear(shown) {
            const button = this.$refs.nameClear;
            if (!button) return;
            // A class, not the hidden attribute: the button keeps its place, so the field never changes width.
            button.classList.toggle('is-hidden', !shown);
            if (shown) {
                button.removeAttribute('tabindex');
                button.removeAttribute('aria-hidden');
            } else {
                button.setAttribute('tabindex', '-1');
                button.setAttribute('aria-hidden', 'true');
            }
        },

        async applyNameSearch(value) {
            const text = value.trim();
            const url = filterUrl(window.location.href, 'q', text === '' ? [] : [text], true);
            window.history.replaceState(null, '', url.toString());
            await this.reloadList(url);
        },

        /** The reload URL with when it was made (the server's clock at render plus the time since), always later than the last. */
        stamped(url) {
            const clock = Number(this.screen.dataset.filtersClock);
            if (!clock) return url;
            this.lastStamp = Math.max(this.lastStamp + 1, Math.round(clock + Date.now() - this.loadedAt));
            const stamped = new URL(url.toString());
            stamped.searchParams.set('_filters_at', String(this.lastStamp));
            return stamped;
        },
    };
}
