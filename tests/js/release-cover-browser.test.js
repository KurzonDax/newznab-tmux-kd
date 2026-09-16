import assert from 'node:assert/strict';
import test from 'node:test';
import { releaseBrowser } from '../../resources/js/alpine/components/release-browser-component.js';

function covers() {
    const liveTrees = new Set();
    const panel = {
        hidden: true, children: [], markup: '', style: { setProperty() {} },
        get innerHTML() { return this.markup; },
        set innerHTML(html) {
            this.markup = html;
            this.children = html ? [{ html, boxes: [...html.matchAll(/data-release-select value="([^"]+)"/g)].map(match => ({
                value: match[1], checked: false, row: { dataset: {} }, closest() { return this.row; },
            })) }] : [];
        },
        setAttribute() {}, removeAttribute() {}, scrollIntoView() {},
        getBoundingClientRect: () => ({ left: 0 }),
    };
    const tiles = ['first', 'second'].map((id, i) => {
        const tile = { dataset: { coverTile: id }, offsetTop: 0,
            after: element => { element.previousElementSibling = tile; },
            getBoundingClientRect: () => ({ left: i * 100, width: 90 }),
        };
        tile.button = { isConnected: true, closest: () => tile, setAttribute() {}, focus() {} };
        tile.querySelector = () => tile.button;
        return tile;
    });
    globalThis.window = {
        location: { href: 'https://nntmux.test/browse/movies?view=covers&genre=Drama&watching=1' },
        matchMedia: () => ({ matches: false }),
        Alpine: { mutateDom: callback => callback(), destroyTree: tree => liveTrees.delete(tree), initTree: tree => liveTrees.add(tree) },
    };
    const component = releaseBrowser();
    component.browserRoot = {
        dataset: {},
        ownerDocument: { querySelectorAll: () => [] },
        querySelectorAll: selector => selector === '[data-cover-tile]' ? tiles
            : selector === '[data-release-select]' ? panel.children.flatMap(tree => tree.boxes) : [],
        querySelector: () => ({ innerHTML: '<div role="alert">Could not load releases. <button>Try again</button></div>' }),
    };
    component.coverPanel = panel;
    component.$nextTick = callback => callback();
    return { component, panel, tiles, liveTrees };
}

test('switching covers cancels the old request and a late reply cannot replace the selected title', async () => {
    const { component, panel, tiles } = covers();
    const pending = [];
    globalThis.fetch = (url, options) => new Promise(resolve => pending.push({ url, options, resolve }));
    const first = component.openCover({ currentTarget: tiles[0].button });
    const second = component.openCover({ currentTarget: tiles[1].button });
    assert.equal(pending[0].options.signal.aborted, true);
    const url = new URL(pending[1].url);
    assert.equal(url.searchParams.get('cover'), 'second');
    assert.equal(url.searchParams.get('genre'), 'Drama');
    assert.equal(url.searchParams.get('watching'), '1');
    pending[1].resolve({ ok: true, text: async () => '<table data-release-table>Second</table>' });
    await second;
    pending[0].resolve({ ok: true, text: async () => '<table data-release-table>First</table>' });
    await first;
    assert.match(panel.innerHTML, /Second/);
    assert.equal(component.coverTile, tiles[1]);
    await component.openCover({ currentTarget: tiles[1].button });
    assert.equal(panel.hidden, true);
    assert.equal(component.coverTile, null);
    assert.equal(panel.innerHTML, '');
});

test('a failed expansion can retry and closing it cancels an in-flight reply', async () => {
    const { component, panel, tiles } = covers();
    globalThis.fetch = async () => ({ ok: false });
    await component.openCover({ currentTarget: tiles[0].button });
    assert.match(panel.innerHTML, /Try again/);
    let finish;
    globalThis.fetch = () => new Promise(resolve => { finish = resolve; });
    const retry = component.fetchCover();
    component.closeCover();
    finish({ ok: true, text: async () => '<table data-release-table>Late reply</table>' });
    await retry;
    assert.equal(panel.hidden, true);
    assert.equal(panel.innerHTML, '');
});

