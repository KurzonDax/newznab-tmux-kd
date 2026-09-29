// Drives headless Chrome over CDP: clicks every control on adult.html and reports pass/fail.
// One check per rule: the TV / Movies SPEC rules Adult inherits, and Randall's Adult decisions (ADULT-DESIGN-RECORD.md).
// node adcheck.mjs   (V=1 prints progress on stderr)
import {spawn} from 'node:child_process';
import {mkdtempSync} from 'node:fs';
import {tmpdir} from 'node:os';
const URL_ = process.env.AD_URL || 'http://127.0.0.1:8766/adult.html', PORT = 9379;
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', ['--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(tmpdir() + '/adcdp-')}`, 'about:blank'], {stdio: 'ignore'});
const sleep = ms => new Promise(r => setTimeout(r, ms));
const results = []; let ws, seq = 0; const pending = new Map(), errors = [];
for (let i = 0; i < 40; i++) { try { const t = await (await fetch(`http://127.0.0.1:${PORT}/json`)).json(); const p = t.find(x => x.type === 'page'); if (p) { ws = new WebSocket(p.webSocketDebuggerUrl); await new Promise(r => ws.onopen = r); break; } } catch {} await sleep(250); }
ws.onmessage = m => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d.result); pending.delete(d.id); } if (d.method === 'Runtime.exceptionThrown') errors.push(d.params.exceptionDetails.exception?.description || d.params.exceptionDetails.text); };
const send = (method, params = {}) => new Promise(r => { const id = ++seq; pending.set(id, r); ws.send(JSON.stringify({id, method, params})); });
const js = async expr => { const r = await send('Runtime.evaluate', {expression: expr, awaitPromise: true, returnByValue: true}); if (r.exceptionDetails) { results.push(['FAIL', 'eval threw', expr.slice(0, 90) + ' :: ' + (r.exceptionDetails.exception?.description || '').split('\n')[0]]); return undefined; } return r.result.value; };
const ok = (name, cond, extra = '') => { results.push([cond ? 'PASS' : 'FAIL', name, extra]); if (process.env.V) console.error((cond ? 'PASS ' : 'FAIL ') + name + (cond ? '' : ' ' + extra)); };
const go = async hash => { await js(`location.hash=${JSON.stringify(hash)}`); await sleep(300); };
const key = (k, code) => send('Input.dispatchKeyEvent', {type: 'keyDown', key: k, code: code || k, windowsVirtualKeyCode: k === 'Escape' ? 27 : 0}).then(() => send('Input.dispatchKeyEvent', {type: 'keyUp', key: k, code: code || k}));
const load = async () => { await send('Page.navigate', {url: URL_ + '?r=' + Math.random() + '#/'}); for (let i = 0; i < 60 && !(await js('!!window.READY')); i++) await sleep(200); await sleep(300); };
const open = k => js(`(()=>{if(state.dd!=='${k}')document.querySelector('[data-ddtoggle=${k}]').click();return state.dd==='${k}';})()`);
const pick = async (k, v) => { await open(k); await js(v === 'Any' ? `document.querySelector('[data-ddany=${k}]').click()` : `document.querySelector('[data-ddpick=${k}][data-v="${v}"]').click()`); };
const close = () => js(`document.querySelector('.pager .sum').click()`);
const count = () => js(`+document.querySelector('.pager.slim .sum').textContent.replace(/^.* of ([\\d,]+) .*$/,'$1').replace(/,/g,'')`);
const ROWIDS = `[...document.querySelectorAll('.feed tbody tr [data-nzb]')].map(b=>+b.dataset.nzb)`;
const every = pred => js(`(()=>{const ids=${ROWIDS};return ids.length>0&&ids.every(id=>{const r=BYID[id],x=X(r);return ${pred};});})()`);

