import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

class Element {
    constructor(attributes = {}, children = []) {
        this.attributes = { ...attributes };
        this.children = children;
        this.handlers = {};
        this.dataset = { season: attributes['data-season'] };
        this.classes = new Set((attributes.class || '').split(/\s+/));
        this.classList = {
            add: (...names) => names.forEach(name => this.classes.add(name)),
            remove: (...names) => names.forEach(name => this.classes.delete(name)),
            toggle: (name, enabled) => enabled ? this.classes.add(name) : this.classes.delete(name),
        };
        this.style = {
            removeProperty(name) { delete this[name]; },
            setProperty(name, value) { this[name] = value; },
        };
    }

    getAttribute(name) { return this.attributes[name] ?? null; }
    setAttribute(name, value) { this.attributes[name] = value; }
    removeAttribute(name) { delete this.attributes[name]; }
    addEventListener(name, handler) { this.handlers[name] = handler; }
    click() { this.handlers.click?.call(this, { preventDefault() {}, target: this }); }
    matches(selector) {
        if (selector.startsWith('.')) { return this.classes.has(selector.slice(1)); }
        if (selector.startsWith('#')) { return this.attributes.id === selector.slice(1); }
        if (selector.startsWith('[')) { return Object.hasOwn(this.attributes, selector.slice(1, -1)); }
        return selector === this.attributes.tag;
    }
    closest(selector) { return this.matches(selector) ? this : null; }
    querySelectorAll(selector) { return this.children.filter(child => child.matches(selector)); }
    querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null; }
}

function loadComponent(filename, elements, extraGlobals = {}) {
    const registrations = {};
    const root = new Element({}, elements);
    root.getElementById = id => root.querySelector('#' + id);
    const context = {
        Alpine: { data: (name, factory) => { registrations[name] = factory; }, initTree() {} },
        document: root,
        window: { location: { hash: '', origin: 'https://indexer.test' }, addEventListener() {} },
        history: { pushState() {} },
        Element, URL, AbortController,
        ...extraGlobals,
    };
    context.window.history = context.history;
    const source = readFileSync(new URL('../../resources/js/alpine/components/' + filename, import.meta.url), 'utf8');
    vm.runInNewContext(source.replace(/^import Alpine from '@alpinejs\/csp';\n/m, ''), context);
    return { root, registrations };
}

function viewElements(path, pattern) {
    const view = readFileSync(new URL('../../resources/views/' + path, import.meta.url), 'utf8');
    return Array.from(view.matchAll(pattern), match => new Element({ [match[1]]: match[2], class: match[3] }));
}

function assertPrimaryOnly(element) {
    assert.equal([...element.classes].some(name => /(?:blue|purple|indigo)-/.test(name)), false);
}

test('profile clicks transfer the server active state including dark colors', () => {
    const links = viewElements('profile/index.blade.php', /<a (href)="(#(?:general|preferences|api))" class="([^"]+)"/g);
    const panels = ['general', 'preferences', 'api'].map(id => new Element({ id, class: 'tab-content' }));
    loadComponent('tab-switcher.js', [...links, ...panels]);

    links[1].click();
    assert.equal(links[0].classes.has('bg-primary-50'), false);
    assert.equal(links[0].classes.has('dark:text-primary-300'), false);
    assert.equal(links[1].classes.has('bg-primary-50'), true);
    assert.equal(links[1].classes.has('dark:bg-primary-900/20'), true);
    assert.equal(links[1].classes.has('dark:bg-gray-900'), false);
    assert.equal(links[1].classes.has('dark:bg-(--surface-body-dark)'), false);
    assert.equal(links[1].classes.has('hover:bg-(--public-surface-hover)'), false);
    assert.equal(links[0].classes.has('dark:bg-(--surface-body-dark)'), true);
    assert.equal(panels[0].style.display, 'none');
    assert.equal(panels[1].style.display, 'block');

    links[0].click();
    assert.equal(links[1].classes.has('bg-primary-50'), false);
    links.forEach(assertPrimaryOnly);
});

test('generic tabs replace the initial primary state on every click', () => {
    const first = new Element({ 'data-tab-trigger': 'first', class: 'active border-primary-500 text-primary-600' });
    const second = new Element({ 'data-tab-trigger': 'second', class: 'border-transparent text-gray-500' });
    const panels = ['first', 'second'].map(id => new Element({ id, class: 'tab-content' }));
    loadComponent('tab-switcher.js', [first, second, ...panels]);

    second.click();
    assert.equal(first.classes.has('text-primary-600'), false);
    assert.equal(second.classes.has('text-primary-600'), true);
    first.click();
    assert.equal(second.classes.has('border-primary-500'), false);
    [first, second].forEach(assertPrimaryOnly);
});

