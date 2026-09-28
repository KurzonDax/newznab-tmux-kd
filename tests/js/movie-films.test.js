import assert from 'node:assert/strict';
import test from 'node:test';
import { hasFilters, tvShows } from '../../resources/js/alpine/components/tv-shows-component.js';
import { resultItems } from '../../resources/js/alpine/components/tv-search-component.js';

/** The Films wall (movies/films/index.blade.php) runs the wall component with the Movies dataset. */

function attributes(initial = {}) {
    const values = { ...initial };
    return {
        values,
        getAttribute: name => values[name] ?? null,
        setAttribute(name, value) { values[name] = String(value); },
        removeAttribute(name) { delete values[name]; },
    };
}

function browser(href, respond = { ok: true }) {
    const requests = [], history = [], toasts = [];
    globalThis.window = {
        location: { href, assign: value => history.push(['assign', value]) },
        history: { replaceState: (state, title, url) => { history.push(['replace', url]); globalThis.window.location.href = url; } },
        showToast: (message, type) => toasts.push({ message, type }),
    };
    globalThis.document = { querySelector: () => ({ content: 'csrf-token' }) };
    globalThis.fetch = async (url, options = {}) => {
        requests.push({ url: String(url), ...options });
        return { redirected: false, json: async () => ({ success: true }), text: async () => '<div class="tv-tiles">new films</div>', ...respond };
    };
    return { requests, history, toasts };
}

function filmsWall() {
    const classes = new Set(['tv-clear-all', 'is-hidden']);
    const clearAll = { ...attributes({ 'aria-hidden': 'true', tabindex: '-1' }), classList: { toggle: (name, on) => (on ? classes.add(name) : classes.delete(name)) } };
    const removePerson = attributes({ href: 'https://nntmux.test/movies/films' });
    const component = tvShows();
    component.$el = {
        dataset: { preferenceUrl: '/profile/update-view', preferenceRoot: 'movies', sortKey: 'films_sort', noun: 'films' },
        querySelector: selector => ({ '[data-clear-all]': clearAll, '[data-remove-person]': removePerson })[selector] ?? null,
    };
    component.$refs = { list: { innerHTML: '' } };
    component.init();
    return { component, clearAll, classes, removePerson };
}

test('a Year menu change sets its params on page 1, shows "Clear all" and reloads only the list', async () => {
    const { history, requests } = browser('https://nntmux.test/movies/films?decade%5B%5D=1990&page=4');
    const { component, clearAll, classes } = filmsWall();
    await component.applyFilter({ detail: { params: { decade: [], year_from: '1980', year_to: '1989' } } });
    const url = new URL(history[0][1]);
    assert.deepEqual(url.searchParams.getAll('decade[]'), []);
    assert.equal(url.searchParams.get('year_from'), '1980');
    assert.equal(url.searchParams.get('year_to'), '1989');
    assert.equal(url.searchParams.get('page'), null);
    assert.equal(url.pathname, '/movies/films');
    assert.equal(new URL(requests[0].url).searchParams.get('_fragment'), 'list');
    assert.equal(component.$refs.list.innerHTML, '<div class="tv-tiles">new films</div>');
    assert.ok(!classes.has('is-hidden'));
    assert.equal(clearAll.getAttribute('aria-hidden'), 'false');
    assert.equal(clearAll.getAttribute('tabindex'), null);
});

test('a film menu change keeps the other filters and the person, and the chip keeps the filters', async () => {
    const { history } = browser('https://nntmux.test/movies/films?person=7&score%5B%5D=9');
    const { component, removePerson } = filmsWall();
    await component.applyFilter({ detail: { name: 'genre', values: ['3', '1'] } });
    const url = new URL(history[0][1]);
    assert.deepEqual(url.searchParams.getAll('genre[]'), ['3', '1']);
    assert.deepEqual(url.searchParams.getAll('score[]'), ['9']);
    assert.equal(url.searchParams.get('person'), '7');
    const without = new URL(removePerson.getAttribute('href'));
    assert.equal(without.searchParams.get('person'), null);
    assert.deepEqual(without.searchParams.getAll('genre[]'), ['3', '1']);
});

test('Score, MPAA Rating, Language and the Year range count as filters; the sort and page do not', () => {
    for (const query of ['score%5B%5D=few', 'rating%5B%5D=R', 'language%5B%5D=fr', 'year_from=1994', 'decade%5B%5D=2010', 'person=7']) {
        assert.equal(hasFilters(new URL('https://nntmux.test/movies/films?' + query)), true, query);
    }
    assert.equal(hasFilters(new URL('https://nntmux.test/movies/films?page=2&_fragment=list')), false);
});

test('clearing the last film filter hides "Clear all" again in its place', async () => {
    browser('https://nntmux.test/movies/films?rating%5B%5D=R');
    const { component, clearAll, classes } = filmsWall();
    await component.applyFilter({ detail: { name: 'rating', values: [] } });
    assert.ok(classes.has('is-hidden'));
    assert.equal(clearAll.getAttribute('aria-hidden'), 'true');
    assert.equal(clearAll.getAttribute('tabindex'), '-1');
});

test('changing the wall sort saves films_sort under movies and returns to page 1', async () => {
    const { history, requests } = browser('https://nntmux.test/movies/films?genre%5B%5D=2&page=3');
    const { component } = filmsWall();
    await component.changeSort({ target: { value: 'year' } });
    assert.deepEqual(JSON.parse(requests[0].body), { root: 'movies', films_sort: 'year' });
    assert.equal(history[0][1], 'https://nntmux.test/movies/films?genre%5B%5D=2');
});

test('a failed list load names films', async () => {
    const { toasts } = browser('https://nntmux.test/movies/films', { ok: false });
    const { component } = filmsWall();
    await component.applyFilter({ detail: { name: 'genre', values: ['1'] } });
    assert.deepEqual(toasts, [{ message: 'Could not load the films. Reload the page and try again.', type: 'error' }]);
});

test('a person in "Search films or actors" opens the wall filtered to them', () => {
    const items = resultItems({ films: [], people: [{ id: 7, name: 'Ada Quill', count: 4, films: ['A', 'B', 'C'] }] },
        { show: '/movies/film', shows: '/movies/films' }, 'film');
    assert.deepEqual(items.map(item => [item.kind, item.href, item.detail]), [['person', '/movies/films?person=7', '4 films: A, B, C']]);
});
