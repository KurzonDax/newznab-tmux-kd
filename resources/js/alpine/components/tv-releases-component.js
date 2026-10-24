import { summaryAfterDelete, takeOutRow } from './release-delete-component.js';
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
 *
 * On a search (issue #1050) the Category cell is the search's scope: the page names the address
 * parameter (data-scope-param) and the ticked roots travel in it, comma separated. The reloaded
 * fragment brings the query row; what it says the header's scope picker shows is passed on in a
 * `search-scope` event. Leaving a legacy scope (data-scope-custom) redraws the whole page.
 *
 * On the basket (issue #1051, data-basket) every row is in the basket, so a row's cart button only
 * takes its release out: the list is then drawn again without the row, and the heading row's
 * Download N NZBs follows the count the redrawn pager line carries (both actions leave with the
 * last release).
 *
 * On the Adult list an admin deletes a release from its row (docs/proposals/adult-redesign/SPEC.md 5.11,
 * release-deleted from releaseDeleteDialog): the row leaves the page, which is not refilled, the pager
 * line's count drops by one and the selection follows; the last row on a page draws the list again at
 * the current address, as the basket does.
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
            if (cart) return this.screen.dataset.basket === undefined ? this.toggleCart(cart) : this.removeFromBasket(cart);
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
            const scope = name === 'category' ? this.screen.dataset.scopeParam : undefined;
            const url = scope ? paramsUrl(window.location.href, { [scope]: this.tickedValues(event.target).join(',') })
                : params ? paramsUrl(window.location.href, params) : filterUrl(window.location.href, name, values, single);
            // An explicit Completion choice (Any included) retires today's minc link (the generic lists, issue #1032): it never resurfaces.
            if (name === 'completion') url.searchParams.delete('minc');
            if (scope && this.screen.dataset.scopeCustom !== undefined) {
                window.location.assign(url.toString());
                return;
            }
            window.history.replaceState(null, '', url.toString());
            await this.reloadList(url);
            if (this.screen.dataset.scopeParam) this.announceScope();
        },

        /** The values a menu has ticked, in menu order: a search's scope is every ticked root, Exclude Other's set included. */
        tickedValues(menu) {
            return Array.from(menu.querySelectorAll('[data-value][aria-checked="true"]')).map(item => item.dataset.value);
        },

        /** Tells the header's scope picker what the reloaded query row says it shows and keeps. */
        announceScope() {
            const row = this.$refs.list.querySelector('[data-query-row]');
            if (!row) return;
            window.dispatchEvent(new CustomEvent('search-scope', { detail: { choice: row.dataset.scopeChoice, value: row.dataset.scopeValue } }));
        },

        /** The list reloaded at the current URL: a poster list's finished blacklist sweep (posterSweepStatus) asks for it. */
        refreshList() {
            return this.reloadList(new URL(window.location.href));
        },

        /**
         * The basket's row button only takes a release out: once it has, the button reads unpressed until the list
         * is drawn again without its row, and a click on it then does nothing (it never adds the release back).
         */
        async removeFromBasket(button) {
            if (button.getAttribute('aria-pressed') !== 'true') return;
            if (await this.toggleCart(button)) await this.redrawBasket();
        },

        /**
         * The basket's list at the current address, then the heading row's count from the redrawn pager line. When
         * the list cannot be read (the removed release was the last on the last page, so that page is gone and the
         * server redirects) the address is opened again.
         */
        async redrawBasket() {
            if (!(await this.redrawHere())) return;
            const total = this.$refs.list.querySelector('[data-basket-total]')?.dataset.basketTotal;
            if (total === undefined) return;
            if (total === '0') {
                this.screen.querySelectorAll('[data-basket-action]').forEach(action => action.remove());
                return;
            }
            const count = this.screen.querySelector('[data-download-count]');
            if (count) count.textContent = total;
        },

        /**
         * The list at the current address drawn again; resolves true when it was. When it cannot be read (the page
         * was the last and is gone, so the server redirects) the address is opened again.
         */
        async redrawHere() {
            try {
                return await this.drawList(new URL(window.location.href));
            } catch {
                window.location.assign(window.location.href);
                return false;
            }
        },

        /**
         * A release an admin deleted (release-deleted on its row): the row goes and the trashcan now in its place takes
         * the focus, the selection and the pager line's "Showing X–Y of N" following. When it was the page's last row
         * the list is drawn again at the current address and the name search takes the focus.
         */
        async releaseDeleted(event) {
            const row = event.target.closest('[data-release-row]');
            if (!row) return;
            const next = takeOutRow(row);
            this.selectionChanged();
            if (next === null) {
                if (await this.redrawHere()) this.$refs.nameSearch?.focus();
                return;
            }
            const summary = this.$refs.list.querySelector('.pager-line-summary');
            if (summary) summary.textContent = summaryAfterDelete(summary.textContent);
            next.focus();
        },

        async reloadList(url) {
            try {
                await this.drawList(url);
            } catch {
                window.showToast('Could not load the releases. Reload the page and try again.', 'error');
            }
        },

        /**
         * Reads the list at a URL and draws it in place of the current one. Resolves true when it was drawn and false
         * when a later request took its place; rejects when the list could not be read.
         */
        async drawList(url) {
            this.request?.abort();
            const request = new AbortController();
            this.request = request;
            let html;
            try {
                html = await fetchList(this.stamped(url), request.signal);
            } catch (error) {
                if (error.name === 'AbortError') return false;
                throw error;
            }
            if (request.signal.aborted) return false;
            this.$refs.list.innerHTML = html;
            this.selectionChanged();
            return true;
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
