import { fetchList, filterUrl, firstPageUrl, postJson } from './tv-list.js';

const FILTERS = ['genre', 'decade', 'language', 'network', 'rating', 'status', 'person'];

/** Whether a wall URL narrows the shows (the person included); the sort and page do not. */
export function hasFilters(url) {
    return [...url.searchParams.keys()].some(key => FILTERS.includes(key.replace(/\[\d*\]$/, '')));
}

/**
 * The TV shows wall (tv/shows/index.blade.php): a filter menu change rewrites the URL on
 * page 1, shows or hides "Clear all" in its fixed place and reloads the list in place; the
 * sort is saved per user and reloads the page.
 */
export function tvShows() {
    return {
        screen: null,
        request: null,

        init() {
            this.screen = this.$el;
        },

        async applyFilter(event) {
            const { name, values } = event.detail;
            const url = filterUrl(window.location.href, name, values);
            window.history.replaceState(null, '', url.toString());
            this.showClearAll(hasFilters(url));
            this.keepPersonLink(url);
            await this.reloadList(url);
        },

        showClearAll(on) {
            const link = this.screen.querySelector('[data-clear-all]');
            if (!link) return;
            link.classList.toggle('is-hidden', !on);
            link.setAttribute('aria-hidden', on ? 'false' : 'true');
            if (on) link.removeAttribute('tabindex');
            else link.setAttribute('tabindex', '-1');
        },

        /** The Starring chip's remove link keeps the filters ticked since the page loaded. */
        keepPersonLink(url) {
            const link = this.screen.querySelector('[data-remove-person]');
            if (!link) return;
            const without = new URL(url.toString());
            without.searchParams.delete('person');
            link.setAttribute('href', without.toString());
        },

        async changeSort(event) {
            try {
                await postJson(this.screen.dataset.preferenceUrl, { root: 'tv', shows_sort: event.target.value });
            } catch {
                window.showToast('Could not save your sort order. Please try again.', 'error');
                return;
            }
            window.location.assign(firstPageUrl(window.location.href).toString());
        },

        async reloadList(url) {
            this.request?.abort();
            const request = new AbortController();
            this.request = request;
            try {
                const html = await fetchList(url, request.signal);
                if (request.signal.aborted) return;
                this.$refs.list.innerHTML = html;
            } catch (error) {
                if (error.name !== 'AbortError') window.showToast('Could not load the shows. Reload the page and try again.', 'error');
            }
        },
    };
}
