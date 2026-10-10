import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { homeShelves } from '../../resources/js/alpine/components/home-shelves-component.js';

// The home page's shelves (issue #1037; docs/proposals/home-redesign/SPEC.md 3 and 4): the checks of
// prototype/check.mjs that live in the browser. The rail arrows, a tile's panel (opened at once, swapped, closed
// again when its load fails), the Shelves dialog's ticks and its drag-and-drop reordering with pointer and keyboard.

const ROW_HEIGHT = 48;
const SHELVES = ['Following', 'TV', 'Movies', 'Audio', 'Books', 'Console', 'PC', 'Adult', 'Other'];
const TICKED = ['Following', 'TV', 'Movies', 'Audio', 'Books'];

/** A small stand-in for a DOM element: attributes, classes, a tree and the selectors the component uses. */
class Element {
    constructor(attributes = {}, children = []) {
        this.attributes = { ...attributes };
        this.children = [];
        this.parent = null;
        this.style = {};
        this.html = '';
        this.focused = 0;
        this.scrolls = [];
        this.clientWidth = 1000;
        this.isConnected = true;
        children.forEach(child => this.appendChild(child));
    }

    get dataset() {
        return new Proxy({}, { get: (_, key) => this.attributes['data-' + String(key).replace(/[A-Z]/g, letter => '-' + letter.toLowerCase())] });
    }

    get classList() {
        const names = () => (this.attributes.class ?? '').split(' ').filter(Boolean);
        return {
            add: name => { if (!names().includes(name)) this.attributes.class = [...names(), name].join(' '); },
            remove: name => { this.attributes.class = names().filter(item => item !== name).join(' '); },
            contains: name => names().includes(name),
        };
    }

    get className() { return this.attributes.class ?? ''; }

    set className(value) { this.attributes.class = value; }

    get innerHTML() { return this.html; }

    set innerHTML(value) {
        this.html = value;
        this.children = [];
    }

    get firstElementChild() { return this.children[0] ?? null; }

    get previousElementSibling() { return this.parent?.children[this.parent.children.indexOf(this) - 1] ?? null; }

    get nextElementSibling() { return this.parent?.children[this.parent.children.indexOf(this) + 1] ?? null; }

    getAttribute(name) { return this.attributes[name] ?? null; }

    setAttribute(name, value) { this.attributes[name] = String(value); }

    removeAttribute(name) { delete this.attributes[name]; }

    hasAttribute(name) { return name in this.attributes; }

    /** `[name]`, `[name="value"]` and `.class`, alone or joined. */
    matches(selector) {
        return (selector.match(/\[[^\]]+\]|\.[\w-]+/g) ?? []).every(part => {
            if (part.startsWith('.')) return this.classList.contains(part.slice(1));
            const [, name, value] = part.match(/^\[([\w-]+)(?:="([^"]*)")?\]$/);
            return value === undefined ? this.hasAttribute(name) : this.getAttribute(name) === value;
        });
    }

    closest(selector) {
        for (let node = this; node; node = node.parent) if (node.matches(selector)) return node;
        return null;
    }

    querySelectorAll(selector) {
        return this.children.flatMap(child => [...(child.matches(selector) ? [child] : []), ...child.querySelectorAll(selector)]);
    }

    querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null; }

    detach() {
        if (this.parent) this.parent.children.splice(this.parent.children.indexOf(this), 1);
        this.parent = null;
    }

    appendChild(child) {
        child.detach();
        child.parent = this;
        this.children.push(child);
        return child;
    }

    place(node, offset) {
        node.detach();
        node.parent = this.parent;
        this.parent.children.splice(this.parent.children.indexOf(this) + offset, 0, node);
    }

    before(node) { this.place(node, 0); }

    after(node) { this.place(node, 1); }

    remove() { this.detach(); }

    cloneNode() { return new Element(this.attributes, this.children.map(child => child.cloneNode())); }

    /** A dialog row sits where its place in the list puts it; everything else at the origin. */
    getBoundingClientRect() {
        const rows = this.parent?.children.filter(child => child.hasAttribute('data-key')) ?? [];
        const top = 100 + Math.max(0, rows.indexOf(this)) * ROW_HEIGHT;
        return { left: 300, top, width: 560, height: ROW_HEIGHT, right: 860, bottom: top + ROW_HEIGHT };
    }

    /** On screen unless an ancestor is hidden (the dialog before x-show reveals it). */
    getClientRects() { return this.closest('[hidden]') ? [] : [this.getBoundingClientRect()]; }

    focus() { this.focused += 1; }

    scrollBy(options) { this.scrolls.push(options); }
}

