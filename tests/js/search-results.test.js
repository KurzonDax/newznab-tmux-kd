import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test, { mock } from 'node:test';
import { checkboxMenu } from '../../resources/js/alpine/components/checkbox-menu-component.js';
import { publicNavigation } from '../../resources/js/alpine/components/public-navigation-component.js';
import { searchFeed, searchFilters } from '../../resources/js/alpine/components/search-filters-component.js';
import { tvReleases } from '../../resources/js/alpine/components/tv-releases-component.js';

// Search results on the generic list (issue #1050): what lives in the browser. The Category cell writes the
// search's scope (`t`, the ticked roots comma separated) and reloads the list in place, the header's scope picker
// following; leaving a legacy scope redraws the page; "+ Add filter" opens on its list, swaps to a filter's form and
// closes on Escape, a click outside and focus leaving; the feed dialog opens from the heading row's button, copies
// the feed URL and returns focus.

function attributes(initial = {}) {
    const values = { ...initial };
    return { values, getAttribute: name => values[name] ?? null, setAttribute(name, value) { values[name] = String(value); } };
}

const ROOTS = [['2000', 'Movies'], ['5000', 'TV'], ['3000', 'Audio'], ['1', 'Other']];

/** A search's Category cell: the roots, "Any category" and "Exclude Other", as x-checkbox-menu renders them. */
function categoryCell({ ticked = [], cellText = false } = {}) {
    const items = ROOTS.map(([value, text]) => ({ ...attributes({ 'aria-checked': ticked.includes(value) ? 'true' : 'false' }), dataset: { value, text } }));
    const any = attributes({ 'aria-checked': ticked.length || cellText ? 'false' : 'true' });
    const exclude = { ...attributes({ 'aria-checked': 'false' }), dataset: { excludeOther: '1', mode: 'exclude-other' } };
    const events = [], classes = new Set();
    const root = {
        dataset: { name: 'category', label: 'Category', summary: 'count', ...(cellText ? { cellText: 'true' } : {}) },
        querySelectorAll: selector => (selector === '[data-value]' ? items : selector === '[data-value][aria-checked="true"]' ? items.filter(item => item.getAttribute('aria-checked') === 'true') : []),
        querySelector: selector => (selector === '[data-any]' ? any : selector === '[data-exclude-other]' ? exclude : null),
        classList: { toggle: (name, on) => (on ? classes.add(name) : classes.delete(name)) },
        dispatchEvent: event => events.push(event),
        contains: () => true,
    };
    const valueClasses = new Set();
    const component = checkboxMenu();
    component.$el = root;
    component.$refs = {
        value: { textContent: cellText ? 'Custom scope' : 'any', classList: { toggle: (name, on) => (on ? valueClasses.add(name) : valueClasses.delete(name)) } },
        button: { ...attributes(), focus() {} },
    };
    component.init();
    return { component, root, items, any, exclude, events };
}

/** The search page's screen (generic/releases/index.blade.php in the search context) at an address. */
function searchScreen(href, { custom = false, row = { scopeChoice: '0', scopeValue: '0' } } = {}) {
    const requests = [], assigned = [], dispatched = [];
    globalThis.document = { querySelector: () => null };
    globalThis.window = {
        location: { href, assign: url => assigned.push(url) },
        history: { replaceState: (state, title, url) => { window.location.href = url; } },
        showToast() {},
        dispatchEvent: event => dispatched.push(event),
    };
    globalThis.fetch = async url => {
        requests.push(String(url));
        return { ok: true, redirected: false, text: async () => '<div data-query-row></div>' };
    };
    const component = tvReleases();
    component.$el = { dataset: { preferenceUrl: '/profile/update-view', preferenceRoot: 'all', scopeParam: 't', ...(custom ? { scopeCustom: '' } : {}) }, querySelectorAll: () => [], querySelector: () => null };
    component.$refs = { list: { innerHTML: '', querySelector: selector => (selector === '[data-query-row]' ? { dataset: row } : null) } };
    component.init();
    return { component, requests, assigned, dispatched };
}

