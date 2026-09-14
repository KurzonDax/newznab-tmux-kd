import assert from 'node:assert/strict';
import test from 'node:test';
import { releaseBrowser } from '../../resources/js/alpine/components/release-browser-component.js';

function browser(...guids) {
    const rows = guids.map(value => ({
        value, checked: false,
        row: { dataset: {} },
        closest() { return this.row; },
    }));
    const header = { checked: false, indeterminate: false };
    const component = releaseBrowser();
    component.$el = {
        dataset: { root: 'all', per: '48', thumbs: '0', lastPage: '3' },
        querySelectorAll(selector) {
            if (selector === '[data-release-select]') return rows;
            if (selector === '[data-select-all]') return [header];
            return [];
        },
    };
    component.init();
    return { component, rows, header };
}

test('select all and archive download stay within one browser and clear its selection', () => {
    const navigations = [];
    globalThis.window = { location: { assign: value => navigations.push(value) } };
    const first = browser('guid-a', 'guid-b');
    const second = browser('guid-c');
    first.component.selectAll({ target: { checked: true } });
    assert.ok(first.rows.every(row => row.checked));
    assert.equal(second.rows[0].checked, false);
    assert.equal(first.header.checked, true);
    first.component.downloadSelected();
    assert.deepEqual(navigations, ['/getnzb?id=guid-a%2Cguid-b&zip=1']);
    assert.ok(first.rows.every(row => !row.checked));
    assert.equal(first.header.checked, false);
    assert.equal(second.rows[0].checked, false);
});

test('go to page warns on invalid input and never navigates outside the available pages', () => {
    const navigations = [], toasts = [];
    globalThis.window = {
        location: { href: 'https://nntmux.test/browse/all?group=alt.binaries.test&page=2', assign: value => navigations.push(value) },
        showToast: (...args) => toasts.push(args),
    };
    const { component } = browser('guid-a');
    for (const value of ['0', '4', '-1', '1.5', 'abc', '']) {
        component.goToPage({ target: { value } });
    }
    assert.equal(navigations.length, 0);
    assert.equal(toasts.length, 6);
    assert.ok(toasts.every(toast => toast[1] === 'warning'));
    component.goToPage({ target: { value: '3' } });
    assert.deepEqual(navigations, ['https://nntmux.test/browse/all?group=alt.binaries.test&page=3']);
});

test('per-page saves only that root preference before navigation, while sort clears the letter and page', async () => {
    const navigations = [], requests = [];
    globalThis.document = { querySelector: () => ({ content: 'csrf-token' }) };
    globalThis.window = {
        location: { href: 'https://nntmux.test/browse/all?group=test&letter=A&page=3&size=l&ob=size_desc', assign: value => navigations.push(value) },
        showToast: () => {},
    };
    let finish;
    globalThis.fetch = (url, options) => {
        requests.push({ url, options });
        return new Promise(resolve => { finish = resolve; });
    };
    const { component } = browser('guid-a');
    const saving = component.changePreference({ currentTarget: { dataset: { preference: 'per', value: '24' } } });
    assert.equal(navigations.length, 0);
    assert.equal(requests[0].url, '/profile/update-view');
    assert.deepEqual(JSON.parse(requests[0].options.body), { root: 'all', per: 24 });
    assert.equal(requests[0].options.headers['X-CSRF-TOKEN'], 'csrf-token');
    finish({ ok: true, json: async () => ({ success: true }) });
    await saving;
    const changed = new URL(navigations[0]);
    assert.equal(changed.searchParams.get('group'), 'test');
    assert.equal(changed.searchParams.get('size'), 'l');
    assert.equal(changed.searchParams.get('per'), '24');
    assert.equal(changed.searchParams.has('page'), false);
    component.sortListing({ target: { value: 'title' } });
    const sorted = new URL(navigations[1]);
    assert.equal(sorted.searchParams.get('sort'), 'title');
    assert.equal(sorted.searchParams.has('letter'), false);
    assert.equal(sorted.searchParams.has('ob'), false);
    assert.equal(sorted.searchParams.has('page'), false);
});

test('basket toggles use the server result and bulk add updates only selected rows', async () => {
    const requests = [], notices = [], counts = [];
    globalThis.document = { querySelector: () => ({ content: 'csrf-token' }) };
    globalThis.window = { showToast: (...args) => notices.push(args) };
    globalThis.fetch = async (url, options) => {
        requests.push({ url, body: JSON.parse(options.body) });
        return { ok: true, json: async () => ({ success: true, cartCount: requests.length }) };
    };
    const { component, rows } = browser('guid-a', 'guid-b');
    component.$store = { cart: { setCount: n => counts.push(n) } };
    const buttons = rows.map(row => ({
        dataset: { guid: row.value, inBasket: '0' }, disabled: false,
        setAttribute(name, value) { this[name] = value; },
    }));
    const query = component.$el.querySelectorAll;
    component.$el.querySelectorAll = selector => selector === '[data-row-action="basket"]' ? buttons : query(selector);
    await component.toggleBasket({ currentTarget: buttons[0] });
    assert.equal(buttons[0].dataset.inBasket, '1');
    assert.equal(buttons[0]['aria-label'], 'Remove from basket');
    await component.toggleBasket({ currentTarget: buttons[0] });
    assert.equal(buttons[0].dataset.inBasket, '0');
    assert.equal(requests[1].url, '/cart/delete/guid-a');
    rows[1].checked = true;
    await component.addSelectedToBasket();
    assert.deepEqual(requests[2].body, { id: 'guid-b' });
    assert.equal(buttons[0].dataset.inBasket, '0');
    assert.equal(buttons[1].dataset.inBasket, '1');
    assert.deepEqual(counts, [1, 2, 3]);
    assert.equal(buttons[0].disabled, false);
});

test('removing a basket-page row reloads its count and bounded page after success', async () => {
    let reloads = 0;
    globalThis.document = { querySelector: () => ({ content: 'csrf-token' }) };
    globalThis.window = { showToast: () => {}, location: { reload: () => { reloads++; } } };
    globalThis.fetch = async () => ({ ok: true, json: async () => ({ success: true, cartCount: 0 }) });
    const { component } = browser('guid-a');
    component.$el.dataset.basketOnly = '1';
    component.$store = { cart: { setCount() {} } };
    await component.toggleBasket({ currentTarget: { dataset: { guid: 'guid-a', inBasket: '1' } } });
    assert.equal(reloads, 1);
});

test('event handlers keep the browser scope when Alpine exposes the clicked control as $el', () => {
    const navigations = [];
    globalThis.window = { location: { assign: value => navigations.push(value) }, showToast: () => {} };
    const { component, rows } = browser('guid-a', 'guid-b');
    component.$el = { dataset: {}, querySelectorAll: () => [] };
    component.selectAll({ target: { checked: true } });
    assert.ok(rows.every(row => row.checked));
    component.goToPage({ target: { value: '999' } });
    assert.equal(navigations.length, 0);
});
