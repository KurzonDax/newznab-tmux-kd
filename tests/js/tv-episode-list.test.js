import assert from 'node:assert/strict';
import test from 'node:test';
import { listUrl, nextSort, selectionKey, sortRows, SWITCH_KEY, tvEpisodeList } from '../../resources/js/alpine/components/tv-episode-list-component.js';

function attributes(initial = {}) {
    const values = { ...initial };
    return {
        values,
        getAttribute: name => values[name] ?? null,
        setAttribute(name, value) { values[name] = String(value); },
        removeAttribute(name) { delete values[name]; },
        hasAttribute: name => name in values,
    };
}

function storage(initial = {}) {
    const values = { ...initial };
    return { values, getItem: key => values[key] ?? null, setItem(key, value) { values[key] = String(value); }, removeItem(key) { delete values[key]; } };
}

function browser({ href = 'https://nntmux.test/tv/show/7/1', session = storage(), fail = false } = {}) {
    const toasts = [], requests = [], history = [], scrolled = [], forms = [];
    globalThis.window = {
        location: { href },
        history: { replaceState: (state, title, url) => history.push(url) },
        sessionStorage: session,
        scrollY: 640,
        scrollTo: (x, y) => scrolled.push(y),
        showToast: (message, type) => toasts.push({ message, type }),
    };
    globalThis.document = {
        querySelector: () => ({ content: 'csrf-token' }),
        createElement: () => { const form = { fields: {}, append(input) { form.fields[input.name] = input.value; }, submit() { forms.push(form.fields); }, remove() {} }; return form; },
        body: { append() {} },
    };
    globalThis.fetch = async (url, options = {}) => {
        requests.push({ url: String(url), ...options });
        return { ok: !fail, redirected: false, json: async () => ({ success: true, cartCount: 3 }), text: async () => '<table>releases</table>' };
    };
    return { toasts, requests, history, scrolled, forms, session };
}

function box(guid) {
    return { value: guid, checked: false, matches: selector => selector === '[data-select]' };
}

function releaseRow(guid, data) {
    return { guid, dataset: data };
}

function table(rows) {
    const cells = ['resolution', 'size', 'posted', 'grabs', null].map(key => ({ ...attributes(key === 'size' ? { 'aria-sort': 'descending' } : {}), querySelector: () => (key ? { dataset: { sort: key } } : null) }));
    const body = { rows, append(...ordered) { body.rows = ordered; } };
    return { tBodies: [body], cells, querySelectorAll: () => cells };
}

function episode(number, open = false) {
    const button = { dataset: { ep: String(number) }, ...attributes({ 'aria-expanded': open ? 'true' : 'false' }) };
    const releases = { innerHTML: open ? '<table>old</table>' : '', querySelectorAll: () => [] };
    const row = { dataset: { episode: String(number) }, ...attributes(open ? { 'data-open': '' } : {}), querySelector: () => releases };
    button.closest = selector => (selector === '.tv-episode' ? row : null);
    return { button, row, releases };
}

function page({ show = '7', boxes = [], tables = [], episodes = [], tabs = [], currentTab = null } = {}) {
    const list = { innerHTML: '', querySelectorAll: selector => (selector === '.tv-release-table' ? tables : []) };
    const root = {
        dataset: { show, nzbLinkBase: 'https://nntmux.test/api/v1/api', apiToken: 'secret-key' },
        querySelectorAll(selector) {
            if (selector === '[data-select]') return boxes;
            if (selector === '.tv-episode[data-open]') return episodes.filter(item => item.row.hasAttribute('data-open')).map(item => item.row);
            if (selector === '.tv-season-tabs a') return tabs;
            if (selector === '[data-cart]') return [];
            return [];
        },
        querySelector: selector => (selector === '.tv-season-tabs [aria-current]' ? currentTab : null),
    };
    const component = tvEpisodeList();
    component.$el = root;
    component.$refs = { list };
    component.$store = { cart: { count: 0, setCount(count) { this.count = count; } } };
    component.init();
    return { component, list };
}

test('a header sorts largest or newest first, the same header again flips it', () => {
    assert.deepEqual(nextSort({ key: 'size', dir: -1 }, 'size'), { key: 'size', dir: 1 });
    assert.deepEqual(nextSort({ key: 'size', dir: 1 }, 'grabs'), { key: 'grabs', dir: -1 });
    const rows = [releaseRow('a', { size: '10' }), releaseRow('b', { size: '300' }), releaseRow('c', { size: '10' })];
    assert.deepEqual(sortRows(rows, { key: 'size', dir: -1 }).map(row => row.guid), ['b', 'a', 'c']);
    assert.deepEqual(sortRows(rows, { key: 'size', dir: 1 }).map(row => row.guid), ['a', 'c', 'b']);
});

test('sorting reorders every open table, moves aria-sort to the header and keeps the ticked boxes', () => {
    browser();
    const first = table([releaseRow('a', { size: '9', grabs: '1' }), releaseRow('b', { size: '5', grabs: '8' })]);
    const second = table([releaseRow('c', { size: '1', grabs: '4' }), releaseRow('d', { size: '2', grabs: '2' })]);
    const boxes = [box('b')];
    const { component } = page({ tables: [first, second], boxes });
    boxes[0].checked = true;
    component.handleChange({ target: boxes[0] });
    const grabs = { dataset: { sort: 'grabs' } };
    component.handleClick({ target: { closest: selector => (selector === '[data-sort]' ? grabs : null) } });
    assert.deepEqual(first.tBodies[0].rows.map(row => row.guid), ['b', 'a']);
    assert.deepEqual(second.tBodies[0].rows.map(row => row.guid), ['c', 'd']);
    assert.equal(first.cells[3].getAttribute('aria-sort'), 'descending');
    assert.equal(first.cells[1].getAttribute('aria-sort'), null);
    component.sortBy(grabs);
    assert.deepEqual(first.tBodies[0].rows.map(row => row.guid), ['a', 'b']);
    assert.equal(first.cells[3].getAttribute('aria-sort'), 'ascending');
    assert.equal(boxes[0].checked, true);
});

