import assert from 'node:assert/strict';
import test, { mock } from 'node:test';
import { releaseDeleteDialog, summaryAfterDelete } from '../../resources/js/alpine/components/release-delete-component.js';
import { tvReleases } from '../../resources/js/alpine/components/tv-releases-component.js';
import { movieReleaseDetails } from '../../resources/js/alpine/components/movie-release-details-component.js';

// The admin's Delete on the Adult list and an Adult release's Similar releases (issue #1167,
// docs/proposals/adult-redesign/SPEC.md 5.11): the confirmation dialog, the one POST, and what the
// screen does with the deleted row. A small fake DOM stands in for the browser.

const settle = () => new Promise(resolve => setImmediate(resolve));

/** A selector part: a tag, classes, [attr] and [attr="value"], and button's :not([disabled]). */
function matchesPart(node, part) {
    let rest = part, enabledOnly = false;
    if (rest.includes(':not([disabled])')) { enabledOnly = true; rest = rest.replace(':not([disabled])', ''); }
    const tag = rest.match(/^[a-z]+/)?.[0];
    if (tag && node.tagName !== tag) return false;
    for (const [, name] of rest.matchAll(/\.([\w-]+)/g)) if (!(node.getAttribute('class') ?? '').split(' ').includes(name)) return false;
    for (const [, name, value] of rest.matchAll(/\[([\w-]+)(?:="([^"]*)")?\]/g)) {
        if (!node.attrs.has(name) || (value !== undefined && node.attrs.get(name) !== value)) return false;
    }
    return !(enabledOnly && node.disabled);
}

class FakeNode {
    constructor(doc, tag, attrs = {}, children = []) {
        this.ownerDocument = doc;
        this.tagName = tag;
        this.attrs = new Map(Object.entries(attrs));
        this.children = [];
        this.parentElement = null;
        this.listeners = new Map();
        this.textContent = '';
        this.checked = false;
        this.disabled = false;
        this.hidden = false;
        this.append(...children);
    }

    get dataset() {
        const data = {};
        for (const [name, value] of this.attrs) {
            if (name.startsWith('data-')) data[name.slice(5).replace(/-([a-z])/g, (all, letter) => letter.toUpperCase())] = value;
        }
        return data;
    }

    get value() { return this.attrs.get('value') ?? ''; }
    getAttribute(name) { return this.attrs.has(name) ? this.attrs.get(name) : null; }
    setAttribute(name, value) { this.attrs.set(name, String(value)); }
    removeAttribute(name) { this.attrs.delete(name); }
    matches(selector) { return selector.split(',').some(part => matchesPart(this, part.trim())); }
    closest(selector) {
        for (let node = this; node; node = node.parentElement) if (node.matches(selector)) return node;
        return null;
    }

    *descendants() {
        for (const child of this.children) { yield child; yield* child.descendants(); }
    }

    querySelectorAll(selector) { return [...this.descendants()].filter(node => node.matches(selector)); }
    querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null; }
    contains(node) { return node === this || [...this.descendants()].includes(node); }
    append(...nodes) {
        for (const node of nodes) { node.remove(); node.parentElement = this; this.children.push(node); }
    }

    remove() {
        if (!this.parentElement) return;
        this.parentElement.children.splice(this.parentElement.children.indexOf(this), 1);
        this.parentElement = null;
    }

    get isConnected() {
        let node = this;
        while (node.parentElement) node = node.parentElement;
        return node === this.ownerDocument.root;
    }

    focus() { this.ownerDocument.activeElement = this; }
    getClientRects() { return [1]; }
    addEventListener(type, listener) { this.listeners.set(type, [...(this.listeners.get(type) ?? []), listener]); }
    dispatchEvent(event) {
        const passed = { type: event.type, detail: event.detail, target: this };
        for (let node = this; node; node = node.parentElement) (node.listeners.get(event.type) ?? []).forEach(listener => listener(passed));
        return true;
    }

    set innerHTML(html) {
        this.html = html;
        [...this.children].forEach(child => child.remove());
    }

    get innerHTML() { return this.html ?? ''; }
}

