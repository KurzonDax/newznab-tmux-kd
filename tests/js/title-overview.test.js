import assert from 'node:assert/strict';
import test from 'node:test';
import { trailerModal } from '../../resources/js/alpine/components/trailer-modal-component.js';
import { titleOverview } from '../../resources/js/alpine/components/title-overview-component.js';

function overview() {
    const urls = [];
    globalThis.window = { location: { href: 'https://nntmux.test/title/tv/12' }, history: { replaceState: (a, b, url) => urls.push(String(url)) } };
    const component = titleOverview();
    component.$refs = { releases: { innerHTML: 'Initial season' } };
    component.init();
    return { component, urls };
}

test('switching seasons discards a late reply and keeps the latest table and URL together', async () => {
    const { component, urls } = overview();
    const pending = [];
    globalThis.fetch = (url, options) => new Promise(resolve => pending.push({ url, options, resolve }));
    const first = component.loadReleases(new URL('https://nntmux.test/title/tv/12?season=1'));
    const second = component.loadReleases(new URL('https://nntmux.test/title/tv/12?season=3'));
    assert.equal(pending[0].options.signal.aborted, true);
    assert.equal(pending[1].url.searchParams.get('_fragment'), 'releases');
    pending[1].resolve({ ok: true, text: async () => '<div data-title-releases>Season 3</div>' });
    await second;
    pending[0].resolve({ ok: true, text: async () => '<div data-title-releases>Season 1</div>' });
    await first;
    assert.equal(component.$refs.releases.innerHTML, '<div data-title-releases>Season 3</div>');
    assert.deepEqual(urls, ['https://nntmux.test/title/tv/12?season=3']);
    assert.equal(component.loading, false);
});

test('a failed filter preserves the working table and can be retried', async () => {
    const { component, urls } = overview();
    globalThis.fetch = async () => ({ ok: false });
    const filtered = new URL('https://nntmux.test/title/tv/12?quality[]=2160p');
    await component.loadReleases(filtered);
    assert.equal(component.$refs.releases.innerHTML, 'Initial season');
    assert.match(component.error, /try again/i);
    assert.equal(urls.length, 0);
    assert.equal(component.pendingUrl.searchParams.has('quality[]'), false);
    globalThis.fetch = async () => ({ ok: true, text: async () => '<div data-title-releases>Filtered season</div>' });
    await component.loadReleases(filtered);
    assert.equal(component.error, '');
    assert.equal(component.$refs.releases.innerHTML, '<div data-title-releases>Filtered season</div>');
});

test('quality toggles combine pending choices, retain the season, and reset pagination', () => {
    const { component } = overview();
    component.pendingUrl = new URL('https://nntmux.test/title/tv/12?season=3&quality[0]=1080p&page=2');
    const queries = [];
    component.loadReleases = url => { component.pendingUrl = url; queries.push(url); };
    const click = value => component.navigateReleases({ preventDefault() {}, target: { closest: selector => selector === '[data-title-quality]' ? { dataset: { titleQuality: value } } : null } });
    click('2160p');
    assert.deepEqual(queries[0].searchParams.getAll('quality[]'), ['1080p', '2160p']);
    assert.equal(queries[0].searchParams.get('season'), '3');
    assert.equal(queries[0].searchParams.has('page'), false);
    click('1080p');
    assert.deepEqual(queries[1].searchParams.getAll('quality[]'), ['2160p']);
    click('');
    assert.deepEqual(queries[2].searchParams.getAll('quality[]'), []);
});


test('session redirects and unexpected documents preserve the previous table', async () => {
    const { component, urls } = overview();
    for (const redirected of [true, false]) {
        globalThis.fetch = async () => ({ ok: true, redirected, text: async () => '<!DOCTYPE html><form>Log in</form>' });
        await component.loadReleases(new URL('https://nntmux.test/title/tv/12?season=1'));
        assert.equal(component.$refs.releases.innerHTML, 'Initial season');
        assert.match(component.error, /try again/i);
        assert.equal(urls.length, 0);
    }
});


test('trailer close and teardown release the player and document listener', () => {
    const events = {};
    globalThis.document = { createElement: () => ({}), addEventListener: (name, listener) => { events[name] = listener; }, removeEventListener: name => { delete events[name]; } };
    const modal = trailerModal();
    const container = { children: [], replaceChildren(...children) { this.children = children; } };
    modal.$el = { querySelector: () => container };
    modal.initModal = () => {};
    modal.init();
    events.click({ target: { closest: () => ({ dataset: { trailerUrl: 'https://www.youtube-nocookie.com/embed/Way9Dexny3w' } }) } });
    assert.equal(modal.open, true);
    assert.match(modal.url, /Way9Dexny3w/);
    assert.equal(container.children.length, 1);
    assert.equal(container.children[0].tabIndex, 0);
    modal.close();
    assert.equal(modal.open, false);
    assert.equal(modal.url, '');
    assert.equal(container.children.length, 0);
    modal.destroy();
    assert.equal(events.click, undefined);
});
