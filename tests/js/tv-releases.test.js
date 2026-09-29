import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { checkboxMenu } from '../../resources/js/alpine/components/checkbox-menu-component.js';
import { filterUrl } from '../../resources/js/alpine/components/tv-list.js';
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
    component.$refs = { value: valueRef(), button: { focused: false, focus() { this.focused = true; } } };
    component.init();
    return { component, items, any, events, classes };
}

function valueRef() {
    const classes = new Set(['is-any']);
    return { textContent: 'any', classes, classList: { toggle: (name, on) => (on ? classes.add(name) : classes.delete(name)) } };
}

test('ticking keeps the menu open, ORs the values in menu order and turns the button coral', () => {
    const { component, items, any, events, classes } = menu();
    component.open = true;
    component.pick({ currentTarget: items[1] });
    component.pick({ currentTarget: items[0] });
    assert.equal(component.open, true);
    assert.equal(component.$refs.value.textContent, '4K, 1080p');
    assert.ok(!component.$refs.value.classes.has('is-any'));
    assert.equal(any.getAttribute('aria-checked'), 'false');
    assert.ok(classes.has('is-set'));
    assert.deepEqual(events.at(-1).detail, { name: 'resolution', values: ['4k', '1080p'], single: false });
    assert.equal(events.at(-1).bubbles, true);

    component.pick({ currentTarget: items[1] });
    assert.deepEqual(events.at(-1).detail.values, ['4k']);
    component.clear();
    assert.equal(component.$refs.value.textContent, 'any');
    assert.ok(component.$refs.value.classes.has('is-any'));
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

function screen(guids, { batchRows = [], clock } = {}) {
    const boxes = guids.map(guid => row(guid));
    batchRows.forEach(guid => { const box = row(guid, true); box.tr.dataset.batch = 'run'; boxes.push(box); });
    const header = { checked: false, indeterminate: false, ...attributes() };
    const cartButtons = guids.map(guid => ({ dataset: { cart: guid }, ...attributes({ 'aria-pressed': 'false' }) }));
    const root = {
        dataset: { nzbLinkBase: 'https://nntmux.test/api/v1/api', apiToken: 'secret-key', preferenceUrl: '/profile/update-view', ...(clock === undefined ? {} : { filtersClock: String(clock) }) },
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

test('a filter change after a bare open keeps the remembered filters in the address bar and the refresh, stamped in order', async () => {
    // #881: the bare open redirected to the URL carrying the remembered Category and Resolution
    const { history, requests } = browser({ href: 'https://nntmux.test/tv?category%5B0%5D=5040&resolution%5B0%5D=1080p' });
    const { component } = screen(['a'], { clock: 1_000_000 });
    await component.applyFilter({ detail: { name: 'audio', values: ['1'] } });
    const kept = url => [url.searchParams.getAll('category[0]'), url.searchParams.getAll('resolution[0]'), url.searchParams.getAll('audio[]')];
    const address = new URL(history[0][1]), refresh = new URL(requests[0].url);
    assert.deepEqual(kept(address), [['5040'], ['1080p'], ['1']]);
    assert.deepEqual(kept(refresh), [['5040'], ['1080p'], ['1']]);
    assert.equal(address.searchParams.has('_filters_at'), false);
    assert.equal(refresh.searchParams.get('_fragment'), 'list');
    const first = Number(refresh.searchParams.get('_filters_at'));
    assert.ok(first >= 1_000_000);

    // unticking the only Audio value refreshes without it; each refresh is stamped later than the one before
    await component.applyFilter({ detail: { name: 'audio', values: [] } });
    const second = new URL(requests[1].url);
    assert.deepEqual(kept(second), [['5040'], ['1080p'], []]);
    assert.ok(Number(second.searchParams.get('_filters_at')) > first);
});

test('without a clock on the page a refresh carries no time and the server uses its own', async () => {
    const { requests } = browser({ href: 'https://nntmux.test/tv' });
    const { component } = screen(['a']);
    await component.applyFilter({ detail: { name: 'source', values: ['web'] } });
    assert.equal(new URL(requests[0].url).searchParams.has('_filters_at'), false);
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

/** A bar cell's menu (check.mjs 379-419): items with text, an optional search field and a scrolling panel. */
function cell({ name = 'audio', label = 'Audio', values = ['1', '2', '3'], texts = ['English', 'Korean', 'Japanese'], ticked = [], single = false, short = {}, search = false } = {}) {
    const items = values.map((value, index) => ({
        ...attributes({ 'aria-checked': ticked.includes(value) ? 'true' : 'false' }), hidden: false,
        dataset: { value, text: texts[index], ...(short[value] ? { short: short[value] } : {}) }, offsetTop: 62 + index * 40, offsetHeight: 40,
    }));
    const any = attributes({ 'aria-checked': ticked.length ? 'false' : 'true' });
    const events = [], classes = new Set(['checkbox-menu', 'is-cell']);
    const root = {
        dataset: { name, label, summary: 'count', ...(single ? { single: 'true' } : {}) },
        querySelectorAll: selector => (selector === '[data-value]' ? items : []),
        querySelector: selector => (selector === '[data-any]' ? any : null),
        classList: { toggle: (className, on) => (on ? classes.add(className) : classes.delete(className)), contains: className => classes.has(className) },
        dispatchEvent: event => events.push(event),
        contains: () => true,
    };
    const component = checkboxMenu();
    component.$el = root;
    component.$nextTick = callback => callback();
    const focused = [];
    component.$refs = {
        value: valueRef(),
        button: { ...attributes({ title: '' }), focus() { focused.push('button'); } },
        panel: { scrollTop: 0, clientHeight: 402, style: {}, getBoundingClientRect: () => ({ right: 400 }) },
        ...(search ? { search: { value: '', parentElement: { offsetHeight: 56 }, focus() { focused.push('search'); } } } : {}),
    };
    component.init();
    return { component, items, any, events, classes, focused };
}

test('the open cell is marked open for the lift and the hidden hairlines, and loses it on every way of closing', () => {
    globalThis.document = { documentElement: { clientWidth: 1600 } };
    const { component, classes, focused } = cell();
    component.toggle();
    assert.ok(classes.has('is-open'));
    assert.deepEqual(focused, [], 'a short menu has no search field to focus');
    component.close();
    assert.ok(!classes.has('is-open'));
    component.toggle();
    component.focusLeft({ relatedTarget: null });
    assert.ok(classes.has('is-open'), 'focus leaving the window keeps it open');
    component.closeAndFocus();
    assert.ok(!classes.has('is-open'));
});

test('Completion is one choice: a radio pick reads 95%+, closes the menu, returns focus and sends one value', () => {
    const { component, items, any, events, classes, focused } = cell({
        name: 'completion', label: 'Completion', values: ['100', '95'], texts: ['100% only', '95% or more'], single: true, short: { 100: '100%', 95: '95%+' },
    });
    component.toggle();
    component.pick({ currentTarget: items[1] });
    assert.equal(component.open, false);
    assert.deepEqual(focused, ['button']);
    assert.equal(component.$refs.value.textContent, '95%+');
    assert.equal(component.$refs.button.getAttribute('title'), 'Completion: 95% or more');
    assert.deepEqual(items.map(item => item.getAttribute('aria-checked')), ['false', 'true']);
    assert.equal(any.getAttribute('aria-checked'), 'false');
    assert.ok(classes.has('is-set'));
    assert.deepEqual(events.at(-1).detail, { name: 'completion', values: ['95'], single: true });

    component.toggle();
    component.pick({ currentTarget: items[0] });
    assert.deepEqual(items.map(item => item.getAttribute('aria-checked')), ['true', 'false']);
    assert.equal(component.$refs.value.textContent, '100%');
    component.toggle();
    component.pick({ currentTarget: items[0] });
    assert.equal(events.length, 2, 'picking the ticked choice again changes nothing');
    assert.equal(component.open, false);

    component.toggle();
    component.clear();
    assert.equal(component.open, false);
    assert.equal(component.$refs.value.textContent, 'any');
    assert.ok(!classes.has('is-set'));
    assert.deepEqual(events.at(-1).detail.values, []);
});

test('a long menu opens on its search field, which narrows in place, survives ticking and is forgotten on close', () => {
    globalThis.document = { documentElement: { clientWidth: 1600 } };
    const { component, items, focused } = cell({ search: true });
    component.toggle();
    assert.deepEqual(focused, ['search']);
    component.$refs.search.value = ' KOR ';
    component.narrow();
    assert.deepEqual(items.map(item => item.hidden), [true, false, true]);
    component.pick({ currentTarget: items[1] });
    assert.equal(component.open, true);
    assert.equal(component.$refs.search.value, ' KOR ');
    assert.deepEqual(items.map(item => item.hidden), [true, false, true]);
    assert.equal(component.$refs.value.textContent, 'Korean');

    component.closeAndFocus();
    assert.equal(component.$refs.search.value, '');
    assert.deepEqual(items.map(item => item.hidden), [false, false, false]);
});

test('a menu opens scrolled to its first ticked option, just under the search field', () => {
    globalThis.document = { documentElement: { clientWidth: 1600 } };
    const values = Array.from({ length: 20 }, (_, index) => String(index + 1));
    const { component, items } = cell({ values, texts: values.map(value => 'Language ' + value), ticked: ['15', '18'], search: true });
    component.toggle();
    assert.equal(component.$refs.panel.scrollTop, items[14].offsetTop - 56 - 6);

    const short = cell({ values, texts: values, ticked: ['2'], search: true });
    short.component.toggle();
    assert.equal(short.component.$refs.panel.scrollTop, 0, 'a ticked option already in view does not scroll');
});

test('a Completion change writes one completion value, clearing it drops it, and the page is dropped', async () => {
    const { history } = browser({ href: 'https://nntmux.test/tv?completion=100&audio%5B%5D=unknown&page=4' });
    const { component } = screen(['a']);
    await component.applyFilter({ detail: { name: 'completion', values: ['95'], single: true } });
    let url = new URL(history.at(-1)[1]);
    assert.equal(url.searchParams.get('completion'), '95');
    assert.deepEqual(url.searchParams.getAll('completion[]'), []);
    assert.deepEqual(url.searchParams.getAll('audio[]'), ['unknown']);
    assert.equal(url.searchParams.get('page'), null);

    url = filterUrl(url.toString(), 'completion', [], true);
    assert.equal(url.searchParams.has('completion'), false);
    assert.deepEqual(url.searchParams.getAll('audio[]'), ['unknown']);
});

test('the bar look lives only inside the bar: equal cells, name above value, coral line under a set value, never a fill', () => {
    const tv = cssRules('../../resources/css/tv.css'), app = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    assert.match(tv.get('.filter-bar'), /display: flex;.*height: 56px; padding: 4px; border-radius: 18px; background: var\(--tv-panel-alt\);/);
    assert.match(tv.get('.tv-bar-list .filter-bar.is-release'), /flex: 5 1 0;/);
    assert.match(tv.get('.tv-bar-list .filter-bar.is-show'), /flex: 6 1 0;/);
    assert.match(tv.get('.tv-bar-wall .filter-bar.is-show'), /flex: 0 0 calc\(\(100% - 12px\) \* 6 \/ 11\);/);
    assert.match(tv.get('.filter-bar .checkbox-menu'), /flex: 1 1 0; min-width: 0;/);
    assert.match(tv.get('.filter-bar .checkbox-menu-button'), /height: 48px; padding: 0 10px 0 14px; border-radius: 14px; background: transparent;/);
    assert.match(tv.get('.filter-bar .checkbox-menu-label'), /flex-direction: column;/);
    assert.match(tv.get('.filter-bar .checkbox-menu-sep'), /display: none;/);
    assert.match(tv.get('.filter-bar .checkbox-menu-name'), /color: var\(--tv-filter-name\); font-size: 11.5px; font-weight: 500;/);
    assert.match(tv.get('.filter-bar .checkbox-menu-value.is-any'), /color: var\(--tv-dim\); font-weight: 500;/);
    assert.match(tv.get('.filter-bar .checkbox-menu.is-set .checkbox-menu-button'), /background: transparent; color: var\(--tv-ink\);/);
    assert.match(tv.get('.filter-bar .checkbox-menu.is-set .checkbox-menu-button::after'), /right: 14px; bottom: 3px; left: 14px; height: 3px; border-radius: 2px; background: var\(--tv-accent\);/);
    assert.match(tv.get('.filter-bar .checkbox-menu.is-open .checkbox-menu-button'), /background: var\(--tv-raise\); box-shadow: var\(--tv-shadow\);/);
    assert.match(tv.get('.filter-bar .checkbox-menu.is-open + .checkbox-menu::before'), /opacity: 0;/);
    assert.match(tv.get('.filter-bar .checkbox-menu-panel'), /top: 58px;.*min-width: max\(100%, 230px\);/);
    assert.match(tv.get('.filter-bar .checkbox-menu-panel.is-searchable'), /max-height: 402px;/);
    assert.match(tv.get('.checkbox-menu-search'), /position: sticky; top: 0;/);
    for (const [selector, body] of tv) {
        if (/is-open|::after|is-cell|checkbox-menu-name|checkbox-menu-sep/.test(selector) && selector.includes('checkbox-menu') && !selector.includes('checkbox-menu-dot')) {
            assert.ok(selector.startsWith('.filter-bar '), `${selector} is scoped to the bar`);
        }
        if (selector.startsWith('.filter-bar') && selector.includes('is-set') && !selector.endsWith('::after')) assert.doesNotMatch(body, /var\(--tv-accent\)/, selector);
    }
    assert.match(tv.get('.dark'), /--tv-filter-name: var\(--text-filter-name-dark\);/);
    assert.match(tv.get(':root'), /--tv-filter-name: var\(--text-filter-name\);/);
    assert.match(app, /--text-filter-name: #17181c;/);
    assert.match(app, /--text-filter-name-dark: #ffffff;/);
    assert.match(app, /--surface-raised: #ffffff;/);
    assert.match(app, /--surface-raised-dark: #2a2e39;/);
    assert.match(tv.get('.pager-line.is-fixed .pager-line-page'), /min-width: calc\(13ch \+ 20px\); text-align: center;/);
    assert.match(tv.get('.pager-line a.pager-line-clear'), /width: 64px;.*margin-right: 12px;/);
    assert.match(tv.get('.pager-line-clear.is-hidden'), /visibility: hidden;/);
});

/** The releases list's Category cell with its "Exclude Other" item (issue #886): HD, SD, Other. */
function categoryCell(ticked = []) {
    const items = [['5040', 'HD'], ['5030', 'SD'], ['5999', 'Other']].map(([value, text]) => ({
        ...attributes({ 'aria-checked': ticked.includes(value) ? 'true' : 'false' }), hidden: false, dataset: { value, text },
    }));
    const any = attributes({ 'aria-checked': ticked.length ? 'false' : 'true' });
    const exclude = { ...attributes({ 'aria-checked': 'false' }), dataset: { excludeOther: '5999', mode: 'exclude-other' } };
    const events = [], classes = new Set(['checkbox-menu', 'is-cell']);
    const root = {
        dataset: { name: 'category', label: 'Category', summary: 'count' },
        querySelectorAll: selector => (selector === '[data-value]' ? items : []),
        querySelector: selector => ({ '[data-any]': any, '[data-exclude-other]': exclude })[selector] ?? null,
        classList: { toggle: (className, on) => (on ? classes.add(className) : classes.delete(className)), contains: className => classes.has(className) },
        dispatchEvent: event => events.push(event),
        contains: () => true,
    };
    const component = checkboxMenu();
    component.$el = root;
    component.$refs = { value: valueRef(), button: { ...attributes({ title: '' }), focus() {} } };
    component.init();
    component.open = true;
    return { component, items, any, exclude, events, classes };
}

test('Exclude Other ticks every category but Other, reads "Exclude Other" and sends the mode, not the ids', () => {
    const { component, items, any, exclude, events, classes } = categoryCell();
    component.excludeOther();
    assert.deepEqual(items.map(item => item.getAttribute('aria-checked')), ['true', 'true', 'false']);
    assert.equal(exclude.getAttribute('aria-checked'), 'true');
    assert.equal(any.getAttribute('aria-checked'), 'false');
    assert.equal(component.$refs.value.textContent, 'Exclude Other');
    assert.equal(component.$refs.button.getAttribute('title'), 'Category: Exclude Other');
    assert.ok(classes.has('is-set'));
    assert.equal(component.open, true);
    assert.deepEqual(events.at(-1).detail, { name: 'category', values: ['exclude-other'], single: true });

    // ticking Other as well is the explicit list; picking Exclude Other then sets the mode again
    component.pick({ currentTarget: items[2] });
    assert.equal(component.$refs.value.textContent, '3 chosen');
    assert.equal(exclude.getAttribute('aria-checked'), 'false');
    assert.deepEqual(events.at(-1).detail, { name: 'category', values: ['5040', '5030', '5999'], single: false });
    component.excludeOther();
    assert.deepEqual(events.at(-1).detail.values, ['exclude-other']);

    // picking it again while it is set clears the Category filter
    component.excludeOther();
    assert.deepEqual(items.map(item => item.getAttribute('aria-checked')), ['false', 'false', 'false']);
    assert.equal(exclude.getAttribute('aria-checked'), 'false');
    assert.equal(any.getAttribute('aria-checked'), 'true');
    assert.equal(component.$refs.value.textContent, 'any');
    assert.ok(!classes.has('is-set'));
    assert.deepEqual(events.at(-1).detail, { name: 'category', values: [], single: false });
});

test('ticking every category but Other by hand becomes Exclude Other; unticking one while it is set is the explicit list', () => {
    const { component, items, exclude, events } = categoryCell(['5040']);
    component.pick({ currentTarget: items[1] });
    assert.equal(component.$refs.value.textContent, 'Exclude Other');
    assert.equal(exclude.getAttribute('aria-checked'), 'true');
    assert.deepEqual(events.at(-1).detail.values, ['exclude-other']);

    component.pick({ currentTarget: items[0] });
    assert.equal(component.$refs.value.textContent, 'SD');
    assert.equal(exclude.getAttribute('aria-checked'), 'false');
    assert.deepEqual(events.at(-1).detail, { name: 'category', values: ['5030'], single: false });
});

test('the Exclude Other mode is one category value in the URL, replacing the ticked ids, and the page is dropped', async () => {
    const { history } = browser({ href: 'https://nntmux.test/movies?category%5B0%5D=2040&category%5B1%5D=2030&resolution%5B%5D=1080p&page=3' });
    const { component } = screen(['a']);
    await component.applyFilter({ detail: { name: 'category', values: ['exclude-other'], single: true } });
    const url = new URL(history.at(-1)[1]);
    assert.equal(url.searchParams.get('category'), 'exclude-other');
    assert.deepEqual([...url.searchParams.keys()].filter(key => key.startsWith('category')), ['category']);
    assert.deepEqual(url.searchParams.getAll('resolution[]'), ['1080p']);
    assert.equal(url.searchParams.get('page'), null);
    assert.deepEqual(filterUrl(url.toString(), 'category', ['2040'], false).searchParams.getAll('category[]'), ['2040']);
    assert.equal(filterUrl(url.toString(), 'category', ['2040'], false).searchParams.has('category'), false);
});

test('the Exclude Other separator mixes from the ink, since the line colour matches the raised menu ground in dark', () => {
    const tv = cssRules('../../resources/css/tv.css');
    assert.match(tv.get('.checkbox-menu-rule.is-ink'), /background: color-mix\(in oklab, var\(--tv-ink\) 16%, transparent\);/);
    assert.match(tv.get('.checkbox-menu-rule'), /background: var\(--tv-line\);/);
});