function shelf(name, tiles) {
    return new Element({ 'data-shelf': name }, [
        new Element({ 'data-rail': '-1' }), new Element({ 'data-rail': '1' }),
        new Element({ 'data-rail-of': name }, tiles.map(([kind, id]) => new Element({ 'data-tile': '', 'data-kind': kind, 'data-id': id, 'aria-expanded': 'false' }, [new Element({ class: 'tv-tile-art' })]))),
        new Element({ 'data-panel-slot': '' }),
    ]);
}

function dialogRow(name) {
    return new Element({ class: 'home-shelf-row', 'data-key': name }, [
        new Element({ 'data-shelf-tick': '', role: 'checkbox', 'aria-checked': TICKED.includes(name) ? 'true' : 'false' }),
        new Element({ 'data-grip': '', 'aria-pressed': 'false', 'aria-label': 'Drag to reorder ' + name }),
    ]);
}

/** The page with two shelves and the dialog; `fail` names the requests that fail ('panel', 'shelves', 'save'). */
function page({ fail = [] } = {}) {
    const toasts = [], requests = [], posts = [], listeners = [], pending = [];
    const body = new Element();
    globalThis.window = { location: { href: 'https://nntmux.test/' }, showToast: (message, type) => toasts.push({ message, type }), isSecureContext: true };
    globalThis.document = {
        listeners, body,
        addEventListener(type, listener) { listeners.push({ type, listener }); },
        removeEventListener(type, listener) {
            const index = listeners.findIndex(item => item.type === type && item.listener === listener);
            if (index >= 0) listeners.splice(index, 1);
        },
        querySelector: () => ({ content: 'csrf-token' }),
        querySelectorAll: () => [],
        createElement: () => new Element(),
    };
    globalThis.fetch = (url, options = {}) => {
        if (options.method === 'POST') {
            posts.push({ url: String(url), body: JSON.parse(options.body) });
            return Promise.resolve({ ok: !fail.includes('save'), json: async () => ({ success: true }) });
        }
        const fragment = new URL(String(url)).searchParams.get('_fragment');
        requests.push(String(url));
        // a panel answers when the test lets it, so "open at once" is observable
        return new Promise(resolve => pending.push(() => resolve({ ok: !fail.includes(fragment), redirected: false, text: async () => `<div data-${fragment}>${requests.length}</div>` })));
    };
    const tv = shelf('TV', [['show', '11'], ['show', '12']]), books = shelf('Books', [['rel', '70']]);
    const zone = new Element({ 'data-drag-zone': '' }, [new Element(), ...SHELVES.map(dialogRow)]);
    const opener = new Element({ 'data-shelves-open': '' });
    const shelves = new Element({}, [tv, books]);
    const screen = new Element({ 'data-home-url': 'https://nntmux.test/', 'data-preference-url': '/profile/update-view' }, [opener, shelves, zone]);
    const component = homeShelves();
    component.$el = screen;
    component.$refs = { shelves };
    component.$nextTick = callback => callback();
    component.$watch = () => {};
    component.init();
    // lets every request that is waiting answer, and the ones those answers start
    const settle = async () => {
        for (let round = 0; round < 3; round += 1) {
            while (pending.length) pending.shift()();
            await new Promise(resolve => setTimeout(resolve));
        }
    };
    const tile = (shelfElement, index) => shelfElement.querySelectorAll('[data-tile]')[index];
    const row = name => zone.querySelector(`[data-key="${name}"]`);
    const keys = () => zone.querySelectorAll('[data-key]').map(item => item.dataset.key);
    const click = target => component.handleClick({ target });
    const key = (target, name) => {
        const event = { target, key: name, prevented: false, stopped: false, preventDefault() { event.prevented = true; }, stopPropagation() { event.stopped = true; } };
        component.handleKeydown(event);
        return event;
    };
    const emit = (type, event = {}) => listeners.filter(item => item.type === type).forEach(item => item.listener(event));
    return { component, screen, shelves, tv, books, zone, opener, body, toasts, requests, posts, settle, tile, row, keys, click, key, emit };
}

