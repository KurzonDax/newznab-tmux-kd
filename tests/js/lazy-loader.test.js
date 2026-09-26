import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test, { mock } from 'node:test';
import vm from 'node:vm';

// Pins LOAD_BUDGET_MS in lazy-loader.js: changing the budget should change this test too.
const BUDGET_MS = 4000;

test.beforeEach(() => mock.timers.enable({ apis: ['setTimeout'] }));
test.afterEach(() => mock.timers.reset());

function element(xData, events, extra = {}) {
    const attributes = new Map([['x-data', xData], ...Object.entries(extra)]);
    return {
        getAttribute: name => attributes.has(name) ? attributes.get(name) : null,
        hasAttribute: name => attributes.has(name),
        setAttribute(name, value) { attributes.set(name, String(value)); events.push(`mark ${xData}`); },
        removeAttribute(name) { attributes.delete(name); events.push(`unmark ${xData}`); },
    };
}

function deferred() {
    let resolve, reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
}

function loaderSource() {
    return readFileSync(new URL('../../resources/js/alpine/lazy-loader.js', import.meta.url), 'utf8')
        .replace(/^import Alpine from '@alpinejs\/csp';\n/m, '')
        .replace(/^export function/m, 'function')
        .replace(/\bimport\(/g, '__import(');
}

/**
 * Run the loader in a vm context. Imports listed in `hanging` stay pending until
 * the test settles them through `pending[path]`.
 */
function loadLoader({ roots: xData, failing = [], hanging = [], alpine, document, cloaked = [] }) {
    const events = [], errors = [], warnings = [], pending = {};
    const roots = xData.map(value => element(value, events, cloaked.includes(value) ? { 'x-cloak': '' } : {}));
    const context = {
        Alpine: alpine ?? {
            start() { events.push('start'); },
            destroyTree() { events.push('destroyTree'); },
            initTree() { events.push('initTree'); },
        },
        document: document ?? {
            querySelectorAll: selector => selector === '[x-data]' ? roots : [],
            documentElement: { removeAttribute() {} },
        },
        console: {
            error(...args) { errors.push(args); },
            warn(...args) { warnings.push(args); },
        },
        setTimeout: globalThis.setTimeout,
        clearTimeout: globalThis.clearTimeout,
        requestAnimationFrame(callback) { callback(); },
        __import(path) {
            if (hanging.includes(path)) {
                pending[path] ??= deferred();
                return pending[path].promise;
            }
            return failing.includes(path)
                ? Promise.reject(new Error(`Failed to fetch dynamically imported module: ${path}`))
                : Promise.resolve({});
        },
    };
    vm.runInNewContext(loaderSource(), context);
    return { run: () => context.loadAndStart(), roots, events, errors, warnings, pending };
}

// setImmediate, not setTimeout: the mocked timers would hold a setTimeout until ticked.
const settle = () => new Promise(resolve => setImmediate(resolve));

test('a failed import marks every root of that component x-ignore before Alpine starts', async () => {
    const loader = loadLoader({ roots: ['nfoModal', 'tvSearch', 'nfoModal()', '{ open: false }'], failing: ['./components/nfo-modal.js'] });
    const [nfo, tvSearch, nfoCall, inline] = loader.roots;

    await loader.run();

    assert.equal(nfo.getAttribute('x-ignore'), '');
    assert.equal(nfoCall.getAttribute('x-ignore'), '');
    assert.equal(tvSearch.hasAttribute('x-ignore'), false);
    assert.equal(inline.hasAttribute('x-ignore'), false);
    assert.deepEqual(loader.events, ['mark nfoModal', 'mark nfoModal()', 'start']);
    assert.equal(loader.errors.length, 1);
    assert.match(loader.errors[0].join(' '), /nfoModal/);
    assert.ok(loader.errors[0].some(arg => arg instanceof Error));
});

test('two component names backed by one failed file are both marked and each logged once', async () => {
    const loader = loadLoader({ roots: ['tvFilesDialog', 'tvImageDialog', 'nfoModal'], failing: ['./components/tv-dialogs.js'] });
    const [files, image, nfo] = loader.roots;

    await loader.run();

    assert.equal(files.getAttribute('x-ignore'), '');
    assert.equal(image.getAttribute('x-ignore'), '');
    assert.equal(nfo.hasAttribute('x-ignore'), false);
    assert.equal(loader.events.filter(event => event === 'start').length, 1);
    assert.equal(loader.events.at(-1), 'start');
    const logged = loader.errors.map(args => args.join(' '));
    assert.equal(logged.length, 2);
    assert.equal(logged.filter(line => line.includes('tvFilesDialog')).length, 1);
    assert.equal(logged.filter(line => line.includes('tvImageDialog')).length, 1);
});

test('when every import loads nothing is marked and Alpine starts once', async () => {
    const loader = loadLoader({ roots: ['nfoModal', 'tvSearch'] });

    await loader.run();

    assert.deepEqual(loader.events, ['start']);
    assert.equal(loader.errors.length, 0);
});

test('when every import loads within the budget the budget never fires', async () => {
    const loader = loadLoader({ roots: ['nfoModal', 'tvSearch'] });

    await loader.run();
    mock.timers.tick(BUDGET_MS * 2);
    await settle();

    assert.deepEqual(loader.events, ['start']);
    assert.equal(loader.errors.length, 0);
    assert.equal(loader.warnings.length, 0);
});

test('a hung import stops blocking Alpine once the budget expires', async () => {
    const loader = loadLoader({ roots: ['nfoModal', 'tvSearch', 'nfoModal()'], hanging: ['./components/nfo-modal.js'], cloaked: ['nfoModal'] });
    const [nfo, tvSearch, nfoCall] = loader.roots;

    let started = false;
    const run = loader.run().then(() => { started = true; });
    await settle();
    mock.timers.tick(BUDGET_MS - 1);
    await settle();
    assert.equal(started, false);
    assert.deepEqual(loader.events, []);

    mock.timers.tick(1);
    await run;

    assert.deepEqual(loader.events, ['mark nfoModal', 'mark nfoModal()', 'start']);
    assert.equal(nfo.getAttribute('x-ignore'), '');
    assert.equal(nfoCall.getAttribute('x-ignore'), '');
    assert.equal(nfo.getAttribute('x-cloak'), '');
    assert.equal(tvSearch.hasAttribute('x-ignore'), false);
    assert.equal(loader.warnings.length, 1);
    assert.match(loader.warnings[0].join(' '), /nfoModal/);
    assert.equal(loader.errors.length, 0);
});

test('an import that fails after the budget leaves its roots ignored and logs once', async () => {
    const loader = loadLoader({ roots: ['nfoModal', 'tvSearch'], hanging: ['./components/nfo-modal.js'] });
    const [nfo] = loader.roots;

    const run = loader.run();
    await settle();
    mock.timers.tick(BUDGET_MS);
    await run;
    loader.pending['./components/nfo-modal.js'].reject(new Error('Failed to fetch dynamically imported module'));
    await settle();

    assert.deepEqual(loader.events, ['mark nfoModal', 'start']);
    assert.equal(nfo.getAttribute('x-ignore'), '');
    assert.equal(loader.errors.length, 1);
    assert.match(loader.errors[0].join(' '), /nfoModal/);
    assert.ok(loader.errors[0].some(arg => arg instanceof Error));
});

/*
 * A stand-in DOM, just enough for the real @alpinejs/csp build: attribute
 * selectors only, and a MutationObserver that reports attribute changes in a
 * microtask, as a browser does. Alpine reads these from the global scope.
 */
function installStandInDom() {
    const observers = new Set();
    const record = (target, attributeName, oldValue) => observers.forEach(observer => observer.record({
        type: 'attributes', target, attributeName, oldValue, addedNodes: [], removedNodes: [],
    }));

    class Node {}
    class Element extends Node {
        constructor(tag) {
            super();
            Object.assign(this, { tagName: tag.toUpperCase(), nodeType: 1, _attrs: [], children: [], parentNode: null, style: {} });
        }
        get attributes() { return this._attrs.map(({ name, value }) => ({ name, value })); }
        getAttribute(name) { return this._attrs.find(a => a.name === name)?.value ?? null; }
        hasAttribute(name) { return this._attrs.some(a => a.name === name); }
        setAttribute(name, value) {
            const attr = this._attrs.find(a => a.name === name);
            const old = attr ? attr.value : null;
            attr ? attr.value = String(value) : this._attrs.push({ name, value: String(value) });
            record(this, name, old);
        }
        removeAttribute(name) {
            const i = this._attrs.findIndex(a => a.name === name);
            if (i < 0) return;
            const [{ value }] = this._attrs.splice(i, 1);
            record(this, name, value);
        }
        get parentElement() { return this.parentNode instanceof Element ? this.parentNode : null; }
        get firstElementChild() { return this.children[0] ?? null; }
        get nextElementSibling() { return this.parentNode?.children[this.parentNode.children.indexOf(this) + 1] ?? null; }
        get isConnected() { let node = this; while (node.parentNode) node = node.parentNode; return node === document; }
        appendChild(child) { child.parentNode = this; this.children.push(child); return child; }
        contains(other) { for (; other; other = other.parentNode) if (other === this) return true; return false; }
        matches(selector) { return selector.split(',').some(s => this.hasAttribute(s.trim().match(/^\[([^\]=]+)\]$/)?.[1])); }
        querySelectorAll(selector) {
            const found = [];
            const visit = el => el.children.forEach(child => { if (child.matches(selector)) found.push(child); visit(child); });
            visit(this);
            return found;
        }
        dispatchEvent() { return true; }
        addEventListener() {}
        removeEventListener() {}
    }

    class MutationObserver {
        constructor(callback) { this.callback = callback; this.records = []; this.active = false; observers.add(this); }
        observe() { this.active = true; }
        disconnect() { this.active = false; this.records = []; }
        takeRecords() { return this.records.splice(0); }
        record(entry) {
            if (!this.active || this.records.push(entry) > 1) return;
            queueMicrotask(() => {
                const records = this.takeRecords();
                if (records.length) this.callback(records);
            });
        }
    }

    const html = new Element('html');
    const body = new Element('body');
    const document = {
        nodeType: 9, documentElement: html, body,
        createElement: tag => new Element(tag),
        querySelector: selector => document.querySelectorAll(selector)[0] ?? null,
        querySelectorAll: selector => html.querySelectorAll(selector),
        dispatchEvent() { return true; }, addEventListener() {}, removeEventListener() {},
    };
    html.parentNode = document;
    html.appendChild(body);

    const globals = {
        document, MutationObserver, Element, Node, window: globalThis,
        ShadowRoot: class {}, HTMLIFrameElement: class {}, HTMLScriptElement: class {}, HTMLTemplateElement: class {},
        CustomEvent: class { constructor(type, init) { this.type = type; Object.assign(this, init); } },
    };
    const saved = Object.fromEntries(Object.keys(globals).map(key => [key, Object.getOwnPropertyDescriptor(globalThis, key)]));
    Object.assign(globalThis, globals);
    const restore = () => Object.entries(saved).forEach(([key, descriptor]) =>
        descriptor ? Object.defineProperty(globalThis, key, descriptor) : delete globalThis[key]);
    return { document, body, Element, restore };
}

