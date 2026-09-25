import assert from 'node:assert/strict';
import test from 'node:test';
import { checkboxMenu } from '../../resources/js/alpine/components/checkbox-menu-component.js';
import { hasFilters, tvShows } from '../../resources/js/alpine/components/tv-shows-component.js';
import { resultItems, tvSearch } from '../../resources/js/alpine/components/tv-search-component.js';

function attributes(initial = {}) {
    const values = { ...initial };
    return {
        values,
        getAttribute: name => values[name] ?? null,
        setAttribute(name, value) { values[name] = String(value); },
        removeAttribute(name) { delete values[name]; },
    };
}

function browser(href) {
    const requests = [], history = [], toasts = [];
    globalThis.window = {
        location: { href, assign: value => history.push(['assign', value]) },
        history: { replaceState: (state, title, url) => { history.push(['replace', url]); globalThis.window.location.href = url; } },
        showToast: (message, type) => toasts.push({ message, type }),
    };
    globalThis.document = { querySelector: () => ({ content: 'csrf-token' }) };
    globalThis.fetch = async (url, options = {}) => {
        requests.push({ url: String(url), ...options });
        return { ok: true, redirected: false, json: async () => ({ success: true }), text: async () => '<div class="tv-tiles">new wall</div>' };
    };
    return { requests, history, toasts };
}

function wall() {
    const classes = new Set(['tv-clear-all', 'is-hidden']);
    const clearAll = { ...attributes({ 'aria-hidden': 'true', tabindex: '-1' }), classList: { toggle: (name, on) => (on ? classes.add(name) : classes.delete(name)) } };
    const component = tvShows();
    component.$el = { dataset: { preferenceUrl: '/profile/update-view' }, querySelector: selector => (selector === '[data-clear-all]' ? clearAll : null) };
    component.$refs = { list: { innerHTML: '' } };
    component.init();
    return { component, clearAll, classes };
}

test('a counted menu reads "Genre: Drama", then "Genre: 2 chosen" with every name in the title', () => {
    const items = ['1', '2', '3'].map((value, index) => ({ ...attributes({ 'aria-checked': 'false' }), dataset: { value, text: ['Comedy', 'Drama', 'Sci-Fi'][index] } }));
    const button = { ...attributes({ title: '' }), focus() {} };
    const component = checkboxMenu();
    component.$el = {
        dataset: { name: 'genre', label: 'Genre', summary: 'count' },
        querySelectorAll: () => items,
        querySelector: () => attributes(),
        classList: { toggle() {} },
        dispatchEvent() {},
    };
    component.$refs = { summary: { textContent: '' }, button };
    component.init();
    component.pick({ currentTarget: items[1] });
    assert.equal(component.$refs.summary.textContent, 'Genre: Drama');
    assert.equal(button.getAttribute('title'), 'Genre: Drama');
    component.pick({ currentTarget: items[0] });
    assert.equal(component.$refs.summary.textContent, 'Genre: 2 chosen');
    assert.equal(button.getAttribute('title'), 'Genre: Comedy, Drama');
    component.clear();
    assert.equal(component.$refs.summary.textContent, 'Genre: any');
    assert.equal(button.getAttribute('title'), '');
});

test('a wall filter change replaces the URL on page 1, shows "Clear all" in place and reloads only the list', async () => {
    const { history, requests } = browser('https://nntmux.test/tv/shows?language%5B%5D=en&page=3');
    const { component, clearAll, classes } = wall();
    await component.applyFilter({ detail: { name: 'genre', values: ['2', '1'] } });
    const url = new URL(history[0][1]);
    assert.deepEqual(url.searchParams.getAll('genre[]'), ['2', '1']);
    assert.deepEqual(url.searchParams.getAll('language[]'), ['en']);
    assert.equal(url.searchParams.get('page'), null);
    assert.equal(new URL(requests[0].url).searchParams.get('_fragment'), 'list');
    assert.equal(component.$refs.list.innerHTML, '<div class="tv-tiles">new wall</div>');
    assert.ok(!classes.has('is-hidden'));
    assert.equal(clearAll.getAttribute('aria-hidden'), 'false');
    assert.equal(clearAll.getAttribute('tabindex'), null);

    await component.applyFilter({ detail: { name: 'language', values: [] } });
    await component.applyFilter({ detail: { name: 'genre', values: [] } });
    assert.ok(classes.has('is-hidden'));
    assert.equal(clearAll.getAttribute('aria-hidden'), 'true');
    assert.equal(clearAll.getAttribute('tabindex'), '-1');
});

