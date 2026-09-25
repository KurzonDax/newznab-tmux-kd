import assert from 'node:assert/strict';
import test from 'node:test';
import { releaseDetails } from '../../resources/js/alpine/components/release-details-component.js';

function details(hash = '#overview') {
    const panels = ['overview', 'files', 'media', 'nfo', 'comments'].map(id => ({ id, hidden: false, dataset: {} }));
    const links = panels.map(panel => ({ dataset: { tab: panel.id }, setAttribute(key, value) { this[key] = value; }, removeAttribute(key) { delete this[key]; } }));
    const contents = Object.fromEntries(['files', 'media', 'nfo'].map(tab => [tab, { innerHTML: 'Initial ' + tab }]));
    const retries = Object.fromEntries(['files', 'media', 'nfo'].map(tab => [tab, { hidden: true }]));
    const events = {};
    globalThis.window = { location: { hash, href: 'https://nntmux.test/details/abc' + hash }, history: { replaceState: (a, b, url) => { window.location.hash = new URL(url).hash; } },
        addEventListener: (name, handler) => { events[name] = handler; }, removeEventListener: name => { delete events[name]; } };
    const component = releaseDetails();
    component.$el = { dataset: { guid: 'abc', releaseId: '7' },
        querySelectorAll: selector => selector === '[data-details-panel]' ? panels : links,
        querySelector: selector => (selector.startsWith('[data-retry-tab') ? retries : contents)[selector.match(/"(.*?)"/)?.[1]] || null };
    component.init();
    return { component, panels, links, contents, events, retries };
}

test('deep links select one server-rendered panel and browser history switches tabs', () => {
    const { component, panels, links, events } = details('#comments');
    assert.deepEqual(panels.filter(panel => !panel.hidden).map(panel => panel.id), ['comments']);
    assert.equal(links[4]['aria-current'], 'page');
    window.location.hash = '#overview';
    events.hashchange();
    assert.deepEqual(panels.filter(panel => !panel.hidden).map(panel => panel.id), ['overview']);
    component.destroy();
    assert.equal(events.hashchange, undefined);
});

test('media tab shows the shared stream rendering, caches success, and retries failure', async () => {
    const { component, contents, retries } = details();
    const requests = [];
    globalThis.fetch = async url => { requests.push(url); return { ok: false, status: 500 }; };
    await component.selectTab('media');
    assert.match(contents.media.innerHTML, /Could not load/);
    assert.equal(retries.media.hidden, false);
    globalThis.fetch = async url => { requests.push(url); return { ok: true, json: async () => ({ media: { identity: { title: 'Movie <title>' }, container: { format: 'Matroska' }, streams: { video: [{ format: 'HEVC', width: 3840, height: 2160 }], audio: [], subtitle: [] } } }) }; };
    await component.selectTab('media');
    assert.match(contents.media.innerHTML, /class="mi-block"/);
    assert.match(contents.media.innerHTML, /3840 × 2160/);
    await component.selectTab('overview');
    await component.selectTab('media');
    assert.deepEqual(requests, ['/release/7/mediainfo', '/release/7/mediainfo']);
});

test('details basket action changes its label and count only after a successful response', async () => {
    const { component } = details();
    const label = { textContent: 'Add to basket' };
    const button = { dataset: { guid: 'abc', inBasket: '0' }, disabled: false, querySelector: () => label };
    const requests = [], counts = [], notices = [];
    component.$store = { cart: { setCount: count => counts.push(count) } };
    globalThis.document = { querySelector: () => ({ content: 'csrf' }) };
    window.showToast = (...args) => notices.push(args);
    globalThis.fetch = async (url, options) => { requests.push({ url, options }); return { ok: true, json: async () => ({ success: true, cartCount: 1 }) }; };
    await component.toggleBasket({ currentTarget: button });
    assert.equal(button.dataset.inBasket, '1');
    assert.equal(label.textContent, 'Remove from basket');
    assert.deepEqual(counts, [1]);
    assert.equal(requests[0].url, '/cart/add');
    assert.equal(requests[0].options.headers['X-CSRF-TOKEN'], 'csrf');
    globalThis.fetch = async () => ({ ok: false });
    await component.toggleBasket({ currentTarget: button });
    assert.equal(button.dataset.inBasket, '1');
    assert.equal(label.textContent, 'Remove from basket');
    assert.equal(button.disabled, false);
    assert.equal(notices.at(-1)[1], 'error');
});