test('ticking roots in the Category cell writes the scope parameter, comma separated, reloads the list in place and drops the page', async () => {
    const cell = categoryCell();
    const { component, requests, assigned, dispatched } = searchScreen('https://nntmux.test/search?q=dune&maxage=30&completion=95&page=4', { row: { scopeChoice: '5000', scopeValue: '5000' } });
    cell.component.pick({ currentTarget: cell.items[1] });
    await component.applyFilter({ detail: cell.events.at(-1).detail, target: cell.root });
    let url = new URL(window.location.href);
    assert.equal(url.search, '?q=dune&maxage=30&completion=95&t=5000');
    assert.deepEqual(assigned, [], 'the page is not reloaded');
    const fragment = new URL(requests[0]);
    assert.deepEqual([fragment.searchParams.get('t'), fragment.searchParams.get('_fragment'), fragment.searchParams.has('_filters_at'), fragment.searchParams.has('page')], ['5000', 'list', false, false]);
    assert.deepEqual(dispatched.at(-1).detail, { choice: '5000', value: '5000' }, 'the header\'s scope picker is told what the reloaded query row says');
    assert.equal(dispatched.at(-1).type, 'search-scope');

    cell.component.pick({ currentTarget: cell.items[0] });
    await component.applyFilter({ detail: cell.events.at(-1).detail, target: cell.root });
    url = new URL(window.location.href);
    assert.equal(url.searchParams.get('t'), '2000,5000', 'several roots, in menu order');
    assert.equal(url.searchParams.has('category[]'), false);

    // Exclude Other is every root but Other, written as roots like any other scope
    cell.component.excludeOther();
    assert.deepEqual(cell.events.at(-1).detail, { name: 'category', values: ['exclude-other'], single: true });
    await component.applyFilter({ detail: cell.events.at(-1).detail, target: cell.root });
    assert.equal(new URL(window.location.href).searchParams.get('t'), '2000,5000,3000');
    assert.equal(new URL(window.location.href).searchParams.has('category'), false);

    // "Any category" clears the scope
    cell.component.clear();
    await component.applyFilter({ detail: cell.events.at(-1).detail, target: cell.root });
    assert.equal(new URL(window.location.href).search, '?q=dune&maxage=30&completion=95');
});

test('the Completion cell writes its own key on a search, drops a minc link and keeps the scope as written', async () => {
    const { component, requests, dispatched } = searchScreen('https://nntmux.test/search?q=dune&t=2040%2C5000&minc=80&page=2', { custom: true, row: { scopeChoice: '0', scopeValue: '2040,5000' } });
    await component.applyFilter({ detail: { name: 'completion', values: ['imported'], single: true }, target: {} });
    const url = new URL(window.location.href);
    assert.deepEqual([url.searchParams.get('t'), url.searchParams.get('completion'), url.searchParams.has('minc'), url.searchParams.has('page')], ['2040,5000', 'imported', false, false]);
    assert.equal(new URL(requests[0]).searchParams.get('t'), '2040,5000');
    assert.deepEqual(dispatched.at(-1).detail, { choice: '0', value: '2040,5000' }, 'a legacy scope is kept while the header reads All');
});

test('choosing roots on a legacy scope replaces the whole scope and redraws the page; "Any category" clears it', async () => {
    const cell = categoryCell({ ticked: ['5000'], cellText: true });
    const { component, requests, assigned } = searchScreen('https://nntmux.test/search?q=dune&t=5000%2C2040&cat=2040&page=3', { custom: true });
    cell.component.pick({ currentTarget: cell.items[0] });
    assert.equal(cell.component.$refs.value.textContent, '2 chosen');
    assert.equal(cell.root.dataset.cellText, undefined, 'the cell reads its ticked roots again');
    await component.applyFilter({ detail: cell.events.at(-1).detail, target: cell.root });
    assert.deepEqual(assigned, ['https://nntmux.test/search?q=dune&cat=2040&t=2000%2C5000']);
    assert.deepEqual(requests, [], 'no list fragment is fetched: the page reloads');

    // a legacy scope with no root of its own: nothing is ticked, yet "Any category" is a change
    const bare = categoryCell({ cellText: true });
    bare.component.clear();
    assert.equal(bare.events.length, 1);
    assert.deepEqual(bare.events[0].detail, { name: 'category', values: [], single: false });
    await component.applyFilter({ detail: bare.events[0].detail, target: bare.root });
    assert.equal(assigned.at(-1), 'https://nntmux.test/search?q=dune&cat=2040');
    // a cell with nothing ticked and no text of its own still ignores "Any …"
    const plain = categoryCell();
    plain.component.clear();
    assert.equal(plain.events.length, 0);
});