test('an import that arrives after the budget brings its component to life in real Alpine', async t => {
    const { document, body, Element, restore } = installStandInDom();
    t.after(restore);
    const { default: Alpine } = await import(new URL('../../node_modules/@alpinejs/csp/dist/module.esm.js', import.meta.url));
    const root = body.appendChild(new Element('div'));
    root.setAttribute('x-data', 'nfoModal');
    const frame = root.appendChild(new Element('div'));
    frame.setAttribute('x-cloak', '');

    const loader = loadLoader({ roots: [], hanging: ['./components/nfo-modal.js'], alpine: Alpine, document });
    const run = loader.run();
    mock.timers.tick(BUDGET_MS);
    await run;
    await settle();
    assert.equal(root._x_ignore, true);
    assert.equal(frame.hasAttribute('x-cloak'), true);

    const inits = [];
    Alpine.data('nfoModal', () => ({ init() { inits.push('nfoModal'); } }));
    loader.pending['./components/nfo-modal.js'].resolve({});
    await settle();

    assert.deepEqual(inits, ['nfoModal']);
    assert.equal(root.hasAttribute('x-ignore'), false);
    assert.equal(frame.hasAttribute('x-cloak'), false);
    assert.equal(loader.errors.length, 0);
    assert.equal(loader.warnings.length, 1);
});
