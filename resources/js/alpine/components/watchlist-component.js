import { modalLifecycle } from './modal-lifecycle.js';

export function watchlistPicker() {
    return {
        ...modalLifecycle(),
        open: false, loading: false, saving: false, error: '', current: null, categories: [], selected: [],
        get heading() { return this.current ? `${this.current.watched ? 'Edit' : 'Add'} “${this.current.title}” ${this.current.watched ? 'on' : 'to'} ${this.current.listName}` : 'Following'; },
        get saveLabel() { return this.current?.watched ? 'Save' : 'Add'; },
        get watched() { return this.current?.watched === true; },
        get busy() { return this.loading || this.saving; },
        init() {
            this.rootElement = this.$el;
            this.initModal();
            this._watchClick = event => {
                const button = event.target.closest('[data-watch-picker], [data-watch-remove]');
                if (!button) return;
                event.preventDefault();
                if (this.saving) return;
                button.focus();
                this.activate(button);
            };
            document.addEventListener('click', this._watchClick);
            const location = new URL(window.location.href), match = location.pathname.match(/^\/title\/(movies|tv)\/(\d+)$/);
            if (location.searchParams.get('watch') === '1' && match) {
                this.load(`${this.rootElement.dataset.watchlistBase}/${match[1]}/${match[2]}`);
                location.searchParams.delete('watch');
                window.history.replaceState(null, '', location);
            }
        },
        // The picker opens only to start following: a follow button on a followed title unfollows in one click. The Edit / Add /
        // Remove buttons (data-watch-label-kind, data-watch-remove) on the Following and title pages keep their own actions.
        activate(button) {
            if (button.dataset.watchRemove) return this.remove(button.dataset.watchRemove);
            if (button.dataset.watched === '1' && !button.dataset.watchLabelKind) return this.unfollow(button.dataset.watchPicker);
            return this.load(button.dataset.watchPicker);
        },
        destroy() { this.request?.abort(); document.removeEventListener('click', this._watchClick); this._modalTeardown?.(); },
        async response(url, method = 'GET', payload = null, signal = null) {
            const response = await fetch(url, { method, signal, headers: {
                Accept: 'application/json', 'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            }, ...(payload ? { body: JSON.stringify(payload) } : {}) });
            if (response.redirected) throw new Error('Your session changed. Reload this page to continue.');
            const data = await response.json();
            if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'Could not update what you follow.');
            return data;
        },
        async load(url) {
            this.request?.abort();
            const request = new AbortController(); this.request = request;
            this.current = null; this.categories = []; this.selected = []; this.error = ''; this.loading = true; this.open = true;
            try {
                const data = await this.response(url, 'GET', null, request.signal);
                if (request.signal.aborted) return;
                this.current = data; this.categories = data.categories; this.selected = data.selected;
            } catch (error) { if (error.name !== 'AbortError') this.error = error.message; }
            finally { if (this.request === request) this.loading = false; }
        },
        close() { this.request?.abort(); this.open = false; this.error = ''; },
        async save() {
            if (this.busy || !this.current) return;
            if (!this.selected.length) { window.showToast('Pick at least one category.', 'warning'); return; }
            this.saving = true; this.error = '';
            try {
                const previous = this.current.watched;
                const data = await this.response(this.current.url, 'POST', { categories: this.selected.map(Number) });
                this.changed(data); this.open = false;
                const labels = data.categories.filter(category => data.selected.includes(category.id)).map(category => category.label).join(', ');
                window.showToast(`${previous ? `Updated ${data.title} on ${data.listName}` : `Following ${data.title}`} · ${labels}`, 'success', { label: 'Open', href: data.watchlistUrl });
            } catch (error) {
                if (this.open) this.error = error.message;
                else window.showToast(error.message, 'error');
            } finally { this.saving = false; }
        },
        async unfollow(url) {
            if (this.saving) return;
            this.saving = true;
            try {
                const data = await this.response(url, 'DELETE');
                this.changed(data);
                window.showToast(`Unfollowed ${data.title}`, 'info');
            } catch (error) { window.showToast(error.message, 'error'); }
            finally { this.saving = false; }
        },
        removeCurrent() { if (this.current) this.remove(this.current.url); },
        async remove(url) {
            if (this.saving) return;
            this.saving = true; this.error = '';
            try {
                const data = await this.response(url, 'DELETE');
                this.changed(data); this.open = false;
                window.showToast(`Removed ${data.title} from ${data.listName}`, 'info', data.undoToken ? {
                    label: 'Undo', callback: () => this.restore(data),
                } : null);
            } catch (error) {
                if (this.open) this.error = error.message;
                else window.showToast(error.message, 'error');
            } finally { this.saving = false; }
        },
        async restore(removed) {
            try {
                const data = await this.response(removed.url, 'POST', { undo_token: removed.undoToken });
                this.changed(data);
                window.showToast(`Restored ${data.title} to ${data.listName}`, 'success');
            } catch (error) { window.showToast(error.message, 'error'); }
        },
        changed(data) {
            const key = `${data.root}:${data.id}`;
            document.querySelectorAll('[data-watch-key]').forEach(button => {
                if (button.dataset.watchKey !== key) return;
                button.dataset.watched = data.watched ? '1' : '0';
                if (button.dataset.watchRemove) return;
                const icon = button.querySelector('.fa-bookmark');
                icon?.classList.toggle('fas', data.watched); icon?.classList.toggle('far', !data.watched);
                if (button.hasAttribute('aria-pressed')) button.setAttribute('aria-pressed', data.watched ? 'true' : 'false');
                // Each button renders its own wording for both states (data-watch-on-* / data-watch-off-*); this only swaps between them.
                const state = data.watched ? 'On' : 'Off', label = button.querySelector('[data-watch-label]'), text = button.dataset[`watch${state}Label`];
                if (label && text !== undefined) label.textContent = text;
                [['title', 'Title'], ['aria-label', 'Aria']].forEach(([attribute, name]) => {
                    const value = button.dataset[`watch${state}${name}`];
                    if (value !== undefined) button.setAttribute(attribute, value);
                });
            });
            document.querySelectorAll('[data-watchlist-count]').forEach(count => { count.textContent = data.counts.movies + data.counts.tv; count.hidden = Number(count.textContent) === 0; });
            document.querySelectorAll('[data-watch-count]').forEach(count => { count.textContent = data.counts[count.dataset.watchCount]; });
            document.querySelectorAll('[data-watch-summary]').forEach(summary => {
                if (summary.dataset.watchSummary !== key) return;
                summary.hidden = !data.watched;
                const labels = summary.querySelector('[data-watch-categories]');
                labels?.replaceChildren(...data.categories.filter(category => data.selected.includes(category.id)).map(category => {
                    const chip = document.createElement('span'); chip.className = 'title-watched-category'; chip.textContent = category.label; return chip;
                }));
            });
            window.dispatchEvent(new CustomEvent('watchlist-changed', { detail: data }));
        },
    };
}

export function watchlistPage() {
    return {
        error: '',
        init() { this.pageRoot = this.$el; this._refresh = event => this.findTitles(null, event.detail); window.addEventListener('watchlist-changed', this._refresh); },
        destroy() { this.request?.abort(); window.removeEventListener('watchlist-changed', this._refresh); },
        async findTitles(event = null, change = null) {
            this.request?.abort(); const request = new AbortController(); this.request = request;
            const url = new URL(window.location.href);
            url.searchParams.set('q', this.pageRoot.querySelector('input[name="q"]').value);
            url.searchParams.set('tab', this.pageRoot.dataset.watchlistRoot); url.searchParams.delete('page'); url.searchParams.set('_fragment', 'lists');
            this.error = '';
            try {
                const response = await fetch(url, { signal: request.signal, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!response.ok || response.redirected) throw new Error('Could not load what you follow. Reload this page and try again.');
                const html = await response.text(); if (request.signal.aborted) return;
                if (!html.includes('data-watchlist-fragment') || /<!doctype|<html[ >]/i.test(html)) throw new Error('Could not load what you follow. Reload this page and try again.');
                const active = document.activeElement, restoreFocus = this.$refs.lists.contains(active) || (change && active === document.body);
                const key = active?.closest?.('[data-watch-key]')?.dataset.watchKey || (change ? `${change.root}:${change.id}` : null);
                this.$refs.lists.innerHTML = html;
                if (restoreFocus) {
                    const replacement = [...this.$refs.lists.querySelectorAll('[data-watch-picker]')].find(button => button.dataset.watchKey === key);
                    (replacement || this.pageRoot.querySelector('input[name="q"]')).focus();
                }
                url.searchParams.delete('_fragment'); window.history.replaceState(null, '', url);
            } catch (error) { if (error.name !== 'AbortError') this.error = error.message; }
        },
    };
}
