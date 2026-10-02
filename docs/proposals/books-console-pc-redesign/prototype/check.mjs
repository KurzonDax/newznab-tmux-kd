// Drives headless Chrome over CDP: clicks every control on books.html / console.html / pc.html (one page, three datasets) and reports pass/fail.
// One check per rule: the TV / Movies / Adult SPEC rules these sections inherit, and the Books / Console / PC decisions of 2026-10-01.
// node shcheck.mjs            all three pages      PAGE=pc node shcheck.mjs      one page      (V=1 prints progress on stderr)
import {spawn} from 'node:child_process';
import {mkdtempSync} from 'node:fs';
import {tmpdir} from 'node:os';
const BASE = process.env.SH_BASE || 'http://localhost:8766/', PORT = 9381;
const PAGES = process.env.PAGE ? [process.env.PAGE] : ['books', 'console', 'pc'];
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', ['--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(tmpdir() + '/shcdp-')}`, 'about:blank'], {stdio: 'ignore'});
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

await send('Runtime.enable'); await send('Page.enable'); await send('Emulation.setFocusEmulationEnabled', {enabled: true});
await send('Emulation.setDeviceMetricsOverride', {width: 1600, height: 1000, deviceScaleFactor: 1, mobile: false});

for (PAGE of PAGES) { try {
  await load(); await js('localStorage.clear()'); await load();
  const FX = await js(`fetch(DIR+'fixtures.json').then(r=>r.json())`);
  const TOTAL = await js('REL.length');
  const HEAD = {books: 'Book releases', console: 'Console releases', pc: 'PC releases'}[PAGE];
  const GEN = PAGE === 'console'; // Console alone has the game's Genre (invented data, cn/genres.json)

  // ---- the screen ----
  ok(`heading "${HEAD}", no Releases / wall switch`, await js(`document.querySelector('.filters h1').textContent`) === HEAD && await js(`!document.querySelector('.filters .seg')`));
  ok('header bar: Movies, TV, Audio, Books, Console, PC, Adult, Other, All (#908 order); this section marked current', await js(`[...document.querySelectorAll('.nav a.l')].map(a=>a.textContent).join(',')`) === 'Movies,TV,Audio,Books,Console,PC,Adult,Other,All' && await js(`document.querySelector('.nav a.l.on').textContent`) === {books: 'Books', console: 'Console', pc: 'PC'}[PAGE]);
  ok('release bar: Category, Completion' + (GEN ? ', then the game panel: Genre, Year' : '') + '; no Password filter (his call: the site setting hides passworded releases)', await js(`[...document.querySelectorAll('.fbar .mbtn .k')].map(k=>k.textContent).join(',')`) === (GEN ? 'Category,Completion,Genre,Year' : 'Category,Completion'));
  ok('every cell is as wide as a Movies release bar cell', await js(`(()=>{const w=document.querySelector('.filters').getBoundingClientRect().width,movies=((w-12)/2-8)/5,c=[...document.querySelectorAll('.fbar .sfm')].map(e=>e.getBoundingClientRect().width);return c.length===${GEN ? 4 : 2}&&c.every(v=>Math.abs(v-movies)<1.5);})()`));
  ok(GEN ? 'Genre sits in its own panel ("The game") after the release panel, as Movies puts the film after the release' : 'no Genre filter (no filters beyond these on Books, his call 2026-10-01; none on PC)', GEN ? await js(`(()=>{const g=document.querySelectorAll('.fbar .fseg');return g.length===2&&g[1].getAttribute('aria-label')==='The game'&&!!g[1].querySelector('[data-msel=genre]')&&!!g[1].querySelector('[data-msel=year]');})()`) : await js(`!document.querySelector('[data-msel=genre]')`));
  ok('name search field "' + (GEN ? 'Search releases or games' : 'Search release names') + '" beside the heading', await js(`document.querySelector('.filters h1').nextElementSibling.querySelector('input').placeholder`) === (GEN ? 'Search releases or games' : 'Search release names'));
  ok('four sorts, Posted: newest first by default', await js(`[...document.querySelectorAll('[data-rsort] option')].map(o=>o.textContent).join('|')`) === 'Posted: newest first|Posted: oldest first|Added: newest first|Added: oldest first' && await js(`document.querySelector('[data-rsort]').value`) === 'posted_desc');
  ok('pager line shows every release of the band (prod count)', await count() === TOTAL && TOTAL === FX.total, String(TOTAL));
  ok('at most 50 releases a page, newest posted first', await js(`(()=>{const ids=${ROWIDS},t=ids.map(i=>BYID[i].t);return ids.length===Math.min(50,REL.length)&&t.every((v,i)=>!i||t[i-1]>=v);})()`));
  ok('columns: Release, Category, ' + (GEN ? 'Genre, ' : '') + 'Size, Posted' + (GEN ? ', a cover column under Release' : '; no picture column') + '; no Resolution, Source, Files or Grabs column', await js(`[...document.querySelectorAll('.feed thead th')].map(t=>t.textContent.trim()).filter(Boolean).join(',')`) === (GEN ? 'Release,Category,Genre,Size,Posted,Actions' : 'Release,Category,Size,Posted,Actions') && await js(`!!document.querySelector('.feed td.art')`) === GEN);
  ok('fixed column widths 34 · ' + (GEN ? '116 · ' : '') + 'auto · 128 · ' + (GEN ? '150 · ' : '') + '82 · 112 · 96', await js(`[...document.querySelectorAll('.feed colgroup col')].map(c=>c.style.width||'auto').join(',')`) === (GEN ? '34px,116px,auto,128px,150px,82px,112px,96px' : '34px,auto,128px,82px,112px,96px'));
  if (GEN) {
    ok('cover column: every release with a game shows its cover (88 × 132, the Movies poster slot) linking to its details; one without shows "No cover"', await every(`(()=>{const a=document.querySelector('.feed tbody tr:has([data-nzb="'+id+'"]) td.art a'),img=a.querySelector('img');return a.getAttribute('href')==='#/release/'+id&&(ART[id]?!!img&&img.getAttribute('src')===DIR+'covers/'+id+'.webp':!img&&a.textContent.trim()==='No cover');})()`) && await js(`(()=>{const a=document.querySelector('.feed td.art a').getBoundingClientRect();return Math.round(a.width)===88&&Math.round(a.height)===132;})()`));
    ok('list: the game\'s name sits under the release name as plain text (the Movies film line); none when the release has no game', await every(`(()=>{const l=document.querySelector('.feed tbody tr:has([data-nzb="'+id+'"]) td.what .gameline');return ART[id]?!!l&&l.textContent===ART[id].t+(ART[id].y?' · '+ART[id].y:'')&&l.tagName==='SPAN'&&l.previousElementSibling.classList.contains('rname'):!l;})()`));
    ok('every cover image loads', await js(`Promise.all([...document.querySelectorAll('.feed td.art img')].map(i=>fetch(i.src).then(r=>r.ok&&/image/.test(r.headers.get('content-type')||''),()=>false))).then(a=>a.length>0&&a.every(Boolean))`));
    ok('Genre column: the release\'s genres in IGDB order joined by commas, "—" when it has none', await every(`document.querySelector('.feed tbody tr:has([data-nzb="'+id+'"]) td.c-gen').textContent===(genOf(r).join(', ')||'—')`));
    ok('Genre column holds at most four lines, full list on hover; its heading lines up with its text', await js(`[...document.querySelectorAll('.feed td.c-gen span')].every(s=>s.scrollHeight<=s.clientHeight&&s.getBoundingClientRect().height<=parseFloat(getComputedStyle(s).lineHeight)*4+1)`) && await js(`(()=>{const h=document.querySelector('.feed th.h-gen').getBoundingClientRect().left+12,t=document.querySelector('.feed td.c-gen span').getBoundingClientRect().left;return Math.abs(h-t)<1;})()`));
  }
  ok('only the release name is bold in a row', await js(`(()=>{const tr=document.querySelector('.feed tbody tr');return [...tr.querySelectorAll('*')].filter(e=>getComputedStyle(e).fontWeight>=700&&e.textContent.trim()).every(e=>e.classList.contains('rname'));})()`));
  ok('the Category column is never cut short',await js(`[...document.querySelectorAll('.feed tbody td.c-cat')].every(td=>td.scrollWidth<=td.clientWidth)`));
  ok('the Category column names the sub-category of each row', await every(`document.querySelector('.feed tbody tr:has([data-nzb="'+id+'"]) td.c-cat').textContent===r.cat`));
  ok('no Preview, Sample or Clip chip (no artwork in this first mockup)', await js(`!document.querySelector('.feed .k-pv,.feed .k-sm,.feed .k-cl')`));
  ok('row buttons 2 × 2: Download, Copy link, Cart; no Follow (no title entity)', await js(`(()=>{const a=document.querySelector('.feed .iconacts.stack');return a.querySelectorAll('.ia:not(.slot)').length===3&&!!a.querySelector('.dl')&&!!a.querySelector('[data-copynzb]')&&!!a.querySelector('[data-cart]')&&!a.querySelector('[data-watch]');})()`));
  ok('row buttons: Download coral, Copy link blue, Cart green (tinted, not coral)', await js(`(()=>{const a=document.querySelector('.feed .iconacts.stack'),c=s=>getComputedStyle(a.querySelector(s)).backgroundColor;return c('.dl')!==c('[data-copynzb]')&&c('[data-copynzb]')!==c('[data-cart]')&&c('.dl')!==c('[data-cart]');})()`));
  ok('group + poster outline chips are one unit, same-tab app links', await js(`(()=>{const o=document.querySelector('.feed .rchips .orig');return !!o&&[...o.querySelectorAll('a')].every(a=>a.classList.contains('origin')&&!a.target&&/^\\/browse\\/all\\?(group|poster)=/.test(a.dataset.applink));})()`));
  ok('no Report button, no details button in rows', await js(`!document.querySelector('.feed [data-report],.feed [data-details]')`));

  // ---- filters ----
  ok('Category lists the sub-categories present, in the site order', await js(`CATS.join('|')`) === FX.cats.join('|'), await js(`CATS.join('|')`));
  const hasOther = FX.cats.includes('Other') && FX.cats.length > 1;
  await open('cat');
  ok('Category menu: "Exclude Other" shown only with Other and at least one other category (' + (hasOther ? 'shown' : 'hidden') + ')', await js(`!!document.querySelector('[data-ddexo]')`) === hasOther);
  ok('Category menu searches inside itself only when over 10 options', await js(`!!document.querySelector('[data-msel=cat] .msearch')`) === (FX.cats.length > 10));
  const c0 = FX.cats[0];
  await pick('cat', c0);
  const n0 = await count();
  ok(`ticking ${c0} narrows to that sub-category; menu stays open; cell reads it with a coral line`, n0 > 0 && n0 < TOTAL && await js(`state.dd==='cat'`) && await js(`document.querySelector('[data-msel=cat] .v').textContent`) === c0 && await js(`document.querySelector('[data-msel=cat]').classList.contains('set')`));
  ok('every row shown is in that sub-category', await every(`r.cat==='${c0}'`));
  if (FX.cats.length > 1) { await pick('cat', FX.cats[1]); ok('two ticked: cell reads "2 chosen", counts add (OR within a menu)', await js(`document.querySelector('[data-msel=cat] .v').textContent`) === '2 chosen' && await count() > n0); }
  if (hasOther) { await open('cat'); await js(`document.querySelector('[data-ddexo]').click()`); ok('Exclude Other ticks every category but Other; cell reads "Exclude Other"', await js(`isExo()`) && await js(`document.querySelector('[data-msel=cat] .v').textContent`) === 'Exclude Other' && await every(`r.cat!=='Other'`)); await js(`document.querySelector('[data-ddexo]').click()`); ok('Exclude Other again clears the category filter', await js(`state.cat.size===0`)); }
  await close(); await js(`document.querySelector('[data-clearall]').click()`); await sleep(100);
  ok('Clear all empties the filters and hides in place', await count() === TOTAL && await js(`document.querySelector('[data-clearall]').getAttribute('aria-hidden')==='true'`));
  await radio('comp', '100');
  ok('Completion 100% only: one choice, menu closes, cell reads 100%, every row 100%', await js(`state.dd===null`) && await js(`document.querySelector('[data-msel=comp] .v').textContent`) === '100%' && await every(`r.comp>=100`));
  await radio('comp', '95');
  ok('Completion 95% or more: cell reads 95%+', await js(`document.querySelector('[data-msel=comp] .v').textContent`) === '95%+' && await every(`r.comp>=95`));
  await radio('comp', 'any');
  ok('Completion menu items: Any completion, 100% only, 95% or more', (await open('comp'), await js(`[...document.querySelectorAll('[data-rpick]')].map(b=>b.textContent).join('|')`)) === 'Any completion|100% only|95% or more');
  await radio('comp', 'any');
  ok('combined filters AND between menus', (await radio('comp', '100'), await pick('cat', c0), await close(), await every(`r.comp>=100&&r.cat==='${c0}'`)));
  await js(`document.querySelector('[data-clearall]').click()`); await sleep(100);
  ok('nothing shifts when a filter is set: the pager line and table keep their place', await (async () => { const a = await rect('.feed'), p = await rect('.pager.slim'); await radio('comp', '95'); const b = await rect('.feed'), q = await rect('.pager.slim'); await radio('comp', 'any'); return a && b && a[1] === b[1] && p[1] === q[1]; })());
  ok('a filter change returns to page 1', (TOTAL > 50 ? (await go('#/p/2'), await radio('comp', '95'), await js(`state.page===1&&(location.hash==='#/'||location.hash==='')`)) : true));
  await radio('comp', 'any');
  ok('Escape closes an open menu and returns focus to its cell', (await open('cat'), await key('Escape'), await sleep(100), await js(`state.dd===null&&document.activeElement===document.querySelector('[data-ddtoggle=cat]')`)));
  ok('a click outside closes an open menu', (await open('cat'), await close(), await sleep(100), await js(`state.dd===null`)));

  if (GEN) {
    ok('Genre menu: the genres the releases have, A to Z, then Unknown', await js(`GENRES.join('|')`) === await js(`[...new Set(REL.flatMap(genOf))].sort().concat(REL.some(r=>!genOf(r).length)?['Unknown']:[]).join('|')`));
    ok('every Genre menu option sits on one line', (await open('genre'), await js(`(()=>{const b=[...document.querySelectorAll('[data-ddpick=genre]')],h=b.map(x=>x.getBoundingClientRect().height);return b.length>0&&h.every(v=>Math.abs(v-h[0])<1);})()`)));
    ok('Genre menu searches inside itself (over 10 genres)', (await open('genre'), await js(`GENRES.length>10&&!!document.querySelector('[data-msel=genre] .msearch')`)));
    await pick('genre', 'Fighting');
    ok('ticking Fighting keeps every release with Fighting among its genres; cell reads it', await every(`genOf(r).includes('Fighting')`) && await count() === await js(`REL.filter(r=>genOf(r).includes('Fighting')).length`) && await js(`document.querySelector('[data-msel=genre] .v').textContent`) === 'Fighting');
    await pick('genre', 'Adventure');
    ok('two genres OR together: cell reads "2 chosen"', await every(`genOf(r).some(g=>g==='Fighting'||g==='Adventure')`) && await js(`document.querySelector('[data-msel=genre] .v').textContent`) === '2 chosen');
    await pick('genre', 'Any'); await pick('genre', 'Unknown');
    ok('Unknown keeps the releases with no genre', await every(`!genOf(r).length`) && await count() === await js(`REL.filter(r=>!genOf(r).length).length`));
    await close(); await js(`document.querySelector('[data-clearall]').click()`); await sleep(100);
    ok('Clear all empties the Genre menu too', await js(`!state.genre.size`) && await count() === TOTAL);
    await pick('genre', 'Music'); await close(); await load();
    ok('the list remembers the Genre menu', await js(`state.genre.has('Music')`));
    await js(`document.querySelector('[data-clearall]').click()`); await sleep(100);
  }

  if (GEN) {
    const yopen = () => js(`(()=>{if(state.dd!=='year')document.querySelector('[data-ddtoggle=year]').click();return state.dd==='year';})()`);
    await yopen();
    ok('Year menu: Any year, then decades 2020s back to the 1990s only (his call), then a From–To range', await js(`[...document.querySelectorAll('[data-ypick]')].map(b=>b.textContent.trim()).join('|')`) === 'Any year|2020s|2010s|2000s|1990s' && await js(`!!document.querySelector('[data-yform] #yfrom')&&!!document.querySelector('[data-yform] #yto')`));
    await js(`document.querySelector('[data-ypick="decade:2010"]').click()`);
    ok('ticking 2010s keeps the releases whose game is from 2010–2019; cell reads 2010s; releases with no game drop out', await every(`+gameYear(r)>=2010&&+gameYear(r)<=2019`) && await count() === await js(`REL.filter(r=>+gameYear(r)>=2010&&+gameYear(r)<2020).length`) && await js(`document.querySelector('[data-msel=year] .v').textContent`) === '2010s');
    await js(`document.querySelector('[data-ypick="decade:2000"]').click()`);
    ok('two decades OR together; cell reads "2 chosen"', await every(`+gameYear(r)>=2000&&+gameYear(r)<=2019`) && await js(`document.querySelector('[data-msel=year] .v').textContent`) === '2 chosen');
    await js(`document.querySelector('[data-ypick="any:0"]').click()`);
    await js(`(()=>{const a=document.querySelector('#yfrom'),b=document.querySelector('#yto');a.value='2015';a.dispatchEvent(new Event('input',{bubbles:true}));b.value='2017';b.dispatchEvent(new Event('input',{bubbles:true}));document.querySelector('[data-yform] button').click();})()`); await sleep(150);
    ok('typed range 2015–2017: menu closes, cell reads 2015–2017, every row in range', await js(`state.dd===null`) && await js(`document.querySelector('[data-msel=year] .v').textContent`) === '2015–2017' && await every(`+gameYear(r)>=2015&&+gameYear(r)<=2017`));
    await yopen(); await js(`(()=>{const a=document.querySelector('#yfrom'),b=document.querySelector('#yto');a.value='2020';a.dispatchEvent(new Event('input',{bubbles:true}));b.value='2010';b.dispatchEvent(new Event('input',{bubbles:true}));document.querySelector('[data-yform] button').click();})()`); await sleep(150);
    ok('a backwards range shows the Movies error and keeps the menu open', await js(`state.dd==='year'&&/Years run 1900–2026, earliest first/.test(document.querySelector('.ymenu .yerr')?.textContent||'')`));
    await key('Escape'); await sleep(100);
    await load();
    ok('the list remembers the Year menu', await js(`state.year&&state.year.kind==='range'&&state.year.a===2015`));
    await js(`document.querySelector('[data-clearall]').click()`); await sleep(100);
    ok('Clear all empties the Year menu too', await js(`!state.year`) && await count() === TOTAL);
  }

  // ---- remembered filters (#881) ----
  await pick('cat', c0); await close(); await radio('comp', '95'); await js(`document.querySelector('#q').value='zz';document.querySelector('#q').dispatchEvent(new Event('input',{bubbles:true}))`); await sleep(400);
  await load();
  ok('the list remembers the dropdown filters (profile stand-in), not the name search', await js(`state.cat.has('${c0}')&&state.comp==='95'&&state.q===''`));
  await js(`document.querySelector('[data-clearall]').click()`); await sleep(100); await load();
  ok('Clear all clears the remembered filters too', await js(`!state.comp&&!state.cat.size`));

  // ---- name search ----
  const word = (await js(`(REL[0].name.match(/[A-Za-z]{4,}/)||['Play'])[0]`)) || 'Play';
  await js(`document.querySelector('#q').focus();document.querySelector('#q').value=${JSON.stringify(word)};document.querySelector('#q').dispatchEvent(new Event('input',{bubbles:true}))`); await sleep(400);
  ok('name search narrows to names containing the words' + (GEN ? ' (release or game name)' : '') + ', keeps focus in the field, shows the × button', await every(`r.name.toLowerCase().includes(${JSON.stringify(word.toLowerCase())})||(typeof gameOf==='function'&&(gameOf(r)||'').toLowerCase().includes(${JSON.stringify(word.toLowerCase())}))`) && await js(`document.activeElement.id==='q'&&!document.querySelector('[data-clearq]').hidden`));
  if (GEN) { await js(`(()=>{const q=document.querySelector('#q');q.value='Blades';q.dispatchEvent(new Event('input',{bubbles:true}));})()`); await sleep(400);
    ok('Console: a word found only in a game name finds that game\'s releases ("Blades" → Blades of Varn, whose release name is scrambled)', await count() === await js(`REL.filter(r=>/blades/i.test(r.name)||/blades/i.test(gameOf(r)||'')).length`) && await every(`/blades/i.test(gameOf(r)||'')||/blades/i.test(r.name)`) && await js(`[...document.querySelectorAll('.feed .gameline')].some(l=>/^Blades of Varn/.test(l.textContent))`)); }
  ok('Showing line and "No releases match" for a word that matches nothing', (await js(`document.querySelector('#q').value='qqqqqqzzzz';document.querySelector('#q').dispatchEvent(new Event('input',{bubbles:true}))`), await sleep(400), await js(`document.querySelector('.pager.slim .sum').textContent==='Showing 0 releases'&&/^No releases match (release or game )?names containing/.test(document.querySelector('.empty')?.textContent||'')`)));
  ok('the × empties the name search', (await js(`document.querySelector('[data-clearq]').click()`), await sleep(100), await js(`state.q===''`) && await count() === TOTAL));
  ok('Escape in the field empties it', (await js(`const q=document.querySelector('#q');q.focus();q.value='abc';q.dispatchEvent(new Event('input',{bubbles:true}))`), await sleep(400), await key('Escape'), await sleep(100), await js(`state.q===''`)));

  // ---- pagination ----
  const pages = Math.ceil(TOTAL / 50);
  ok('page in the URL, numbered bottom pager with Go to page, no load-more', pages > 1 ? (await go('#/p/2'), await js(`state.page===2&&!!document.querySelector('.pager.bottom')&&!!document.querySelector('[data-goto]')&&!document.querySelector('[data-more]')`)) : await js(`!document.querySelector('.pager.bottom')&&document.querySelector('.pager.slim .pg').textContent==='Page 1 of 1'`));
  if (pages > 1) { ok('the last page holds the remainder', (await go('#/p/' + pages), await js(`document.querySelectorAll('.feed tbody tr').length===${TOTAL - 50 * (pages - 1)}`))); await go('#/'); }

  // ---- selection ----
  ok('selecting rows shows the floating bar with the count; select-all takes the page', (await js(`document.querySelector('[data-selectall]').click()`), await sleep(100), await js(`document.querySelector('.bulk span').textContent===Math.min(50,REL.length)+' selected'`)));
  ok('Clear selection removes the bar', (await js(`document.querySelector('[data-bulk=clear]').click()`), await sleep(100), await js(`!document.querySelector('.bulk')`)));
  ok('Cart toggles to its own green, never coral; the count in the header follows', (await js(`document.querySelector('.feed [data-cart]').click()`), await sleep(100), await js(`(()=>{const b=document.querySelector('.feed [data-cart]'),d=document.querySelector('.feed .dl');return b.getAttribute('aria-pressed')==='true'&&getComputedStyle(b).backgroundColor!==getComputedStyle(d).backgroundColor&&document.querySelector('#cartn').textContent==='1';})()`)));
  await js(`document.querySelector('.feed [data-cart]').click()`);
  ok('Copy NZB link copies the v1 t=get URL with the API key', (await js(`document.querySelector('.feed [data-copynzb]').click()`), await sleep(200), await js(`/\\/api\\/v1\\/api\\?t=get&id=.+&apikey=/.test(window.__copied||'')`)));

  // ---- dialogs from the list ----
  if (FX.nfo) { await go('#/'); await js(`document.querySelector('[data-nfo="${FX.nfo}"]')?.click()`); await sleep(600); ok('NFO chip opens the NFO dialog (needs the row on page 1)', await js(`!!document.querySelector('#modal .nfo')`) || !(await js(`!!document.querySelector('[data-nfo="${FX.nfo}"]')`))); await key('Escape'); await sleep(100); }
  if (FX.media) { await go('#/'); const on = await js(`!!document.querySelector('[data-mi="${FX.media}"]')`); if (on) { await js(`document.querySelector('[data-mi="${FX.media}"]').click()`); await sleep(400); ok('media info chip opens the Media information dialog', await js(`!!document.querySelector('#modal .mi2')`)); await key('Escape'); await sleep(100); } }

  // ---- details ----
  // Console: the shared details checks run on a release with no game (the release-details form); the game page has its own block below
  const did = GEN ? await js(`(REL.find(r=>!GAME[r.id]&&X(r).files)||REL.find(r=>!GAME[r.id])).id`) : (FX.files || FX.nfo || (await js('REL[0].id')));
  await go('#/release/' + did); await sleep(400);
  ok('details: breadcrumb "<section> › <sub-category>", the name as the heading, full width, ' + (GEN ? 'the About the game panel and a cover only when the release has a game' : 'no aside, no cover') + '', await js(`(()=>{const c=document.querySelector('.crumbs');return c.textContent.replace(/\\s+/g,'')==='${HEAD}›'.replace(/ /g,'')+BYID[${did}].cat.replace(/ /g,'')&&document.querySelector('.dhead h1').textContent===(typeof gameOf==='function'&&gameOf(BYID[${did}])?gameOf(BYID[${did}])+(gameYear(BYID[${did}])?' · '+gameYear(BYID[${did}]):''):BYID[${did}].name)&&!!document.querySelector('.dcols.noshow')===!(typeof gameOfR==='function'&&gameOfR(BYID[${did}]))&&!!document.querySelector('.dhead .art')===!!(typeof ART!=='undefined'&&ART[${did}]&&HASGEN);})()`));
  ok('details buttons: Download NZB (coral), Copy NZB link, Add to cart (neutral); no Follow', await js(`[...document.querySelectorAll('.dacts .btn')].map(b=>b.innerText.trim()).join('|')`) === 'Download NZB|Copy NZB link|Add to cart' && await js(`!document.querySelector('.dacts .wat')`));
  ok('Add to cart pressed reads "In cart", fills green, keeps its width', await (async () => { const w = (await rect('.dacts .ct'))[2]; await js(`document.querySelector('.dacts .ct').click()`); await sleep(100); const r = await js(`(()=>{const b=document.querySelector('.dacts .ct'),d=document.querySelector('.dacts .btn:not(.sec)');return b.getAttribute('aria-pressed')==='true'&&b.textContent.includes('In cart')&&getComputedStyle(b).backgroundColor!==getComputedStyle(d).backgroundColor;})()`); const w2 = (await rect('.dacts .ct'))[2]; await js(`document.querySelector('.dacts .ct').click()`); return r && Math.abs(w - w2) < 1; })());
  const tabsOf = () => js(`[...document.querySelectorAll('[role=tab]')].map(t=>t.textContent.replace(/ \\(\\d+\\)/,'')).join('|')`);
  ok('tabs: Overview, Files (N), NFO, Comments (N), with Media info only when the release has media info (his call 2026-10-01)', await tabsOf() === (await js(`!!RX.summ[${did}]`) ? 'Overview|Files|Media info|NFO|Comments' : 'Overview|Files|NFO|Comments'));
  { const none = await js(`(REL.find(r=>!RX.summ[r.id])||{}).id`); if (none) { await go('#/release/' + none); await sleep(300); ok('a release without media info has no Media info tab', await tabsOf() === 'Overview|Files|NFO|Comments'); } }
  if (FX.media) { await go('#/release/' + FX.media); await sleep(300); ok('a release with media info has the Media info tab, and it shows the media info', await tabsOf() === 'Overview|Files|Media info|NFO|Comments' && (await js(`document.querySelector('[data-tab=media]').click()`), await sleep(500), await js(`!!document.querySelector('.panel .mi2')`))); }
  await go('#/release/' + did); await sleep(300);
  ok('Overview facts: Category, ' + (GEN ? 'Genre, ' : '') + 'Size, Files, Completion, Posted, Added, Grabs, Group, Poster, Password status', await js(`[...document.querySelectorAll('.panel > .vals dt')].map(d=>d.textContent).join('|')`) === (GEN ? 'Category|Genre|' : 'Category|') + 'Size|Files|Completion|Posted|Added|Grabs|Group|Poster|Password status');
  if (GEN) ok('Genre fact reads the release\'s genres, "—" when none', await js(`document.querySelectorAll('.panel > .vals dd')[1].textContent===(genOf(BYID[${did}]).join(', ')||'—')`));
  ok('no preview or sample pictures on the details page', await js(`!document.querySelector('.adpics,.pvthumb')`));
  if (GEN) { const withC = await js(`+Object.keys(GAME).find(k=>GAME[k].story&&GAME[k].site)`), plainG = await js(`+Object.keys(GAME).find(k=>!GAME[k].story&&!GAME[k].site)`), without = await js(`(REL.find(r=>!GAME[r.id])||{}).id`);
    await go('#/release/' + withC); await sleep(300);
    ok('game page: a RELEASE page laid out like the film page: breadcrumb, 200 px cover on the left, the RELEASE name as the 24 px heading, the game name · year · platform under it', await js(`(()=>{const i=document.querySelector('.showhead.relpage .art img'),h=document.querySelector('.showhead.relpage h1');return !!document.querySelector('.crumbs')&&!!i&&i.getAttribute('src')===DIR+'covers/${withC}.webp'&&Math.round(document.querySelector('.showhead .art').getBoundingClientRect().width)===200&&h.textContent===BYID[${withC}].name&&getComputedStyle(h).fontSize==='24px'&&document.querySelector('.showhead .gline').textContent.replace(/\s+/g,'')===(ART[${withC}].t+'·'+ART[${withC}].y+'·'+BYID[${withC}].cat).replace(/\s+/g,'');})()`));
    ok('game page: no section heading over the tabs ("This release" is banned); the tabs follow the header', await js(`!document.querySelector('section.frel')&&![...document.querySelectorAll('h2')].some(h=>/this release/i.test(h.textContent))&&!!document.querySelector('.mdet > .dcols [role=tablist]')`));
    ok('game page: the release chips, then the summary paragraph, then "Storyline" (dim label, ink text)', await js(`(()=>{const p=[...document.querySelectorAll('.showhead p')],b=p[1]&&p[1].querySelector('b');return !!document.querySelector('.showhead .rchips .orig')&&p.length===2&&p[0].textContent===GAME[${withC}].sum&&p[1].classList.contains('story')&&b.textContent==='Storyline'&&getComputedStyle(b).color===getComputedStyle(document.querySelector('.showhead .starring')).color;})()`));
    ok('game page: genre tags (links, as the film page\'s), then outlined Critic score / User score / age rating tags', await js(`(()=>{const t=document.querySelector('.showhead .tags'),g=GAME[${withC}];return [...t.querySelectorAll('a.tag[data-setgenre]')].map(a=>a.textContent).join('|')===genOf(BYID[${withC}]).join('|')&&[...t.querySelectorAll('.tag.plain')].map(x=>x.textContent).join('|')===[g.cs!=null?'Critic score '+g.cs:'',g.us!=null?'User score '+g.us:'',g.age?'ESRB '+g.age:''].filter(Boolean).join('|');})()`));
    ok('game page: info lines in the film page\'s "Directed by" style: Developed by, Published by, Released, Game modes, Perspective', await js(`[...document.querySelectorAll('.showhead .starring')].map(l=>l.firstChild.textContent.trim()).join('|')==='Developed by|Published by|Released|Game modes|Perspective'`));
    ok('game page: one button row: Download NZB, Copy NZB link, Add to cart, IGDB, Website; IGDB and Website open in a new tab with noopener', await js(`(()=>{const b=[...document.querySelectorAll('.showhead .dacts > *')];return b.map(x=>x.tagName==='A'?x.firstChild.textContent:x.innerText.trim()).join('|')==='Download NZB|Copy NZB link|Add to cart|IGDB|Website'&&b.filter(x=>x.tagName==='A').every(a=>a.target==='_blank'&&/noopener/.test(a.rel)&&a.classList.contains('ext'))&&b[3].href.startsWith('https://www.igdb.com/games/');})()`));
    ok('game page: the facts sit right under the tabs (no stray gap) and carry no Genre fact (the genre is in the header)', await js(`(()=>{const t=document.querySelector('.tabs').getBoundingClientRect().bottom,v=document.querySelector('.panel > .vals').getBoundingClientRect().top;return v-t<40&&![...document.querySelectorAll('.panel > .vals dt')].some(d=>d.textContent==='Genre');})()`));
    { const multi = await js(`+Object.keys(GAME).find(k=>releasesOfGame(BYID[k]).length>1)`); await go('#/release/' + multi); await sleep(300);
      ok('game page: "All N releases of this game" after the facts lists every release of the game, this one marked "The release on this page" and not a link', await js(`(()=>{const s=document.querySelector('#sibs'),n=releasesOfGame(BYID[${multi}]).length,me=s&&s.querySelector('tr.me');return !!s&&s.querySelector('h2').textContent==='All '+n+' releases of this game'&&s.querySelectorAll('tbody tr').length===n&&!!me&&me.querySelector('.this').textContent==='The release on this page'&&!me.querySelector('a.rname')&&me.getAttribute('aria-current')==='true';})()`));
      ok('game page: Similar releases leaves out releases of the same game (the Movies rule)', await js(`[...document.querySelectorAll('.simrel tbody [data-nzb]')].every(b=>gameKey(BYID[+b.dataset.nzb])!==gameKey(BYID[${multi}]))`)); }
    await go('#/release/' + withC); await sleep(300);
    ok('game page: a game with one release reads "The only release of this game"', await js(`releasesOfGame(BYID[${withC}]).length!==1||document.querySelector('#sibs h2').textContent==='The only release of this game'`));
    await go('#/release/' + plainG); await sleep(300);
    ok('a game with no storyline and no website: no Storyline paragraph, no Website button', await js(`!document.querySelector('.showhead p.story')&&[...document.querySelectorAll('.showhead .dacts a.ext')].map(a=>a.firstChild.textContent).join('|')==='IGDB'`));
    await js(`document.querySelector('.showhead a.tag[data-setgenre]').click()`); await sleep(500);
    ok('a genre tag opens the list on that genre alone', await js(`(location.hash==='#/'||location.hash==='')&&state.genre.size===1&&state.genre.has(genOf(BYID[${plainG}])[0])&&!!document.querySelector('.feed')`));
    await js(`document.querySelector('[data-clearall]').click()`); await sleep(100);
    if (without) { await go('#/release/' + without); await sleep(300); ok('a release with no game keeps the release-details form: breadcrumb, the release name as the heading, no cover', await js(`!!document.querySelector('.crumbs')&&document.querySelector('.dhead h1.relonly').textContent===BYID[${without}].name&&!document.querySelector('.dhead .art,.showhead')`)); }
    await go('#/release/' + did); await sleep(300); }
  else ok('no cover on Books or PC details', await js(`!document.querySelector('.dhead .art')`));
  if (FX.files) { await go('#/release/' + FX.files); await js(`document.querySelector('[data-tab=files]').click()`); await sleep(500); ok('Files tab lists the stored file names and sizes', await js(`document.querySelectorAll('.panel .filelist tbody tr').length>0`)); }
  { const noFiles = await js(`(REL.find(r=>!X(r).files)||{}).id`); if (noFiles) { await go('#/release/' + noFiles); await sleep(300); ok('a release with no stored file count: tab reads "Files" and the facts grid shows "—", never 0', await js(`document.querySelector('[data-tab=files]').textContent==='Files'&&[...document.querySelectorAll('.panel > .vals div')].find(d=>d.querySelector('dt').textContent==='Files').querySelector('dd').textContent==='—'`)); } }
  if (FX.nfo) { await go('#/release/' + FX.nfo); await js(`document.querySelector('[data-tab=nfo]').click()`); await sleep(500); ok('NFO tab shows the NFO text in monospace', await js(`!!document.querySelector('.panel .nfo')&&/mono/i.test(getComputedStyle(document.querySelector('.panel .nfo')).fontFamily)`)); }
  if (FX.predb) { await go('#/release/' + FX.predb); await sleep(300); ok('PreDB block on Overview when the release has a match', await js(`!!document.querySelector('.predb')&&document.querySelector('.predb dt').textContent==='Title'`)); }
  if (FX.similar) { await go('#/release/' + FX.similar); await sleep(300);
    ok('Similar releases: Release, Category, Size, Files, Posted and the buttons; newest first; without this release; at most 50', await js(`(()=>{const t=document.querySelector('.simrel table');if(!t)return false;const h=[...t.querySelectorAll('th')].map(x=>x.textContent.trim()).filter(Boolean).join(',');const ids=[...t.querySelectorAll('tbody [data-nzb]')].map(b=>+b.dataset.nzb),ts=ids.map(i=>BYID[i].t);return h==='Release,Category,Size,Files,Posted,Actions'&&ids.length>0&&ids.length<=50&&!ids.includes(${FX.similar})&&ts.every((v,i)=>!i||ts[i-1]>=v);})()`));
    ok('Similar releases sort by Size', (await js(`document.querySelector('[data-dsort="ssort:size"]').click()`), await sleep(100), await js(`(()=>{const s=[...document.querySelectorAll('.simrel tbody [data-nzb]')].map(b=>BYID[+b.dataset.nzb].size);return s.every((v,i)=>!i||s[i-1]>=v);})()`)));
    ok('Similar releases rows have no Follow and the name links to that release', await js(`!document.querySelector('.simrel [data-watch]')&&/^#\\/release\\/\\d+$/.test(document.querySelector('.simrel .rname').getAttribute('href'))`)); }
  { const alone = await js(`(REL.find(r=>!SIM[r.id])||{}).id`); if (alone) { await go('#/release/' + alone); await sleep(300); ok('no Similar releases section when nothing matches', await js(`!document.querySelector('.simrel')`)); } }
  ok('a release that is not in the prototype gets a plain message', (await go('#/release/1'), await js(`!!document.querySelector('.later')`)));

  // ---- themes ----
  ok('light theme: no hard-coded dark-only colours leak (body background changes, text stays readable)', (await go('#/'), await js(`document.documentElement.dataset.theme='light';const bg=getComputedStyle(document.body).backgroundColor;const k=getComputedStyle(document.querySelector('.fbar .sfm .mbtn .k')).color;document.documentElement.dataset.theme='dark';bg!=='rgb(15, 16, 20)'&&k!=='rgb(255, 255, 255)'`)));
  ok('no "Watch" wording anywhere', await js(`!/\\bwatch(ing)?\\b/i.test(document.body.innerText)`));
  ok('no instruction text ("click to") on the screen', await js(`!/click to/i.test(document.body.innerText)`));
} catch (e) { results.push(['FAIL', PAGE + ': script aborted', String(e).split('\n')[0]]); } }

const fails = results.filter(r => r[0] === 'FAIL');
results.forEach(r => console.log(r[0], r[1], r[2] ? '· ' + r[2] : ''));
console.log(`\n${results.length} checks, ${fails.length} failures${errors.length ? '; page errors: ' + errors.join(' | ') : ''}`);
ws.close(); chrome.kill('SIGKILL'); process.exit(fails.length || errors.length ? 1 : 0);
