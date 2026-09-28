import { fetchList, filterUrl } from './tv-list.js';
import { rowActions } from './tv-row-actions.js';

/** sessionStorage key of the selection, kept per show so it carries across episodes and seasons. */
export const selectionKey = show => 'tv-show-selection:' + show;

/** sessionStorage key of a season switch in progress: the page it leaves, and where it was scrolled. */
export const SWITCH_KEY = 'tv-show-season-switch';

/** The next table order: the same header flips it, another header starts largest / newest first. */
export function nextSort(current, key) {
    return current.key === key ? { key, dir: -current.dir } : { key, dir: -1 };
}

/** Rows ordered by a numeric data attribute (data-size, data-posted, data-resolution; data-grabs on the details page's table); ties keep their order. */
export function sortRows(rows, sort) {
    return [...rows].sort((a, b) => (Number(a.dataset[sort.key]) - Number(b.dataset[sort.key])) * sort.dir);
}

/** The list fragment's URL: the page's URL with the episodes that are open now. */
export function listUrl(href, open) {
    const url = new URL(href);
    url.searchParams.delete('open');
    url.searchParams.delete('open[]');
    open.forEach(episode => url.searchParams.append('open[]', episode));
    return url;
}

/** The page's URL asking for one episode's release table (fetched as ?_fragment=episode). */
export function episodeUrl(href, episode) {
    const url = listUrl(href, []);
    url.searchParams.set('episode', episode);
    return url;
}

function readJson(key) {
    try {
        return JSON.parse(window.sessionStorage.getItem(key) ?? 'null');
    } catch {
        return null;
    }
}

function writeJson(key, value) {
    try {
        if (value === null) window.sessionStorage.removeItem(key);
        else window.sessionStorage.setItem(key, JSON.stringify(value));
    } catch {
        // storage unavailable: the selection lasts until the page changes
    }
}

/** Scrolls the tab row, never the page, so the whole current season tab is inside its visible width. */
function revealTab(tab) {
    const row = tab.closest('.tv-season-tabs');
    if (!row) return;
    const rowBox = row.getBoundingClientRect(), tabBox = tab.getBoundingClientRect();
    if (tabBox.left < rowBox.left) row.scrollLeft -= rowBox.left - tabBox.left;
    else if (tabBox.right > rowBox.right) row.scrollLeft += tabBox.right - rowBox.right;
}

const MODIFIER_KEYS = ['Shift', 'Control', 'Alt', 'Meta'];

/**
 * Focuses the current season tab after a switch. A keyboard switch always shows the ring
 * (data-focus-ring, until the tab loses focus); after a mouse switch the tab stays quiet
 * (data-focus-quiet) until a key that moves around the page, Shift+Tab included, but not
 * a lone modifier or a Ctrl, Meta or Alt shortcut.
 */
function keepFocus(tab, pointer) {
    tab.focus({ preventScroll: true });
    if (!pointer) {
        tab.setAttribute('data-focus-ring', '');
        tab.addEventListener('blur', () => tab.removeAttribute('data-focus-ring'), { once: true });
        return;
    }
    tab.setAttribute('data-focus-quiet', '');
    const wake = event => {
        if (MODIFIER_KEYS.includes(event.key) || event.ctrlKey || event.metaKey || event.altKey) return;
        tab.removeAttribute('data-focus-quiet');
        document.removeEventListener('keydown', wake, true);
    };
    document.addEventListener('keydown', wake, true);
}

/**
 * The show page (tv/show/index.blade.php): episode rows that open their release table in
 * place, the release tables' sortable headers, the selection and its floating bar (kept per
 * show in sessionStorage), season tabs that keep focus and scroll across the page load, and
 * reloading the episode list when a filter menu changes.
 */
