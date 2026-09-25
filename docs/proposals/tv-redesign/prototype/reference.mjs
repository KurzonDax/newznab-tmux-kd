// Produces the visual contract's evidence from a served copy of the prototype:
//   reference/<state>-<theme>.webp   one screenshot per screen state, dark and light
//   reference/measurements.json      computed type, colour, size and spacing of every named part
//   reference/tokens.json            the resolved value of every colour token, per theme
// Usage: TV_URL=http://127.0.0.1:8767/tv.html node reference.mjs <out-dir>   (needs Chrome + cwebp)
import {spawn, execFileSync} from 'node:child_process';
import {writeFileSync, mkdirSync, mkdtempSync, unlinkSync} from 'node:fs';
import {tmpdir} from 'node:os';
const URL_ = process.env.TV_URL || 'http://127.0.0.1:8766/tv.html', out = (process.argv[2] || '.') + '/reference', PORT = 9381, W = 1600, H = 1000;
const MAIN = process.argv[1] && process.argv[1].endsWith('reference.mjs');
if (MAIN) mkdirSync(out, {recursive: true});
const FX = !MAIN ? {} : await (await fetch(new URL('fixtures.json', URL_))).json();
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', ['--headless=new', '--disable-gpu', '--hide-scrollbars', `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(tmpdir() + '/cdp-')}`, `--window-size=${W},${H}`, 'about:blank'], {stdio: 'ignore'});
const sleep = ms => new Promise(r => setTimeout(r, ms)); let ws, seq = 0; const pending = new Map(), errors = [];
for (let i = 0; i < 40; i++) { try { const t = await (await fetch(`http://127.0.0.1:${PORT}/json`)).json(); const p = t.find(x => x.type === 'page'); if (p) { ws = new WebSocket(p.webSocketDebuggerUrl); await new Promise(r => ws.onopen = r); break; } } catch (e) {} await sleep(250); }
ws.onmessage = m => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d.result); pending.delete(d.id); } if (d.method === 'Runtime.exceptionThrown') errors.push(d.params.exceptionDetails.exception?.description); };
const send = (method, params = {}) => new Promise(r => { const id = ++seq; pending.set(id, r); ws.send(JSON.stringify({id, method, params})); });
const js = async e => { const r = await send('Runtime.evaluate', {expression: e, awaitPromise: true, returnByValue: true}); if (r.exceptionDetails) errors.push(e.slice(0, 80) + ' :: ' + r.exceptionDetails.exception?.description); return r.result?.value; };
await send('Runtime.enable'); await send('Page.enable'); await send('Emulation.setDeviceMetricsOverride', {width: W, height: H, deviceScaleFactor: 1, mobile: false});

const load = async hash => { await send('Page.navigate', {url: 'about:blank'}); await send('Page.navigate', {url: URL_ + hash}); for (let i = 0; i < 80 && !(await js('!!window.READY')); i++) await sleep(150); await sleep(500); };
const theme = async t => { if ((await js(`document.documentElement.dataset.theme`)) !== t) { await js(`document.querySelector('[aria-label*=heme],#theme,[data-theme-toggle]').click()`); await sleep(250); } };
const click = async sel => { await js(`document.querySelector(${JSON.stringify(sel)}).click()`); await sleep(350); };
const shot = async (name, full) => { const r = await send('Page.captureScreenshot', {format: 'png', captureBeyondViewport: !!full}); const png = `${out}/${name}.png`; writeFileSync(png, Buffer.from(r.data, 'base64')); execFileSync('cwebp', ['-quiet', '-q', '68', png, '-o', `${out}/${name}.webp`]); unlinkSync(png); };

