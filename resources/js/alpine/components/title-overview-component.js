export const titleOverview = () => ({
    loading: false,
    error: '',
    request: null,
    activeUrl: null,
    pendingUrl: null,

    init() {
        this.activeUrl = new URL(window.location.href);
        this.pendingUrl = new URL(this.activeUrl);
    },
    artworkFailed(event) {
        event.target.closest('.title-artwork').dataset.hasArt = '0';
    },
    navigateReleases(event) {
        const quality = event.target.closest('[data-title-quality]');
        const page = event.target.closest('a[data-title-page]');
        if (!quality && !page) return;
        if (page && (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button > 0)) return;
        event.preventDefault();
        const url = page ? new URL(page.href) : new URL(this.pendingUrl);
        if (quality) {
            const selected = new Set();
            const keys = [...url.searchParams.keys()].filter(key => /^quality(?:\[\d*\])?$/.test(key));
            keys.forEach(key => url.searchParams.getAll(key).forEach(value => selected.add(value)));
            keys.forEach(key => url.searchParams.delete(key));
            const value = quality.dataset.titleQuality;
            if (value === '') selected.clear();
            else if (selected.has(value)) selected.delete(value);
            else selected.add(value);
            selected.forEach(value => url.searchParams.append('quality[]', value));
            url.searchParams.delete('page');
        }
        this.loadReleases(url);
    },
    async loadReleases(url) {
        this.request?.abort();
        const request = new AbortController();
        this.request = request;
        this.pendingUrl = new URL(url);
        this.loading = true;
        this.error = '';
        const fragmentUrl = new URL(url);
        fragmentUrl.searchParams.set('_fragment', 'releases');
        try {
            const response = await fetch(fragmentUrl, { signal: request.signal, headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok || response.redirected) throw new Error('Could not load releases');
            const html = await response.text();
            if (!html.includes('data-title-releases')) throw new Error('Unexpected releases response');
            if (request.signal.aborted || this.request !== request) return;
            this.$refs.releases.innerHTML = html;
            this.activeUrl = new URL(url);
            window.history.replaceState(null, '', url);
        } catch (error) {
            if (request.signal.aborted || this.request !== request) return;
            this.error = 'Could not load releases. Please try again.';
            this.pendingUrl = new URL(this.activeUrl);
        } finally {
            if (this.request === request) this.loading = false;
        }
    },
    destroy() { this.request?.abort(); },
});