function fakeDocument() {
    const listeners = new Map();
    const doc = {
        activeElement: null,
        body: { style: { overflow: 'auto' } },
        addEventListener(type, listener) { listeners.set(listener, type); },
        removeEventListener(type, listener) { listeners.delete(listener); },
        querySelector: selector => doc.root.querySelector(selector),
        fire(type, event) { for (const [listener, name] of [...listeners]) if (name === type) listener(event); },
    };
    doc.root = new FakeNode(doc, 'html');
    return doc;
}

/** The trashcan's row as the buttons partial draws it: the box, the cart button and the trashcan. */
function releaseRow(doc, index, { carted = false, ticked = false } = {}) {
    const guid = 'guid' + index;
    const box = new FakeNode(doc, 'input', { 'data-select': '', value: guid });
    box.checked = ticked;
    return new FakeNode(doc, 'tr', { 'data-release-row': '' }, [
        new FakeNode(doc, 'td', {}, [box]),
        new FakeNode(doc, 'td', {}, [
            new FakeNode(doc, 'button', { 'data-cart': guid, 'aria-pressed': carted ? 'true' : 'false' }),
            new FakeNode(doc, 'button', { 'data-delete-release': 'https://nntmux.test/admin/release-delete/' + guid, 'data-release-name': 'Studio.Scene.' + index }),
        ]),
    ]);
}

/** The dialog's markup: the frame's close button first, then Cancel and Delete. */
function dialogMarkup(doc) {
    const close = new FakeNode(doc, 'button', { class: 'tv-dialog-close' });
    const cancel = new FakeNode(doc, 'button', { class: 'is-secondary' });
    const confirm = new FakeNode(doc, 'button', { class: 'is-danger' });
    const root = new FakeNode(doc, 'div', {}, [new FakeNode(doc, 'div', { 'data-modal-dialog': '' }, [close, cancel, confirm])]);
    return { root, close, cancel, confirm };
}

/**
 * The dialog with Alpine's order: a change of `open` runs its watcher first (a microtask), then the
 * $nextTick callbacks, those queued while draining included (Alpine's tick stack).
 */
function mountDialog(doc, markup, cart) {
    const effects = [], ticks = [], watchers = {};
    const dialog = releaseDeleteDialog();
    let open = dialog.open;
    Object.defineProperty(dialog, 'open', {
        get: () => open,
        set: value => { open = value; effects.push(() => watchers.open?.(value)); },
    });
    Object.assign(dialog, {
        $el: markup.root, $refs: { cancel: markup.cancel }, $store: { cart },
        $nextTick: callback => ticks.push(callback),
        $watch: (name, callback) => { watchers[name] = callback; },
    });
    dialog.init();
    const flush = () => {
        while (effects.length || ticks.length) {
            while (effects.length) effects.shift()();
            while (ticks.length) ticks.shift()();
        }
    };
    return { dialog, flush };
}

function browser(href, respond) {
    const requests = [], toasts = [], assigned = [];
    globalThis.window = {
        location: { href, assign: url => assigned.push(url) },
        showToast: (message, type) => toasts.push([message, type]),
    };
    globalThis.fetch = (url, options = {}) => {
        requests.push({ url: String(url), method: options.method ?? 'GET' });
        return respond(String(url), options);
    };
    return { requests, toasts, assigned };
}

const json = body => Promise.resolve({ ok: true, redirected: false, json: async () => body, text: async () => '' });

/**
 * The Adult list: the pager line, the table of `rows`, the name search and the dialog inside the screen,
 * whose release-deleted goes to tvReleases.releaseDeleted (index.blade.php's x-on).
 */
