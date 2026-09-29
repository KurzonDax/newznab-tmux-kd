import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test, { mock } from 'node:test';
import { tvImageDialog } from '../../resources/js/alpine/components/tv-dialogs-component.js';
import { tvReleases } from '../../resources/js/alpine/components/tv-releases-component.js';

// The Adult releases list (issue #887): the checks of docs/proposals/adult-redesign/prototype/check.mjs that live in the
// browser — the name search, the picture's click, the Clip chip's dialog and the table's cell colours.

function nameSearchScreen(href) {
    const requests = [], history = [];
    globalThis.window = {
        location: { href },
        history: { replaceState: (state, title, url) => { history.push(url); window.location.href = url; } },
        showToast() {},
    };
    globalThis.fetch = async url => {
        requests.push(String(url));
        return { ok: true, redirected: false, text: async () => '<nav>new list</nav>' };
    };
    const component = tvReleases();
    component.$el = { dataset: { preferenceUrl: '/profile/update-view', preferenceRoot: 'xxx', filtersClock: '5000' }, querySelectorAll: () => [], querySelector: () => null };
    const classes = new Set(['tv-name-search-clear', 'is-hidden']);
    const clear = {
        tabindex: '-1', 'aria-hidden': 'true',
        classList: { toggle: (name, on) => (on ? classes.add(name) : classes.delete(name)), contains: name => classes.has(name) },
        get hidden() { return classes.has('is-hidden'); },
        setAttribute(name, value) { this[name] = value; },
        removeAttribute(name) { delete this[name]; },
    };
    const field = { value: '', focused: false, focus() { this.focused = true; } };
    component.$refs = { list: { innerHTML: '' }, nameSearch: field, nameClear: clear };
    component.init();
    return { component, requests, history, field, clear };
}

test('the destroyed list cancels a pending name search', () => {
    mock.timers.enable({ apis: ['setTimeout'] });
    try {
        const { component, requests, field } = nameSearchScreen('https://nntmux.test/adult');
        field.value = 'Scene';
        component.searchNames({ target: field });
        component.destroy();
        mock.timers.tick(500);
        assert.deepEqual(requests, []);
    } finally {
        mock.timers.reset();
    }
});

test('the name search waits 180 ms, then writes q to the URL on page 1 and reloads the list with the filters kept', async () => {
    mock.timers.enable({ apis: ['setTimeout'] });
    try {
        const { component, requests, history, field, clear } = nameSearchScreen('https://nntmux.test/adult?resolution%5B%5D=1080p&page=3');
        field.value = 'Sce';
        component.searchNames({ target: field });
        field.value = 'Scene';
        component.searchNames({ target: field });
        assert.equal(clear.hidden, false, 'the clear button shows while the field holds text');
        assert.deepEqual([clear.tabindex, clear['aria-hidden']], [undefined, undefined]);
        mock.timers.tick(179);
        assert.deepEqual(requests, []);
        mock.timers.tick(1);
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(requests.length, 1, 'one reload for the last text typed');
        const url = new URL(history[0]);
        assert.deepEqual([url.searchParams.getAll('resolution[]'), url.searchParams.get('q'), url.searchParams.has('page')], [['1080p'], 'Scene', false]);
        const reload = new URL(requests[0]);
        assert.deepEqual([reload.searchParams.get('q'), reload.searchParams.get('_fragment')], ['Scene', 'list']);
        assert.ok(Number(reload.searchParams.get('_filters_at')) >= 5000);
        assert.equal(component.$refs.list.innerHTML, '<nav>new list</nav>');
        assert.equal(field.focused, false, 'the field is never replaced, so focus and caret stay where they are');
    } finally {
        mock.timers.reset();
    }
});

test('the clear button and Escape empty the name search, keep focus in the field and reload without it', async () => {
    const { component, requests, history, field, clear } = nameSearchScreen('https://nntmux.test/adult?category%5B%5D=6040&q=Scene');
    field.value = 'Scene';
    component.showNameClear(true);
    await component.clearNameSearch();
    assert.equal(field.value, '');
    assert.equal(field.focused, true);
    assert.equal(clear.hidden, true);
    assert.deepEqual([clear.tabindex, clear['aria-hidden']], ['-1', 'true']);
    const url = new URL(history[0]);
    assert.deepEqual([url.searchParams.getAll('category[]'), url.searchParams.has('q')], [['6040'], false]);
    assert.equal(new URL(requests[0]).searchParams.has('q'), false);

    // Escape in an empty field with no search in the URL reloads nothing
    await component.clearNameSearch();
    assert.equal(requests.length, 1);
});

function imageDialog() {
    const listeners = {};
    const element = tag => ({ tag, children: [], append(...nodes) { this.children.push(...nodes); } });
    globalThis.document = { addEventListener: (name, handler) => { listeners[name] = handler; }, removeEventListener() {}, createElement: element };
    const dialog = tvImageDialog();
    dialog.$refs = { player: { replaceChildren() {}, querySelector: () => null }, image: {} };
    dialog.$nextTick = callback => callback();
    dialog.init();
    return { dialog, click: listeners.click };
}

