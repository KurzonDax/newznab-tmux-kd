import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { checkboxMenu } from '../../resources/js/alpine/components/checkbox-menu-component.js';
import { tvReleases } from '../../resources/js/alpine/components/tv-releases-component.js';

function attributes(initial = {}) {
    const values = { ...initial };
    return {
        values,
        getAttribute: name => values[name] ?? null,
        setAttribute(name, value) { values[name] = String(value); },
    };
}

function menu(ticked = []) {
    const items = ['4k', '1080p', '720p'].map((value, index) => ({
        ...attributes({ 'aria-checked': ticked.includes(value) ? 'true' : 'false' }),
        dataset: { value, text: ['4K', '1080p', '720p'][index] },
    }));
    const any = attributes({ 'aria-checked': ticked.length ? 'false' : 'true' });
    const events = [], classes = new Set(ticked.length ? ['is-set'] : []);
    const root = {
        dataset: { name: 'resolution', label: 'Resolution' },
        querySelectorAll: selector => (selector === '[data-value]' ? items : []),
        querySelector: selector => (selector === '[data-any]' ? any : null),
        classList: { toggle: (name, on) => (on ? classes.add(name) : classes.delete(name)) },
        dispatchEvent: event => events.push(event),
        contains: node => node === items[0],
    };
    const component = checkboxMenu();
    component.$el = root;
    component.$refs = { summary: { textContent: '' }, button: { focused: false, focus() { this.focused = true; } } };
    component.init();
    return { component, items, any, events, classes };
}

test('ticking keeps the menu open, ORs the values in menu order and turns the button coral', () => {
    const { component, items, any, events, classes } = menu();
    component.open = true;
    component.pick({ currentTarget: items[1] });
    component.pick({ currentTarget: items[0] });
    assert.equal(component.open, true);
    assert.equal(component.$refs.summary.textContent, 'Resolution: 4K, 1080p');
    assert.equal(any.getAttribute('aria-checked'), 'false');
    assert.ok(classes.has('is-set'));
    assert.deepEqual(events.at(-1).detail, { name: 'resolution', values: ['4k', '1080p'] });
    assert.equal(events.at(-1).bubbles, true);

    component.pick({ currentTarget: items[1] });
    assert.deepEqual(events.at(-1).detail.values, ['4k']);
    component.clear();
    assert.equal(component.$refs.summary.textContent, 'Resolution: any');
    assert.equal(any.getAttribute('aria-checked'), 'true');
    assert.ok(!classes.has('is-set'));
    assert.deepEqual(events.at(-1).detail.values, []);
});

test('Escape closes and returns focus, focus leaving closes, focus moving inside does not', () => {
    const { component, items } = menu(['720p']);
    component.open = true;
    component.focusLeft({ relatedTarget: items[0] });
    assert.equal(component.open, true);
    component.focusLeft({ relatedTarget: { outside: true } });
    assert.equal(component.open, false);
    component.open = true;
    component.closeAndFocus();
    assert.equal(component.open, false);
    assert.equal(component.$refs.button.focused, true);
});

function row(guid, hidden = false) {
    const tr = { hidden, dataset: {} };
    return { value: guid, checked: false, closest: () => tr, tr };
}

function screen(guids, { batchRows = [] } = {}) {
    const boxes = guids.map(guid => row(guid));
    batchRows.forEach(guid => { const box = row(guid, true); box.tr.dataset.batch = 'run'; boxes.push(box); });
    const header = { checked: false, indeterminate: false, ...attributes() };
    const cartButtons = guids.map(guid => ({ dataset: { cart: guid }, ...attributes({ 'aria-pressed': 'false' }) }));
    const root = {
        dataset: { nzbLinkBase: 'https://nntmux.test/api/v1/api', apiToken: 'secret-key', preferenceUrl: '/profile/update-view' },
        querySelectorAll(selector) {
            if (selector === '[data-select]') return boxes;
            if (selector === '[data-batch]') return boxes.filter(box => box.tr.dataset.batch).map(box => box.tr);
            if (selector === '[data-cart]') return cartButtons;
            return [];
        },
        querySelector: selector => (selector === '[data-select-all]' ? header : null),
    };
    const component = tvReleases();
    component.$el = root;
    component.$refs = { list: { innerHTML: '' } };
    component.$store = { cart: { count: 0, setCount(count) { this.count = count; } } };
    component.init();
    return { component, boxes, header, cartButtons };
}

