import assert from 'node:assert/strict';
import test from 'node:test';
import { tvShowDirectory } from '../../resources/js/alpine/components/tv-show-directory-component.js';

class Element {
    constructor(dataset = {}, children = []) {
        this.dataset = dataset;
        this.children = children;
        this.open = false;
        children.forEach(child => { child.parentElement = this; });
    }
    matches(selector) {
        return selector.split(',').some(part => {
            const match = part.trim().match(/^\[data-([\w-]+)(?:="([^"]*)")?\]$/);
            if (!match) return false;
            const key = match[1].replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
            return key in this.dataset && (match[2] === undefined || this.dataset[key] === match[2]);
        });
    }
    querySelectorAll(selector) {
        return this.children.flatMap(child => [...(child.matches(selector) ? [child] : []), ...child.querySelectorAll(selector)]);
    }
    querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null; }
    closest(selector) { return this.matches(selector) ? this : this.parentElement?.closest(selector); }
    hasAttribute(name) { return this.matches(`[${name}]`); }
    setAttribute() {}
    scrollIntoView() {}
    remove() { this.parentElement.children = this.parentElement.children.filter(child => child !== this); this.parentElement = null; }
    get firstElementChild() { return this.children[0] ?? null; }
    set innerHTML(html) {
        this.children.forEach(child => { child.parentElement = null; });
        this.children = html ? [fragment(JSON.parse(html))] : [];
        this.children.forEach(child => { child.parentElement = this; });
    }
}

function fragment({ show = false, kind = 'season', season = 2, page = 1, per = 24, episode = '', episodes = [21, 22] } = {}) {
    const list = new Element({ showList: '', kind, season: String(season), page: String(page), per: String(per), episode: String(episode) });
    if (kind === 'season') {
        list.children = [
            new Element({ packSection: '' }, [new Element({ packList: '' }, [new Element({ expandPacks: '' })])]),
            ...episodes.map(id => new Element({ episodeSection: String(id) }, [new Element({ episodeReleases: '' }, [new Element({ expandEpisode: String(id) })])])),
        ];
        list.children.forEach(child => { child.parentElement = list; });
    }
    const pager = new Element({ listPage: String(page + 1) });
    const select = new Element({ listPer: '' });
    list.children.push(pager, select);
    pager.parentElement = list; select.parentElement = list;
    return show ? new Element({}, [new Element({ showSeason: '1' }), new Element({ showSeason: '2' }), new Element({ seasonHost: '' }, [list])]) : list;
}

function setup() {
    const body = new Element();
    const destroyed = [];
    globalThis.window = { location: { href: 'https://nntmux.test/series' }, Alpine: {
        mutateDom: callback => callback(), destroyTree: tree => destroyed.push(tree), initTree() {},
    } };
    const pending = [];
    globalThis.fetch = (url, options) => new Promise((resolve, reject) => pending.push({ url, options, resolve, reject }));
    const component = tvShowDirectory();
    component.$refs = { showBody: body };
    component.directoryUrl = '/series/directory';
    const reply = (index, data = {}) => pending[index].resolve({ ok: true, text: async () => JSON.stringify(data) });
    const open = (id = '7') => component.openShow({ currentTarget: { dataset: { showId: id } } });
    return { component, body, pending, reply, open, destroyed };
}

const tick = () => new Promise(resolve => setImmediate(resolve));

test('closing releases the rendered body and navigation state and ignores a late fetch', async () => {
    const { component, body, pending, reply, open, destroyed } = setup();
    const opening = open();
    reply(0, { show: true });
    await opening;
    assert.equal(body.children.length, 1);
    const switching = component.switchSeason(body.querySelector('[data-show-season="1"]'));
    component.close();
    assert.equal(body.children.length, 0);
    assert.equal(component.seasons.size, 0);
    assert.equal(component.requests.size, 0);
    assert.equal(component.showId, null);
    assert.equal(pending[1].options.signal.aborted, true);
    reply(1, { season: 1 });
    await switching;
    assert.equal(body.children.length, 0);
    assert.ok(destroyed.length > 0);
    assert.equal(component.error, '');
});