// name, start route, steps to reach the state, full-page?
const STATES = [
  ['releases', '#/', async () => {}],
  ['releases-category-menu', '#/', async () => { await click('[data-ddtoggle=cat]'); await js(`document.querySelectorAll('[data-ddpick=cat]')[0].click()`); await sleep(200); await js(`document.querySelectorAll('[data-ddpick=cat]')[2].click()`); await sleep(300); }],
  ['releases-resolution-menu', '#/', async () => { await click('[data-ddtoggle=res]'); await js(`document.querySelector('[data-ddpick=res][data-v="4K"]').click()`); await sleep(200); await js(`document.querySelector('[data-ddpick=res][data-v="1080p"]').click()`); await sleep(300); }],
  ['releases-selection', '#/', async () => { for (const i of [0, 1, 3]) { await js(`document.querySelectorAll('.feed [data-select]')[${i}].click()`); await sleep(200); } }],
  ['releases-batch-expanded', '#/', async () => { await click('[data-expand]'); }, true],
  ['releases-empty', '#/', async () => { await js(`state.res.add('4K');state.src.add('DVD');state.cat.add('SD');route()`); await sleep(300); }],
  ['releases-last-page', '#/', async () => { await js(`location.hash='#/p/'+document.querySelector('.pager.slim .pg').textContent.split(' of ')[1]`); await sleep(500); }, true],
  ['shows', '#/shows', async () => {}],
  ['shows-filters-set', '#/shows', async () => { await js(`state.sf.genre.add('Drama');state.sf.genre.add('Comedy');state.sf.lang.add('English');state.dd='sf_net';route();placeMenu()`); await sleep(350); }],
  ['shows-person', '#/shows', async () => { await js(`state.sf.person=M(${FX.longShow}).cast[0];route()`); await sleep(300); }],
  ['shows-empty', '#/shows', async () => { await js(`state.sf.genre.add('War');state.sf.rating.add('TV-Y');state.sf.status.add('Ended');state.sf.decade.add('1980s');route()`); await sleep(300); }],
  ['search-results', '#/', async () => { await js(`(()=>{const q=document.querySelector('#q');q.focus();q.value=${JSON.stringify(FX.searchQuery.slice(0, 3))};q.dispatchEvent(new Event('input'));})()`); await sleep(350); }],
  ['show', `#/show/${FX.longShow}/1`, async () => {}],
  ['show-episode-open-selected', `#/show/${FX.longShow}/1`, async () => { await js(`document.querySelectorAll('.ep>button')[1].click()`); await sleep(350); await js(`document.querySelectorAll('.ep[open-] [data-select]')[0].click()`); await sleep(250); await js(`document.querySelectorAll('.ep[open-] [data-select]')[1].click()`); await sleep(300); }, true],
  ['show-24-seasons', `#/show/${FX.manySeasons}/3`, async () => {}],
  ['show-thin-no-poster-no-details', `#/show/${FX.thinShow}`, async () => {}],
  ['show-filter-empty', `#/show/${FX.longShow}/1`, async () => { await js(`state.res.add('Unknown');state.src.add('DVD');route()`); await sleep(300); }],
  ['details-overview', `#/release/${FX.filesRelease}`, async () => {}, true],
  ['details-media-info', `#/release/${FX.filesRelease}`, async () => { await click('[data-tab=media]'); await sleep(900); }, true],
  ['details-files', `#/release/${FX.filesRelease}`, async () => { await click('[data-tab=files]'); await sleep(700); }],
  ['details-nfo', '#/', async () => { await js(`location.hash='#/release/'+REL.find(r=>X(r).nfo).id`); await sleep(500); await click('[data-tab=nfo]'); await sleep(700); }],
  ['dialog-media-info', '#/', async () => { await click('.feed [data-mi]'); await sleep(900); }],
  ['dialog-nfo', '#/', async () => { await js(`state.per=9000;route()`); await sleep(400); await click('.feed [data-nfo]'); await sleep(800); }],
  ['dialog-files', `#/release/${FX.filesRelease}`, async () => { await click('.sibs tr.me [data-files]'); await sleep(900); }],
  ['dialog-image-fit', `#/release/${FX.fullPreviewRelease}`, async () => { await click('.dhead [data-img]'); await sleep(900); }],
  ['dialog-image-full-size', `#/release/${FX.fullPreviewRelease}`, async () => { await click('.dhead [data-img]'); await sleep(900); await click('.pvbar [data-full]'); }],
];

