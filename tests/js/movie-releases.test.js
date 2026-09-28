import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { paramsUrl } from '../../resources/js/alpine/components/tv-list.js';
import { tvReleases } from '../../resources/js/alpine/components/tv-releases-component.js';
import { resultItems } from '../../resources/js/alpine/components/tv-search-component.js';
import { yearMenu, yearRange, yearReady } from '../../resources/js/alpine/components/year-menu-component.js';

// The Movie releases list (issue #834): the checks of docs/proposals/movies-redesign/prototype/check.mjs that
// live in the browser — the Year menu, the search results, the URL a filter writes and the remembered sort.

function attributes(initial = {}) {
    const values = { ...initial };
    return {
        values,
        getAttribute: name => values[name] ?? null,
        setAttribute(name, value) { values[name] = String(value); },
        removeAttribute(name) { delete values[name]; },
    };
}

function classList(initial = []) {
    const classes = new Set(initial);
    return {
        classes,
        contains: name => classes.has(name),
        add: name => classes.add(name),
        remove: name => classes.delete(name),
        toggle: (name, on) => (on ? classes.add(name) : classes.delete(name)),
    };
}

function field(value = '') {
    return { value, focused: false, tagName: 'INPUT', focus() { this.focused = true; } };
}

/** A Year cell as x-year-menu renders it, with nothing set unless decades are ticked. */
function year(ticked = []) {
    const decades = ['2020', '2010', '2000', '1990', '1980'].map(value => ({
        ...attributes({ 'aria-checked': ticked.includes(value) ? 'true' : 'false' }),
        dataset: { value, text: value + 's' },
    }));
    const any = attributes({ 'aria-checked': ticked.length ? 'false' : 'true' });
    const events = [], rootClasses = classList(ticked.length ? ['is-set'] : []);
    const root = {
        dataset: { name: 'year', label: 'Year', summary: 'count', first: '1900', last: '2026' },
        querySelectorAll: selector => (selector === '[data-value]' ? decades : []),
        querySelector: selector => (selector === '[data-any]' ? any : null),
        classList: rootClasses,
        dispatchEvent: event => events.push(event),
        contains: () => true,
    };
    const component = yearMenu();
    component.$el = root;
    const value = { textContent: 'any', ...classList(['is-any']) };
    value.classList = value;
    component.$refs = {
        value,
        button: { ...attributes(), focused: false, focus() { this.focused = true; } },
        from: field(), to: field(), apply: { disabled: true },
        rangeHeading: { textContent: 'Range', dataset: { heading: 'Range' }, ...attributes(), classList: classList() },
    };
    component.init();
    return { component, decades, any, events, rootClasses };
}

globalThis.CustomEvent ??= class CustomEvent {
    constructor(type, options = {}) {
        this.type = type;
        this.detail = options.detail;
        this.bubbles = options.bubbles ?? false;
    }
};

test('Apply is ready once From holds four digits; To may stay empty (From alone is one year)', () => {
    assert.equal(yearReady('', ''), false);
    assert.equal(yearReady('199', ''), false);
    assert.equal(yearReady('1990', ''), true);
    assert.equal(yearReady('1990', '19'), false);
    assert.equal(yearReady('1980', '1989'), true);
    assert.deepEqual(yearRange('2024', '', 1900, 2026), { from: 2024, to: 2024 });
    assert.deepEqual(yearRange('1980', '1989', 1900, 2026), { from: 1980, to: 1989 });
    assert.equal(yearRange('1990', '1980', 1900, 2026), null, 'the later year first is refused');
    assert.equal(yearRange('1899', '', 1900, 2026), null);
    assert.equal(yearRange('2027', '', 1900, 2026), null, 'the menu stops at the current year');
});

