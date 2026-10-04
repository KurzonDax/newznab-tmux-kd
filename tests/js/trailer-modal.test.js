import assert from 'node:assert/strict';
import test from 'node:test';
import { trailerModal } from '../../resources/js/alpine/components/trailer-modal-component.js';

test('trailer close and teardown release the player and document listener', () => {
    const events = {};
    globalThis.document = { createElement: () => ({}), addEventListener: (name, listener) => { events[name] = listener; }, removeEventListener: name => { delete events[name]; } };
    const modal = trailerModal();
    const container = { children: [], replaceChildren(...children) { this.children = children; } };
    modal.$el = { querySelector: () => container };
    modal.initModal = () => {};
    modal.init();
    events.click({ target: { closest: () => ({ dataset: { trailerUrl: 'https://www.youtube-nocookie.com/embed/Way9Dexny3w' } }) } });
    assert.equal(modal.open, true);
    assert.match(modal.url, /Way9Dexny3w/);
    assert.equal(container.children.length, 1);
    assert.equal(container.children[0].tabIndex, 0);
    modal.close();
    assert.equal(modal.open, false);
    assert.equal(modal.url, '');
    assert.equal(container.children.length, 0);
    modal.destroy();
    assert.equal(events.click, undefined);
});