test('the selection is kept per show, so it carries across episodes, seasons and page loads', async () => {
    const { session, forms } = browser();
    const onFirstSeason = [box('a'), box('b')];
    const { component } = page({ boxes: onFirstSeason });
    onFirstSeason[1].checked = true;
    component.handleChange({ target: onFirstSeason[1] });
    assert.equal(component.selectedCount, 1);
    assert.deepEqual(JSON.parse(session.values[selectionKey('7')]), ['b']);

    const onSecondSeason = [box('b'), box('c')];
    const next = page({ boxes: onSecondSeason }).component;
    assert.deepEqual(onSecondSeason.map(item => item.checked), [true, false]);
    onSecondSeason[1].checked = true;
    next.handleChange({ target: onSecondSeason[1] });
    assert.equal(next.selectedCount, 2);
    assert.equal(page({ show: '8' }).component.selectedCount, 0);

    next.downloadSelected();
    assert.deepEqual(forms[0], { id: 'b,c', zip: '1', _token: 'csrf-token' });
    assert.equal(next.selectedCount, 0);
    assert.equal(session.values[selectionKey('7')], undefined);
    assert.deepEqual(onSecondSeason.map(item => item.checked), [false, false]);
});

test('an episode row opens its releases in place and closes again, the row itself staying put', async () => {
    const { requests } = browser({ href: 'https://nntmux.test/tv/show/7/1?resolution%5B%5D=4k&open=3' });
    const second = episode(2);
    const { component } = page({ episodes: [second] });
    await component.handleClick({ target: { closest: selector => (selector === '[data-ep]' ? second.button : null) } });
    const url = new URL(requests[0].url);
    assert.equal(url.pathname, '/tv/show/7/1');
    assert.equal(url.searchParams.get('_fragment'), 'episode');
    assert.equal(url.searchParams.get('episode'), '2');
    assert.deepEqual(url.searchParams.getAll('resolution[]'), ['4k']);
    assert.equal(url.searchParams.get('open'), null);
    assert.equal(second.button.getAttribute('aria-expanded'), 'true');
    assert.equal(second.row.hasAttribute('data-open'), true);
    assert.equal(second.releases.innerHTML, '<table>releases</table>');

    await component.toggleEpisode(second.button);
    assert.equal(second.button.getAttribute('aria-expanded'), 'false');
    assert.equal(second.row.hasAttribute('data-open'), false);
    assert.equal(second.releases.innerHTML, '');
    assert.equal(requests.length, 1);
});

test('an episode that cannot load closes again and says so', async () => {
    const { toasts } = browser({ fail: true });
    const third = episode(3);
    const { component } = page({ episodes: [third] });
    await component.toggleEpisode(third.button);
    assert.equal(third.row.hasAttribute('data-open'), false);
    assert.equal(third.button.getAttribute('aria-expanded'), 'false');
    assert.equal(toasts[0].type, 'error');
});

test('a filter change keeps the open episodes open, carries the filter onto every season tab and drops ?open', async () => {
    const { history, requests } = browser({ href: 'https://nntmux.test/tv/show/7/1?open=2' });
    const tabs = [2, 3].map(season => ({ href: 'https://nntmux.test/tv/show/7/' + season, ...attributes() }));
    const episodes = [episode(2, true), episode(1)];
    const { component, list } = page({ tabs, episodes });
    await component.applyFilter({ detail: { name: 'source', values: ['web', 'bluray'] } });
    assert.equal(history[0], 'https://nntmux.test/tv/show/7/1?source%5B%5D=web&source%5B%5D=bluray');
    assert.equal(tabs[1].getAttribute('href'), 'https://nntmux.test/tv/show/7/3?source%5B%5D=web&source%5B%5D=bluray');
    const url = new URL(requests[0].url);
    assert.equal(url.searchParams.get('_fragment'), 'list');
    assert.deepEqual(url.searchParams.getAll('open[]'), ['2']);
    assert.equal(list.innerHTML, '<table>releases</table>');
    assert.equal(listUrl('https://nntmux.test/tv/show/7?open=4', [5, 6]).search, '?open%5B%5D=5&open%5B%5D=6');
});

test('a season tab keeps keyboard focus and the scroll position across its page load', () => {
    const { session, scrolled } = browser();
    const { component } = page();
    component.handleClick({ target: { closest: selector => (selector === '.tv-season-tabs a' ? {} : null) } });
    assert.deepEqual(JSON.parse(session.values[SWITCH_KEY]), { show: '7', y: 640 });

    const currentTab = { focused: null, focus(options) { this.focused = options; } };
    page({ show: '8', currentTab });
    assert.equal(currentTab.focused, null);
    page({ currentTab });
    assert.deepEqual(currentTab.focused, { preventScroll: true });
    assert.deepEqual(scrolled, [640]);
    assert.equal(session.values[SWITCH_KEY], undefined);
});