function browser({ href = 'https://nntmux.test/tv', secure = true, clipboard = true } = {}) {
    const toasts = [], requests = [], history = [], copied = [];
    globalThis.window = {
        isSecureContext: secure,
        location: { href, assign: value => history.push(['assign', value]) },
        history: { replaceState: (state, title, url) => history.push(['replace', url]) },
        showToast: (message, type) => toasts.push({ message, type }),
    };
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value: clipboard ? { clipboard: { writeText: async text => copied.push(text) } } : {} });
    globalThis.document = {
        querySelector: () => ({ content: 'csrf-token' }),
        createElement: () => ({ setAttribute() {}, select() {}, remove() {} }),
        body: { appendChild() {} },
        execCommand: () => { copied.push('legacy'); return true; },
    };
    globalThis.fetch = async (url, options = {}) => {
        requests.push({ url: String(url), ...options });
        return { ok: true, redirected: false, json: async () => ({ success: true, cartCount: 7 }), text: async () => '<nav>new list</nav>' };
    };
    return { toasts, requests, history, copied };
}

test('select all ticks the visible rows only and one unticked row leaves the partial state', () => {
    browser();
    const { component, boxes, header } = screen(['a', 'b', 'c'], { batchRows: ['d'] });
    component.handleChange({ target: { checked: true, matches: selector => selector === '[data-select-all]' } });
    assert.deepEqual(boxes.map(box => box.checked), [true, true, true, false]);
    assert.equal(component.selectedCount, 3);
    assert.equal(header.checked, true);
    assert.equal(header.getAttribute('aria-label'), 'Clear selection on this page');

    boxes[0].checked = false;
    component.handleChange({ target: { matches: selector => selector === '[data-select]' } });
    assert.equal(header.indeterminate, true);
    assert.equal(header.checked, false);
    component.clearSelection();
    assert.equal(component.selectedCount, 0);
    assert.equal(header.indeterminate, false);
});

test('the batch expander opens the hidden rows in place and closes them again', () => {
    browser();
    const { component, boxes } = screen(['a'], { batchRows: ['d', 'e'] });
    const label = { textContent: 'Show 2 more from Glass Meridian posted in the same batch' };
    const button = { dataset: { expand: 'run', labelOpen: 'Show fewer from Glass Meridian', labelClosed: label.textContent }, ...attributes({ 'aria-expanded': 'false' }), querySelector: () => label };
    component.handleClick({ target: { closest: selector => (selector === '[data-expand]' ? button : null) } });
    assert.deepEqual(boxes.map(box => box.tr.hidden), [false, false, false]);
    assert.equal(button.getAttribute('aria-expanded'), 'true');
    assert.equal(label.textContent, 'Show fewer from Glass Meridian');
    component.toggleBatch(button);
    assert.deepEqual(boxes.map(box => box.tr.hidden), [false, true, true]);
    assert.equal(label.textContent, 'Show 2 more from Glass Meridian posted in the same batch');
});

test('copy NZB link copies the v1 get call with the guid and api key, over http too', async () => {
    for (const secure of [true, false]) {
        const { copied, toasts } = browser({ secure });
        const { component } = screen(['a']);
        const icon = { classes: new Set(['fa-link']), classList: { replace(from, to) { if (icon.classes.delete(from)) icon.classes.add(to); } } };
        await component.copyLink({ dataset: { copyNzb: 'abc123' }, querySelector: () => icon });
        assert.equal(component.nzbLink('abc123'), 'https://nntmux.test/api/v1/api?t=get&id=abc123&apikey=secret-key');
        assert.deepEqual(copied, [secure ? 'https://nntmux.test/api/v1/api?t=get&id=abc123&apikey=secret-key' : 'legacy']);
        assert.match(toasts[0].message, /^NZB link copied\. It contains your API key/);
        assert.ok(icon.classes.has('fa-check'));
    }
});

test('a filter change replaces the URL on page 1 and reloads only the list', async () => {
    const { history, requests } = browser({ href: 'https://nntmux.test/tv?resolution%5B0%5D=720p&source%5B%5D=web&page=4' });
    const { component } = screen(['a']);
    await component.applyFilter({ detail: { name: 'resolution', values: ['4k', '1080p'] } });
    const url = new URL(history[0][1]);
    assert.deepEqual(url.searchParams.getAll('resolution[]'), ['4k', '1080p']);
    assert.equal(url.searchParams.get('resolution[0]'), null);
    assert.deepEqual(url.searchParams.getAll('source[]'), ['web']);
    assert.equal(url.searchParams.get('page'), null);
    assert.equal(new URL(requests[0].url).searchParams.get('_fragment'), 'list');
    assert.equal(component.$refs.list.innerHTML, '<nav>new list</nav>');
});

test('changing the sort saves the preference and returns to page 1', async () => {
    const { history, requests } = browser({ href: 'https://nntmux.test/tv?source%5B%5D=web&page=3' });
    const { component } = screen(['a']);
    await component.changeSort({ target: { value: 'oldest' } });
    assert.deepEqual(JSON.parse(requests[0].body), { root: 'tv', sort: 'oldest' });
    assert.equal(requests[0].url, '/profile/update-view');
    assert.equal(history[0][1], 'https://nntmux.test/tv?source%5B%5D=web');
});

