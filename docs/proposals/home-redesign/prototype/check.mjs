// Drives headless Chrome over CDP: clicks every control of the four home-page mockups (home.html) and reports pass/fail.
// One check per rule the mockups claim.   node hmcheck.mjs      (V=1 prints progress on stderr)
import {spawn} from 'node:child_process';
import {mkdtempSync} from 'node:fs';
import {tmpdir} from 'node:os';
const BASE = process.env.HM_BASE || 'http://localhost:8769/', PORT = 9393;
const ONLY = process.env.ONLY === '2'; // the public copy carries the approved Shelves page alone: the other mockups' checks are skipped
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', ['--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(tmpdir() + '/hmcdp-')}`, 'about:blank'], {stdio: 'ignore'});
const sleep = ms => new Promise(r => setTimeout(r, ms));
const results = []; let ws, seq = 0; const pending = new Map(), errors = [];
for (let i = 0; i < 40; i++) { try { const t = await (await fetch(`http://localhost:${PORT}/json`)).json(); const p = t.find(x => x.type === 'page'); if (p) { ws = new WebSocket(p.webSocketDebuggerUrl); await new Promise(r => ws.onopen = r); break; } } catch {} await sleep(250); }
ws.onmessage = m => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d.result); pending.delete(d.id); } if (d.method === 'Runtime.exceptionThrown') errors.push(d.params.exceptionDetails.exception?.description || d.params.exceptionDetails.text); };
const send = (method, params = {}) => new Promise(r => { const id = ++seq; pending.set(id, r); ws.send(JSON.stringify({id, method, params})); });
const js = async expr => { const r = await send('Runtime.evaluate', {expression: expr, awaitPromise: true, returnByValue: true}); if (r.exceptionDetails) { results.push(['FAIL', 'eval threw', expr.slice(0, 90) + ' :: ' + (r.exceptionDetails.exception?.description || '').split('\n')[0]]); return undefined; } return r.result.value; };
const ok = (name, cond, extra = '') => { results.push([cond ? 'PASS' : 'FAIL', name, extra]); if (process.env.V) console.error((cond ? 'PASS ' : 'FAIL ') + name + (cond ? '' : ' ' + extra)); };
const key = (k, code) => send('Input.dispatchKeyEvent', {type: 'keyDown', key: k, code: code || k, windowsVirtualKeyCode: k === 'Escape' ? 27 : 0}).then(() => send('Input.dispatchKeyEvent', {type: 'keyUp', key: k, code: code || k}));
const load = async (hash = '#/1') => { await send('Page.navigate', {url: BASE + 'home.html?r=' + Math.random() + hash}); for (let i = 0; i < 60 && !(await js('!!window.READY')); i++) await sleep(200); await sleep(300); };
const go = async hash => { await js(`location.hash=${JSON.stringify(hash)}`); await sleep(350); };
const click = async sel => { const r = await js(`(()=>{const e=document.querySelector(${JSON.stringify(sel)});if(!e)return false;e.click();return true;})()`); await sleep(250); return r; };
const text = sel => js(`document.querySelector(${JSON.stringify(sel)})?.textContent.trim()`);
const count = sel => js(`document.querySelectorAll(${JSON.stringify(sel)}).length`);
const noOverflow = () => js(`[...document.querySelectorAll('.blk')].every(b=>b.scrollHeight-b.clientHeight<=1)`);
const rect = sel => js(`(()=>{const e=document.querySelector(${JSON.stringify(sel)});if(!e)return null;const r=e.getBoundingClientRect();return [r.left,r.top,r.width,r.height];})()`);
// a real pointer drag: press on a grip, move in steps to the target's top (or left) edge, release
const dragTo = async (gripSel, targetSel, side = 'before') => { const a = await rect(gripSel), b = await rect(targetSel); if (!a || !b) return false;
  const sx = a[0] + a[2] / 2, sy = a[1] + a[3] / 2; const tx = side === 'left' ? b[0] + 6 : side === 'right' ? b[0] + b[2] - 6 : b[0] + b[2] / 2; const ty = side === 'before' ? b[1] + 4 : side === 'after' ? b[1] + b[3] - 4 : b[1] + b[3] / 2;
  await send('Input.dispatchMouseEvent', {type: 'mousePressed', x: sx, y: sy, button: 'left', clickCount: 1});
  for (let i = 1; i <= 8; i++) { await send('Input.dispatchMouseEvent', {type: 'mouseMoved', x: sx + (tx - sx) * i / 8, y: sy + (ty - sy) * i / 8, button: 'left'}); await sleep(25); }
  await send('Input.dispatchMouseEvent', {type: 'mouseReleased', x: tx, y: ty, button: 'left', clickCount: 1}); await sleep(350); return true; };

