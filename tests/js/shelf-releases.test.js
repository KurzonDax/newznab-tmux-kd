import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test, { mock } from 'node:test';
import { tvReleases } from '../../resources/js/alpine/components/tv-releases-component.js';

// The Book and PC releases lists (issue #932): the checks of docs/proposals/books-console-pc-redesign/prototype/check.mjs
// that live in the browser — the name search, the sort remembered under the list's root and the table's cell colours.

function screen(href, preferenceRoot) {
    const requests = [], history = [], posts = [];
    globalThis.document = { querySelector: () => null };
    globalThis.window = {
        location: { href, assign(url) { this.href = url; } },
        history: { replaceState: (state, title, url) => { history.push(url); window.location.href = url; } },
        showToast() {},
    };
    globalThis.fetch = async (url, options = {}) => {
        if (options.method === 'POST') {
            posts.push(JSON.parse(options.body));
            return { ok: true, json: async () => ({}) };
        }
        requests.push(String(url));
        return { ok: true, redirected: false, text: async () => '<nav>new list</nav>' };
    };
    const component = tvReleases();
    component.$el = { dataset: { preferenceUrl: '/profile/update-view', preferenceRoot, filtersClock: '5000' }, querySelectorAll: () => [], querySelector: () => null };
    const classes = new Set(['tv-name-search-clear', 'is-hidden']);
    const clear = {
        classList: { toggle: (name, on) => (on ? classes.add(name) : classes.delete(name)), contains: name => classes.has(name) },
        get hidden() { return classes.has('is-hidden'); },
        setAttribute(name, value) { this[name] = value; },
        removeAttribute(name) { delete this[name]; },
    };
    const field = { value: '', focused: false, focus() { this.focused = true; } };
    component.$refs = { list: { innerHTML: '' }, nameSearch: field, nameClear: clear };
    component.init();
    return { component, requests, history, posts, field, clear };
}

test('the Book list name search waits 180 ms, then writes q on page 1 with the Category kept and reloads the list', async () => {
    mock.timers.enable({ apis: ['setTimeout'] });
    try {
        const { component, requests, history, field } = screen('https://nntmux.test/books?category%5B%5D=7030&page=3', 'books');
        field.value = 'Comic';
        component.searchNames({ target: field });
        mock.timers.tick(179);
        assert.deepEqual(requests, []);
        mock.timers.tick(1);
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(requests.length, 1);
        const url = new URL(history[0]);
        assert.deepEqual([url.pathname, url.searchParams.getAll('category[]'), url.searchParams.get('q'), url.searchParams.has('page')], ['/books', ['7030'], 'Comic', false]);
        assert.equal(new URL(requests[0]).searchParams.get('_fragment'), 'list');
        assert.equal(field.focused, false, 'the field is never replaced, so focus and caret stay where they are');
    } finally {
        mock.timers.reset();
    }
});

test('Escape empties the PC list name search and reloads without it, the Completion kept', async () => {
    const { component, requests, history, field } = screen('https://nntmux.test/pc?completion=95&q=Suite', 'games');
    field.value = 'Suite';
    component.showNameClear(true);
    await component.clearNameSearch();
    assert.equal(field.value, '');
    const url = new URL(history[0]);
    assert.deepEqual([url.pathname, url.searchParams.get('completion'), url.searchParams.has('q')], ['/pc', '95', false]);
    assert.equal(new URL(requests[0]).searchParams.has('q'), false);
});

test('the sort is remembered under books for the Book list and games for the PC list', async () => {
    for (const [href, root] of [['https://nntmux.test/books?page=2', 'books'], ['https://nntmux.test/pc?page=2', 'games']]) {
        const { component, posts } = screen(href, root);
        await component.changeSort({ target: { value: 'newest' } });
        assert.deepEqual(posts, [{ root, sort: 'newest' }]);
        assert.equal(new URL(window.location.href).searchParams.has('page'), false, 'a new sort opens page 1');
    }
});

test('the Book and PC table: Category 128 px, dim on one line, its heading lined up; Size ink, the date dim; two Movies release-bar cells', () => {
    const css = readFileSync(new URL('../../resources/css/tv.css', import.meta.url), 'utf8');
    assert.match(css, /\.tv-col-category \{ width: 128px; \}/);
    assert.match(css, /\.tv-feed\.is-shelf tbody td\.tv-category \{ overflow: hidden; color: var\(--tv-dim\); text-overflow: ellipsis; white-space: nowrap; \}/);
    assert.match(css, /\.tv-feed\.is-shelf th\.tv-category \{ padding-left: 12px; \}/, 'the heading starts where the cell text starts');
    assert.match(css, /\.tv-feed\.is-shelf tbody td\.tv-size \{ color: var\(--tv-ink\); \}/, 'Size in ink, over td.tv-num');
    assert.match(css, /\.tv-feed\.is-shelf tbody td\.tv-date \{ color: var\(--tv-dim\); \}/, 'the date dim');
    assert.match(css, /\.tv-bar-list\.is-shelf \.filter-bar\.is-release \{ flex: 0 0 calc\(\(\(100% - 12px\) \/ 2 - 8px\) \* 2 \/ 5 \+ 8px\); \}/, 'two cells as wide as a Movies release cell');
    // the class rules outrank the position rules (.tv-feed tbody td.tv-num, td:nth-child(n)): three classes over two
    assert.match(css, /\.tv-feed tbody td\.tv-num, \.tv-feed tbody td:nth-child\(5\) \{ color: var\(--tv-dim\); \}/);
});