test('the lists other than a search still send category[] and the Exclude Other mode', async () => {
    const cell = categoryCell();
    const { component } = searchScreen('https://nntmux.test/browse/all?page=2');
    delete component.$el.dataset.scopeParam;
    cell.component.pick({ currentTarget: cell.items[1] });
    await component.applyFilter({ detail: cell.events.at(-1).detail, target: cell.root });
    assert.equal(new URL(window.location.href).search, '?category%5B%5D=5000');
    cell.component.excludeOther();
    await component.applyFilter({ detail: cell.events.at(-1).detail, target: cell.root });
    assert.equal(new URL(window.location.href).search, '?category=exclude-other');
});

test('the header\'s scope picker follows a search\'s Category cell: the one ticked root, otherwise All, a legacy scope kept behind All', () => {
    const items = [['0', 'All'], ['2000', 'Movies'], ['5000', 'TV']].map(([value, text]) => ({ ...attributes({ 'aria-checked': value === '0' ? 'true' : 'false' }), dataset: { value }, textContent: text }));
    const component = publicNavigation();
    component.$refs = {
        searchScope: { value: '0' }, scopeLabel: { textContent: 'All' }, scopeTrigger: attributes({ 'aria-label': 'Search scope: All' }),
        scopePanel: { querySelectorAll: () => items },
    };
    const state = () => [component.$refs.searchScope.value, component.$refs.scopeLabel.textContent, component.$refs.scopeTrigger.getAttribute('aria-label'), items.map(item => item.getAttribute('aria-checked')).join(',')];
    component.syncScope({ detail: { choice: '5000', value: '5000' } });
    assert.deepEqual(state(), ['5000', 'TV', 'Search scope: TV', 'false,false,true']);
    component.syncScope({ detail: { choice: '0', value: '0' } });
    assert.deepEqual(state(), ['0', 'All', 'Search scope: All', 'true,false,false']);
    component.syncScope({ detail: { choice: '0', value: '2040,5000' } });
    assert.deepEqual(state(), ['2040,5000', 'All', 'Search scope: All', 'true,false,false'], 'the form keeps the legacy scope until a scope is picked');
    // a root the header does not list (it is not in the picker) reads All and sends All
    component.syncScope({ detail: { choice: '7000', value: '7000' } });
    assert.deepEqual(state(), ['0', 'All', 'Search scope: All', 'true,false,false']);
    const header = readFileSync(new URL('../../resources/views/partials/header-menu.blade.php', import.meta.url), 'utf8');
    assert.match(header, /x-on:search-scope\.window="syncScope"/);
    assert.match(header, /name="t" value="\{\{ isset\(\$headerScope\) && \$headerScope\['choice'\] === \$scopeValue \? \$headerScope\['value'\] : \$scopeValue \}\}" x-ref="searchScope"/);
});