await send('Runtime.enable'); await send('Page.enable'); await send('Emulation.setFocusEmulationEnabled', {enabled: true});
await send('Emulation.setDeviceMetricsOverride', {width: 1600, height: 1000, deviceScaleFactor: 1, mobile: false});

try {
  await load(); await js('localStorage.clear()'); await load(ONLY ? '#/2' : '#/1');
  // ---- shared: header, selector, theme, row buttons ----
  ok('the header carries the nine category buttons of #908', await count('#navlinks a.l') === 9);
  if (ONLY) { ok('the public copy carries the Shelves page alone, no selector', await count('.proto') === 0 && await count('.hsec.shelf') > 0); await click('[data-shelf=TV] .rail .tile'); }
  else {
    ok('the selector offers the four mockups and marks the current one', await count('.proto [data-m]') === 4 && await js(`document.querySelector('.proto [data-m="1"]').getAttribute('aria-pressed')==='true'`));
    await click('.proto [data-m="2"]'); ok('picking a mockup changes the hash and the page', await js('location.hash') === '#/2' && await count('.hsec.shelf') > 0);
    await load('#/1');
  }
  ok('no implementation-speak in the page copy', await js(`!/bounded query|indexed query|mockup's slice/i.test(document.getElementById('app').innerText)`));
  ok('the theme button flips light and dark', await click('#theme') && await js(`document.documentElement.dataset.theme`) === 'light' && await click('#theme') && await js(`document.documentElement.dataset.theme`) === 'dark');
  const cart0 = +(await text('#cartn'));
  const firstCart = await js(`document.querySelector('.feed [data-cart]').dataset.cart`);
  await click(`.feed [data-cart="${firstCart}"]`);
  ok('a row’s Cart button adds the release and the header count follows', +(await text('#cartn')) === cart0 + 1 && await js(`document.querySelector('.feed [data-cart="${firstCart}"]').getAttribute('aria-pressed')==='true'`));
  await click(`.feed [data-cart="${firstCart}"]`); ok('clicking Cart again removes it', +(await text('#cartn')) === cart0);
  const firstNzb = await js(`document.querySelector('.feed [data-copynzb]').dataset.copynzb`);
  await click(`.feed [data-copynzb="${firstNzb}"]`); ok('Copy link puts the NZB link on the clipboard', await js(`(window.__copied||'').includes(BYID[${firstNzb}].guid)`));
  await click('.feed .rname'); ok('a release name says it opens the release page in the app', await js(`document.getElementById('toast').classList.contains('on')&&/release page/.test(document.getElementById('toast').textContent)`));

  if (!ONLY) {
  // ---- idea 1: Following first ----
  await load('#/1');
  const withNew = await js(`followed().filter(f=>f.newN>0).length`), followedN = await js(`followed().length`);
  ok('idea 1: the Following strip shows only the titles with something new since the last visit', await count('[data-sec=following] .tiles.strip .tile') === withNew && withNew > 0);
  ok('idea 1: every tile in the strip carries its "N new" badge, and the badge is not coral', await js(`[...document.querySelectorAll('[data-sec=following] .tiles.strip .tile .count')].length===${withNew}`) && await js(`getComputedStyle(document.querySelector('[data-sec=following] .tile .count')).backgroundColor!==getComputedStyle(document.querySelector('.ia.dl')).backgroundColor`));
  ok('idea 1: one row per followed title with something new above the last-visit line (6 titles here: the line sits one row below the first screen at 1600 × 1000; it enters the first screen with 4 or fewer)', await js(`[...document.querySelectorAll('[data-sec=following] .feed tbody tr')].findIndex(r=>r.classList.contains('visit'))===${withNew}`) && await count('[data-sec=following] .feed tbody tr:not(.visit)') <= withNew + 4 && await js(`document.querySelector('[data-sec=following] tr.visit').getBoundingClientRect().top-document.querySelector('.proto').getBoundingClientRect().height<1000+111*Math.max(0,${withNew}-4)`));
  ok('no implementation-speak in the page copy', await js(`!/bounded query|indexed query|mockup's slice/i.test(document.getElementById('app').innerText)`));
  ok('idea 1: the rest of the followed titles are one line, not more posters', await js(`/${followedN - withNew} more followed titles have nothing new/.test(document.querySelector('[data-sec=following] .tiles-rest')?.textContent)`));
  ok('idea 1: the last-visit hairline sits in the list and everything above it is newer than the last visit', await js(`(()=>{const rows=[...document.querySelectorAll('[data-sec=following] .feed tbody tr')];const i=rows.findIndex(r=>r.classList.contains('visit'));if(i<1)return false;const id=r=>+r.querySelector('[data-nzb]').dataset.nzb;return rows.slice(0,i).every(r=>BYID[id(r)].added>LAST_VISIT)&&rows.slice(i+1).every(r=>BYID[id(r)].added<=LAST_VISIT);})()`));
  ok('idea 1: the default sections are TV, Movies and Audio, five rows each', await js(`[...document.querySelectorAll('.hsec[data-sec]:not([data-sec=following])')].map(s=>s.dataset.sec).join()==='TV,Movies,Audio'`) && await js(`[...document.querySelectorAll('.hsec[data-sec]:not([data-sec=following]) .feed tbody tr')].length===15`));
  ok('idea 1: inside one section the Category column shows the sub-category alone', await js(`document.querySelector('[data-sec=TV] .feed td.c-cat').textContent.trim()`) !== undefined && await js(`!/>/.test(document.querySelector('[data-sec=TV] .feed td.c-cat').textContent)`));
  ok('idea 1: the Following rows mix sections, so their Category column reads Root > Sub', await js(`/>/.test(document.querySelector('[data-sec=following] .feed td.c-cat').textContent)`));
  await click('[data-cust=m1]'); ok('idea 1: Customize opens a dialog', await count('#modal .dlg') === 1);
  await click('#modal [data-m1sec=Movies]'); ok('idea 1: unticking Movies removes the section at once', await count('.hsec[data-sec=Movies]') === 0);
  ok('no arrow buttons anywhere: reordering is drag and drop', await count('[data-m1mv],[data-m2mv],[data-vmv],[data-blkmv]') === 0 && await count('#modal [data-grip]') > 0);
  await dragTo('#modal .row[data-key=Audio] [data-grip]', '#modal .row[data-key=TV]', 'before'); ok('idea 1: dragging Audio above TV puts it before TV', await js(`[...document.querySelectorAll('.hsec[data-sec]:not([data-sec=following])')].map(s=>s.dataset.sec).join()==='Audio,TV'`));
  await click('#modal [data-m1rows="10"]'); ok('idea 1: ten rows per section', await count('.hsec[data-sec=TV] .feed tbody tr') === 10);
  await click('#modal [data-m1follow]'); ok('idea 1: the Following section can be switched off', await count('[data-sec=following]') === 0);
  await key('Escape'); ok('Escape closes the dialog', await count('#modal .dlg') === 0);
  await load('#/1'); ok('idea 1: the page remembers the customization after a reload', await js(`[...document.querySelectorAll('.hsec[data-sec]')].map(s=>s.dataset.sec).join()==='Audio,TV'`) && await count('.hsec[data-sec=TV] .feed tbody tr') === 10);
  await click('[data-cust=m1]'); await click('#modal [data-m1follow]'); await click('#modal [data-m1sec=Movies]'); await dragTo('#modal .row[data-key=Audio] [data-grip]', '#modal .row[data-key=Movies]', 'after'); await click('#modal [data-m1rows="5"]'); await key('Escape');
  const fk = await js(`document.querySelector('[data-sec=following] .feed [data-watch]').dataset.watch`);
  await click(`[data-sec=following] .feed [data-watch="${fk}"]`); ok('idea 1: unfollowing from a row takes the title out of the strip', await js(`!state.follow.has(${JSON.stringify(fk)})`) && await count('[data-sec=following] .tiles.strip .tile') === withNew - 1);
  await js(`state.follow.add(${JSON.stringify(fk)});saveUser();render()`);
  await js(`state.follow.clear();render()`); ok('idea 1: with nothing followed the section explains how to follow', await js(`/Nothing followed yet/.test(document.querySelector('[data-sec=following] .empty')?.textContent)`));
  await load('#/1'); await js('localStorage.clear()'); await load('#/2');

  }
  await js('localStorage.clear()'); await load('#/2');
  // ---- idea 2: Shelves ----
  ok('idea 2: five shelves by default, Following first', await js(`[...document.querySelectorAll('.hsec.shelf')].map(s=>s.dataset.shelf).join()==='Following,TV,Movies,Audio,Books'`));
  ok('idea 2: the TV shelf holds one tile per show with new episodes today', await count('[data-shelf=TV] .rail .tile') === await js('D.newEpisodes24.length'));
  ok('idea 2: the Movies shelf holds one tile per film posted this week', await count('[data-shelf=Movies] .rail .tile') === await js('D.newFilms7.length'));
  ok('idea 2: every rail tile is the same width, whatever its name, and shelves follow each other without a blank band', await js(`[...document.querySelectorAll('.rail')].every(r=>[...r.querySelectorAll('.tile')].every(t=>Math.abs(t.getBoundingClientRect().width-148)<1))`) && await js(`(()=>{const s=[...document.querySelectorAll('.hsec.shelf')];return s.every((x,i)=>i===0||x.getBoundingClientRect().top-s[i-1].getBoundingClientRect().bottom<60);})()`));
  ok('idea 2: album tiles are square',await js(`(()=>{const a=document.querySelector('[data-shelf=Audio] .tile .art').getBoundingClientRect();return Math.abs(a.width-a.height)<1;})()`));
  ok('idea 2: a tile’s title sits right under its artwork (no gap from button centring)', await js(`(()=>{const t=document.querySelector('[data-shelf=Audio] .rail .tile:nth-child(3)');const a=t.querySelector('.art').getBoundingClientRect(),b=t.querySelector('b').getBoundingClientRect();return b.top-a.bottom<16&&a.top-t.getBoundingClientRect().top<2;})()`));
  const tvTile = await js(`document.querySelector('[data-shelf=TV] .rail .tile').dataset.tile`);
  await click(`[data-shelf=TV] [data-tile="${tvTile}"]`); ok('idea 2: a show tile opens a panel of its releases under the rail', await count('[data-shelf=TV] .shelfpanel') === 1 && await count('[data-shelf=TV] .shelfpanel .feed tbody tr') > 0 && await js(`document.querySelector('[data-shelf=TV] [data-tile="${tvTile}"]').getAttribute('aria-expanded')==='true'`));
  const tvTile2 = await js(`document.querySelector('[data-shelf=TV] .rail .tile:nth-child(2)').dataset.tile`);
  await click(`[data-shelf=TV] [data-tile="${tvTile2}"]`); ok('idea 2: another tile swaps the panel', await js(`document.querySelector('[data-shelf=TV] .shelfpanel').dataset.panel==='${tvTile2}'`) && await count('.shelfpanel') === 1);
  await click('[data-closepanel]'); ok('idea 2: the panel closes', await count('.shelfpanel') === 0);
  await click(`[data-shelf=TV] [data-tile="${tvTile2}"]`); await key('Escape'); ok('idea 2: Escape closes the panel', await count('.shelfpanel') === 0);
  const sl0 = await js(`document.querySelector('.rail[data-railof=TV]').scrollLeft`); await click('[data-rail="TV:1"]'); await sleep(700);
  ok('idea 2: the right arrow scrolls the rail', await js(`document.querySelector('.rail[data-railof=TV]').scrollLeft`) > sl0);
  const bookTile = await js(`document.querySelector('[data-shelf=Books] .rail .tile').dataset.tile`);
  await click(`[data-shelf=Books] [data-tile="${bookTile}"]`); ok('idea 2: a release card opens its own row with the four buttons', await count('[data-shelf=Books] .shelfpanel .feed tbody tr') === 1 && await count('[data-shelf=Books] .shelfpanel [data-nzb]') === 1);
  await click('[data-cust=m2]'); await click('#modal [data-m2on=Books]'); ok('idea 2: unticking Books removes the shelf', await count('[data-shelf=Books]') === 0);
  { const a = await rect('#modal .row[data-key=Movies] [data-grip]'); await send('Input.dispatchMouseEvent', {type: 'mousePressed', x: a[0] + 10, y: a[1] + 10, button: 'left', clickCount: 1}); await send('Input.dispatchMouseEvent', {type: 'mouseMoved', x: a[0] - 30, y: a[1] + 60, button: 'left'}); await sleep(150);
    ok('idea 2: a pointer drag lifts a styled ghost of the row (box, label, grip) and leaves a faded gap', await js(`(()=>{const g=document.querySelector('.ghost');if(!g)return false;const box=g.querySelector('.box'),lbl=g.querySelector('.lbl');return !!box&&!!lbl&&getComputedStyle(box).borderRadius!=='0px'&&getComputedStyle(lbl).display!=='inline'&&g.querySelector('.grip')&&+getComputedStyle(document.querySelector('#modal .row.dragging')).opacity<.5;})()`));
    await send('Input.dispatchMouseEvent', {type: 'mouseReleased', x: a[0] - 30, y: a[1] + 60, button: 'left', clickCount: 1}); await sleep(300); }
  await dragTo('#modal .row[data-key=Movies] [data-grip]', '#modal .row[data-key=TV]', 'before'); ok('idea 2: dragging Movies above TV puts it before TV', await js(`[...document.querySelectorAll('.hsec.shelf')].map(s=>s.dataset.shelf).join()==='Following,Movies,TV,Audio'`));
  await js(`document.querySelector('#modal .row[data-key=TV] [data-grip]').focus()`); await key(' ', 'Space'); await key('ArrowUp'); await key('ArrowUp'); await key(' ', 'Space');
  ok('idea 2: the keyboard path (Space, arrows, Space) moves TV to the top', await js(`[...document.querySelectorAll('.hsec.shelf')].map(s=>s.dataset.shelf).join()==='TV,Following,Movies,Audio'`));
  await dragTo('#modal .row[data-key=TV] [data-grip]', '#modal .row[data-key=Movies]', 'after');
  await js(`document.querySelector('#modal .row[data-key=Audio] [data-grip]').focus()`); await key(' ', 'Space'); await key('ArrowUp');
  ok('idea 2: a keyboard move announces the new position', await js(`/Audio · position 3 of 9/.test(document.getElementById('toast').textContent)`));
  await key('Escape'); ok('idea 2: Escape with a grabbed row puts it back and keeps the dialog open', await count('#modal .dlg') === 1 && await js(`[...document.querySelectorAll('#modal .row[data-key]')].map(r=>r.dataset.key).slice(0,4).join()==='Following,Movies,TV,Audio'`) && await js(`document.querySelector('#modal .row[data-key=Audio] [data-grip]').getAttribute('aria-pressed')==='false'`));
  await click('#modal [data-m2on=Adult]'); ok('idea 2: the Adult shelf shows preview pictures 16:9 on the releases that have one', await count('[data-shelf=Adult] .tile.pic img') === await js(`REL.Adult.filter(r=>r.pv).length`) && await count('[data-shelf=Adult] .tile.pic .nopic') === await js(`REL.Adult.filter(r=>!r.pv).length`) && await js(`(()=>{const a=document.querySelector('[data-shelf=Adult] .tile.pic .art').getBoundingClientRect();return Math.abs(a.width/a.height-16/9)<.02;})()`));
  const picTile = await js(`document.querySelector('[data-shelf=Adult] .tile.pic').dataset.tile`); await click(`[data-shelf=Adult] [data-tile="${picTile}"]`); ok('idea 2: a picture tile opens its row with the Preview chip', await count('[data-shelf=Adult] .shelfpanel .rc.k-pv') === 1);
  await key('Escape'); await load('#/2'); ok('idea 2: shelves are remembered after a reload', await js(`[...document.querySelectorAll('.hsec.shelf')].map(s=>s.dataset.shelf).join()==='Following,Movies,TV,Audio,Adult'`));
  await js('localStorage.clear()'); await load('#/3');

  if (!ONLY) {
  // ---- idea 3: Saved views ----
  ok('idea 3: five tabs, the saved "TV · 1080p web" open first as the default', await count('.vtabs [role=tab]') === 5 && await js(`document.querySelector('.vtabs [aria-selected=true]').textContent.startsWith('TV · 1080p web')`));
  ok('idea 3: every row of the view carries both words of its name filter', await js(`[...document.querySelectorAll('.feed tbody tr .rname')].every(a=>/1080p/i.test(a.textContent)&&/web/i.test(a.textContent))`) && await count('.feed tbody tr') > 0 && await count('.feed tbody tr') <= 25);
  ok('idea 3: the view’s filters stand under the tabs as cells', await js(`[...document.querySelectorAll('.fbar.view .sfm .k')].map(k=>k.textContent).join()==='Section,Name contains'`));
  await click('.vtabs [data-view=new]'); ok('idea 3: "New today" lists every section but Other', await js(`[...document.querySelectorAll('.feed tbody tr td.c-cat')].every(td=>!/^Other/.test(td.textContent))`) && await count('.feed tbody tr') === 25);
  await click('.vtabs [data-view=following]'); ok('idea 3: the Following tab lists followed titles’ releases', await count('.feed tbody tr') > 0 && await js(`[...document.querySelectorAll('.feed tbody tr [data-watch]')].every(b=>b.getAttribute('aria-pressed')==='true')`));
  await click('[data-addview]'); ok('idea 3: Add a view opens the form', await count('#modal [data-viewform]') === 1);
  await js(`(()=>{const s=document.getElementById('vf-root');s.value='Audio';s.dispatchEvent(new Event('change',{bubbles:true}));})()`); await sleep(200);
  ok('idea 3: changing the section lists that section’s sub-categories', await js(`[...document.querySelectorAll('#modal [data-vsub]')].some(b=>b.dataset.vsub==='Lossless')`));
  await click('#modal [data-vsub=Lossless]'); await js(`document.getElementById('vf-name').value='Lossless only'`); await click('#modal [data-viewform] button[type=submit]');
  ok('idea 3: the new view becomes a tab and opens, every row Lossless', await js(`document.querySelector('.vtabs [aria-selected=true]').textContent.startsWith('Lossless only')`) && await js(`[...document.querySelectorAll('.feed tbody tr td.c-cat')].every(td=>td.textContent.trim()==='Lossless')`) && await count('.feed tbody tr') > 0);
  await click('[data-editview]'); ok('idea 3: Edit this view opens the form filled in', await js(`document.getElementById('vf-name').value==='Lossless only'&&document.querySelector('#modal [data-vsub=Lossless]').getAttribute('aria-pressed')==='true'`));
  await key('Escape');
  await click('[data-cust=m3]'); await click('#modal [data-vdef=new]'); ok('idea 3: the default dot moves to New today', await js(`m3().def==='new'`) && await js(`document.querySelector('.vtabs [data-view=new] .n')!==null`));
  await js(`(()=>{const i=document.querySelector('#modal [data-vname=uhd]');i.value='Films in 4K';i.dispatchEvent(new Event('input',{bubbles:true}));})()`); ok('idea 3: renaming in the dialog renames the tab as you type', await js(`document.querySelector('.vtabs [data-view=uhd]').textContent.startsWith('Films in 4K')`));
  const newId = await js(`m3().views.find(v=>v.name==='Lossless only').id`);
  await click(`#modal [data-vrm="${newId}"]`); ok('idea 3: removing a view takes its tab away', await count(`.vtabs [data-view="${newId}"]`) === 0);
  await dragTo('#modal .row[data-key=following] [data-grip]', '#modal .row[data-key=new]', 'before'); ok('idea 3: a view can be dragged to the first tab', await js(`m3().views[0].id==='following'`));
  await key('Escape'); await load('#/3'); ok('idea 3: after a reload the default view opens and the changes are kept', await js(`document.querySelector('.vtabs [aria-selected=true]').dataset.view==='new'&&document.querySelector('.vtabs [data-view=uhd]').textContent.startsWith('Films in 4K')&&m3().views[0].id==='following'`));
  await js(`state.follow.clear();m3().active='following';render()`); ok('idea 3: the Following tab with nothing followed explains how to follow', await js(`/Nothing followed yet/.test(document.querySelector('#app .empty')?.textContent)`));
  await js('localStorage.clear()'); await load('#/4');

  // ---- idea 4: Blocks ----
  ok('idea 4: eight blocks by default in the recorded layout', await js(`[...document.querySelectorAll('.blk')].map(b=>b.dataset.blk+':'+b.className.match(/w\\d+/)[0]).join()==='newtoday:w4,following:w8,newest:TV:w6,newest:Movies:w6,basket:w4,downloads:w4,groups:w4,notes:w12'`));
  ok('idea 4: no block hides part of its content', await noOverflow());
  ok('idea 4: New today lists every section with its real counts', await js(`[...document.querySelectorAll('.blk[data-blk=newtoday] tbody tr')].every(tr=>{const r=tr.querySelector('td.sec').textContent.trim();return tr.querySelectorAll('td.num')[0].textContent.replace(/,/g,'')==String(D.counts[r][1]);})`) && await count('.blk[data-blk=newtoday] tbody tr') === 8);
  ok('idea 4: handles are hidden until Edit layout', await js(`getComputedStyle(document.querySelector('.blkh .handles')).display==='none'`));
  await click('[data-editblk]'); ok('idea 4: Edit layout shows the handles, Add block and Reset', await js(`getComputedStyle(document.querySelector('.blkh .handles')).display!=='none'`) && await count('[data-ddtoggle=addblk]') === 1 && await count('[data-resetblk]') === 1);
  await dragTo('.blk[data-blk=newtoday] [data-grip]', '.blk[data-blk=following]', 'right'); ok('idea 4: dragging a block onto its neighbour’s far side moves it after', await js(`m4().blocks[1].t==='newtoday'&&m4().blocks[0].t==='following'`));
  ok('idea 4: in edit mode every handle stays inside its block, even a narrow one', await js(`[...document.querySelectorAll('.blk')].every(b=>{const r=b.getBoundingClientRect();return [...b.querySelectorAll('.blkh .handles > *')].every(h=>{const x=h.getBoundingClientRect();return x.right<=r.right+1&&x.left>=r.left-1;});})`));
  await click('[data-ddtoggle=w1]'); ok('idea 4: the width control is a menu of words', await count('.wsel .mmenu [data-blkw]') === 4);
  await click('[data-blkw="1:12"]'); ok('idea 4: picking a width resizes the block and closes the menu', await js(`document.querySelector('.blk[data-blk=newtoday]').classList.contains('w12')`) && await count('.wsel .mmenu') === 0);
  await click('[data-blkrm="2"]'); ok('idea 4: Remove takes the block off the board', await count('.blk[data-blk="newest:TV"]') === 0 && await count('.blk') === 7);
  await click('[data-ddtoggle=addblk]'); ok('idea 4: Add block lists only the blocks not on the board', await count('.msel.addblk .mmenu [data-addblk]') > 0 && await count('.msel.addblk [data-addblk="newest:TV"]') === 1 && await count('.msel.addblk [data-addblk="basket"]') === 0);
  await click('.msel.addblk [data-addblk="newepisodes"]'); ok('idea 4: adding a block appends it', await js(`m4().blocks.at(-1).t==='newepisodes'`) && await count('.blk[data-blk=newepisodes] .tile') > 0 && await noOverflow());
  await click('[data-ddtoggle=addblk]'); await click('.msel.addblk [data-addblk="newfilms"]'); await click('[data-ddtoggle=addblk]'); await click('.msel.addblk [data-addblk="newest:Console"]');
  ok('idea 4: every block type renders without hiding content', await noOverflow() && await count('.blk') === 10);
  await load('#/4'); ok('idea 4: the layout is remembered after a reload', await js(`m4().blocks[0].t==='following'&&m4().blocks.length===10`) && await js(`document.querySelector('.blk[data-blk=newtoday]').classList.contains('w12')`));
  const c0 = +(await text('#cartn')); ok('idea 4: the basket’s remove button is a plain action, not a coral pressed toggle', await js(`!document.querySelector('.blk[data-blk=basket] [data-rmcart]').hasAttribute('aria-pressed')`)); await click('.blk[data-blk=basket] [data-rmcart]'); ok('idea 4: removing from the basket block lowers the header count', +(await text('#cartn')) === c0 - 1);
  ok('idea 4: compact rows inside one section show the sub-category alone, never "Root > Sub"', await js(`[...document.querySelectorAll('.blk[data-blk="newest:Movies"] .crow .meta')].every(m=>!/Movies >/.test(m.textContent))`));
  ok('idea 4: the last arrival bar is the same figure as the 24 h column (rolling windows, not calendar days)', await js(`D.roots.every(r=>D.days[r][13]===D.counts[r][1])`));
  ok('idea 4: the arrival bars read against the card (not the hairline colour)', await js(`getComputedStyle(document.querySelector('.bars i')).backgroundColor!==getComputedStyle(document.querySelector('.blk')).borderTopColor`));
  await click('[data-editblk]'); await click('[data-resetblk]'); ok('idea 4: Reset restores the eight default blocks', await count('.blk') === 8 && await js(`m4().blocks[0].t==='newtoday'`));
  await click('[data-editblk]'); ok('idea 4: Done hides the handles again', await js(`getComputedStyle(document.querySelector('.blkh .handles')).display==='none'`));
  for (const w of [4, 6, 8, 12]) { await js(`m4().blocks.forEach(b=>b.w=${w});render()`); ok(`idea 4: every block at width ${w} fits its content`, await noOverflow()); }
  await js('localStorage.clear()');
  }
  // ---- both themes, no runtime errors ----
  for (const m of ONLY ? [2] : [1, 2, 3, 4]) { await load('#/' + m); await js(`document.documentElement.dataset.theme='light'`); await sleep(200); ok(`mockup ${m} renders in light`, await count('#app .hsec, #app .blk, #app .feed') > 0); }
} catch (e) { results.push(['FAIL', 'script error', String(e && e.stack || e)]); }
ok('no uncaught exceptions during the run', errors.length === 0, errors.slice(0, 3).join(' | '));
const fails = results.filter(r => r[0] === 'FAIL');
for (const [s, n, x] of results) if (s === 'FAIL' || process.env.V) console.log(s, n, x || '');
console.log(`${results.length} checks, ${fails.length} failures`);
ws.close(); chrome.kill('SIGKILL'); process.exit(fails.length ? 1 : 0);