test('dashboard cover expansions use the canonical browse URL', async () => {
    const { component, tiles } = covers();
    window.location.href = 'https://nntmux.test/';
    component.browserRoot.dataset.coverUrl = 'https://nntmux.test/browse/movies?view=covers&sort=grabs';
    let fetched;
    globalThis.fetch = async url => { fetched = new URL(url); return { ok: true, text: async () => '<table data-release-table>Latest</table>' }; };
    await component.openCover({ currentTarget: tiles[0].button });
    assert.equal(fetched.pathname, '/browse/movies');
    assert.equal(fetched.searchParams.get('sort'), 'grabs');
    assert.equal(fetched.searchParams.get('cover'), 'first');
});

test('paging clears selection immediately and repeated navigation retains only the current expansion tree', async () => {
    const { component, panel, tiles, liveTrees } = covers();
    const pending = [];
    globalThis.fetch = (url, options) => new Promise(resolve => pending.push({ url: new URL(url), options, resolve }));
    const reply = (index, guid) => pending[index].resolve({ ok: true, text: async () =>
        `<table data-release-table><tr data-release-row><input data-release-select value="${guid}"></tr></table>` });
    const opening = component.openCover({ currentTarget: tiles[0].button });
    reply(0, 'first-page');
    await opening;
    component.selectAll({ target: { checked: true } });
    assert.deepEqual(component.selectedGuids(), ['first-page']);
    assert.equal(component.selectedCount, 1);

    const page = component.changeCoverPage({ currentTarget: { dataset: { coverPage: '2' } } });
    assert.equal(component.selectedCount, 0);
    assert.deepEqual(component.selectedGuids(), []);
    const per = component.changeCoverPer({ target: { value: '48' } });
    assert.equal(pending[1].options.signal.aborted, true);
    assert.equal(pending[1].url.searchParams.get('release_page'), '2');
    assert.equal(pending[2].url.searchParams.get('release_page'), '1');
    assert.equal(pending[2].url.searchParams.get('release_per'), '48');
    assert.equal(pending[2].url.searchParams.get('genre'), 'Drama');
    reply(2, 'current-page');
    await per;
    reply(1, 'stale-page');
    await page;
    assert.equal(liveTrees.size, 1);
    assert.deepEqual([...liveTrees], panel.children);
    component.selectAll({ target: { checked: true } });
    assert.deepEqual(component.selectedGuids(), ['current-page']);
    let download;
    globalThis.document = {
        querySelector: () => ({ content: 'cover-csrf' }),
        body: { append() {} },
        createElement() {
            return { children: [], append(child) { this.children.push(child); }, submit() { download = this; }, remove() {} };
        },
    };
    component.downloadSelected();
    assert.equal(download.method, 'POST');
    assert.equal(download.action, '/getnzb');
    assert.deepEqual(Object.fromEntries(download.children.map(input => [input.name, input.value])),
        { id: 'current-page', zip: '1', _token: 'cover-csrf' });

    for (const key of ['ArrowRight', 'ArrowLeft', 'ArrowRight']) {
        component.coverKeydown({ key, target: { closest: () => null }, preventDefault() {} });
        const index = pending.length - 1;
        reply(index, `navigation-${index}`);
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(liveTrees.size, 1);
        assert.deepEqual([...liveTrees], panel.children);
        assert.equal(panel.previousElementSibling, tiles[1]);
        assert.equal(component.selectedCount, 0);
    }
    const late = component.changeCoverPage({ currentTarget: { dataset: { coverPage: '3' } } });
    component.closeCover();
    assert.equal(pending.at(-1).options.signal.aborted, true);
    reply(pending.length - 1, 'closed-page');
    await late;
    assert.equal(liveTrees.size, 0);
    assert.equal(panel.children.length, 0);
    assert.equal(component.selectedCount, 0);
});
