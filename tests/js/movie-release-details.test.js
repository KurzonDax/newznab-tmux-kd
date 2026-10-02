import assert from 'node:assert/strict';
import test from 'node:test';
import { movieReleaseDetails, opensAtTable, tablePageUrl } from '../../resources/js/alpine/components/movie-release-details-component.js';
import { tvImageDialog } from '../../resources/js/alpine/components/tv-dialogs-component.js';

/** The Movies release details page's tables and Clip (docs/proposals/movies-redesign/SPEC.md 5C; the details checks of prototype/check.mjs). */

function attributes(initial = {}) {
    const values = { ...initial };
    return {
        values,
        getAttribute: name => values[name] ?? null,
        setAttribute(name, value) { values[name] = String(value); },
        removeAttribute(name) { delete values[name]; },
    };
}

/** Moves the fake location to a URL, as history and Back do. */
function go(url) {
    const parsed = new URL(url);
    Object.assign(window.location, { href: parsed.toString(), hash: parsed.hash, search: parsed.search });
}

function browser(href = 'https://nntmux.test/details/abc', { fail = false } = {}) {
    const requests = [], pushed = [], replaced = [], listeners = {}, toasts = [];
    globalThis.window = {
        location: { href, hash: new URL(href).hash, search: new URL(href).search },
        history: {
            pushState: (state, title, url) => { pushed.push(url); go(url); },
            replaceState: (state, title, url) => { replaced.push(url); go(url); },
        },
        addEventListener: (name, handler) => { listeners[name] = handler; },
        removeEventListener() {},
        showToast: (message, kind) => toasts.push([message, kind]),
    };
    globalThis.fetch = async (url, options) => {
        requests.push({ url: String(url), headers: options?.headers ?? {} });
        return fail ? { ok: false, redirected: false, text: async () => '' } : { ok: true, redirected: false, text: async () => '<h2>All 60 releases of this film</h2>' + String(url) };
    };
    return { requests, pushed, replaced, listeners, toasts };
}

function detailsPage() {
    const sortButton = { focus(options) { this.focused = options; } };
    const releases = {
        innerHTML: '<h2>All 60 releases of this film</h2>',
        scrolled: 0,
        scrollIntoView() { this.scrolled++; },
        querySelector: selector => (selector.startsWith('[data-sort=') ? sortButton : null),
    };
    const root = {
        dataset: { guid: 'abc', releaseId: '42', hasMedia: '0', hasNfo: '0' },
        querySelectorAll: () => [],
        querySelector: selector => (selector.startsWith('[data-tab-content=') ? { innerHTML: '' } : null),
    };
    const component = movieReleaseDetails();
    component.$el = root;
    component.$refs = { releases };
    component.init();
    return { component, releases, sortButton };
}

/** A click on an element matching the given selectors (as closest() finds it). */
function click(element, matches) {
    let prevented = false;
    return {
        event: { target: { closest: selector => (matches.some(match => selector.includes(match)) ? element : null) }, preventDefault() { prevented = true; } },
        prevented: () => prevented,
    };
}

const settle = () => new Promise(resolve => setTimeout(resolve, 0));

test('another page of "All N" keeps the open tab; a URL naming a page without a tab opens at the table', () => {
    assert.equal(tablePageUrl('https://nntmux.test/details/abc?sort=size&page=2#releases', 'https://nntmux.test/details/abc#files').toString(), 'https://nntmux.test/details/abc?sort=size&page=2#files');
    assert.equal(tablePageUrl('https://nntmux.test/details/abc?page=3#releases', 'https://nntmux.test/details/abc').toString(), 'https://nntmux.test/details/abc?page=3');
    assert.equal(opensAtTable('https://nntmux.test/details/abc?page=2'), true);
    assert.equal(opensAtTable('https://nntmux.test/details/abc?page=2#releases'), true);
    assert.equal(opensAtTable('https://nntmux.test/details/abc?page=2#nfo'), false);
    assert.equal(opensAtTable('https://nntmux.test/details/abc?sort=size'), false);

    browser('https://nntmux.test/details/abc?page=2');
    assert.equal(detailsPage().releases.scrolled, 1);
    browser('https://nntmux.test/details/abc');
    assert.equal(detailsPage().releases.scrolled, 0);
});

test('a page link loads the table in place, on the same tab, into view, with the page in the history', async () => {
    const { requests, pushed } = browser('https://nntmux.test/details/abc#files');
    const { component, releases } = detailsPage();
    const link = { href: 'https://nntmux.test/details/abc?sort=size&page=1#releases' };
    const pressed = click(link, ['[data-film-releases] nav a[href]']);
    await component.handleClick(pressed.event);
    await settle();
    assert.equal(pressed.prevented(), true);
    assert.deepEqual(pushed, ['https://nntmux.test/details/abc?sort=size&page=1#files']);
    assert.equal(requests.at(-1).url, 'https://nntmux.test/details/abc?sort=size&page=1&_fragment=releases#files');
    assert.match(releases.innerHTML, /_fragment=releases/);
    assert.equal(releases.scrolled, 1);
    assert.equal(component.activeTab, 'files');
});

