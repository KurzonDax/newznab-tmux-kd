import { tvReleaseDetails, TABS } from './tv-release-details-component.js';
import { currentSort, nextSort, offeredSorts, sortUrl } from './movie-film-component.js';
import { sortRows } from './tv-episode-list-component.js';
import { fetchList } from './tv-list.js';

/**
 * The URL of another page of "All N releases of this film": the page link's own query (its sort
 * and page), keeping the tab open now in the hash so the page reopens on it.
 */
export function tablePageUrl(href, current) {
    const url = new URL(href);
    const tab = new URL(current).hash.slice(1);
    url.hash = TABS.includes(tab) && tab !== 'overview' ? tab : '';
    return url;
}

/**
 * Similar releases in an order: the Books, Console and PC tables' rows carry data-category, and
 * their ties come newest posted first, then the higher id (the prototype's detSort); the Movies
 * and Adult rows keep their previous order on a tie.
 */
export function sortSimilarRows(rows, sort) {
    if (!rows.some(row => row.dataset.category !== undefined)) return sortRows(rows, sort);
    const value = (row, key) => Number(row.dataset[key]);
    return [...rows].sort((a, b) => (value(a, sort.key) - value(b, sort.key)) * sort.dir || value(b, 'posted') - value(a, 'posted') || value(b, 'id') - value(a, 'id'));
}

/** Whether a URL names a page of the table without naming a tab, so the page opens at the table. */
export function opensAtTable(href) {
    const url = new URL(href);
    return url.searchParams.has('page') && !TABS.includes(url.hash.slice(1));
}

/**
 * The Movies release details page (details/movies/index.blade.php): TV's tabs, dialogs and header
 * buttons, plus the two tables. "All N releases of this film" sorts and pages on the server
 * (?sort=, ?page=, loaded in place as ?_fragment=releases): a sort change returns to the page
 * holding this release, another page keeps the open tab and brings the table into view, and the
 * page is in the URL so Back works. Similar releases sorts in the browser on its own.
 */
export function movieReleaseDetails() {
    const tv = tvReleaseDetails();

    return {
        ...tv,
        request: null,
        similarSort: { key: 'posted', dir: -1 },

        init() {
            tv.init.call(this);
            // Back or Forward to another page or order of the table; a tab's hash alone changes nothing here
            this._query = window.location.search;
            this._popped = () => {
                if (window.location.search === this._query) return;
                this._query = window.location.search;
                this.reloadTable(window.location.href);
            };
            window.addEventListener('popstate', this._popped);
            if (opensAtTable(window.location.href)) this.$refs.releases?.scrollIntoView();
        },

        destroy() {
            tv.destroy.call(this);
            window.removeEventListener('popstate', this._popped);
        },

        handleClick(event) {
            const similar = event.target.closest('[data-similar-sort]');
            if (similar) return this.sortSimilar(similar);
            const pageLink = event.target.closest('[data-film-releases] nav a[href]');
            if (pageLink) {
                event.preventDefault();
                return this.openPage(tablePageUrl(pageLink.href, window.location.href));
            }
            const sort = event.target.closest('[data-sort]');
            if (sort) return this.sortTable(sort.dataset.sort);
            return tv.handleClick.call(this, event);
        },

        /** The bottom pager's "Go to page" field. */
        handleSubmit(event) {
            const form = event.target.closest('[data-film-releases] form');
            if (!form) return;
            event.preventDefault();
            const url = new URL(form.action);
            new FormData(form).forEach((value, key) => url.searchParams.append(key, String(value)));
            this.openPage(tablePageUrl(url.toString(), window.location.href));
        },

        async openPage(url) {
            window.history.pushState(null, '', url.toString());
            this._query = window.location.search;
            if (await this.reloadTable(url)) this.$refs.releases?.scrollIntoView();
        },

        async sortTable(key) {
            const url = sortUrl(window.location.href, nextSort(currentSort(window.location.href, offeredSorts(this.$refs.releases)), key));
            window.history.replaceState(null, '', url.toString());
            this._query = window.location.search;
            if (await this.reloadTable(url)) this.$refs.releases?.querySelector(`[data-sort="${key}"]`)?.focus({ preventScroll: true });
        },

        /** Loads the table's section for a URL in place; false when it failed or another load replaced it. */
        async reloadTable(url) {
            if (!this.$refs.releases) return false;
            this.request?.abort();
            const request = new AbortController();
            this.request = request;
            try {
                const html = await fetchList(url, request.signal, 'releases');
                if (request.signal.aborted) return false;
                this.$refs.releases.innerHTML = html;
                return true;
            } catch (error) {
                if (error.name !== 'AbortError') window.showToast('Could not load the releases. Reload the page and try again.', 'error');
                return false;
            }
        },

        sortSimilar(button) {
            this.similarSort = nextSort(this.similarSort, button.dataset.similarSort);
            const table = button.closest('table');
            const body = table.tBodies[0];
            body.append(...sortSimilarRows(Array.from(body.rows), this.similarSort));
            table.querySelectorAll('th').forEach(cell => {
                const sorter = cell.querySelector('[data-similar-sort]');
                if (sorter && sorter.dataset.similarSort === this.similarSort.key) cell.setAttribute('aria-sort', this.similarSort.dir > 0 ? 'ascending' : 'descending');
                else cell.removeAttribute('aria-sort');
            });
        },
    };
}
