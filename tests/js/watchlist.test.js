import test from 'node:test';
import assert from 'node:assert/strict';
import { watchlistPicker, watchlistPage } from '../../resources/js/alpine/components/watchlist-component.js';

function fixture() {
    const messages = [], events = [], requests = [];
    const key = 'movies:0137523';
    const buttons = Array.from({ length: 3 }, () => ({ dataset: { watchKey: key, watched: '0' }, querySelector: () => null, setAttribute() {} }));
    const count = { textContent: '0', hidden: true };
    globalThis.document = { querySelector: () => ({ content: 'csrf' }), querySelectorAll: selector => selector === '[data-watch-key]' ? buttons : selector === '[data-watchlist-count]' ? [count] : [] };
    globalThis.window = { dispatchEvent: event => events.push(event.detail), showToast: (message, type, action) => messages.push({ message, type, action }) };
    let resource = { root: 'movies', id: '0137523', title: '<A Movie>', listName: 'My Movies', watched: false, selected: [2030, 2040], categories: [{ id: 2030, label: 'HD' }, { id: 2040, label: 'UHD' }], url: '/watchlist/movies/0137523', watchlistUrl: '/watchlist?tab=movies', counts: { movies: 1, tv: 2 } };
    globalThis.fetch = async (url, options) => {
        requests.push({ url, ...options });
        if (options.method === 'POST') resource = { ...resource, watched: true, selected: JSON.parse(options.body).categories || resource.selected };
        if (options.method === 'DELETE') resource = { ...resource, watched: false, removedCategories: resource.selected, undoToken: 'bound-original-categories', counts: { movies: 0, tv: 2 } };
        return { ok: true, json: async () => resource };
    };
    const picker = watchlistPicker();
    picker.current = resource; picker.selected = [2040]; picker.open = true;
    return { picker, requests, buttons, messages, events, count };
}

test('saving picker choices updates title, cover and row controls and offers the Watchlist link', async () => {
    const { picker, requests, buttons, messages, events, count } = fixture();
    await picker.save();
    assert.equal(requests[0].method, 'POST');
    assert.deepEqual(JSON.parse(requests[0].body), { categories: [2040] });
    assert.equal(requests[0].headers['X-CSRF-TOKEN'], 'csrf');
    assert.ok(buttons.every(button => button.dataset.watched === '1'));
    assert.equal(count.textContent, 3);
    assert.equal(picker.open, false);
    assert.equal(events[0].id, '0137523');
    assert.deepEqual(messages[0].action, { label: 'Open', href: '/watchlist?tab=movies' });
    assert.match(messages[0].message, /Added <A Movie> to My Movies · UHD/);
});

test('removal offers Undo that restores the previously saved categories', async () => {
    const { picker, requests, buttons, messages } = fixture();
    await picker.remove('/watchlist/movies/0137523');
    assert.equal(requests[0].method, 'DELETE');
    assert.ok(buttons.every(button => button.dataset.watched === '0'));
    assert.equal(messages[0].action.label, 'Undo');
    await messages[0].action.callback();
    assert.equal(requests[1].method, 'POST');
    assert.deepEqual(JSON.parse(requests[1].body), { undo_token: 'bound-original-categories' });
    assert.ok(buttons.every(button => button.dataset.watched === '1'));
});

test('empty choices and failed saves keep the picker open without updating followed state', async () => {
    const { picker, requests, events, messages } = fixture();
    picker.selected = [];
    await picker.save();
    assert.equal(requests.length, 0);
    assert.equal(messages[0].type, 'warning');
    picker.selected = [2030];
    globalThis.fetch = async () => ({ ok: false, json: async () => ({ errors: { categories: ['Choose an available category.'] } }) });
    await picker.save();
    assert.equal(picker.open, true);
    assert.equal(picker.saving, false);
    assert.equal(picker.error, 'Choose an available category.');
    assert.equal(events.length, 0);
});


test('closing a picker remains available while a save is pending', () => {
    const { picker } = fixture(); picker.saving = true;
    picker.close(); assert.equal(picker.open, false);
});

test('find keeps current content when a response redirects or is not a list fragment', async () => {
    for (const redirected of [true, false]) {
        globalThis.window = { location: { href: 'https://indexer.test/watchlist?tab=movies' }, history: { replaceState() { throw new Error('Should not replace URL'); } } };
        globalThis.fetch = async () => ({ ok: true, redirected, text: async () => '<html>Sign in</html>' });
        const page = watchlistPage(); page.pageRoot = { dataset: { watchlistRoot: 'movies' }, querySelector: () => ({ value: 'Dune' }) };
        page.$refs = { lists: { innerHTML: 'Existing list' } };
        await page.findTitles();
        assert.equal(page.$refs.lists.innerHTML, 'Existing list');
        assert.match(page.error, /Could not load/);
    }
});

test('refreshing the list returns keyboard focus to the replacement title control', async () => {
    const old = { dataset: { watchKey: 'movies:0137523' }, closest() { return this; } };
    const replacement = { dataset: { watchKey: 'movies:0137523' }, focus() { document.activeElement = this; } };
    globalThis.document = { body: {}, activeElement: old };
    globalThis.window = { location: { href: 'https://indexer.test/watchlist?tab=movies' }, history: { replaceState() {} } };
    globalThis.fetch = async () => ({ ok: true, redirected: false, text: async () => '<div data-watchlist-fragment>Updated list</div>' });
    const page = watchlistPage();
    page.pageRoot = { dataset: { watchlistRoot: 'movies' }, querySelector: () => ({ value: 'Dune', focus() { throw new Error('Existing title should keep focus'); } }) };
    page.$refs = { lists: { contains: element => element === old, querySelectorAll: () => [replacement], set innerHTML(html) { document.activeElement = document.body; } } };
    await page.findTitles();
    assert.equal(document.activeElement, replacement);
});