function assertScalars(value) {
    if (value instanceof Map) { value.forEach(assertScalars); return; }
    if (value && Object.getPrototypeOf(value) === Object.prototype) { Object.values(value).forEach(assertScalars); return; }
    assert.ok(typeof value === 'number' || typeof value === 'boolean', 'navigation state must contain only numbers and booleans');
}

test('season navigation refetches saved pages and only restores visible open expansions', async () => {
    const { component, body, pending, reply, open } = setup();
    const opening = open(); reply(0, { show: true, page: 2, per: 48 }); await opening;
    const episode = body.querySelector('[data-episode-section="21"]');
    episode.open = true;
    component.navigate({ target: episode.querySelector('[data-expand-episode]') });
    reply(1, { kind: 'episode', episode: 21, page: 3, per: 100 }); await tick();
    const packs = body.querySelector('[data-pack-section]');
    packs.open = true;
    component.navigate({ target: packs.querySelector('[data-expand-packs]') });
    reply(2, { kind: 'packs', page: 2, per: 48 }); await tick();
    const switching = component.switchSeason(body.querySelector('[data-show-season="1"]'));
    assertScalars(component.seasons);
    assert.equal(body.querySelector('[data-season-host]').children.length, 0);
    reply(3, { season: 1, page: 3, per: 100, episodes: [11] }); await switching;
    const returning = component.switchSeason(body.querySelector('[data-show-season="2"]'));
    assert.equal(pending[4].url.searchParams.get('page'), '2');
    assert.equal(pending[4].url.searchParams.get('per'), '48');
    reply(4, { season: 2, page: 2, per: 48, episodes: [21, 23] }); await returning;
    await tick();
    const restored = pending.slice(5).map(request => Object.fromEntries(request.url.searchParams));
    assert.equal(restored.length, 2);
    assert.ok(restored.some(list => list.kind === 'episode' && list.episode === '21' && list.page === '3' && list.per === '100'));
    assert.ok(restored.some(list => list.kind === 'packs' && list.page === '2' && list.per === '48'));
    assert.equal(body.querySelector('[data-episode-section="21"]').open, true);
    assert.equal(body.querySelector('[data-episode-section="23"]').open, false);
    component.close();
    for (let index = 5; index < pending.length; index++) reply(index);
    await tick();
    assert.equal(component.restorationQueue.length, 0);
    assert.equal(component.restorations.size, 0);
});

test('close during opening and rapid show changes ignore late successes and failures, including the same show', async () => {
    const { component, body, pending, reply, open } = setup();
    const closed = open(); component.close(); reply(0, { show: true }); await closed;
    assert.equal(body.children.length, 0);
    const first = open('7');
    const second = open('8');
    const third = open('7');
    reply(3, { show: true, season: 4 }); await third;
    pending[1].reject(new Error('old failure')); await first;
    reply(2, { show: true, season: 3 }); await second;
    assert.equal(body.querySelector('[data-show-list]').dataset.season, '4');
    assert.equal(component.error, '');
    assert.equal(component.loading, false);
    assert.equal(component.requests.size, 0);
    assert.ok(pending.slice(0, 3).every(request => request.options.signal.aborted));
});

test('season changes abort nested loads and an old list failure cannot overwrite newer success', async () => {
    const { component, body, pending, reply, open } = setup();
    const opening = open(); reply(0, { show: true }); await opening;
    const section = body.querySelector('[data-episode-section="21"]'); section.open = true;
    component.navigate({ target: section.querySelector('[data-expand-episode]') });
    const first = component.switchSeason(body.querySelector('[data-show-season="1"]'));
    const second = component.switchSeason(body.querySelector('[data-show-season="2"]'));
    assert.equal(pending[1].options.signal.aborted, true);
    assert.equal(pending[2].options.signal.aborted, true);
    reply(3, { season: 2, episodes: [22] }); await second;
    pending[2].reject(new Error('old season failed')); await first;
    pending[1].reject(new Error('old expansion failed')); await tick();
    assert.equal(component.error, '');
    assert.equal(body.querySelector('[data-show-list]').dataset.season, '2');
    assert.equal(pending.length, 4, 'off-page expansion must not fetch');
});

