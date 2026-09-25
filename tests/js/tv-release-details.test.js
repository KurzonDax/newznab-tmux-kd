import assert from 'node:assert/strict';
import test from 'node:test';
import { tabFromHash, TABS, tvReleaseDetails } from '../../resources/js/alpine/components/tv-release-details-component.js';
import { tvFilesDialog, tvImageDialog } from '../../resources/js/alpine/components/tv-dialogs-component.js';
import { fileSize, fileTable, loadAllFiles } from '../../resources/js/alpine/components/tv-files.js';

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

function browser({ hash = '', responses = {} } = {}) {
    const requests = [], history = [], listeners = {};
    globalThis.window = {
        location: { href: 'https://nntmux.test/details/abc' + hash, hash },
        history: { replaceState: (state, title, url) => history.push(url) },
        addEventListener: (name, handler) => { listeners[name] = handler; },
        removeEventListener() {},
        showToast() {},
        requestAnimationFrame: callback => callback(),
    };
    globalThis.fetch = async url => {
        requests.push(String(url));
        const body = Object.entries(responses).find(([prefix]) => String(url).startsWith(prefix))?.[1];
        if (typeof body === 'function') return body(String(url));
        return { ok: body !== undefined, status: body === undefined ? 500 : 200, redirected: false, json: async () => body, text: async () => String(body) };
    };
    globalThis.DOMParser = class {
        parseFromString(html) {
            const match = html.match(/<pre[^>]*>([\s\S]*)<\/pre>/);
            return { querySelector: () => (match ? { textContent: match[1] } : null) };
        }
    };
    return { requests, history, listeners };
}

function detailsPage({ media = '1', nfo = '1', table = null } = {}) {
    const tabs = TABS.map(tab => ({ dataset: { tab }, ...attributes({ 'aria-selected': tab === 'overview' ? 'true' : 'false' }), focus() { this.focused = true; } }));
    const panels = TABS.map(id => ({ id, hidden: id !== 'overview' }));
    const contents = Object.fromEntries(['files', 'media', 'nfo'].map(tab => [tab, { innerHTML: '' }]));
    const root = {
        dataset: { guid: 'abc', releaseId: '42', hasMedia: media, hasNfo: nfo, nzbLinkBase: 'https://nntmux.test/api/v1/api', apiToken: 'secret' },
        querySelectorAll(selector) {
            if (selector === '[data-tab]') return tabs;
            if (selector === '[data-details-panel]') return panels;
            if (selector === '.tv-release-table') return table ? [table] : [];
            return [];
        },
        querySelector(selector) {
            const content = selector.match(/data-tab-content="(\w+)"/);
            if (content) return contents[content[1]];
            const tab = selector.match(/data-tab="(\w+)"/);
            if (tab) return tabs.find(button => button.dataset.tab === tab[1]);
            return null;
        },
    };
    const component = tvReleaseDetails();
    component.$el = root;
    component.init();
    return { component, tabs, panels, contents };
}

const click = target => ({ target: { closest: selector => (selector === '[data-tab]' && target.dataset.tab) || (selector === '[data-sort]' && target.dataset.sort) ? target : null } });
const settle = () => new Promise(resolve => setTimeout(resolve, 0));

test('the tab in the URL hash opens; anything else opens Overview', () => {
    assert.equal(tabFromHash('#nfo'), 'nfo');
    assert.equal(tabFromHash('#comments'), 'comments');
    assert.equal(tabFromHash('#reports'), 'overview');
    assert.equal(tabFromHash(''), 'overview');
    browser({ hash: '#comments' });
    const { tabs, panels } = detailsPage();
    assert.equal(tabs[4].getAttribute('aria-selected'), 'true');
    assert.equal(tabs[0].getAttribute('aria-selected'), 'false');
    assert.deepEqual(panels.filter(panel => !panel.hidden).map(panel => panel.id), ['comments']);
});

