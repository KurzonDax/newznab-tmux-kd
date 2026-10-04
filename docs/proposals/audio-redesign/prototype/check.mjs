// Drives headless Chrome over CDP: clicks every control on audio.html and reports pass/fail.
// One check per rule: the TV / Movies / Adult / Console rules Audio inherits, and the Audio calls of 2026-10-04 (AUDIO-DESIGN-RECORD.md).
// node aucheck.mjs     (V=1 prints progress on stderr)
import {spawn} from 'node:child_process';
import {mkdtempSync} from 'node:fs';
import {tmpdir} from 'node:os';
const BASE = process.env.SH_BASE || 'http://localhost:8766/', PORT = 9462;
const PAGES = ['audio'];
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', ['--headless=new', '--disable-gpu', '--autoplay-policy=no-user-gesture-required', `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(tmpdir() + '/aucdp-')}`, 'about:blank'], {stdio: 'ignore'});
const sleep = ms => new Promise(r => setTimeout(r, ms));
const results = []; let ws, seq = 0; const pending = new Map(), errors = [];
for (let i = 0; i < 40; i++) { try { const t = await (await fetch(`http://localhost:${PORT}/json`)).json(); const p = t.find(x => x.type === 'page'); if (p) { ws = new WebSocket(p.webSocketDebuggerUrl); await new Promise(r => ws.onopen = r); break; } } catch {} await sleep(250); }
ws.onmessage = m => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d.result); pending.delete(d.id); } if (d.method === 'Runtime.exceptionThrown') errors.push(d.params.exceptionDetails.exception?.description || d.params.exceptionDetails.text); };
const send = (method, params = {}) => new Promise(r => { const id = ++seq; pending.set(id, r); ws.send(JSON.stringify({id, method, params})); });
let PAGE = '';
const js = async expr => { const r = await send('Runtime.evaluate', {expression: expr, awaitPromise: true, returnByValue: true}); if (r.exceptionDetails) { results.push(['FAIL', PAGE + ': eval threw', expr.slice(0, 90) + ' :: ' + (r.exceptionDetails.exception?.description || '').split('\n')[0]]); return undefined; } return r.result.value; };
const ok = (name, cond, extra = '') => { results.push([cond ? 'PASS' : 'FAIL', PAGE + ': ' + name, extra]); if (process.env.V) console.error((cond ? 'PASS ' : 'FAIL ') + PAGE + ': ' + name + (cond ? '' : ' ' + extra)); };
const go = async hash => { await js(`location.hash=${JSON.stringify(hash)}`); await sleep(300); if (/^#\/release\//.test(hash)) for (let i = 0; i < 20 && !(await js(`!!document.querySelector('.mdet,.later')`)); i++) await sleep(150); };
const key = (k, code) => send('Input.dispatchKeyEvent', {type: 'keyDown', key: k, code: code || k, windowsVirtualKeyCode: k === 'Escape' ? 27 : 0}).then(() => send('Input.dispatchKeyEvent', {type: 'keyUp', key: k, code: code || k}));
const load = async () => { await send('Page.navigate', {url: BASE + PAGE + '.html?r=' + Math.random() + '#/'}); for (let i = 0; i < 60 && !(await js('!!window.READY')); i++) await sleep(200); await sleep(300); };
const open = k => js(`(()=>{if(state.dd!=='${k}')document.querySelector('[data-ddtoggle=${k}]').click();return state.dd==='${k}';})()`);
const pick = async (k, v) => { await open(k); await js(v === 'Any' ? `document.querySelector('[data-ddany=${k}]').click()` : `document.querySelector('[data-ddpick=${k}][data-v="${v}"]').click()`); };
const radio = async (k, v) => { await open(k); await js(`document.querySelector('[data-rpick="${k}:${v}"]').click()`); };
const close = () => js(`document.querySelector('.pager .sum').click()`);
const count = () => js(`+document.querySelector('.pager.slim .sum').textContent.replace(/^.* of ([\\d,]+) .*$/,'$1').replace(/,/g,'')`);
const ROWIDS = `[...document.querySelectorAll('.feed tbody tr [data-nzb]')].map(b=>+b.dataset.nzb)`;
const every = pred => js(`(()=>{const ids=${ROWIDS};return ids.length>0&&ids.every(id=>{const r=BYID[id],x=X(r);return ${pred};});})()`);
const rect = sel => js(`(()=>{const e=document.querySelector(${JSON.stringify(sel)});if(!e)return null;const r=e.getBoundingClientRect();return [r.left,r.top,r.width,r.height];})()`);

await send('Runtime.enable'); await send('Emulation.setAutoDarkModeOverride',{enabled:false}); await send('Page.enable'); await send('Emulation.setFocusEmulationEnabled', {enabled: true});
await send('Emulation.setDeviceMetricsOverride', {width: 1600, height: 1000, deviceScaleFactor: 1, mobile: false});


for (PAGE of PAGES) { try {
  await load(); await js('localStorage.clear()'); await load();
  const FX = await js(`fetch(DIR+'fixtures.json').then(r=>r.json())`);
  const TOTAL = await js('REL.length');
  const HEAD = 'Audio releases';

  // ---- the screen ----
  ok(`heading "${HEAD}", no Releases / wall switch`, await js(`document.querySelector('.filters h1').textContent`) === HEAD && await js(`!document.querySelector('.filters .seg')`));
  ok('header bar: Movies, TV, Audio, Books, Console, PC, Adult, Other, All (#908 order); Audio marked current', await js(`[...document.querySelectorAll('.nav a.l')].map(a=>a.textContent).join(',')`) === 'Movies,TV,Audio,Books,Console,PC,Adult,Other,All' && await js(`document.querySelector('.nav a.l.on').textContent`) === 'Audio');
  ok('release panel Category, Completion; then the music panel Genre, Year (the Console form); no Password, Label or Artist filter', await js(`[...document.querySelectorAll('.fbar .mbtn .k')].map(k=>k.textContent).join(',')`) === 'Category,Completion,Genre,Year' && await js(`(()=>{const g=document.querySelectorAll('.fbar .fseg');return g.length===2&&g[0].getAttribute('aria-label')==='The release'&&g[1].getAttribute('aria-label')==='The music';})()`));
  ok('every cell is as wide as a Movies release bar cell', await js(`(()=>{const w=document.querySelector('.filters').getBoundingClientRect().width,movies=((w-12)/2-8)/5,c=[...document.querySelectorAll('.fbar .sfm')].map(e=>e.getBoundingClientRect().width);return c.length===4&&c.every(v=>Math.abs(v-movies)<1.5);})()`));
  ok('name search "Search releases, artists or albums" beside the heading', await js(`document.querySelector('.filters h1').nextElementSibling.querySelector('input').placeholder`) === 'Search releases, artists or albums');
  ok('four sorts, Posted: newest first by default', await js(`[...document.querySelectorAll('[data-rsort] option')].map(o=>o.textContent).join('|')`) === 'Posted: newest first|Posted: oldest first|Added: newest first|Added: oldest first' && await js(`document.querySelector('[data-rsort]').value`) === 'posted_desc');
  ok('pager line shows every release of the band (prod count, 3,719)', await count() === TOTAL && TOTAL === FX.total, String(TOTAL));
  ok('at most 50 releases a page, newest posted first', await js(`(()=>{const ids=${ROWIDS},t=ids.map(i=>BYID[i].t);return ids.length===50&&t.every((v,i)=>!i||t[i-1]>=v);})()`));
  ok('columns: Release (cover + name), Category, Genre, Size, Posted; no Resolution, Source, Files or Grabs', await js(`[...document.querySelectorAll('.feed thead th')].map(t=>t.textContent.trim()).filter(Boolean).join(',')`) === 'Release,Category,Genre,Size,Posted,Actions' && await js(`!!document.querySelector('.feed td.art')`));
  ok('fixed column widths 34 · 110 · auto · 128 · 150 · 82 · 112 · 96', await js(`[...document.querySelectorAll('.feed colgroup col')].map(c=>c.style.width||'auto').join(',')`) === '34px,110px,auto,128px,150px,82px,112px,96px');
  await pick('cat', 'Lossless'); await close();
  ok('cover: square 88 × 88 (album art is square), links to the details; "No cover" dashed tile when there is none', await every(`(()=>{const a=document.querySelector('.feed tbody tr:has([data-nzb="'+id+'"]) td.art a'),img=a.querySelector('img');return a.getAttribute('href')==='#/release/'+id&&(ART[id]?!!img&&img.getAttribute('src')===DIR+'covers/'+id+'.webp':!img&&a.classList.contains('nopic')&&a.textContent.trim()==='No cover');})()`) && await js(`(()=>{const a=document.querySelector('.feed td.art a').getBoundingClientRect();return Math.round(a.width)===88&&Math.round(a.height)===88;})()`) && await js(`[...document.querySelectorAll('.feed td.art a.nopic')].every(a=>getComputedStyle(a).borderStyle==='dashed')`));
  ok('every cover image on the page loads', await js(`Promise.all([...document.querySelectorAll('.feed td.art img')].map(i=>fetch(i.src).then(r=>r.ok&&/image/.test(r.headers.get('content-type')||''),()=>false))).then(a=>a.length>0&&a.every(Boolean))`));
  ok('music line under the name: "Artist – Album · Year" in dim text, plain text; none when the tags name no album or artist', await every(`(()=>{const l=document.querySelector('.feed tbody tr:has([data-nzb="'+id+'"]) td.what .gameline'),m=MU[id]||{},t=[m.a,m.al].filter(Boolean).join(' – ');return t?!!l&&l.tagName==='SPAN'&&l.textContent===t+(m.y?' · '+m.y:''):!l;})()`) && await js(`!!document.querySelector('.feed .gameline')`));
  ok('Genre column: the tags\' genres as a comma list, "—" when none', await every(`document.querySelector('.feed tbody tr:has([data-nzb="'+id+'"]) td.c-gen').textContent===(genOf(r).join(', ')||'—')`));
  ok('Listen chip on every release with a preview, none without; magenta (the Clip kind), last in the chip line before group / poster', await every(`(()=>{const c=document.querySelector('.feed tbody tr:has([data-nzb="'+id+'"]) [data-listen]');return pvOf(r)?!!c&&c.textContent==='Listen'&&c.classList.contains('k-cl')&&c.nextElementSibling.classList.contains('orig'):!c;})()`) && await js(`!!document.querySelector('.feed [data-listen]')`));
  ok('no Preview, Sample or Clip chip, no Resolution chip', await js(`!document.querySelector('.feed .k-pv,.feed .k-sm,.feed [data-clip],.feed .res')`));
  ok('media info chip on an audio-only release reads codec and channels ("FLAC 2.0")', await js(`[...document.querySelectorAll('.feed [data-mi]')].some(c=>/^(FLAC|MP3|MPEG Audio|AAC|ALAC|WavPack|DSD|Opus|Vorbis|PCM).* \\d\\.\\d$/.test(c.textContent))`));
  await js(`document.querySelector('[data-clearall]').click()`); await sleep(100);
  ok('only the release name is bold in a row', await js(`(()=>{const tr=document.querySelector('.feed tbody tr');return [...tr.querySelectorAll('*')].filter(e=>getComputedStyle(e).fontWeight>=700&&e.textContent.trim()).every(e=>e.classList.contains('rname'));})()`));
  ok('the Category column is never cut short', await js(`[...document.querySelectorAll('.feed tbody td.c-cat')].every(td=>td.scrollWidth<=td.clientWidth)`));
  ok('row buttons 2 × 2: Download coral, Copy link blue, Cart green; no Follow', await js(`(()=>{const a=document.querySelector('.feed .iconacts.stack'),c=s=>getComputedStyle(a.querySelector(s)).backgroundColor;return a.querySelectorAll('.ia:not(.slot)').length===3&&!a.querySelector('[data-watch]')&&c('.dl')!==c('[data-copynzb]')&&c('[data-copynzb]')!==c('[data-cart]');})()`));
  ok('group + poster outline chips are one unit, same-tab app links', await js(`(()=>{const o=document.querySelector('.feed .rchips .orig');return !!o&&[...o.querySelectorAll('a')].every(a=>a.classList.contains('origin')&&!a.target);})()`));
  ok('no Report button, no details button in rows', await js(`!document.querySelector('.feed [data-report],.feed [data-details]')`));

  // ---- the Listen dialog ----
  { const lid = await js(`(REL.find(r=>pvSrc(r)&&coverOf(r)&&MU[r.id].tn)||{}).id`);
    await js(`(()=>{const q=document.querySelector('#q');q.value=BYID[${lid}].name;q.dispatchEvent(new Event('input',{bubbles:true}));})()`); await sleep(400);
    await js(`(()=>{const b=document.querySelector('[data-listen="${lid}"]');b.focus();b.click();})()`); await sleep(1200);
    ok('Listen opens a dialog "Listen" with the release name, the cover, the track title, the artist and a player that plays at once', await js(`(()=>{const d=document.querySelector('#modal .dlg'),a=d&&d.querySelector('audio');return !!d&&d.querySelector('h2').textContent==='Listen'&&d.querySelector('.dlghead p').textContent===BYID[${lid}].name&&!!d.querySelector('.lsn img')&&d.querySelector('.lsn .who').firstChild.textContent===MU[${lid}].tn&&!!a&&a.autoplay&&a.controls;})()`));
    ok('the preview really plays (30 s, not paused)', await js(`(()=>{const a=document.querySelector('#modal audio');return !!a&&!a.paused&&a.currentTime>0&&Math.round(a.duration)>=25;})()`));
    await key('Escape'); await sleep(200);
    ok('Escape closes the dialog and the sound stops (the player is gone)', await js(`!document.querySelector('#modal audio')&&document.activeElement===document.querySelector('[data-listen="${lid}"]')`));
    await js(`document.querySelector('[data-clearq]').click()`); await sleep(200);
    const miss = await js(`(REL.find(r=>pvOf(r)&&!pvSrc(r))||{}).id`);
    if (miss) { await go('#/release/' + miss); await sleep(300); await js(`document.querySelector('.dhead [data-listen],.showhead [data-listen]').click()`); await sleep(300);
      ok('a preview the prototype did not copy says so in plain words (no broken player)', await js(`/This prototype holds only \\d+ of the [\\d,]+ previews/.test(document.querySelector('#modal').textContent)&&!document.querySelector('#modal audio')`)); await key('Escape'); await sleep(100); } }
  await go('#/'); await sleep(200);

  // ---- filters ----
  ok('Category lists the sub-categories present, in the site order (no Audiobook or Podcast on prod)', await js(`CATS.join('|')`) === FX.cats.join('|'), await js(`CATS.join('|')`));
  await open('cat');
  ok('Category menu: "Exclude Other" shown (Other and other sub-categories listed)', await js(`!!document.querySelector('[data-ddexo]')`));
  await js(`document.querySelector('[data-ddexo]').click()`);
  ok('Exclude Other ticks every category but Other; cell reads "Exclude Other"', await js(`isExo()`) && await js(`document.querySelector('[data-msel=cat] .v').textContent`) === 'Exclude Other' && await every(`r.cat!=='Other'`));
  await js(`document.querySelector('[data-ddexo]').click()`); await pick('cat', 'MP3'); await pick('cat', 'Lossless');
  ok('two ticked: cell reads "2 chosen", rows in either (OR within a menu)', await js(`document.querySelector('[data-msel=cat] .v').textContent`) === '2 chosen' && await every(`r.cat==='MP3'||r.cat==='Lossless'`));
  await close(); await js(`document.querySelector('[data-clearall]').click()`); await sleep(100);
  ok('Clear all empties the filters and hides in place', await count() === TOTAL && await js(`document.querySelector('[data-clearall]').getAttribute('aria-hidden')==='true'`));
  await radio('comp', '100');
  ok('Completion 100% only: one choice, menu closes, every row 100%', await js(`state.dd===null`) && await js(`document.querySelector('[data-msel=comp] .v').textContent`) === '100%' && await every(`r.comp>=100`));
  await radio('comp', 'any');
  ok('nothing shifts when a filter is set: the pager line and table keep their place', await (async () => { const a = await rect('.feed'), p = await rect('.pager.slim'); await radio('comp', '95'); const b = await rect('.feed'), q = await rect('.pager.slim'); await radio('comp', 'any'); return a && b && a[1] === b[1] && p[1] === q[1]; })());
  ok('a filter change returns to page 1', (await go('#/p/2'), await radio('comp', '95'), await js(`state.page===1&&(location.hash==='#/'||location.hash==='')`)));
  await radio('comp', 'any');
  ok('Escape closes an open menu and returns focus to its cell', (await open('cat'), await key('Escape'), await sleep(100), await js(`state.dd===null&&document.activeElement===document.querySelector('[data-ddtoggle=cat]')`)));
  ok('a click outside closes an open menu', (await open('cat'), await close(), await sleep(100), await js(`state.dd===null`)));

  ok('Genre menu: the tags\' genres A to Z (case-insensitive), then Unknown', await js(`GENRES.join('|')`) === await js(`[...new Set(REL.flatMap(genOf))].filter(g=>g!=='Unknown').sort((a,b)=>a.localeCompare(b,undefined,{sensitivity:'base'})).concat(['Unknown']).join('|')`));
  ok('a multi-value genre tag ("Rock; Pop") counts as several genres, never one joined option', await js(`!GENRES.some(g=>/;/.test(g))`));
  ok('Genre menu searches inside itself (over 10 genres)', (await open('genre'), await js(`GENRES.length>10&&!!document.querySelector('[data-msel=genre] .msearch')`)));
  await js(`(()=>{const i=document.querySelector('[data-msearch=genre]');i.value='rock';i.dispatchEvent(new Event('input',{bubbles:true}));})()`); await sleep(100);
  ok('typing in the Genre search hides options without the word', await js(`[...document.querySelectorAll('[data-ddpick=genre]')].every(b=>b.hidden===!/rock/i.test(b.textContent))`));
  await pick('genre', 'Rock');
  ok('ticking Rock keeps every release tagged Rock; cell reads it', await every(`genOf(r).includes('Rock')`) && await count() === await js(`REL.filter(r=>genOf(r).includes('Rock')).length`) && await js(`document.querySelector('[data-msel=genre] .v').textContent`) === 'Rock');
  await pick('genre', 'Pop');
  ok('two genres OR together: "2 chosen"', await every(`genOf(r).some(g=>g==='Rock'||g==='Pop')`) && await js(`document.querySelector('[data-msel=genre] .v').textContent`) === '2 chosen');
  await pick('genre', 'Any'); await pick('genre', 'Unknown');
  ok('Unknown keeps the releases with no genre tag (and a tag that says Unknown)', await every(`!genOf(r).length||genOf(r).includes('Unknown')`) && await count() === await js(`REL.filter(r=>!genOf(r).length||genOf(r).includes('Unknown')).length`), String(await count())+' vs '+String(await js(`REL.filter(r=>!genOf(r).length).length`))+' set '+String(await js(`[...state.genre].join('|')`)));
  await close(); await js(`document.querySelector('[data-clearall]').click()`); await sleep(100);
  const yopen = () => js(`(()=>{if(state.dd!=='year')document.querySelector('[data-ddtoggle=year]').click();return state.dd==='year';})()`);
  await yopen();
  ok('Year menu: Any year, decades 2020s back to the 1940s, then a From–To range', await js(`[...document.querySelectorAll('[data-ypick]')].map(b=>b.textContent.trim()).join('|')`) === 'Any year|2020s|2010s|2000s|1990s|1980s|1970s|1960s|1950s|1940s' && await js(`!!document.querySelector('[data-yform] #yfrom')`));
  await js(`document.querySelector('[data-ypick="decade:1980"]').click()`);
  ok('1980s keeps releases whose tags give 1980–1989; releases with no year drop out', await every(`+gameYear(r)>=1980&&+gameYear(r)<=1989`) && await count() === await js(`REL.filter(r=>+gameYear(r)>=1980&&+gameYear(r)<1990).length`));
  await js(`document.querySelector('[data-ypick="any:0"]').click()`);
  await js(`(()=>{const a=document.querySelector('#yfrom'),b=document.querySelector('#yto');a.value='1950';a.dispatchEvent(new Event('input',{bubbles:true}));b.value='1959';b.dispatchEvent(new Event('input',{bubbles:true}));document.querySelector('[data-yform] button').click();})()`); await sleep(150);
  ok('a typed range works (1950–1959)', await js(`document.querySelector('[data-msel=year] .v').textContent`) === '1950–1959' && await every(`+gameYear(r)>=1950&&+gameYear(r)<=1959`));
  await load();
  ok('the list remembers the Year menu (#881)', await js(`state.year&&state.year.kind==='range'&&state.year.a===1950`));
  await js(`document.querySelector('[data-clearall]').click()`); await sleep(100);
  await pick('cat', 'MP3'); await close(); await pick('genre', 'Metal'); await close(); await radio('comp', '95'); await js(`document.querySelector('#q').value='zz';document.querySelector('#q').dispatchEvent(new Event('input',{bubbles:true}))`); await sleep(400);
  await load();
  ok('the list remembers the dropdown filters, not the name search', await js(`state.cat.has('MP3')&&state.genre.has('Metal')&&state.comp==='95'&&state.q===''`));
  await js(`document.querySelector('[data-clearall]').click()`); await sleep(100); await load();
  ok('Clear all clears the remembered filters too', await js(`!state.comp&&!state.cat.size&&!state.genre.size`));

  // ---- name search ----
  const art = await js(`(()=>{const r=REL.find(r=>artistOf(r).length>5&&!r.name.toLowerCase().includes(artistOf(r).toLowerCase()));return r?artistOf(r):null;})()`);
  if (art) { await js(`(()=>{const q=document.querySelector('#q');q.focus();q.value=${JSON.stringify(art)};q.dispatchEvent(new Event('input',{bubbles:true}));})()`); await sleep(400);
    ok(`an artist found only in the tags ("${art}") finds that release; focus stays in the field`, await count() === await js(`REL.filter(r=>r.name.toLowerCase().includes(${JSON.stringify(art.toLowerCase())})||artistOf(r).toLowerCase().includes(${JSON.stringify(art.toLowerCase())})||albumOf(r).toLowerCase().includes(${JSON.stringify(art.toLowerCase())})).length`) && await count() > 0 && await js(`document.activeElement.id==='q'`)); }
  ok('"No releases match release names, artists or albums containing …" for a word that matches nothing', (await js(`document.querySelector('#q').value='qqqqqqzzzz';document.querySelector('#q').dispatchEvent(new Event('input',{bubbles:true}))`), await sleep(400), await js(`document.querySelector('.pager.slim .sum').textContent==='Showing 0 releases'&&/^No releases match release names, artists or albums containing/.test(document.querySelector('.empty')?.textContent||'')`)));
  ok('the × empties the name search', (await js(`document.querySelector('[data-clearq]').click()`), await sleep(100), await js(`state.q===''`) && await count() === TOTAL));

  // ---- pagination, selection ----
  const pages = Math.ceil(TOTAL / 50);
  ok('page in the URL, numbered bottom pager with Go to page, no load-more', (await go('#/p/2'), await js(`state.page===2&&!!document.querySelector('.pager.bottom')&&!!document.querySelector('[data-goto]')&&!document.querySelector('[data-more]')`)));
  ok('the last page holds the remainder', (await go('#/p/' + pages), await js(`document.querySelectorAll('.feed tbody tr').length===${TOTAL - 50 * (pages - 1)}`))); await go('#/');
  ok('select-all shows the floating bar with the count; Clear selection removes it', (await js(`document.querySelector('[data-selectall]').click()`), await sleep(100), await js(`document.querySelector('.bulk span').textContent==='50 selected'`)) && (await js(`document.querySelector('[data-bulk=clear]').click()`), await sleep(100), await js(`!document.querySelector('.bulk')`)));
  ok('Copy NZB link copies the v1 t=get URL with the API key', (await js(`document.querySelector('.feed [data-copynzb]').click()`), await sleep(200), await js(`/\\/api\\/v1\\/api\\?t=get&id=.+&apikey=/.test(window.__copied||'')`)));

  // ---- album release page ----
  const AL = FX.album;
  await go('#/release/' + AL); await sleep(400);
  ok('album page: breadcrumb "Audio releases › <sub-category>"; square 200 px cover; the RELEASE name as the 24 px heading; "Artist – Album · Year · Category" under it', await js(`(()=>{const r=BYID[${AL}],m=MU[${AL}],c=document.querySelector('.crumbs'),a=document.querySelector('.showhead.relpage .art').getBoundingClientRect(),h=document.querySelector('.showhead.relpage h1');return c.textContent.replace(/\\s+/g,'')===('Audio releases›'+r.cat).replace(/\\s+/g,'')&&Math.round(a.width)===200&&Math.round(a.height)===200&&h.textContent===r.name&&getComputedStyle(h).fontSize==='24px'&&document.querySelector('.showhead .gline').textContent.replace(/\\s+/g,'')===(m.a+'–'+m.al+'·'+m.y+'·'+r.cat).replace(/\\s+/g,'');})()`));
  ok('album page: no section heading over the tabs; the tabs follow the header', await js(`![...document.querySelectorAll('h2')].some(h=>/this release|about the/i.test(h.textContent))&&!!document.querySelector('.mdet > .dcols [role=tablist]')`));
  ok('album page: genre tags are links; a format tag only when it adds to the category', await js(`(()=>{const t=document.querySelector('.showhead .tags'),r=BYID[${AL}],f=fmtOf(r);return [...t.querySelectorAll('a.tag[data-setgenre]')].map(a=>a.textContent).join('|')===genOf(r).join('|')&&[...t.querySelectorAll('.tag.plain')].map(x=>x.textContent).join('|')===(f&&f!==r.cat&&!(miSummary(r)||'').startsWith(f)?f:'');})()`));
  ok('album page: "Tracks N · length" info line when a track list is stored', await js(`(()=>{const l=[...document.querySelectorAll('.showhead .starring')].find(x=>x.firstChild.textContent.trim()==='Tracks');return !!l&&l.querySelector('.v').textContent.startsWith(String(tracksOf(BYID[${AL}]).length));})()`));
  ok('album page: one button row Download NZB, Copy NZB link, Add to cart (+ MusicBrainz only with an accepted identity)', await js(`[...document.querySelectorAll('.showhead .dacts > *')].map(x=>x.tagName==='A'?x.firstChild.textContent:x.innerText.trim()).join('|')`) === 'Download NZB|Copy NZB link|Add to cart' + (await js(`!!mbOf(BYID[${AL}])`) ? '|MusicBrainz' : ''));
  ok('tabs: Overview, Tracks (N), Files, Media info only with media info, NFO, Comments', await js(`[...document.querySelectorAll('[role=tab]')].map(t=>t.textContent.replace(/ \\(\\d+\\)/,'')).join('|')`) === 'Overview|Tracks|Files' + (await js(`!!RX.summ[${AL}]`) ? '|Media info' : '') + '|NFO|Comments');
  ok('Overview opens with the preview in the page: the track title, the browser\'s player directly above the spectrogram and as wide as it; no dialog, no Listen tile', await js(`(()=>{const b=document.querySelector('.panel .aupv'),a=b&&b.querySelector('audio'),i=b&&b.querySelector('[data-spec] img');if(!b||b!==document.querySelector('.panel').children[1]||!a||!i||b.querySelector('.lsnt'))return false;const ar=a.getBoundingClientRect(),ir=i.getBoundingClientRect();return a.controls&&!a.autoplay&&ar.bottom<=ir.top&&ir.top-ar.bottom<=12&&Math.abs(ar.width-ir.width)<2&&Math.round(ir.height)===220&&b.querySelector('.pvt b').textContent===MU[${AL}].tn&&b.querySelector('[data-spec] .lbl').textContent==='Spectrogram';})()`));
  ok('no Listen chip under the release title (the embedded player makes it redundant); the other chips stay', await js(`!document.querySelector('.showhead [data-listen],.dhead [data-listen]')&&!!document.querySelector('.showhead .rchips [data-mi]')`));
  ok('the spectrogram image loads', await js(`fetch(document.querySelector('[data-spec] img').src).then(r=>r.ok)`));
  await js(`document.querySelector('[data-spec]').click()`); await sleep(700);
  ok('the spectrogram opens in the image dialog with its size and Full size', await js(`(()=>{const d=document.querySelector('#modal .dlg');return !!d&&d.querySelector('h2').textContent==='Spectrogram'&&/^\\d+ × \\d+$/.test(d.querySelector('.pvdim').textContent);})()`));
  await key('Escape'); await sleep(100);
  ok('album facts: no Genre fact (the genres are in the tags)', await js(`![...document.querySelectorAll('.panel > .vals dt')].some(d=>d.textContent==='Genre')`));
  ok('"All N releases of this album" (or "The only release of this album") lists the album\'s releases, this one marked and not a link', await js(`(()=>{const s=document.querySelector('#sibs'),n=releasesOfGame(BYID[${AL}]).length,me=s&&s.querySelector('tr.me');return !!s&&s.querySelector('h2').textContent===(n>1?'All '+n+' releases of this album':'The only release of this album')&&s.querySelectorAll('tbody tr').length===n&&!!me&&!me.querySelector('a.rname');})()`));
  { const multi = await js(`(REL.find(r=>releasesOfGame(r).length>1)||{}).id`); if (multi) { await go('#/release/' + multi); await sleep(300);
    ok('an album with several releases: "All N releases of this album"; Similar leaves the album\'s releases out', await js(`document.querySelector('#sibs h2').textContent==='All '+releasesOfGame(BYID[${multi}]).length+' releases of this album'&&[...document.querySelectorAll('.simrel tbody [data-nzb]')].every(b=>gameKey(BYID[+b.dataset.nzb])!==gameKey(BYID[${multi}]))`)); } }
  await go('#/release/' + AL); await sleep(300);
  await js(`document.querySelector('[data-tab=tracks]').click()`); await sleep(200);
  ok('Tracks tab: #, Title, and Length only when lengths are stored (total length above it); the title never repeats the number column', await js(`(()=>{const t=tracksOf(BYID[${AL}]),hasLen=t.some(x=>x[3]!=null),rows=[...document.querySelectorAll('.panel table.trk tbody tr:not(.disc)')];return !!document.querySelector('.trksum')===(hasLen&&t.every(x=>x[3]!=null))&&[...document.querySelectorAll('.panel table.trk th')].map(h=>h.textContent).join('|')===(hasLen?'#|Title|Length':'#|Title')&&rows.length===t.length&&rows.every(r=>{const n=r.children[0].textContent;return !new RegExp('^0*'+n+'\\\\b').test(r.children[1].textContent);});})()`));
  await js(`document.querySelector('.showhead a.tag[data-setgenre]')?.click()`); await sleep(500);
  ok('a genre tag opens the list on that genre alone', await js(`(location.hash==='#/'||location.hash==='')&&state.genre.size===1&&state.genre.has(genOf(BYID[${AL}])[0])&&!!document.querySelector('.feed')`));
  await js(`document.querySelector('[data-clearall]').click()`); await sleep(100);
  if (FX.mb) { await go('#/release/' + FX.mb); await sleep(300);
    ok('a release with an accepted MusicBrainz identity: a MusicBrainz button to its release group, new tab, noopener', await js(`(()=>{const a=[...document.querySelectorAll('.dacts a.ext')].find(a=>a.firstChild.textContent==='MusicBrainz');return !!a&&a.target==='_blank'&&/noopener/.test(a.rel)&&/^https:\\/\\/musicbrainz\\.org\\/release-group\\/[0-9a-f-]{36}$/.test(a.href);})()`)); }

  // ---- release with no album ----
  const NA = FX.noalbum;
  await go('#/release/' + NA); await sleep(300);
  ok('no album: the release-details form: breadcrumb, the release name as the heading, no cover, no music line', await js(`!!document.querySelector('.crumbs')&&document.querySelector('.dhead h1.relonly').textContent===BYID[${NA}].name&&!document.querySelector('.dhead .art,.showhead,.gline')`));
  ok('no album: facts Category, Genre (—), Size, Files, Completion, Posted, Added, Grabs, Group, Poster, Password status', await js(`[...document.querySelectorAll('.panel > .vals dt')].map(d=>d.textContent).join('|')`) === 'Category|Genre|Size|Files|Completion|Posted|Added|Grabs|Group|Poster|Password status');
  ok('details buttons: Download NZB (coral), Copy NZB link, Add to cart', await js(`[...document.querySelectorAll('.dacts .btn')].map(b=>b.innerText.trim()).join('|')`) === 'Download NZB|Copy NZB link|Add to cart');
  ok('Add to cart pressed reads "In cart", fills green, keeps its width', await (async () => { const w = (await rect('.dacts .ct'))[2]; await js(`document.querySelector('.dacts .ct').click()`); await sleep(100); const r = await js(`(()=>{const b=document.querySelector('.dacts .ct'),d=document.querySelector('.dacts .btn:not(.sec)');return b.getAttribute('aria-pressed')==='true'&&b.textContent.includes('In cart')&&getComputedStyle(b).backgroundColor!==getComputedStyle(d).backgroundColor;})()`); const w2 = (await rect('.dacts .ct'))[2]; await js(`document.querySelector('.dacts .ct').click()`); return r && Math.abs(w - w2) < 1; })());
  { const nt = await js(`(REL.find(r=>!tracksOf(r).length&&!RX.summ[r.id])||{}).id`); await go('#/release/' + nt); await sleep(300);
    ok('no track list, no media info: no Tracks tab, no Media info tab', await js(`[...document.querySelectorAll('[role=tab]')].map(t=>t.textContent.replace(/ \\(\\d+\\)/,'')).join('|')`) === 'Overview|Files|NFO|Comments'); }
  if (FX.files) { await go('#/release/' + FX.files); await js(`document.querySelector('[data-tab=files]').click()`); await sleep(500); ok('Files tab lists the stored file names and sizes', await js(`document.querySelectorAll('.panel .filelist tbody tr').length>0`)); }
  if (FX.nfo) { await go('#/release/' + FX.nfo); await js(`document.querySelector('[data-tab=nfo]').click()`); await sleep(500); ok('NFO tab shows the NFO text in monospace', await js(`!!document.querySelector('.panel .nfo')`)); }
  if (FX.media) { await go('#/release/' + FX.media); await js(`document.querySelector('[data-tab=media]').click()`); await sleep(600); ok('Media info tab shows the media info', await js(`!!document.querySelector('.panel .mi2')`));
    ok('the media info audio table has Title (the track title tag) where Language was', await js(`(()=>{const h=[...document.querySelectorAll('.panel table.ha th')].map(x=>x.textContent);return h[1]==='Title'&&!h.includes('Language');})()`)); }
  if (FX.predb) { await go('#/release/' + FX.predb); await sleep(300); ok('PreDB block on Overview when the release has a match', await js(`!!document.querySelector('.predb')`)); }
  if (FX.similar) { await go('#/release/' + FX.similar); await sleep(300);
    ok('Similar releases: Release, Category, Size, Files, Posted and the buttons; newest first; at most 50', await js(`(()=>{const t=document.querySelector('.simrel table');if(!t)return false;const h=[...t.querySelectorAll('th')].map(x=>x.textContent.trim()).filter(Boolean).join(',');const ids=[...t.querySelectorAll('tbody [data-nzb]')].map(b=>+b.dataset.nzb),ts=ids.map(i=>BYID[i].t);return h==='Release,Category,Size,Files,Posted,Actions'&&ids.length>0&&ids.length<=50&&ts.every((v,i)=>!i||ts[i-1]>=v);})()`)); }
  ok('a release that is not in the prototype gets a plain message', (await go('#/release/1'), await js(`!!document.querySelector('.later')`)));

  // ---- themes ----
  ok('light theme: background and filter text change (no dark-only colours)', (await go('#/'), await js(`document.documentElement.dataset.theme='light';const bg=getComputedStyle(document.body).backgroundColor;const k=getComputedStyle(document.querySelector('.fbar .sfm .mbtn .k')).color;document.documentElement.dataset.theme='dark';bg!=='rgb(15, 16, 20)'&&k!=='rgb(255, 255, 255)'`)));
  ok('the audio player follows the theme (light controls in light)', await js(`document.documentElement.dataset.theme='light';const s=document.createElement('audio');s.className='aup';document.body.appendChild(s);const c=getComputedStyle(s).colorScheme;s.remove();document.documentElement.dataset.theme='dark';c==='light'`));
  ok('no "Watch" wording anywhere', await js(`!/\\bwatch(ing)?\\b/i.test(document.body.innerText)`));
  ok('no instruction text ("click to") on the screen', await js(`!/click to/i.test(document.body.innerText)`));
} catch (e) { results.push(['FAIL', PAGE + ': script aborted', String(e).split('\n')[0]]); } }

const fails = results.filter(r => r[0] === 'FAIL');
results.forEach(r => console.log(r[0], r[1], r[2] ? '· ' + r[2] : ''));
console.log(`\n${results.length} checks, ${fails.length} failures${errors.length ? '; page errors: ' + errors.join(' | ') : ''}`);
ws.close(); chrome.kill('SIGKILL'); process.exit(fails.length || errors.length ? 1 : 0);
