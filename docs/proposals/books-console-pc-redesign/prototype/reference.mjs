// Reference screenshots of the Books / Console / PC prototype: each key screen in dark and light at 1600 x 1000, as webp.
// node shreference.mjs <out-dir>   (writes <out-dir>/reference/; SH_BASE points at a served copy; needs Google Chrome and cwebp)
import {spawn, execFileSync} from 'node:child_process'; import {mkdtempSync, mkdirSync, writeFileSync, unlinkSync} from 'node:fs'; import {tmpdir} from 'node:os';
const BASE = process.env.SH_BASE || 'http://localhost:8766/', out = (process.argv[2] || '.') + '/reference', PORT = 9385;
mkdirSync(out, {recursive: true});
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', ['--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(tmpdir() + '/shref-')}`, 'about:blank'], {stdio: 'ignore'});
const sleep = ms => new Promise(r => setTimeout(r, ms)); let ws, seq = 0; const pending = new Map();
for (let i = 0; i < 40; i++) { try { const t = await (await fetch(`http://localhost:${PORT}/json`)).json(); const p = t.find(x => x.type === 'page'); if (p) { ws = new WebSocket(p.webSocketDebuggerUrl); await new Promise(r => ws.onopen = r); break; } } catch {} await sleep(250); }
ws.onmessage = m => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d.result); pending.delete(d.id); } };
const send = (method, params = {}) => new Promise(r => { const id = ++seq; pending.set(id, r); ws.send(JSON.stringify({id, method, params})); });
const js = async e => (await send('Runtime.evaluate', {expression: e, awaitPromise: true, returnByValue: true})).result?.value;
await send('Page.enable'); await send('Emulation.setDeviceMetricsOverride', {width: 1600, height: 1000, deviceScaleFactor: 1, mobile: false});
const FX = `(await (await fetch(DIR+'fixtures.json')).json())`, wait = ms => `await new Promise(r=>setTimeout(r,${ms}))`;
const rel = id => `location.hash='#/release/'+(${id});${wait(1200)}`;
// [screen name, page, script run after the page is ready]
const states = [
  ['books-list', 'books', ``], ['console-list', 'console', ``], ['pc-list', 'pc', ``],
  ['console-genre-menu', 'console', `document.querySelector('[data-ddtoggle=genre]').click()`],
  ['console-year-menu', 'console', `document.querySelector('[data-ddtoggle=year]').click()`],
  ['console-release-game', 'console', rel(`+Object.keys(GAME).find(k=>GAME[k].story&&GAME[k].site)`)],
  ['console-release-no-game', 'console', rel(`REL.find(r=>!GAME[r.id]).id`)],
  ['books-details', 'books', rel(`${FX}.predb||${FX}.files`)],
  ['pc-details', 'pc', rel(`${FX}.similar||${FX}.files`)]];
for (const theme of ['dark', 'light']) for (const [name, page, pre] of states) {
  const url = BASE + page + '.html';
  await send('Page.navigate', {url: url + '?r=' + Math.random() + '#/'}); for (let i = 0; i < 60 && !(await js('!!window.READY')); i++) await sleep(200);
  await js(`localStorage.clear()`); await send('Page.navigate', {url: url + '?r=' + Math.random() + '#/'}); for (let i = 0; i < 60 && !(await js('!!window.READY')); i++) await sleep(200); await sleep(400);
  await js(`document.documentElement.dataset.theme='${theme}'`); if (pre) await js(`(async()=>{${pre}})()`); await sleep(500);
  const r = await send('Page.captureScreenshot', {format: 'png'}); const png = `${out}/${name}-${theme}.png`;
  writeFileSync(png, Buffer.from(r.data, 'base64')); execFileSync('cwebp', ['-quiet', '-q', '70', png, '-o', png.replace(/\.png$/, '.webp')]); unlinkSync(png);
}
console.log(`reference: ${states.length} screens x 2 themes`); ws.close(); chrome.kill('SIGKILL'); process.exit(0);