test('ticking decades keeps the menu open, reads "1990s" then "2 chosen" with both in the title, and clears a range', () => {
    const { component, decades, any, events, rootClasses } = year();
    component.open = true;
    component.$refs.from.value = '1980';
    component.pick({ currentTarget: decades[3] });
    assert.equal(component.open, true);
    assert.equal(component.$refs.value.textContent, '1990s');
    assert.equal(component.$refs.from.value, '', 'ticking a decade replaces a range');
    assert.ok(rootClasses.contains('is-set'));
    assert.equal(any.getAttribute('aria-checked'), 'false');
    assert.deepEqual(events.at(-1).detail, { params: { decade: ['1990'], year_from: '', year_to: '' } });

    component.pick({ currentTarget: decades[2] });
    assert.equal(component.$refs.value.textContent, '2 chosen');
    assert.equal(component.$refs.button.getAttribute('title'), 'Year: 2000s, 1990s');
    assert.deepEqual(events.at(-1).detail.params.decade, ['2000', '1990']);
});

test('"Any year" unticks every decade and keeps the menu open', () => {
    const { component, decades, any, events, rootClasses } = year(['1990', '2000']);
    component.open = true;
    component.clear();
    assert.equal(component.open, true);
    assert.ok(decades.every(item => item.getAttribute('aria-checked') === 'false'));
    assert.equal(any.getAttribute('aria-checked'), 'true');
    assert.equal(component.$refs.value.textContent, 'any');
    assert.ok(!rootClasses.contains('is-set'));
    assert.deepEqual(events.at(-1).detail, { params: { decade: [], year_from: '', year_to: '' } });
});

test('a backwards range is refused in place of the Range heading and focus returns to From', () => {
    const { component, events } = year();
    component.open = true;
    component.$refs.from.value = '1990';
    component.$refs.to.value = '1980';
    component.applyRange();
    const heading = component.$refs.rangeHeading;
    assert.equal(heading.textContent, 'Years run 1900–2026, earliest first');
    assert.ok(heading.classList.contains('is-error'));
    assert.equal(heading.getAttribute('role'), 'alert');
    assert.equal(component.$refs.from.focused, true);
    assert.equal(component.open, true);
    assert.equal(events.length, 0);

    component.$refs.from.value = '2030';
    component.$refs.to.value = '';
    component.applyRange();
    assert.equal(heading.textContent, 'Years run 1900–2026, earliest first', 'a year after the current one is refused');
});

test('a valid range replaces the decades, reads "1980–1989", closes the menu and returns focus to the cell', () => {
    const { component, decades, any, events, rootClasses } = year(['1990']);
    component.open = true;
    component.$refs.from.value = '1980';
    component.$refs.to.value = '1989';
    component.applyRange();
    assert.equal(component.$refs.value.textContent, '1980–1989');
    assert.equal(component.$refs.button.getAttribute('title'), 'Year: 1980–1989');
    assert.ok(decades.every(item => item.getAttribute('aria-checked') === 'false'));
    assert.equal(any.getAttribute('aria-checked'), 'false');
    assert.ok(rootClasses.contains('is-set'));
    assert.equal(component.open, false);
    assert.equal(component.$refs.button.focused, true);
    assert.equal(component.$refs.rangeHeading.textContent, 'Range');
    assert.deepEqual(events.at(-1).detail, { params: { decade: [], year_from: '1980', year_to: '1989' } });

    const single = year();
    single.component.$refs.from.value = '2024';
    single.component.applyRange();
    assert.equal(single.component.$refs.value.textContent, '2024', 'From alone picks that one year');
    assert.deepEqual(single.events.at(-1).detail.params, { decade: [], year_from: '2024', year_to: '' });
});

test('the range fields keep digits only and enable Apply once From is ready', () => {
    const { component } = year();
    component.$refs.from.value = '19a9';
    component.rangeInput({ target: component.$refs.from });
    assert.equal(component.$refs.from.value, '199');
    assert.equal(component.$refs.apply.disabled, true);
    component.$refs.from.value = '1999';
    component.rangeInput({ target: component.$refs.from });
    assert.equal(component.$refs.apply.disabled, false);
    component.$refs.to.value = '20';
    component.rangeInput({ target: component.$refs.to });
    assert.equal(component.$refs.apply.disabled, true);
});

