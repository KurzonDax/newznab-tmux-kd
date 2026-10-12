import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { modalLifecycle } from '../../resources/js/alpine/components/modal-lifecycle.js';

const read = path => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

/** Component name → the module path the lazy loader imports for it. */
function lazyComponentMap() {
    const map = read('resources/js/alpine/lazy-loader.js').match(/const lazyComponentMap = \{\n([\s\S]*?)\n\};/)[1];
    return Object.fromEntries([...map.matchAll(/^\s*'([^']+)'\s*:\s*\(\)\s*=>\s*import\('([^']+)'\)/gm)].map(entry => [entry[1], entry[2]]));
}

test('every fixed control has a lazily loaded component, and the new ones are not in the admin features file', () => {
    const map = lazyComponentMap();

    assert.equal(map.passwordToggle, './components/password-toggle.js');
    assert.equal(map.copyToClipboard, './components/profile-edit.js');
    assert.equal(map.adminGroups, './components/admin/groups.js');
    assert.equal(map.adminRowDelete, './components/admin/row-delete.js');
    assert.equal(map.imageFallback, './components/admin/image-fallback.js');
    assert.equal(map.adminInvitations, './components/admin/invitations.js');

    // the admin features file is still fetched for the two roots it was fetched for, and no other
    assert.deepEqual(
        Object.keys(map).filter(name => map[name] === './components/admin/features.js').sort(),
        ['adminDeletedUsers', 'adminUserEdit'],
    );
});

test('each registration file registers the component its loader key names', () => {
    for (const [name, path] of Object.entries({
        passwordToggle: 'password-toggle.js',
        copyToClipboard: 'profile-edit.js',
        adminRowDelete: 'admin/row-delete.js',
        imageFallback: 'admin/image-fallback.js',
        adminInvitations: 'admin/invitations.js',
    })) {
        assert.match(read('resources/js/alpine/components/' + path), new RegExp(`Alpine\\.data\\('${name}', ${name}\\);`), path);
    }
});

test('no module-level document handler is left for the fixed controls', () => {
    const gone = [
        'password-toggle-btn', 'togglePasswordVisibility', 'copy-btn', 'copyApiToken', 'data-action', 'updateSelectionUI',
        'promotion-toggle-btn', 'promotion-delete-btn', 'data-delete-', 'ajax_binaryblacklist_delete', 'data-fallback-src',
        'select_all', 'invitation-checkbox',
    ];
    for (const path of ['password-toggle.js', 'password-toggle-component.js', 'profile-edit.js', 'profile-edit-component.js', 'admin/features.js']) {
        const source = read('resources/js/alpine/components/' + path);
        for (const token of gone) {
            assert.equal(source.includes(token), false, `${path} still names ${token}`);
        }
    }
    for (const path of ['password-toggle.js', 'profile-edit.js']) {
        assert.doesNotMatch(read('resources/js/alpine/components/' + path), /document\.|window\./, `${path} only registers its component`);
    }

    assert.doesNotMatch(read('resources/js/alpine/components/admin/groups-component.js'), /window\.[\w$]+\s*=[^=]/);
});

test('the admin features file keeps the deleted-users handlers it had', () => {
    const features = read('resources/js/alpine/components/admin/features.js');
    for (const kept of [
        "Alpine.data('adminUserEdit'", "Alpine.data('adminDeletedUsers'", '.restore-user-btn', '.delete-user-btn',
        "getElementById('selectAll')", "getElementById('bulkActionForm')",
    ]) {
        assert.ok(features.includes(kept), kept);
    }
});

test('release comments draw their top line and mute their secondary text with the details page tokens', () => {
    const css = read('resources/css/csp-safe.css');

    assert.match(css, /\.details-comment \{[^}]*border-top: 1px solid var\(--tv-line\);[^}]*\}/);
    assert.match(css, /\.tv-details-comments \.text-muted \{[^}]*color: var\(--tv-dim\);[^}]*\}/);

    const tv = read('resources/css/tv.css');
    assert.match(tv, /--tv-line: var\(--border-default\);/, 'the line token is the removed variable\'s light value');
    assert.match(tv, /--tv-dim: var\(--text-muted\);/, 'the muted token is the removed variable\'s light value');
    assert.match(tv, /--tv-line: var\(--border-default-dark\);/);
    assert.match(tv, /--tv-dim: var\(--text-muted-dark\);/);

    const directory = new URL('../../resources/css/', import.meta.url);
    for (const file of readdirSync(directory, { recursive: true }).filter(name => String(name).endsWith('.css'))) {
        const source = readFileSync(new URL(String(file), directory), 'utf8');
        assert.equal(source.includes('--details-border'), false, `${file} names --details-border`);
        assert.equal(source.includes('--details-muted'), false, `${file} names --details-muted`);
    }
});