test('quality clicks clear server colors and preserve combined release filtering', () => {
    const buttons = viewElements('movies/viewmoviefull.blade.php', /<button (data-resolution|data-source)="([^"]+)"\s+class="([^"]+)"/g);
    const releases = ['Example.1080p.WEB-DL', 'Example.720p.Bluray'].map(name => new Element({ class: 'release-item', 'data-release-name': name }));
    const { root, registrations } = loadComponent('quality-filter.js', [...buttons, ...releases]);
    const component = Object.assign(registrations.qualityFilter(), { $el: root });
    component.init();
    const click = button => root.handlers.click({ target: button });
    click(buttons.find(button => button.getAttribute('data-resolution') === '1080p'));
    click(buttons.find(button => button.getAttribute('data-source') === 'web-dl'));

    for (const key of ['data-resolution', 'data-source']) {
        const all = buttons.find(button => button.getAttribute(key) === 'all');
        assert.equal(all.classes.has('bg-primary-600'), false);
        assert.equal(all.classes.has('text-white'), false);
        const selected = buttons.find(button => button.getAttribute(key) === component[key === 'data-source' ? 'activeSource' : 'activeResolution']);
        assert.equal(selected.classes.has('bg-primary-600'), true);
        assert.equal(selected.classes.has('dark:bg-primary-700'), true);
        assert.equal(selected.classes.has('bg-(--surface-panel-alt)'), false);
        assert.equal(selected.classes.has('dark:bg-(--surface-panel-alt-dark)'), false);
        assert.equal(all.classes.has('bg-(--surface-panel-alt)'), true);
    }
    assert.equal(component.visibleCount, 1);
    assert.equal(releases[1].style.display, 'none');
    buttons.forEach(assertPrimaryOnly);

    buttons.filter(button => button.getAttribute('data-resolution') === 'all' || button.getAttribute('data-source') === 'all').forEach(click);
    assert.equal(component.visibleCount, 2);
    assert.equal(releases[1].style.display, undefined);
});

test('loading another season transfers active tab and badge colors', async () => {
    const firstBadge = new Element({ tag: 'span', class: 'bg-primary-100 text-primary-800' });
    const secondBadge = new Element({ tag: 'span', class: 'bg-(--surface-panel-alt) dark:bg-(--surface-card-dark) text-gray-600' });
    const first = new Element({ 'data-series-season-link': '', 'data-season': '1', 'aria-current': 'page', class: 'border-primary-500 text-primary-600' }, [firstBadge]);
    const second = new Element({ 'data-series-season-link': '', 'data-season': '2', class: 'border-transparent text-gray-500' }, [secondBadge]);
    const panel = new Element({ 'data-series-season-content': '' });
    const { root, registrations } = loadComponent('series-season-loader.js', [first, second, panel], {
        fetch: async () => ({ ok: true, json: async () => ({ selectedSeason: 2, contentHtml: '<div>Season 2</div>' }) }),
    });
    const component = Object.assign(registrations.seriesSeasonLoader(), { $el: root });
    component.init();
    component.load({ href: 'https://indexer.test/series/1?season=2' });
    await new Promise(setImmediate);

    assert.equal(first.getAttribute('aria-current'), null);
    assert.equal(second.getAttribute('aria-current'), 'page');
    assert.equal(first.classes.has('text-primary-600'), false);
    assert.equal(firstBadge.classes.has('bg-primary-100'), false);
    assert.equal(second.classes.has('text-primary-600'), true);
    assert.equal(secondBadge.classes.has('bg-primary-100'), true);
    assert.equal(secondBadge.classes.has('bg-(--surface-panel-alt)'), false);
    assert.equal(firstBadge.classes.has('bg-(--surface-panel-alt)'), true);
    [first, second, firstBadge, secondBadge].forEach(assertPrimaryOnly);
});

test('theme selection removes the server neutral hover fill and restores it on deselection', () => {
    const view = readFileSync(new URL('../../resources/views/partials/theme-switcher.blade.php', import.meta.url), 'utf8');
    const inactive = view.match(/: '(text-gray-300[^']+)'/)[1];
    const buttons = ['light', 'dark'].map(theme => {
        const button = new Element({ class: inactive });
        button.dataset.theme = theme;
        return button;
    });
    let store;
    const source = readFileSync(new URL('../../resources/js/alpine/stores/theme.js', import.meta.url), 'utf8');
    vm.runInNewContext(source.replace(/^import Alpine from '@alpinejs\/csp';\n/m, ''), {
        Alpine: { store(name, value) { store = value; } },
        window: { matchMedia: () => ({}) },
        document: { getElementById: () => null, querySelectorAll: selector => selector.includes('theme-btn') ? buttons : [] },
    });
    store.current = 'dark';
    store._updateUI();
    assert.equal(buttons[1].classes.has('bg-primary-600'), true);
    assert.equal(buttons[1].classes.has('hover:bg-(--surface-chrome-border)'), false);
    assert.equal(buttons[1].classes.has('dark:hover:bg-(--surface-chrome-border-dark)'), false);
    store.current = 'light';
    store._updateUI();
    assert.equal(buttons[1].classes.has('bg-primary-600'), false);
    assert.equal(buttons[1].classes.has('hover:bg-(--surface-chrome-border)'), true);
    assert.equal(buttons[1].classes.has('dark:hover:bg-(--surface-chrome-border-dark)'), true);
    assert.equal(buttons.some(button => [...button.classes].some(name => name.includes('bg-gray-'))), false);
});
