import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// The Console release page of a release with a game (issue #935; docs/proposals/books-console-pc-redesign/SPEC.md 5B):
// a release page laid out like the film page, with the release name as a 24 px heading, the game line under it, the
// Storyline paragraph's run-in label and the plain info-line values.

const css = readFileSync(new URL('../../resources/css/tv.css', import.meta.url), 'utf8');

test('the release name is the heading at the release-details size, wrapping anywhere', () => {
    assert.match(css, /\.tv-show-head h1\.is-release-name \{ font-size: 24px; line-height: 1\.2; letter-spacing: -0\.01em; overflow-wrap: anywhere; \}/);
});

test('the header\'s game line is 15.5 px with the game\'s name in ink at weight 600', () => {
    assert.match(css, /\.tv-show-head \.tv-game-line \{ margin-top: 8px; font-size: 15\.5px; \}/);
    assert.match(css, /\.tv-show-head \.tv-game-line b \{ color: var\(--tv-ink\); font-weight: 600; \}/);
});

test('the Storyline label is dim at normal weight and an info-line value is plain ink text', () => {
    assert.match(css, /\.tv-show-head p\.tv-storyline b \{ color: var\(--tv-dim\); font-weight: 400; \}/);
    assert.match(css, /\.tv-starring \.tv-starring-value \{ color: var\(--tv-ink\); \}/);
});