export function tvEpisodeList() {
    return {
        selectedCount: 0,
        screen: null,
        show: '',
        pageUrl: '',
        selection: new Set(),
        sort: { key: 'size', dir: -1 },
        request: null,

        init() {
            this.screen = this.$el;
            this.show = this.screen.dataset.show;
            // The URL of what the page rendered, season included, even when the address bar has none.
            this.pageUrl = this.screen.dataset.pageUrl ?? window.location.href;
            this.selection = new Set(readJson(selectionKey(this.show)) ?? []);
            this.syncBoxes();
            const tab = this.screen.querySelector('.tv-season-tabs [aria-current]');
            if (tab) revealTab(tab);
            const leaving = readJson(SWITCH_KEY);
            if (leaving && leaving.show === this.show) {
                writeJson(SWITCH_KEY, null);
                window.scrollTo(0, leaving.y);
                if (tab) keepFocus(tab, leaving.pointer === true);
            }
        },

        handleClick(event) {
            const episode = event.target.closest('[data-ep]');
            if (episode) return this.toggleEpisode(episode);
            const sort = event.target.closest('[data-sort]');
            if (sort) return this.sortBy(sort);
            const tab = event.target.closest('.tv-season-tabs a');
            if (tab) return this.switchSeason(event, tab);
            const copy = event.target.closest('[data-copy-nzb]');
            if (copy) return this.copyLink(copy);
            const cart = event.target.closest('[data-cart]');
            if (cart) return this.toggleCart(cart);
            return undefined;
        },

        /**
         * A plain click replaces the history entry, so Back returns to the page the show was opened from.
         * Modified and non-primary clicks (new tab, new window, download) are left to the browser.
         * A mouse switch is recorded as `pointer`, so the next page keeps the focused tab quiet.
         */
        switchSeason(event, tab) {
            if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            // A mouse click has a click count (detail); Enter on the tab has none.
            writeJson(SWITCH_KEY, { show: this.show, y: window.scrollY, ...(event.detail > 0 ? { pointer: true } : {}) });
            event.preventDefault();
            window.location.replace(tab.href);
        },

        handleChange(event) {
            const box = event.target;
            if (!box.matches('[data-select]')) return;
            if (box.checked) this.selection.add(box.value);
            else this.selection.delete(box.value);
            this.saveSelection();
        },

        saveSelection() {
            writeJson(selectionKey(this.show), this.selection.size ? [...this.selection] : null);
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

        async toggleEpisode(button) {
            const row = button.closest('.tv-episode'), releases = row.querySelector('.tv-episode-releases');
            if (row.hasAttribute('data-open')) {
                row.removeAttribute('data-open');
                button.setAttribute('aria-expanded', 'false');
                releases.innerHTML = '';
                return;
            }
            row.setAttribute('data-open', '');
            button.setAttribute('aria-expanded', 'true');
            try {
                const html = await fetchList(episodeUrl(this.pageUrl, button.dataset.ep), undefined, 'episode');
                if (!row.hasAttribute('data-open')) return;
                releases.innerHTML = html;
                this.arrange(releases);
            } catch {
                row.removeAttribute('data-open');
                button.setAttribute('aria-expanded', 'false');
                window.showToast('Could not load the releases. Reload the page and try again.', 'error');
            }
        },

        sortBy(button) {
            this.sort = nextSort(this.sort, button.dataset.sort);
            this.arrange(this.$refs.list);
        },

        /** Orders every release table inside `root` by the current sort and ticks the selected boxes. */
        arrange(root) {
            root.querySelectorAll('.tv-release-table').forEach(table => {
                const body = table.tBodies[0];
                body.append(...sortRows(Array.from(body.rows), this.sort));
                table.querySelectorAll('th').forEach(cell => {
                    const button = cell.querySelector('[data-sort]');
                    if (button && button.dataset.sort === this.sort.key) cell.setAttribute('aria-sort', this.sort.dir > 0 ? 'ascending' : 'descending');
                    else cell.removeAttribute('aria-sort');
                });
            });
            this.syncBoxes();
        },

        openEpisodes() {
            return Array.from(this.screen.querySelectorAll('.tv-episode[data-open]')).map(row => row.dataset.episode);
        },

        async applyFilter(event) {
            const { name, values } = event.detail;
            const url = listUrl(filterUrl(this.pageUrl, name, values).toString(), []);
            this.pageUrl = url.toString();
            window.history.replaceState(null, '', this.pageUrl);
            this.screen.querySelectorAll('.tv-season-tabs a').forEach(tab => {
                tab.setAttribute('href', filterUrl(tab.href, name, values).toString());
            });
            this.request?.abort();
            const request = new AbortController();
            this.request = request;
            try {
                const html = await fetchList(listUrl(url.toString(), this.openEpisodes()), request.signal);
                if (request.signal.aborted) return;
                this.$refs.list.innerHTML = html;
                this.arrange(this.$refs.list);
            } catch (error) {
                if (error.name !== 'AbortError') window.showToast('Could not load the releases. Reload the page and try again.', 'error');
            }
        },
    };
}