// every part an implementation must match: selector → the computed properties that define it
export const PARTS = {
  'page ground and body text': 'body', 'content width wrapper': '.wrap', 'page title': '.filters h1', 'view switch, current': '.seg a[aria-pressed=true]', 'view switch, other': '.seg a[aria-pressed=false]',
  'filter menu button': '.msel:not(.set) .mbtn', 'filter menu button, set': '.msel.set .mbtn', 'filter menu panel': '.mmenu', 'filter menu item': '.mitem', 'filter menu checkbox, ticked': '.mitem[aria-checked=true] .box',
  'sort dropdown': '.filters .sel select', 'showing line': '.pager.slim .sum', 'page x of y': '.pager.slim .pg', 'pager arrow': '.pager.slim a, .pager.slim .off',
  'releases table header cell': 'table.feed th', 'release row cell': 'table.feed tbody tr:first-child td', 'row poster': '.feed td.art img, .feed td.art .np', 'release name': '.feed a.rname', 'show line under the name': '.feed .showlink',
  'chip base': '.feed .rc', 'completion chip': '.rc.comp', 'media info chip': '.rc.k-mi', 'NFO chip': '.rc.k-nfo', 'Preview chip': '.rc.k-pv',
  'resolution chip 4K': '.res.r-4k', 'resolution chip 1080p': '.res.r-1080', 'resolution chip 720p': '.res.r-720', 'resolution chip SD': '.res.r-sd', 'resolution chip Unknown': '.res.r-unk',
  'row action button': '.iconacts .ia:not(.dl)', 'row action: download': '.iconacts .ia.dl', 'file count button': '.filesbtn', 'batch expander': '.moreof button', 'selection bar': '.bulk', 'selection bar primary button': '.bulk .btn:not(.sec)',
  'bottom pager current page': 'nav.pager [aria-current]',
  'show tile': '.tile', 'show tile art': '.tile .art', 'show tile title': '.tile b', 'show tile line 1': '.tile .what', 'shows filter button': '.sfm .mbtn', 'starring chip': '.person',
  'search field': '#q', 'search results panel': '#results',
  'show page title': '.showhead h1', 'show page poster': '.showhead .art', 'show meta line': '.showhead .meta', 'genre tag (link)': 'a.tag', 'plain tag': '.tag.plain', 'starring line': '.starring',
  'season tab bar': '.bar2', 'season tab': '.stabs a:not([aria-current])', 'season tab, current': '.stabs a[aria-current]', 'episode row': '.ep>button', 'episode number': '.ep .no', 'episode title': '.ep .ti', 'releases button, closed': '.ep:not([open-]) .relbtn', 'releases button, open': '.ep[open-] .relbtn',
  'episode release table header': 'table.rt th', 'episode release table cell': 'table.rt tbody tr:first-child td',
  'details heading': '.dhead h1', 'details release name': '.relname', 'details poster': '.dhead .art', 'details primary button': '.dacts .btn:not(.sec)', 'details secondary button': '.dacts .btn.sec', 'tab': '.tabs button[aria-selected=false]', 'tab, current': '.tabs button[aria-selected=true]',
  'facts label': 'dl.vals dt', 'facts value': 'dl.vals dd', 'about the show heading': '.aboutshow h2', 'episode releases heading': '.sibs h2', 'current release row': '.sibs tr.me td', 'current release label': '.sibs .this',
  'dialog': '.dlg', 'dialog title': '.dlg header h2', 'dialog close button': '.dlg header .ia',
  'media info glance value': '.mi2 .glance dd', 'media info section chip: video': '.hue.hv', 'media info section chip: audio': '.hue.ha', 'media info section chip: subtitles': '.hue.hs', 'media info table header': '.mi2 th', 'media info table cell': '.mi2 tbody tr:first-child td',
};
export const PROPS = ['fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'color', 'backgroundColor', 'borderRadius', 'borderTopWidth', 'borderTopColor', 'borderBottomWidth', 'borderBottomColor', 'boxShadow', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'gap', 'width', 'height', 'maxWidth'];
export const RGBA = `const __cv=document.createElement('canvas').getContext('2d',{willReadFrequently:true});const rgba=s=>{if(!s||s==='none')return s;__cv.clearRect(0,0,1,1);__cv.fillStyle='#000';__cv.fillStyle=s;__cv.fillRect(0,0,1,1);const d=__cv.getImageData(0,0,1,1).data;return 'rgba('+d[0]+','+d[1]+','+d[2]+','+(d[3]/255).toFixed(2)+')';};`;
const COLOR_PROPS = ['color', 'backgroundColor', 'borderTopColor', 'borderBottomColor'];
const measure = () => js(`(()=>{${RGBA}const P=${JSON.stringify(PROPS)},CP=${JSON.stringify(COLOR_PROPS)},parts=${JSON.stringify(PARTS)},o={};for(const [n,s] of Object.entries(parts)){const e=document.querySelector(s);if(!e)continue;const c=getComputedStyle(e),r=e.getBoundingClientRect(),v={};for(const p of P)v[p]=CP.includes(p)?rgba(c[p]):c[p];v.renderedWidth=Math.round(r.width*10)/10;v.renderedHeight=Math.round(r.height*10)/10;o[n]=v;}return o;})()`);
const TOKENS = ['--bg', '--panel', '--panel2', '--line', '--ink', '--dim', '--acc', '--accink', '--ok', '--warn', '--bad', '--shadow', '--k-mi-bg', '--k-mi-fg', '--k-nfo-bg', '--k-nfo-fg', '--k-pv-bg', '--k-pv-fg', '--k-sm-bg', '--k-sm-fg', '--c-ok-bg', '--c-ok-fg', '--c-mid-bg', '--c-mid-fg', '--c-low-bg', '--c-low-fg', '--r4k-bg', '--r4k-fg', '--r1080-bg', '--r1080-fg', '--r720-bg', '--r720-fg', '--rsd-bg', '--rsd-fg'];

const measurements = {}, tokens = {};
for (const th of ['dark', 'light']) {
  measurements[th] = {};
  for (const [name, route, steps, full] of STATES) {
    await load(route); await theme(th); await steps(); await shot(`${name}-${th}`, full);
    Object.assign(measurements[th], {...(await measure()), ...measurements[th]});   // first sighting of a part wins
  }
  tokens[th] = await js(`(()=>{${RGBA}const c=getComputedStyle(document.documentElement),o={};for(const t of ${JSON.stringify(TOKENS)}){const v=c.getPropertyValue(t).trim();o[t]=t==='--shadow'?v:{declared:v,rgba:rgba(v)};}return o;})()`);
  tokens[th].hues = await js(`(()=>{${RGBA}const o={};for(const k of ['hv','ha','hs','k-dv','k-hdrp','k-hdr','k-atmos','k-71','k-51','k-20','k-def','k-forced','k-sdh','k-img']){const e=document.createElement('span');e.className='hue '+k;document.body.appendChild(e);const c=getComputedStyle(e);o[k]={background:rgba(c.backgroundColor),color:rgba(c.color)};e.remove();}return o;})()`);
}
const colgroup = await js(`(async()=>{location.hash='#/';await new Promise(r=>setTimeout(r,500));return [...document.querySelectorAll('table.feed colgroup col')].map(c=>c.style.width||'auto');})()`);
writeFileSync(`${out}/measurements.json`, JSON.stringify({viewport: [W, H], releasesTableColumns: colgroup, parts: measurements}, null, 1));
writeFileSync(`${out}/tokens.json`, JSON.stringify(tokens, null, 1));
writeFileSync(`${out}/parts.json`, JSON.stringify({props: PROPS, colorProps: COLOR_PROPS, parts: PARTS, states: STATES.map(([n, route, , full]) => ({name: n, prototypeRoute: route, fullPage: !!full}))}, null, 1));
const missing = Object.keys(PARTS).filter(n => !measurements.dark[n]);
console.log(`states ${STATES.length} × 2 themes, parts measured ${Object.keys(measurements.dark).length}/${Object.keys(PARTS).length}${missing.length ? ', NOT FOUND: ' + missing.join('; ') : ''}, errors ${errors.length ? JSON.stringify(errors.slice(0, 5)) : 'none'}`);
chrome.kill('SIGKILL'); process.exit(errors.length || missing.length ? 1 : 0);