test('restoration queues more than four visible expansions and close drains every collection', async () => {
    const { component, body, pending, reply, open } = setup();
    const episodes = [21, 22, 23, 24, 25, 26];
    const opening = open(); reply(0, { show: true, episodes }); await opening;
    body.querySelectorAll('[data-episode-section]').forEach(section => { section.open = true; });
    body.querySelector('[data-pack-section]').open = true;
    const away = component.switchSeason(body.querySelector('[data-show-season="1"]'));
    reply(1, { season: 1 }); await away;
    const back = component.switchSeason(body.querySelector('[data-show-season="2"]'));
    reply(2, { season: 2, episodes }); await back;
    assert.equal(pending.length, 7);
    assert.equal(component.restorations.size, 4);
    assert.equal(component.restorationQueue.length, 3);
    reply(3, { kind: 'episode', episode: 21 }); await tick();
    assert.equal(pending.length, 8);
    assert.equal(component.restorations.size, 4);
    assert.equal(component.restorationQueue.length, 2);
    let teardown = 0;
    component._modalTeardown = () => { teardown++; };
    component.destroy();
    assert.equal(teardown, 1);
    assert.equal(component._modalTeardown, null);
    assert.equal(component.restorations.size, 0);
    assert.equal(component.restorationQueue.length, 0);
    assert.equal(component.requests.size, 0);
    assert.equal(component.seasons.size, 0);
    assert.equal(body.children.length, 0);
    for (let index = 4; index < 8; index++) { assert.equal(pending[index].options.signal.aborted, true); reply(index); }
    await tick();
    assert.equal(pending.length, 8, 'aborted workers cannot restart the queue');
});

test('changing an episode page size resets only that list and ignores its superseded failure', async () => {
    const { component, body, pending, reply, open } = setup();
    const opening = open(); reply(0, { show: true, page: 3, per: 100 }); await opening;
    const section = body.querySelector('[data-episode-section="21"]'); section.open = true;
    component.navigate({ target: section.querySelector('[data-expand-episode]') });
    reply(1, { kind: 'episode', episode: 21, page: 2 }); await tick();
    component.navigate({ target: section.querySelector('[data-list-page]') });
    const select = section.querySelector('[data-list-per]'); select.value = '48';
    component.changePageSize({ target: select });
    assert.equal(pending[3].url.searchParams.get('page'), '1');
    assert.equal(pending[3].url.searchParams.get('per'), '48');
    reply(3, { kind: 'episode', episode: 21, page: 1, per: 48 }); await tick();
    pending[2].reject(new Error('old page failed')); await tick();
    assert.equal(component.error, '');
    assert.equal(body.querySelector('[data-show-list]').dataset.page, '3');
    assert.equal(body.querySelector('[data-show-list]').dataset.per, '100');
    assert.equal(section.querySelector('[data-show-list]').dataset.per, '48');
});

test('leaving during restoration preserves the saved expansion page for the next visit', async () => {
    const { component, body, reply, open, pending } = setup();
    const opening = open(); reply(0, { show: true }); await opening;
    const episode = body.querySelector('[data-episode-section="21"]'); episode.open = true;
    component.navigate({ target: episode.querySelector('[data-expand-episode]') });
    reply(1, { kind: 'episode', episode: 21, page: 4, per: 100 }); await tick();
    const away = component.switchSeason(body.querySelector('[data-show-season="1"]'));
    reply(2, { season: 1 }); await away;
    const back = component.switchSeason(body.querySelector('[data-show-season="2"]'));
    reply(3, { season: 2 }); await back;
    const awayAgain = component.switchSeason(body.querySelector('[data-show-season="1"]'));
    reply(5, { season: 1 }); await awayAgain;
    const backAgain = component.switchSeason(body.querySelector('[data-show-season="2"]'));
    reply(6, { season: 2 }); await backAgain;
    assert.equal(pending[7].url.searchParams.get('page'), '4');
    assert.equal(pending[7].url.searchParams.get('per'), '100');
    component.close(); reply(4); reply(7); await tick();
});
