import assert from 'node:assert/strict';
import test from 'node:test';
import { publicNavigation } from '../../resources/js/alpine/components/public-navigation-component.js';

function navigation() {
    globalThis.window = { location: { href: 'https://nntmux.test/browse/movies' } };
    globalThis.document = { querySelectorAll: () => [] };
    const component = publicNavigation();
    component.$refs = {
        searchInput: { value: 'dune', focus() {} },
        searchScope: { value: '2000' },
        searchForm: { action: 'https://nntmux.test/search', dataset: { suggestUrl: 'https://nntmux.test/api/search/suggest' } },
    };
    return component;
}

test('suggestions keep the current scope and discard stale replies after the input changes', async () => {
    const component = navigation();
    const pending = [];
    globalThis.fetch = (url, options) => new Promise(resolve => pending.push({ url, options, resolve }));
    const first = component.fetchSuggestions();
    component.$refs.searchInput.value = 'arrival';
    const second = component.fetchSuggestions();
    assert.equal(pending[0].options.signal.aborted, true);
    assert.equal(new URL(pending[1].url).pathname, '/api/search/suggest');
    pending[1].resolve({ ok: true, json: async () => ({ success: true, suggestions: ['Arrival 2016'] }) });
    await second;
    pending[0].resolve({ ok: true, json: async () => ({ success: true, suggestions: ['Dune'] }) });
    await first;
    assert.deepEqual(component.suggestions.map(item => item.label), ['Arrival 2016']);
    const link = new URL(component.suggestions[0].url);
    assert.equal(link.pathname, '/search');
    assert.equal(link.searchParams.get('q'), 'Arrival 2016');
    assert.equal(link.searchParams.get('t'), '2000');
    component.$refs.searchInput.value = '';
    await component.fetchSuggestions();
    assert.deepEqual(component.suggestions, []);
});

test('an answer for text that has already changed is ignored during the debounce interval', async () => {
    const component = navigation();
    let finish;
    globalThis.fetch = () => new Promise(resolve => { finish = resolve; });
    const request = component.fetchSuggestions();
    component.$refs.searchInput.value = 'arrival';
    finish({ ok: true, json: async () => ({ success: true, suggestions: ['Dune'] }) });
    await request;
    assert.deepEqual(component.suggestions, []);
});

// The header drop-downs and the search scope picker (issue #908): one menu per category plus All,
// and the one-choice scope menu, built as the approved header prototype behaves.

function matches(node, selector) {
    if (selector === 'a') return node.tagName === 'A';
    const match = selector.match(/^\[([\w-]+)(?:="([^"]*)")?\]$/);
    if (!match) return false;
    const [, name, wanted] = match;
    const value = name.startsWith('data-')
        ? node.dataset[name.slice(5).replace(/-(\w)/g, (_, letter) => letter.toUpperCase())]
        : node.values[name];
    return value !== undefined && (wanted === undefined || String(value) === wanted);
}

function node(tag, { dataset = {}, attributes = {}, text = '' } = {}, children = []) {
    const element = {
        tagName: tag.toUpperCase(), dataset, values: { ...attributes }, children, parent: null, textContent: text,
        getAttribute(name) { return this.values[name] ?? null; },
        setAttribute(name, value) { this.values[name] = String(value); },
        focus() { globalThis.document.activeElement = this; },
        contains(other) { for (let at = other; at; at = at.parent) if (at === this) return true; return false; },
        closest(selector) { for (let at = this; at; at = at.parent) if (matches(at, selector)) return at; return null; },
        querySelectorAll(selector) {
            const found = [];
            const walk = at => at.children.forEach(child => { if (matches(child, selector)) found.push(child); walk(child); });
            walk(this);
            return found;
        },
        querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null; },
    };
    children.forEach(child => { child.parent = element; });
    return element;
}

