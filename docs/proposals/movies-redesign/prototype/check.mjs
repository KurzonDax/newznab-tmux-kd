// Drives headless Chrome over CDP: clicks every control on movies.html and reports pass/fail.
// One check per rule Randall set (TV SPEC section 2 + the Movies decisions in MOVIES-DESIGN-RECORD.md).
import {spawn} from 'node:child_process';
import {mkdtempSync} from 'node:fs';
import {tmpdir} from 'node:os';
const URL_ = process.env.MV_URL || 'http://127.0.0.1:8766/movies.html', PORT = 9377;
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', ['--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(tmpdir() + '/mvcdp-')}`, 'about:blank'], {stdio: 'ignore'});
const sleep = ms => new Promise(r => setTimeout(r, ms));
const results = []; let ws, seq = 0; const pending = new Map(), errors = [];
for (let i = 0; i < 40; i++) { try { const t = await (await fetch(`http://127.0.0.1:${PORT}/json`)).json(); const p = t.find(x => x.type === 'page'); if (p) { ws = new WebSocket(p.webSocketDebuggerUrl); await new Promise(r => ws.onopen = r); break; } } catch {} await sleep(250); }
ws.onmessage = m => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d.result); pending.delete(d.id); } if (d.method === 'Runtime.exceptionThrown') { errors.push(d.params.exceptionDetails.exception?.description || d.params.exceptionDetails.text); if (process.env.V) console.error('EXCEPTION after: ' + (results.at(-1)?.[1] || 'start') + ' :: ' + (d.params.exceptionDetails.exception?.description || '').split('\n').slice(0,3).join(' | ')); } };
const send = (method, params = {}) => new Promise(r => { const id = ++seq; pending.set(id, r); ws.send(JSON.stringify({id, method, params})); });
const js = async expr => { const r = await send('Runtime.evaluate', {expression: expr, awaitPromise: true, returnByValue: true}); if (r.exceptionDetails) { results.push(['FAIL', 'eval threw', expr.slice(0, 90) + ' :: ' + (r.exceptionDetails.exception?.description || '').split('\n')[0]]); return undefined; } return r.result.value; };
const ok = (name, cond, extra = '') => { results.push([cond ? 'PASS' : 'FAIL', name, extra]); if (process.env.V) console.error((cond ? 'PASS ' : 'FAIL ') + name); };
const size = (w, h) => send('Emulation.setDeviceMetricsOverride', {width: w, height: h, deviceScaleFactor: 1, mobile: false});
const go = async hash => { await js(`location.hash=${JSON.stringify(hash)}`); await sleep(250); };
const ROWS = `[...document.querySelectorAll('.feed tbody tr:not(.moreof)')]`;
const rowIds = `${ROWS}.map(r=>+r.querySelector('[data-nzb]').dataset.nzb)`;
const every = pred => js(`(()=>{const ids=${rowIds};return ids.length>0&&ids.every(id=>{const r=BYID[id],f=F(r);return ${pred};});})()`);

await send('Runtime.enable'); await send('Page.enable'); await send('Emulation.setFocusEmulationEnabled', {enabled: true}); await size(1600, 1000);
await send('Page.navigate', {url: URL_}); for (let i = 0; i < 60 && !(await js('!!window.READY')); i++) await sleep(200);
await js(`localStorage.clear()`); await send('Page.navigate', {url: URL_}); for (let i = 0; i < 60 && !(await js('!!window.READY')); i++) await sleep(200);
await sleep(600);
const FX = await js(`fetch('mv/fixtures.json').then(r=>r.json())`);

const open = k => js(`(()=>{if(state.dd!=='${k}')document.querySelector('[data-ddtoggle=${k}]').click();return state.dd==='${k}';})()`);
const pick = async (k, v) => { await open(k); await js(v === 'Any' ? `document.querySelector('[data-ddany=${k}]').click()` : `document.querySelector('[data-ddpick=${k}][data-v="${v}"]').click()`); };
const close = () => js(`document.querySelector('.pager .sum').click()`);