test('the arrows scroll their shelf’s rail by 80% of its width', () => {
    const { tv, books, click } = page();
    click(tv.querySelector('[data-rail="1"]'));
    click(tv.querySelector('[data-rail="-1"]'));
    assert.deepEqual(tv.querySelector('[data-rail-of]').scrolls, [{ left: 800, behavior: 'smooth' }, { left: -800, behavior: 'smooth' }]);
    assert.deepEqual(books.querySelector('[data-rail-of]').scrolls, []);
});

test('a tile is marked open at once, its panel arrives under its rail, another tile swaps it and the open tile closes it', async () => {
    const { tv, books, requests, settle, tile, click } = page();
    const first = tile(tv, 0), second = tile(tv, 1), card = tile(books, 0);
    click(first.querySelector('.tv-tile-art'));
    assert.equal(first.getAttribute('aria-expanded'), 'true', 'open before the panel arrives');
    assert.equal(tv.querySelector('[data-panel-slot]').innerHTML, '');
    await settle();
    const url = new URL(requests[0]);
    assert.equal(url.pathname, '/');
    assert.deepEqual(Object.fromEntries(url.searchParams), { shelf: 'TV', kind: 'show', id: '11', _fragment: 'panel' });
    assert.equal(tv.querySelector('[data-panel-slot]').innerHTML, '<div data-panel>1</div>');

    // one panel per page: a tile on another shelf swaps it
    click(card);
    assert.equal(first.getAttribute('aria-expanded'), 'false');
    assert.equal(card.getAttribute('aria-expanded'), 'true');
    assert.equal(tv.querySelector('[data-panel-slot]').innerHTML, '');
    await settle();
    assert.deepEqual(Object.fromEntries(new URL(requests[1]).searchParams), { shelf: 'Books', kind: 'rel', id: '70', _fragment: 'panel' });
    assert.equal(books.querySelector('[data-panel-slot]').innerHTML, '<div data-panel>2</div>');

    // a panel that arrives after another tile was opened is dropped
    click(first);
    click(second);
    await settle();
    assert.equal(first.getAttribute('aria-expanded'), 'false');
    assert.equal(second.getAttribute('aria-expanded'), 'true');
    assert.equal(tv.querySelector('[data-panel-slot]').innerHTML, '<div data-panel>4</div>');

    click(second);
    assert.equal(second.getAttribute('aria-expanded'), 'false');
    assert.equal(tv.querySelector('[data-panel-slot]').innerHTML, '');
    assert.equal(requests.length, 4);
});

test('a panel that fails to load closes its tile again and says so', async () => {
    const { tv, toasts, settle, tile, click } = page({ fail: ['panel'] });
    click(tile(tv, 0));
    assert.equal(tile(tv, 0).getAttribute('aria-expanded'), 'true');
    await settle();
    assert.equal(tile(tv, 0).getAttribute('aria-expanded'), 'false');
    assert.equal(tv.querySelector('[data-panel-slot]').innerHTML, '');
    assert.deepEqual(toasts, [{ message: 'Could not load the releases. Reload the page and try again.', type: 'error' }]);
});

test('Escape and the close button close the panel and return focus to its tile', async () => {
    const { tv, settle, tile, click, emit } = page();
    click(tile(tv, 0));
    await settle();
    emit('keydown', { key: 'Enter' });
    assert.equal(tile(tv, 0).getAttribute('aria-expanded'), 'true');
    emit('keydown', { key: 'Escape' });
    assert.equal(tile(tv, 0).getAttribute('aria-expanded'), 'false');
    assert.equal(tv.querySelector('[data-panel-slot]').innerHTML, '');
    assert.equal(tile(tv, 0).focused, 1);

    click(tile(tv, 1));
    await settle();
    const close = tv.querySelector('[data-panel-slot]').appendChild(new Element({ 'data-close-panel': '' }));
    click(close);
    assert.equal(tile(tv, 1).getAttribute('aria-expanded'), 'false');
    assert.equal(tile(tv, 1).focused, 1);
});

