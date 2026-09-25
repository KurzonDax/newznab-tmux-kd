// Compares a running implementation with the prototype's measurements, part by part.
// The implementation marks each part with  data-part="<name from reference/parts.json>".
//   node compare.mjs --base http://host:port --pages pages.json [--cookie "name=value"] [--ref reference]
//   pages.json: { "dark"|"light" handled automatically; keys are page names, values are paths, e.g.
//                { "releases": "/browse/TV", "shows": "/series", "show": "/title/tv/123", "details": "/details/<guid>" } }
// Self-test against the prototype itself (uses the prototype's CSS selectors instead of data-part):
//   node compare.mjs --base http://127.0.0.1:8767/tv.html --prototype
// Tolerances: lengths ±1px (font-size ±0.5px), colours exact after normalising to rgba, first font family, exact weight.
import {spawn} from 'node:child_process';
import {readFileSync, mkdtempSync} from 'node:fs';
import {tmpdir} from 'node:os';
const arg = n => { const i = process.argv.indexOf('--' + n); return i < 0 ? null : (process.argv[i + 1] && !process.argv[i + 1].startsWith('--') ? process.argv[i + 1] : true); };
const base = arg('base'), refDir = arg('ref') || 'reference', proto = !!arg('prototype'), cookie = arg('cookie');
if (!base) { console.error('usage: node compare.mjs --base URL (--pages pages.json | --prototype) [--cookie "k=v"] [--ref dir]'); process.exit(2); }
const ref = JSON.parse(readFileSync(`${refDir}/measurements.json`, 'utf8')), meta = JSON.parse(readFileSync(`${refDir}/parts.json`, 'utf8'));
const pages = proto ? Object.fromEntries(meta.states.map(s => [s.name, s.prototypeRoute])) : JSON.parse(readFileSync(arg('pages'), 'utf8'));
const PORT = 9391, [W, H] = ref.viewport;
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', ['--headless=new', '--disable-gpu', '--hide-scrollbars', `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(tmpdir() + '/cdp-')}`, `--window-size=${W},${H}`, 'about:blank'], {stdio: 'ignore'});
const sleep = ms => new Promise(r => setTimeout(r, ms)); let ws, seq = 0; const pending = new Map();
for (let i = 0; i < 40; i++) { try { const t = await (await fetch(`http://127.0.0.1:${PORT}/json`)).json(); const p = t.find(x => x.type === 'page'); if (p) { ws = new WebSocket(p.webSocketDebuggerUrl); await new Promise(r => ws.onopen = r); break; } } catch (e) {} await sleep(250); }
ws.onmessage = m => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d.result); pending.delete(d.id); } };
const send = (method, params = {}) => new Promise(r => { const id = ++seq; pending.set(id, r); ws.send(JSON.stringify({id, method, params})); });
const js = async e => (await send('Runtime.evaluate', {expression: e, awaitPromise: true, returnByValue: true})).result?.value;
await send('Page.enable'); await send('Network.enable'); await send('Emulation.setDeviceMetricsOverride', {width: W, height: H, deviceScaleFactor: 1, mobile: false});
if (cookie) { const [name, ...v] = cookie.split('='); await send('Network.setCookie', {name, value: v.join('='), url: base}); }
const RGBA = `const __cv=document.createElement('canvas').getContext('2d',{willReadFrequently:true});const rgba=s=>{if(!s||s==='none')return s;__cv.clearRect(0,0,1,1);__cv.fillStyle='#000';__cv.fillStyle=s;__cv.fillRect(0,0,1,1);const d=__cv.getImageData(0,0,1,1).data;return 'rgba('+d[0]+','+d[1]+','+d[2]+','+(d[3]/255).toFixed(2)+')';};`;
const measure = () => js(`(()=>{${RGBA}const P=${JSON.stringify(meta.props)},CP=${JSON.stringify(meta.colorProps)},parts=${JSON.stringify(meta.parts)},o={};for(const [n,s] of Object.entries(parts)){const e=document.querySelector(${proto ? 's' : `'[data-part="'+n.replace(/"/g,'\\\\"')+'"]'`});if(!e)continue;const c=getComputedStyle(e),r=e.getBoundingClientRect(),v={};for(const p of P)v[p]=CP.includes(p)?rgba(c[p]):c[p];v.renderedWidth=Math.round(r.width*10)/10;v.renderedHeight=Math.round(r.height*10)/10;o[n]=v;}return o;})()`);
const setTheme = t => js(`(()=>{const r=document.documentElement;if(${proto}){if(r.dataset.theme!=='${t}')document.querySelector('[aria-label*=heme],#theme,[data-theme-toggle]')?.click();}else{r.classList.toggle('dark','${t}'==='dark');r.dataset.theme='${t}';}})()`);
// what defines a part; widths and heights of containers depend on data, so only the intrinsic ones are compared
const LENGTHS = ['fontSize', 'lineHeight', 'letterSpacing', 'borderRadius', 'borderTopWidth', 'borderBottomWidth', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'gap'], FIXED_BOX = /chip|button|poster|checkbox|tab$|tab,|action|arrow|tile art/;
const px = v => { const n = parseFloat(v); return Number.isFinite(n) ? n : null; };
const diffs = [], seen = {dark: new Set(), light: new Set()};
for (const th of ['dark', 'light']) for (const [page, path] of Object.entries(pages)) {
  await send('Page.navigate', {url: 'about:blank'}); await send('Page.navigate', {url: proto ? base + path : new URL(path, base).href});
  for (let i = 0; i < 60 && !(await js(proto ? '!!window.READY' : `document.readyState==='complete'`)); i++) await sleep(150); await sleep(600);
  await setTheme(th); await sleep(300);
  const got = await measure();
  for (const [name, g] of Object.entries(got)) {
    if (seen[th].has(name)) continue; seen[th].add(name);
    const want = ref.parts[th][name]; if (!want) continue;
    for (const p of LENGTHS) { const a = px(want[p]), b = px(g[p]); if (a == null && b == null) continue; if (a == null || b == null || Math.abs(a - b) > (p === 'fontSize' ? 0.5 : 1)) diffs.push([th, page, name, p, want[p], g[p]]); }
    for (const p of meta.colorProps) { if (p.startsWith('border') && (px(want[p.replace('Color', 'Width')]) === 0 || px(g[p.replace('Color', 'Width')]) === 0)) continue; if (want[p] !== g[p]) diffs.push([th, page, name, p, want[p], g[p]]); }
    if (String(want.fontWeight) !== String(g.fontWeight)) diffs.push([th, page, name, 'fontWeight', want.fontWeight, g.fontWeight]);
    const fam = s => String(s).split(',')[0].replace(/["']/g, '').trim(); if (fam(want.fontFamily) !== fam(g.fontFamily)) diffs.push([th, page, name, 'fontFamily', fam(want.fontFamily), fam(g.fontFamily)]);
    if (FIXED_BOX.test(name)) for (const p of ['renderedHeight']) if (Math.abs(want[p] - g[p]) > 1) diffs.push([th, page, name, p, want[p], g[p]]);
  }
}
const missing = Object.keys(meta.parts).filter(n => !seen.dark.has(n));
for (const d of diffs) console.log(`DIFF  [${d[0]}] ${d[1]} · ${d[2]} · ${d[3]}: want ${d[4]}, got ${d[5]}`);
for (const m of missing) console.log(`MISSING  no element found for part "${m}"`);
console.log(`${diffs.length} differences, ${missing.length} parts not found, ${seen.dark.size} parts compared`);
chrome.kill('SIGKILL'); process.exit(diffs.length || missing.length ? 1 : 0);