function adultList({ rows = 3, summary = 'Showing 51–53 of 230 releases', carted = [], ticked = [], respond = () => json({ success: true }), list = () => '<p>next page</p>' } = {}) {
    const doc = fakeDocument();
    globalThis.document = doc;
    const network = browser('https://nntmux.test/adult?resolution%5B%5D=1080p&page=2', (url, options) => (options.method === 'POST'
        ? respond(url)
        : Promise.resolve({ ok: true, redirected: list() === null, text: async () => list() ?? '' })));
    const line = new FakeNode(doc, 'span', { class: 'pager-line-summary' });
    line.textContent = summary;
    const body = new FakeNode(doc, 'tbody', {}, Array.from({ length: rows }, (_, index) => releaseRow(doc, index + 1, { carted: carted.includes(index + 1), ticked: ticked.includes(index + 1) })));
    const listRoot = new FakeNode(doc, 'div', {}, [
        new FakeNode(doc, 'nav', { class: 'pager-line' }, [line]),
        new FakeNode(doc, 'table', {}, [new FakeNode(doc, 'thead', {}, [new FakeNode(doc, 'input', { 'data-select-all': '' })]), body]),
    ]);
    const nameSearch = new FakeNode(doc, 'input', { 'x-ref': 'nameSearch' });
    const markup = dialogMarkup(doc);
    const screen = new FakeNode(doc, 'div', { 'data-filters-clock': '5000' }, [nameSearch, listRoot, markup.root]);
    doc.root.append(screen);

    const cart = { count: 4, setCount(count) { this.count = count; } };
    const component = tvReleases();
    Object.assign(component, { $el: screen, $refs: { list: listRoot, nameSearch }, $store: { cart } });
    component.init();
    screen.addEventListener('release-deleted', event => component.releaseDeleted(event));
    const { dialog, flush } = mountDialog(doc, markup, cart);
    const trash = index => body.children[index - 1]?.querySelector('[data-delete-release]');
    return { doc, ...network, line, body, listRoot, nameSearch, markup, component, dialog, flush, cart, trash };
}

function open(page, trashcan) {
    page.doc.fire('click', { target: trashcan });
    page.flush();
}

test.afterEach(() => {
    delete globalThis.window;
    delete globalThis.document;
    delete globalThis.fetch;
});

test('the trashcan opens the dialog naming the release, with Cancel focused after the frame focuses its close button', () => {
    const page = adultList();
    open(page, page.trash(2));
    assert.equal(page.dialog.open, true);
    assert.equal(page.dialog.name, 'Studio.Scene.2');
    assert.equal(page.doc.activeElement, page.markup.cancel);
    assert.equal(page.doc.body.style.overflow, 'hidden');
    assert.deepEqual(page.requests, []);
});

test('when x-show reveals the dialog after the ticks, Cancel takes the focus once it is on screen', () => {
    mock.timers.enable({ apis: ['setTimeout'] });
    try {
        const page = adultList();
        let shown = false;
        for (const control of [page.markup.close, page.markup.cancel, page.markup.confirm]) control.getClientRects = () => (shown ? [1] : []);
        const trashcan = page.trash(2);
        open(page, trashcan);
        assert.equal(page.doc.activeElement, trashcan, 'nothing hidden takes the focus');
        shown = true;
        mock.timers.tick(16);
        assert.equal(page.doc.activeElement, page.markup.cancel);
    } finally {
        mock.timers.reset();
    }
});

test('Cancel, the close button, a click outside and Escape close it sending nothing, and the trashcan has the focus again', () => {
    for (const how of ['cancel', 'close button', 'scrim', 'escape']) {
        const page = adultList();
        const trashcan = page.trash(2);
        open(page, trashcan);
        if (how === 'escape') page.doc.fire('keydown', { key: 'Escape', preventDefault() {} });
        else page.dialog.close();
        page.flush();
        assert.equal(page.dialog.open, false, how);
        assert.equal(page.doc.activeElement, trashcan, how);
        assert.equal(page.body.children.length, 3, how);
        assert.deepEqual(page.requests, [], how);
    }
});

test('confirming sends one POST to the admin delete; while it is in flight nothing closes the dialog or sends again', async () => {
    let answer;
    const page = adultList({ respond: () => new Promise(resolve => { answer = resolve; }) });
    open(page, page.trash(2));
    const deleting = page.dialog.deleteRelease();
    assert.equal(page.dialog.busy, true, 'the Delete button is disabled (x-bind:disabled="busy")');
    page.dialog.deleteRelease();
    page.dialog.close();
    page.doc.fire('keydown', { key: 'Escape', preventDefault() {} });
    page.flush();
    assert.equal(page.dialog.open, true);
    assert.deepEqual(page.requests, [{ url: 'https://nntmux.test/admin/release-delete/guid2', method: 'POST' }]);
    answer({ ok: true, json: async () => ({ success: true }) });
    await deleting;
    page.flush();
    assert.equal(page.dialog.open, false);
    assert.equal(page.dialog.busy, false);
    assert.equal(page.requests.length, 1);
});