await send('Runtime.enable'); await send('Page.enable'); await send('Emulation.setFocusEmulationEnabled', {enabled: true});
await send('Emulation.setDeviceMetricsOverride', {width: 1600, height: 1000, deviceScaleFactor: 1, mobile: false});
await load(); await js('localStorage.clear()'); await load();
const FX = await js(`fetch('ad/fixtures.json').then(r=>r.json())`);
const TOTAL = await js('REL.length');

// ---- the screen ----
ok('heading "Adult releases", no Releases / wall switch (his call: no wall yet)', await js(`document.querySelector('.filters h1').textContent==='Adult releases'&&!document.querySelector('.filters .seg')`));
ok('one release bar: Category, Resolution, Audio, Completion (no Source, his call)', await js(`[...document.querySelectorAll('.fbar .mbtn .k')].map(k=>k.textContent).join(',')`) === 'Category,Resolution,Audio,Completion');
ok('the four cells are as wide as the Movies release bar cells', await js(`(()=>{const w=document.querySelector('.filters').getBoundingClientRect().width,movies=((w-12)/2-8)/5,c=[...document.querySelectorAll('.fbar .sfm')].map(e=>e.getBoundingClientRect().width);return c.length===4&&c.every(v=>Math.abs(v-movies)<1.5);})()`));
ok('name search field "Search release names" beside the heading', await js(`document.querySelector('.filters h1').nextElementSibling.querySelector('input').placeholder==='Search release names'`));
ok('four sorts, Posted: newest first by default', await js(`[...document.querySelectorAll('[data-rsort] option')].map(o=>o.textContent).join('|')`) === 'Posted: newest first|Posted: oldest first|Added: newest first|Added: oldest first' && await js(`document.querySelector('[data-rsort]').value`) === 'posted_desc');
ok('pager line shows every adult release', await count() === TOTAL && TOTAL > 1000, String(TOTAL));
ok('50 releases a page, newest posted first', await js(`(()=>{const ids=${ROWIDS},t=ids.map(i=>BYID[i].t);return ids.length===50&&t.every((v,i)=>!i||t[i-1]>=v);})()`));
ok('columns: Release, Resolution, Size, Posted; no Source (his call), no Files or Grabs', await js(`[...document.querySelectorAll('.feed thead th')].map(t=>t.textContent.trim()).filter(Boolean).join(',')`) === 'Release,Resolution,Size,Posted,Actions');
ok('no Follow / Watch anywhere (no title to follow)', await js(`!document.querySelector('[data-watch]')&&!/\\bwatch/i.test(document.body.innerText)`));
ok('row buttons: Download coral, Copy link, Cart; Cart alone under Download', await js(`(()=>{const a=document.querySelector('.feed .iconacts');const b=[...a.querySelectorAll('button')];const d=b[0].getBoundingClientRect(),c=b[2].getBoundingClientRect();return b.length===3&&b[0].matches('.dl')&&b[1].matches('[data-copynzb]')&&b[2].matches('[data-cart]')&&Math.abs(c.left-d.left)<1&&c.top>d.bottom;})()`));
ok('Size in ink, Posted dim (as on Movies)', await js(`(()=>{const tr=document.querySelector('.feed tbody tr'),c=i=>getComputedStyle(tr.children[i]).color,ink=getComputedStyle(document.body).color;return c(4)===ink&&c(5)!==ink;})()`));
ok('only the release name is bold in a row', await js(`[...document.querySelectorAll('.feed tbody tr:first-child *')].filter(e=>e.childNodes.length&&[...e.childNodes].some(n=>n.nodeType===3&&n.textContent.trim())&&+getComputedStyle(e).fontWeight>=700).every(e=>e.matches('.rname'))`));
ok('name links to the release details page', await js(`document.querySelector('.feed .rname').getAttribute('href').startsWith('#/release/')`));
ok('group + poster chips link to the all-releases list (same tab)', await js(`[...document.querySelectorAll('.feed .orig a')].every(a=>/^\\/browse\\/all\\?(group|poster)=/.test(a.getAttribute('href'))&&!a.target)`));
ok('group + poster never split; poster never cut below 96 px', await js(`[...document.querySelectorAll('.feed .orig')].every(o=>{const c=[...o.children];return c.length<2||(Math.abs(c[0].getBoundingClientRect().top-c[1].getBoundingClientRect().top)<1&&c[1].getBoundingClientRect().width>=95);})`));