test('each tab shows its content: a tab is selected, its panel shown, the others hidden, and the hash remembers it', async () => {
    const { history } = browser({ responses: { '/release/abc/files': { files: [{ title: 'a.mkv', size: 58678 }], last_page: 1 } } });
    const { component, tabs, panels, contents } = detailsPage();
    await component.handleClick(click(tabs[1]));
    assert.equal(tabs[1].getAttribute('aria-selected'), 'true');
    assert.equal(tabs[1].getAttribute('tabindex'), '0');
    assert.equal(tabs[0].getAttribute('tabindex'), '-1');
    assert.deepEqual(panels.filter(panel => !panel.hidden).map(panel => panel.id), ['files']);
    assert.match(contents.files.innerHTML, /a\.mkv<\/td><td class="tv-num">57 KB/);
    assert.match(history.at(-1), /#files$/);
    await component.handleClick(click(tabs[0]));
    assert.doesNotMatch(history.at(-1), /#/);
});

test('media info and the NFO load once, with the shared renderer and as monospace text', async () => {
    const media = { container: { format: 'Matroska' }, streams: { video: [{ codec_name: 'H.265', format: 'HEVC', width: 1920, height: 1080, hdr: [] }], audio: [], subtitle: [] } };
    const { requests } = browser({ responses: { '/release/42/mediainfo': { media, resolution: '1080p' }, '/nfo/abc': '<pre data-release-name="x">  &lt;ascii&gt; art</pre>' } });
    const { component, contents } = detailsPage();
    await component.selectTab('media', true);
    await component.selectTab('media', true);
    assert.equal(requests.filter(url => url.startsWith('/release/42/mediainfo')).length, 1);
    assert.match(contents.media.innerHTML, /class="mi-block"/);
    assert.match(contents.media.innerHTML, /resolution-chip-1080">1080p/);
    await component.selectTab('nfo', true);
    assert.match(contents.nfo.innerHTML, /^<pre class="tv-nfo">/);
});

test('a release without media info or NFO asks for neither; a failed load can be retried', async () => {
    const { requests } = browser();
    const { component, contents } = detailsPage({ media: '0', nfo: '0' });
    await component.selectTab('media', true);
    await component.selectTab('nfo', true);
    assert.equal(requests.length, 0);
    const failing = detailsPage();
    await failing.component.selectTab('files', true);
    assert.match(failing.contents.files.innerHTML, /role="alert"/);
    assert.equal(failing.component.loaded.has('files'), false);
    assert.equal(contents.media.innerHTML, '');
});

test('arrow keys move between the tabs and keep focus on the tab row', () => {
    browser();
    const { component, tabs } = detailsPage();
    const key = name => ({ key: name, preventDefault() {} });
    component.tabKey(key('ArrowLeft'));
    assert.equal(component.activeTab, 'comments');
    assert.equal(tabs[4].focused, true);
    component.tabKey(key('Home'));
    assert.equal(component.activeTab, 'overview');
});

test('sorting the episode table reorders its rows in place, so focus stays on the pressed header', () => {
    browser();
    const rows = [{ guid: 'a', dataset: { size: '1', grabs: '9' } }, { guid: 'b', dataset: { size: '3', grabs: '1' } }];
    const cells = ['size', 'grabs'].map(key => ({ ...attributes(), querySelector: () => ({ dataset: { sort: key } }) }));
    const body = { rows, append(...ordered) { body.rows = ordered; } };
    const table = { tBodies: [body], querySelectorAll: () => cells };
    const { component } = detailsPage({ table });
    const header = { dataset: { sort: 'grabs' } };
    component.handleClick(click(header));
    assert.deepEqual(body.rows.map(row => row.guid), ['a', 'b']);
    assert.equal(cells[1].getAttribute('aria-sort'), 'descending');
    assert.equal(cells[0].getAttribute('aria-sort'), null);
    component.handleClick(click(header));
    assert.deepEqual(body.rows.map(row => row.guid), ['b', 'a']);
    assert.equal(cells[1].getAttribute('aria-sort'), 'ascending');
});

test('the header cart button reads "In cart" with a tick once added, and back', () => {
    browser();
    const icon = { classList: new Set(['fa-cart-shopping']) };
    icon.classList.toggle = function (name, on) { if (on) this.add(name); else this.delete(name); };
    const label = { textContent: 'Add to cart' };
    const button = { dataset: { cart: 'abc', cartLabel: '' }, ...attributes(), querySelector: selector => (selector === 'span' ? label : icon) };
    const { component } = detailsPage();
    component.screen.querySelectorAll = selector => (selector === '[data-cart]' ? [button] : []);
    component.markCart(['abc'], true);
    assert.equal(label.textContent, 'In cart');
    assert.equal(button.getAttribute('aria-pressed'), 'true');
    assert.equal(icon.classList.has('fa-check'), true);
    component.markCart(['abc'], false);
    assert.equal(label.textContent, 'Add to cart');
    assert.equal(icon.classList.has('fa-cart-shopping'), true);
});

test('files under 1 MB are shown in KB, the rest in MB and GB', () => {
    assert.equal(fileSize(58678), '57 KB');
    assert.equal(fileSize(100), '1 KB');
    assert.equal(fileSize(1048576), '1 MB');
    assert.equal(fileSize(734 * 1048576), '734 MB');
    assert.equal(fileSize(2.41 * 1073741824), '2.41 GB');
    assert.equal(fileSize(12.34 * 1073741824), '12.3 GB');
    assert.match(fileTable([{ title: '<b>x</b>.srt', size: 1 }]), /&lt;b&gt;x&lt;\/b&gt;\.srt/);
});

test('the file list fetches every page at 100 a page and shows them as one list', async () => {
    const { requests } = browser({ responses: { '/release/abc/files': url => {
        const page = Number(new URL(url, 'https://nntmux.test').searchParams.get('page'));
        return { ok: true, redirected: false, json: async () => ({ files: [{ title: 'file ' + page, size: 10 }], last_page: 3, release: { searchname: 'Name' } }) };
    } } });
    const { name, files } = await loadAllFiles('abc');
    assert.deepEqual(requests, ['/release/abc/files?page=1&per=100', '/release/abc/files?page=2&per=100', '/release/abc/files?page=3&per=100']);
    assert.deepEqual(files.map(file => file.title), ['file 1', 'file 2', 'file 3']);
    assert.equal(name, 'Name');
});

test('the file count opens the file list dialog with the release name', async () => {
    browser({ responses: { '/release/abc/files': { files: [{ title: 'a.mkv', size: 5 }], last_page: 1, release: { searchname: 'Search.Name' } } } });
    const listeners = {};
    globalThis.document = { addEventListener: (name, handler) => { listeners[name] = handler; }, removeEventListener() {} };
    const dialog = tvFilesDialog();
    dialog.$el = { querySelector: () => null };
    dialog.$refs = { content: { innerHTML: '' } };
    dialog.$watch = () => {};
    dialog.init();
    const badge = { dataset: { guid: 'abc' }, closest: selector => (selector === '[data-release-row]' ? { querySelector: () => ({ textContent: ' The.Release ' }) } : null) };
    listeners.click({ target: { closest: selector => (selector === '.filelist-badge' ? badge : null) }, preventDefault() {} });
    await settle();
    assert.equal(dialog.open, true);
    assert.equal(dialog.releaseName, 'The.Release');
    assert.match(dialog.$refs.content.innerHTML, /tv-file-list/);
    dialog.close();
    assert.equal(dialog.open, false);
});

test('the image dialog shows the full-size copy, its pixel size, and Full size only when it is larger than shown', () => {
    browser();
    globalThis.document = { addEventListener() {}, removeEventListener() {} };
    const dialog = tvImageDialog();
    const image = { complete: false, naturalWidth: 1920, naturalHeight: 1080, clientWidth: 816, clientHeight: 459 };
    dialog.$refs = { image };
    dialog.$nextTick = callback => callback();
    const trigger = { classList: { contains: name => name === 'preview-badge' }, dataset: { guid: 'abc', imageUrl: '/covers/preview/abc_thumb.webp', fullUrl: '/covers/preview/abc.webp', releaseDisplayName: 'The.Release' } };
    dialog.show(trigger);
    assert.equal(dialog.imageUrl, '/covers/preview/abc.webp');
    assert.equal(dialog.title, 'Preview image');
    dialog.measure();
    assert.equal(dialog.dimensions, '1920 × 1080');
    assert.equal(dialog.canFull, true);
    assert.equal(dialog.fullLabel(), 'Full size');
    dialog.toggleFull();
    assert.equal(dialog.full, true);
    assert.equal(dialog.fullLabel(), 'Fit to window');
    assert.equal(dialog.fullPressed(), 'true');
    assert.match(dialog.dialogClass(), /is-full/);
    dialog.toggleFull();
    assert.equal(dialog.full, false);

    const small = { ...image, naturalWidth: 400, naturalHeight: 300, clientWidth: 400, clientHeight: 300 };
    dialog.$refs = { image: small };
    dialog.show({ ...trigger, classList: { contains: name => name === 'sample-badge' }, dataset: { guid: 'abc', imageUrl: '/covers/sample/abc_thumb.webp' } });
    dialog.measure();
    assert.equal(dialog.title, 'Sample image');
    assert.equal(dialog.imageUrl, '/covers/sample/abc_thumb.webp');
    assert.equal(dialog.canFull, false);
    dialog.toggleFull();
    assert.equal(dialog.full, false);
});