test('on success the dialog closes, the row goes, the count drops, the selection, the cart and the toast follow, and the next trashcan has the focus', async () => {
    const page = adultList({ carted: [2], ticked: [1, 2] });
    assert.equal(page.component.selectedCount, 2);
    const next = page.trash(3);
    const order = [];
    page.listRoot.addEventListener('release-deleted', () => order.push(page.dialog.open ? 'row before the dialog closed' : 'row after the dialog closed'));
    open(page, page.trash(2));
    await page.dialog.deleteRelease();
    page.flush();
    await settle();
    assert.deepEqual(order, ['row after the dialog closed']);
    assert.equal(page.dialog.open, false);
    assert.deepEqual(page.body.children.map(row => row.querySelector('[data-select]').value), ['guid1', 'guid3']);
    assert.equal(page.line.textContent, 'Showing 51–53 of 229 releases');
    assert.equal(page.component.selectedCount, 1);
    assert.equal(page.cart.count, 3);
    assert.deepEqual(page.toasts, [['Deleted Studio.Scene.2', 'success']]);
    assert.equal(page.doc.activeElement, next);
    assert.equal(page.requests.filter(request => request.method === 'GET').length, 0, 'the page is not refilled');
});

test('deleting a release outside the cart leaves the cart count, and the last row hands the focus to the row above', async () => {
    const page = adultList();
    const above = page.trash(2);
    open(page, page.trash(3));
    await page.dialog.deleteRelease();
    page.flush();
    assert.equal(page.cart.count, 4);
    assert.equal(page.doc.activeElement, above);
    assert.equal(page.component.selectedCount, 0);
});

test('the pager summary follows the server\'s rule: N drops by one, Y becomes min(Y, N), X stays, separators and the noun follow', () => {
    assert.equal(summaryAfterDelete('Showing 51–100 of 230 releases'), 'Showing 51–100 of 229 releases');
    assert.equal(summaryAfterDelete('Showing 201–230 of 230 releases'), 'Showing 201–229 of 229 releases');
    assert.equal(summaryAfterDelete('Showing 1–2 of 2 releases'), 'Showing 1–1 of 1 release');
    assert.equal(summaryAfterDelete('Showing 1–50 of 1,001 releases'), 'Showing 1–50 of 1,000 releases');
    assert.equal(summaryAfterDelete('Showing 1,001–1,050 of 1,050 releases'), 'Showing 1,001–1,049 of 1,049 releases');
    assert.equal(summaryAfterDelete('Showing 0 releases'), 'Showing 0 releases', 'anything else is left as it is');
});

test('the last row on a page reloads the list at the current address and the name search takes the focus', async () => {
    const page = adultList({ rows: 1, summary: 'Showing 51–51 of 51 releases' });
    open(page, page.trash(1));
    await page.dialog.deleteRelease();
    page.flush();
    await settle();
    const reloads = page.requests.filter(request => request.method === 'GET');
    assert.equal(reloads.length, 1);
    const reload = new URL(reloads[0].url);
    assert.deepEqual([reload.pathname, reload.searchParams.get('page'), reload.searchParams.getAll('resolution[]'), reload.searchParams.get('_fragment')], ['/adult', '2', ['1080p'], 'list']);
    assert.equal(page.listRoot.innerHTML, '<p>next page</p>');
    assert.equal(page.doc.activeElement, page.nameSearch);
    assert.deepEqual(page.assigned, []);
    assert.deepEqual(page.toasts, [['Deleted Studio.Scene.1', 'success']]);
});

test('when that reload is redirected (the page is past the last) the address is opened again', async () => {
    const page = adultList({ rows: 1, list: () => null });
    open(page, page.trash(1));
    await page.dialog.deleteRelease();
    page.flush();
    await settle();
    assert.deepEqual(page.assigned, ['https://nntmux.test/adult?resolution%5B%5D=1080p&page=2']);
});