// ---- picture ----
ok('picture: a 176 x 99 frame in a 196 px column (his pick A)', await js(`(()=>{const a=document.querySelector('.feed td.art a').getBoundingClientRect();return Math.round(a.width)===176&&Math.round(a.height)===99;})()`));
ok('row picture = preview, else sample, else a "No picture" tile', await every(`(()=>{const a=document.querySelector('[data-select="'+r.id+'"]').closest('tr').querySelector('td.art a'),img=a.querySelector('img');const want=(x.pv&&imgFor(r,'preview'))||(x.jpg&&imgFor(r,'sample'));return want?img&&img.getAttribute('src')===want:!img&&a.textContent.includes('No picture');})()`));
ok('picture tile is skipped by keyboard and screen readers', await js(`[...document.querySelectorAll('.feed td.art a')].every(a=>a.tabIndex===-1&&a.getAttribute('aria-hidden')==='true')`));
ok('no variation selector left on the page', await js(`!document.querySelector('.varsel')`));
ok('No picture tile: no fill, no shadow, dashed outline (his pick B)', await js(`(()=>{const s=getComputedStyle(document.querySelector('.feed a.nopic'));return s.borderStyle==='dashed'&&s.boxShadow==='none'&&s.backgroundColor==='rgba(0, 0, 0, 0)';})()`));

await go('#/'); await pick('cat', 'x264'); await close();
{ const h0 = await js('location.hash'); await js(`document.querySelector('.feed td.art a[data-img]').click()`); await sleep(300);
  ok('clicking a row picture opens its image dialog and stays on the list (his call)', await js(`/image$/.test(document.querySelector('#modal [role=dialog]')?.getAttribute('aria-label')||'')`) && await js('location.hash') === h0);
  await key('Escape'); await sleep(150);
  ok('closing it returns focus to the row\'s Preview / Sample chip, not the hidden picture', await js(`document.activeElement.matches('.rchips [data-img]')`)); }
{ const h0 = await js('location.hash'); await js(`document.querySelector('.feed td.art a[data-img]').dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true,ctrlKey:true}))`); await sleep(200);
  ok('Ctrl-click on a picture opens no dialog (the browser follows the link)', await js(`!document.querySelector('#modal').innerHTML`)); if (await js('location.hash') !== h0) { await go(h0); } }
ok('the Category menu separator is visible on the raised ground (dark)', await (async () => { await open('cat'); const r = await js(`(()=>{const s=getComputedStyle(document.querySelector('.fbar .sfm .msep')).backgroundColor,m=getComputedStyle(document.querySelector('.fbar .sfm .mmenu')).backgroundColor;return s!==m;})()`); await close(); return r; })());
ok('the picture opens the image it shows (preview, else sample)', await every(`(()=>{const a=document.querySelector('[data-select="'+r.id+'"]').closest('tr').querySelector('td.art a');return !a.querySelector('img')||a.dataset.kind===(x.pv&&imgFor(r,'preview')?'preview':'sample');})()`));
await js(`document.querySelector('[data-clearall]').click()`); await sleep(150);
await open('cat'); await js(`document.querySelector('[data-ddexo]').click()`); await sleep(200);
ok('the menu item reads "Exclude Other"', await js(`document.querySelector('[data-ddexo]').textContent.trim()==='Exclude Other'`));
ok('Category "Exclude Other" (his wording) picks every category but Other and reads so', await js(`isExo()&&!state.cat.has('Other')&&document.querySelector('[data-msel=cat] .v').textContent==='Exclude Other'&&document.querySelector('[data-ddexo]').getAttribute('aria-checked')==='true'`));
ok('Exclude Other: no Other release listed, count = all but Other', await count() === await js(`REL.filter(r=>catOf(r)!=='Other').length`) && await every(`catOf(r)!=='Other'`));
ok('Exclude Other: the menu stays open, focus on the item', await js(`state.dd==='cat'&&document.activeElement.matches('[data-ddexo]')`));
await js(`document.querySelector('[data-ddpick=cat][data-v="Other"]').click()`); await sleep(150);
ok('ticking Other as well turns it into a plain count', await js(`!isExo()&&document.querySelector('[data-msel=cat] .v').textContent===state.cat.size+' chosen'`));
await js(`document.querySelector('[data-ddexo]').click()`); await sleep(150); await js(`document.querySelector('[data-ddexo]').click()`); await sleep(150);
ok('picking Exclude Other again clears the category filter', await js(`state.cat.size===0`));
await close();

