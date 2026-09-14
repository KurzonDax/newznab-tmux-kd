import assert from 'node:assert/strict';
import test from 'node:test';
import { releaseBrowser } from '../../resources/js/alpine/components/release-browser-component.js';

function covers() {
    const panel = {
        hidden: true, children: [], innerHTML: '', style: { setProperty() {} },
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
        Alpine: { mutateDom: callback => callback(), destroyTree() {}, initTree() {} },
    };
    const component = releaseBrowser();
    component.browserRoot = {
        dataset: {},
        querySelectorAll: selector => selector === '[data-cover-tile]' ? tiles : [],
        querySelector: () => ({ innerHTML: '<div role="alert">Could not load releases. <button>Try again</button></div>' }),
    };
    component.coverPanel = panel;
    component.$nextTick = callback => callback();
    return { component, panel, tiles };
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
