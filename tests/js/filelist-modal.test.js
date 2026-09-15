import assert from 'node:assert/strict';
import test from 'node:test';
import { filelistModal } from '../../resources/js/alpine/components/filelist-modal-component.js';

function modal() {
    const component = filelistModal();
    component.$refs = { content: { innerHTML: '' } };
    return component;
}

function reply(title, page = 1, per = 100) {
    return { ok: true, json: async () => ({ release: { searchname: title }, files: [{ index: 0, title, size: 0 }], total: 201, page, per, last_page: 3 }) };
}

test('modal paginates and replaces content, resetting page on per changes', async () => {
    const component = modal();
    const requests = [];
    globalThis.fetch = async (url, options) => { requests.push({ url, options }); return reply(url); };
    await component.show('abc');
    await component.filesNext();
    assert.match(requests[1].url, /page=2&per=100/);
    assert.doesNotMatch(component.$refs.content.innerHTML, /page=1/);
    await component.filesPerChanged({ target: { value: '24' } });
    assert.match(requests[2].url, /page=1&per=24/);
    assert.match(component.$refs.content.innerHTML, /0 B/);
});

test('closing or switching releases aborts and ignores stale successes and failures', async () => {
    const component = modal();
    const requests = [];
    globalThis.fetch = (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject }));
    const first = component.show('first');
    const second = component.show('second');
    assert.equal(requests[0].options.signal.aborted, true);
    requests[1].resolve(reply('second'));
    await second;
    requests[0].resolve(reply('first'));
    await first;
    assert.equal(component.releaseName, 'second');
    assert.doesNotMatch(component.$refs.content.innerHTML, /first/);
    const third = component.loadFilePage(2);
    component.close();
    requests[2].reject(new Error('late error'));
    await third;
    assert.equal(component.open, false);
    assert.equal(component.$refs.content.innerHTML, '');
    assert.equal(component.filesLoading, false);
});
