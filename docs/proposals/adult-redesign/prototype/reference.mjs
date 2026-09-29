// Reference screenshots of the Adult prototype: each state in dark and light at 1600 x 1000, as webp.
// node adreference.mjs <out-dir>   (AD_URL points at a served copy; needs Google Chrome and cwebp)
import {spawn, execFileSync} from 'node:child_process'; import {mkdtempSync, mkdirSync, writeFileSync, unlinkSync} from 'node:fs'; import {tmpdir} from 'node:os';
const URL_ = process.env.AD_URL || 'http://127.0.0.1:8766/adult.html', out = (process.argv[2] || '.') + '/reference', PORT = 9384;
mkdirSync(out, {recursive: true});
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', ['--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(tmpdir() + '/adref-')}`, 'about:blank'], {stdio: 'ignore'});
const sleep = ms => new Promise(r => setTimeout(r, ms)); let ws, seq = 0; const pending = new Map();
for (let i = 0; i < 40; i++) { try { const t = await (await fetch(`http://127.0.0.1:${PORT}/json`)).json(); const p = t.find(x => x.type === 'page'); if (p) { ws = new WebSocket(p.webSocketDebuggerUrl); await new Promise(r => ws.onopen = r); break; } } catch {} await sleep(250); }
ws.onmessage = m => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d.result); pending.delete(d.id); } };
const send = (method, params = {}) => new Promise(r => { const id = ++seq; pending.set(id, r); ws.send(JSON.stringify({id, method, params})); });
const js = async e => (await send('Runtime.evaluate', {expression: e, awaitPromise: true, returnByValue: true})).result?.value;
await send('Page.enable'); await send('Emulation.setDeviceMetricsOverride', {width: 1600, height: 1000, deviceScaleFactor: 1, mobile: false});
const FX = `(await (await fetch('ad/fixtures.json')).json())`, wait = ms => `await new Promise(r=>setTimeout(r,${ms}))`;
const pics = `state.cat.clear();CATS.filter(c=>c!=='Other').forEach(c=>state.cat.add(c));route()`;
const states = [
  ['releases', ``], ['releases-exclude-other', pics], ['releases-category-menu', `${pics};document.querySelector('[data-ddtoggle=cat]').click()`],
  ['releases-audio-menu', `document.querySelector('[data-ddtoggle=aud]').click()`], ['releases-filters-set', `state.res.add('1080p');state.comp='95';${pics}`],
  ['releases-name-search', `state.q=${FX}.search;route()`], ['releases-no-match', `state.q='nothing matches this';state.res.add('SD');route()`],
  ['releases-selection', `${pics};document.querySelector('.feed input[data-select]').click()`],
  ['releases-picture-dialog', `${pics};document.querySelector('.feed td.art a[data-img]').click();${wait(700)}`],
  ['releases-media-info', `${pics};document.querySelector('.feed [data-mi]').click();${wait(900)}`],
  ['releases-clip-dialog', `${pics};document.querySelector('.feed [data-clip]').click();${wait(400)}`],
  ['details-clip', `location.hash='#/release/'+${FX}.clip;${wait(1200)}`],
  ['details-sample-full', `location.hash='#/release/'+(REL.find(r=>X(r).jpg&&PV.has('sample/'+X(r).guid+'.webp'))||{}).id;${wait(1200)};document.querySelector('.adpics [data-kind=sample]')?.click();${wait(1200)}`],
  ['details-similar', `location.hash='#/release/'+${FX}.similar;${wait(1200)};scrollTo(0,document.querySelector('.simrel')?.offsetTop-90||0)`],
  ['details-predb', `location.hash='#/release/'+${FX}.predb;${wait(1200)};scrollTo(0,300)`], ['details-no-picture', `location.hash='#/release/'+${FX}.nopic;${wait(1200)}`],
  ['details-media-tab', `location.hash='#/release/'+${FX}.clip;${wait(1200)};document.querySelector('[data-tab=media]').click();${wait(1200)}`],
  ['details-pressed', `location.hash='#/release/'+${FX}.clip;${wait(1200)};document.querySelector('.dacts [data-cart]').click()`]];
for (const theme of ['dark', 'light']) for (const [name, pre] of states) {
  await send('Page.navigate', {url: URL_ + '?r=' + Math.random() + '#/'}); for (let i = 0; i < 60 && !(await js('!!window.READY')); i++) await sleep(200);
  await js(`localStorage.clear()`); await send('Page.navigate', {url: URL_ + '?r=' + Math.random() + '#/'}); for (let i = 0; i < 60 && !(await js('!!window.READY')); i++) await sleep(200); await sleep(400);
  await js(`document.documentElement.dataset.theme='${theme}'`); if (pre) await js(`(async()=>{${pre}})()`); await sleep(500);
  const r = await send('Page.captureScreenshot', {format: 'png'}); const png = `${out}/${name}-${theme}.png`;
  writeFileSync(png, Buffer.from(r.data, 'base64')); execFileSync('cwebp', ['-quiet', '-q', '70', png, '-o', png.replace(/\.png$/, '.webp')]); unlinkSync(png);
}
console.log(`reference: ${states.length} states x 2 themes`); ws.close(); chrome.kill('SIGKILL'); process.exit(0);