test('cart buttons toggle one release and the bar adds the whole selection', async () => {
    const { requests } = browser();
    const { component, boxes, cartButtons } = screen(['a', 'b']);
    await component.toggleCart(cartButtons[0]);
    assert.equal(requests[0].url, '/cart/add');
    assert.equal(cartButtons[0].getAttribute('aria-pressed'), 'true');
    assert.equal(component.$store.cart.count, 7);
    await component.toggleCart(cartButtons[0]);
    assert.equal(requests[1].url, '/cart/delete/a');
    assert.equal(cartButtons[0].getAttribute('aria-pressed'), 'false');

    boxes.forEach(box => { box.checked = true; });
    component.selectionChanged();
    await component.addSelectedToCart();
    assert.deepEqual(JSON.parse(requests[2].body), { id: 'a,b' });
    assert.deepEqual(cartButtons.map(button => button.getAttribute('aria-pressed')), ['true', 'true']);
    assert.equal(component.selectedCount, 0);
});

function cssRules(file) {
    const rules = new Map();
    for (const [, selectors, body] of readFileSync(new URL(file, import.meta.url), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
        for (const selector of selectors.split(',').map(part => part.trim().replace(/\s+/g, ' '))) {
            rules.set(selector, [rules.get(selector) ?? '', body.trim()].filter(Boolean).join(' '));
        }
    }
    return rules;
}

test('row buttons sit 2 × 2 and Copy link, Cart and Follow each keep their own hue, pressed too', () => {
    const tv = cssRules('../../resources/css/tv.css'), app = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    assert.match(tv.get('.tv-actions'), /display: grid; grid-template-columns: repeat\(2, 32px\); gap: 6px; justify-content: end;/);
    assert.match(tv.get('.tv-action-slot'), /display: none;/);
    assert.match(tv.get('.tv-col-actions'), /width: 96px;/);
    assert.equal(tv.get('.tv-col-grabs'), undefined);

    const off = '.tv-actions .tv-action:not(.tv-action-download)', pressed = ['.tv-actions .tv-action[aria-pressed="true"]', '.tv-actions .tv-action[data-watched="1"]'];
    assert.match(tv.get(off), /background: var\(--tv-action-bg\); color: var\(--tv-action-fg\);/);
    assert.match(tv.get(`${off}:hover`), /background: var\(--tv-action-hover-bg\);/);
    for (const selector of pressed) {
        assert.match(tv.get(selector), /background: var\(--tv-action-on-bg\); color: var\(--tv-action-on-fg\);/);
        assert.match(tv.get(`${selector}:hover`), /background: var\(--tv-action-on-hover-bg\);/);
    }
    for (const [selector, body] of tv) {
        if (/aria-pressed|data-watched/.test(selector) && selector.includes('tv-action')) assert.doesNotMatch(body, /accent/, selector);
    }

    const hues = { copy: 235, cart: 150, follow: 300 }, token = name => app.match(new RegExp(`${name}: ([^;]+);`))?.[1];
    for (const [button, kind, states] of [['[data-copy-nzb]', 'copy', ['bg', 'fg', 'hover-bg']], ['[data-cart]', 'cart', ['bg', 'fg', 'hover-bg', 'on-bg', 'on-fg', 'on-hover-bg']], ['[data-watch-picker]', 'follow', ['bg', 'fg', 'hover-bg', 'on-bg', 'on-fg', 'on-hover-bg']]]) {
        for (const state of states) {
            assert.match(tv.get(`.tv-actions ${button}`), new RegExp(`--tv-action-${state}: var\\(--row-action-${kind}-${state}\\);`));
            assert.match(tv.get(`.dark .tv-actions ${button}`), new RegExp(`--tv-action-${state}: var\\(--row-action-${kind}-${state}-dark\\);`));
            for (const name of [`--row-action-${kind}-${state}`, `--row-action-${kind}-${state}-dark`]) {
                const value = token(name);
                assert.ok(value === '#ffffff' || value?.endsWith(` ${hues[kind]})`), `${name} is in the ${kind} hue: ${value}`);
            }
        }
    }
    assert.equal(token('--row-action-cart-on-bg'), 'oklch(0.50 0.15 150)');
    assert.equal(token('--row-action-cart-on-bg-dark'), 'oklch(0.72 0.15 150)');
    assert.equal(token('--row-action-follow-on-bg-dark'), 'oklch(0.72 0.15 300)');
    assert.match(tv.get(`${off}:focus-visible`), /outline-color: var\(--tv-ink\);/);
    assert.match(tv.get('.tv-action-download'), /background: var\(--tv-accent\); color: var\(--tv-accent-on\);/);
});