/** The header as header-menu.blade.php renders it for a user with Movies and TV. */
function header() {
    const drops = ['nav-menu-movies', 'nav-menu-tv', 'nav-menu-all'].map(menu => {
        const trigger = node('button', { dataset: { menu } });
        const first = node('a'), second = node('a');
        const panel = node('div', { attributes: { id: menu } }, [first, second]);
        return { menu, trigger, first, panel, drop: node('div', { dataset: { menuDrop: menu } }, [trigger, panel]) };
    });
    const items = [['0', 'All'], ['2000', 'Movies'], ['5000', 'TV']].map(([value, text]) =>
        node('button', { dataset: { value }, attributes: { role: 'menuitemradio', 'aria-checked': value === '0' ? 'true' : 'false' }, text }));
    const scopePanel = node('div', {}, items);
    const scopeTrigger = node('button', { attributes: { 'aria-label': 'Search scope: All' } });
    const scopeLabel = node('span', { text: 'All' });
    const scope = node('div', { dataset: { searchScope: '' } }, [scopeTrigger, scopePanel]);
    const input = node('input');
    input.value = 'dune';
    input.placeholder = 'Search releases… (Press / to search)';
    const root = node('header', {}, [...drops.map(drop => drop.drop), scope, input]);
    globalThis.window = { location: { href: 'https://nntmux.test/browse/movies' } };
    globalThis.document = {
        activeElement: null,
        querySelectorAll: () => [],
        querySelector: selector => root.querySelector(selector),
        getElementById: id => drops.find(drop => drop.menu === id)?.panel ?? null,
    };
    globalThis.fetch = async () => ({ ok: true, json: async () => ({ success: true, suggestions: ['Dune'] }) });
    const component = publicNavigation();
    component.$refs = {
        searchInput: input,
        searchScope: { value: '0' },
        searchForm: { action: 'https://nntmux.test/search', dataset: { suggestUrl: 'https://nntmux.test/api/search/suggest' } },
        scopeTrigger, scopeLabel, scopePanel,
        userTrigger: node('button'),
    };
    component.$nextTick = callback => callback();
    const [movies, tv, all] = drops;
    return { component, movies, tv, all, items, scope, scopeTrigger, scopeLabel, input };
}

const click = (target, currentTarget = target) => ({ target, currentTarget, preventDefault() {} });
const key = (name, currentTarget = null) => ({ key: name, currentTarget, target: currentTarget ?? {}, preventDefault() { this.prevented = true; }, ctrlKey: false, metaKey: false, altKey: false });
const leave = (currentTarget, relatedTarget) => ({ currentTarget, relatedTarget });

test('opening a header menu closes the other menus, the scope picker and the suggestions', async () => {
    const { component, movies, tv, scopeTrigger } = header();
    component.userOpen = true;
    component.toggleMenu(click(movies.trigger));
    assert.equal(component.openMenu, 'nav-menu-movies');
    assert.equal(component.userOpen, false);
    component.toggleScope(click(scopeTrigger));
    assert.equal(component.scopeOpen, true);
    assert.equal(component.openMenu, '');
    await component.fetchSuggestions();
    assert.equal(component.suggestionsOpen, true);
    component.toggleMenu(click(tv.trigger));
    assert.equal(component.openMenu, 'nav-menu-tv');
    assert.equal(component.scopeOpen, false);
    assert.equal(component.suggestionsOpen, false);
});

test('opening the scope picker closes open suggestions, which would cover its options', async () => {
    const { component, scopeTrigger } = header();
    await component.fetchSuggestions();
    component.toggleScope(click(scopeTrigger));
    assert.equal(component.suggestionsOpen, false);
});

test('a second click on an open button closes its menu', () => {
    const { component, all } = header();
    component.toggleMenu(click(all.trigger));
    component.toggleMenu(click(all.trigger));
    assert.equal(component.openMenu, '');
});

test('a click elsewhere in the header closes the open menu and the scope picker, a click inside keeps them', () => {
    const { component, movies, scopeTrigger, items, input } = header();
    component.toggleMenu(click(movies.trigger));
    component.headerClick(click(movies.first));
    assert.equal(component.openMenu, 'nav-menu-movies');
    component.headerClick(click(input));
    assert.equal(component.openMenu, '');
    component.toggleScope(click(scopeTrigger));
    component.headerClick(click(items[1]));
    assert.equal(component.scopeOpen, true);
    component.headerClick(click(input));
    assert.equal(component.scopeOpen, false);
});