function row(kind) {
    const chip = {
        focused: false,
        focus() { this.focused = true; },
        classList: { contains: name => name === kind + '-badge' },
        dataset: { guid: 'abc', releaseDisplayName: 'Studio.Scene.1080p', imageUrl: '/covers/' + kind + '/abc_thumb.jpg', fullUrl: '/covers/' + kind + '/abc.jpg' },
    };
    const tr = { querySelector: selector => (selector === '.' + kind + '-badge' ? chip : null) };
    const picture = { dataset: { picture: kind }, closest: selector => (selector === '[data-release-row]' ? tr : null) };
    const target = { closest: selector => (selector === '[data-picture]' ? picture : null) };
    return { chip, target };
}

function click(target, keys = {}) {
    return { target, button: 0, ctrlKey: false, metaKey: false, shiftKey: false, ...keys, prevented: false, preventDefault() { this.prevented = true; } };
}

test('a plain click on a row picture opens its image dialog from the matching chip, which takes focus for the return', () => {
    for (const kind of ['preview', 'sample']) {
        const { dialog, click: handle } = imageDialog();
        const { chip, target } = row(kind);
        const event = click(target);
        handle(event);
        assert.equal(event.prevented, true, kind + ': the list stays');
        assert.equal(dialog.open, true);
        assert.equal(dialog.title, kind === 'sample' ? 'Sample image' : 'Preview image');
        assert.equal(dialog.imageUrl, '/covers/' + kind + '/abc.jpg');
        assert.equal(chip.focused, true, kind + ': focus returns to the chip on close');
    }
});

test('a Ctrl-, Cmd- or Shift-click on a row picture follows its link to the details page', () => {
    for (const keys of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }]) {
        const { dialog, click: handle } = imageDialog();
        const { chip, target } = row('preview');
        const event = click(target, keys);
        handle(event);
        assert.equal(event.prevented, false, JSON.stringify(keys));
        assert.equal(dialog.open, false);
        assert.equal(chip.focused, false);
    }
});

test('the Clip chip opens the image dialog in its video mode, titled Video clip', () => {
    const { dialog, click: handle } = imageDialog();
    const chip = {
        classList: { contains: () => false },
        dataset: { guid: 'abc', videoUrl: '/preview/video/abc', videoType: 'video/mp4', imageTitle: 'Video clip', releaseDisplayName: 'Studio.Scene.1080p' },
    };
    const target = { closest: selector => (selector === '.preview-badge, .sample-badge, .clip-badge' ? chip : null) };
    const event = click(target);
    handle(event);
    assert.equal(event.prevented, true);
    assert.deepEqual([dialog.open, dialog.video, dialog.title, dialog.releaseName], [true, true, 'Video clip', 'Studio.Scene.1080p']);
});

test('the Adult table keeps Size in ink and the date dim, and draws the picture frame and the dashed "No picture" tile', () => {
    const css = readFileSync(new URL('../../resources/css/tv.css', import.meta.url), 'utf8');
    const app = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    assert.match(css, /\.tv-feed\.is-adult tbody td:nth-child\(5\) \{ color: var\(--tv-ink\); \}/, 'Size is the fifth cell without Source');
    assert.match(css, /\.tv-feed\.is-adult tbody td:nth-child\(6\) \{ color: var\(--tv-dim\); \}/, 'the date is the sixth');
    assert.match(css, /\.tv-col-picture \{ width: 196px; \}/);
    assert.match(css, /\.tv-art\.is-picture a \{ width: 176px; height: 99px; \}/);
    assert.match(css, /\.tv-art a\.is-no-picture \{ gap: 6px; background: transparent; box-shadow: none; border-style: dashed; \}/);
    // the base styles force display: none !important on [hidden], so the empty field's clear button is hidden by a class and keeps its place
    assert.match(css, /\.tv-name-search-clear\.is-hidden \{ visibility: hidden; \}/, 'the hidden clear button keeps its place');
    assert.doesNotMatch(css, /\.tv-name-search-clear\[hidden\]/);
    assert.match(css, /\.tv-bar-list\.is-adult \.filter-bar\.is-release \{ flex: 0 0 calc\(\(\(100% - 12px\) \/ 2 - 8px\) \* 4 \/ 5 \+ 8px\); \}/, 'four cells as wide as a Movies release cell');
    assert.match(app, /--chip-clip-bg: oklch\(0\.93 0\.03 305\);/);
    assert.match(app, /--chip-clip-fg: oklch\(0\.40 0\.17 305\);/);
    assert.match(app, /--chip-clip-bg-dark: oklch\(0\.31 0\.085 305\);/);
    assert.match(app, /--chip-clip-fg-dark: oklch\(0\.87 0\.09 305\);/);
    assert.match(app, /\.dark \.chip-tone-clip \{ background-color: var\(--chip-clip-bg-dark\); color: var\(--chip-clip-fg-dark\); \}/);
});