// ---- chips ----
await go('#/'); await pick('cat', 'x264'); await close();
ok('chip order: completion, Password, media info, NFO, Preview, Sample, Clip', await js(`[...document.querySelectorAll('.feed .rchips')].every(c=>{const o=['comp','pw','k-mi','k-nfo','k-pv','k-sm','k-cl'];const k=[...c.children].filter(e=>!e.matches('.orig')).map(e=>o.findIndex(n=>n==='pw'?e.textContent.includes('Password'):e.classList.contains(n)));return k.every((v,i)=>v>=0&&(!i||k[i-1]<v));})`));
ok('Clip chip on every release with a clip, and only there', await every(`!!document.querySelector('[data-select="'+r.id+'"]').closest('tr').querySelector('[data-clip]')===!!x.clip`));
ok('Clip chip hue differs from every other chip kind (both themes)', await js(`(()=>{const out=[];for(const th of ['dark','light']){document.documentElement.dataset.theme=th;const bg=s=>{const e=document.querySelector('.feed '+s);return e&&getComputedStyle(e).backgroundColor;};const c=bg('.k-cl');out.push(!!c&&['.k-pv','.k-sm','.k-mi','.comp'].map(bg).filter(Boolean).every(o=>o!==c));}document.documentElement.dataset.theme='dark';return out.every(Boolean);})()`));
const contrast = await js(`(()=>{const cv=document.createElement('canvas').getContext('2d');const rgb=c=>{cv.fillStyle='#000';cv.fillStyle=c;cv.fillRect(0,0,1,1);return [...cv.getImageData(0,0,1,1).data].slice(0,3).join(',');};const L=c=>{const [r,g,b]=rgb(c).split(',').map(Number).map(v=>{v/=255;return v<=.03928?v/12.92:((v+.055)/1.055)**2.4;});return .2126*r+.7152*g+.0722*b;};const cr=(a,b)=>{const x=L(a),y=L(b);return (Math.max(x,y)+.05)/(Math.min(x,y)+.05);};const out=[];for(const th of ['dark','light']){document.documentElement.dataset.theme=th;const e=document.querySelector('.feed .k-cl'),s=getComputedStyle(e);out.push(cr(s.color,s.backgroundColor));}document.documentElement.dataset.theme='dark';return out;})()`);
ok('Clip chip text contrast >= 4.5 in both themes', contrast && contrast.every(v => v >= 4.5), JSON.stringify(contrast));
await js(`(()=>{const b=document.querySelector('.feed [data-clip]');b.focus();b.click();})()`); await sleep(300);
ok('Clip opens the video clip dialog', await js(`document.querySelector('#modal [role=dialog]').getAttribute('aria-label')==='Video clip'`));
await key('Escape'); await sleep(150);
ok('Escape closes the dialog and focus returns to the chip', await js(`!document.querySelector('#modal').innerHTML&&document.activeElement.matches('[data-clip]')`));
await js(`document.querySelector('.feed [data-img]').click()`); await sleep(300);
ok('Preview opens the image dialog', await js(`/image$/.test(document.querySelector('#modal [role=dialog]').getAttribute('aria-label'))`));
await key('Escape'); await sleep(100);
await js(`document.querySelector('.feed [data-mi]').click()`); await sleep(1200);
ok('media info chip opens the media info dialog', await js(`!!document.querySelector('#modal .mi2')`));
await key('Escape'); await sleep(100);