test('the person alone counts as a filter; the sort and page do not', () => {
    assert.equal(hasFilters(new URL('https://nntmux.test/tv/shows?person=7')), true);
    assert.equal(hasFilters(new URL('https://nntmux.test/tv/shows?status%5B0%5D=ended')), true);
    assert.equal(hasFilters(new URL('https://nntmux.test/tv/shows?page=2&_fragment=list')), false);
});

test('changing the wall sort saves shows_sort and returns to page 1', async () => {
    const { history, requests } = browser('https://nntmux.test/tv/shows?genre%5B%5D=2&page=3');
    const { component } = wall();
    await component.changeSort({ target: { value: 'az' } });
    assert.deepEqual(JSON.parse(requests[0].body), { root: 'tv', shows_sort: 'az' });
    assert.equal(history[0][1], 'https://nntmux.test/tv/shows?genre%5B%5D=2');
});

const urls = { show: '/tv/show', shows: '/tv/shows' };

test('results come in two groups, shows then people, with the prototype lines', () => {
    const items = resultItems({
        shows: [{ id: 4, title: 'Harbor Lights', year: 2011, genres: ['Comedy', 'Drama'], poster: '/covers/tvshows/4.webp' }, { id: 5, title: 'Thin', year: null, genres: [], poster: null }],
        people: [{ id: 7, name: 'Ada Quill', shows: ['A', 'B', 'C', 'D'] }, { id: 8, name: 'Bo', shows: ['A'] }],
    }, urls);
    assert.deepEqual(items.map(item => [item.kind, item.href, item.title, item.detail]), [
        ['show', '/tv/show/4', 'Harbor Lights', '2011 · Comedy, Drama'],
        ['show', '/tv/show/5', 'Thin', ''],
        ['person', '/tv/shows?person=7', 'Ada Quill', '4 shows: A, B, C'],
        ['person', '/tv/shows?person=8', 'Bo', '1 show: A'],
    ]);
    assert.equal(items[0].poster, '/covers/tvshows/4.webp');
});

function search(value = '') {
    const field = { value, blurred: false, ...attributes(), blur() { this.blurred = true; } };
    const component = tvSearch();
    component.$el = { dataset: { searchUrl: '/tv/search', showUrl: '/tv/show', showsUrl: '/tv/shows' } };
    component.$refs = { field, results: {} };
    component.render = () => {};
    component.mark = () => {};
    component.init();
    return { component, field };
}

test('the search asks the TV endpoint, arrows move the highlight within bounds and Enter opens it', async () => {
    const { history, requests } = browser('https://nntmux.test/tv/shows');
    globalThis.fetch = async (url, options = {}) => {
        requests.push({ url: String(url), ...options });
        return { ok: true, json: async () => ({ shows: [{ id: 4, title: 'Harbor', year: 2011, genres: [], poster: null }], people: [{ id: 7, name: 'Ada', shows: ['Harbor'] }] }) };
    };
    const { component, field } = search('harb');
    await component.search();
    assert.equal(new URL(requests[0].url).pathname, '/tv/search');
    assert.equal(new URL(requests[0].url).searchParams.get('q'), 'harb');
    assert.equal(component.open, true);
    const key = name => { const event = { key: name, prevented: false, preventDefault() { this.prevented = true; } }; component.handleKey(event); return event; };
    assert.ok(key('ArrowDown').prevented);
    key('ArrowDown');
    assert.equal(component.highlighted, 1);
    key('ArrowUp');
    key('ArrowUp');
    assert.equal(component.highlighted, 0);
    key('ArrowDown');
    key('Enter');
    assert.deepEqual(history.at(-1), ['assign', '/tv/shows?person=7']);
    assert.equal(field.value, '');
    assert.equal(component.open, false);
});

test('an empty field closes the results and Escape closes them and leaves the field', async () => {
    browser('https://nntmux.test/tv');
    const { component, field } = search('  ');
    component.open = true;
    await component.search();
    assert.equal(component.open, false);
    component.open = true;
    component.handleKey({ key: 'Escape', preventDefault() {} });
    assert.equal(component.open, false);
    assert.equal(field.blurred, true);
});