test('"Go to page" loads that page the same way', async () => {
    const { pushed } = browser('https://nntmux.test/details/abc?sort=resolution_asc');
    const { component, releases } = detailsPage();
    globalThis.FormData = class { constructor(form) { this.entries = form.fields; } forEach(callback) { this.entries.forEach(([key, value]) => callback(value, key)); } };
    const form = { action: 'https://nntmux.test/details/abc#releases', fields: [['sort', 'resolution_asc'], ['page', '4']] };
    let prevented = false;
    await component.handleSubmit({ target: { closest: selector => (selector === '[data-film-releases] form' ? form : null) }, preventDefault() { prevented = true; } });
    await settle();
    assert.equal(prevented, true);
    assert.deepEqual(pushed, ['https://nntmux.test/details/abc?sort=resolution_asc&page=4']);
    assert.equal(releases.scrolled, 1);
});

test('a sort change drops the page, so the table returns to the page holding this release, and keeps focus on the heading', async () => {
    const { requests, replaced } = browser('https://nntmux.test/details/abc?page=2#nfo');
    const { component, sortButton, releases } = detailsPage();
    await component.handleClick(click({ dataset: { sort: 'size' } }, ['[data-sort]']).event);
    assert.deepEqual(replaced.at(-1), 'https://nntmux.test/details/abc?sort=size#nfo');
    assert.equal(requests.at(-1).url, 'https://nntmux.test/details/abc?sort=size&_fragment=releases#nfo');
    assert.equal(sortButton.focused.preventScroll, true);
    assert.equal(releases.scrolled, 0);
    await component.handleClick(click({ dataset: { sort: 'size' } }, ['[data-sort]']).event);
    assert.equal(replaced.at(-1), 'https://nntmux.test/details/abc?sort=size_asc#nfo');
    await component.handleClick(click({ dataset: { sort: 'size' } }, ['[data-sort]']).event);
    assert.equal(replaced.at(-1), 'https://nntmux.test/details/abc?sort=size#nfo');
    await component.handleClick(click({ dataset: { sort: 'posted' } }, ['[data-sort]']).event);
    assert.equal(replaced.at(-1), 'https://nntmux.test/details/abc#nfo');
});

test('Back reloads the table for the page in the URL, not for a tab\'s hash; a failed load says so and keeps the table', async () => {
    const { listeners, requests } = browser('https://nntmux.test/details/abc?page=3');
    detailsPage();
    go('https://nntmux.test/details/abc?page=3#comments');
    await listeners.popstate();
    assert.equal(requests.length, 0);
    go('https://nntmux.test/details/abc?page=2');
    await listeners.popstate();
    assert.equal(requests.at(-1).url, 'https://nntmux.test/details/abc?page=2&_fragment=releases');

    const failing = browser('https://nntmux.test/details/abc', { fail: true });
    const { component, releases } = detailsPage();
    await component.handleClick(click({ dataset: { sort: 'size' } }, ['[data-sort]']).event);
    assert.equal(releases.innerHTML, '<h2>All 60 releases of this film</h2>');
    assert.equal(failing.toasts[0][1], 'error');
});

test('Similar releases sorts in the browser, apart from "All N", newest posted first to begin with', () => {
    const { requests } = browser();
    const { component } = detailsPage();
    const rows = [{ guid: 'a', dataset: { size: '1', posted: '30', resolution: '1' } }, { guid: 'b', dataset: { size: '3', posted: '20', resolution: '9' } }];
    const cells = ['resolution', 'size', 'posted'].map(key => ({ ...attributes(key === 'posted' ? { 'aria-sort': 'descending' } : {}), querySelector: () => ({ dataset: { similarSort: key } }) }));
    const body = { rows, append(...ordered) { body.rows = ordered; } };
    const table = { tBodies: [body], querySelectorAll: () => cells };
    const header = { dataset: { similarSort: 'size' }, closest: () => table };
    component.handleClick(click(header, ['[data-similar-sort]']).event);
    assert.deepEqual(body.rows.map(row => row.guid), ['b', 'a']);
    assert.equal(cells[1].getAttribute('aria-sort'), 'descending');
    assert.equal(cells[2].getAttribute('aria-sort'), null);
    component.handleClick(click(header, ['[data-similar-sort]']).event);
    assert.deepEqual(body.rows.map(row => row.guid), ['a', 'b']);
    assert.equal(cells[1].getAttribute('aria-sort'), 'ascending');
    assert.equal(requests.length, 0);
});

