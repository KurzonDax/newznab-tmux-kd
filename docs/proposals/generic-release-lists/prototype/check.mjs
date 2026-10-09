// Drives headless Chrome over CDP: clicks every control on releases.html (All releases, a group's list, a poster's list, Other)
// and reports pass/fail. One check per rule: the TV / Movies / Adult / Books SPEC rules these lists inherit, plus the
// generic-list decisions of 2026-10-09 (RELEASES-DESIGN-RECORD.md).   node rlcheck.mjs      (V=1 prints progress on stderr)
import {spawn} from 'node:child_process';
import {mkdtempSync} from 'node:fs';
import {tmpdir} from 'node:os';
const BASE = process.env.RL_BASE || 'http://localhost:8766/', PORT = 9383;
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', ['--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(tmpdir() + '/rlcdp-')}`, 'about:blank'], {stdio: 'ignore'});
const sleep = ms => new Promise(r => setTimeout(r, ms));
const results = []; let ws, seq = 0; const pending = new Map(), errors = [];
for (let i = 0; i < 40; i++) { try { const t = await (await fetch(`http://localhost:${PORT}/json`)).json(); const p = t.find(x => x.type === 'page'); if (p) { ws = new WebSocket(p.webSocketDebuggerUrl); await new Promise(r => ws.onopen = r); break; } } catch {} await sleep(250); }
ws.onmessage = m => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d.result); pending.delete(d.id); } if (d.method === 'Runtime.exceptionThrown') errors.push(d.params.exceptionDetails.exception?.description || d.params.exceptionDetails.text); };
const send = (method, params = {}) => new Promise(r => { const id = ++seq; pending.set(id, r); ws.send(JSON.stringify({id, method, params})); });
let PAGE = 'releases';
const js = async expr => { const r = await send('Runtime.evaluate', {expression: expr, awaitPromise: true, returnByValue: true}); if (r.exceptionDetails) { results.push(['FAIL', 'eval threw', expr.slice(0, 90) + ' :: ' + (r.exceptionDetails.exception?.description || '').split('\n')[0]]); return undefined; } return r.result.value; };
const ok = (name, cond, extra = '') => { results.push([cond ? 'PASS' : 'FAIL', name, extra]); if (process.env.V) console.error((cond ? 'PASS ' : 'FAIL ') + name + (cond ? '' : ' ' + extra)); };
const go = async hash => { await js(`location.hash=${JSON.stringify(hash)}`); await sleep(300); if (/^#\/release\//.test(hash)) for (let i = 0; i < 20 && !(await js(`!!document.querySelector('.mdet,.later')`)); i++) await sleep(150); };
const key = (k, code) => send('Input.dispatchKeyEvent', {type: 'keyDown', key: k, code: code || k, windowsVirtualKeyCode: k === 'Escape' ? 27 : 0}).then(() => send('Input.dispatchKeyEvent', {type: 'keyUp', key: k, code: code || k}));
const load = async (hash = '#/') => { await send('Page.navigate', {url: BASE + PAGE + '.html?r=' + Math.random() + hash}); for (let i = 0; i < 60 && !(await js('!!window.READY')); i++) await sleep(200); await sleep(300); };
const open = k => js(`(()=>{if(state.dd!=='${k}')document.querySelector('[data-ddtoggle=${k}]').click();return state.dd==='${k}';})()`);
const pick = async (k, v) => { await open(k); await js(v === 'Any' ? `document.querySelector('[data-ddany=${k}]').click()` : `document.querySelector('[data-ddpick=${k}][data-v="${v}"]').click()`); };
const radio = async (k, v) => { await open(k); await js(`document.querySelector('[data-rpick="${k}:${v}"]').click()`); };
const close = () => js(`document.querySelector('.pager .sum').click()`);
const count = () => js(`+document.querySelector('.pager.slim .sum').textContent.replace(/^.* of ([\\d,]+) .*$/,'$1').replace(/,/g,'')`);
const ROWIDS = `[...document.querySelectorAll('.feed tbody tr [data-nzb]')].map(b=>+b.dataset.nzb)`;
const every = pred => js(`(()=>{const ids=${ROWIDS};return ids.length>0&&ids.every(id=>{const r=BYID[id],x=X(r);return ${pred};});})()`);
const rect = sel => js(`(()=>{const e=document.querySelector(${JSON.stringify(sel)});if(!e)return null;const r=e.getBoundingClientRect();return [r.left,r.top,r.width,r.height];})()`);
const heading = () => js(`document.querySelector('.filters h1')?.textContent`);
const typeQ = async v => { await js(`document.getElementById('q').focus()`); await send('Input.insertText', {text: v}); await sleep(400); };

await send('Runtime.enable'); await send('Page.enable'); await send('Emulation.setFocusEmulationEnabled', {enabled: true});
await send('Emulation.setDeviceMetricsOverride', {width: 1600, height: 1000, deviceScaleFactor: 1, mobile: false});

try {
  await load(); await js('localStorage.clear()'); await load();
  const FX = await js(`fetch(DIR+'fixtures.json').then(r=>r.json())`);
  const TOTAL = await js('REL.length');
  const G = FX.group, GENC = encodeURIComponent(G), P = FX.poster, PENC = encodeURIComponent(P);

  // ---- the header ----
  ok('header bar: Movies, TV, Audio, Books, Console, PC, Adult, Other, All (#908 order)', await js(`[...document.querySelectorAll('.nav .l')].map(a=>a.textContent.trim()).join(',')`) === 'Movies,TV,Audio,Books,Console,PC,Adult,Other,All');
  ok('Other and All are drop-downs; the other seven are placeholders', await js(`[...document.querySelectorAll('.nav button.l')].map(b=>b.dataset.nd).join(',')`) === 'other,all' && await js(`document.querySelectorAll('.nav a.l[data-dummy]').length`) === 7);
  ok('All is the current section on All releases', await js(`document.querySelector('.nav .l.on')?.textContent.trim()`) === 'All');
  await js(`document.querySelector('[data-nd=all]').click()`); await sleep(100);
  ok('All menu: Browse by group, All Releases', await js(`[...document.querySelectorAll('.nav .nd.open .mitem')].map(a=>a.textContent).join('|')`) === 'Browse by group|All Releases');
  await js(`document.querySelector('.nav .nd.open .mitem').click()`); await sleep(100);
  ok('Browse by group is today\'s group list page, outside this prototype (a notice, the menu closes)', await js(`document.getElementById('toast').textContent`).then(t => /group list page/.test(t)) && !(await js(`!!document.querySelector('.nav .nd.open')`)));
  await js(`document.querySelector('[data-nd=other]').click()`); await sleep(100);
  ok('Other menu: All Other (bold), a separator, Misc, Hashed', await js(`[...document.querySelectorAll('.nav .nd.open .mitem')].map(a=>a.textContent+(a.classList.contains('root')?'*':'')).join('|')`) === 'All Other*|Misc|Hashed' && await js(`!!document.querySelector('.nav .nd.open .msep')`));
  await key('Escape'); await sleep(100);
  ok('Escape closes the header menu', !(await js(`!!document.querySelector('.nav .nd.open')`)));

  // ---- All releases ----
  ok('heading "All releases"; no breadcrumb', await heading() === 'All releases' && !(await js(`!!document.querySelector('.crumbs.top')`)));
  ok('filter bar: Category, Completion; nothing else', await js(`[...document.querySelectorAll('.fbar .mbtn .k')].map(k=>k.textContent).join(',')`) === 'Category,Completion');
  ok('every cell is as wide as a Movies release bar cell', await js(`(()=>{const w=document.querySelector('.filters').getBoundingClientRect().width,movies=((w-12)/2-8)/5,c=[...document.querySelectorAll('.fbar .sfm')].map(e=>e.getBoundingClientRect().width);return c.length===2&&c.every(v=>Math.abs(v-movies)<2);})()`));
  ok('name search "Search release names" beside the heading', await js(`document.querySelector('.filters h1').nextElementSibling.querySelector('input').placeholder`) === 'Search release names');
  ok('five sorts: Posted / Added newest and oldest, Name A to Z; Posted newest first by default', await js(`[...document.querySelectorAll('[data-rsort] option')].map(o=>o.textContent).join('|')`) === 'Posted: newest first|Posted: oldest first|Added: newest first|Added: oldest first|Name: A to Z' && await js(`document.querySelector('[data-rsort]').value`) === 'posted_desc');
  ok('pager line counts the whole slice', await count() === TOTAL && TOTAL === FX.total, String(TOTAL));
  ok('50 releases a page, newest posted first', await js(`(()=>{const ids=${ROWIDS},t=ids.map(i=>BYID[i].t);return ids.length===50&&t.every((v,i)=>!i||t[i-1]>=v);})()`));
  ok('columns: Release, Category, Size, Posted; no picture, Resolution, Files or Grabs column', await js(`[...document.querySelectorAll('.feed thead th')].map(t=>t.textContent.trim()).filter(t=>t&&t!=='Actions').join(',')`) === 'Release,Category,Size,Posted');
  ok('fixed widths 34 · auto · measured Category · 82 · 112 · 96', await js(`[...document.querySelectorAll('.feed colgroup col')].map(c=>c.style.width||'auto').join(',')`) === await js(`'34px,auto,'+CATW+'px,82px,112px,96px'`));
  ok('the Category column is as wide as "Console > Xbox 360 DLC" at the column\'s font, plus the cell padding', await js(`(()=>{const s=document.createElement('span');s.style.cssText='position:absolute;visibility:hidden;white-space:nowrap';const td=document.querySelector('.feed td.c-cat'),cs=getComputedStyle(td);s.style.fontFamily=cs.fontFamily;s.style.fontSize=cs.fontSize;s.style.fontWeight=cs.fontWeight;s.textContent='Console > Xbox 360 DLC';document.body.appendChild(s);const w=s.getBoundingClientRect().width;s.remove();return Math.abs(CATW-(Math.ceil(w)+24))<=1;})()`));
  ok('the Category column reads "Root > Sub" and is never cut short', await every(`document.querySelector('.feed tbody tr:has([data-nzb="'+id+'"]) td.c-cat').textContent===r.root+' > '+r.sub`) && await js(`[...document.querySelectorAll('.feed tbody td.c-cat')].every(td=>td.scrollWidth<=td.clientWidth)`));
  ok('only the release name is bold in a row', await js(`(()=>{const tr=document.querySelector('.feed tbody tr');return [...tr.querySelectorAll('*')].filter(e=>getComputedStyle(e).fontWeight>=700&&e.textContent.trim()).every(e=>e.classList.contains('rname'));})()`));
  ok('every row: group and poster outline chips as one unit; both link to their lists', await every(`(()=>{const o=document.querySelector('.feed tbody tr:has([data-nzb="'+id+'"]) .orig'),a=o.querySelectorAll('a.rc.origin');return a.length===2&&a[0].getAttribute('href')==='#/group/'+encodeURIComponent(x.group)&&a[1].getAttribute('href')==='#/poster/'+encodeURIComponent(x.poster);})()`));
  ok('2 x 2 row buttons: Download coral, Copy link, Cart; Follow only on a film or show row, else an empty slot', await every(`(()=>{const b=[...document.querySelectorAll('.feed tbody tr:has([data-nzb="'+id+'"]) .iconacts.stack > *')];const e=E(r),f=e&&(e[0]==='film'||e[0]==='show');return b.length===4&&b[0].classList.contains('dl')&&b[1].dataset.copynzb&&b[2].dataset.cart&&(f?!!b[3].dataset.watch:b[3].classList.contains('slot'));})()`));
  ok('Download is the only coral button in a row', await js(`(()=>{const acc=getComputedStyle(document.documentElement).getPropertyValue('--acc').trim(),tr=document.querySelector('.feed tbody tr');const c=document.createElement('i');c.style.background=acc;document.body.appendChild(c);const v=getComputedStyle(c).backgroundColor;c.remove();return [...tr.querySelectorAll('.ia')].filter(b=>getComputedStyle(b).backgroundColor===v).every(b=>b.classList.contains('dl'));})()`));

  // ---- chips on the fixture releases (each opened by name search) ----
  const chipOn = async (id, sel) => { await go('#/'); await js(`state.q='';state.cat.clear();state.comp=false;saveFilters();`); await js(`document.getElementById('q').focus()`); await js(`document.getElementById('q').value=''`); await typeQ(await js(`BYID[${id}].name.slice(0,40)`)); return js(`!!document.querySelector('.feed tbody tr:has([data-nzb="${id}"]) ${sel}')`); };
  ok('a partly complete release shows "N% complete" in the completion colours', await chipOn(FX.partial, '.rc.comp'));
  ok('a passworded release shows the Password chip', await chipOn(FX.pw, '.rc:not(.comp):not(.origin) use[href="#i-lock"]'));
  ok('media info chip (codec · audio) opens the media info dialog', await chipOn(FX.media, '.rc.k-mi') && await js(`document.querySelector('.feed [data-mi="${FX.media}"]').click();1`) && (await sleep(500), await js(`!!document.querySelector('#modal .mi2')`)));
  await key('Escape');
  ok('NFO chip opens the NFO dialog with the text', await chipOn(FX.nfo, '.rc.k-nfo') && await js(`document.querySelector('.feed [data-nfo="${FX.nfo}"]').click();1`) && (await sleep(500), await js(`!!document.querySelector('#modal pre.nfo')`)));
  await key('Escape');
  ok('Preview chip opens the preview image dialog', await chipOn(FX.preview, '.rc.k-pv') && await js(`document.querySelector('.feed [data-img="${FX.preview}"][data-kind=preview]').click();1`) && (await sleep(500), await js(`!!document.querySelector('#modal img.pvimg')`)));
  await key('Escape');
  ok('Sample chip opens the sample image dialog', await chipOn(FX.sample, '.rc.k-sm') && await js(`document.querySelector('.feed [data-img="${FX.sample}"][data-kind=sample]').click();1`) && (await sleep(500), await js(`!!document.querySelector('#modal img.pvimg')`)));
  await key('Escape');
  ok('Clip chip opens the clip dialog', await chipOn(FX.clip, '.rc.k-cl') && await js(`document.querySelector('.feed [data-clip="${FX.clip}"]').click();1`) && (await sleep(400), await js(`document.querySelector('#modal .dlghead h2')?.textContent`) === 'Video clip'));
  await key('Escape');
  ok('Reported chip (invented data) links to the release page', await chipOn(FX.reported, '.rc.k-rep[href="#/release/' + FX.reported + '"]'));
  ok('Response chip (invented data) links to the release page', await chipOn(FX.responded, '.rc.k-resp[href="#/release/' + FX.responded + '"]'));
  ok('a film\'s release: "Title · Year" line under the name, Follow film button', await chipOn(FX.film, 'a.showlink') && await js(`(()=>{const e=E(BYID[${FX.film}]),l=document.querySelector('.feed tbody tr:has([data-nzb="${FX.film}"]) a.showlink');return l.textContent===e[1]+(e[2]?' · '+e[2]:'')&&!!document.querySelector('.feed tbody tr:has([data-nzb="${FX.film}"]) [data-watch="film:'+e[3]+'"]');})()`));
  await js(`document.querySelector('.feed tbody tr:has([data-nzb="${FX.film}"]) [data-watch]').click()`); await sleep(200);
  ok('Follow pressed: on in its own hue, never coral', await js(`(()=>{const b=document.querySelector('.feed tbody tr:has([data-nzb="${FX.film}"]) [data-watch]'),dl=document.querySelector('.feed .ia.dl');return b.getAttribute('aria-pressed')==='true'&&getComputedStyle(b).backgroundColor!==getComputedStyle(dl).backgroundColor;})()`));
  ok('a show\'s release: "Show · episode" line under the name', await chipOn(FX.show, 'a.showlink') && await js(`(()=>{const e=E(BYID[${FX.show}]),l=document.querySelector('.feed tbody tr:has([data-nzb="${FX.show}"]) a.showlink');return l.textContent===e[1]+(e[2]?' · '+e[2]:'');})()`));
  ok('an album\'s release: performer · album as plain text', await chipOn(FX.album, '.gameline'));
  await js(`document.querySelector('.feed tbody tr:has([data-nzb="${FX.album}"]) [data-cart]').click()`); await sleep(200);
  ok('Cart pressed: on in its own hue, cart count 1', await js(`document.querySelector('.feed [data-cart="${FX.album}"]').getAttribute('aria-pressed')==='true'&&document.getElementById('cartn').textContent==='1'`));
  await js(`document.querySelector('.feed [data-copynzb="${FX.album}"]').click()`); await sleep(200);
  ok('Copy NZB link copies a link with the release guid', await js(`(window.__copied||'').includes(X(BYID[${FX.album}]).guid)`));

  // ---- filters and search ----
  await go('#/'); await js(`state.q='';state.cat.clear();state.comp=false;saveFilters();rerender()`); await sleep(200);
  await open('cat');
  ok('Category menu on All: Any category, Exclude Other, then the roots present in header order', await js(`[...document.querySelectorAll('[data-msel=cat] .mitem')].map(b=>b.textContent).join('|')`) === ['Any category', 'Exclude Other', ...FX.roots].join('|'));
  await js(`document.querySelector('[data-ddexo]').click()`); await sleep(200);
  ok('Exclude Other: no Other row, cell reads "Exclude Other", count = slice minus Other', await every(`r.root!=='Other'`) && await js(`document.querySelector('[data-msel=cat] .v').textContent`) === 'Exclude Other' && await count() === FX.total - FX.other);
  await close();
  await pick('cat', 'Any'); await close();
  await pick('cat', 'TV'); await close();
  ok('Category TV: only TV rows', await every(`r.root==='TV'`));
  await radio('comp', '95');
  ok('Completion 95% or more: every row at 95%+; cell reads 95%+', await every(`r.comp>=95`) && await js(`document.querySelector('[data-msel=comp] .v').textContent`) === '95%+');
  ok('the set cells carry the coral line; Clear all shows', await js(`document.querySelectorAll('.fbar .msel.set').length`) === 2 && await js(`document.querySelector('[data-clearall]').getAttribute('aria-hidden')`) !== 'true');
  await load('#/');
  ok('the dropdown filters are remembered after a reload (#881)', await js(`[...state.cat].join()==='TV'&&state.comp==='95'`));
  await typeQ('S01E');
  ok('the name search narrows the list to names containing the text, back to page 1; not remembered', await every(`r.name.toLowerCase().includes('s01e')`) && await js(`state.page`) === 1);
  await load('#/');
  ok('name search forgotten on reload, dropdown filters kept', await js(`state.q===''&&[...state.cat].join()==='TV'`));
  await js(`document.querySelector('[data-clearall]').click()`); await sleep(200);
  ok('Clear all clears both menus and the remembered set', await js(`!state.cat.size&&!state.comp`) && await count() === TOTAL);
  await js(`document.querySelector('[data-rsort]').value='name_asc';document.querySelector('[data-rsort]').dispatchEvent(new Event('change',{bubbles:true}))`); await sleep(300);
  ok('Name: A to Z sorts by release name', await js(`(()=>{const n=${ROWIDS}.map(i=>BYID[i].name.toLowerCase());return n.every((v,i)=>!i||n[i-1].localeCompare(v,'en',{sensitivity:'base'})<=0);})()`));
  await js(`document.querySelector('[data-rsort]').value='added_desc';document.querySelector('[data-rsort]').dispatchEvent(new Event('change',{bubbles:true}))`); await sleep(300);
  ok('Added sort: the date column is headed Added', await js(`[...document.querySelectorAll('.feed thead th')].map(t=>t.textContent.trim()).includes('Added')`));
  await js(`document.querySelector('[data-rsort]').value='posted_desc';document.querySelector('[data-rsort]').dispatchEvent(new Event('change',{bubbles:true}))`); await sleep(300);

  // ---- paging ----
  await go('#/p/3');
  ok('page in the URL: #/p/3 shows page 3, Showing 101–150', await js(`document.querySelector('.pager.slim .sum').textContent`).then(t => t.startsWith('Showing 101–150')));
  ok('bottom pager: Previous, numbers with the current one marked, Next, Go to page', await js(`!!document.querySelector('.pager.bottom .cur[aria-current=page]')&&document.querySelector('.pager.bottom .cur').textContent==='3'&&!!document.querySelector('.pager.bottom [data-goto]')`));
  await js(`document.querySelector('#gob').value='7';document.querySelector('[data-goto]').requestSubmit()`); await sleep(300);
  ok('Go to page 7 lands on #/p/7', await js(`location.hash`) === '#/p/7');
  await pick('cat', 'Movies'); await close();
  ok('a filter change returns to page 1', await js(`state.page===1&&location.hash==='#/'`));
  await js(`document.querySelector('[data-clearall]').click()`); await sleep(200);

  // ---- selection ----
  await js(`document.querySelector('[data-selectall]').click()`); await sleep(200);
  ok('select all: the floating bar says 50 selected, with Download NZBs / Add to cart / Clear selection', await js(`document.querySelector('.bulk span')?.textContent`) === '50 selected' && await js(`[...document.querySelectorAll('.bulk .btn')].map(b=>b.textContent.trim()).join('|')`) === 'Download NZBs|Add to cart|Clear selection');
  await js(`document.querySelector('[data-bulk=clear]').click()`); await sleep(200);
  ok('Clear selection removes the bar', !(await js(`!!document.querySelector('.bulk')`)));

  // ---- a group's list ----
  await go('#/group/' + GENC); await sleep(200);
  ok(`group list: heading "Releases in ${G}", breadcrumb All releases › Group, All marked current`, await heading() === 'Releases in ' + G && await js(`document.querySelector('.crumbs.top')?.textContent.replace(/\\s+/g,' ')`) === 'All releases›Group' && await js(`document.querySelector('.nav .l.on')?.textContent.trim()`) === 'All');
  ok('every row is from that group; the group chip is left off, the poster chip stays', await every(`x.group===${JSON.stringify(G)}`) && await js(`document.querySelectorAll('.feed .orig a[href^="#/group/"]').length===0&&document.querySelectorAll('.feed .orig a[href^="#/poster/"]').length===50`) && await count() === FX.group_n);
  await open('cat');
  ok('the Category menu lists only the roots this group has', await js(`[...document.querySelectorAll('[data-msel=cat] [data-ddpick]')].map(b=>b.dataset.v).join()`) === await js(`ROOTS.filter(c=>new Set(LIST().map(r=>r.root)).has(c)).join()`));
  await close();
  await js(`document.querySelector('.crumbs.top a').click()`); await sleep(300);
  ok('the breadcrumb returns to All releases', await heading() === 'All releases');
  await js(`document.querySelector('.feed .orig a[href^="#/group/"]').click()`); await sleep(300);
  ok('a group chip opens that group\'s list', await heading().then(h => h.startsWith('Releases in ')));
  await go('#/group/alt.binaries.nothing.here'); await sleep(200);
  ok('a group with nothing in the slice says so', await js(`document.querySelector('.empty')?.textContent`).then(t => /No releases from this group/.test(t || '')));

  // ---- a poster's list ----
  await go('#/poster/' + PENC); await sleep(200);
  ok(`poster list: heading "Posts by ${P}", breadcrumb All releases › Poster`, await heading() === 'Posts by ' + P && await js(`document.querySelector('.crumbs.top')?.textContent.replace(/\\s+/g,' ')`) === 'All releases›Poster');
  ok('every row is by that poster; the poster chip is left off, the group chip stays', await every(`x.poster===${JSON.stringify(P)}`) && await js(`document.querySelectorAll('.feed .orig a[href^="#/poster/"]').length===0&&document.querySelectorAll('.feed .orig a[href^="#/group/"]').length===50`) && await count() === FX.poster_n);
  ok('the admin\'s "Blacklist this poster" button beside the search; red as a tinted button, not coral', await js(`(()=>{const b=document.querySelector('[data-blacklist]'),dl=getComputedStyle(document.querySelector('.feed .ia.dl')).backgroundColor;return !!b&&b.textContent.trim()==='Blacklist this poster'&&getComputedStyle(b).backgroundColor!==dl;})()`));
  await js(`document.querySelector('[data-blacklist]').click()`); await sleep(300);
  ok('the confirmation shows the exact rule: Regex ^poster$ quoted, Rule, Group scope from the poster\'s groups, Description', await js(`(()=>{const d=[...document.querySelectorAll('#modal dl.vals dd')].map(x=>x.textContent);return document.querySelector('#modal h2').textContent==='Blacklist this poster'&&d[0]==='^'+reQuote(${JSON.stringify(P)})+'$'&&d[1]==='Posted By · Type: Black · Status: enabled'&&d[2].startsWith('^(?:')&&d[2].includes('alt\\\\.binaries\\\\.')&&d[3].startsWith('Poster identity blocked from poster page by');})()`));
  ok('the remove-releases option names the poster\'s release count; Cancel and Confirm blacklist', await js(`document.querySelector('#modal .danger-opt').textContent.includes((${FX.poster_n}).toLocaleString()+' existing releases')&&[...document.querySelectorAll('#modal .dacts .btn')].map(b=>b.textContent.trim()).join('|')==='Cancel|Confirm blacklist'`));
  await js(`document.querySelector('#modal .dacts [data-close]').click()`); await sleep(200);
  ok('Cancel closes without a rule', !(await js(`!!document.querySelector('#modal .dlg')`)) && await js(`!!document.querySelector('[data-blacklist]')`));
  await js(`document.querySelector('[data-blacklist]').click()`); await sleep(200);
  await js(`document.querySelector('#modal input[name=delete_releases]').click();document.querySelector('[data-blform]').requestSubmit()`); await sleep(300);
  ok('Confirm: the button becomes "Blacklisted (rule #N)"; the sweep status sits in the pager line (nothing moves), no toast doubles it', await js(`document.querySelector('.btn.bl')?.textContent.trim()`).then(t => /^Blacklisted \(rule #\d+\)$/.test(t || '')) && !(await js(`document.getElementById('toast').classList.contains('on')`)) && await js(`document.querySelector('.pager.slim .sweep')?.textContent`).then(t => /sweep running/.test(t || '')));
  ok('rows stay while the sweep runs', await count() === FX.poster_n);
  await sleep(4300);
  ok('the finished sweep: the poster\'s releases are gone, the list says so', await js(`document.querySelector('.pager.slim .sweep')?.textContent`).then(t => /sweep finished/.test(t || '')) && await js(`document.querySelector('.empty')?.textContent`).then(t => /blacklist sweep removed/.test(t || '')));
  await go('#/'); await sleep(200);
  ok('All releases no longer counts the swept poster\'s releases', await count() === TOTAL - FX.poster_n && await every(`x.poster!==${JSON.stringify(P)}`));
  await js(`state.sweep=null`);
  await go('#/'); await sleep(200);
  ok('All releases carries no Blacklist button and no sweep status', !(await js(`!!document.querySelector('[data-blacklist],.btn.bl,.sweep')`)));
  ok('the Reported chip is teal-green 170 and Response blue 245: clear of coral, the amber completion chip and the Media info chip', await js(`(()=>{const cs=getComputedStyle(document.documentElement);return cs.getPropertyValue('--k-rep-bg').includes(' 170)')&&cs.getPropertyValue('--k-resp-bg').includes(' 245)');})()`));

  // ---- Other ----
  await go('#/other'); await sleep(200);
  ok('Other list: heading "Other releases", Other marked current, Other scope preselected, no breadcrumb', await heading() === 'Other releases' && await js(`document.querySelector('.nav .l.on')?.textContent.trim()`) === 'Other' && await js(`document.getElementById('scope').value`) === 'Other' && !(await js(`!!document.querySelector('.crumbs.top')`)));
  ok('every row is Other; the Category column reads the sub-category alone; count = the slice\'s Other rows', await every(`r.root==='Other'&&document.querySelector('.feed tbody tr:has([data-nzb="'+id+'"]) td.c-cat').textContent===r.sub`) && await count() === FX.other);
  await open('cat');
  ok('Category menu on Other: Any category, Misc, Hashed; no Exclude Other', await js(`[...document.querySelectorAll('[data-msel=cat] .mitem')].map(b=>b.textContent).join('|')`) === 'Any category|Misc|Hashed');
  await close();
  await js(`document.querySelector('[data-nd=other]').click()`); await sleep(100); await js(`[...document.querySelectorAll('.nav .nd.open .mitem')].find(a=>a.textContent==='Hashed').click()`); await sleep(400);
  ok('the header\'s Hashed item opens Other with Category = Hashed', await js(`location.hash`) === '#/other' && await js(`[...state.cat].join()`) === 'Hashed' && await every(`r.sub==='Hashed'`) && await count() === FX.hashed);
  await load('#/');
  ok('All releases keeps its own remembered filters, separate from Other\'s', await js(`!state.cat.size`));
  await go('#/other'); await sleep(200);
  ok('Other remembers Hashed', await js(`[...state.cat].join()`) === 'Hashed');
  await js(`document.querySelector('[data-clearall]').click()`); await sleep(200);

  // ---- release details ----
  await go('#/'); await sleep(200); await go('#/release/' + FX.media);
  ok('details: breadcrumb "All releases › Root > Sub", the name as the heading, tabs Overview / Files / Media info / NFO / Comments', await js(`document.querySelector('.mdet .crumbs')?.textContent.replace(/\\s+/g,' ')`) === 'All releases›' + await js(`X(BYID[${FX.media}]).cat`) && await js(`document.querySelector('.mdet h1').textContent===BYID[${FX.media}].name`) && await js(`[...document.querySelectorAll('[role=tab]')].map(t=>t.textContent.replace(/ \\(\\d+\\)/,'')).join('|')`) === 'Overview|Files|Media info|NFO|Comments');
  ok('facts: Category reads "Root > Sub", Group and Poster listed', await js(`(()=>{const d={};document.querySelectorAll('.panel dl.vals > div').forEach(x=>d[x.querySelector('dt').textContent]=x.querySelector('dd').textContent);return d.Category===X(BYID[${FX.media}]).cat&&'Group' in d&&'Poster' in d;})()`));
  await go('#/release/' + (await js(`REL.find(r=>r.root==='Other'&&!RX.summ[r.id]).id`)));
  ok('a release without media info has no Media info tab', await js(`[...document.querySelectorAll('[role=tab]')].map(t=>t.textContent).join('|')`).then(t => !/Media info/.test(t)));
  await go('#/other'); await sleep(200); await go('#/release/' + FX.pw);
  ok('details opened from the Other list crumbs back to "Other releases"', await js(`document.querySelector('.mdet .crumbs a').textContent`) === 'Other releases');
  await go('#/release/' + FX.reported);
  ok('a reported release (invented) shows the report note on Overview, marked as invented', await js(`document.querySelector('.report')?.textContent`).then(t => /Reported/.test(t || '') && /INVENTED/.test(t || '')));

  // ---- light theme ----
  await go('#/'); await js(`document.documentElement.dataset.theme='light'`); await sleep(200);
  ok('light theme: light page, dark ink, chips keep their hues (no chip white-on-white)', await js(`(()=>{const cv=document.createElement('canvas').getContext('2d'),L=c=>{cv.fillStyle='#000';cv.fillStyle=c;cv.fillRect(0,0,1,1);const m=cv.getImageData(0,0,1,1).data;return (0.2126*m[0]+0.7152*m[1]+0.0722*m[2])/255;};const bg=L(getComputedStyle(document.body).backgroundColor);return bg>0.9&&[...document.querySelectorAll('.feed .rc:not(.origin)')].every(c=>{const s=getComputedStyle(c);return Math.abs(L(s.backgroundColor)-L(s.color))>0.3;});})()`));
  await js(`document.documentElement.dataset.theme='dark'`);
} catch (e) { results.push(['FAIL', 'harness', String(e)]); }

ws.close(); chrome.kill('SIGKILL');
const fails = results.filter(r => r[0] === 'FAIL');
for (const [s, n, x] of results) if (s === 'FAIL' || process.env.ALL) console.log(s, n, x || '');
if (errors.length) console.log('PAGE ERRORS', errors.slice(0, 5));
console.log(`${results.length - fails.length}/${results.length} passed`);
process.exit(fails.length || errors.length ? 1 : 0);
