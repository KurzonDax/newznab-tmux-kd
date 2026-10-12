import assert from 'node:assert/strict';
import test from 'node:test';
import { imageFallback } from '../../resources/js/alpine/components/admin/image-fallback-component.js';

const COVER = 'https://indexer.test/covers/book/7.jpg';
const PLACEHOLDER = 'https://indexer.test/assets/images/no-cover.png';

/** An <img> stand-in: `fail()` fires its error listeners, as the browser does on a failed load. */
function image({ complete = false, naturalWidth = 0 } = {}) {
    const listeners = [];
    return {
        src: COVER,
        complete,
        naturalWidth,
        dataset: { fallbackSrc: PLACEHOLDER },
        addEventListener(name, listener) { if (name === 'error') listeners.push(listener); },
        fail() { listeners.forEach(listener => listener({ type: 'error' })); },
    };
}

function mount(img) {
    const component = imageFallback();
    component.$el = img;
    component.init();
    return component;
}

test('an image that fails to load is switched to the placeholder', () => {
    const img = image();
    mount(img);
    assert.equal(img.src, COVER);

    img.fail();

    assert.equal(img.src, PLACEHOLDER);
});

test('a placeholder that fails too is not retried', () => {
    const img = image();
    mount(img);
    img.fail();

    img.src = 'https://indexer.test/assets/images/no-cover.png?failed';
    img.fail();

    assert.equal(img.src, 'https://indexer.test/assets/images/no-cover.png?failed');
});

test('an image that had already failed before the component started is switched at once', () => {
    const img = image({ complete: true, naturalWidth: 0 });

    mount(img);

    assert.equal(img.src, PLACEHOLDER);
});

test('an image that loaded, or is still loading, keeps its own address', () => {
    const loaded = image({ complete: true, naturalWidth: 120 });
    const loading = image({ complete: false, naturalWidth: 0 });

    mount(loaded);
    mount(loading);

    assert.equal(loaded.src, COVER);
    assert.equal(loading.src, COVER);
});
