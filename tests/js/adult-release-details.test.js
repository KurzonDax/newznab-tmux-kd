import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { tvImageDialog } from '../../resources/js/alpine/components/tv-dialogs-component.js';

// The Adult release details page (issue #888): the Overview's pictures (docs/proposals/adult-redesign/SPEC.md 5A.3).
// A preview whose release has a clip plays it in the Video preview dialog; the sample opens straight at full size when
// its image is larger than the dialog, and shows only once laid out that way; the header chips keep the fitted dialog.

function imageDialog(image = {}) {
    const listeners = {};
    globalThis.window = { requestAnimationFrame: callback => callback() };
    globalThis.document = {
        addEventListener: (name, handler) => { listeners[name] = handler; },
        removeEventListener() {},
        createElement: tag => ({ tag, children: [], append(...nodes) { this.children.push(...nodes); } }),
    };
    const dialog = tvImageDialog();
    dialog.$refs = { player: { replaceChildren() {}, querySelector: () => null }, image };
    dialog.$nextTick = callback => callback();
    dialog.init();
    return { dialog, click: listeners.click };
}

function picture(kind, dataset = {}) {
    return {
        classList: { contains: name => name === kind + '-badge' },
        dataset: { guid: 'abc', releaseDisplayName: 'Studio.Scene.1080p', imageUrl: '/covers/' + kind + '/abc_thumb.jpg', fullUrl: '/covers/' + kind + '/abc.jpg', ...dataset },
    };
}

function click(trigger) {
    const target = { closest: selector => (selector === '.preview-badge, .sample-badge' ? trigger : null) };
    return { target, button: 0, prevented: false, preventDefault() { this.prevented = true; } };
}

/** The image loads: its natural size, and the size the fitted dialog lays it out at. */
function load(dialog, natural, shown) {
    Object.assign(dialog.$refs.image, { complete: true, naturalWidth: natural[0], naturalHeight: natural[1], clientWidth: shown[0], clientHeight: shown[1] });
    dialog.measure();
}

test('the sample opens in its Full size state when the image is larger than the dialog, and is hidden until it is laid out so', () => {
    const { dialog, click: handle } = imageDialog();
    const event = click(picture('sample', { openFull: '', imageTitle: 'Sample image' }));
    handle(event);

    assert.equal(event.prevented, true);
    assert.equal(dialog.open, true, 'the dialog opens at once');
    assert.equal(dialog.imageUrl, '/covers/sample/abc.jpg', 'the full-size copy');
    assert.match(dialog.dialogClass(), /\bis-measuring\b/, 'the image is hidden until it is measured');
    assert.doesNotMatch(dialog.dialogClass(), /\bis-full\b/);

    load(dialog, [1920, 1080], [900, 506]);
    assert.deepEqual([dialog.canFull, dialog.full, dialog.fullLabel(), dialog.fullPressed()], [true, true, 'Fit to window', 'true']);
    assert.equal(dialog.dialogClass(), 'tv-dialog tv-image-dialog can-full is-full');
    assert.equal(dialog.dimensions, '1920 × 1080');

    dialog.toggleFull();
    assert.equal(dialog.full, false, 'Fit to window still fits it');
});

test('a sample no larger than the dialog opens fitted, with no Full size button', () => {
    const { dialog, click: handle } = imageDialog();
    handle(click(picture('sample', { openFull: '' })));
    load(dialog, [650, 366], [650, 366]);

    assert.deepEqual([dialog.canFull, dialog.full], [false, false]);
    assert.equal(dialog.dialogClass(), 'tv-dialog tv-image-dialog');
});

test('a sample already in the browser cache is measured when the dialog opens', () => {
    const { dialog, click: handle } = imageDialog({ complete: true, naturalWidth: 1920, naturalHeight: 1080, clientWidth: 900, clientHeight: 506 });
    handle(click(picture('sample', { openFull: '' })));

    assert.equal(dialog.dialogClass(), 'tv-dialog tv-image-dialog can-full is-full');
});

