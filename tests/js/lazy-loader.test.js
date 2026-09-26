import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

function element(xData, events) {
    const attributes = new Map([['x-data', xData]]);
    return {
        getAttribute: name => attributes.has(name) ? attributes.get(name) : null,
        hasAttribute: name => attributes.has(name),
        setAttribute(name, value) { attributes.set(name, String(value)); events.push(`mark ${xData}`); },
    };
}

function loadLoader({ roots: xData, failing = [] }) {
    const events = [], errors = [];
    const roots = xData.map(value => element(value, events));
    const context = {
        Alpine: { start() { events.push('start'); } },
        document: {
            querySelectorAll: selector => selector === '[x-data]' ? roots : [],
            documentElement: { removeAttribute() {} },
        },
        console: { error(...args) { errors.push(args); } },
        requestAnimationFrame(callback) { callback(); },
        __import: path => failing.includes(path)
            ? Promise.reject(new Error(`Failed to fetch dynamically imported module: ${path}`))
            : Promise.resolve({}),
    };
    const source = readFileSync(new URL('../../resources/js/alpine/lazy-loader.js', import.meta.url), 'utf8')
        .replace(/^import Alpine from '@alpinejs\/csp';\n/m, '')
        .replace(/^export function/m, 'function')
        .replace(/\bimport\(/g, '__import(');
    vm.runInNewContext(source, context);
    return { run: () => context.loadAndStart(), roots, events, errors };
}

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
