import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { tvImageDialog, tvListenDialog } from '../../resources/js/alpine/components/tv-dialogs-component.js';

// The Audio list's Listen dialog (issue #962; docs/proposals/audio-redesign/SPEC.md 5.10 and the dialog checks of
// prototype/check.mjs on audio.html): opened by the Listen chip, the track title and artist, a player that plays at once,
// closed by Escape, the close button and a click outside, the sound stopped and focus returned to the chip.

function environment() {
    const listeners = { click: [], keydown: [] };
    const created = [];
    const document = {
        activeElement: null,
        body: { style: { overflow: 'auto' } },
        addEventListener: (name, handler) => listeners[name].push(handler),
        removeEventListener: (name, handler) => { listeners[name] = listeners[name].filter(fn => fn !== handler); },
        createElement(tag) {
            const element = {
                tag, children: [], attributes: {}, played: 0, paused: false, loaded: 0,
                append(...nodes) { this.children.push(...nodes); },
                replaceChildren(...nodes) { this.children = nodes; },
                setAttribute(name, value) { this.attributes[name] = value; },
                play() { this.played++; return Promise.resolve(); },
                pause() { this.paused = true; },
                load() { this.loaded++; },
            };
            created.push(element);
            return element;
        },
    };
    globalThis.document = document;
    const control = () => ({ isConnected: true, getClientRects: () => [1], focus() { document.activeElement = this; } });
    const closeButton = control();
    const modal = { ownerDocument: document, hasAttribute: () => false, contains: node => node === closeButton, querySelector: () => null, querySelectorAll: () => [closeButton] };
    const player = { children: [], replaceChildren(...nodes) { this.children = nodes; }, querySelector(selector) { return this.children.find(node => node.tag === selector) ?? null; } };
    const dialog = tvListenDialog();
    // Alpine's reactivity, enough for modalLifecycle: setting open runs the $watch callback
    let watcher = () => {};
    let open = dialog.open;
    Object.defineProperty(dialog, 'open', { get: () => open, set(value) { open = value; watcher(value); }, configurable: true });
    dialog.$el = { querySelector: selector => (selector === '[data-modal-dialog]' ? modal : null) };
    dialog.$watch = (name, fn) => { watcher = fn; };
    dialog.$nextTick = fn => fn();
    dialog.$refs = { player };
    dialog.init();
    return {
        dialog, player, created, document, closeButton,
        click: event => listeners.click.forEach(fn => fn(event)),
        key: event => listeners.keydown.forEach(fn => fn(event)),
    };
}

function listenChip(document, dataset = {}) {
    const chip = {
        focus() { document.activeElement = this; },
        isConnected: true,
        dataset: { guid: 'abc', releaseDisplayName: 'Artist-Album-WEB-2024-GRP', audioUrl: '/preview/audio/abc', audioType: 'audio/mpeg', audioTitle: 'Opening Song',
            audioArtist: 'The Artist', audioSeconds: '30', ...dataset },
    };
    return { chip, target: { closest: selector => (selector === '.listen-badge' ? chip : null) } };
}

function click(target) {
    return { target, prevented: false, preventDefault() { this.prevented = true; } };
}

test('the Listen chip opens the dialog with the release name, the track title and artist, and a player that plays at once', () => {
    const env = environment();
    const { chip, target } = listenChip(env.document);
    chip.focus();
    const event = click(target);
    env.click(event);
    assert.equal(event.prevented, true);
    assert.deepEqual([env.dialog.open, env.dialog.releaseName, env.dialog.trackTitle, env.dialog.artist], [true, 'Artist-Album-WEB-2024-GRP', 'Opening Song', 'The Artist']);
    assert.deepEqual([env.dialog.showTrack(), env.dialog.showArtist()], [true, true]);
    const audio = env.player.children[0];
    assert.equal(audio.tag, 'audio');
    assert.deepEqual([audio.controls, audio.preload, audio.tabIndex], [true, 'auto', 0]);
    assert.equal(audio.attributes['aria-label'], '30-second preview of Artist-Album-WEB-2024-GRP');
    assert.deepEqual([audio.children[0].tag, audio.children[0].src, audio.children[0].type], ['source', '/preview/audio/abc', 'audio/mpeg']);
    assert.equal(audio.played, 1, 'it plays at once');
    assert.equal(env.document.activeElement, env.closeButton, 'focus moves into the dialog');
    assert.equal(env.document.body.style.overflow, 'hidden');
});

test('with no track title neither the title nor the artist shows; the artist shows only under a title', () => {
    const env = environment();
    env.click(click(listenChip(env.document, { audioTitle: undefined, audioSeconds: undefined }).target));
    assert.deepEqual([env.dialog.trackTitle, env.dialog.artist, env.dialog.showTrack(), env.dialog.showArtist()], ['', 'The Artist', false, false]);
    assert.equal(env.player.children[0].attributes['aria-label'], 'Preview of Artist-Album-WEB-2024-GRP');
    env.dialog.close();
    env.click(click(listenChip(env.document, { audioArtist: undefined }).target));
    assert.deepEqual([env.dialog.showTrack(), env.dialog.showArtist()], [true, false]);
});