test('file names are escaped and zero-byte files retain a size', async () => {
    const { component, contents } = details();
    globalThis.fetch = async () => ({ ok: true, json: async () => ({ files: [{ title: 'Movie <script>.mkv', size: 41943040 }, { name: 'empty.txt', size: 0 }] }) });
    await component.selectTab('files');
    assert.match(contents.files.innerHTML, /Movie &lt;script&gt;.mkv/);
    assert.match(contents.files.innerHTML, /40 MB/);
    assert.match(contents.files.innerHTML, /0 B/);
});

test('missing NFO uses the dark pane while a session redirect remains retryable', async () => {
    const { component, contents, retries } = details();
    globalThis.fetch = async () => ({ ok: false, status: 404 });
    await component.selectTab('nfo');
    assert.equal(contents.nfo.innerHTML, '<pre class="nfo-pane">No NFO for this release.</pre>');
    globalThis.fetch = async () => ({ ok: true, redirected: true });
    await component.selectTab('files');
    assert.match(contents.files.innerHTML, /Could not load/);
    assert.equal(retries.files.hidden, false);
});

test('a late reply cannot replace another tab or a destroyed page', async () => {
    const { component, contents, panels } = details();
    let resolve;
    globalThis.fetch = () => new Promise(done => { resolve = done; });
    const pending = component.selectTab('files');
    await component.selectTab('comments');
    component.destroy();
    resolve({ ok: true, json: async () => ({ files: [{ name: 'Late', size: 1 }] }) });
    await pending;
    assert.deepEqual(panels.filter(panel => !panel.hidden).map(panel => panel.id), ['comments']);
    assert.doesNotMatch(contents.files.innerHTML, /Late/);
    assert.equal(component.loaded.has('files'), false);
});

test('header and tab handlers keep querying the details root when Alpine changes event $el', async () => {
    const { component, panels, contents } = details();
    component.$el = { querySelectorAll: () => [], querySelector: () => null, dataset: {} };
    globalThis.fetch = async () => ({ ok: false, status: 404 });
    await component.selectTab('nfo');
    assert.deepEqual(panels.filter(panel => !panel.hidden).map(panel => panel.id), ['nfo']);
    assert.match(contents.nfo.innerHTML, /No NFO for this release/);
});

test('file paging replaces the page and rejects older success and failure replies', async () => {
    const { component, contents } = details();
    const requests = [];
    globalThis.fetch = (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject }));
    const first = component.selectTab('files');
    const second = component.loadFilePage(2);
    assert.equal(requests[0].options.signal.aborted, true);
    assert.match(requests[1].url, /\/release\/abc\/files\?page=2&per=100/);
    requests[1].resolve({ ok: true, json: async () => ({ files: [{ index: 100, title: 'New page', size: 0 }], page: 2, per: 100, total: 201, last_page: 3 }) });
    await second;
    requests[0].resolve({ ok: true, json: async () => ({ files: [{ title: 'Old page', size: 1 }], page: 1, per: 100, total: 201, last_page: 3 }) });
    await first;
    assert.match(contents.files.innerHTML, /New page/);
    assert.doesNotMatch(contents.files.innerHTML, /Old page/);
    const third = component.loadFilePage(3);
    const changed = component.filesPerChanged({ target: { value: '24' } });
    assert.match(requests[3].url, /page=1&per=24/);
    requests[3].resolve({ ok: true, json: async () => ({ files: [], page: 1, per: 24, total: 0, last_page: 1 }) });
    await changed;
    requests[2].reject(new Error('stale failure'));
    await third;
    assert.doesNotMatch(contents.files.innerHTML, /New page|Could not load/);
    assert.equal(component.filesPage, 1);
    assert.equal(component.filesPer, 24);
});
