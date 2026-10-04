import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// The Audio release pages (issue #963; docs/proposals/audio-redesign/SPEC.md 5B, 5C.1 and 5C.2): the album page's
// square cover slot, the preview the Overview opens with (the player above the spectrogram, as wide as it) and the
// Tracks tab's table.

const css = readFileSync(new URL('../../resources/css/tv.css', import.meta.url), 'utf8');

test('the album page\'s cover slot is square', () => {
    assert.match(css, /\.tv-show-art\.is-square \{ aspect-ratio: 1 \/ 1; \}/);
});

test('the preview block is a column as wide as the spectrogram: 10 px between the player and the 220 px picture, the facts 24 px below', () => {
    assert.match(css, /\.tv-audio-preview \{ display: inline-flex; flex-direction: column; gap: 10px; margin-bottom: 24px; min-width: min\(560px, 100%\); \}/);
    assert.match(css, /\.tv-audio-preview audio \{ display: block; width: 100%; height: 44px; \}/);
    assert.match(css, /\.tv-audio-preview \.tv-details-preview \{ position: relative; margin: 0; background: #000; \}/);
    assert.match(css, /\.tv-audio-preview \.tv-details-preview img \{ display: block; height: 220px; width: auto; max-width: none; max-height: none; \}/);
    assert.match(css, /\.tv-details-picture-label\.is-top \{ top: 8px; bottom: auto; \}/);
    assert.match(css, /\.tv-audio-preview \+ \.tv-details-facts \{ margin-top: 0; \}/);
});

test('the line naming what plays is dim at 14 px with the track title in ink at weight 600 and a dot before the length', () => {
    assert.match(css, /\.tv-audio-preview-line \{ display: flex; align-items: baseline; gap: 8px; color: var\(--tv-dim\); font-size: 14px; \}/);
    assert.match(css, /\.tv-audio-preview-line b \{ color: var\(--tv-ink\); font-weight: 600; \}/);
    assert.match(css, /\.tv-audio-preview-line b \+ span::before \{ content: "·"; margin-right: 8px; \}/);
});

test('the Tracks table is 760 px at most with a 56 px number column and a 90 px length column, both dim and right-aligned', () => {
    assert.match(css, /\.tv-tracks \{ table-layout: fixed; max-width: 760px; \}/);
    assert.match(css, /\.tv-tracks td \{ padding: 9px 10px; \}/);
    assert.match(css, /\.tv-tracks \.tv-track-number \{ width: 56px; padding-right: 18px; color: var\(--tv-dim\); text-align: right; font-variant-numeric: tabular-nums; \}/);
    assert.match(css, /\.tv-tracks \.tv-track-length \{ width: 90px; color: var\(--tv-dim\); text-align: right; \}/);
    assert.match(css, /\.tv-tracks \.tv-track-disc td \{ padding: 18px 10px 6px; border-bottom: 1px solid var\(--tv-line\); color: var\(--tv-dim\); font-size: 13px; font-weight: 700; \}/);
    assert.match(css, /\.tv-tracks-total \{ max-width: 760px; margin: 4px 0 10px; color: var\(--tv-dim\); font-size: 13\.5px; font-variant-numeric: tabular-nums; \}/);
});

test('the Audio rules add no literal colour but the spectrogram\'s black picture ground', () => {
    const rules = css.split('\n').filter(line => /^\.(tv-audio-preview|tv-tracks|tv-show-art\.is-square|tv-details-picture-label\.is-top)/.test(line));
    assert.ok(rules.length >= 12);
    const literals = rules.flatMap(line => line.match(/#[0-9a-fA-F]{3,8}\b|rgba?\(/g) ?? []);
    assert.deepEqual(literals, ['#000']);
});
