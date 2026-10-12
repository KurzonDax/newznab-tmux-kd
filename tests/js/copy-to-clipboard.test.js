import assert from 'node:assert/strict';
import test, { mock } from 'node:test';
import { copyToClipboard } from '../../resources/js/alpine/components/profile-edit-component.js';

test.beforeEach(() => mock.timers.enable({ apis: ['setTimeout'] }));
test.afterEach(() => {
    mock.timers.reset();
    delete globalThis.document;
    delete globalThis.navigator;
});

/** A page holding text inputs by id, and a clipboard that records what it is handed. */
function page(inputs) {
    const written = [];
    globalThis.document = {
        getElementById: id => (id in inputs ? { value: inputs[id], select() {}, setSelectionRange() {} } : null),
    };
    Object.defineProperty(globalThis, 'navigator', {
        configurable: true,
        writable: true,
        value: { clipboard: { writeText(text) { written.push(text); return Promise.resolve(); } } },
    });
    return written;
}

// setImmediate, not setTimeout: the mocked timers would hold a setTimeout until ticked.
const settle = () => new Promise(resolve => setImmediate(resolve));

test('copy puts the value of the named input on the clipboard and shows the check for two seconds', async () => {
    const written = page({ fullFeedUrl: 'https://indexer.test/rss/full-feed?dl=1', cartFeedUrl: 'https://indexer.test/rss/cart' });
    const button = copyToClipboard();
    assert.equal(button.copied, false);

    button.copy('cartFeedUrl');
    await settle();

    assert.deepEqual(written, ['https://indexer.test/rss/cart']);
    assert.equal(button.copied, true);

    mock.timers.tick(1999);
    assert.equal(button.copied, true);
    mock.timers.tick(1);
    assert.equal(button.copied, false);
});

test('copy does nothing when no input has that id', async () => {
    const written = page({});
    const button = copyToClipboard();

    button.copy('missing');
    await settle();

    assert.deepEqual(written, []);
    assert.equal(button.copied, false);
});