/** A Similar releases table of fake rows, sorted by clicks on its headings. */
function similarTable(component, rows, keys) {
    const cells = keys.map(key => ({ ...attributes(key === 'posted' ? { 'aria-sort': 'descending' } : {}), querySelector: () => ({ dataset: { similarSort: key } }) }));
    const body = { rows, append(...ordered) { body.rows = ordered; } };
    const table = { tBodies: [body], querySelectorAll: () => cells };
    const sortBy = key => component.handleClick(click({ dataset: { similarSort: key }, closest: () => table }, ['[data-similar-sort]']).event);
    return { body, cells, sortBy, order: () => body.rows.map(row => row.guid) };
}

test('the Books, Console and PC Similar table sorts by Category ascending on the first click, descending on the second; Size and Posted start descending', () => {
    browser();
    const { component } = detailsPage();
    const row = (guid, category, size, posted, id) => ({ guid, dataset: { category: String(category), size: String(size), posted: String(posted), id: String(id) } });
    const { cells, sortBy, order } = similarTable(component, [row('a', 2, 1, 30, 1), row('b', 0, 3, 20, 2), row('c', 1, 2, 10, 3)], ['category', 'size', 'posted']);
    sortBy('category');
    assert.deepEqual(order(), ['b', 'c', 'a']);
    assert.equal(cells[0].getAttribute('aria-sort'), 'ascending');
    sortBy('category');
    assert.deepEqual(order(), ['a', 'c', 'b']);
    assert.equal(cells[0].getAttribute('aria-sort'), 'descending');
    sortBy('size');
    assert.deepEqual(order(), ['b', 'c', 'a']);
    assert.equal(cells[1].getAttribute('aria-sort'), 'descending');
    sortBy('posted');
    assert.deepEqual(order(), ['a', 'b', 'c']);
    assert.equal(cells[2].getAttribute('aria-sort'), 'descending');
});

test('rows with data-category tied on the sorted value come newest posted first, then the higher id, in either direction', () => {
    browser();
    const { component } = detailsPage();
    const row = (guid, category, posted, id) => ({ guid, dataset: { category: String(category), size: '5', posted: String(posted), id: String(id) } });
    const { sortBy, order } = similarTable(component, [row('old', 0, 10, 9), row('low', 0, 20, 1), row('high', 0, 20, 7), row('last', 3, 30, 2)], ['category', 'size', 'posted']);
    sortBy('category');
    assert.deepEqual(order(), ['high', 'low', 'old', 'last']);
    sortBy('category');
    assert.deepEqual(order(), ['last', 'high', 'low', 'old']);
    sortBy('size');
    assert.deepEqual(order(), ['last', 'high', 'low', 'old']);
});

test('rows without data-category (the Movies and Adult Similar tables) keep their previous order on a tie', () => {
    browser();
    const { component } = detailsPage();
    const row = (guid, size, posted) => ({ guid, dataset: { size: String(size), posted: String(posted), resolution: '1' } });
    const { sortBy, order } = similarTable(component, [row('a', 5, 10), row('b', 5, 30), row('c', 9, 20)], ['resolution', 'size', 'posted']);
    sortBy('size');
    assert.deepEqual(order(), ['c', 'a', 'b']);
    sortBy('resolution');
    assert.deepEqual(order(), ['c', 'a', 'b']);
});

test('the Clip chip opens the image dialog with a player that fetches nothing until played, removed on close', () => {
    const created = [];
    globalThis.document = {
        createElement(tag) {
            const element = { tag, children: [], append(...nodes) { this.children.push(...nodes); }, replaceChildren(...nodes) { this.children = nodes; }, pause() { this.paused = true; }, load() {} };
            created.push(element);
            return element;
        },
    };
    const player = { children: [], replaceChildren(...nodes) { this.children = nodes; }, querySelector() { return this.children[0] ?? null; } };
    const dialog = tvImageDialog();
    dialog.$refs = { player, image: {} };
    dialog.$nextTick = callback => callback();
    dialog.show({ classList: { contains: () => false }, dataset: { guid: 'abc', videoUrl: '/preview/video/abc', videoType: 'video/webm', imageTitle: 'Video preview', releaseDisplayName: 'The.Film.2020' } });
    assert.equal(dialog.title, 'Video preview');
    assert.equal(dialog.video, true);
    assert.equal(dialog.showImage(), false);
    assert.equal(dialog.imageUrl, '');
    const video = player.children[0];
    assert.equal(video.tag, 'video');
    assert.equal(video.preload, 'none');
    assert.equal(video.controls, true);
    assert.equal(video.tabIndex, 0);
    assert.deepEqual([video.children[0].src, video.children[0].type], ['/preview/video/abc', 'video/webm']);
    dialog.close();
    assert.equal(video.paused, true);
    assert.deepEqual(player.children, []);
    assert.equal(dialog.video, false);

    dialog.show({ classList: { contains: name => name === 'preview-badge' }, dataset: { guid: 'abc', imageUrl: '/covers/preview/abc_thumb.webp' } });
    assert.equal(dialog.video, false);
    assert.equal(dialog.showImage(), true);
    assert.deepEqual(player.children, []);
});