/** "+ Add filter" as search/filter-menu.blade.php renders it: the kinds, a form per kind, the Sub-category choices. */
function addFilter() {
    const focused = [];
    const drawing = { ticks: 2 };
    const control = name => ({ name, getClientRects: () => (drawing.ticks-- > 0 ? [] : [1]), focus() { focused.push(name); } });
    const kinds = { cat: control('kind cat'), age: control('kind age'), title: control('kind title') };
    const fields = { cat: control('search field'), age: control('minimum'), title: control('words') };
    const choices = ['Movies > HD', 'Movies > SD', 'TV > HD'].map(text => ({ dataset: { text }, hidden: false }));
    const search = { value: '' };
    const classes = new Set();
    const inside = new Set([...Object.values(kinds), ...Object.values(fields)]);
    const root = {
        classList: { toggle: (name, on) => (on ? classes.add(name) : classes.delete(name)) },
        contains: node => inside.has(node),
        querySelectorAll: selector => (selector === '[data-sub-category]' ? choices : []),
        querySelector(selector) {
            if (selector === '[data-filter-search]') return search;
            const kind = selector.match(/^\[data-kind="(\w+)"\]$/);
            if (kind) return kinds[kind[1]] ?? null;
            const form = selector.match(/^\[data-filter-form="(\w+)"\]$/);
            return form ? { querySelector: () => fields[form[1]] ?? null } : null;
        },
    };
    const component = searchFilters();
    Object.defineProperties(component, Object.getOwnPropertyDescriptors(searchFilters()));
    component.$el = root;
    component.$refs = { button: control('button') };
    component.$nextTick = callback => callback();
    component.init();
    return { component, kinds, fields, choices, search, classes, focused, drawing };
}

/** Lets the timer run that moves focus once a swapped form or list is drawn. */
const drawn = () => new Promise(resolve => setTimeout(resolve, 120));

test('+ Add filter opens on its list, shows the picked filter\'s form with focus in it, and the back row returns to the list', async () => {
    const { component, focused, drawing } = addFilter();
    assert.deepEqual([component.open, component.onList], [false, true]);
    component.toggle();
    assert.deepEqual([component.open, component.kind, component.onList], [true, '', true]);
    component.pick({ currentTarget: { dataset: { kind: 'age' } } });
    assert.deepEqual([component.kind, component.onList, focused.length], ['age', false, 0], 'focus waits until the form is drawn');
    await drawn();
    assert.equal(focused.at(-1), 'minimum');
    component.back();
    await drawn();
    assert.deepEqual([component.kind, component.onList, focused.at(-1)], ['', true, 'kind age']);
    component.pick({ currentTarget: { dataset: { kind: 'title' } } });
    await drawn();
    assert.deepEqual([component.kind, focused.at(-1)], ['title', 'words']);
    // a menu closed before the form is drawn takes no focus
    drawing.ticks = 2;
    component.pick({ currentTarget: { dataset: { kind: 'cat' } } });
    component.close();
    await drawn();
    assert.equal(focused.at(-1), 'words');
});

test('+ Add filter closes on Escape with focus back on the button, on a click outside and when focus leaves, and reopens on its list', async () => {
    const { component, kinds, classes, focused } = addFilter();
    component.toggle();
    assert.ok(classes.has('is-open'));
    component.pick({ currentTarget: { dataset: { kind: 'age' } } });
    await drawn();
    component.closeAndFocus();
    assert.deepEqual([component.open, focused.at(-1), classes.has('is-open')], [false, 'button', false]);
    component.toggle();
    assert.deepEqual([component.open, component.kind], [true, ''], 'never on the form it was closed on');

    component.pick({ currentTarget: { dataset: { kind: 'cat' } } });
    component.focusLeft({ relatedTarget: kinds.age });
    assert.equal(component.open, true, 'focus moving inside the menu keeps it open');
    component.focusLeft({ relatedTarget: null });
    assert.equal(component.open, true, 'a hidden list dropping focus is not focus leaving');
    component.focusLeft({ relatedTarget: { outside: true } });
    assert.equal(component.open, false);
    component.toggle();
    assert.equal(component.kind, '');

    component.pick({ currentTarget: { dataset: { kind: 'title' } } });
    await drawn();
    const before = focused.length;
    component.close();
    assert.deepEqual([component.open, focused.length], [false, before], 'a click outside closes it without moving focus');
    component.closeAndFocus();
    assert.equal(focused.length, before, 'Escape on a closed menu does nothing');
    component.toggle();
    component.toggle();
    assert.equal(component.open, false, 'the button toggles it');
});

