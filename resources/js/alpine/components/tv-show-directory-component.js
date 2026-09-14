import { modalLifecycle } from './modal-lifecycle.js';

export function tvShowDirectory() {
    return {
        ...modalLifecycle(),
        open: false,
        loading: false,
        error: '',
        showId: null,
        directoryUrl: '',
        seasons: new Map(),
        episodes: new Map(),
        packs: new Map(),
        requests: new Map(),

        init() { this.directoryUrl = this.$el.dataset.directoryUrl; this.initModal(); },
        close() { this.open = false; },
        destroy() {
            this.requests.forEach(request => request.abort());
            this._modalTeardown?.();
        },
        url(parameters) {
            const url = new URL(this.directoryUrl, window.location.href);
            Object.entries({ show: this.showId, ...parameters }).forEach(([key, value]) => url.searchParams.set(key, value));
            return url;
        },
        async fetchHtml(target, parameters) {
            this.requests.get(target)?.abort();
            const request = new AbortController();
            this.requests.set(target, request);
            try {
                const response = await fetch(this.url(parameters), { signal: request.signal, headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } });
                if (!response.ok || response.redirected) throw new Error('Could not load this show list.');
                const html = await response.text();
                if (request.signal.aborted || this.requests.get(target) !== request) return null;
                return html;
            } finally {
                if (this.requests.get(target) === request) this.requests.delete(target);
            }
        },
        replace(target, html) {
            window.Alpine.mutateDom(() => {
                [...target.children].forEach(child => window.Alpine.destroyTree(child));
                target.innerHTML = html;
                [...target.children].forEach(child => window.Alpine.initTree(child));
            });
        },
        async openShow(event) {
            this.requests.forEach(request => request.abort());
            this.requests.clear();
            this.showId = event.currentTarget.dataset.showId;
            this.seasons.clear(); this.episodes.clear(); this.packs.clear();
            this.replace(this.$refs.showBody, '');
            this.open = true; this.loading = true; this.error = '';
            const id = this.showId;
            try {
                const html = await this.fetchHtml(this.$refs.showBody, { _fragment: 'show' });
                if (html === null || this.showId !== id) return;
                this.replace(this.$refs.showBody, html);
                const section = this.$refs.showBody.querySelector('[data-show-list]');
                if (section) this.seasons.set(section.dataset.season, section);
            } catch (error) {
                if (error.name !== 'AbortError' && this.showId === id) this.error = error.message;
            } finally { if (this.showId === id) this.loading = false; }
        },
        async switchSeason(button) {
            const number = button.dataset.showSeason;
            const host = this.$refs.showBody.querySelector('[data-season-host]');
            this.requests.get(host)?.abort();
            this.requests.delete(host);
            const current = host.firstElementChild;
            if (current?.dataset.season === number) return;
            if (current) {
                this.seasons.set(current.dataset.season, current);
                window.Alpine.mutateDom(() => current.remove());
            }
            this.$refs.showBody.querySelectorAll('[data-show-season]').forEach(tab => tab.setAttribute('aria-pressed', String(tab === button)));
            button.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            const cached = this.seasons.get(number);
            if (cached) window.Alpine.mutateDom(() => host.append(cached));
            else await this.loadList(host, { kind: 'season', season: number, page: 1, per: 24 });
        },
        async loadList(target, parameters) {
            this.error = '';
            try {
                const html = await this.fetchHtml(target, { _fragment: 'list', ...parameters });
                if (html === null) return;
                if (parameters.kind === 'season') {
                    window.Alpine.mutateDom(() => {
                        target.querySelectorAll('[data-episode-section]').forEach(episode => {
                            this.episodes.set(episode.dataset.episodeSection, episode); episode.remove();
                        });
                        const packs = target.querySelector('[data-pack-section]');
                        if (packs) { this.packs.set(String(parameters.season), packs); packs.remove(); }
                    });
                }
                this.replace(target, html);
                if (parameters.kind === 'season') {
                    window.Alpine.mutateDom(() => {
                        target.querySelectorAll('[data-episode-section]').forEach(episode => {
                            const saved = this.episodes.get(episode.dataset.episodeSection);
                            if (saved) { window.Alpine.destroyTree(episode); episode.replaceWith(saved); }
                        });
                        const savedPacks = this.packs.get(String(parameters.season));
                        const packs = target.querySelector('[data-pack-section]');
                        if (savedPacks && packs) { window.Alpine.destroyTree(packs); packs.replaceWith(savedPacks); }
                    });
                    this.seasons.set(String(parameters.season), target.firstElementChild);
                }
                target.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            } catch (error) { if (error.name !== 'AbortError') this.error = error.message; }
        },
        navigate(event) {
            const season = event.target.closest('[data-show-season]');
            if (season) { this.switchSeason(season); return; }
            const action = event.target.closest('[data-expand-episode], [data-expand-packs], [data-list-page]');
            if (!action || action.disabled) return;
            const list = action.closest('[data-show-list]');
            const parameters = { kind: list.dataset.kind, season: list.dataset.season, episode: list.dataset.episode, per: list.dataset.per, page: action.dataset.listPage || 1 };
            let target = list.parentElement;
            if (action.hasAttribute('data-expand-episode')) {
                parameters.kind = 'episode'; parameters.episode = action.dataset.expandEpisode; parameters.per = 24;
                target = action.parentElement;
            } else if (action.hasAttribute('data-expand-packs')) {
                parameters.kind = 'packs'; parameters.per = 24; target = action.parentElement;
            }
            this.loadList(target, parameters);
        },
        changePageSize(event) {
            if (!event.target.matches('[data-list-per]')) return;
            const list = event.target.closest('[data-show-list]');
            this.loadList(list.parentElement, { kind: list.dataset.kind, season: list.dataset.season, episode: list.dataset.episode, per: event.target.value, page: 1 });
        },
    };
}
