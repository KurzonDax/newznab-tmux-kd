import { fetchList, filterUrl } from './tv-list.js';
import { rowActions } from './tv-row-actions.js';

/**
 * sessionStorage key of the film page's selection: one for every film, so the selection
 * carries across the table's pages and into another film's page (SPEC 5B.2).
 */
export const SELECTION_KEY = 'movies-film-selection';

/** The table's order in a film page URL: `?sort=size` (descending), `?sort=size_asc`; newest posted first without one. */
export function currentSort(href) {
    const value = new URL(href).searchParams.get('sort') ?? '';
    const ascending = value.endsWith('_asc'), key = ascending ? value.slice(0, -4) : value;
    return ['resolution', 'size', 'posted'].includes(key) ? { key, dir: ascending ? 1 : -1 } : { key: 'posted', dir: -1 };
}

/** The next order: the sorted heading flips it, another heading starts descending; Category starts ascending, in the Category menu's order. */
export function nextSort(current, key) {
    return current.key === key ? { key, dir: -current.dir } : { key, dir: key === 'category' ? 1 : -1 };
}

/** The URL of the first page in that order; newest posted first carries no sort. */
export function sortUrl(href, sort) {
    const url = new URL(href);
    url.searchParams.delete('page');
    if (sort.key === 'posted' && sort.dir < 0) url.searchParams.delete('sort');
    else url.searchParams.set('sort', sort.key + (sort.dir > 0 ? '_asc' : ''));
    return url;
}

function readSelection() {
    try {
        return JSON.parse(window.sessionStorage.getItem(SELECTION_KEY) ?? 'null') ?? [];
    } catch {
        return [];
    }
}

function writeSelection(guids) {
    try {
        if (guids.length) window.sessionStorage.setItem(SELECTION_KEY, JSON.stringify(guids));
        else window.sessionStorage.removeItem(SELECTION_KEY);
    } catch {
        // storage unavailable: the selection lasts until the page changes
    }
}

/**
 * The film page (movies/film/index.blade.php): the Resolution and Source cells and the sortable
 * headings reload the table in place on page 1 (the URL follows, so Back and the pager keep
 * them); the selection and its floating bar, kept in sessionStorage; the row actions.
 */
export function movieFilm() {
    return {
        selectedCount: 0,
        screen: null,
        selection: new Set(),
        request: null,

        init() {
            this.screen = this.$el;
            this.selection = new Set(readSelection());
            this.syncBoxes();
        },

        handleClick(event) {
            const sort = event.target.closest('[data-sort]');
            if (sort) return this.sortBy(sort.dataset.sort);
            const copy = event.target.closest('[data-copy-nzb]');
            if (copy) return this.copyLink(copy);
            const cart = event.target.closest('[data-cart]');
            if (cart) return this.toggleCart(cart);
            return undefined;
        },

        handleChange(event) {
            const box = event.target;
            if (!box.matches('[data-select]')) return;
            if (box.checked) this.selection.add(box.value);
            else this.selection.delete(box.value);
            this.saveSelection();
        },

        saveSelection() {
            writeSelection([...this.selection]);
            this.selectedCount = this.selection.size;
        },

        /** Ticks the boxes of the selected releases on the page. */
        syncBoxes() {
            this.screen.querySelectorAll('[data-select]').forEach(box => { box.checked = this.selection.has(box.value); });
            this.selectedCount = this.selection.size;
        },

        selectedGuids() {
            return [...this.selection];
        },

        clearSelection() {
            this.selection.clear();
            this.saveSelection();
            this.syncBoxes();
        },

        ...rowActions(),

        async sortBy(key) {
            await this.reloadList(sortUrl(window.location.href, nextSort(currentSort(window.location.href), key)));
            this.$refs.list.querySelector(`[data-sort="${key}"]`)?.focus({ preventScroll: true });
        },

        /** A cell's change: {name, values}. */
        async applyFilter(event) {
            const { name, values } = event.detail;
            await this.reloadList(filterUrl(window.location.href, name, values));
        },

        async reloadList(url) {
            window.history.replaceState(null, '', url.toString());
            this.request?.abort();
            const request = new AbortController();
            this.request = request;
            try {
                const html = await fetchList(url, request.signal);
                if (request.signal.aborted) return;
                this.$refs.list.innerHTML = html;
                this.syncBoxes();
            } catch (error) {
                if (error.name !== 'AbortError') window.showToast('Could not load the releases. Reload the page and try again.', 'error');
            }
        },
    };
}