test('the Shelves button opens the dialog on its first checkbox once it is on screen; closing returns focus to the button', async () => {
    const { component, tv, zone, opener, row, settle, tile, click, emit } = page();
    click(tile(tv, 0));
    await settle();
    // x-show reveals the dialog a moment after `open` is set
    zone.setAttribute('hidden', '');
    click(opener);
    assert.equal(component.open, true);
    const first = row('Following').querySelector('[data-shelf-tick]');
    assert.equal(first.focused, 0, 'nothing to focus while the dialog is not on screen');
    zone.removeAttribute('hidden');
    await new Promise(resolve => setTimeout(resolve, 60));
    assert.equal(first.focused, 1);
    emit('keydown', { key: 'Escape' });
    assert.equal(tile(tv, 0).getAttribute('aria-expanded'), 'true', 'Escape belongs to the dialog');
    component.closeDialog();
    assert.equal(component.open, false);
    assert.equal(opener.focused, 1);
    component.closeDialog();
    assert.equal(opener.focused, 1, 'a closed dialog takes no focus');
});

test('a tick saves at once with the listed rows, the shelves are fetched again and the dialog stays open', async () => {
    const { component, shelves, posts, requests, toasts, row, settle, click } = page();
    click(component.$el.querySelector('[data-shelves-open]'));
    const console_ = row('Console').querySelector('[data-shelf-tick]');
    click(console_);
    assert.equal(console_.getAttribute('aria-checked'), 'true');
    await settle();
    assert.deepEqual(posts, [{ url: '/profile/update-view', body: { root: 'home', shelves: SHELVES, ticked: [...TICKED, 'Console'] } }]);
    assert.deepEqual(Object.fromEntries(new URL(requests[0]).searchParams), { _fragment: 'shelves' });
    assert.equal(shelves.innerHTML, '<div data-shelves>1</div>');
    assert.equal(component.open, true);
    assert.deepEqual(toasts, []);

    // unticking the last ticked shelf posts an empty list
    for (const name of [...TICKED, 'Console']) {
        click(row(name).querySelector('[data-shelf-tick]'));
        await settle();
    }
    assert.deepEqual(posts.at(-1).body.ticked, []);
});

test('a tick that cannot be saved is put back with the lists’ error toast and nothing is fetched', async () => {
    const { posts, requests, toasts, row, settle, click } = page({ fail: ['save'] });
    const box = row('TV').querySelector('[data-shelf-tick]');
    click(box);
    assert.equal(box.getAttribute('aria-checked'), 'false');
    await settle();
    assert.equal(posts.length, 1);
    assert.equal(box.getAttribute('aria-checked'), 'true');
    assert.deepEqual(toasts, [{ message: 'Could not save your view preference. Please try again.', type: 'error' }]);
    assert.deepEqual(requests, []);
});

test('dragging a grip lifts a ghost styled like the row, reflows the list under the pointer and saves the order on the drop', async () => {
    const { component, body, shelves, posts, requests, toasts, row, keys, settle, emit } = page();
    const adult = row('Adult'), grip = adult.querySelector('[data-grip]');
    const down = { target: grip, button: 0, clientX: 840, clientY: 100 + 7 * ROW_HEIGHT + 10, prevented: false, preventDefault() { down.prevented = true; } };
    component.startDrag(down);
    assert.equal(down.prevented, true);
    const ghost = body.children[0];
    // the ghost: the row's clone inside a carrier that wears the row list's class, at the row's place and size
    assert.equal(ghost.className, 'home-drag-ghost home-shelf-rows');
    assert.equal(ghost.children[0].className, 'home-shelf-row');
    assert.equal(ghost.children[0].hasAttribute('data-key'), false);
    assert.deepEqual(ghost.style, { width: '560px', height: '48px', left: '300px', top: 100 + 7 * ROW_HEIGHT + 'px' });
    assert.equal(adult.classList.contains('is-dragging'), true, 'the row stays in the list, faded');

    // over the upper half of TV: Adult takes the place before it, live
    emit('pointermove', { clientX: 700, clientY: 100 + ROW_HEIGHT + 5 });
    assert.deepEqual(keys(), ['Following', 'Adult', 'TV', 'Movies', 'Audio', 'Books', 'Console', 'PC', 'Other']);
    assert.deepEqual(ghost.style.left, '160px');
    assert.equal(posts.length, 0);
    // over the lower half of Movies (now the fourth row): after it
    emit('pointermove', { clientX: 700, clientY: 100 + 3 * ROW_HEIGHT + 40 });
    assert.deepEqual(keys(), ['Following', 'TV', 'Movies', 'Adult', 'Audio', 'Books', 'Console', 'PC', 'Other']);

    emit('pointerup');
    assert.equal(body.children.length, 0, 'the ghost is gone');
    assert.equal(adult.classList.contains('is-dragging'), false);
    assert.deepEqual(toasts, [], '"Order saved" only once the save succeeded');
    await settle();
    assert.deepEqual(posts, [{ url: '/profile/update-view', body: { root: 'home', shelves: ['Following', 'TV', 'Movies', 'Adult', 'Audio', 'Books', 'Console', 'PC', 'Other'] } }]);
    assert.deepEqual(toasts, [{ message: 'Order saved', type: 'success' }]);
    assert.deepEqual(Object.fromEntries(new URL(requests[0]).searchParams), { _fragment: 'shelves' });
    assert.equal(shelves.innerHTML, '<div data-shelves>1</div>');
    // the pointer listeners live only while a row is dragged
    emit('pointermove', { clientX: 0, clientY: 0 });
    assert.deepEqual(keys()[3], 'Adult');
});

