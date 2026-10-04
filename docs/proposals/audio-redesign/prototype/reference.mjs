// Reference screenshots of the Audio prototype: each key screen in dark and light at 1600 x 1000, as webp.
// node aureference.mjs <out-dir>   (writes <out-dir>/reference/; SH_BASE points at a served copy; needs Google Chrome and cwebp)
import {spawn, execFileSync} from 'node:child_process'; import {mkdtempSync, mkdirSync, writeFileSync, unlinkSync} from 'node:fs'; import {tmpdir} from 'node:os';
const BASE = process.env.SH_BASE || 'http://localhost:8766/', out = (process.argv[2] || '.') + '/reference', PORT = 9463;
mkdirSync(out, {recursive: true});
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', ['--headless=new', '--disable-gpu', '--autoplay-policy=no-user-gesture-required', `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(tmpdir() + '/auref-')}`, 'about:blank'], {stdio: 'ignore'});
const sleep = ms => new Promise(r => setTimeout(r, ms)); let ws, seq = 0; const pending = new Map();
for (let i = 0; i < 40; i++) { try { const t = await (await fetch(`http://localhost:${PORT}/json`)).json(); const p = t.find(x => x.type === 'page'); if (p) { ws = new WebSocket(p.webSocketDebuggerUrl); await new Promise(r => ws.onopen = r); break; } } catch {} await sleep(250); }
ws.onmessage = m => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d.result); pending.delete(d.id); } };
const send = (method, params = {}) => new Promise(r => { const id = ++seq; pending.set(id, r); ws.send(JSON.stringify({id, method, params})); });
const js = async e => (await send('Runtime.evaluate', {expression: e, awaitPromise: true, returnByValue: true})).result?.value;
await send('Page.enable'); await send('Emulation.setDeviceMetricsOverride', {width: 1600, height: 1000, deviceScaleFactor: 1, mobile: false});
const FX = `(await (await fetch(DIR+'fixtures.json')).json())`, wait = ms => `await new Promise(r=>setTimeout(r,${ms}))`;
const rel = id => `location.hash='#/release/'+(${id});${wait(1200)}`;
// [screen name, page, script run after the page is ready]
const ALBUM = `(REL.find(r=>gameOfR(r)&&coverOf(r)&&pvOf(r)&&genOf(r).length&&releasesOfGame(r).length>1)||{}).id||${FX}.album`;
const states = [
  ['audio-list', 'audio', ``],
  ['audio-list-lossless-rock', 'audio', `state.cat=new Set(['Lossless']);state.genre=new Set(['Rock']);afterFilter();route()`],
  ['audio-genre-menu', 'audio', `document.querySelector('[data-ddtoggle=genre]').click()`],
  ['audio-year-menu', 'audio', `document.querySelector('[data-ddtoggle=year]').click()`],
  ['audio-listen-dialog', 'audio', `state.cat=new Set(['Lossless']);afterFilter();route();${wait(300)};document.querySelector('[data-listen]').click()`],
  ['audio-release-album', 'audio', rel(ALBUM)],
  ['audio-release-tracks', 'audio', rel(`${FX}.tracks`) + `;document.querySelector('[data-tab=tracks]').click()`],
  ['audio-release-media-info', 'audio', rel(ALBUM) + `;document.querySelector('[data-tab=media]')?.click();${wait(600)}`],
  ['audio-release-no-album', 'audio', rel(`${FX}.noalbum`)]];
for (const theme of ['dark', 'light']) for (const [name, page, pre] of states) {
  const url = BASE + page + '.html';
  await send('Page.navigate', {url: url + '?r=' + Math.random() + '#/'}); for (let i = 0; i < 60 && !(await js('!!window.READY')); i++) await sleep(200);
  await js(`localStorage.clear()`); await send('Page.navigate', {url: url + '?r=' + Math.random() + '#/'}); for (let i = 0; i < 60 && !(await js('!!window.READY')); i++) await sleep(200); await sleep(400);
  await js(`document.documentElement.dataset.theme='${theme}'`); if (pre) await js(`(async()=>{${pre}})()`); await sleep(500);
  const r = await send('Page.captureScreenshot', {format: 'png'}); const png = `${out}/${name}-${theme}.png`;
  writeFileSync(png, Buffer.from(r.data, 'base64')); execFileSync('cwebp', ['-quiet', '-q', '70', png, '-o', png.replace(/\.png$/, '.webp')]); unlinkSync(png);
}
console.log(`reference: ${states.length} screens x 2 themes`); ws.close(); chrome.kill('SIGKILL'); process.exit(0);
