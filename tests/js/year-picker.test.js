import assert from 'node:assert/strict';
import test from 'node:test';
import { yearPickerUrl } from '../../resources/js/alpine/components/year-picker-controls.js';
import { releaseBrowser } from '../../resources/js/alpine/components/release-browser-component.js';

const location = 'https://nntmux.test/browse/tv?year=custom&year_from=1960&year_to=1990&page=3&network=Harbor&group=test&poster=A%2BB&watching=1&view=covers&size=l&per=24&sort=posted';

test('year changes apply both custom bounds together and preserve other listing restrictions', () => {
    for (const [from, to] of [['1970', '1975'], ['1975', ''], ['', '1975'], ['1980', '1970'], ['', '']]) {
        const url = yearPickerUrl(location, 'custom', from, to);
        assert.equal(url.searchParams.get('year'), 'custom');
        assert.equal(url.searchParams.get('year_from'), from || null);
        assert.equal(url.searchParams.get('year_to'), to || null);
        assert.equal(url.searchParams.has('page'), false);
        for (const key of ['network', 'group', 'poster', 'watching', 'view', 'size', 'per', 'sort']) {
            assert.equal(url.searchParams.get(key), new URL(location).searchParams.get(key));
        }
    }
});

test('clearing, choosing a single year, and choosing a decade remove stale custom bounds', () => {
    for (const selection of ['', '1975', '1970s']) {
        const url = yearPickerUrl(location, selection, '1960', '1990');
        assert.equal(url.searchParams.get('year'), selection || null);
        assert.equal(url.searchParams.has('year_from'), false);
        assert.equal(url.searchParams.has('year_to'), false);
        assert.equal(url.searchParams.has('page'), false);
        assert.equal(url.searchParams.get('poster'), 'A+B');
    }
});

test('sort, unrelated filters, view, cover size and pagination preserve the complete year range', () => {
    const navigations = [];
    globalThis.window = { location: { href: location, assign: value => navigations.push(new URL(value)) } };
    const component = releaseBrowser();
    for (const [key, value] of [['sort', 'title'], ['network', 'Other'], ['view', 'table'], ['size', 'xl'], ['per', '48']]) {
        component.navigateFilter(key, value);
    }
    component.browserRoot = { dataset: { lastPage: '4' } };
    component.goToPage({ target: { value: '2' } });
    for (const url of navigations) {
        assert.equal(url.searchParams.get('year'), 'custom');
        assert.equal(url.searchParams.get('year_from'), '1960');
        assert.equal(url.searchParams.get('year_to'), '1990');
    }
});