test('the Sub-category list narrows to the text typed and forgets it when the list is left or the menu closes', () => {
    const { component, choices, search } = addFilter();
    component.toggle();
    component.pick({ currentTarget: { dataset: { kind: 'cat' } } });
    search.value = ' movies ';
    component.narrow({ target: search });
    assert.deepEqual(choices.map(choice => choice.hidden), [false, false, true]);
    search.value = 'SD';
    component.narrow({ target: search });
    assert.deepEqual(choices.map(choice => choice.hidden), [true, false, true]);
    component.back();
    assert.deepEqual([search.value, choices.some(choice => choice.hidden)], ['', false]);
    search.value = 'tv';
    component.narrow({ target: search });
    component.close();
    assert.deepEqual([search.value, choices.some(choice => choice.hidden)], ['', false]);
});

function feedDialog({ copies = true } = {}) {
    const listeners = { keydown: [] }, copied = [], toasts = [];
    const document = {
        activeElement: null,
        body: { style: { overflow: 'auto' }, appendChild() {} },
        addEventListener: (name, handler) => listeners[name].push(handler),
        removeEventListener: (name, handler) => { listeners[name] = listeners[name].filter(fn => fn !== handler); },
        createElement: () => ({ setAttribute() {}, select() {}, remove() {} }),
        execCommand: () => false,
    };
    globalThis.document = document;
    globalThis.window = { isSecureContext: true, showToast: (message, type) => toasts.push({ message, type }) };
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value: copies ? { clipboard: { writeText: async text => copied.push(text) } } : {} });
    const control = () => ({ isConnected: true, getClientRects: () => [1], focus() { document.activeElement = this; } });
    const closeButton = control(), copyButton = control();
    const classes = new Set();
    copyButton.classList = { toggle: (name, on) => (on ? classes.add(name) : classes.delete(name)) };
    const modal = { ownerDocument: document, hasAttribute: () => false, contains: node => node === closeButton || node === copyButton, querySelector: () => null, querySelectorAll: () => [closeButton, copyButton] };
    const dialog = searchFeed();
    let watcher = () => {};
    let open = dialog.open;
    Object.defineProperty(dialog, 'open', { get: () => open, set(value) { open = value; watcher(value); }, configurable: true });
    dialog.$el = { querySelector: selector => (selector === '[data-modal-dialog]' ? modal : null) };
    dialog.$refs = { feedUrl: { textContent: '\n  https://nntmux.test/api?t=search&apikey=key&o=xml&q=dune  ' }, copy: copyButton };
    dialog.$watch = (name, fn) => { watcher = fn; };
    dialog.$nextTick = fn => fn();
    dialog.init();
    return { dialog, document, closeButton, classes, copied, toasts, key: event => listeners.keydown.forEach(fn => fn(event)) };
}

test('RSS for this search opens from the heading row\'s button; Escape and the close button close it and focus returns to the button', () => {
    const { dialog, document, closeButton, key } = feedDialog();
    const button = { isConnected: true, focus() { document.activeElement = this; } };
    document.activeElement = button;
    dialog.openFeed();
    assert.equal(dialog.open, true);
    assert.equal(document.activeElement, closeButton, 'focus moves into the dialog');
    key({ key: 'Escape', preventDefault() {} });
    assert.equal(dialog.open, false);
    assert.equal(document.activeElement, button);
    dialog.openFeed();
    dialog.close();
    assert.deepEqual([dialog.open, document.activeElement === button], [false, true]);
    dialog.destroy();
    const page = readFileSync(new URL('../../resources/views/generic/releases/index.blade.php', import.meta.url), 'utf8');
    assert.match(page, /data-search-feed-button x-on:click="\$dispatch\('search-feed-open'\)"/);
    const view = readFileSync(new URL('../../resources/views/search/feed-dialog.blade.php', import.meta.url), 'utf8');
    assert.match(view, /x-data="searchFeed" x-on:search-feed-open\.window="openFeed"/);
    assert.match(readFileSync(new URL('../../resources/views/components/tv-dialog.blade.php', import.meta.url), 'utf8'), /class="tv-scrim" x-on:click\.self="\{\{ \$close \}\}"/, 'a click on the scrim closes it');
});