test('a drop where the row started saves nothing; another mouse button and a press off the grip start no drag', async () => {
    const { component, body, posts, toasts, row, settle, emit } = page();
    const grip = row('TV').querySelector('[data-grip]');
    component.startDrag({ target: grip, button: 2, clientX: 0, clientY: 0, preventDefault() {} });
    component.startDrag({ target: row('TV').querySelector('[data-shelf-tick]'), button: 0, clientX: 0, clientY: 0, preventDefault() {} });
    assert.equal(body.children.length, 0);
    component.startDrag({ target: grip, button: 0, clientX: 840, clientY: 100 + ROW_HEIGHT + 10, preventDefault() {} });
    emit('pointercancel');
    await settle();
    assert.deepEqual(posts, []);
    assert.deepEqual(toasts, []);
    assert.equal(body.children.length, 0);
});

test('an order that cannot be saved is put back with the error toast and never reads "Order saved"', async () => {
    const { component, requests, toasts, row, keys, settle, emit } = page({ fail: ['save'] });
    component.startDrag({ target: row('Other').querySelector('[data-grip]'), button: 0, clientX: 840, clientY: 100 + 8 * ROW_HEIGHT + 10, preventDefault() {} });
    emit('pointermove', { clientX: 700, clientY: 100 + 5 });
    assert.equal(keys()[0], 'Other');
    emit('pointerup');
    await settle();
    assert.deepEqual(keys(), SHELVES);
    assert.deepEqual(toasts, [{ message: 'Could not save your view preference. Please try again.', type: 'error' }]);
    assert.deepEqual(requests, []);
});

test('on the grip Space grabs, the arrow keys move and announce, Space drops and saves, Escape puts the row back and keeps the dialog', async () => {
    const { component, posts, toasts, row, keys, settle, key } = page();
    component.open = true;
    const movies = row('Movies'), grip = movies.querySelector('[data-grip]');
    assert.equal(key(grip, 'ArrowUp').prevented, false, 'the arrow keys do nothing until the row is grabbed');
    assert.deepEqual(keys(), SHELVES);

    assert.equal(key(grip, ' ').prevented, true);
    assert.equal(grip.getAttribute('aria-pressed'), 'true');
    assert.equal(movies.classList.contains('is-grabbed'), true);
    key(grip, 'ArrowUp');
    assert.deepEqual(keys().slice(0, 3), ['Following', 'Movies', 'TV']);
    key(grip, 'ArrowUp');
    key(grip, 'ArrowUp');
    assert.deepEqual(keys().slice(0, 3), ['Movies', 'Following', 'TV'], 'the first place is the end of the way up');
    key(grip, 'ArrowDown');
    assert.deepEqual(toasts, [
        { message: 'Grabbed · arrow keys move it, Space drops it', type: 'info' },
        { message: 'Movies · position 2 of 9', type: 'info' }, { message: 'Movies · position 1 of 9', type: 'info' },
        { message: 'Movies · position 1 of 9', type: 'info' }, { message: 'Movies · position 2 of 9', type: 'info' },
    ]);
    assert.equal(posts.length, 0, 'nothing is saved until the drop');

    key(grip, ' ');
    assert.equal(grip.getAttribute('aria-pressed'), 'false');
    assert.equal(movies.classList.contains('is-grabbed'), false);
    await settle();
    assert.deepEqual(posts.map(post => post.body), [{ root: 'home', shelves: ['Following', 'Movies', 'TV', 'Audio', 'Books', 'Console', 'PC', 'Adult', 'Other'] }]);
    assert.deepEqual(toasts.at(-1), { message: 'Order saved', type: 'success' });

    // Escape: back where it was, the dialog open, nothing saved
    key(grip, 'Enter');
    key(grip, 'ArrowDown');
    key(grip, 'ArrowRight');
    assert.equal(keys()[3], 'Movies');
    const escape = key(grip, 'Escape');
    assert.deepEqual([escape.prevented, escape.stopped], [true, true], 'the dialog never sees this Escape');
    assert.deepEqual(keys(), ['Following', 'Movies', 'TV', 'Audio', 'Books', 'Console', 'PC', 'Adult', 'Other']);
    assert.equal(grip.getAttribute('aria-pressed'), 'false');
    assert.equal(component.open, true);
    await settle();
    assert.equal(posts.length, 1);
    // an Escape on a grip that holds nothing is the dialog's
    assert.equal(key(grip, 'Escape').stopped, false);

    // closing the dialog with a row still grabbed puts it back: nothing was dropped
    key(grip, ' ');
    key(grip, 'ArrowDown');
    component.closeDialog();
    assert.deepEqual(keys(), ['Following', 'Movies', 'TV', 'Audio', 'Books', 'Console', 'PC', 'Adult', 'Other']);
    assert.equal(grip.getAttribute('aria-pressed'), 'false');
    assert.equal(movies.classList.contains('is-grabbed'), false);
    await settle();
    assert.equal(posts.length, 1);
});