// ---- filters ----
await go('#/p/3'); await js(`location.hash='#/p/3'`); await sleep(200);
await pick('res', '1080p');
ok('a filter change returns to page 1 (URL #/)', await js(`state.page===1&&(location.hash==='#/'||location.hash==='')`));
ok('filters AND between menus (x264 and 1080p)', await every(`catOf(r)==='x264'&&r.res==='1080p'`));
await pick('res', '720p');
ok('OR within a menu (1080p or 720p)', await every(`catOf(r)==='x264'&&['1080p','720p'].includes(r.res)`));
ok('menu stays open while ticking', await js(`state.dd==='res'&&!!document.querySelector('[data-msel=res] .mmenu')`));
ok('a set cell reads "2 chosen" with a coral line under it', await js(`(()=>{const m=document.querySelector('[data-msel=res]');return m.classList.contains('set')&&m.querySelector('.v').textContent==='2 chosen';})()`));
await close();
ok('Category menu lists the sub-categories in the site\'s order and searches (more than 10)', await (async () => { await open('cat'); const r = await js(`(()=>{const v=[...document.querySelectorAll('[data-ddpick=cat]')].map(b=>b.dataset.v);return v.length>10&&!!document.querySelector('[data-msel=cat] [data-msearch]')&&v.join(',')===CAT_ORDER.filter(c=>v.includes(c)).join(',');})()`); await close(); return r; })());
await open('cat');
ok('Category menu has VR and no OnlyFans (his call)', await js(`(()=>{const v=[...document.querySelectorAll('[data-ddpick=cat]')].map(b=>b.dataset.v);return v.includes('VR')&&!v.includes('OnlyFans');})()`));
await close();
await open('aud');
ok('Audio menu: English first, A to Z, then Unknown', await js(`(()=>{const v=[...document.querySelectorAll('[data-ddpick=aud]')].map(b=>b.dataset.v);const mid=v.slice(1,-1);return v[0]==='English'&&v.at(-1)==='Unknown'&&mid.every((x,i)=>!i||mid[i-1].localeCompare(x)<0);})()`));
await close();
const pagerX = () => js(`Math.round(document.querySelector('.pager.slim [aria-label="Previous page"]').getBoundingClientRect().left)`);
const withF = await pagerX();
await js(`document.querySelector('[data-clearall]').click()`); await sleep(200);
ok('Clear all clears every filter', await js(`!anySet()`) && await count() === TOTAL);
ok('Clear all is hidden in place, the page arrows do not move', await pagerX() === withF && await js(`document.querySelector('[data-clearall]').getAttribute('aria-hidden')==='true'`));
await open('comp'); await js(`document.querySelector('[data-cpick="100"]').click()`); await sleep(150);
ok('Completion 100% only', await every(`r.comp>=100`));
await js(`document.querySelector('[data-clearall]').click()`); await sleep(150);

