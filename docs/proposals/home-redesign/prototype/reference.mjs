// Reference screens of the approved home page (Shelves), both themes, 1600 px wide, into <out>/reference/<name>-<theme>.webp
//   HM_BASE=http://localhost:8769/ node hmreference.mjs <out-dir>      (needs Google Chrome and cwebp)
import {spawn, execFileSync} from 'node:child_process'; import {writeFileSync, mkdirSync, mkdtempSync, unlinkSync} from 'node:fs'; import {tmpdir} from 'node:os';
const out = process.argv[2] || '.'; mkdirSync(`${out}/reference`, {recursive: true});
const BASE = process.env.HM_BASE || 'http://localhost:8769/';
const PORT = 9420 + Math.floor(Math.random() * 30), chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', ['--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(tmpdir() + '/hmref-')}`, 'about:blank'], {stdio: 'ignore'});
const sleep = ms => new Promise(r => setTimeout(r, ms)); let ws, seq = 0; const pending = new Map();
for (let i = 0; i < 40; i++) { try { const t = await (await fetch(`http://localhost:${PORT}/json`)).json(); const p = t.find(x => x.type === 'page'); if (p) { ws = new WebSocket(p.webSocketDebuggerUrl); await new Promise(r => ws.onopen = r); break; } } catch (e) {} await sleep(250); }
ws.onmessage = m => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d.result); pending.delete(d.id); } };
const send = (method, params = {}) => new Promise(r => { const id = ++seq; pending.set(id, r); ws.send(JSON.stringify({id, method, params})); });
const js = async e => (await send('Runtime.evaluate', {expression: e, awaitPromise: true, returnByValue: true})).result?.value;
await send('Page.enable'); await send('Runtime.enable');
const W = 'await new Promise(r=>setTimeout(r,400))';
const rect = sel => js(`(()=>{const r=document.querySelector(${JSON.stringify(sel)}).getBoundingClientRect();return [r.left,r.top,r.width,r.height];})()`);
const screens = [
  ['shelves', '', true],
  ['shelves-dialog', `document.querySelector('[data-cust=m2]').click()`, false],
  ['shelves-dialog-grabbed', `document.querySelector('[data-cust=m2]').click();${W};const g=document.querySelector('#modal .row[data-key=Movies] [data-grip]');g.focus();g.dispatchEvent(new KeyboardEvent('keydown',{key:' ',bubbles:true}));g.dispatchEvent(new KeyboardEvent('keydown',{key:'ArrowUp',bubbles:true}))`, false],
  ['shelves-dialog-drag', 'DRAG', false],
  ['shelf-tile-panel', `document.querySelector('[data-shelf=TV] .rail .tile').click();${W};window.scrollTo(0,420)`, false],
  ['shelf-release-card-panel', `document.querySelector('[data-shelf=Books] .rail .tile').click();${W};document.querySelector('[data-shelf=Books]').scrollIntoView();window.scrollBy(0,-90)`, false],
  ['shelves-adult', `m2().on.push('Adult');render();${W};document.querySelector('[data-shelf=Adult]').scrollIntoView();window.scrollBy(0,-90)`, false],
  ['shelves-nothing-followed', `state.follow.clear();render()`, false],
  ['shelves-none', `m2().on=[];render()`, false],
];
for (const theme of ['dark', 'light']) for (const [name, pre, full] of screens) {
  await send('Emulation.setDeviceMetricsOverride', {width: 1600, height: 1000, deviceScaleFactor: 1, mobile: false});
  await send('Page.navigate', {url: BASE + 'home.html?r=' + Math.random() + '#/2'});
  for (let i = 0; i < 60 && !(await js('!!window.READY')); i++) await sleep(200);
  await js(`localStorage.clear();document.documentElement.dataset.theme='${theme}';render()`); await sleep(400);
  if (pre === 'DRAG') {
    await js(`document.querySelector('[data-cust=m2]').click()`); await sleep(400);
    const a = await rect('#modal .row[data-key=Movies] [data-grip]'), b = await rect('#modal .row[data-key=Audio]');
    const sx = a[0] + a[2] / 2, sy = a[1] + a[3] / 2, ty = b[1] + b[3] - 4;
    await send('Input.dispatchMouseEvent', {type: 'mousePressed', x: sx, y: sy, button: 'left', clickCount: 1});
    for (let i = 1; i <= 8; i++) { await send('Input.dispatchMouseEvent', {type: 'mouseMoved', x: sx - 40 * i / 8, y: sy + (ty - sy) * i / 8, button: 'left'}); await sleep(30); }
    await sleep(250);
  } else if (pre) { await js(`(async()=>{${pre};${W}})()`); await sleep(700); }
  if (full) { const m = await send('Page.getLayoutMetrics'); const h = Math.min(Math.ceil(m.cssContentSize.height), 6000); await send('Emulation.setDeviceMetricsOverride', {width: 1600, height: h, deviceScaleFactor: 1, mobile: false}); await sleep(500); }
  const r = await send('Page.captureScreenshot', {format: 'png'});
  const png = `${out}/reference/${name}-${theme}.png`; writeFileSync(png, Buffer.from(r.data, 'base64'));
  execFileSync('cwebp', ['-quiet', '-q', '82', png, '-o', `${out}/reference/${name}-${theme}.webp`]); unlinkSync(png);
  if (pre === 'DRAG') await send('Input.dispatchMouseEvent', {type: 'mouseReleased', x: 0, y: 0, button: 'left', clickCount: 1});
  console.log('wrote', `${name}-${theme}.webp`);
}
ws.close(); chrome.kill('SIGKILL'); process.exit(0);
