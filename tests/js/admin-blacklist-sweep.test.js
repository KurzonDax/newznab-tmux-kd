import assert from 'node:assert/strict';
import test, { mock } from 'node:test';
import { blacklistSweep } from '../../resources/js/alpine/components/admin/blacklist-sweep-component.js';
import { adminRowDelete } from '../../resources/js/alpine/components/admin/row-delete-component.js';

const START_URL = 'https://indexer.test/admin/binaryblacklist-sweep';
const STATUS_URL = 'https://indexer.test/admin/binaryblacklist-sweep/status';

const RUNNING_RUN = {
    id: '20261026-120000-abc', mode: 'dry-run', rule_id: 12, started_at: '2026-10-26T12:00:00Z',
    finished_at: null, exit_code: null, matched_count: 0, removed_count: 0,
};
const FINISHED_RUN = { ...RUNNING_RUN, finished_at: '2026-10-26T12:00:05Z', exit_code: 0, matched_count: 3 };

/**
 * The admin blacklist list: `init()` runs against the root's dataset, then `$el` becomes
 * the clicked button, which carries no URLs, as Alpine does for an `@click` expression.
 * `respond(url, options)` builds the fetch outcome for each request.
 */
function mountList({ respond, initialStatus = {} } = {}) {
    const confirms = [], requests = [], toasts = [];

    globalThis.window = {
        showConfirm(options) { confirms.push(options); },
        showToast(...args) { toasts.push(args); },
    };
    globalThis.document = { querySelector: selector => (selector === 'meta[name="csrf-token"]' ? { content: 'csrf-token-1' } : null) };
    globalThis.fetch = (url, options = {}) => { requests.push({ url, options }); return Promise.resolve(respond(url, options)); };

    const component = blacklistSweep();
    component.$el = { dataset: { startUrl: START_URL, statusUrl: STATUS_URL, initialStatus: JSON.stringify(initialStatus) } };
    component.init();
    component.$el = { dataset: {} };

    return { component, confirms, requests, toasts };
}

const answer = (status, body) => ({ ok: status >= 200 && status < 300, status, json: async () => body });

const settle = async () => {
    for (let i = 0; i < 5; i++) await new Promise(resolve => setImmediate(resolve));
};

test.beforeEach(() => mock.timers.enable({ apis: ['setInterval'] }));

test.afterEach(() => {
    mock.timers.reset();
    delete globalThis.window;
    delete globalThis.document;
    delete globalThis.fetch;
});

test('confirming a row broom posts the mode and that rule to the start URL, then polls the status URL', async () => {
    let status = { running: true, current: RUNNING_RUN, last: null };
    const page = mountList({
        respond: url => (url === START_URL
            ? answer(202, { run: RUNNING_RUN, message: 'Blacklist sweep started.' })
            : answer(200, status)),
    });

    page.component.confirmSweep(12);
    page.confirms[0].onConfirm();
    await settle();

    assert.equal(page.requests.length, 1);
    const [{ url, options }] = page.requests;
    assert.equal(url, START_URL);
    assert.equal(options.method, 'POST');
    assert.equal(options.headers['X-CSRF-TOKEN'], 'csrf-token-1');
    assert.deepEqual(JSON.parse(options.body), { mode: 'dry-run', rule_id: 12 });
    assert.deepEqual(page.toasts, [['Blacklist sweep started.', 'success']]);
    assert.equal(page.component.running, true);

    mock.timers.tick(3000);
    await settle();
    assert.equal(page.requests.length, 2);
    assert.equal(page.requests[1].url, STATUS_URL);

    status = { running: false, current: null, last: FINISHED_RUN };
    mock.timers.tick(3000);
    await settle();
    assert.equal(page.requests[2].url, STATUS_URL);
    assert.equal(page.component.running, false);
    assert.equal(page.component.pollTimer, null);
});

test('confirming the header button posts the mode with no rule to the start URL', async () => {
    const page = mountList({
        respond: url => (url === START_URL
            ? answer(202, { run: { ...RUNNING_RUN, rule_id: null }, message: 'Blacklist sweep started.' })
            : answer(200, { running: true, current: RUNNING_RUN, last: null })),
    });

    page.component.mode = 'delete';
    page.component.confirmSweep();
    page.confirms[0].onConfirm();
    await settle();

    assert.equal(page.requests.length, 1);
    assert.equal(page.requests[0].url, START_URL);
    assert.deepEqual(JSON.parse(page.requests[0].options.body), { mode: 'delete' });

    mock.timers.tick(3000);
    await settle();
    assert.equal(page.requests[1].url, STATUS_URL);
});

test('a refused start shows the error, refreshes from the status URL and polls the run already in progress until it ends', async () => {
    let status = { running: true, current: RUNNING_RUN, last: null };
    const page = mountList({
        respond: url => (url === START_URL
            ? answer(409, { message: 'A blacklist sweep is already running.' })
            : answer(200, status)),
    });

    page.component.confirmSweep(12);
    page.confirms[0].onConfirm();
    await settle();

    assert.deepEqual(page.toasts, [['A blacklist sweep is already running.', 'error']]);
    assert.deepEqual(page.requests.map(request => request.url), [START_URL, STATUS_URL]);
    assert.equal(page.component.running, true);
    assert.notEqual(page.component.pollTimer, null);

    status = { running: false, current: null, last: FINISHED_RUN };
    mock.timers.tick(3000);
    await settle();

    assert.deepEqual(page.requests.map(request => request.url), [START_URL, STATUS_URL, STATUS_URL]);
    assert.equal(page.component.running, false);
    assert.equal(page.component.pollTimer, null);
});

test('a refused start whose status shows nothing running leaves the controls enabled and does not poll', async () => {
    const page = mountList({
        respond: url => (url === START_URL
            ? answer(422, { message: 'The selected mode is invalid.' })
            : answer(200, { running: false, current: null, last: FINISHED_RUN })),
    });

    page.component.confirmSweep();
    page.confirms[0].onConfirm();
    await settle();

    assert.deepEqual(page.requests.map(request => request.url), [START_URL, STATUS_URL]);
    assert.equal(page.component.running, false);
    assert.equal(page.component.pollTimer, null);
});

test('init assigns only declared properties that the nested row-delete roots do not share', () => {
    // Alpine writes a property no scope declares onto the outermost component, and the rows'
    // adminRowDelete roots sit inside this one, so a shared name would cross the two.
    const declared = Object.keys(blacklistSweep());
    const page = mountList({ respond: () => answer(200, {}) });

    assert.deepEqual(Object.keys(page.component).filter(key => key !== '$el' && ! declared.includes(key)), []);
    const rowState = Object.entries(adminRowDelete()).filter(([, value]) => typeof value !== 'function').map(([key]) => key);
    assert.deepEqual(declared.filter(key => rowState.includes(key)), []);
});