test('the component is registered for the page and its styles carry the prototype’s sizes', () => {
    const loader = readFileSync(new URL('../../resources/js/alpine/lazy-loader.js', import.meta.url), 'utf8');
    assert.match(loader, /'homeShelves':\s+\(\) => import\('\.\/components\/home-shelves\.js'\)/);
    const css = readFileSync(new URL('../../resources/css/tv.css', import.meta.url), 'utf8');
    assert.match(css, /\.home-tile \{[^}]*flex: 0 0 148px;[^}]*width: 148px;/, 'tiles are 148 px wide');
    assert.match(css, /\.home-rail \{[^}]*gap: 16px;[^}]*overflow-x: auto;[^}]*scroll-snap-type: x mandatory;/);
    assert.match(css, /\.home-tile\.is-picture \{ flex-basis: 236px; width: 236px; \}/, 'the Adult picture tiles are 236 px');
    assert.match(css, /\.home-tile\.is-picture \.tv-tile-art \{ aspect-ratio: 16 \/ 9; \}/);
    assert.match(css, /\.home-tile\.is-album \.tv-tile-art \{ aspect-ratio: 1; \}/, 'album tiles are square');
    assert.match(css, /\.home-tile\.is-card \.tv-tile-art \{ height: 150px;/, 'release cards are 150 px tall');
    assert.match(css, /\.home-tile\.is-faded \.tv-tile-art \{ opacity: 0\.55; \}/, 'a title with nothing new is faded, never hidden');
    assert.match(css, /\.home-tile\[aria-expanded="true"\] \.tv-tile-art \{[^}]*outline: 3px solid var\(--tv-ink\);/, 'the open tile carries a 3 px ink outline');
    assert.match(css, /\.home-panel \{[^}]*box-shadow: var\(--tv-shadow\);/, 'the panel floats');
    assert.match(css, /\.home-col-category \{ width: 110px; \}/);
    assert.match(css, /\.tv-feed\.is-home-panel\.is-mixed \.home-col-category \{ width: 190px; \}/);
    assert.match(css, /\.tv-dialog\.is-shelves \{ width: min\(620px, 100%\); \}/);
    // the row's styles hang on the row list's class, which the ghost's carrier wears too
    assert.match(css, /\.home-shelf-rows \.home-shelf-row \{[^}]*height: 48px;/);
    assert.match(css, /\.home-drag-ghost \{[^}]*position: fixed;[^}]*box-shadow: var\(--tv-shadow\);[^}]*pointer-events: none;[^}]*transform: rotate\(0\.6deg\) scale\(1\.01\);/);
    assert.match(css, /\.home-shelf-rows \.home-shelf-row\.is-dragging \{ opacity: 0\.28;/);
    assert.match(css, /\.home-grip\[aria-pressed="true"\] \{ background: var\(--tv-ink\); color: var\(--tv-ground\); \}/, 'a grabbed grip fills in ink');
    assert.match(css, /\.home-shelf-check\[aria-checked="true"\] \.home-shelf-box \{[^}]*background: var\(--tv-accent\);/, 'the coral box when ticked');
});
