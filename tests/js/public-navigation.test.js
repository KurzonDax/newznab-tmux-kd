import assert from 'node:assert/strict';
import test from 'node:test';
import { publicNavigation } from '../../resources/js/alpine/components/public-navigation-component.js';

function navigation() {
    globalThis.window = { location: { href: 'https://nntmux.test/browse/movies' } };
    globalThis.document = { querySelectorAll: () => [] };
    const component = publicNavigation();
    component.$refs = {
        searchInput: { value: 'dune', focus() {} },
        searchScope: { value: '2000' },
        searchForm: { action: 'https://nntmux.test/search', dataset: { suggestUrl: 'https://nntmux.test/api/search/suggest' } },
    };
    return component;
}

test('suggestions keep the current scope and discard stale replies after the input changes', async () => {
    const component = navigation();
    const pending = [];
    globalThis.fetch = (url, options) => new Promise(resolve => pending.push({ url, options, resolve }));
    const first = component.fetchSuggestions();
    component.$refs.searchInput.value = 'arrival';
    const second = component.fetchSuggestions();
    assert.equal(pending[0].options.signal.aborted, true);
    assert.equal(new URL(pending[1].url).pathname, '/api/search/suggest');
    pending[1].resolve({ ok: true, json: async () => ({ success: true, suggestions: ['Arrival 2016'] }) });
    await second;
    pending[0].resolve({ ok: true, json: async () => ({ success: true, suggestions: ['Dune'] }) });
    await first;
    assert.deepEqual(component.suggestions.map(item => item.label), ['Arrival 2016']);
    const link = new URL(component.suggestions[0].url);
    assert.equal(link.pathname, '/search');
    assert.equal(link.searchParams.get('q'), 'Arrival 2016');
    assert.equal(link.searchParams.get('t'), '2000');
    component.$refs.searchInput.value = '';
    await component.fetchSuggestions();
    assert.deepEqual(component.suggestions, []);
});

test('an answer for text that has already changed is ignored during the debounce interval', async () => {
    const component = navigation();
    let finish;
    globalThis.fetch = () => new Promise(resolve => { finish = resolve; });
    const request = component.fetchSuggestions();
    component.$refs.searchInput.value = 'arrival';
    finish({ ok: true, json: async () => ({ success: true, suggestions: ['Dune'] }) });
    await request;
    assert.deepEqual(component.suggestions, []);
});
