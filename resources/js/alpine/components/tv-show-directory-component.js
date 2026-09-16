import { modalLifecycle } from './modal-lifecycle.js';

export function tvShowDirectory() {
    return {
        ...modalLifecycle(),
        open: false,
        loading: false,
        error: '',
        showId: null,
        currentSeason: null,
        directoryUrl: '',
        generation: 0,
        seasons: new Map(),
        requests: new Map(),
        restorationQueue: [],
        restorations: new Set(),

        init() { this.directoryUrl = this.$el.dataset.directoryUrl; this.initModal(); },
        cancelRequests() {
            this.generation++;
            this.requests.forEach(request => request.abort());
            this.requests.clear();
            this.restorationQueue = [];
            this.restorations.clear();
        },
        close() {
            this.open = false;
            this.cancelRequests();
            this.seasons.clear();
            this.showId = null;
            this.currentSeason = null;
            this.loading = false;
            this.error = '';
            if (this.$refs.showBody) this.replace(this.$refs.showBody, '');
        },
        destroy() {
            this.close();
            this._modalTeardown?.();
            this._modalTeardown = null;
        },
        url(parameters) {
            const url = new URL(this.directoryUrl, window.location.href);
            Object.entries({ show: this.showId, ...parameters }).forEach(([key, value]) => url.searchParams.set(key, value));
            return url;
        },
        async fetchHtml(parameters, apply) {
            const identity = [parameters._fragment, parameters.kind, parameters.season, parameters.episode].join(':');
            this.requests.get(identity)?.abort();
            const request = new AbortController();
            this.requests.set(identity, request);
            const show = this.showId, generation = this.generation, season = this.currentSeason;
            const current = () => this.open && this.showId === show && this.generation === generation
                && this.currentSeason === season && !request.signal.aborted && this.requests.get(identity) === request;
            try {
                const response = await fetch(this.url(parameters), { signal: request.signal, headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } });
                if (!response.ok || response.redirected) throw new Error('Could not load this show list.');
                const html = await response.text();
                if (current()) apply(html);
            } catch (error) {
                if (current() && error.name !== 'AbortError') this.error = error.message;
            } finally {
                if (this.requests.get(identity) === request) this.requests.delete(identity);
            }
        },
        replace(target, html) {
            window.Alpine.mutateDom(() => {
                [...target.children].forEach(child => window.Alpine.destroyTree(child));
                target.innerHTML = html;
                [...target.children].forEach(child => window.Alpine.initTree(child));
            });
        },
        seasonState(number) {
            const key = Number(number);
            if (!this.seasons.has(key)) this.seasons.set(key, {
                episodePage: 1, episodePer: 24, packExpanded: false, packPage: 1, packPer: 24, expandedEpisodes: {},
            });
            return this.seasons.get(key);
        },
        saveSeason() {
            const list = this.$refs.showBody.querySelector('[data-season-host]')?.firstElementChild;
            if (!list) return;
            const state = this.seasonState(list.dataset.season);
            state.episodePage = Number(list.dataset.page);
            state.episodePer = Number(list.dataset.per);
            list.querySelectorAll('[data-episode-section]').forEach(section => {
                const id = section.dataset.episodeSection;
                const releases = section.querySelector('[data-show-list]');
                if (section.open) state.expandedEpisodes[id] = {
                    page: Number(releases?.dataset.page ?? state.expandedEpisodes[id]?.page ?? 1),
                    per: Number(releases?.dataset.per ?? state.expandedEpisodes[id]?.per ?? 24),
                };
                else delete state.expandedEpisodes[id];
            });
            const packs = list.querySelector('[data-pack-section]');
            state.packExpanded = Boolean(packs?.open);
            const releases = packs?.querySelector('[data-show-list]');
            if (releases) { state.packPage = Number(releases.dataset.page); state.packPer = Number(releases.dataset.per); }
        },
        async openShow(event) {
            this.close();
            this.showId = event.currentTarget.dataset.showId;
            this.open = true; this.loading = true;
            const generation = this.generation;
            await this.fetchHtml({ _fragment: 'show' }, html => {
                this.replace(this.$refs.showBody, html);
                const section = this.$refs.showBody.querySelector('[data-show-list]');
                this.currentSeason = section ? Number(section.dataset.season) : null;
                this.saveSeason();
            });
            if (this.generation === generation) this.loading = false;
        },
        async switchSeason(button) {
            const number = Number(button.dataset.showSeason);
            const host = this.$refs.showBody.querySelector('[data-season-host]');
            if (!host || Number(host.firstElementChild?.dataset.season) === number) return;
            this.$refs.showBody.querySelectorAll('[data-show-season]').forEach(tab => tab.setAttribute('aria-pressed', String(tab === button)));
            button.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            const state = this.seasonState(number);
            await this.loadList(host, { kind: 'season', season: number, page: state.episodePage, per: state.episodePer });
        },
        restoreExpansions(target, season) {
            const state = this.seasonState(season);
            target.querySelectorAll('[data-episode-section]').forEach(section => {
                const episode = Number(section.dataset.episodeSection);
                const saved = state.expandedEpisodes[episode];
                if (!saved) return;
                section.open = true;
                this.restorationQueue.push({ kind: 'episode', season, episode, ...saved });
            });
            const packs = target.querySelector('[data-pack-section]');
            if (packs && state.packExpanded) {
                packs.open = true;
                this.restorationQueue.push({ kind: 'packs', season, page: state.packPage, per: state.packPer });
            }
            this.restoreNext();
        },
        restoreNext() {
            while (this.restorations.size < 4 && this.restorationQueue.length) {
                const parameters = this.restorationQueue.shift();
                const selector = parameters.kind === 'packs' ? '[data-pack-section]' : `[data-episode-section="${parameters.episode}"]`;
                const section = this.$refs.showBody.querySelector(selector);
                if (!section?.open) continue;
                const target = section.querySelector(parameters.kind === 'packs' ? '[data-pack-list]' : '[data-episode-releases]');
                if (!target) continue;
                const generation = this.generation;
                this.restorations.add(parameters);
                this.loadList(target, parameters, false).finally(() => {
                    if (this.generation !== generation) return;
                    this.restorations.delete(parameters);
                    this.restoreNext();
                });
            }
        },
        async loadList(target, parameters, scroll = true) {
            this.error = '';
            if (parameters.kind === 'season') {
                this.saveSeason();
                this.cancelRequests();
                this.currentSeason = Number(parameters.season);
                this.replace(target, '');
            }
            await this.fetchHtml({ _fragment: 'list', ...parameters }, html => {
                this.replace(target, html);
                if (parameters.kind === 'season') {
                    const list = target.firstElementChild;
                    const state = this.seasonState(parameters.season);
                    state.episodePage = Number(list.dataset.page);
                    state.episodePer = Number(list.dataset.per);
                    this.restoreExpansions(target, Number(parameters.season));
                }
                if (scroll) target.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            });
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