test('on failure (success false, a 403, a two-factor page, a network error) the dialog closes, the row stays and the trashcan has the focus', async () => {
    const failures = {
        'success false': () => json({ success: false }),
        403: () => Promise.resolve({ ok: false, status: 403, json: async () => ({}) }),
        'two-factor page': () => Promise.resolve({ ok: true, json: async () => { throw new SyntaxError('Unexpected token <'); } }),
        'network error': () => Promise.reject(new TypeError('Failed to fetch')),
    };
    for (const [name, respond] of Object.entries(failures)) {
        const page = adultList({ carted: [2], respond });
        const trashcan = page.trash(2);
        open(page, trashcan);
        await page.dialog.deleteRelease();
        page.flush();
        assert.equal(page.dialog.open, false, name);
        assert.equal(page.body.children.length, 3, name);
        assert.equal(page.line.textContent, 'Showing 51–53 of 230 releases', name);
        assert.equal(page.cart.count, 4, name);
        assert.equal(page.doc.activeElement, trashcan, name);
        assert.deepEqual(page.toasts, [['Could not delete the release. Please try again.', 'error']], name);
    }
});

test('a delete that succeeds after the list was drawn again still says it was deleted', async () => {
    let answer;
    const page = adultList({ respond: () => new Promise(resolve => { answer = resolve; }) });
    open(page, page.trash(2));
    const deleting = page.dialog.deleteRelease();
    page.listRoot.innerHTML = '<p>redrawn</p>';
    answer({ ok: true, json: async () => ({ success: true }) });
    await deleting;
    page.flush();
    assert.equal(page.dialog.open, false);
    assert.deepEqual(page.toasts, [['Deleted Studio.Scene.2', 'success']]);
});

/** An Adult details page: the selected tab, the Similar releases section, and the dialog inside the screen. */
function adultDetails(rows) {
    const doc = fakeDocument();
    globalThis.document = doc;
    const network = browser('https://nntmux.test/details/current', () => json({ success: true }));
    const tab = new FakeNode(doc, 'button', { role: 'tab', 'aria-selected': 'true' });
    const body = new FakeNode(doc, 'tbody', {}, Array.from({ length: rows }, (_, index) => releaseRow(doc, index + 1)));
    const section = new FakeNode(doc, 'section', { 'data-similar-releases': '' }, [new FakeNode(doc, 'h2'), new FakeNode(doc, 'table', {}, [body])]);
    const markup = dialogMarkup(doc);
    const screen = new FakeNode(doc, 'div', {}, [new FakeNode(doc, 'button', { role: 'tab', 'aria-selected': 'false' }), tab, section, markup.root]);
    doc.root.append(screen);
    const cart = { count: 1, setCount(count) { this.count = count; } };
    const component = movieReleaseDetails();
    Object.assign(component, { $el: screen, screen, $store: { cart } });
    screen.addEventListener('release-deleted', event => component.similarReleaseDeleted(event));
    const { dialog, flush } = mountDialog(doc, markup, cart);
    return { doc, ...network, tab, body, section, screen, markup, dialog, flush, trash: index => body.children[index - 1].querySelector('[data-delete-release]') };
}

test('from the details page\'s Similar releases the row goes, you stay on the page, and the next trashcan has the focus', async () => {
    const page = adultDetails(2);
    const next = page.trash(2);
    open(page, page.trash(1));
    assert.equal(page.doc.activeElement, page.markup.cancel);
    await page.dialog.deleteRelease();
    page.flush();
    assert.deepEqual(page.requests, [{ url: 'https://nntmux.test/admin/release-delete/guid1', method: 'POST' }]);
    assert.equal(page.body.children.length, 1);
    assert.equal(page.section.isConnected, true);
    assert.equal(page.doc.activeElement, next);
    assert.deepEqual(page.toasts, [['Deleted Studio.Scene.1', 'success']]);
});

test('the last Similar row takes the whole section with it and the selected tab has the focus', async () => {
    const page = adultDetails(1);
    open(page, page.trash(1));
    await page.dialog.deleteRelease();
    page.flush();
    assert.equal(page.section.isConnected, false);
    assert.equal(page.doc.activeElement, page.tab);
});

test('a failed delete from Similar releases keeps the row and returns the focus to its trashcan', async () => {
    const page = adultDetails(1);
    globalThis.fetch = () => Promise.resolve({ ok: false, status: 419, json: async () => ({}) });
    const trashcan = page.trash(1);
    open(page, trashcan);
    await page.dialog.deleteRelease();
    page.flush();
    assert.equal(page.section.isConnected, true);
    assert.equal(page.doc.activeElement, trashcan);
    assert.deepEqual(page.toasts, [['Could not delete the release. Please try again.', 'error']]);
});
