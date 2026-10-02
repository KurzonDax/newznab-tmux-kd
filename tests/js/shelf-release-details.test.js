import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

/** The Books, Console and PC release details page (docs/proposals/books-console-pc-redesign/SPEC.md 5A; prototype/shelf.html). */

test('the Similar table\'s Category column is 120 px, its cell dim, on one line, cut with an ellipsis', () => {
    const css = readFileSync(new URL('../../resources/css/tv.css', import.meta.url), 'utf8');
    assert.match(css, /\.tv-col-details-category \{ width: 120px; \}/);
    assert.match(css, /\.tv-release-table td\.tv-category \{ overflow: hidden; color: var\(--tv-dim\); text-overflow: ellipsis; white-space: nowrap; \}/);
    assert.match(css, /\.tv-col-category \{ width: 128px; \}/, 'the lists keep their 128 px Category column');
});