test('Copy puts the feed URL on the clipboard and the button reads Copied for two seconds; a failed copy says so', async () => {
    mock.timers.enable({ apis: ['setTimeout'] });
    try {
        const { dialog, classes, copied } = feedDialog();
        dialog.openFeed();
        await dialog.copyFeed();
        assert.deepEqual(copied, ['https://nntmux.test/api?t=search&apikey=key&o=xml&q=dune']);
        assert.ok(classes.has('is-copied'));
        mock.timers.tick(1999);
        assert.ok(classes.has('is-copied'));
        mock.timers.tick(1);
        assert.ok(!classes.has('is-copied'));
        await dialog.copyFeed();
        dialog.close();
        assert.ok(!classes.has('is-copied'), 'closing forgets Copied');
        dialog.destroy();

        const failing = feedDialog({ copies: false });
        failing.dialog.openFeed();
        await failing.dialog.copyFeed();
        assert.ok(!failing.classes.has('is-copied'));
        assert.deepEqual(failing.toasts, [{ message: 'Could not copy the feed URL. Select it and copy it by hand.', type: 'error' }]);
        failing.dialog.destroy();
    } finally {
        mock.timers.reset();
    }
});

test('the search components are loaded for the page and the picture column and query row keep the issue\'s measures', () => {
    const loader = readFileSync(new URL('../../resources/js/alpine/lazy-loader.js', import.meta.url), 'utf8');
    assert.match(loader, /'searchFeed': \(\) => import\('\.\/components\/search-filters\.js'\)/);
    assert.match(loader, /'searchFilters': \(\) => import\('\.\/components\/search-filters\.js'\)/);
    const css = readFileSync(new URL('../../resources/css/tv.css', import.meta.url), 'utf8');
    // the picture column: the TV / Movies column, 116 px; 88 x 132 posters, 88 x 88 covers, 16:9 at 88 x 50
    assert.match(css, /\.tv-col-art \{ width: 116px; \}/);
    assert.match(css, /\.tv-art a \{ position: relative; display: block; width: 88px; height: 132px;/);
    assert.match(css, /\.tv-art\.is-square a \{ width: 88px; height: 88px; \}/);
    assert.match(css, /\.tv-feed\.is-generic \.tv-art\.is-picture a \{ width: 88px; height: 50px; \}/);
    assert.match(css, /\.tv-art\.is-picture a \{ width: 176px; height: 99px; \}/, 'the Adult list keeps its own picture size');
    assert.match(css, /\.tv-feed\.is-generic:not\(\.is-other, \.is-home-panel\) td\.tv-date \{ white-space: nowrap; \}/, 'the date stays on one line beside the picture; the Other list and the home panels are untouched');
    // the query row: 12 px under the filter bar, 32 px high; a chip is a 32 px pill, radius 16, 13 px semibold, neutral, its x in a 22 px round
    assert.match(css, /\.tv-query-row \{ display: flex; min-height: 32px; margin-top: 12px; \}/);
    assert.match(css, /\.tv-query-chip \{[^}]*height: 32px; padding: 0 5px 0 14px; border-radius: 16px; background: var\(--tv-panel-alt\); color: var\(--tv-ink\); font-size: 13px; font-weight: 600;/);
    assert.match(css, /\.tv-query-chip-x \{[^}]*width: 22px; height: 22px; border-radius: 11px;/);
    assert.match(css, /\.tv-add-filter-button \{[^}]*height: 32px;[^}]*border: 1px solid var\(--tv-line\); border-radius: 16px; background: none; color: var\(--tv-dim\);/);
    assert.doesNotMatch(css.slice(css.indexOf('.tv-query-row')), /\.tv-query-chip[^{]*\{[^}]*var\(--tv-accent\)/, 'every chip is the same neutral colour');
});
