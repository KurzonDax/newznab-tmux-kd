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
