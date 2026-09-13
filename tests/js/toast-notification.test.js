import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

function loadToasts({ publicPage = true, messages = {} } = {}) {
    let store;
    const timers = [];
    const window = { location: { href: 'https://indexer.test/browse' } };
    const context = {
        Alpine: { store(name, value) { if (value) store = value; return store; } },
        document: {
            querySelector: () => publicPage ? {} : null,
            getElementById: () => ({ dataset: { messages: JSON.stringify(messages) } }),
        },
        window, URL,
        setTimeout(callback, delay) { timers.push({ callback, delay }); },
    };
    const source = readFileSync(new URL('../../resources/js/alpine/stores/toast.js', import.meta.url), 'utf8');
    vm.runInNewContext(source.replace(/^import Alpine from '@alpinejs\/csp';\n/m, ''), context);
    store.init();
    return { store, timers, show: window.showToast };
}

test('public notifications stack and dismiss after the prototype interval', () => {
    const { store, timers, show } = loadToasts();
    show('Saved', 'success');
    show('Pick a category', 'warning');
    assert.equal(store.items.length, 2);
    assert.equal(timers[0].delay, 3200);
    timers[0].callback();
    assert.equal(store.items[0].removing, true);
    timers.at(-1).callback();
    assert.equal(store.items.length, 1);
    assert.equal(store.items[0].message, 'Pick a category');
});

test('admin notifications retain their five-second interval', () => {
    const { timers, show } = loadToasts({ publicPage: false });
    show('Saved', 'success');
    assert.equal(timers[0].delay, 5000);
});

test('all forum messages appear alongside ordinary flashes of the same kind', () => {
    const { store } = loadToasts({ messages: {
        success: ['Saved.', 'Reply added.', 'Thread created.'],
        warning: ['Thread is locked.'],
    } });
    assert.equal(store.items.length, 4);
    assert.equal(store.items[0].message, 'Saved.');
    assert.equal(store.items[2].message, 'Thread created.');
    assert.equal(store.items[3].type, 'warning');
});

test('flash messages preserve text and structured Open links', () => {
    const { store } = loadToasts({ messages: {
        success: { message: '<b>Added</b>', action: { label: 'Open', href: '/mymovies' } },
        error: ['First error', 'Second error'],
    } });
    assert.equal(store.items.length, 3);
    assert.equal(store.items[0].message, '<b>Added</b>');
    assert.equal(store.items[0].action.label, 'Open');
    assert.equal(store.items[0].action.href, 'https://indexer.test/mymovies');
    assert.equal(store.items[2].message, 'Second error');
});

test('Undo callbacks run once and unsafe action URLs are omitted', () => {
    const { store, show } = loadToasts();
    let restored = 0;
    const id = show('Removed', 'info', { label: 'Undo', callback: () => restored++ });
    store.activateAction(id);
    store.activateAction(id);
    assert.equal(restored, 1);
    assert.equal(store.items[0].removing, true);
    show('Open this', 'info', { label: 'Open', href: 'javascript:alert(1)' });
    assert.equal(store.items[1].action, null);
});