test('the header Sample chip keeps the fitted dialog for the same large image', () => {
    const { dialog, click: handle } = imageDialog();
    handle(click(picture('sample', { imageTitle: 'Sample image' })));

    assert.doesNotMatch(dialog.dialogClass(), /\bis-measuring\b/);
    load(dialog, [1920, 1080], [900, 506]);
    assert.deepEqual([dialog.canFull, dialog.full], [true, false]);
    assert.equal(dialog.dialogClass(), 'tv-dialog tv-image-dialog can-full');
});

test('a sample whose image fails to load shows the dialog at once in its failed state', () => {
    const { dialog, click: handle } = imageDialog();
    handle(click(picture('sample', { openFull: '' })));
    dialog.imageFailed();

    assert.deepEqual([dialog.open, dialog.failed, dialog.showImage()], [true, true, false]);
    assert.equal(dialog.dialogClass(), 'tv-dialog tv-image-dialog');
});

test('an image that loads without a size still shows, fitted', () => {
    const { dialog, click: handle } = imageDialog();
    handle(click(picture('sample', { openFull: '' })));
    load(dialog, [0, 0], [0, 0]);

    assert.deepEqual([dialog.canFull, dialog.full], [false, false]);
    assert.equal(dialog.dialogClass(), 'tv-dialog tv-image-dialog');
});

test('closing and opening another picture forgets the full-size opening', () => {
    const { dialog, click: handle } = imageDialog();
    handle(click(picture('sample', { openFull: '' })));
    load(dialog, [1920, 1080], [900, 506]);
    dialog.close();
    assert.equal(dialog.full, false);

    handle(click(picture('preview', { imageTitle: 'Image preview' })));
    load(dialog, [1920, 1080], [900, 506]);
    assert.deepEqual([dialog.canFull, dialog.full, dialog.title], [true, false, 'Image preview']);
});

test('a preview with a clip opens the Video preview dialog with the player, as the Preview chip does; one without opens its image', () => {
    const { dialog, click: handle } = imageDialog();
    const clip = {
        classList: { contains: name => name === 'preview-badge' },
        dataset: { guid: 'abc', releaseDisplayName: 'Studio.Scene.1080p', videoUrl: '/preview/video/abc', videoType: 'video/mp4', posterUrl: '/covers/preview/abc_thumb.jpg', imageTitle: 'Video preview' },
    };
    handle(click(clip));
    assert.deepEqual([dialog.open, dialog.video, dialog.title, dialog.imageUrl, dialog.showImage()], [true, true, 'Video preview', '', false]);
    assert.doesNotMatch(dialog.dialogClass(), /\bis-measuring\b/);
    dialog.close();

    handle(click(picture('preview', { imageTitle: 'Image preview' })));
    assert.deepEqual([dialog.open, dialog.video, dialog.title, dialog.imageUrl], [true, false, 'Image preview', '/covers/preview/abc.jpg']);
});

test('the pictures sit side by side at 220 px; the play button is 56 px round and turns magenta; the clip tag keeps the dark chip values', () => {
    const css = readFileSync(new URL('../../resources/css/tv.css', import.meta.url), 'utf8');
    assert.match(css, /\.tv-details-pictures \{ display: flex; flex-wrap: wrap; gap: 14px; margin-bottom: 22px; \}/);
    assert.match(css, /\.tv-details-pictures \.tv-details-preview img \{ max-height: 220px; \}/);
    assert.match(css, /\.tv-details-play \{[^}]*width: 56px; height: 56px;[^}]*border-radius: 50%;/);
    assert.match(css, /\.tv-details-preview\.has-clip:hover \.tv-details-play, \.tv-details-preview\.has-clip:focus-visible \.tv-details-play \{ background: var\(--chip-clip-play-hover\);/);
    const app = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    assert.match(app, /--chip-clip-play-hover: oklch\(0\.55 0\.17 305\);/);
    assert.match(app, /--picture-label-bg: rgb\(10 10 14 \/ 80%\);/);
    assert.match(app, /--picture-play-bg: rgb\(10 10 14 \/ 72%\);/);
    assert.match(css, /\.tv-details-picture-label\.is-clip \{ right: 8px; left: auto; background: var\(--chip-clip-bg-dark\); color: var\(--chip-clip-fg-dark\); \}/);
    assert.match(css, /\.tv-image-dialog\.is-measuring \.tv-image-bar, \.tv-image-dialog\.is-measuring \.tv-image-frame \{ visibility: hidden; \}/);
});
