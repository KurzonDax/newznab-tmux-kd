import assert from 'node:assert/strict';
import test from 'node:test';
import { currentSort, movieFilm, nextSort, SELECTION_KEY, sortUrl } from '../../resources/js/alpine/components/movie-film-component.js';

/** The film page (movies/film/index.blade.php): its table's sorts, cells and selection (SPEC 5B.2). */

function storage(initial = {}) {
    const values = { ...initial };
    return { values, getItem: key => values[key] ?? null, setItem(key, value) { values[key] = String(value); }, removeItem(key) { delete values[key]; } };
}

function browser({ href = 'https://nntmux.test/movies/film/10', session = storage(), fail = false } = {}) {
    const toasts = [], requests = [], history = [];
    globalThis.window = {
        location: { href },
        history: { replaceState: (state, title, url) => { history.push(url); globalThis.window.location.href = url; } },
        sessionStorage: session,
        showToast: (message, type) => toasts.push({ message, type }),
    };
    globalThis.document = { querySelector: () => ({ content: 'csrf-token' }) };
    globalThis.fetch = async (url, options = {}) => {
        requests.push({ url: String(url), ...options });
        return { ok: !fail, redirected: false, json: async () => ({ success: true }), text: async () => '<table>new rows</table>' };
    };
    return { toasts, requests, history, session };
}

function box(guid) {
    return { value: guid, checked: false, matches: selector => selector === '[data-select]' };
}

function filmPage(boxes = []) {
    const focused = [];
    const heading = key => ({ focus: () => focused.push(key) });
    const list = { innerHTML: '', querySelector: selector => { const match = selector.match(/data-sort="([a-z]+)"/); return match ? heading(match[1]) : null; } };
    const component = movieFilm();
    component.$el = {
        dataset: { nzbLinkBase: 'https://nntmux.test/api/v1/api', apiToken: 'secret-key' },
        querySelectorAll: selector => (selector === '[data-select]' ? boxes : []),
    };
    component.$refs = { list };
    component.init();
    return { component, list, focused };
}

function click(target) {
    return { target: { closest: selector => (selector === '[data-sort]' && target.sort ? { dataset: { sort: target.sort } } : null) } };
}

test('the order comes from the URL: newest posted first without a sort, _asc for ascending, anything else ignored', () => {
    assert.deepEqual(currentSort('https://nntmux.test/movies/film/10'), { key: 'posted', dir: -1 });
    assert.deepEqual(currentSort('https://nntmux.test/movies/film/10?sort=size'), { key: 'size', dir: -1 });
    assert.deepEqual(currentSort('https://nntmux.test/movies/film/10?sort=resolution_asc'), { key: 'resolution', dir: 1 });
    assert.deepEqual(currentSort('https://nntmux.test/movies/film/10?sort=grabs'), { key: 'posted', dir: -1 });
});

test('a heading sorts descending on its first click and flips on the next', () => {
    assert.deepEqual(nextSort({ key: 'posted', dir: -1 }, 'size'), { key: 'size', dir: -1 });
    assert.deepEqual(nextSort({ key: 'size', dir: -1 }, 'size'), { key: 'size', dir: 1 });
    assert.deepEqual(nextSort({ key: 'size', dir: 1 }, 'size'), { key: 'size', dir: -1 });
    assert.deepEqual(nextSort({ key: 'posted', dir: -1 }, 'posted'), { key: 'posted', dir: 1 });
});

test('a sort URL returns to page 1, keeps the filters and leaves out newest posted first', () => {
    const href = 'https://nntmux.test/movies/film/10?resolution%5B%5D=4k&sort=size&page=3';
    const ascending = sortUrl(href, { key: 'size', dir: 1 });
    assert.equal(ascending.searchParams.get('sort'), 'size_asc');
    assert.equal(ascending.searchParams.get('page'), null);
    assert.deepEqual(ascending.searchParams.getAll('resolution[]'), ['4k']);
    assert.equal(sortUrl(href, { key: 'posted', dir: -1 }).searchParams.get('sort'), null);
    assert.equal(sortUrl(href, { key: 'posted', dir: 1 }).searchParams.get('sort'), 'posted_asc');
});

