import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { modalLifecycle } from '../../resources/js/alpine/components/modal-lifecycle.js';

function environment() {
    const handlers = new Map();
    const document = { body: { style: { overflow: 'auto' } }, activeElement: null,
        addEventListener(name, fn) { handlers.set(fn, name); },
        removeEventListener(name, fn) { handlers.delete(fn); },
    };
    function control() { return { isConnected: true, disabled: false, getClientRects: () => [1], focus() { document.activeElement = this; } }; }
    const trigger = control(); trigger.focus();
    function modal() {
        const first = control(), last = control();
        const dialog = { ownerDocument: document, contains: node => node === first || node === last,
            querySelector: () => null, querySelectorAll: () => [first, last],
        };
        let watch;
        const instance = { ...modalLifecycle(), open: false,
            $el: { querySelector: () => dialog }, $watch(name, fn) { watch = fn; },
            $nextTick(fn) { fn(); }, close() { this.open = false; watch(false); },
        };
        instance.initModal();
        return { instance, first, last, show() { instance.open = true; watch(true); } };
    }
    return { document, trigger, modal, key(event) { for (const [fn, name] of handlers) if (name === 'keydown') fn(event); } };
}

test('opening focuses the dialog, traps Tab, and Escape restores focus and body scrolling', () => {
    const env = environment(), modal = env.modal();
    modal.show();
    assert.equal(env.document.body.style.overflow, 'hidden');
    assert.equal(env.document.activeElement, modal.first);
    modal.last.focus();
    let prevented = false;
    env.key({ key: 'Tab', shiftKey: false, preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(env.document.activeElement, modal.first);
    env.key({ key: 'Escape', preventDefault() {} });
    assert.equal(modal.instance.open, false);
    assert.equal(env.document.body.style.overflow, 'auto');
    assert.equal(env.document.activeElement, env.trigger);
    modal.instance.destroy();
});

test('switching modals closes the first and restores the original external trigger at the end', () => {
    const env = environment(), first = env.modal(), second = env.modal();
    first.show(); second.show();
    assert.equal(first.instance.open, false);
    assert.equal(second.instance.open, true);
    assert.equal(env.document.body.style.overflow, 'hidden');
    second.instance.close();
    assert.equal(env.document.body.style.overflow, 'auto');
    assert.equal(env.document.activeElement, env.trigger);
    first.instance.destroy(); second.instance.destroy();
});


test('NFO actions copy and download the loaded text and reject a stale response', async () => {
    let factory, copied, downloaded;
    const requests = [];
    const notices = [];
    const blobs = [];
    const context = {
        Alpine: { data(name, value) { factory = value; } }, modalLifecycle,
        window: { showToast: (...args) => notices.push(args) },
        document: {
            querySelector: () => null,
            createElement: () => ({ click() { downloaded = this.download; } }),
        },
        navigator: { clipboard: { async writeText(value) { copied = value; } } },
        URL: { createObjectURL(blob) { blobs.push(blob); return 'blob:nfo'; }, revokeObjectURL() {} },
        Blob, setTimeout: callback => callback(),
        DOMParser: class { parseFromString(text) { return { querySelector: () => ({ textContent: text, dataset: { releaseName: 'Example.Release' } }) }; } },
        fetch: url => new Promise(resolve => requests.push({ url, resolve })),
    };
    const source = readFileSync(new URL('../../resources/js/alpine/components/nfo-modal.js', import.meta.url), 'utf8');
    vm.runInNewContext(source.replace(/^import .*?;\n/gm, ''), context);
    const modal = factory();
    modal.openNfo('first-guid');
    modal.close();
    modal.openNfo('second-guid');
    requests[1].resolve({ ok: true, text: async () => 'Current NFO\n  ASCII art' });
    await new Promise(resolve => setImmediate(resolve));
    requests[0].resolve({ ok: true, text: async () => 'Stale NFO' });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(modal.content, 'Current NFO\n  ASCII art');
    assert.equal(modal.releaseName, 'Example.Release');
    assert.equal(modal.detailsUrl(), '/details/second-guid#nfo');
    assert.equal(modal.downloadUrl(), '/getnzb/second-guid');
    await modal.copyText(); modal.downloadNfo();
    assert.equal(copied, modal.content);
    assert.equal(await blobs[0].text(), modal.content);
    assert.equal(downloaded, 'second-guid.nfo');
    assert.equal(notices[0][0], 'NFO text copied');
});