test('tabbing out of a button and its menu closes it', () => {
    const { component, movies, tv, scope, scopeTrigger, input } = header();
    component.toggleMenu(click(movies.trigger));
    component.dropFocusLeft(leave(movies.drop, movies.first));
    assert.equal(component.openMenu, 'nav-menu-movies');
    component.dropFocusLeft(leave(movies.drop, null));
    assert.equal(component.openMenu, 'nav-menu-movies');
    component.dropFocusLeft(leave(movies.drop, tv.trigger));
    assert.equal(component.openMenu, '');
    component.toggleScope(click(scopeTrigger));
    component.scopeFocusLeft(leave(scope, input));
    assert.equal(component.scopeOpen, false);
});

test('Escape closes a click-opened menu or scope picker and returns focus to its button', () => {
    const { component, tv, scopeTrigger } = header();
    component.toggleMenu(click(tv.trigger));
    component.handleShortcut(key('Escape'));
    assert.equal(component.openMenu, '');
    assert.equal(document.activeElement, tv.trigger);
    component.toggleScope(click(scopeTrigger));
    component.handleShortcut(key('Escape'));
    assert.equal(component.scopeOpen, false);
    assert.equal(document.activeElement, scopeTrigger);
});

test('the Down arrow opens a menu and focuses its first link, also after Enter opened it', () => {
    const { component, movies, tv } = header();
    const down = key('ArrowDown', movies.trigger);
    component.openMenuAndFocus(down);
    assert.equal(component.openMenu, 'nav-menu-movies');
    assert.equal(document.activeElement, movies.first);
    component.toggleMenu(click(tv.trigger));
    tv.trigger.focus();
    component.openMenuAndFocus(key('ArrowDown', tv.trigger));
    assert.equal(component.openMenu, 'nav-menu-tv');
    assert.equal(document.activeElement, tv.first);
});

test('the scope picker opens on the checked option, and Up, Down, Home and End move through it with wrapping', () => {
    const { component, items } = header();
    items[0].setAttribute('aria-checked', 'false');
    items[1].setAttribute('aria-checked', 'true');
    component.openScopeAndFocus(key('ArrowDown'));
    assert.equal(component.scopeOpen, true);
    assert.equal(document.activeElement, items[1]);
    const move = name => { const event = key(name); component.scopeKey(event); assert.equal(event.prevented, true, name); };
    move('ArrowDown');
    assert.equal(document.activeElement, items[2]);
    move('ArrowDown');
    assert.equal(document.activeElement, items[0]);
    move('ArrowUp');
    assert.equal(document.activeElement, items[2]);
    move('Home');
    assert.equal(document.activeElement, items[0]);
    move('End');
    assert.equal(document.activeElement, items[2]);
});

test('choosing a scope sets the hidden value, the label and the checked dot, and keeps the scope on suggestion links', async () => {
    const { component, items, scopeTrigger, scopeLabel } = header();
    component.toggleScope(click(scopeTrigger));
    await component.pickScope(click(items[2]));
    assert.equal(component.$refs.searchScope.value, '5000');
    assert.equal(scopeLabel.textContent, 'TV');
    assert.equal(scopeTrigger.getAttribute('aria-label'), 'Search scope: TV');
    assert.deepEqual(items.map(item => item.getAttribute('aria-checked')), ['false', 'false', 'true']);
    assert.equal(component.scopeOpen, false);
    assert.equal(document.activeElement, scopeTrigger);
    assert.equal(new URL(component.suggestions[0].url).searchParams.get('t'), '5000');
});

test('the search placeholder drops its shortcut hint at 1440px and narrower', () => {
    const { component, input } = header();
    let narrow = true, changed = null;
    window.matchMedia = query => {
        assert.equal(query, '(max-width: 1440px)');
        return { get matches() { return narrow; }, addEventListener: (_, listener) => { changed = listener; }, removeEventListener() {} };
    };
    component.init();
    assert.equal(input.placeholder, 'Search releases…');
    narrow = false;
    changed();
    assert.equal(input.placeholder, 'Search releases… (Press / to search)');
});
