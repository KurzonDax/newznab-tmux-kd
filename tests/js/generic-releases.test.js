import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test, { mock } from 'node:test';
import { tvReleases } from '../../resources/js/alpine/components/tv-releases-component.js';
import { posterIdentityBlacklist } from '../../resources/js/alpine/components/poster-identity-blacklist-component.js';
import { POLL_MS, posterSweepStatus } from '../../resources/js/alpine/components/poster-sweep-status-component.js';

// The generic release lists (issue #1032; docs/proposals/generic-release-lists/SPEC.md 5): the checks of
// prototype/check.mjs that live in the browser — the Category column's widths, the sort remembered under `all`
// (the All, group and poster lists) and `other`, the blacklist confirmation's open and close, and the sweep status
// that polls its own run and asks for the list once the run ends.

function screen(href, preferenceRoot) {
    const requests = [], posts = [];
    globalThis.document = { querySelector: () => null };
    globalThis.window = {
        location: { href, assign(url) { this.href = url; } },
        history: { replaceState: (state, title, url) => { window.location.href = url; } },
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
    component.$refs = { list: { innerHTML: '' } };
    component.init();
    return { component, requests, posts };
}

test('the Category column is 160 px ("Console > Xbox 360 DLC" at 13.5 px plus the cell padding) and 92 px on the Other list', () => {
    const css = readFileSync(new URL('../../resources/css/tv.css', import.meta.url), 'utf8');
    assert.match(css, /\.tv-col-category-path \{ width: 160px; \}/);
    assert.match(css, /\.tv-feed\.is-other col\.tv-col-category-path \{ width: 92px; \}/);
    assert.match(css, /\.tv-col-category \{ width: 128px; \}/, 'the shelf lists keep their own column');
    assert.match(css, /\.tv-dialog\.is-blacklist \{ width: min\(680px, 100%\); \}/, 'the confirmation dialog is 680 px');
    assert.match(css, /\.tv-blacklist-button \{ background: var\(--tv-danger-bg\); color: var\(--tv-danger-fg\); \}/, 'the Blacklist button is a tinted button');
    assert.match(css, /--tv-danger-bg: oklch\(0\.92 0\.045 12\); --tv-danger-fg: oklch\(0\.45 0\.16 12\)/, 'rose 12 in the light theme');
    assert.match(css, /\.dark \.tv-screen \{ --tv-danger-bg: oklch\(0\.31 0\.07 12\); --tv-danger-fg: oklch\(0\.86 0\.11 12\)/, 'rose 12 in the dark theme');
    const app = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    assert.match(app, /--chip-reported-bg: oklch\(0\.93 0\.04 170\)/, 'Reported is teal-green 170');
    assert.match(app, /--chip-response-bg: oklch\(0\.93 0\.04 245\)/, 'Response is blue 245');
});

test('the sort is remembered under all for the All, group and poster lists and under other for Other; a new sort opens page 1 and keeps the identity', async () => {
    for (const [href, root, keep] of [
        ['https://nntmux.test/browse/all?page=2', 'all', {}],
        ['https://nntmux.test/browse/all?group=alt.binaries.demo&page=3', 'all', { group: 'alt.binaries.demo' }],
        ['https://nntmux.test/poster?name=Bob+%3Cbob%40home.mex%3E&page=2', 'all', { name: 'Bob <bob@home.mex>' }],
        ['https://nntmux.test/browse/other?category%5B%5D=20&page=2', 'other', { 'category[]': '20' }],
    ]) {
        const { component, posts } = screen(href, root);
        await component.changeSort({ target: { value: 'title' } });
        assert.deepEqual(posts, [{ root, sort: 'title' }]);
        const url = new URL(window.location.href);
        assert.equal(url.searchParams.has('page'), false, 'a new sort opens page 1: ' + href);
        for (const [key, value] of Object.entries(keep)) assert.equal(url.searchParams.get(key), value, key + ' kept: ' + href);
    }
});

test('a finished sweep asks the screen to reload the list at the current address', async () => {
    const { component, requests } = screen('https://nntmux.test/poster?name=x&completion=95', 'all');
    await component.refreshList();
    assert.equal(requests.length, 1);
    const url = new URL(requests[0]);
    assert.deepEqual([url.pathname, url.searchParams.get('name'), url.searchParams.get('completion'), url.searchParams.get('_fragment')], ['/poster', 'x', '95', 'list']);
});

function dialogEnvironment() {
    const listeners = { keydown: [] };
    const document = {
        activeElement: null,
        body: { style: { overflow: 'auto' } },
        addEventListener: (name, handler) => listeners[name].push(handler),
        removeEventListener: (name, handler) => { listeners[name] = listeners[name].filter(fn => fn !== handler); },
    };
    globalThis.document = document;
    const control = () => ({ isConnected: true, getClientRects: () => [1], focus() { document.activeElement = this; } });
    const closeButton = control(), cancel = control();
    const modal = { ownerDocument: document, hasAttribute: () => false, contains: node => node === closeButton || node === cancel, querySelector: () => null, querySelectorAll: () => [closeButton, cancel] };
    const dialog = posterIdentityBlacklist();
    let watcher = () => {};
    let open = dialog.open;
    Object.defineProperty(dialog, 'open', { get: () => open, set(value) { open = value; watcher(value); }, configurable: true });
    dialog.$el = { querySelector: selector => (selector === '[data-modal-dialog]' ? modal : null) };
    dialog.$watch = (name, fn) => { watcher = fn; };
    dialog.$nextTick = fn => fn();
    dialog.init();
    return { dialog, document, closeButton, key: event => listeners.keydown.forEach(fn => fn(event)) };
}

test('the Blacklist button opens the confirmation; Escape and Cancel close it, forget the remove-releases tick and return focus to the button', () => {
    const { dialog, document, closeButton, key } = dialogEnvironment();
    const button = { isConnected: true, focus() { document.activeElement = this; } };
    document.activeElement = button;
    assert.equal(dialog.open, false);
    dialog.openConfirmation();
    assert.equal(dialog.open, true);
    assert.equal(document.activeElement, closeButton, 'focus moves into the dialog');
    assert.equal(document.body.style.overflow, 'hidden');
    dialog.deleteReleases = true;
    key({ key: 'Escape', preventDefault() {} });
    assert.deepEqual([dialog.open, dialog.deleteReleases], [false, false]);
    assert.equal(document.activeElement, button, 'focus returns to the Blacklist button');
    assert.equal(document.body.style.overflow, 'auto');
    dialog.openConfirmation();
    dialog.deleteReleases = true;
    dialog.closeConfirmation();
    assert.deepEqual([dialog.open, dialog.deleteReleases], [false, false]);
    assert.equal(dialog.partWhenOpen('dialog'), null, 'a closed dialog has no measured part');
    dialog.destroy();
});

test('the sweep status polls its own run every 3 s while running and reports a finished, unavailable or failed poll once', async () => {
    mock.timers.enable({ apis: ['setInterval', 'setTimeout'] });
    try {
        const answers = [{ running: true, available: true }, { running: true, available: true }, { running: false, available: true }];
        const fetched = [], dispatched = [];
        globalThis.fetch = async (url, options = {}) => {
            fetched.push(String(url));
            if (options.signal?.aborted) throw Object.assign(new Error('aborted'), { name: 'AbortError' });
            return { ok: true, json: async () => answers.shift() };
        };
        const component = posterSweepStatus();
        component.$el = { dataset: { running: '1', statusUrl: '/admin/binaryblacklist-sweep/status?run=20261009-120000-000000-abcdefgh' } };
        component.$dispatch = name => dispatched.push(name);
        component.init();
        assert.equal(POLL_MS, 3000);
        for (const expected of [1, 2, 3]) {
            mock.timers.tick(3000);
            await new Promise(resolve => setImmediate(resolve));
            assert.equal(fetched.length, expected);
            assert.match(fetched[expected - 1], /run=20261009-120000-000000-abcdefgh$/, 'only this run is asked about');
        }
        assert.deepEqual(dispatched, ['blacklist-sweep-finished']);
        mock.timers.tick(9000);
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(fetched.length, 3, 'polling stops once the run has ended');

        const idle = posterSweepStatus();
        idle.$el = { dataset: { running: '0', statusUrl: '/x' } };
        idle.$dispatch = name => dispatched.push(name);
        idle.init();
        mock.timers.tick(9000);
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(fetched.length, 3, 'a finished status never polls');

        globalThis.fetch = async () => { throw new Error('network down'); };
        const failing = posterSweepStatus();
        failing.$el = { dataset: { running: '1', statusUrl: '/x' } };
        failing.$dispatch = name => dispatched.push(name);
        failing.init();
        mock.timers.tick(3000);
        await new Promise(resolve => setImmediate(resolve));
        assert.deepEqual(dispatched, ['blacklist-sweep-finished', 'blacklist-sweep-finished'], 'a failed request hands the outcome to the server-rendered list');
        failing.destroy();
    } finally {
        mock.timers.reset();
    }
});

test('choosing a Completion value, Any included, retires a minc link from the address and the reload (correction 3)', async () => {
    for (const [values, expectedCompletion] of [[['95'], '95'], [[], null]]) {
        const { component, requests } = screen('https://nntmux.test/browse/all?minc=80&group=alt.binaries.demo&page=2', 'all');
        await component.applyFilter({ detail: { name: 'completion', values, single: true } });
        const address = new URL(window.location.href);
        assert.deepEqual([address.searchParams.has('minc'), address.searchParams.get('completion'), address.searchParams.get('group'), address.searchParams.has('page')],
            [false, expectedCompletion, 'alt.binaries.demo', false], JSON.stringify(values));
        assert.equal(new URL(requests[0]).searchParams.has('minc'), false, 'the reload never re-applies minc');
    }
    const { component } = screen('https://nntmux.test/browse/all?minc=80', 'all');
    await component.applyFilter({ detail: { name: 'category', values: ['5000'], single: false } });
    assert.equal(new URL(window.location.href).searchParams.get('minc'), '80', 'another menu leaves the legacy threshold in place');
});
