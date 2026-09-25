/**
 * The results of GET /tv/search as one list in display order: shows first, then people.
 * A show opens its show page; a person opens the wall filtered to their shows.
 */
export function resultItems(data, urls) {
    const shows = (data.shows ?? []).map(show => ({
        kind: 'show',
        href: urls.show + '/' + show.id,
        title: show.title,
        detail: [show.year ?? '', (show.genres ?? []).join(', ')].filter(Boolean).join(' · '),
        poster: show.poster ?? null,
    }));
    const people = (data.people ?? []).map(person => ({
        kind: 'person',
        href: urls.shows + '?person=' + person.id,
        title: person.name,
        detail: person.shows.length + (person.shows.length === 1 ? ' show: ' : ' shows: ') + person.shows.slice(0, 3).join(', '),
        poster: null,
    }));
    return [...shows, ...people];
}

/**
 * The TV section's "Search shows or actors" field (x-tv-search): asks the TV search as the
 * user types, lists Shows then People under the field, and supports the arrow keys, Enter
 * and Escape. The site's top-bar search is separate and unchanged.
 */
export function tvSearch() {
    return {
        open: false,
        highlighted: 0,
        items: [],
        request: null,
        timer: null,
        root: null,

        init() {
            this.root = this.$el;
        },

        changed() {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.search(), 150);
        },

        reopen() {
            if (this.$refs.field.value.trim()) this.search();
        },

        async search() {
            const text = this.$refs.field.value.trim();
            this.request?.abort();
            if (!text) {
                this.close();
                return;
            }
            const request = new AbortController();
            this.request = request;
            const url = new URL(this.root.dataset.searchUrl, window.location.href);
            url.searchParams.set('q', text);
            try {
                const response = await fetch(url.toString(), { signal: request.signal, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                if (!response.ok) throw new Error('Search failed');
                const data = await response.json();
                if (request.signal.aborted) return;
                this.items = resultItems(data, { show: this.root.dataset.showUrl, shows: this.root.dataset.showsUrl });
                this.highlighted = 0;
                this.render(text);
                this.open = true;
            } catch (error) {
                if (error.name !== 'AbortError') this.close();
            }
        },

        render(text) {
            const box = this.$refs.results;
            box.replaceChildren();
            if (!this.items.length) {
                const empty = document.createElement('p');
                empty.textContent = 'Nothing called “' + text + '” here.';
                box.append(empty);
                return;
            }
            let group = null;
            this.items.forEach((item, index) => {
                if (item.kind !== group) {
                    group = item.kind;
                    const heading = document.createElement('h4');
                    heading.textContent = group === 'show' ? 'Shows' : 'People';
                    box.append(heading);
                }
                const link = document.createElement('a');
                link.href = item.href;
                link.id = 'tv-search-option-' + index;
                link.setAttribute('role', 'option');
                if (item.kind === 'person') {
                    link.classList.add('is-person');
                } else if (item.poster) {
                    const poster = document.createElement('img');
                    poster.src = item.poster;
                    poster.alt = '';
                    link.append(poster);
                } else {
                    const card = document.createElement('span');
                    card.className = 'tv-search-card';
                    link.append(card);
                }
                const words = document.createElement('span');
                const title = document.createElement('b');
                title.textContent = item.title;
                const detail = document.createElement('span');
                detail.textContent = item.detail;
                words.append(title, detail);
                link.append(words);
                box.append(link);
            });
            this.mark();
        },

        mark() {
            this.$refs.results.querySelectorAll('a').forEach((link, index) => {
                link.classList.toggle('is-highlighted', index === this.highlighted);
                link.setAttribute('aria-selected', index === this.highlighted ? 'true' : 'false');
            });
            if (this.items.length) this.$refs.field.setAttribute('aria-activedescendant', 'tv-search-option-' + this.highlighted);
            else this.$refs.field.removeAttribute('aria-activedescendant');
        },

        handleKey(event) {
            if (!this.open) return;
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                this.highlighted = Math.max(0, Math.min(this.items.length - 1, this.highlighted + (event.key === 'ArrowDown' ? 1 : -1)));
                this.mark();
            } else if (event.key === 'Enter') {
                event.preventDefault();
                const item = this.items[this.highlighted];
                if (!item) return;
                this.reset();
                window.location.assign(item.href);
            } else if (event.key === 'Escape') {
                this.close();
                this.$refs.field.blur();
            }
        },

        picked(event) {
            if (event.target.closest('a')) this.reset();
        },

        reset() {
            this.$refs.field.value = '';
            this.close();
        },

        close() {
            this.open = false;
        },
    };
}
