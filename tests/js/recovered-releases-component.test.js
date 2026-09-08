import assert from 'node:assert/strict';
import test from 'node:test';
import { recoveredReleases } from '../../resources/js/alpine/components/admin/recovered-releases-component.js';

test('opening another recovery disclosure closes the first and resets the filename preview', () => {
    const page = recoveredReleases();
    assert.equal(page.isExpanded(1), false);
    page.toggleDetails(1);
    assert.equal(page.isExpanded(1), true);
    page.toggleFiles();
    assert.equal(page.allFiles, true);
    page.toggleDetails(2);
    assert.equal(page.isExpanded(1), false);
    assert.equal(page.isExpanded(2), true);
    assert.equal(page.allFiles, false);
    page.toggleDetails(2);
    assert.equal(page.isExpanded(2), false);
});

test('show fewer restores the short filename preview', () => {
    const page = recoveredReleases();
    page.toggleDetails(3);
    page.toggleFiles();
    page.toggleFiles();
    assert.equal(page.allFiles, false);
    assert.equal(page.isExpanded(3), true);
});