// ---- name search ----
await js(`(()=>{const q=document.querySelector('#q');q.focus();q.value=${JSON.stringify(FX.search)};q.dispatchEvent(new Event('input',{bubbles:true}));})()`); await sleep(500);
const want = await js(`REL.filter(r=>r.name.toLowerCase().includes(${JSON.stringify(FX.search.toLowerCase())})).length`);
ok('name search narrows to names containing the words', await count() === want && want > 0 && await every(`r.name.toLowerCase().includes(${JSON.stringify(FX.search.toLowerCase())})`), `${want}`);
ok('typing keeps focus and the caret in the field', await js(`document.activeElement.id==='q'&&document.activeElement.selectionStart===${FX.search.length}`));
await pick('cat', 'x264'); await close();
ok('name search combines with the filters', await every(`catOf(r)==='x264'&&r.name.toLowerCase().includes(${JSON.stringify(FX.search.toLowerCase())})`) || await count() === 0);
await js(`document.querySelector('[data-clearq]').click()`); await sleep(200);
ok('the clear button empties only the name search', await js(`state.q===''&&document.querySelector('#q').value===''&&state.cat.has('x264')`));
await js(`(()=>{const q=document.querySelector('#q');q.value='zzqqxxnothing';q.dispatchEvent(new Event('input',{bubbles:true}));})()`); await sleep(500);
ok('no match: a line naming the filters and the search in words', /^No releases match x264 · names containing “zzqqxxnothing”\.$/.test(await js(`document.querySelector('.empty').textContent`)));
await js(`document.querySelector('[data-clearall]').click()`); await sleep(200);
ok('Clear all also empties the name search', await js(`state.q===''&&document.querySelector('#q').value===''`) && await count() === TOTAL);

// ---- sort, pages ----
await js(`(()=>{const s=document.querySelector('[data-rsort]');s.value='added_desc';s.dispatchEvent(new Event('change',{bubbles:true}));})()`); await sleep(200);
ok('Added sort: newest added first, the date column says Added', await js(`(()=>{const t=${ROWIDS}.map(i=>X(BYID[i]).added);return t.every((v,i)=>!i||t[i-1]>=v);})()`) && await js(`[...document.querySelectorAll('.feed thead th')].some(t=>t.textContent==='Added')`));
ok('the sort is remembered', await js(`localStorage.getItem('nntmux.adult.rsort')`) === 'added_desc');
await js(`(()=>{const s=document.querySelector('[data-rsort]');s.value='posted_desc';s.dispatchEvent(new Event('change',{bubbles:true}));})()`); await sleep(200);
const last = Math.ceil(TOTAL / 50);
await go('#/p/' + last);
ok('the last page is reachable and holds the rest', await js(`${ROWIDS}.length`) === TOTAL - (last - 1) * 50 && await js(`document.querySelector('.pager.slim .pg').textContent`) === `Page ${last} of ${last}`);
await js(`(()=>{const f=document.querySelector('[data-goto]');f.querySelector('input').value='7';f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));})()`); await sleep(300);
ok('Go to page', await js(`location.hash`) === '#/p/7' && await js(`state.page`) === 7);
await go('#/');
await js(`document.querySelector('.feed [data-select]').click()`); await sleep(150);
ok('selecting a row shows the selection bar', /^1 selected/.test(await js(`document.querySelector('.bulk').textContent`)));
await js(`document.querySelector('[data-bulk=clear]').click()`); await sleep(150);
await js(`document.querySelector('.feed [data-cart]').click()`); await sleep(150);
ok('a pressed Cart fills in its own green, never coral', await js(`(()=>{const b=document.querySelector('.feed [data-cart][aria-pressed=true]'),c=getComputedStyle(b).backgroundColor,a=getComputedStyle(document.querySelector('.feed .ia.dl')).backgroundColor;return c!==a;})()`));
await js(`document.querySelector('.feed [data-cart]').click()`); await sleep(100);