test('the Year change writes decade[] or the range and drops the page; the other filters stay', () => {
    const base = 'https://nntmux.test/movies?genre[]=3&decade[]=1990&page=4';
    const range = paramsUrl(base, { decade: [], year_from: '1980', year_to: '1989' });
    assert.equal(range.search, '?genre%5B%5D=3&year_from=1980&year_to=1989');
    const decades = paramsUrl(range.toString(), { decade: ['2000', '1990'], year_from: '', year_to: '' });
    assert.equal(decades.search, '?genre%5B%5D=3&decade%5B%5D=2000&decade%5B%5D=1990');
    assert.equal(paramsUrl(decades.toString(), { decade: [], year_from: '2024', year_to: '' }).search, '?genre%5B%5D=3&year_from=2024');
});

test('search lists films (poster, title, year · two genres) then people with their film count and first three films', () => {
    const urls = { show: '/movies/film', shows: '/movies/films' };
    const items = resultItems({
        films: [{ id: 21, title: 'Glass Meridian', year: 1994, genres: ['Horror', 'Comedy'], poster: '/covers/movies/1-cover.jpg' }, { id: 22, title: 'Salt', year: null, genres: [], poster: null }],
        people: [{ id: 1, name: 'Ada Glass', count: 12, films: ['A', 'B', 'C'] }, { id: 2, name: 'Glass Onion', count: 1, films: ['Salt'] }],
    }, urls, 'film');
    assert.deepEqual(items.map(item => [item.kind, item.href, item.title, item.detail, item.poster]), [
        ['film', '/movies/film/21', 'Glass Meridian', '1994 · Horror, Comedy', '/covers/movies/1-cover.jpg'],
        ['film', '/movies/film/22', 'Salt', '', null],
        ['person', '/movies/films?person=1', 'Ada Glass', '12 films: A, B, C', null],
        ['person', '/movies/films?person=2', 'Glass Onion', '1 film: Salt', null],
    ]);
});

test('changing the sort saves it under the Movies root and returns to page 1', async () => {
    const requests = [], history = [];
    globalThis.window = { location: { href: 'https://nntmux.test/movies?genre[]=3&page=5', assign: value => history.push(value) }, showToast() {} };
    globalThis.document = { querySelector: () => ({ content: 'csrf-token' }) };
    globalThis.fetch = async (url, options = {}) => {
        requests.push({ url: String(url), ...options });
        return { ok: true, json: async () => ({ success: true }) };
    };
    const component = tvReleases();
    component.$el = { dataset: { preferenceUrl: '/profile/update-view', preferenceRoot: 'movies' }, querySelectorAll: () => [], querySelector: () => null };
    component.init();
    await component.changeSort({ target: { value: 'oldest' } });
    assert.deepEqual(JSON.parse(requests[0].body), { root: 'movies', sort: 'oldest' });
    assert.deepEqual(history, ['https://nntmux.test/movies?genre%5B%5D=3']);
});

test('the Year menu shows decades in three columns and the range without scrolling; a refusal takes the heading red', () => {
    const css = readFileSync(new URL('../../resources/css/tv.css', import.meta.url), 'utf8');
    const app = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    assert.match(css, /\.year-menu-decades \{ display: grid; grid-template-columns: repeat\(3, 1fr\);/);
    assert.match(css, /\.filter-bar \.year-menu-panel \{ width: 290px; max-height: none; overflow: visible; \}/);
    assert.match(css, /\.checkbox-menu-heading\.is-error \{ color: var\(--tv-error\); \}/);
    assert.match(css, /\.tv-bar-list \.filter-bar\.is-film \{ flex: 5 1 0; \}/, 'the release and film bars share the row equally');
    assert.match(css, /\.year-menu-range \.tv-button:disabled \{ background: var\(--tv-panel-alt\); color: var\(--tv-dim\);/, 'a disabled Apply is neutral, not faded coral');
    assert.match(app, /--text-error: #b3261e;/);
    assert.match(app, /--text-error-dark: #ff7a7a;/);
});