test('the dialog shows the release\'s cover beside the track when the chip carries one, and no image otherwise', () => {
    const env = environment();
    env.click(click(listenChip(env.document, { audioCover: '/covers/audio/11111111-1111-4111-8111-111111111111.jpg' }).target));
    assert.deepEqual([env.dialog.cover, env.dialog.hasCover(), env.dialog.layoutClass()], ['/covers/audio/11111111-1111-4111-8111-111111111111.jpg', true, 'has-cover']);
    env.dialog.close();
    env.click(click(listenChip(env.document).target));
    assert.deepEqual([env.dialog.cover, env.dialog.hasCover(), env.dialog.layoutClass()], ['', false, ''], 'a release without a cover shows no image');

    const markup = readFileSync(new URL('../../resources/views/tv/partials/dialogs.blade.php', import.meta.url), 'utf8');
    const listen = markup.slice(markup.indexOf('x-data="tvListenDialog"'));
    assert.match(listen, /<div class="tv-listen" x-bind:class="layoutClass\(\)">\s*<template x-if="hasCover\(\)"><img class="tv-listen-cover" x-bind:src="cover" alt=""><\/template>/);
    const css = readFileSync(new URL('../../resources/css/tv.css', import.meta.url), 'utf8');
    assert.match(css, /\.tv-listen\.has-cover \{[^}]*grid-template-columns: 120px 1fr;/, 'the 120 px cover column (SPEC 5.10)');
    assert.match(css, /\.tv-listen-cover \{[^}]*width: 120px; height: 120px;/);
});

test('Escape closes the dialog, pauses, empties and removes the player, and returns focus to the chip', () => {
    const env = environment();
    const { chip, target } = listenChip(env.document);
    chip.focus();
    env.click(click(target));
    const audio = env.player.children[0];
    let prevented = false;
    env.key({ key: 'Escape', preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(env.dialog.open, false);
    assert.deepEqual([audio.paused, audio.children, audio.loaded], [true, [], 1], 'the sound stops and the source is dropped');
    assert.deepEqual(env.player.children, [], 'the player is removed');
    assert.equal(env.document.activeElement, chip, 'focus returns to the chip');
    assert.equal(env.document.body.style.overflow, 'auto');
});

test('the close button and a click outside close the dialog the same way', () => {
    const frame = readFileSync(new URL('../../resources/views/components/tv-dialog.blade.php', import.meta.url), 'utf8');
    assert.match(frame, /class="tv-scrim" x-on:click\.self="\{\{ \$close \}\}"/, 'a click outside the panel');
    assert.match(frame, /class="tv-action tv-dialog-close" x-on:click="\{\{ \$close \}\}"/, 'the close button');
    const env = environment();
    const { chip, target } = listenChip(env.document);
    chip.focus();
    env.click(click(target));
    const audio = env.player.children[0];
    env.dialog.close();
    assert.deepEqual([env.dialog.open, audio.paused, env.player.children.length, env.document.activeElement === chip], [false, true, 0, true]);
});

test('a second chip replaces the first player, and teardown stops the sound', () => {
    const env = environment();
    env.click(click(listenChip(env.document).target));
    const first = env.player.children[0];
    env.click(click(listenChip(env.document, { audioUrl: '/preview/audio/def', releaseDisplayName: 'Other.Release' }).target));
    assert.equal(first.paused, true);
    assert.equal(env.player.children.length, 1);
    assert.equal(env.player.children[0].children[0].src, '/preview/audio/def');
    const second = env.player.children[0];
    env.dialog.destroy();
    assert.equal(second.paused, true);
});

test('the Listen dialog never opens on a Preview or Sample chip, and the image dialog never on Listen', () => {
    const env = environment();
    for (const name of ['preview-badge', 'sample-badge']) {
        const chip = { dataset: {} };
        const event = click({ closest: selector => (selector.split(', ').includes('.' + name) ? chip : null) });
        env.click(event);
        assert.equal(event.prevented, false, name);
        assert.equal(env.dialog.open, false, name);
    }
    const listeners = {};
    globalThis.document = { addEventListener: (name, handler) => { listeners[name] = handler; }, removeEventListener() {}, createElement: () => ({}) };
    const image = tvImageDialog();
    image.$refs = { player: { replaceChildren() {}, querySelector: () => null }, image: {} };
    image.$nextTick = callback => callback();
    image.init();
    const event = click({ closest: selector => (selector === '.listen-badge' ? { dataset: {} } : null) });
    listeners.click(event);
    assert.equal(event.prevented, false);
    assert.equal(image.open, false);
});

test('the dialog is registered for the lazy loader beside the image dialog and its markup has no footer', () => {
    const loader = readFileSync(new URL('../../resources/js/alpine/lazy-loader.js', import.meta.url), 'utf8');
    const registry = readFileSync(new URL('../../resources/js/alpine/components/tv-dialogs.js', import.meta.url), 'utf8');
    const markup = readFileSync(new URL('../../resources/views/tv/partials/dialogs.blade.php', import.meta.url), 'utf8');
    assert.match(loader, /'tvImageDialog':\s+\(\) => import\('\.\/components\/tv-dialogs\.js'\),.*\n\s+'tvListenDialog':\s+\(\) => import\('\.\/components\/tv-dialogs\.js'\),/);
    assert.match(registry, /Alpine\.data\('tvListenDialog', tvListenDialog\);/);
    const listen = markup.slice(markup.indexOf('x-data="tvListenDialog"'));
    assert.match(listen, /<x-tv-dialog name="listen" class="is-listen">\s*<x-slot:title>Listen<\/x-slot:title>\s*<x-slot:subtitle><span x-text="releaseName"><\/span><\/x-slot:subtitle>/);
    assert.doesNotMatch(listen, /x-slot:footer/);
});