// ---- remembered filters (his call 2026-09-29: the release lists remember their dropdown filters, never the name search) ----
await go('#/'); await js(`document.querySelector('[data-clearall]')?.click()`); await sleep(150);
await pick('cat', 'x264'); await pick('res', '1080p'); await close(); await open('comp'); await js(`document.querySelector('[data-cpick="95"]').click()`); await sleep(150);
await js(`(()=>{const q=document.querySelector('#q');q.value=${JSON.stringify(FX.search)};q.dispatchEvent(new Event('input',{bubbles:true}));})()`); await sleep(500);
await load();
ok('after a reload the dropdown filters are back', await js(`state.cat.has('x264')&&state.cat.size===1&&state.res.has('1080p')&&state.comp==='95'`));
ok('the name search is not remembered', await js(`state.q===''&&document.querySelector('#q').value===''`));
ok('the page starts at 1 with the remembered filters', await js(`state.page===1`) && /^Showing 1–/.test(await js(`document.querySelector('.pager.slim .sum').textContent`)));
await js(`(()=>{state.cat.clear();EXO().forEach(c=>state.cat.add(c));saveFilters();})()`); await load();
ok('a remembered "Exclude Other" comes back as Exclude Other (a mode, not ids)', await js(`isExo()&&JSON.parse(localStorage.getItem('nntmux.adult.filters')).cat==='exo'`));
await js(`document.querySelector('[data-clearall]').click()`); await sleep(150); await load();
ok('Clear all is remembered too: after a reload nothing is set', await js(`!anySet()`));

// ---- details ----
await go('#/release/' + FX.clip); await sleep(600);
ok('details: breadcrumb Adult releases › sub-category', await js(`document.querySelector('.crumbs').textContent.startsWith('Adult releases›')`));
ok('details: the release name is the heading, full width, no aside', await js(`document.querySelector('.dhead h1.relonly').textContent===BYID[${FX.clip}].name&&!document.querySelector('.aboutshow')&&document.querySelector('.dcols').classList.contains('noshow')`));
ok('details: no source chip in the header (his call)', await js(`!document.querySelector('.dhead .chip.src')`));
ok('details: header buttons Download, Copy NZB link, Add to cart; no Follow', await js(`[...document.querySelectorAll('.dacts .btn')].map(b=>b.innerText.trim()).join('|')`) === 'Download NZB|Copy NZB link|Add to cart');
ok('details: tabs Overview, Files, Media info, NFO, Comments', await js(`[...document.querySelectorAll('.tabs button')].map(b=>b.textContent.replace(/ \\(\\d+\\)/,'')).join(',')`) === 'Overview,Files,Media info,NFO,Comments');
ok('details: the preview with a clip shows a play button and a "Clip · N s" tag beside "Preview"', await js(`(()=>{const b=document.querySelector('.adpics .pvthumb.hasclip');const x=X(BYID[${FX.clip}]);return !!b&&!!b.querySelector('.play svg')&&b.querySelector('.lbl.cl').textContent==='Clip'+(x.clip[1]?' · '+x.clip[1]+' s':'')&&b.querySelector('.lbl:not(.cl)').textContent==='Preview';})()`));
ok('details: the play button sits in the middle of the picture', await js(`(()=>{const b=document.querySelector('.adpics .pvthumb.hasclip').getBoundingClientRect(),p=document.querySelector('.adpics .pvthumb.hasclip .play').getBoundingClientRect();return Math.abs((p.left+p.width/2)-(b.left+b.width/2))<2&&Math.abs((p.top+p.height/2)-(b.top+b.height/2))<2;})()`));
ok('details: no separate Clip box; the preview is the only picture box for the clip', await js(`!document.querySelector('.clipbtn')&&document.querySelectorAll('.adpics [data-clip]').length===1&&!!document.querySelector('.adpics [data-clip] img')`));
await js(`(()=>{const b=document.querySelector('.adpics [data-clip]');b.focus();b.click();})()`); await sleep(500);
ok('details: clicking the preview opens the clip dialog, as the Clip chip does', await js(`document.querySelector('#modal [role=dialog]').getAttribute('aria-label')==='Video clip'&&(CLIPS.size?!!document.querySelector('#modal video.clipv'):true)`));
await key('Escape'); await sleep(150);
ok('details: Escape closes it and focus returns to the preview', await js(`!document.querySelector('#modal').innerHTML&&document.activeElement.matches('.adpics [data-clip]')`));
await js(`document.querySelector('.dhead [data-clip]').click()`); await sleep(400);
ok('details: the header Clip chip opens the same dialog', await js(`document.querySelector('#modal [role=dialog]').getAttribute('aria-label')==='Video clip'`));
await key('Escape'); await sleep(100);
await js(`document.querySelector('.dacts [data-cart]').click()`); await sleep(100);
ok('details: pressed Add to cart reads In cart without moving the buttons', await js(`document.querySelector('.dacts [data-cart]').getAttribute('aria-pressed')==='true'&&document.querySelector('.dacts [data-cart] .wl span:not([hidden])').textContent==='In cart'`));
await go('#/release/' + FX.similar); await sleep(400);
ok('details: Similar releases table has no Source column', await js(`![...document.querySelectorAll('.simrel thead th')].some(t=>t.textContent.trim()==='Source')`));
ok('details: Similar releases table (today\'s query), without this release', await js(`(()=>{const s=document.querySelector('.simrel');return !!s&&s.querySelectorAll('tbody tr').length===SIM[${FX.similar}].length&&!s.querySelector('a[href="#/release/${FX.similar}"]');})()`));
ok('details: an unknown file count reads a dash, never 0', await js(`[...document.querySelectorAll('.simrel tbody tr')].every(tr=>{const id=+tr.querySelector('[data-nzb]').dataset.nzb,c=tr.children[3];return X(BYID[id]).files?c.querySelector('[data-files]').textContent==String(X(BYID[id]).files):c.textContent==='—';})`) && await js(`[...document.querySelectorAll('.simrel tbody tr')].some(tr=>tr.children[3].textContent==='—')`));
await go('#/release/' + FX.clip); await sleep(400);
if (await js(`!!document.querySelector('.adpics [data-kind=sample]')`)) { await js(`document.querySelector('.adpics [data-kind=sample]').click()`); await sleep(900);
  ok('details: the Sample picture opens straight at full size', await js(`document.querySelector('#modal .dlg').classList.contains('full')`)); await key('Escape'); await sleep(150); }
