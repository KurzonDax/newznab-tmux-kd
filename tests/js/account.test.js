import assert from 'node:assert/strict';
import test from 'node:test';
import { accountPage, accountRss } from '../../resources/js/alpine/components/account-component.js';

test('RSS controls keep their container data when Alpine changes the event element', () => {
    const component = accountRss();
    component.$el = { dataset: { rssBase: 'https://indexer.test/rss', apiToken: 'token+test' }, querySelector: () => ({ value: '2030' }) };
    component.init();
    assert.equal(new URL(component.url).pathname, '/rss/full-feed');
    component.$el = { dataset: {} };
    component.feed = 'category'; component.count = '100'; component.downloads = true; component.build();
    const url = new URL(component.url);
    assert.equal(url.pathname, '/rss/category');
    assert.equal(url.searchParams.get('id'), '2030');
    assert.equal(url.searchParams.get('api_token'), 'token+test');
    assert.equal(url.searchParams.get('limit'), '100');
    assert.equal(url.searchParams.has('num'), false);
    assert.equal(url.searchParams.get('dl'), '1');
    component.feed = 'myshows'; component.downloads = false; component.build();
    assert.equal(new URL(component.url).pathname, '/rss/myshows');
    assert.equal(new URL(component.url).searchParams.has('id'), false);
    assert.equal(new URL(component.url).searchParams.has('dl'), false);
    assert.equal(new URL(component.url).searchParams.get('num'), '100');
    assert.equal(new URL(component.url).searchParams.has('limit'), false);
});

test('appearance controls apply theme and scheme through the existing store', () => {
    const applied = [];
    const component = accountPage();
    component.$store = { theme: { set: value => applied.push(['theme', value]), setScheme: value => applied.push(['scheme', value]) } };
    component.setTheme({ currentTarget: { dataset: { theme: 'system' } } });
    component.setScheme({ currentTarget: { dataset: { scheme: 'violet' } } });
    assert.deepEqual(applied, [['theme', 'system'], ['scheme', 'violet']]);
});