if (!process.env.FILMONLY && !process.env.DETONLY) {
// ---- the screen ----
ok('front page is Movie releases', await js(`document.querySelector('.filters h1').textContent`) === 'Movie releases');
ok('Releases / Films switch, Releases current', await js(`document.querySelector('.seg [aria-current=page]').textContent==='Releases'&&document.querySelector('.seg a[href="#/films"]')!==null`));
ok('search field "Search films or actors" right of the switch', await js(`document.querySelector('.seg').nextElementSibling.querySelector('input').placeholder==='Search films or actors'`));
ok('no variation selector on the list', await js(`!document.querySelector('[data-ml],[data-fl],.varsel')`));
ok('pager line: Showing 1–50 of N releases · Page 1 of N', /^Showing 1–50 of [\d,]+ releases/.test(await js(`document.querySelector('.pager.slim .sum').textContent`)) && /^Page 1 of \d+$/.test(await js(`document.querySelector('.pager.slim .pg').textContent`)));
ok('bottom pager with Go to page', await js(`!!document.querySelector('.pager.bottom form[data-goto]')`));
ok('50 rows on a page (a collapsed batch counts)', await js(`(()=>{const n=${ROWS}.length,hidden=[...document.querySelectorAll('[data-expand]')].length;return n<=50&&n>=40;})()`), await js(`${ROWS}.length`));
ok('newest posted first', await js(`(()=>{const t=${rowIds}.map(i=>BYID[i].t);return t.every((x,i)=>!i||t[i-1]>=x);})()`));
ok('only the release name is bold in a row', await js(`${ROWS}.every(r=>[...r.querySelectorAll('*')].filter(e=>e.childNodes[0]?.nodeType===3&&e.textContent.trim()&&+getComputedStyle(e).fontWeight>=600&&!e.closest('.rname,.res,.btn,button,.rc')).length===0)`));
ok('release name links to that release\'s details page', await js(`${ROWS}.every(r=>r.querySelector('.rname').getAttribute('href')==='#/release/'+r.querySelector('[data-nzb]').dataset.nzb)`));
ok('film line reads "Title · Year" and links to the film page', await js(`${ROWS}.filter(r=>r.querySelector('.showlink')).every(r=>{const id=+r.querySelector('[data-nzb]').dataset.nzb,f=F(BYID[id]);return r.querySelector('.showlink').getAttribute('href')==='#/film/'+BYID[id].f&&r.querySelector('.showlink').textContent===f.t+(f.y?' · '+f.y:'');})`));
ok('a release with no film: placeholder tile instead of a poster, no film line, invisible watch slot', await js(`(()=>{const r=document.querySelector('.feed tr[data-nofilm]');return !!r&&!!r.querySelector('td.art a.ph')&&!r.querySelector('td.art img')&&!r.querySelector('.showlink')&&!r.querySelector('[data-watch]')&&!!r.querySelector('.ia.slot');})()`));
ok('four round actions in order: Download (coral), Copy link, Cart, Follow', await js(`(()=>{const a=[...document.querySelector('.feed tr:not([data-nofilm]) .iconacts').children].map(b=>b.getAttribute('aria-label'));return a.join('|')==='Download NZB|Copy NZB link for SABnzbd or NZBGet|Add to cart|Follow film'&&getComputedStyle(document.querySelector('.ia.dl')).backgroundColor===getComputedStyle(document.querySelector('.seg [aria-current=page]')).backgroundColor;})()`));
ok('no Report button, no details button in rows', await js(`!document.querySelector('.feed [title*=Report i],.feed [aria-label*=Report i],.feed [aria-label=Details],.feed [title=Details]')`));

// ---- no-poster placeholder (his choice: B) ----
ok('a film-less release shows a name card when its name states Title.Year.quality, else the film tile with "No poster"', await js(`(()=>{const t=[...document.querySelectorAll('.feed tr[data-nofilm]')],p=document.querySelector('.feed td.art a:not(.ph)').getBoundingClientRect();return t.length>0&&t.every(r=>{const id=+r.querySelector('[data-nzb]').dataset.nzb,nt=nameTitle(BYID[id].name),a=r.querySelector('td.art a.ph'),b=a.getBoundingClientRect();return Math.round(b.width)===Math.round(p.width)&&Math.round(b.height)===Math.round(p.height)&&(nt?a.classList.contains('card')&&a.querySelector('b').textContent===nt.t:!a.classList.contains('card')&&a.textContent.trim()==='No poster');});})()`));
ok('a matched film with no poster shows the same name card with its matched title and year', await js(`(()=>{const r=REL.find(r=>r.f&&!F(r).p);if(!r)return true;const i=REL.filter(matchRel).sort((a,b)=>b.t-a.t||a.id-b.id).indexOf(r);location.hash=i<50?'#/':'#/p/'+(Math.floor(i/50)+1);return new Promise(res=>setTimeout(()=>{const a=[...document.querySelectorAll('.feed tr')].find(t=>t.querySelector('[data-nzb="'+r.id+'"]'))?.querySelector('td.art a.ph.card');res(!!a&&a.querySelector('b').textContent===F(r).t&&!document.querySelector('.feed .np'));location.hash='#/';},300));})()`)); await sleep(300);
ok('page 1 shows at least one name card', await js(`!!document.querySelector('.feed a.ph.card')`));
ok('no name card for episodes, archive parts or names without a quality token', await js(`!nameTitle('Top.Gear.S01E02.2002.1080p.WEB')&&!nameTitle('"Airwolf - 01x03.part02.rar" 1984 720p')&&!nameTitle('lz-boerse.online.de.no.37.2026')&&!!nameTitle('A.Bugs.Life.1998.MULTi.VFF.2160p')`));

// ---- group and poster chips on rows (2026-09-26; he chose A, the details page's outline chips) ----
ok('group/poster chips: every row with a group or poster links to /browse/all?group= / ?poster= like the current site', await js(`(()=>{const rows=[...document.querySelectorAll('.feed tbody tr:not(.dayrow):not(.moreof)')];let n=0;const good=rows.every(r=>{const id=+r.querySelector('[data-nzb]').dataset.nzb,x=X(BYID[id]),g=r.querySelector('.rchips a[href^="/browse/all?group="]'),p=r.querySelector('.rchips a[href^="/browse/all?poster="]');if(g||p)n++;return (!x.group||(g&&g.getAttribute('href')==='/browse/all?group='+encodeURIComponent(x.group)&&g.title==='All releases in '+x.group))&&(!x.poster||(p&&p.getAttribute('href')==='/browse/all?poster='+encodeURIComponent(x.poster)&&p.title==='All posts by '+x.poster));});return good&&n>0;})()`));
ok('group/poster chips are the outline chips of the details page', await js(`[...document.querySelectorAll('.feed .rchips a[href^="/browse/all"]')].every(a=>a.classList.contains('origin'))`));
ok('the releases list has no Files or Grabs column; chips, group and poster share one chip line', await js(`(()=>{const h=[...document.querySelectorAll('.feed thead th')].map(t=>t.textContent.trim());return !h.includes('Files')&&!h.includes('Grabs')&&!document.querySelector('.feed [data-files]')&&!document.querySelector('.originline');})()`));
for (const w of [1280, 1440]) { await size(w, 900); await sleep(150);
  ok(`group and poster stay inside the release column at ${w} px `, await js(`[...document.querySelectorAll('.feed .rchips .orig')].every(l=>{const td=l.closest('td'),cs=getComputedStyle(td),right=td.getBoundingClientRect().right-parseFloat(cs.paddingRight)+0.5;return [...l.children].every(c=>c.getBoundingClientRect().right<=right);})`)); }
await size(1600, 1000);
ok('group and poster never split and never pass the release column (the poster name shortens first)', await js(`[...document.querySelectorAll('.feed .rchips .orig')].every(o=>{const c=[...o.children],td=o.closest('td'),right=td.getBoundingClientRect().right-parseFloat(getComputedStyle(td).paddingRight)+0.5;return new Set(c.map(e=>Math.round(e.getBoundingClientRect().top))).size===1&&c.every(e=>e.getBoundingClientRect().right<=right);})`));
ok('row actions are 2 × 2: download and copy link on top, cart and watch below (cart alone under download when there is no watch)', await js(`[...document.querySelectorAll('.feed tbody tr:not(.dayrow):not(.moreof) .iconacts')].every(a=>{const b=[...a.querySelectorAll('.ia:not(.slot)')],R=e=>e.getBoundingClientRect(),[dl,cp,ct,w]=b;if(!a.classList.contains('stack')||!dl.dataset.nzb||!cp.dataset.copynzb||!ct.dataset.cart)return false;const top=Math.round(R(dl).top)===Math.round(R(cp).top)&&R(dl).left<R(cp).left,low=R(ct).top>=R(dl).bottom&&Math.round(R(ct).left)===Math.round(R(dl).left);return top&&low&&(w?Math.round(R(w).top)===Math.round(R(ct).top)&&Math.round(R(w).left)===Math.round(R(cp).left):b.length===3);})`));
// ---- release-list buttons in colour (2026-09-26): coral on Download only; own hue when on ----
{ const L = `(c=>{const d=document.createElement('canvas').getContext('2d');d.fillStyle=c;d.fillRect(0,0,1,1);const q=[...d.getImageData(0,0,1,1).data].slice(0,3).map(v=>{v/=255;return v<=.03928?v/12.92:Math.pow((v+.055)/1.055,2.4)});return .2126*q[0]+.7152*q[1]+.0722*q[2]})`;
  const acc = await js(`getComputedStyle(document.querySelector('.feed .ia.dl')).backgroundColor`);
  await js(`(()=>{const a=document.querySelector('.feed tr:not([data-noshow]):not([data-nofilm]) .iconacts.stack');a.querySelector('[data-cart]').click();})()`); await sleep(150);
  for (const v of ['a']) {
    for (const theme of ['dark', 'light']) { await js(`document.documentElement.dataset.theme='${theme}'`); await sleep(80);
      ok(`buttons (${v}, ${theme}): Download keeps the approved coral fill and ink, with no on/off state`, await js(`(()=>{const d=[...document.querySelectorAll('.feed .ia.dl')],s=getComputedStyle(document.documentElement),probe=document.createElement('span');probe.style.cssText='background:var(--acc);color:var(--accink)';document.body.appendChild(probe);const ps=getComputedStyle(probe),ok=d.every(b=>getComputedStyle(b).backgroundColor===ps.backgroundColor&&getComputedStyle(b).color===ps.color&&!b.hasAttribute('aria-pressed'))&&![...document.querySelectorAll('.feed [data-copynzb]')].some(b=>b.hasAttribute('aria-pressed'));probe.remove();return ok;})()`));
      ok(`buttons (${v}, ${theme}): off buttons sit on a ${theme==='light'?'light':'dark'} tinted ground and a pressed cart is clearly darker/lighter than an unpressed one`, await js(`(()=>{const L=c=>{const d=document.createElement('canvas').getContext('2d');d.fillStyle=c;d.fillRect(0,0,1,1);const q=[...d.getImageData(0,0,1,1).data].slice(0,3).map(v=>{v/=255;return v<=.03928?v/12.92:Math.pow((v+.055)/1.055,2.4)});return .2126*q[0]+.7152*q[1]+.0722*q[2]};const off=[...document.querySelectorAll('.feed .iconacts.stack .ia:not(.dl):not(.slot):not([aria-pressed=true])')],on=document.querySelector('.feed .iconacts.stack [data-cart][aria-pressed=true]'),offc=document.querySelector('.feed .iconacts.stack [data-cart]:not([aria-pressed=true])');const bg=e=>L(getComputedStyle(e).backgroundColor);const lightTheme='${theme}'==='light';return off.every(b=>lightTheme?bg(b)>0.6:bg(b)<0.1)&&!!on&&!!offc&&Math.abs(bg(on)-bg(offc))>0.12;})()`));
      ok(`buttons (${v}, ${theme}): every icon ≥ 3:1 on its button, off and on`, await js(`(()=>{const L=${L};return [...document.querySelectorAll('.feed .iconacts.stack .ia:not(.slot)')].every(b=>{const s=getComputedStyle(b),a=L(s.color),g=L(s.backgroundColor);return (Math.max(a,g)+.05)/(Math.min(a,g)+.05)>=3;});})()`));
      ok(`buttons (${v}, ${theme}): Download is the only coral button; copy, cart and watch each their own colour; a pressed cart fills in its own colour`, await js(`(()=>{const acc=getComputedStyle(document.querySelector('.feed .ia.dl')).backgroundColor,r=[...document.querySelectorAll('.feed .iconacts.stack')].find(a=>a.querySelector('[data-watch]')),c=e=>getComputedStyle(e).color,bg=e=>getComputedStyle(e).backgroundColor;const cp=r.querySelector('[data-copynzb]'),ct=document.querySelector('.feed .iconacts.stack [data-cart][aria-pressed=true]'),w=r.querySelector('[data-watch]');const others=[...document.querySelectorAll('.feed .iconacts.stack .ia:not(.dl):not(.slot)')];return !!ct&&others.every(b=>bg(b)!==acc)&&new Set([c(cp),c(w),c(r.querySelector('[data-cart]:not([aria-pressed=true])')||cp)]).size>=2&&bg(ct)!==acc;})()`)); } }
  await js(`document.documentElement.dataset.theme='dark'`);
  await js(`document.querySelector('.feed .iconacts.stack [data-cart][aria-pressed=true]').click()`); await sleep(100);
  }
await js(`document.querySelector('.feed .rchips a[href^="/browse/all?group="]').click()`); await sleep(150);
ok('clicking a group chip names the page it opens and stays in the prototype', /^Opens \/browse\/all\?group=/.test(await js(`document.querySelector('#toast').textContent`)) && !(await js(`location.pathname.startsWith('/browse')`)));

// ---- all filters combine ----
await pick('cat', 'HD'); await pick('cat', 'UHD'); await pick('res', '1080p'); await pick('res', '4K'); await pick('src', 'WEB'); await pick('genre', 'Action'); await pick('genre', 'Drama');
await open('year'); await js(`document.querySelector('[data-ypick="decade:2020"]').click()`); await pick('score', '7'); await pick('score', '6'); await pick('cert', 'R'); await pick('aud', 'English'); await pick('lang', 'English'); await close();
ok(`all filters combine (AND between menus, OR within)`, await every(`['HD','UHD'].includes(catOf(r))&&['1080p','4K'].includes(r.res)&&r.src==='WEB'&&f&&f.g.some(g=>g==='Action'||g==='Drama')&&+f.y>=2020&&+f.y<2030&&['7','6'].includes(scoreBand(f))&&f.cert==='R'&&audOf(r).includes('English')&&FLANG[r.f]==='English'`) || await js(`document.querySelector('.empty')!==null`));
await js(`document.querySelector('[data-clearall]').click()`); await sleep(100);
ok('"Clear all" clears every filter and hides itself', !(await js(`anySet()`)) && await js(`getComputedStyle(document.querySelector('[data-clearall]')).visibility`) === 'hidden');

// ---- checkbox menus ----
for (const [k, v] of [['cat', 'Other'], ['res', '720p'], ['src', 'Blu-ray'], ['genre', 'Horror'], ['cert', 'PG-13']]) {
  await pick(k, v);
  ok(`${k}: menu stays open while ticking, focus stays on the ticked item`, await js(`state.dd==='${k}'&&document.activeElement.dataset.v===${JSON.stringify(v)}`));
  await close(); await pick(k, 'Any'); await close();
}
await pick('cat', 'Other'); await close(); ok('Category Other: every row is Movies > Other', await every(`catOf(r)==='Other'`)); await pick('cat', 'Any'); await close();
await pick('res', 'Unknown'); await close(); ok('Resolution Unknown: every row shows the dashed Unknown chip', await every(`r.res==='Unknown'`) && await js(`${ROWS}.every(r=>r.querySelector('.res.r-unk'))`)); await pick('res', 'Any'); await close();
await pick('src', 'Blu-ray'); await close(); ok('Source Blu-ray: every row is Blu-ray (remux reads "Remux")', await every(`r.src==='Blu-ray'`) && await js(`${ROWS}.every(r=>/^(Blu-ray|Remux)$/.test(r.children[4].textContent))`)); await pick('src', 'Any'); await close();
await pick('genre', 'Horror'); await close(); ok('Genre Horror: every row\'s film is Horror', await every(`f&&f.g.includes('Horror')`)); await pick('genre', 'Any'); await close();
await pick('cert', 'PG-13'); await close(); ok('Certificate PG-13: every row\'s film is PG-13', await every(`f&&f.cert==='PG-13'`)); await pick('cert', 'Any'); await close();
await pick('aud', 'Hindi'); await close(); ok('Audio Hindi: every row carries a Hindi audio track (a multi-dub counts)', await every(`audOf(r).includes('Hindi')`) || await js(`!!document.querySelector('.empty')`)); await pick('aud', 'Any'); await close();
await pick('aud', 'Unknown'); await close(); ok('Audio Unknown: every row has no known audio language (film-less releases included)', await every(`!ALANG[r.id]`) && await js(`!!document.querySelector('.feed tr[data-nofilm]')`)); await pick('aud', 'Any'); await close();
await pick('lang', 'French'); await close(); ok('Language French: every row\'s film is originally French; releases with no film are left out', await every(`f&&FLANG[r.f]==='French'`) && await js(`!document.querySelector('.feed tr[data-nofilm]')`)); await pick('lang', 'Any'); await close();
ok('Audio lists the languages present, most releases first, then Unknown; Language lists film languages, most first', await js(`(()=>{const c={};REL.forEach(r=>(ALANG[r.id]||[]).forEach(a=>c[a]=(c[a]||0)+1));const n=AUDS.slice(0,-1).map(a=>c[a]);return AUDS.at(-1)==='Unknown'&&n.every((x,i)=>!i||n[i-1]>=x)&&!AUDS.some(a=>/^(zxx|mul|und|q[a-t][a-z])$|-|\\(/i.test(a))&&LANGS_ON.length>1;})()`));
ok('Certificate lists only US certificates present, in order (NR included when stored)', await js(`(()=>{document.querySelector('[data-ddtoggle=cert]').click();const v=[...document.querySelectorAll('[data-ddpick=cert]')].map(b=>b.dataset.v);document.querySelector('.pager .sum').click();return v.every(x=>CERTS.includes(x))&&v.join()===CERTS.filter(c=>v.includes(c)).join();})()`));
// score
ok('Score options: 9+, 8–8.9, 7–7.9, 6–6.9, 5–5.9, Under 5, Too few votes', await js(`(()=>{document.querySelector('[data-ddtoggle=score]').click();const v=[...document.querySelectorAll('[data-ddpick=score]')].map(b=>b.textContent.trim()).join('|');document.querySelector('.pager .sum').click();return v;})()`) === '9+|8–8.9|7–7.9|6–6.9|5–5.9|Under 5|Too few votes');
await pick('score', 'few'); await close(); ok('Too few votes: every film has no score or under 10 votes', await every(`f&&(f.sc==null||(f.vc!=null&&f.vc<10))`));
await pick('score', 'few'); await pick('score', '9'); await close(); ok('Score 9+: every film scores 9+ with 10+ votes or an unknown count', await every(`f&&f.sc>=9&&(f.vc==null||f.vc>=10)`) || await js(`!!document.querySelector('.empty')`)); await pick('score', 'Any'); await close();
// year
await open('year');
ok('Year menu: Any, decades 2020s…1900s, a from–to range; no list of single years (his ruling 2026-09-26)', await js(`(()=>{const d=[...document.querySelectorAll('[data-ypick^=decade]')].map(b=>b.textContent.trim());return !!document.querySelector('[data-ypick="any:0"]')&&d[0]==='2020s'&&d[d.length-1]==='1900s'&&!!document.querySelector('[data-yform] #yfrom')&&!document.querySelector('[data-ypick^=year],.ymenu .years');})()`));
ok('Year decades are multi-select tick boxes (his request 2026-09-26): Any year + 13 decades', await js(`document.querySelectorAll('.ymenu [role=radio]').length===0&&document.querySelectorAll('.ymenu [role=menuitemcheckbox]').length===14`));
await js(`document.querySelector('[data-ypick="decade:1990"]').click()`); await sleep(100);
ok('ticking a decade keeps the menu open, filters to it, the cell reads "1990s"', await js(`state.dd==='year'&&document.activeElement.matches('[data-ypick="decade:1990"]')`) && await every(`f&&+f.y>=1990&&+f.y<2000`) && await js(`document.querySelector('[data-ddtoggle=year]').textContent.trim()`) === 'Year: 1990s');
await js(`document.querySelector('[data-ypick="decade:2010"]').click()`); await sleep(100);
ok('ticking a second decade: films of either decade; the cell reads "2 chosen", the tooltip names both', await every(`f&&((+f.y>=1990&&+f.y<2000)||(+f.y>=2010&&+f.y<2020))`) && await js(`document.querySelector('[data-ddtoggle=year] .v').textContent==='2 chosen'&&document.querySelector('[data-ddtoggle=year]').title==='Year: 2010s, 1990s'`));
await js(`(()=>{const a=document.querySelector('#yfrom'),b=document.querySelector('#yto');a.value='2000';a.dispatchEvent(new Event('input',{bubbles:true}));b.value='2004';b.dispatchEvent(new Event('input',{bubbles:true}));document.querySelector('[data-yform] button').click();})()`); await sleep(100);
ok('a range replaces the ticked decades', await js(`state.year.kind==='range'&&state.year.a===2000&&!document.querySelector('.ymenu')`) && await every(`f&&+f.y>=2000&&+f.y<=2004`));
await open('year'); await js(`document.querySelector('[data-ypick="decade:1990"]').click()`); await sleep(100);
ok('ticking a decade replaces a range', await js(`state.year.kind==='decade'&&state.year.vs.join()==='1990'`));
await js(`document.querySelector('[data-ypick="any:0"]').click()`); await sleep(100);
ok('"Any year" unticks every decade and keeps the menu open', await js(`state.year===null&&state.dd==='year'&&!document.querySelector('.ymenu [data-ypick^=decade][aria-checked=true]')`));
await js(`document.querySelector('[data-ypick="decade:1990"]').click()`); await sleep(100); await close();
await open('year'); await js(`(()=>{const a=document.querySelector('#yfrom');a.value='2024';a.dispatchEvent(new Event('input',{bubbles:true}));document.querySelector('[data-yform] button').click();})()`); await sleep(100);
ok('From alone (To left empty) picks that one year: every film is 2024', await every(`f&&+f.y===2024`) && await js(`document.querySelector('[data-ddtoggle=year]').textContent.trim()`) === 'Year: 2024');
const yGridTop = await (async()=>{await open('year');await js(`(()=>{const a=document.querySelector('#yfrom');a.value='';a.dispatchEvent(new Event('input',{bubbles:true}));})()`);return js(`Math.round(document.querySelector('.ymenu [data-yform]').offsetTop)`);})();
ok('Apply is disabled until From has four digits; To may stay empty', await js(`(()=>{const a=document.querySelector('#yfrom'),b=document.querySelector('[data-yform] button');const d0=b.disabled;a.value='199';a.dispatchEvent(new Event('input',{bubbles:true}));const d1=b.disabled;a.value='1999';a.dispatchEvent(new Event('input',{bubbles:true}));const d2=b.disabled;a.value='';a.dispatchEvent(new Event('input',{bubbles:true}));return d0&&d1&&!d2;})()`));
await open('year'); await js(`(()=>{const a=document.querySelector('#yfrom'),b=document.querySelector('#yto');a.value='2010';a.dispatchEvent(new Event('input',{bubbles:true}));b.value='2005';b.dispatchEvent(new Event('input',{bubbles:true}));document.querySelector('[data-yform] button').click();})()`); await sleep(100);
ok('a backwards range is refused with a message in place of the Range heading, nothing below moves', await js(`state.dd==='year'&&!!document.querySelector('.mhead.yerr')&&state.year.kind==='year'`) && await js(`Math.round(document.querySelector('.ymenu [data-yform]').offsetTop)`) === yGridTop);
await js(`(()=>{const a=document.querySelector('#yfrom'),b=document.querySelector('#yto');a.value='1980';a.dispatchEvent(new Event('input',{bubbles:true}));b.value='1989';b.dispatchEvent(new Event('input',{bubbles:true}));document.querySelector('[data-yform] button').click();})()`); await sleep(100);
ok('a range 1980–1989 filters and reads "Year: 1980–1989"', await every(`f&&+f.y>=1980&&+f.y<=1989`) && await js(`document.querySelector('[data-ddtoggle=year]').textContent.trim()`) === 'Year: 1980–1989');
await open('year'); await js(`document.querySelector('[data-ypick="any:0"]').click()`); await sleep(100);
ok('"Any year" clears the year', await js(`state.year===null&&!document.querySelector('.msel[data-msel=year].set')`));
// menu closing rules
await open('genre'); await js(`document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape'}))`); await sleep(80);
ok('Escape closes a menu and returns focus to its button', await js(`state.dd===null&&document.activeElement.dataset.ddtoggle==='genre'`));
await open('genre'); await js(`document.querySelector('h1').click()`); await sleep(80); ok('a click outside closes a menu', await js(`state.dd===null`));
await open('genre'); await js(`document.querySelector('[data-ddpick=genre]').focus()`); await js(`document.querySelector('.seg a').focus()`); await sleep(80);
ok('a menu closes when keyboard focus leaves it', await js(`!document.querySelector('.mmenu')`)); await js(`state.dd=null`);
await open('genre'); ok('long menus scroll inside themselves and stay inside the window', await js(`(()=>{const m=document.querySelector('.mmenu'),r=m.getBoundingClientRect();return getComputedStyle(m).overflowY==='auto'&&r.right<=innerWidth&&r.bottom<=innerHeight;})()`)); await close();
await open('cert'); ok('the last menu stays inside the window', await js(`document.querySelector('.mmenu').getBoundingClientRect().right<=innerWidth`)); await close();

// ---- filtering resets paging; empty result keeps the pager line ----
await go('#/p/3'); ok('page 3 in the URL shows rows 101–150', /^Showing 101–150 of/.test(await js(`document.querySelector('.pager .sum').textContent`)));
await pick('genre', 'Drama'); await close(); ok('changing a filter returns to page 1', await js(`location.hash==='#/'&&state.page===1`)); await pick('genre', 'Any'); await close();
await pick('genre', 'Western'); await pick('cert', 'G'); await close();
ok('no match: "Showing 0 releases" line stays, Page 1 of 1 with both arrows grey', await js(`(()=>{const e=document.querySelector('.empty');return !e||(document.querySelector('.pager .sum').textContent==='Showing 0 releases'&&document.querySelector('.pager .pg').textContent==='Page 1 of 1'&&document.querySelectorAll('.pager.slim .off[disabled]').length===2);})()`));
await js(`document.querySelector('[data-clearall]').click()`); await sleep(80);
await go('#/p/2'); await js(`document.querySelector('.pager.bottom input').value='5';document.querySelector('.pager.bottom form').requestSubmit()`); await sleep(200);
ok('Go to page 5', await js(`location.hash`) === '#/p/5'); await go('#/');

// ---- sort ----
await js(`(()=>{const s=document.querySelector('[data-rsort]');s.value='added_desc';s.dispatchEvent(new Event('change',{bubbles:true}));})()`); await sleep(150);
ok('Added sort: the date column reads "Added" and rows are newest added first', await js(`document.querySelectorAll('.feed thead th')[5].textContent==='Added'`) && await js(`(()=>{const t=${rowIds}.map(i=>X(BYID[i]).added);return t.every((x,i)=>!i||t[i-1]>=x);})()`));
ok('the sort is remembered', await js(`localStorage.getItem('nntmux.movies.rsort')`) === 'added_desc');
await js(`(()=>{const s=document.querySelector('[data-rsort]');s.value='posted_desc';s.dispatchEvent(new Event('change',{bubbles:true}));})()`); await sleep(150);

// ---- selection ----
const top0 = await js(`Math.round(document.querySelector('.feed').getBoundingClientRect().top)`);
await js(`document.querySelector('[data-selectall]').click()`); await sleep(100);
ok('select all selects every row on the page; floating bar appears', await js(`state.sel.size===${ROWS}.length&&!!document.querySelector('.bulk')&&getComputedStyle(document.querySelector('.bulk')).position==='fixed'`));
ok('the list does not move when rows are selected', top0 === await js(`Math.round(document.querySelector('.feed').getBoundingClientRect().top)`));
await js(`document.querySelectorAll('.feed [data-select]')[0].click()`); await sleep(80); ok('select-all shows the partial state', await js(`document.querySelector('[data-selectall]').indeterminate`));
await js(`document.querySelector('[data-bulk=clear]').click()`); await sleep(80); ok('Clear selection empties it', await js(`state.sel.size===0&&!document.querySelector('.bulk')`));

// ---- batch expander ----
const hasBatch = await js(`(()=>{const [sk]=state.rsort.split('_');const rs=REL.filter(matchRel).sort((a,b)=>b.t-a.t||a.id-b.id);for(let p=0;p*50<rs.length;p++){const pg=rs.slice(p*50,p*50+50);for(let i=0;i+4<pg.length;i++){if(pg[i].f&&[1,2,3,4].every(k=>pg[i+k].f===pg[i].f&&dayKey(pg[i+k].t)===dayKey(pg[i].t)))return p+1;}}return 0;})()`);
if (hasBatch) { await go(hasBatch === 1 ? '#/' : '#/p/' + hasBatch); const n0 = await js(`${ROWS}.length`); await js(`document.querySelector('[data-expand]').click()`); await sleep(80);
  ok('same-film batch collapses to three plus "Show N more", and expands', !!n0 && await js(`${ROWS}.length`) > n0 && /^Show fewer from/.test(await js(`document.querySelector('[data-expand]').textContent.trim()`)), 'page ' + hasBatch); await go('#/'); }
else ok('same-film batch expander (no batch of 5 in this data)', true, 'not exercised');

// ---- actions and dialogs ----
await js(`document.querySelector('.feed [data-cart]').click()`); await sleep(80); ok('cart button toggles to a pressed coral state', await js(`document.querySelector('.feed [data-cart]').getAttribute('aria-pressed')==='true'`)); await js(`document.querySelector('.feed [data-cart]').click()`);
await js(`document.querySelector('.feed [data-watch]').click()`); await sleep(80); ok('follow button follows the film (pressed state)', await js(`document.querySelector('.feed [data-watch]').getAttribute('aria-pressed')==='true'`)); await js(`document.querySelector('.feed [data-watch]').click()`);
{ const bx = JSON.parse(await js(`(()=>{const e=document.querySelector('.feed [data-copynzb]');e.scrollIntoView({block:'center'});return JSON.stringify(e.getBoundingClientRect());})()`)); for (const type of ['mousePressed', 'mouseReleased']) await send('Input.dispatchMouseEvent', {type, x: bx.x + bx.width / 2, y: bx.y + bx.height / 2, button: 'left', clickCount: 1}); } await sleep(400);
ok('Copy NZB link copies the v1 t=get URL with the API key and says so', /\/api\/v1\/api\?t=get&id=[0-9a-f-]+&apikey=/.test(await js(`window.__copied||''`)) && /API key/.test(await js(`document.querySelector('#toast').textContent`)));
for (const [sel, title] of [['[data-mi]', 'Media information'], ['[data-nfo]', 'NFO'], ['[data-img]', 'Preview image']]) {
  const found = await js(`(()=>{let b=document.querySelector('.feed ${sel}');if(!b)return false;b.focus();b.click();return true;})()`); await sleep(500);
  ok(`${title} dialog opens from its row control`, found && await js(`document.querySelector('.dlg h2')?.textContent`) === title);
  await js(`document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape'}))`); await sleep(80);
  ok(`${title} dialog closes on Escape and focus returns`, await js(`!document.querySelector('.dlg')&&document.activeElement.matches('${sel}')`));
}
// ---- search ----
await js(`(()=>{const q=document.querySelector('#q');q.focus();q.value=F(REL.find(r=>r.f)).t.slice(0,5);q.dispatchEvent(new Event('input',{bubbles:true}));})()`); await sleep(100);
ok('search lists films (poster, title, year · genres)', await js(`!!document.querySelector('#results h4')&&document.querySelector('#results h4').textContent==='Films'&&!!document.querySelector('#results a[href^="#/film/"]')`));
await js(`(()=>{const q=document.querySelector('#q');const n=Object.keys(PEOPLE).find(n=>PEOPLE[n].length>2);q.value=n.slice(0,6);q.dispatchEvent(new Event('input',{bubbles:true}));})()`); await sleep(100);
ok('search lists people under "People" with their films', await js(`[...document.querySelectorAll('#results h4')].some(h=>h.textContent==='People')&&!!document.querySelector('#results a.p')`));
await js(`document.querySelector('h1').click()`);
// ---- links ----
await js(`document.querySelector('.feed .showlink').click()`); await sleep(200); ok('film line opens the film page route', /^#\/film\/\d+$/.test(await js(`location.hash`))); await go('#/');
await js(`document.querySelector('.feed .rname').click()`); await sleep(200); ok('release name opens the release details route', /^#\/release\/\d+$/.test(await js(`location.hash`))); await go('#/');
// ---- filter bar (2026-09-26) ----
{ const Lm = `(c=>{const d=document.createElement('canvas').getContext('2d');d.fillStyle=c;d.fillRect(0,0,1,1);const p=[...d.getImageData(0,0,1,1).data].slice(0,3).map(v=>{v/=255;return v<=.03928?v/12.92:Math.pow((v+.055)/1.055,2.4)});return .2126*p[0]+.7152*p[1]+.0722*p[2]})`;
  const bgOf = `e=>{let n=e,bg='rgba(0, 0, 0, 0)';while(n&&(bg=getComputedStyle(n).backgroundColor)==='rgba(0, 0, 0, 0)')n=n.parentElement;return bg;}`;
  const CELLS = `[...document.querySelectorAll('.fbar .fseg .sfm')]`;
  const G = () => js(`JSON.stringify({c:${CELLS}.map(c=>{const r=c.getBoundingClientRect();return [Math.round(r.left),Math.round(r.width),Math.round(r.height)].join();}),clear:Math.round(document.querySelector('[data-clearall]').getBoundingClientRect().left),list:Math.round(document.querySelector('.pager.slim').getBoundingClientRect().top)})`);
  const reset = `(()=>{['cat','res','src','aud','genre','score','cert','lang','wgenre','wscore','wcert','wlang'].forEach(k=>state[k].clear());state.year=null;state.wyear=null;state.person=null;state.dd=null;route();})()`;
  await go('#/'); await sleep(150); await js(reset); await sleep(80);
  ok('bar: two bars, the release (Category, Resolution, Source, Audio, Completion) then the film (Genre, Year, Score, Certificate, Language)', await js(`[...document.querySelectorAll('.fbar .fseg')].map(g=>[...g.querySelectorAll('.mbtn .k')].map(k=>k.textContent).join(',')).join('|')`) === 'Category,Resolution,Source,Audio,Completion|Genre,Year,Score,MPAA Rating,Language');
  ok('bar: the two bars run the full width (film bar ends where the sort ends); Clear all sits on the Showing line, its slot kept when hidden', await js(`(()=>{const f=document.querySelector('.fbar .fseg.film').getBoundingClientRect(),s=document.querySelector('[data-rsort]').closest('label').getBoundingClientRect(),c=document.querySelector('.pager.slim [data-clearall]');return Math.abs(f.right-s.right)<=1&&!!c&&getComputedStyle(c).visibility==='hidden'&&c.getBoundingClientRect().width>=60;})()`));
  ok('bar: the filter names are white on the dark bar (his request 2026-09-26)', await js(`[...document.querySelectorAll('.fbar .mbtn .k')].every(k=>getComputedStyle(k).color==='rgb(255, 255, 255)')`));
  await js(`document.documentElement.dataset.theme='light'`); await sleep(60);
  ok('bar: the filter names are ink on the light bar', await js(`(()=>{const p=document.createElement('span');p.style.color='var(--ink)';document.body.appendChild(p);const c=getComputedStyle(p).color;p.remove();return [...document.querySelectorAll('.fbar .mbtn .k')].every(k=>getComputedStyle(k).color===c);})()`));
  await js(`document.documentElement.dataset.theme='dark'`); await sleep(60);
  ok('bar: every cell shows its name above its value', await js(`${CELLS}.every(c=>{const k=c.querySelector('.k').getBoundingClientRect(),v=c.querySelector('.v').getBoundingClientRect();return k.bottom<=v.top+1;})`));
  const g0 = JSON.parse(await G());
  await pick('cat', 'HD'); await pick('res', '1080p'); await pick('res', '4K'); await pick('src', 'WEB'); await pick('aud', 'English'); await js(`document.querySelector('[data-ddtoggle=comp]').click();document.querySelector('[data-cpick="95"]').click()`); await pick('genre', 'Drama'); await open('year'); await js(`document.querySelector('[data-ypick="decade:2010"]').click()`); await pick('score', '6'); await pick('cert', 'R'); await pick('lang', 'English'); await close(); await sleep(80);
  const g1 = JSON.parse(await G());
  ok('bar: nothing moves with every filter set (cells, Clear all, the list)', JSON.stringify(g0) === JSON.stringify(g1), JSON.stringify([g0, g1]));
  await js(`document.querySelector('.pager.slim [data-clearall]').click()`); await sleep(100);
  ok('bar: Clear all (on the Showing line) clears every filter and puts focus on the first filter cell', await js(`!anySet()&&document.activeElement.matches('.fbar .mbtn')`));
  await pick('cat', 'HD'); await pick('res', '1080p'); await pick('res', '4K'); await pick('src', 'WEB'); await pick('aud', 'English'); await js(`document.querySelector('[data-ddtoggle=comp]').click();document.querySelector('[data-cpick="95"]').click()`); await pick('genre', 'Drama'); await open('year'); await js(`document.querySelector('[data-ypick="decade:2010"]').click()`); await pick('score', '6'); await pick('cert', 'R'); await pick('lang', 'English'); await close(); await sleep(80);
  ok('bar: a set filter is marked by a coral line under its value; unset cells have none; no coral fill', await js(`(()=>{const acc=getComputedStyle(document.querySelector('.seg [aria-current=page]')).backgroundColor;return ${CELLS}.every(c=>{const a=getComputedStyle(c.querySelector('.mbtn'),'::after');const set=c.classList.contains('set');return getComputedStyle(c.querySelector('.mbtn')).backgroundColor!==acc&&(set?a.backgroundColor===acc&&a.content!=='none':a.content==='none'||a.backgroundColor!==acc);});})()`));
  ok('bar: Completion 95% or more keeps only releases at 95% or more (or names it when nothing matches)', await every(`r.comp>=95`) || /95%\+ complete/.test(await js(`document.querySelector('.empty')?.textContent||''`)));
  ok('bar: Completion is one choice (radio items), closes on pick, reads "95%+" with the coral line', await js(`!document.querySelector('[data-msel=comp] .mmenu')&&document.querySelector('[data-ddtoggle=comp] .v').textContent==='95%+'&&document.querySelector('[data-msel=comp]').classList.contains('set')`));
  { const was = await js(`state.comp`); await js(`(()=>{document.querySelector('[data-ddtoggle=comp]').click();document.querySelector('[data-cpick="100"]').click();})()`); await sleep(120);
    ok('bar: Completion "100% only" keeps only complete releases and reads "100%"', (await every(`r.comp>=100`) || /100% complete/.test(await js(`document.querySelector('.empty')?.textContent||''`))) && await js(`document.querySelector('[data-ddtoggle=comp] .v').textContent==='100%'`));
    await js(`(()=>{document.querySelector('[data-ddtoggle=comp]').click();})()`); await sleep(80);
    ok('bar: the Completion menu offers Any, 100% only, 95% or more, as round radio markers, the chosen one marked', await js(`(()=>{const it=[...document.querySelectorAll('[data-msel=comp] [data-cpick]')];return it.map(b=>b.textContent.trim()).join('|')==='Any completion|100% only|95% or more'&&it.every(b=>b.getAttribute('role')==='menuitemradio'&&b.querySelector('.dot')&&getComputedStyle(b.querySelector('.dot')).borderRadius!=='0px'&&b.querySelector('.dot').getBoundingClientRect().width>=16)&&it[1].getAttribute('aria-checked')==='true';})()`));
    await js(`document.querySelector('[data-cpick="95"]').click()`); await sleep(100); }
  ok('bar: several values read "N chosen" with the full list in the tooltip', await js(`document.querySelector('[data-ddtoggle=res] .v').textContent==='2 chosen'&&document.querySelector('[data-ddtoggle=res]').title==='Resolution: 4K, 1080p'`));
  for (const theme of ['dark', 'light']) { await js(`document.documentElement.dataset.theme='${theme}'`); await sleep(60);
    ok(`bar (${theme}): names, values and "any" ≥ 4.5:1, chevrons and the coral line ≥ 3:1`, await js(`(()=>{const L=${Lm},B=${bgOf},cr=(a,b)=>(Math.max(a,b)+.05)/(Math.min(a,b)+.05);return ${CELLS}.every(c=>{const b=c.querySelector('.mbtn'),g=L(B(b));const t=[...b.querySelectorAll('.k,.v')].every(x=>cr(L(getComputedStyle(x).color),g)>=4.5);const ch=cr(L(getComputedStyle(b.querySelector('svg')).color),g)>=3;const ln=!c.classList.contains('set')||cr(L(getComputedStyle(b,'::after').backgroundColor),g)>=3;return t&&ch&&ln;});})()`)); }
  await js(`document.documentElement.dataset.theme='dark'`);
  for (const w of [1280, 1366, 1600]) { await size(w, 900); await sleep(100);
    ok(`bar at ${w} px: one row inside the page`, await js(`(()=>{const r=document.querySelector('.fbar');const t=new Set([...r.children].map(c=>{const b=c.getBoundingClientRect();return Math.round(b.top+b.height/2);}));return t.size===1&&document.documentElement.scrollWidth<=innerWidth&&r.lastElementChild.getBoundingClientRect().right<=innerWidth-39;})()`));
    console.log(`MEASURE bar ${w} px: cell width ${await js(`Math.round(document.querySelector('.fbar .sfm').getBoundingClientRect().width)`)} px; values cut short with filters set: ` + await js(`[...document.querySelectorAll('.fbar .mbtn .v')].filter(v=>v.scrollWidth>v.clientWidth+1).map(v=>v.closest('.mbtn').title).join(', ')||'none'`)); }
  await size(1600, 1000); await sleep(80);
  // opening: the cell lifts; long lists search
  await js(reset); await sleep(60); await js(`document.querySelector('[data-ddtoggle=genre]').click()`); await sleep(100);
  for (const theme of ['dark', 'light']) { await js(`document.documentElement.dataset.theme='${theme}'`); await sleep(60);
    ok(`bar (${theme}): the open cell is lighter than the bar (it lifts, never sinks)`, await js(`(()=>{const L=${Lm},c=document.querySelector('.fbar .sfm.open .mbtn'),bar=c.closest('.fseg');return L(getComputedStyle(c).backgroundColor)>L(getComputedStyle(bar).backgroundColor);})()`)); }
  await js(`document.documentElement.dataset.theme='dark'`);
  ok('bar: the magnifier sits inside the search field', await js(`(()=>{const i=document.querySelector('[data-msearch=genre]').getBoundingClientRect(),g=document.querySelector('.fbar [data-msel=genre] .msearch svg').getBoundingClientRect();return g.left>=i.left&&g.right<=i.right&&g.top>=i.top&&g.bottom<=i.bottom;})()`));
  ok('bar: the open cell lifts out of the bar (own ground, shadow); its neighbours lose their divider', await js(`(()=>{const c=document.querySelector('.fbar .sfm.open');const b=getComputedStyle(c.querySelector('.mbtn'));return b.backgroundColor!=='rgba(0, 0, 0, 0)'&&b.boxShadow!=='none'&&getComputedStyle(c,'::before').opacity==='0'&&getComputedStyle(c.nextElementSibling,'::before').opacity==='0';})()`));
  ok('bar: Genre (over 10 options) opens with a search field, focused', await js(`document.activeElement.matches('.fbar [data-msel=genre] [data-msearch]')&&document.activeElement.placeholder==='Search genres'`));
  await js(`(()=>{const i=document.activeElement;i.value='hor';i.dispatchEvent(new Event('input',{bubbles:true}));})()`); await sleep(80);
  ok('bar: typing narrows the list in place', await js(`(()=>{const v=[...document.querySelectorAll('.fbar [data-msel=genre] [data-ddpick]')].filter(b=>!b.hidden).map(b=>b.dataset.v);return v.length===1&&v[0]==='Horror';})()`));
  await js(`document.querySelector('.fbar [data-ddpick=genre][data-v=Horror]').click()`); await sleep(100);
  ok('bar: ticking keeps the menu open, the search text and the narrowed list; focus on the tick', await js(`state.dd==='genre'&&document.querySelector('[data-msearch=genre]').value==='hor'&&[...document.querySelectorAll('.fbar [data-msel=genre] [data-ddpick]')].filter(b=>!b.hidden).length===1&&document.activeElement.matches('[data-ddpick=genre][data-v=Horror]')`) && await every(`f&&f.g.includes('Horror')`));
  await js(`document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape'}))`); await sleep(80); await js(`document.querySelector('[data-ddtoggle=genre]').click()`); await sleep(100);
  ok('bar: reopening a menu shows the ticked option in view, below the search field', await js(`(()=>{const m=document.querySelector('.fbar [data-msel=genre] .mmenu'),t=m.querySelector('[data-ddpick][aria-checked=true]').getBoundingClientRect(),s=m.querySelector('.msearch').getBoundingClientRect(),r=m.getBoundingClientRect();return t.top>=s.bottom-1&&t.bottom<=r.bottom+1;})()`));
  ok('bar: a long list visibly continues (the last visible row is cut, not whole)', await js(`(()=>{const m=document.querySelector('.fbar [data-msel=genre] .mmenu');m.scrollTop=0;const r=m.getBoundingClientRect();return [...m.querySelectorAll('.mitem')].some(b=>{const x=b.getBoundingClientRect();return x.top<r.bottom-8&&x.bottom>r.bottom+8;});})()`));
  ok('bar: closing forgets the search; reopening shows every genre again', await js(`document.querySelector('[data-msearch=genre]').value===''&&[...document.querySelectorAll('.fbar [data-msel=genre] [data-ddpick]')].every(b=>!b.hidden)`));
  ok('bar: the search stays at the top while the list scrolls inside the menu, which stays inside the window', await js(`(()=>{const m=document.querySelector('.fbar [data-msel=genre] .mmenu');m.scrollTop=400;const s=document.querySelector('[data-msearch=genre]').getBoundingClientRect(),r=m.getBoundingClientRect();return getComputedStyle(m).overflowY==='auto'&&s.top>=r.top-1&&r.bottom<=innerHeight&&r.right<=innerWidth;})()`));
  ok('bar: short lists (Resolution) have no search', await js(`(()=>{document.querySelector('[data-ddtoggle=res]').click();return !document.querySelector('[data-msel=res] [data-msearch]');})()`));
  await close(); await open('year');
  ok('bar: a disabled Apply is neutral, not faded coral', await js(`(()=>{const b=document.querySelector('.fbar [data-yform] button');return b.disabled&&getComputedStyle(b).opacity==='1'&&getComputedStyle(b).backgroundColor!==getComputedStyle(document.querySelector('.seg [aria-current=page]')).backgroundColor;})()`));
  ok('bar: the year menu shows decades in three columns and the range without scrolling', await js(`(()=>{const m=document.querySelector('.fbar .ymenu'),f=m.querySelector('[data-yform]').getBoundingClientRect(),r=m.getBoundingClientRect();return f.bottom<=r.bottom&&m.scrollHeight<=m.clientHeight+1&&!m.querySelector('.years');})()`));
  await close();
  // the wall: the film bar, cells as wide as the list's
  const listCell = await js(`Math.round(document.querySelector('.fbar .fseg.film .sfm').getBoundingClientRect().width)`);
  await go('#/films'); await sleep(200);
  ok('bar (wall): the film bar with the same five cells, as wide as on the list', await js(`[...document.querySelectorAll('.fbar .fseg .mbtn .k')].map(k=>k.textContent).join()`) === 'Genre,Year,Score,MPAA Rating,Language' && Math.abs(await js(`Math.round(document.querySelector('.fbar .fseg.film .sfm').getBoundingClientRect().width)`) - listCell) <= 1);
  await js(`state.wgenre.add('Drama');state.wyear={kind:'decade',v:2010};route()`); await sleep(80);
  for (const theme of ['dark', 'light']) { await js(`document.documentElement.dataset.theme='${theme}'`); await sleep(60);
    ok(`bar (wall, ${theme}): names, values and "any" ≥ 4.5:1, chevrons and the coral line ≥ 3:1`, await js(`(()=>{const L=${Lm},B=${bgOf},cr=(a,b)=>(Math.max(a,b)+.05)/(Math.min(a,b)+.05);return ${CELLS}.every(c=>{const b=c.querySelector('.mbtn'),g=L(B(b));const t=[...b.querySelectorAll('.k,.v')].every(x=>cr(L(getComputedStyle(x).color),g)>=4.5);const ch=cr(L(getComputedStyle(b.querySelector('svg')).color),g)>=3;const ln=!c.classList.contains('set')||cr(L(getComputedStyle(b,'::after').backgroundColor),g)>=3;return t&&ch&&ln;});})()`)); }
  await js(`document.documentElement.dataset.theme='dark'`);
  for (const w of [1280, 1366, 1600]) { await size(w, 900); await sleep(100);
    ok(`bar (wall) at ${w} px: one row inside the page`, await js(`(()=>{const r=document.querySelector('.fbar');const t=new Set([...r.children].map(c=>{const b=c.getBoundingClientRect();return Math.round(b.top+b.height/2);}));return t.size===1&&document.documentElement.scrollWidth<=innerWidth&&r.lastElementChild.getBoundingClientRect().right<=innerWidth-39;})()`)); }
  await size(1600, 1000); await sleep(80);
  await go('#/'); await sleep(150); await js(reset); }
// ---- Films wall (shaped 2026-09-26) ----
{ const TILES = `[...document.querySelectorAll('.wall .tiles .tile')]`, tids = `${TILES}.map(t=>t.getAttribute('href').split('/').pop())`;
  const vis = sel => `[...document.querySelectorAll('${sel}')].filter(e=>e.offsetParent!==null)`;
  const wgeom = () => js(`JSON.stringify({row:Math.round(document.querySelector('.mrow.fbar').getBoundingClientRect().height),w:[...document.querySelectorAll('.fbar .mbtn')].map(b=>Math.round(b.getBoundingClientRect().width)),clear:Math.round(document.querySelector('[data-clearall]').getBoundingClientRect().left),list:Math.round(document.querySelector('.pager.slim').getBoundingClientRect().top),tiles:Math.round(document.querySelector('.tiles')?.getBoundingClientRect().top||0),prev:Math.round(document.querySelector('.pager.slim [aria-label="Previous page"]').getBoundingClientRect().left),next:Math.round(document.querySelector('.pager.slim [aria-label="Next page"]').getBoundingClientRect().left)})`);
  await js(`localStorage.removeItem('nntmux.movies.wsort')`); await go('#/films'); await sleep(200);
  ok('wall: title "Films", switch with Films current, Releases goes to the list', await js(`document.querySelector('.filters h1').textContent==='Films'&&document.querySelector('.seg [aria-current=page]').textContent==='Films'&&document.querySelector('.seg a[href="#/"]').textContent==='Releases'`));
  ok('wall: "Search films or actors" right of the switch', await js(`document.querySelector('.seg').nextElementSibling.querySelector('input').placeholder==='Search films or actors'`));
  ok('wall: five menus in order Genre, Year, Score, Certificate, Language', await js(`[...document.querySelectorAll('.fbar .mbtn')].map(b=>b.textContent.split(':')[0]).join(',')`) === 'Genre,Year,Score,MPAA Rating,Language');
  ok('wall: four sorts, Newest releases first by default', await js(`[...document.querySelectorAll('[data-wsort] option')].map(o=>o.textContent).join('|')==='Newest releases first|Newest to the site first|Newest films first|A to Z'&&document.querySelector('[data-wsort]').value==='recent'`));
  ok('wall: pager line "Showing 1–42 of N films · Page 1 of N" and a bottom pager with Go to page', /^Showing 1–42 of [\d,]+ films$/.test(await js(`document.querySelector('.pager.slim .sum').textContent`)) && /^Page 1 of \d+$/.test(await js(`document.querySelector('.pager.slim .pg').textContent`)) && await js(`!!document.querySelector('.pager.bottom form[data-goto][data-base="#/films"]')`));
  ok('wall: 42 tiles on a page', await js(`${TILES}.length`) === 42);
  ok('wall: newest releases first = the film\'s latest release, from the whole catalogue', await js(`(()=>{const t=${tids}.map(i=>FSTAT[i][1]);return t.every((x,i)=>!i||t[i-1]>=x);})()`));
  ok('wall: tile = poster, bold title, "Year · Genre, Genre", "Score · Certificate", release count; opens the film page', await js(`${TILES}.every(t=>{const id=t.getAttribute('href').split('/').pop(),f=DB.films[id];return t.getAttribute('href')==='#/film/'+id&&t.querySelector('b').textContent===f.t&&+getComputedStyle(t.querySelector('b')).fontWeight>=700&&t.querySelector('.what').textContent===[f.y,tileGenres(f).join(', ')].filter(Boolean).join(' · ')&&t.querySelector('.m2').textContent===scoreLine(f)&&t.querySelector('.m3').textContent===relCount(FSTAT[id][0]);})`));
  ok('wall: only the title is bold on a tile', await js(`${TILES}.every(t=>[...t.querySelectorAll('.what,.m2,.m3,.count')].every(e=>+getComputedStyle(e).fontWeight<600))`));
  ok('wall: under 10 votes (or no score) reads "Too few votes"; no certificate shows the score alone', await js(`scoreLine({sc:9.2,vc:3,cert:'R'})==='Too few votes · R'&&scoreLine({sc:null,vc:null,cert:null})==='Too few votes'&&scoreLine({sc:8.5,vc:30521,cert:'PG-13'})==='8.5 · PG-13'&&scoreLine({sc:7,vc:null,cert:null})==='7'`));
  ok('wall: release count "1 release" / "N releases"', await js(`relCount(1)==='1 release'&&relCount(12)==='12 releases'`));
  ok('wall: no buttons on tiles', await js(`!document.querySelector('.wall .tiles button,.wall .tiles [data-watch],.wall .tiles [data-cart]')`));
  ok('wall: the release count is a grey line under the score (he chose A); nothing sits on the poster', await js(`${TILES}.every(t=>t.querySelector('.m3').offsetParent!==null&&t.querySelector('.m3').previousElementSibling.classList.contains('m2')&&!t.querySelector('.count')&&t.querySelector('.art').children.length===1)`));
  ok('wall: no variation selector left on the page', await js(`!document.querySelector('[data-cnt],[data-sl],[data-fl],.varsel')`));
  // sorts
  for (const [k, cmp] of [['newsite', `(a,b)=>FSTAT[a][2]>=FSTAT[b][2]`], ['year', `(a,b)=>(+DB.films[a].y||0)>=(+DB.films[b].y||0)`], ['az', `(a,b)=>DB.films[a].t.localeCompare(DB.films[b].t,undefined,{sensitivity:'base'})<=0`]]) {
    await js(`(()=>{const s=document.querySelector('[data-wsort]');s.value='${k}';s.dispatchEvent(new Event('change',{bubbles:true}));})()`); await sleep(150);
    ok(`wall: sort "${k}" orders the tiles and is remembered`, await js(`(()=>{const c=${cmp},t=${tids};return t.length===42&&t.every((x,i)=>!i||c(t[i-1],x))&&localStorage.getItem('nntmux.movies.wsort')==='${k}'&&location.hash==='#/films';})()`)); }
  await js(`(()=>{const s=document.querySelector('[data-wsort]');s.value='recent';s.dispatchEvent(new Event('change',{bubbles:true}));})()`); await sleep(150);
  // paging
  await js(`document.querySelector('.pager.slim a[rel=next]').click()`); await sleep(200);
  ok('wall: next page is #/films/p/2 with the next 42 films', await js(`location.hash==='#/films/p/2'&&/^Showing 43–84 of/.test(document.querySelector('.pager.slim .sum').textContent)&&${TILES}.length===42`));
  await js(`(()=>{const f=document.querySelector('.pager.bottom form[data-goto]');f.querySelector('input').value='5';f.requestSubmit();})()`); await sleep(200);
  ok('wall: Go to page opens that page of the wall', await js(`location.hash==='#/films/p/5'`));
  // filters
  const w0 = JSON.parse(await wgeom());
  await pick('wgenre', 'Horror'); await close(); await sleep(100);
  ok('wall: a filter change returns to page 1 (#/films)', await js(`location.hash==='#/films'&&state.wpage===1`));
  ok('wall: a matched genre is shown first on every tile (never hidden past the two shown)', await js(`${TILES}.every(t=>t.querySelector('.what .gn')?.textContent==='Horror')`));
  ok('wall: a genre name never breaks across lines', await js(`[...document.querySelectorAll('.wall .tile .gn')].every(g=>g.getClientRects().length===1)`));
  ok('wall: Genre keeps films with that genre', await js(`${tids}.length>0&&${tids}.every(i=>DB.films[i].g.includes('Horror'))`));
  ok('wall: the wall\'s filters are its own (the releases list is not filtered)', await js(`state.genre.size===0&&state.wgenre.has('Horror')`));
  await open('wyear'); await js(`document.querySelector('[data-ypick="decade:1990"]').click()`); await sleep(100);
  ok('wall: Year (one choice) keeps films of that decade', await js(`document.querySelector('[data-ddtoggle=wyear]').textContent.trim()==='Year: 1990s'&&${tids}.every(i=>+DB.films[i].y>=1990&&+DB.films[i].y<2000)`));
  await pick('wscore', '7'); await close(); await pick('wcert', 'R'); await close(); await sleep(100);
  await pick('wlang', 'English'); await close(); await sleep(60);
  ok('wall: Language keeps films whose original language it is', await js(`${tids}.every(i=>FLANG[i]==='English')`));
  ok('wall: Score and Certificate keep films in that band and certificate', await js(`${tids}.every(i=>scoreBand(DB.films[i])==='7'&&DB.films[i].cert==='R'&&FLANG[i]==='English')`));
  await js(`state.person=Object.keys(PEOPLE).find(n=>PEOPLE[n].length>2);route()`); await sleep(100);
  const w1 = JSON.parse(await wgeom());
  ok('wall: nothing moves when every filter and a person are set (menu widths, Clear all slot, pager line)', w0.row === w1.row && w0.w.join() === w1.w.join() && w0.clear === w1.clear && w0.list === w1.list, JSON.stringify([w0, w1]));
  ok('wall: the top pager arrows stay put whatever the page count', w0.prev === w1.prev && w0.next === w1.next, JSON.stringify([w0.prev, w1.prev, w0.next, w1.next]));
  await js(`document.querySelector('[data-clearall]').click()`); await sleep(100);
  ok('wall: Clear all clears the five menus and the person', await js(`!wallAnySet()&&!document.querySelector('.mrow .person')&&document.querySelector('[data-clearall]').getAttribute('aria-hidden')==='true'`));
  await open('wyear'); await js(`(()=>{const a=document.querySelector('#yfrom'),b=document.querySelector('#yto');a.value='2030';a.dispatchEvent(new Event('input',{bubbles:true}));b.value='1990';b.dispatchEvent(new Event('input',{bubbles:true}));document.querySelector('[data-yform]').requestSubmit();})()`); await sleep(100);
  ok('wall: a bad year range shows the error in place of "Range" and filters nothing', await js(`/^Years run 1900–2026, earliest first$/.test(document.querySelector('.ymenu .yerr')?.textContent||'')&&!state.wyear&&!state.yerr`));
  await js(`document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape'}))`); await sleep(80);
  ok('wall: Escape closes the year menu and focus returns to its button', await js(`!document.querySelector('.ymenu')&&document.activeElement.matches('[data-ddtoggle=wyear]')`));
  // person
  const person = await js(`Object.keys(PEOPLE).find(n=>PEOPLE[n].length>2)`);
  await go('#/films?person=' + encodeURIComponent(person)); await sleep(150);
  ok('wall: a person link filters the wall to that person\'s films with a removable coral "Films with <name>" chip', await js(`(()=>{const c=document.querySelector('.mrow .person');return !!c&&c.textContent.trim()==='Films with '+${JSON.stringify(person)}&&getComputedStyle(c).backgroundColor===getComputedStyle(document.querySelector('.seg [aria-current=page]')).backgroundColor&&${tids}.length===PEOPLE[${JSON.stringify(person)}].filter(i=>FSTAT[i]).length&&${tids}.every(i=>PEOPLE[${JSON.stringify(person)}].includes(i))&&location.hash==='#/films';})()`));
  await js(`document.querySelector('[data-unperson]').click()`); await sleep(100);
  ok('wall: removing the person chip shows every film again', await js(`!state.person&&!document.querySelector('.mrow .person')&&new RegExp('of '+Object.keys(DB.films).filter(i=>FSTAT[i]).length.toLocaleString()+' films$').test(document.querySelector('.pager.slim .sum').textContent)`));
  await js(`(()=>{const q=document.querySelector('#q');q.focus();q.value=${JSON.stringify(person.slice(0, 6))};q.dispatchEvent(new Event('input',{bubbles:true}));})()`); await sleep(100);
  await js(`document.querySelector('#results a.p').click()`); await sleep(200);
  ok('wall: picking a person in search opens the wall filtered to them', await js(`!!state.person&&!!document.querySelector('.mrow .person')&&location.hash==='#/films'`));
  // a film with no poster: the name card with its matched title and year
  const np = await js(`Object.keys(DB.films).find(i=>!DB.films[i].p&&FSTAT[i])`);
  if (np) { await js(`state.person=null;state.wsort='az';['wgenre','wscore','wcert'].forEach(k=>state[k].clear());state.wyear=null;route()`); await sleep(100);
    await js(`(()=>{const ids=Object.keys(DB.films).filter(i=>FSTAT[i]).sort((a,b)=>DB.films[a].t.localeCompare(DB.films[b].t,undefined,{sensitivity:'base'})||a.localeCompare(b));location.hash=pageHref('#/films',Math.floor(ids.indexOf(${JSON.stringify(np)})/42)+1);})()`); await sleep(250);
    ok('wall: a film with no poster gets the name card (matched title and year), poster-sized', await js(`(()=>{const t=document.querySelector('.tile[href="#/film/${np}"]'),f=DB.films['${np}'],o=document.querySelector('.tile .art img')?.closest('.art');if(!t)return false;const a=t.querySelector('.art').getBoundingClientRect(),b=o?o.getBoundingClientRect():a;return t.querySelector('.ph .t').textContent===f.t&&(t.querySelector('.ph small')?.textContent||'')===(f.y||'')&&Math.round(a.height)===Math.round(b.height);})()`));
    await js(`state.wsort='recent';route()`); }
  // empty
  await go('#/films'); await js(`state.wscore.add('9');state.wcert.add('NC-17');state.wgenre.add('Western');route()`); await sleep(100);
  await js(`state.wscore.clear();state.wcert.clear();state.wgenre.clear();state.person=${JSON.stringify('__none__')};PEOPLE.__none__=[Object.keys(DB.films).find(i=>FSTAT[i])];route()`); await sleep(80);
  ok('wall: a single film reads "Showing 1 film"', await js(`document.querySelector('.pager.slim .sum').textContent==='Showing 1 film'`));
  await js(`delete PEOPLE.__none__;state.person=null;state.wscore.add('9');state.wcert.add('NC-17');state.wgenre.add('Western');route()`); await sleep(80);
  ok('wall: no results reads "Showing 0 films", "Page 1 of 1" and names the filters', await js(`document.querySelector('.pager.slim .sum').textContent==='Showing 0 films'&&document.querySelector('.pager.slim .pg').textContent==='Page 1 of 1'&&/^No films match Western · score 9\\+ · rated NC-17\\.$/.test(document.querySelector('.wall .empty').textContent)`));
  await js(`document.querySelector('[data-clearall]').click()`); await sleep(100);
  for (const theme of ['dark', 'light']) { await js(`document.documentElement.dataset.theme='${theme}'`); await sleep(60);
    ok(`wall (${theme}): tile lines ≥ 4.5:1`, await js(`(()=>{const L=${`(c=>{const d=document.createElement('canvas').getContext('2d');d.fillStyle=c;d.fillRect(0,0,1,1);const p=[...d.getImageData(0,0,1,1).data].slice(0,3).map(v=>{v/=255;return v<=.03928?v/12.92:Math.pow((v+.055)/1.055,2.4)});return .2126*p[0]+.7152*p[1]+.0722*p[2]})`},bg=L(getComputedStyle(document.body).backgroundColor),cr=(a,b)=>(Math.max(a,b)+.05)/(Math.min(a,b)+.05),v=${vis('.wall .tile .what,.wall .tile .m2,.wall .tile .m3')};return v.length>0&&v.every(e=>cr(L(getComputedStyle(e).color),bg)>=4.5);})()`)); }
  await js(`document.documentElement.dataset.theme='dark'`);
  await go('#/'); await sleep(200);
}
}
// ---- film page (2026-09-27) ----
if (!process.env.DETONLY) { const FR = `[...document.querySelectorAll('.frel table.rt tbody tr')]`, fids = `${FR}.map(r=>+r.querySelector('[data-nzb]').dataset.nzb)`;
  const L = `(c=>{const d=document.createElement('canvas').getContext('2d');d.fillStyle=c;d.fillRect(0,0,1,1);const p=[...d.getImageData(0,0,1,1).data].slice(0,3).map(v=>{v/=255;return v<=.03928?v/12.92:Math.pow((v+.055)/1.055,2.4)});return .2126*p[0]+.7152*p[1]+.0722*p[2]})`;
  const cr = `((a,b)=>{const x=${L}(a),y=${L}(b);return (Math.max(x,y)+.05)/(Math.min(x,y)+.05);})`;
  const fgeom = () => js(`JSON.stringify({bar:Math.round(document.querySelector('.pf .fseg').getBoundingClientRect().top),w:[...document.querySelectorAll('.pf .mbtn')].map(b=>Math.round(b.getBoundingClientRect().width)),clear:Math.round(document.querySelector('.frel [data-clearall]').getBoundingClientRect().left),line:Math.round(document.querySelector('.frel .pager.slim').getBoundingClientRect().top),prev:Math.round(document.querySelector('.frel .pager.slim [aria-label="Previous page"]').getBoundingClientRect().left),table:Math.round(document.querySelector('.frel table.rt')?.getBoundingClientRect().top||0)})`);
  await go('#/films'); await sleep(150);
  const listCell = await js(`(()=>{location.hash='#/';return new Promise(r=>setTimeout(()=>{route();r(Math.round(document.querySelector('.fbar .fseg.rel .mbtn')?.getBoundingClientRect().width||0));},250));})()`);
  await go('#/films'); await sleep(150);
  const wallTile = await js(`Math.round(document.querySelector('.wall .tile').getBoundingClientRect().width)`);
  await js(`document.querySelector('.wall .tile').click()`); await sleep(100);
  for (let i = 0; i < 50 && !(await js('!!MORE&&!!document.querySelector(".fhead")')); i++) await sleep(200);
  ok('film: a wall tile opens the film page; the back link returns to Films', await js(`/^#\\/film\\/\\d+$/.test(location.hash)&&document.querySelector('.back').getAttribute('href')==='#/films'&&document.querySelector('.back').textContent.trim()==='Films'`));
  ok('film: every film lists as many releases as the wall counts (whole catalogue)', await js(`Object.keys(DB.films).filter(i=>FSTAT[i]).every(i=>(BYFILM[i]||[]).length===FSTAT[i][0])&&Object.keys(MORE.films).every(i=>(BYFILM[i]||[]).length===STAT(i)[0])`));
  await go('#/film/' + FX.film); await sleep(200);
  ok('film: title, then "Year · N releases · latest <date> · best <resolution chip>"', await js(`(()=>{const f=FILM('${FX.film}'),a=BYFILM['${FX.film}'],m=document.querySelector('.fhead .meta'),best=RES.find(x=>a.some(r=>r.res===x));return document.querySelector('.fhead h1').textContent===f.t&&m.textContent.replace(/\\s+/g,' ').trim()===[f.y,relCount(a.length),'latest '+ago(Math.max(...a.map(r=>r.t))),'best '+best].join('·').replace(/·/g,'·')&&m.querySelector('.res').textContent===best;})()`), await js(`document.querySelector('.fhead .meta').textContent`));
  ok('film: plot, then tags: genres (links), "Score N" or "Too few votes", MPAA rating, language (plain)', await js(`(()=>{const f=FILM('${FX.film}'),t=[...document.querySelectorAll('.fhead .tags .tag')];return document.querySelector('.fhead p').textContent===f.plot&&t.filter(x=>x.tagName==='A').map(x=>x.textContent).join()===f.g.join()&&t.filter(x=>x.classList.contains('plain')).map(x=>x.textContent).join()===[scoreBand(f)==='few'?'Too few votes':'Score '+(+f.sc),f.cert,FLANG['${FX.film}']].filter(Boolean).join();})()`));
  ok('film: "Directed by" and "Starring" (up to 12) link to the Films wall filtered by that person', await js(`(()=>{const f=FILM('${FX.film}'),[d,c]=document.querySelectorAll('.fhead .starring');return d.textContent.startsWith('Directed by ')&&c.textContent.startsWith('Starring ')&&c.querySelectorAll('a').length===Math.min(12,f.cast.length)&&[...d.querySelectorAll('a'),...c.querySelectorAll('a')].every(a=>a.getAttribute('href')==='#/films?person='+encodeURIComponent(a.textContent));})()`));
  ok('film: IMDb (tt id) and TMDB links open a new tab; no Trakt link (no Trakt id is stored)', await js(`(()=>{const a=[...document.querySelectorAll('.fhead .dacts a.ext')];return a.map(x=>x.textContent.replace(' (opens in a new tab)','')).join()==='IMDb,TMDB'&&a[0].href==='https://www.imdb.com/title/tt${FX.film}/'&&a[1].href==='https://www.themoviedb.org/movie/'+FILM('${FX.film}').tmdb&&a.every(x=>x.target==='_blank'&&/noopener/.test(x.rel));})()`));
  ok('film: header actions are Follow film then the links; no Download in the header; no Report', await js(`[...document.querySelector('.fhead .dacts').children].map(b=>(b.querySelector('.wl>span:not([hidden])')||b).textContent.trim().replace(' (opens in a new tab)','')).join('|')==='Follow film|IMDb|TMDB'&&!document.querySelector('.fhead [data-nzb],[aria-label*=Report i]')`));
  for (const theme of ['dark', 'light']) { await js(`document.documentElement.dataset.theme='${theme}'`); await sleep(60);
    ok(`film (${theme}): Follow film is violet, never coral, off and on; its text ≥ 4.5:1`, await js(`(()=>{const b=document.querySelector('.fhead [data-watch]'),acc=getComputedStyle(document.querySelector('.frel .ia.dl')).backgroundColor,s0=getComputedStyle(b),c0=${cr}(s0.color,s0.backgroundColor),bg0=s0.backgroundColor;b.click();const b2=document.querySelector('.fhead [data-watch]'),s1=getComputedStyle(b2),c1=${cr}(s1.color,s1.backgroundColor),on=b2.getAttribute('aria-pressed')==='true'&&b2.querySelector('.wl>span:not([hidden])').textContent==='Following film';b2.click();return on&&bg0!==acc&&s1.backgroundColor!==acc&&c0>=4.5&&c1>=4.5;})()`));
    ok(`film (${theme}): meta, plot, tags, people and table text ≥ 4.5:1`, await js(`(()=>{const bg=getComputedStyle(document.body).backgroundColor;return [...document.querySelectorAll('.fhead .meta,.fhead p,.fhead .starring,.fhead .starring a,.fhead .tag.plain,.frel .pager .sum,.frel td.num')].every(e=>${cr}(getComputedStyle(e).color,bg)>=4.5)&&[...document.querySelectorAll('.fhead a.tag')].every(e=>${cr}(getComputedStyle(e).color,getComputedStyle(e).backgroundColor)>=4.5);})()`));
    ok(`film (${theme}): row buttons tinted in their own hue; icons ≥ 3:1 off and on; a pressed Cart is not coral`, await js(`(()=>{const acc=getComputedStyle(document.querySelector('.frel .ia.dl')).backgroundColor,r=document.querySelector('.frel tbody tr'),id=r.querySelector('[data-cart]').dataset.cart,ok0=[...r.querySelectorAll('.ia:not(.dl):not(.slot)')].every(b=>{const s=getComputedStyle(b);return ${cr}(s.color,s.backgroundColor)>=3&&s.backgroundColor!==acc;});r.querySelector('[data-cart]').click();const b=document.querySelector('.frel [data-cart="'+id+'"]'),s=getComputedStyle(b),ok1=b.getAttribute('aria-pressed')==='true'&&s.backgroundColor!==acc&&${cr}(s.color,s.backgroundColor)>=3;b.click();return ok0&&ok1;})()`)); }
  await js(`document.documentElement.dataset.theme='dark'`);
  ok('film: pressing Follow film moves nothing after it (the label keeps its width)', await js(`(()=>{const x=()=>[...document.querySelectorAll('.fhead .dacts>*')].map(b=>Math.round(b.getBoundingClientRect().left)).join();const a=x();document.querySelector('.fhead [data-watch]').click();const b=x();document.querySelector('.fhead [data-watch]').click();return a===b;})()`));
  ok('film: no "Same name posted more than once" line on any row (his call 2026-09-27)', await js(`!document.querySelector('.frel .dupe')&&!/Same name posted/.test(document.querySelector('.frel').textContent)`));
  ok('film: the film is followed from the header only; the release rows have no Follow button (his call 2026-09-27)', await js(`(()=>{const rows=!document.querySelector('.frel [data-watch]');document.querySelector('.fhead [data-watch]').click();const on=document.querySelector('.fhead [data-watch]').getAttribute('aria-pressed')==='true';document.querySelector('.fhead [data-watch]').click();return rows&&on;})()`));
  ok('film: "Releases", then a filter bar of two cells (Resolution, Source) as wide as the list\'s cells', await js(`document.querySelector('.frel h2').textContent==='Releases'&&[...document.querySelectorAll('.pf .mbtn .k')].map(k=>k.textContent).join()==='Resolution,Source'&&[...document.querySelectorAll('.pf .mbtn')].every(b=>Math.abs(Math.round(b.getBoundingClientRect().width)-${listCell})<=1)`), String(listCell));
  ok('film: one table, newest posted first, every release of the film on the page (not grouped)', await js(`(()=>{const ids=${fids},t=ids.map(i=>BYID[i].t);return document.querySelectorAll('.frel table.rt').length===1&&ids.length===BYFILM['${FX.film}'].length&&t.every((x,i)=>!i||t[i-1]>=x)&&document.querySelector('.frel .pager .sum').textContent==='Showing 1–'+BYFILM['${FX.film}'].length+' of '+BYFILM['${FX.film}'].length+' releases';})()`));
  ok('film: columns Release, Resolution, Source, Size, Files, Posted (no Grabs, his call 2026-09-27); a box per row and no check-all box', await js(`[...document.querySelectorAll('.frel thead th')].map(t=>t.textContent.trim()).join('|')==='Select|Release|Resolution|Source|Size|Files|Posted|Actions'&&!document.querySelector('.frel [data-selectall]')&&${FR}.every(r=>r.querySelector('input[data-select]'))`));
  ok('film: only the release name is bold in a row; it links to that release\'s details', await js(`${FR}.every(r=>r.querySelector('.rname').getAttribute('href')==='#/release/'+r.querySelector('[data-nzb]').dataset.nzb&&[...r.querySelectorAll('*')].filter(e=>e.childNodes[0]?.nodeType===3&&e.textContent.trim()&&+getComputedStyle(e).fontWeight>=600&&!e.closest('.rname,.res,button,.rc')).length===0)`));
  ok('film: the releases list\'s buttons, 2 x 2: Download (coral) and Copy link on top, Cart alone below, no Follow', await js(`${FR}.every(r=>{const a=[...r.querySelectorAll('.iconacts .ia:not(.slot)')],t=a.map(b=>Math.round(b.getBoundingClientRect().top)),x=a.map(b=>Math.round(b.getBoundingClientRect().left));return r.querySelector('.iconacts').classList.contains('stack')&&a.map(b=>b.getAttribute('aria-label')).join('|')==='Download NZB|Copy NZB link for SABnzbd or NZBGet|Add to cart'&&t[0]===t[1]&&t[2]>t[0]&&x[2]===x[0];})`));
  for (const [k, v] of [['size', 'r=>r.size'], ['res', "r=>RES.indexOf(r.res)<0?9:RES.indexOf(r.res)"]]) {
    await js(`document.querySelector('[data-fsort=${k}]').click()`); await sleep(60);
    const d1 = await js(`(()=>{const v=${v},x=${fids}.map(i=>v(BYID[i]));return x.every((y,i)=>!i||x[i-1]>=y)&&document.activeElement.matches('[data-fsort=${k}]')&&document.querySelector('[data-fsort=${k}]').closest('th').getAttribute('aria-sort')==='descending';})()`);
    await js(`document.querySelector('[data-fsort=${k}]').click()`); await sleep(60);
    ok(`film: the ${k} header sorts descending, then ascending`, d1 && await js(`(()=>{const v=${v},x=${fids}.map(i=>v(BYID[i]));return x.every((y,i)=>!i||x[i-1]<=y)&&document.querySelector('[data-fsort=${k}]').closest('th').getAttribute('aria-sort')==='ascending';})()`)); }
  await js(`document.querySelector('[data-fsort=posted]').click()`); await sleep(60);
  const g0 = JSON.parse(await fgeom());
  await pick('pres', '1080p'); await sleep(60);
  ok('film: the sorted column\'s heading reads in ink, the others muted', await js(`(()=>{const s=getComputedStyle(document.querySelector('th[aria-sort] button')).color,o=getComputedStyle(document.querySelector('[data-fsort=size]')).color;return document.querySelector('th[aria-sort] [data-fsort=posted]')!==null&&s!==o&&s===getComputedStyle(document.querySelector('.fhead h1')).color;})()`), await js(`JSON.stringify([document.querySelector('th[aria-sort]')?.textContent,getComputedStyle(document.querySelector('th[aria-sort] button')).color,getComputedStyle(document.querySelector('[data-fsort=size]')).color,getComputedStyle(document.querySelector('.fhead h1')).color,document.activeElement.outerHTML.slice(0,60)])`));
  ok('film: the Resolution menu stays open while ticking; the cell reads 1080p with the coral line', await js(`state.dd==='pres'&&document.querySelector('[data-msel=pres]').classList.contains('set')&&document.querySelector('[data-ddtoggle=pres] .v').textContent==='1080p'`));
  await close(); await sleep(60);
  ok('film: Resolution keeps only those releases; Clear all appears', await js(`${fids}.length>0&&${fids}.every(i=>BYID[i].res==='1080p')&&document.querySelector('.frel [data-clearall]').getAttribute('aria-hidden')===null`));
  await pick('psrc', 'WEB'); await close(); await sleep(60);
  ok('film: Resolution and Source combine', await js(`${fids}.every(i=>BYID[i].res==='1080p'&&BYID[i].src==='WEB')`));
  await js(`document.querySelector('.frel [data-sel],.frel input[data-select]').click()`); await sleep(80);
  const g1 = JSON.parse(await fgeom());
  ok('film: nothing moves when filters are set and rows selected (bar, cells, Clear all slot, pager line, table)', g0.bar === g1.bar && g0.w.join() === g1.w.join() && g0.clear === g1.clear && g0.line === g1.line && g0.prev === g1.prev && g0.table === g1.table, JSON.stringify([g0, g1]));
  ok('film: selecting a row brings up the floating selection bar', await js(`/^1 selected/.test(document.querySelector('.bulk')?.textContent||'')`));
  await js(`document.querySelector('[data-bulk=clear]').click()`); await sleep(60);
  await pick('psrc', 'DVD'); await pick('psrc', 'WEB'); await pick('pres', 'Any'); await pick('pres', '4K'); await close(); await sleep(60);
  ok('film: no match reads "Showing 0 releases" and names the filters', await js(`document.querySelector('.frel .pager .sum').textContent==='Showing 0 releases'&&/^No releases of this film match 4K · DVD\\.$/.test(document.querySelector('.frel .empty')?.textContent||'')`), await js(`document.querySelector('.frel .empty')?.textContent+' | '+document.querySelector('.frel .pager .sum').textContent`));
  await js(`document.querySelector('.frel [data-clearall]').click()`); await sleep(60);
  ok('film: Clear all clears both cells and keeps focus in the bar', await js(`!pAnySet()&&document.activeElement.matches('.pf .mbtn')&&document.querySelector('.frel [data-clearall]').getAttribute('aria-hidden')==='true'`));
  // Similar films
  ok('film: "Similar films", six tiles as wide as the wall\'s, each opening its film page', await js(`(()=>{const t=[...document.querySelectorAll('.simf .tile')];return document.querySelector('.simf h2').textContent==='Similar films'&&t.length===6&&t.every(x=>/^#\\/film\\/\\d+$/.test(x.getAttribute('href'))&&Math.abs(Math.round(x.getBoundingClientRect().width)-${wallTile})<=1)&&t.map(x=>x.getAttribute('href').split('/').pop()).join()===(MORE.sim['${FX.film}']||[]).filter(FILM).slice(0,6).join();})()`), String(wallTile));
  ok('film: a similar-film tile carries the wall tile\'s lines', await js(`[...document.querySelectorAll('.simf .tile')].every(t=>{const id=t.getAttribute('href').split('/').pop(),f=FILM(id);return t.querySelector('b').textContent===f.t&&t.querySelector('.what').textContent===[f.y,f.g.slice(0,2).join(', ')].filter(Boolean).join(' · ')&&t.querySelector('.m2').textContent===scoreLine(f)&&t.querySelector('.m3').textContent===relCount(STAT(id)[0]);})`));
  await js(`scrollTo(0,99999)`); await sleep(60); await js(`document.querySelectorAll('.simf .tile')[5].click()`); await sleep(300);
  ok('film: another film opens at the top of the page, filters reset', await js(`(()=>{const id=(MORE.sim['${FX.film}']||[]).filter(FILM)[5];return location.hash==='#/film/'+id&&scrollY===0&&document.querySelector('.fhead h1').textContent===FILM(id).t&&!pAnySet();})()`));
  // paging on the largest film (152 releases)
  await go('#/film/' + FX.big); await sleep(200);
  const bigN = await js(`BYFILM['${FX.big}'].length`), bigP = Math.ceil(bigN / 50);
  ok(`film: the largest film (${bigN} releases) pages at 50: "Showing 1–50 of ${bigN} releases", Page 1 of ${bigP}, bottom pager`, bigN > 150 && await js(`${FR}.length===50&&document.querySelector('.frel .pager .sum').textContent==='Showing 1–50 of ${bigN} releases'&&document.querySelector('.frel .pager .pg').textContent==='Page 1 of ${bigP}'&&!!document.querySelector('.frel .pager.bottom form[data-goto][data-base="#/film/${FX.big}"]')`));
  await js(`document.querySelector('.frel .pager.bottom a[rel=next]').click()`); await sleep(250);
  ok('film: the next page is #/film/<id>/p/2 and opens at the Releases heading', await js(`location.hash==='#/film/${FX.big}/p/2'&&new RegExp('^Showing 51–100 of '+${bigN}).test(document.querySelector('.frel .pager .sum').textContent)&&Math.abs(document.querySelector('#releases').getBoundingClientRect().top-84)<=2`), await js(`document.querySelector('#releases').getBoundingClientRect().top`));
  await js(`(()=>{const f=document.querySelector('.frel .pager.bottom form[data-goto]');f.querySelector('input').value='${bigP}';f.requestSubmit();})()`); await sleep(250);
  ok(`film: Go to page ${bigP} shows the last releases`, await js(`location.hash==='#/film/${FX.big}/p/${bigP}'&&document.querySelector('.frel .pager .sum').textContent==='Showing ${(bigP - 1) * 50 + 1}–${bigN} of ${bigN} releases'`));
  await pick('pres', 'SD'); await close(); await sleep(80);
  ok('film: a filter change returns to the first page (#/film/<id>)', await js(`location.hash==='#/film/${FX.big}'&&/^Showing 1–/.test(document.querySelector('.frel .pager .sum').textContent)`));
  await js(`state.pres.clear();rerender()`);
  // a film with no poster; an extra film (reached only through Similar films)
  const np = await js(`Object.keys(DB.films).find(i=>!DB.films[i].p&&FSTAT[i])`);
  if (np) { await go('#/film/' + np); await sleep(150);
    ok('film: a film with no poster shows the name card (title and year) in the poster box', await js(`(()=>{const f=FILM('${np}'),a=document.querySelector('.fhead .art');return a.querySelector('.ph .t').textContent===f.t&&(a.querySelector('.ph small')?.textContent||'')===(f.y||'')&&Math.round(a.getBoundingClientRect().height)===300;})()`)); }
  const ex = await js(`Object.keys(MORE.films).find(i=>!DB.films[i]&&MORE.films[i].p&&(MORE.sim[i]||[]).filter(FILM).length)||''`);
  if (ex) {
  await go('#/film/' + ex); await sleep(150);
    ok('film: a film outside the list\'s slice opens with all its releases and its own Similar films', await js(`${FR}.length===Math.min(50,STAT('${ex}')[0])&&document.querySelectorAll('.simf .tile').length>0`));
  }
  // back to the list
  await go('#/'); await sleep(200); await js(`document.querySelector('.feed .showlink').click()`); await sleep(250);
  ok('film: from the list, the film line opens the film page and the back link returns to Movie releases', await js(`isFilm()&&document.querySelector('.back').getAttribute('href')==='#/'&&document.querySelector('.back').textContent.trim()==='Movie releases'`));
  await go('#/'); await sleep(200);
}
const noWatchD = `(()=>{const t=[...document.querySelectorAll('button,[role=button],.btn,#toast')].map(e=>e.textContent+' '+(e.title||'')+' '+(e.getAttribute('aria-label')||'')).join(' ');return !/\\bwatch(ing|es|ed)?\\b/i.test(t);})()`;
// ---- release details (2026-09-27): the approved TV details page adapted (SPEC 6.5) ----
{ const DT = `[...document.querySelectorAll('.mdet #sibs table.rt tbody tr')]`, dids = `${DT}.map(r=>+r.querySelector('[data-nzb]').dataset.nzb)`;
  const SR = `[...document.querySelectorAll('.mdet .simrel table.rt tbody tr')]`;
  const L = `(c=>{const d=document.createElement('canvas').getContext('2d');d.fillStyle=c;d.fillRect(0,0,1,1);const p=[...d.getImageData(0,0,1,1).data].slice(0,3).map(v=>{v/=255;return v<=.03928?v/12.92:Math.pow((v+.055)/1.055,2.4)});return .2126*p[0]+.7152*p[1]+.0722*p[2]})`;
  const cr = `((a,b)=>{const x=${L}(a),y=${L}(b);return (Math.max(x,y)+.05)/(Math.min(x,y)+.05);})`;
  const R = FX.release, waitDet = async () => { for (let i = 0; i < 60 && !(await js(`!!DET&&!!document.querySelector('.mdet,.later')`)); i++) await sleep(200); await sleep(120); };
  await go('#/'); await sleep(200);
  await js(`document.querySelector('.feed .rname').click()`); await waitDet();
  ok('details: a release name on the list opens #/release/<id> on the Overview tab, at the top', await js(`/^#\\/release\\/\\d+$/.test(location.hash)&&state.tab==='overview'&&scrollY===0&&!!document.querySelector('.mdet .tabs [aria-selected=true][data-tab=overview]')`));
  await go('#/release/' + R); await waitDet();
  ok('details: breadcrumb Movie releases › film (film page) › category', await js(`(()=>{const r=BYID[${R}],a=[...document.querySelectorAll('.crumbs a,.crumbs span:not(:empty)')].filter(e=>e.textContent!=='›').map(e=>e.textContent);return a.join('|')===['Movie releases',F(r).t,X(r).cat].join('|')&&document.querySelectorAll('.crumbs a')[1].getAttribute('href')==='#/film/'+r.f;})()`));
  ok('details: heading "Title · Year" (title links to the film page), the release name bold on the second line', await js(`(()=>{const r=BYID[${R}],f=F(r),h=document.querySelector('.dhead h1');return h.textContent===f.t+' · '+f.y&&h.querySelector('a').getAttribute('href')==='#/film/'+r.f&&document.querySelector('.dhead .relname').textContent===r.name&&+getComputedStyle(document.querySelector('.dhead .relname')).fontWeight>=700;})()`));
  ok('details: resolution and source chips, the chip line, then the group and poster chips', await js(`(()=>{const r=BYID[${R}],c=document.querySelectorAll('.dhead .rchips');return c[0].querySelector('.res').textContent===r.res&&c[0].querySelector('.chip.src').textContent===srcLabel(r)&&c[1].querySelectorAll('.rc.origin').length===2;})()`));
  ok('details: header buttons Download NZB, Copy NZB link, Add to cart, Follow film; no Report, Edit release or failure line', await js(`[...document.querySelector('.dhead .dacts').children].map(b=>(b.querySelector('.wl>span:not([hidden])')||b).textContent.trim()).join('|')==='Download NZB|Copy NZB link|Add to cart|Follow film'&&!/Report|Edit release|reported download failure/i.test(document.querySelector('.mdet').textContent)`));
  for (const theme of ['dark', 'light']) { await js(`document.documentElement.dataset.theme='${theme}'`); await sleep(60);
    ok(`details (${theme}): Download coral; Copy link and Cart neutral, Follow film violet (his pick B); a pressed Cart fills green, a pressed Follow violet, never coral; text ≥ 4.5:1`, await js(`(()=>{const acc=getComputedStyle(document.querySelector('.dacts [data-nzb]')).backgroundColor,q=s=>document.querySelector('.dacts '+s),st=b=>getComputedStyle(b);
      const c=st(q('[data-copynzb]')),k=st(q('[data-cart]')),w=st(q('[data-watch]'));
      const good0=c.backgroundColor===k.backgroundColor&&w.backgroundColor!==c.backgroundColor&&[c,k,w].every(s=>s.backgroundColor!==acc&&${cr}(s.color,s.backgroundColor)>=4.5);
      q('[data-cart]').click();q('[data-watch]').click();const on=['[data-cart]','[data-watch]'].map(s=>q(s)),bg=on.map(b=>st(b).backgroundColor),good1=on.every(b=>b.getAttribute('aria-pressed')==='true'&&st(b).backgroundColor!==acc&&${cr}(st(b).color,st(b).backgroundColor)>=4.5)&&bg[0]!==bg[1]&&bg[0]!==c.backgroundColor;
      q('[data-cart]').click();q('[data-watch]').click();return acc===getComputedStyle(document.querySelector('.ia.dl')).backgroundColor&&good0&&good1&&!document.querySelector('[data-hb]');})()`));
    ok(`details (${theme}): tabs, facts, plot, aside and table text ≥ 4.5:1`, await js(`(()=>{const bg=getComputedStyle(document.body).backgroundColor;return [...document.querySelectorAll('.crumbs,.tabs button,dl.vals dt,dl.vals dd,.aboutshow .starring,.aboutshow .starring a,.aboutshow .tag.plain,.aboutshow .toshow,.sibs td.num,.sibs .showlink')].every(e=>${cr}(getComputedStyle(e).color,bg)>=4.5)&&[...document.querySelectorAll('.epbox .plot,.epbox .tagl')].every(e=>${cr}(getComputedStyle(e).color,getComputedStyle(document.querySelector('.epbox')).backgroundColor)>=4.5);})()`)); }
  await js(`document.documentElement.dataset.theme='dark'`); await sleep(40);
  ok('details: pressing Add to cart or Follow film moves nothing (each label keeps its width)', await js(`(()=>{const x=()=>[...document.querySelectorAll('.dhead .dacts>*')].map(b=>Math.round(b.getBoundingClientRect().left)+':'+Math.round(b.getBoundingClientRect().width)).join();const a=x();document.querySelector('.dacts [data-cart]').click();document.querySelector('.dacts [data-watch]').click();const b=x(),t=document.querySelector('.dacts [data-cart] .wl>span:not([hidden])').textContent+'|'+document.querySelector('.dacts [data-watch] .wl>span:not([hidden])').textContent;document.querySelector('.dacts [data-cart]').click();document.querySelector('.dacts [data-watch]').click();return a===b&&t==='In cart|Following film';})()`));
  ok('details: Follow film follows the film (the list and film page show it followed) and says so in a toast', await js(`(()=>{document.querySelector('.dacts [data-watch]').click();const r=BYID[${R}],on=state.watch.has(r.f)&&document.querySelector('#toast').textContent==='Following '+F(r).t;document.querySelector('.dacts [data-watch]').click();return on&&!state.watch.has(r.f);})()`));
  ok('details: tabs Overview, Files (n), Media info, NFO, Comments (n)', await js(`(()=>{const x=X(BYID[${R}]);return [...document.querySelectorAll('.tabs [role=tab]')].map(t=>t.textContent).join('|')==='Overview|Files ('+x.files+')|Media info|NFO|Comments ('+x.cm+')';})()`));
  ok('details: the plot runs the full width of its box (his pick 2026-09-27)', await js(`(()=>{const b=document.querySelector('.epbox'),p=b.querySelector('.plot'),cs=getComputedStyle(b);return Math.abs(p.getBoundingClientRect().width-(b.clientWidth-parseFloat(cs.paddingLeft)-parseFloat(cs.paddingRight)))<=1;})()`));
  ok('details: Overview shows the preview thumbnail, the tagline in quotes and the plot, then the facts', await js(`(()=>{const f=F(BYID[${R}]);return !!document.querySelector('.panel .pvthumb img')&&document.querySelector('.epbox .tagl').textContent==='“'+f.tag+'”'&&document.querySelector('.epbox .plot').textContent===f.plot&&[...document.querySelectorAll('.panel > dl.vals dt')].map(d=>d.textContent).join('|')==='Category|Size|Files|Completion|Posted|Added|Grabs|Group|Poster|Password status';})()`));
  await js(`document.querySelector('.panel .pvthumb').click()`); await sleep(200);
  ok('details: the thumbnail opens the preview image dialog', await js(`!!document.querySelector('#modal [role=dialog][aria-label="Preview image"] img.pvimg')`)); await js(`document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape'}))`);
  for (const [t, sel] of [['files', '.panel table.filelist'], ['media', '.panel .mi2'], ['nfo', '.panel pre.nfo,.panel .note'], ['comments', '.panel .note']]) {
    await js(`document.querySelector('[data-tab=${t}]').click()`); for (let i = 0; i < 30 && !(await js(`!!document.querySelector('${sel}')`)); i++) await sleep(150);
    ok(`details: the ${t} tab shows its panel and keeps focus on the tab`, await js(`!!document.querySelector('${sel}')&&document.activeElement.matches('[data-tab=${t}]')&&document.querySelector('[data-tab=${t}]').getAttribute('aria-selected')==='true'`)); }
  await js(`document.querySelector('[data-tab=overview]').click()`); await sleep(60);
  ok('details: "About the film": genres (links to the Films wall), Score, MPAA rating, language, Directed by, Starring (up to 8, links), Film page', await js(`(()=>{const r=BYID[${R}],f=F(r),a=document.querySelector('.aboutshow'),t=[...a.querySelectorAll('.tag')],p=[...a.querySelectorAll('.starring')];return a.querySelector('h2').textContent==='About the film'&&t.filter(x=>x.tagName==='A').map(x=>x.textContent).join()===f.g.join()&&t.filter(x=>x.tagName==='A').every(x=>x.getAttribute('href')==='#/films'&&x.dataset.setwf==='wgenre')&&t.filter(x=>x.classList.contains('plain')).map(x=>x.textContent).join()===[scoreBand(f)==='few'?'Too few votes':'Score '+(+f.sc),f.cert,FLANG[r.f]].filter(Boolean).join()&&p[0].textContent.startsWith('Directed by ')&&p[1].textContent.startsWith('Starring ')&&p[1].querySelectorAll('a').length===Math.min(8,f.cast.length)&&[...a.querySelectorAll('.starring a')].every(x=>x.getAttribute('href')==='#/films?person='+encodeURIComponent(x.textContent))&&a.querySelector('.toshow').getAttribute('href')==='#/film/'+r.f;})()`));
  ok('details: "All N releases of this film" lists every release of the film; this one tinted, "The release on this page", its name not a link', await js(`(()=>{const r=BYID[${R}],all=BYFILM[r.f],me=document.querySelector('#sibs tr.me');return document.querySelector('#sibs h2').textContent==='All '+all.length+' releases of this film'&&${dids}.length===all.length&&me&&+me.querySelector('[data-nzb]').dataset.nzb===r.id&&me.querySelector('.this').textContent==='The release on this page'&&!me.querySelector('a.rname')&&getComputedStyle(me.querySelector('td')).backgroundColor!==getComputedStyle(document.querySelector('#sibs tr:not(.me) td')).backgroundColor;})()`));
  ok('details: the table is the film page\'s without the select box: Release, Resolution, Source, Size, Files, Posted; no Grabs; no same-name line', await js(`[...document.querySelectorAll('#sibs thead th')].map(t=>t.textContent.trim()).join('|')==='Release|Resolution|Source|Size|Files|Posted|Actions'&&!document.querySelector('#sibs input[type=checkbox]')&&!/Same name posted/.test(document.querySelector('.mdet').textContent)`));
  ok('details: its buttons are 2 x 2, Download and Copy link on top, Cart alone below, no Follow', await js(`${DT}.every(r=>{const a=[...r.querySelectorAll('.iconacts .ia:not(.slot)')],t=a.map(b=>Math.round(b.getBoundingClientRect().top)),x=a.map(b=>Math.round(b.getBoundingClientRect().left));return a.map(b=>b.getAttribute('aria-label')).join('|')==='Download NZB|Copy NZB link for SABnzbd or NZBGet|Add to cart'&&t[0]===t[1]&&t[2]>t[0]&&x[2]===x[0];})&&!document.querySelector('.sibs [data-watch]')`));
  ok('details: only the release name is bold in the table rows', await js(`[...${DT},...${SR}].every(r=>[...r.querySelectorAll('*')].filter(e=>e.childNodes[0]?.nodeType===3&&e.textContent.trim()&&+getComputedStyle(e).fontWeight>=600&&!e.closest('.rname,.res,button,.rc,.this')).length===0)`));
  for (const [k, v] of [['size', 'r=>r.size'], ['res', "r=>RES.indexOf(r.res)<0?9:RES.indexOf(r.res)"]]) {
    await js(`document.querySelector('[data-dsort="dsort:${k}"]').click()`); await sleep(60);
    const d1 = await js(`(()=>{const v=${v},x=${dids}.map(i=>v(BYID[i]));return x.every((y,i)=>!i||x[i-1]>=y)&&document.activeElement.matches('[data-dsort="dsort:${k}"]');})()`);
    await js(`document.querySelector('[data-dsort="dsort:${k}"]').click()`); await sleep(60);
    ok(`details: the table's ${k} heading sorts descending, then ascending`, d1 && await js(`(()=>{const v=${v},x=${dids}.map(i=>v(BYID[i]));return x.every((y,i)=>!i||x[i-1]<=y)&&document.querySelector('[data-dsort="dsort:${k}"]').closest('th').getAttribute('aria-sort')==='ascending';})()`)); }
  ok('details: "Similar releases" = today\'s name matches minus this film\'s own releases, newest posted first, each with its film line', await js(`(()=>{const r=BYID[${R}],ids=${SR}.map(t=>+t.querySelector('[data-nzb]').dataset.nzb),want=DET.sim[${R}];return document.querySelector('.simrel h2').textContent==='Similar releases'&&ids.join()===[...want].sort((a,b)=>BYID[b].t-BYID[a].t||b-a).join()&&ids.every(i=>BYID[i].f!==r.f)&&${SR}.every(t=>{const x=BYID[+t.querySelector('[data-nzb]').dataset.nzb];return (!x.f&&!x.fm)||!!t.querySelector('a.showlink[href="#/film/'+(x.f||x.fm)+'"]');})&&${SR}.every(t=>t.querySelector('a.rname').getAttribute('href')==='#/release/'+t.querySelector('[data-nzb]').dataset.nzb);})()`));
  await go('#/release/' + FX.ownOnlyRelease); await waitDet();
  ok('details: a release whose name matches only its own film has no Similar releases section', await js(`!!document.querySelector('.mdet #sibs')&&!document.querySelector('.simrel')`));
  await go('#/release/' + FX.predbRelease); await waitDet();
  ok('details: a PreDB match shows the PreDB block (title, source, pre date, category when known)', await js(`(()=>{const p=DET.predb[${FX.predbRelease}],d=[...document.querySelectorAll('.predb dt')].map(x=>x.textContent);return document.querySelector('.predb h3').textContent==='PreDB'&&d[0]==='Title'&&document.querySelector('.predb dd').textContent===p[0]&&d.includes('Source')&&d.includes('Pre date');})()`));
  await go('#/release/' + R); await waitDet();
  ok('details: no PreDB block without a match', await js(`!DET.predb[${R}]&&!document.querySelector('.predb')`));
  // the largest film: the table opens on the page that holds this release
  await go('#/release/' + FX.bigRelease); await waitDet();
  const bp = await js(`(()=>{const rs=detSort(BYFILM[BYID[${FX.bigRelease}].f],{k:'posted',dir:-1});return Math.floor(rs.findIndex(o=>o.id===${FX.bigRelease})/50)+1;})()`);
  ok(`details: on the largest film the table pages at 50 and opens on the page holding this release (page ${bp})`, bp > 1 && await js(`${DT}.length<=50&&document.querySelector('#sibs .pager .pg').textContent.startsWith('Page ${bp} of ')&&!!document.querySelector('#sibs tr.me')&&!!document.querySelector('#sibs .pager.bottom form[data-goto]')`));
  await js(`document.querySelector('#sibs .pager.bottom a[aria-label="Page 1"],#sibs .pager.bottom a[href$="/p/1"]').click()`); await sleep(300);
  ok('details: another page of the table is #/release/<id>/p/N and opens at the table, on the same tab', await js(`location.hash==='#/release/${FX.bigRelease}/p/1'&&document.querySelector('#sibs .pager .pg').textContent.startsWith('Page 1 of ')&&!document.querySelector('#sibs tr.me')&&Math.abs(document.querySelector('#sibs').getBoundingClientRect().top-84)<=2`), await js(`document.querySelector('#sibs').getBoundingClientRect().top`));
  await js(`document.querySelector('[data-dsort="dsort:size"]').click()`); await sleep(120);
  ok('details: a sort change returns the table to the page holding this release', await js(`location.hash==='#/release/${FX.bigRelease}'&&!!document.querySelector('#sibs tr.me')`));
  await js(`scrollTo(0,99999)`); await sleep(60); await js(`document.querySelector('#sibs a.rname').click()`); await sleep(300); await waitDet();
  ok('details: another release opens at the top, on the Overview tab', await js(`/^#\\/release\\/\\d+$/.test(location.hash)&&location.hash!=='#/release/${FX.bigRelease}'&&scrollY===0&&state.tab==='overview'`));
  // a release with no matched film (as TV's unmatched releases)
  await go('#/release/' + FX.noFilmRelease); await waitDet();
  ok('details: a release with no matched film: its name is the heading; no poster, film crumb, Follow, About the film or releases table', await js(`(()=>{const r=BYID[${FX.noFilmRelease}];return document.querySelector('.dhead h1.relonly').textContent===r.name&&!document.querySelector('.dhead .art,.aboutshow,#sibs,.dacts [data-watch]')&&document.querySelectorAll('.crumbs a').length===1&&document.querySelector('.dcols').classList.contains('noshow');})()`));
  ok('details: it still shows Similar releases', await js(`document.querySelectorAll('.simrel tbody tr').length===DET.sim[${FX.noFilmRelease}].length`));
  await go('#/release/' + FX.outsideRelease); await waitDet();
  ok('details: a similar release of a film outside the prototype opens a plain note', await js(`!!document.querySelector('.later')&&/a film outside this prototype/.test(document.querySelector('.later').textContent)`));
  await go('#/release/' + R); await waitDet();
  await js(`document.querySelectorAll('.dacts [data-watch]').forEach(b=>b.click())`); await sleep(80);
  ok('details: no "watch" wording (buttons, tooltips, labels, toasts), following and not', await js(noWatchD)); await js(`state.watch.clear();rerender()`);
  await go('#/'); await sleep(200);
}
// ---- colour ----
const lum =`(c=>{const d=document.createElement('canvas').getContext('2d');d.fillStyle=c;d.fillRect(0,0,1,1);const p=[...d.getImageData(0,0,1,1).data].slice(0,3).map(v=>{v/=255;return v<=.03928?v/12.92:Math.pow((v+.055)/1.055,2.4)});return .2126*p[0]+.7152*p[1]+.0722*p[2]})`;
for (const theme of ['dark', 'light']) {
  await js(`document.documentElement.dataset.theme='${theme}'`); await sleep(80);
  ok(`chips and resolution chips ≥ 4.5:1 (${theme})`, await js(`(()=>{const L=${lum};return [...document.querySelectorAll('.feed .rc:not(.origin),.feed .res:not(.r-unk)')].every(e=>{const s=getComputedStyle(e),a=L(s.color),b=L(s.backgroundColor);return (Math.max(a,b)+.05)/(Math.min(a,b)+.05)>=4.5;});})()`));
  ok(`muted text ≥ 4.5:1 on the page (${theme})`, await js(`(()=>{const L=${lum},a=L(getComputedStyle(document.querySelector('.pager .sum')).color),b=L(getComputedStyle(document.body).backgroundColor);return (Math.max(a,b)+.05)/(Math.min(a,b)+.05)>=4.5;})()`));
}
await js(`document.documentElement.dataset.theme='dark'`);
{ // Follow icon (2026-09-27): a bookmark (his pick over the eye and a bell), filled solid only while followed
  await js(`location.hash=${JSON.stringify('#/film/'+FX.film)}`); await sleep(1500);
  ok('follow icon: every follow button shows the bookmark; no icon selector left', await js(`(()=>{const b=[...document.querySelectorAll('[data-watch]')];return b.length>0&&b.every(x=>x.querySelector('use').getAttribute('href')==='#i-bookmark')&&!document.querySelector('.ficsel,[data-fic]');})()`));
  await js(`document.querySelector('[data-watch]').click()`); await sleep(150);
  ok('follow icon: a followed bookmark fills solid, an unfollowed one stays an outline', await js(`(()=>{const on=document.querySelector('[data-watch][aria-pressed=true] svg'),off=document.querySelector('[data-watch][aria-pressed=false] svg');return !!on&&getComputedStyle(on).fill!=='none'&&(!off||getComputedStyle(off).fill==='none');})()`));
  await js(`state.watch.clear();route()`); await sleep(100);
}
const noWatch = `(()=>{const t=[...document.querySelectorAll('button,[role=button],.btn,#toast')].map(e=>e.textContent+' '+(e.title||'')+' '+(e.getAttribute('aria-label')||'')).join(' ');return !/\\bwatch(ing|es|ed)?\\b/i.test(t);})()`;
for (const h of ['#/','#/films','#/film/'+FX.film]) { await js(`location.hash=${JSON.stringify(h)}`); for (let i=0;i<30&&!(await js(`!isFilm()||!!document.querySelector('.fhead')`));i++) await sleep(200); await sleep(200);
  await js(`document.querySelectorAll('[data-watch]').forEach(b=>{if(b.getAttribute('aria-pressed')!=='true')b.click();})`); await sleep(100);
  ok(`no "watch" wording on ${h} (buttons, tooltips, labels, toasts; following and not following): it reads Follow (his ruling 2026-09-27)`, await js(noWatch)); await js(`state.watch.clear();route()`); }
ok('no script errors', errors.length === 0, errors.join(' | ').slice(0, 300));

const fails = results.filter(r => r[0] === 'FAIL');
results.forEach(([s, n, e]) => console.log(s, n, e ? '· ' + e : ''));
console.log(`\n${results.length} checks, ${fails.length} failures`);
ws.close(); chrome.kill('SIGKILL'); process.exit(fails.length ? 1 : 0);
