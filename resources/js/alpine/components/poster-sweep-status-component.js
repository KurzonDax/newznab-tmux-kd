/**
 * The blacklist sweep status in a poster list's pager line (docs/proposals/generic-release-lists/SPEC.md
 * 5.8, issue #1032 corrections 1 and 5). While the server rendered the run as running, the span polls
 * that run alone (data-status-url names it by id) every POLL_MS; when the run is no longer running,
 * cannot be found, or the request fails, it stops and asks the screen to reload the list, which the
 * server renders with the run's actual outcome and the refreshed rows and count. The text is never
 * written here: a reloaded fragment that still runs starts a new poll.
 */
export const POLL_MS = 3000;

export function posterSweepStatus() {
    return {
        timer: null,
        request: null,

        init() {
            if (this.$el.dataset.running === '1') this.timer = setInterval(() => this.poll(), POLL_MS);
        },

        destroy() {
            clearInterval(this.timer);
            this.timer = null;
            this.request?.abort();
        },

        async poll() {
            if (this.request) return;
            const request = new AbortController();
            this.request = request;
            let running = false;
            try {
                const response = await fetch(this.$el.dataset.statusUrl, { signal: request.signal, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                if (response.ok) running = Boolean((await response.json()).running);
            } catch (error) {
                if (error.name === 'AbortError') return;
            } finally {
                this.request = null;
            }
            if (running) return;
            clearInterval(this.timer);
            this.timer = null;
            this.$dispatch('blacklist-sweep-finished');
        },
    };
}