else { const sid = await js(`REL.find(r=>X(r).jpg&&X(r).pv&&PV.has('sample/'+X(r).guid+'.webp'))?.id`); await go('#/release/' + sid); await sleep(400); await js(`document.querySelector('.adpics [data-kind=sample]').click()`); await sleep(900);
  ok('details: the Sample picture opens straight at full size', await js(`document.querySelector('#modal .dlg').classList.contains('full')`)); await key('Escape'); await sleep(150); }
ok('the list\'s Sample chip still opens the fitted dialog (unchanged)', await (async () => { await go('#/'); await sleep(300); const b = await js(`!!document.querySelector('.feed .rchips [data-kind=sample]')`); if (!b) { await pick('cat', 'x264'); await close(); } await js(`document.querySelector('.feed .rchips [data-kind=sample]').click()`); await sleep(800); const r = await js(`!document.querySelector('#modal .dlg').classList.contains('full')`); await key('Escape'); await sleep(100); return r; })());
await go('#/release/' + FX.predb); await sleep(300);
ok('details: PreDB block when the release has one', await js(`!!document.querySelector('.predb')`));
await go('#/release/' + FX.nopic); await sleep(300);
ok('details: a release with no picture shows no picture box', await js(`!document.querySelector('.adpics')`));
await js(`document.querySelector('[data-tab=media]').click()`); await sleep(1200);
ok('details: Media info tab draws after loading', await js(`!!document.querySelector('.panel .mi2, .panel .note')`));
await go('#/'); await sleep(200);

ok('no script errors', errors.length === 0, errors.slice(0, 3).join(' | '));
const fails = results.filter(r => r[0] === 'FAIL');
for (const [s, n, x] of results) if (s === 'FAIL' || process.env.ALL) console.log(s, n, x);
console.log(`${results.length} checks, ${fails.length} failures`);
ws.close(); chrome.kill('SIGKILL');
process.exit(fails.length ? 1 : 0);
