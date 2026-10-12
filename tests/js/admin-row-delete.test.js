import assert from 'node:assert/strict';
import test from 'node:test';
import { adminRowDelete } from '../../resources/js/alpine/components/admin/row-delete-component.js';

const REGEX_QUESTION = 'Are you sure you want to delete this regex?';

/**
 * A list page holding one row (`#row-7`) and the delete root inside it.
 * `answer` is what the browser's confirm() returns; `respond` builds the fetch outcome.
 */
function mountRow({ answer = true, respond = async () => ({ ok: true, status: 200 }), dataset = {} } = {}) {
    const questions = [], requests = [], toasts = [];
    const row = { removed: false, remove() { this.removed = true; } };

    globalThis.window = {
        confirm(question) { questions.push(question); return answer; },
        showToast(...args) { toasts.push(args); },
    };
    globalThis.document = { getElementById: id => (id === 'row-7' ? row : null) };
    globalThis.fetch = (url, options) => { requests.push({ url, options }); return respond(); };

    const component = adminRowDelete();
    component.$el = {
        dataset: {
            ajaxUrl: 'https://indexer.test/admin/ajax',
            csrfToken: 'csrf-token-1',
            ajaxAction: 'category_regex_delete',
            rowId: '7',
            question: REGEX_QUESTION,
            deletedMessage: 'Regex deleted',
            ...dataset,
        },
    };
    component.init();

    return { component, row, questions, requests, toasts };
}

const settle = () => new Promise(resolve => setImmediate(resolve));

test.afterEach(() => {
    delete globalThis.window;
    delete globalThis.document;
    delete globalThis.fetch;
});

test('a click asks with the list\'s own question and sends nothing when declined', async () => {
    const page = mountRow({ answer: false });

    page.component.remove();
    await settle();

    assert.deepEqual(page.questions, [REGEX_QUESTION]);
    assert.equal(page.requests.length, 0);
    assert.equal(page.row.removed, false);
    assert.deepEqual(page.toasts, []);
});

test('accepting sends one POST with the action, the row id and the CSRF token', async () => {
    const page = mountRow();

    page.component.remove();
    await settle();

    assert.equal(page.requests.length, 1);
    const [{ url, options }] = page.requests;
    assert.equal(url, 'https://indexer.test/admin/ajax');
    assert.equal(options.method, 'POST');
    assert.equal(options.headers['X-CSRF-TOKEN'], 'csrf-token-1');
    const body = new URLSearchParams(options.body);
    assert.deepEqual([...body.entries()].sort(), [['action', 'category_regex_delete'], ['row_id', '7']]);
});

test('a 2xx answer removes the row and shows the success toast', async () => {
    const page = mountRow({ respond: async () => ({ ok: true, status: 200 }) });

    page.component.remove();
    await settle();

    assert.equal(page.row.removed, true);
    assert.deepEqual(page.toasts, [['Regex deleted', 'success']]);
});

test('the blacklist list asks its own question and names the blacklist in the toast', async () => {
    const page = mountRow({ dataset: {
        ajaxAction: 'binary_blacklist_delete',
        question: 'Are you sure? This will delete the blacklist.',
        deletedMessage: 'Blacklist deleted',
    } });

    page.component.remove();
    await settle();

    assert.deepEqual(page.questions, ['Are you sure? This will delete the blacklist.']);
    assert.equal(new URLSearchParams(page.requests[0].options.body).get('action'), 'binary_blacklist_delete');
    assert.deepEqual(page.toasts, [['Blacklist deleted', 'success']]);
});

test('a 500 answer leaves the row and shows the error toast', async () => {
    const page = mountRow({ respond: async () => ({ ok: false, status: 500 }) });

    page.component.remove();
    await settle();

    assert.equal(page.row.removed, false);
    assert.deepEqual(page.toasts, [['Error', 'error']]);
});

test('a failed request leaves the row and shows the error toast', async () => {
    const page = mountRow({ respond: () => Promise.reject(new TypeError('Failed to fetch')) });

    page.component.remove();
    await settle();

    assert.equal(page.row.removed, false);
    assert.deepEqual(page.toasts, [['Error', 'error']]);
});

test('init assigns only properties the component declares, so a root nested in another component keeps its own row', () => {
    // Alpine writes a property no scope declares onto the outermost component, which every row of the blacklist list shares.
    const declared = Object.keys(adminRowDelete());
    const page = mountRow();

    assert.deepEqual(Object.keys(page.component).filter(key => key !== '$el' && ! declared.includes(key)), []);
});