/**
 * The admin confirmation dialog as its Blade view writes it: the `confirmModal` root holds a
 * `[data-modal-dialog]` element only if the view's markup does, which is what the shared modal
 * code looks for before it binds Escape.
 */
function adminConfirmation() {
    const view = read('resources/views/admin/confirmation-modal.blade.php');
    const root = view.slice(view.indexOf('x-data="confirmModal"'));
    const inner = root.slice(root.indexOf('>') + 1);
    const holdsDialog = /<[a-z][^>]*\sdata-modal-dialog[\s>]/.test(inner);

    const listeners = { keydown: [], click: [] };
    const document = {
        body: { style: { overflow: 'auto' } },
        activeElement: null,
        addEventListener(name, listener) { listeners[name]?.push(listener); },
        removeEventListener(name, listener) { listeners[name] = (listeners[name] ?? []).filter(fn => fn !== listener); },
    };
    const control = () => ({ isConnected: true, getClientRects: () => [1], focus() { document.activeElement = this; } });
    const closeButton = control(), cancelButton = control(), confirmButton = control();
    const dialog = {
        ownerDocument: document,
        contains: node => [closeButton, cancelButton, confirmButton].includes(node),
        querySelectorAll: () => [closeButton, cancelButton, confirmButton],
    };

    let factory;
    const window = {};
    const source = read('resources/js/alpine/components/confirm-modal.js').replace(/^import .*?;\n/gm, '');
    vm.runInNewContext(source, {
        Alpine: { data(name, value) { if (name === 'confirmModal') factory = value; } },
        modalLifecycle,
        adminConfirmTheme: { icon: '', button: '' },
        submitConfirmedElement() {},
        window,
        document,
    });

    const modal = factory();
    let watcher = () => {};
    let open = modal.open;
    Object.defineProperty(modal, 'open', { get: () => open, set(value) { open = value; watcher(value); }, configurable: true });
    modal.$el = { matches: selector => selector === '[data-admin-confirm]', querySelector: selector => (selector === '[data-modal-dialog]' && holdsDialog ? dialog : null) };
    modal.$watch = (name, fn) => { watcher = fn; };
    modal.$nextTick = fn => fn();
    modal.init();

    return { modal, document, control, closeButton, showConfirm: window.showConfirm, key: event => listeners.keydown.forEach(fn => fn(event)) };
}

test('Escape cancels the open admin confirmation dialog, runs no confirmed action and returns focus to the opener', async () => {
    const { modal, document, control, closeButton, showConfirm, key } = adminConfirmation();
    const opener = control();
    opener.focus();
    let confirmed = 0;

    const answer = showConfirm({ title: 'Delete Promotion', type: 'danger', onConfirm() { confirmed += 1; } });
    assert.equal(modal.open, true);
    assert.equal(document.activeElement, closeButton, 'focus moves into the dialog');

    let prevented = false;
    key({ key: 'Escape', preventDefault() { prevented = true; } });

    assert.equal(modal.open, false);
    assert.equal(prevented, true);
    assert.equal(confirmed, 0);
    assert.equal(await answer, false, 'Escape answers as the Cancel button does');
    assert.equal(document.activeElement, opener);

    // the Cancel button itself, for comparison
    const second = showConfirm({ onConfirm() { confirmed += 1; } });
    modal.cancel();
    assert.equal(await second, false);
    assert.equal(confirmed, 0);
    assert.equal(document.activeElement, opener);
    modal.destroy();
});

test('Escape does nothing while the admin confirmation dialog is closed', () => {
    const { modal, key } = adminConfirmation();
    let prevented = false;

    key({ key: 'Escape', preventDefault() { prevented = true; } });

    assert.equal(modal.open, false);
    assert.equal(prevented, false);
    modal.destroy();
});