test('clicking a heading reloads only the table on page 1 in the next order and keeps focus on that heading', async () => {
    const { history, requests } = browser({ href: 'https://nntmux.test/movies/film/10?source%5B%5D=web&sort=size&page=2' });
    const { component, list, focused } = filmPage();

    await component.handleClick(click({ sort: 'size' }));
    const url = new URL(history[0]);
    assert.equal(url.pathname, '/movies/film/10');
    assert.equal(url.searchParams.get('sort'), 'size_asc');
    assert.equal(url.searchParams.get('page'), null);
    assert.deepEqual(url.searchParams.getAll('source[]'), ['web']);
    assert.equal(new URL(requests[0].url).searchParams.get('_fragment'), 'list');
    assert.equal(list.innerHTML, '<table>new rows</table>');
    assert.deepEqual(focused, ['size']);

    await component.handleClick(click({ sort: 'resolution' }));
    assert.equal(new URL(history[1]).searchParams.get('sort'), 'resolution');
    await component.handleClick(click({ sort: 'posted' }));
    assert.equal(new URL(history[2]).searchParams.get('sort'), null, 'Posted starts descending: the opening order carries no sort.');
});

test('a Resolution or Source change reloads the table on page 1 and keeps the sort', async () => {
    const { history, requests } = browser({ href: 'https://nntmux.test/movies/film/10?sort=size_asc&page=3' });
    const { component, list } = filmPage();

    await component.applyFilter({ detail: { name: 'resolution', values: ['4k', '1080p'] } });
    const url = new URL(history[0]);
    assert.deepEqual(url.searchParams.getAll('resolution[]'), ['4k', '1080p']);
    assert.equal(url.searchParams.get('sort'), 'size_asc');
    assert.equal(url.searchParams.get('page'), null);
    assert.equal(new URL(requests[0].url).searchParams.get('_fragment'), 'list');
    assert.equal(list.innerHTML, '<table>new rows</table>');

    await component.applyFilter({ detail: { name: 'resolution', values: [] } });
    assert.deepEqual(new URL(history[1]).searchParams.getAll('resolution[]'), []);
});

test('the selection carries across pages and into another film, ticking the boxes it holds', async () => {
    const session = storage();
    browser({ session });
    const first = [box('a'), box('b')];
    const { component } = filmPage(first);
    first[0].checked = true;
    component.handleChange({ target: first[0] });
    assert.equal(component.selectedCount, 1);
    assert.deepEqual(JSON.parse(session.values[SELECTION_KEY]), ['a']);

    // another page, or another film: one key for every film page
    browser({ href: 'https://nntmux.test/movies/film/11?page=2', session });
    const next = [box('a'), box('c')];
    const other = filmPage(next).component;
    assert.equal(next[0].checked, true);
    assert.equal(next[1].checked, false);
    assert.equal(other.selectedCount, 1);
    next[1].checked = true;
    other.handleChange({ target: next[1] });
    assert.deepEqual(other.selectedGuids(), ['a', 'c']);

    // a reloaded table ticks the selected rows it holds
    const reloaded = [box('c')];
    other.$el.querySelectorAll = selector => (selector === '[data-select]' ? reloaded : []);
    await other.applyFilter({ detail: { name: 'source', values: ['web'] } });
    assert.equal(reloaded[0].checked, true);

    other.clearSelection();
    assert.equal(other.selectedCount, 0);
    assert.equal(session.values[SELECTION_KEY], undefined);
    assert.equal(reloaded[0].checked, false);
});

test('a table that fails to load says so', async () => {
    const { toasts } = browser({ fail: true });
    const { component, list } = filmPage();
    list.innerHTML = '<table>old rows</table>';

    await component.applyFilter({ detail: { name: 'source', values: ['dvd'] } });
    assert.equal(list.innerHTML, '<table>old rows</table>');
    assert.deepEqual(toasts, [{ message: 'Could not load the releases. Reload the page and try again.', type: 'error' }]);
});
