// Reference screenshots of the generic release lists prototype: each key screen in dark and light at 1600 x 1000, as webp.
// node rlreference.mjs <out-dir>   (writes <out-dir>/reference/; SH_BASE points at a served copy; needs Google Chrome and cwebp)
import {spawn, execFileSync} from 'node:child_process'; import {mkdtempSync, mkdirSync, writeFileSync, unlinkSync} from 'node:fs'; import {tmpdir} from 'node:os';
const BASE = process.env.SH_BASE || 'http://localhost:8766/', out = (process.argv[2] || '.') + '/reference', PORT = 9386;
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
// [screen name, script run after the page is ready]
const states = [
  ['all-list', ``], ['all-category-menu', `document.querySelector('[data-ddtoggle=cat]').click()`],
  ['all-exclude-other', `document.querySelector('[data-ddtoggle=cat]').click();document.querySelector('[data-ddexo]').click();document.querySelector('.pager .sum').click()`],
  ['all-completion-menu', `document.querySelector('[data-ddtoggle=comp]').click()`],
  ['header-all-menu', `document.querySelector('[data-nd=all]').click()`], ['header-other-menu', `document.querySelector('[data-nd=other]').click()`],
  ['group-list', `location.hash='#/group/'+encodeURIComponent(${FX}.group);${wait(500)}`],
  ['poster-list', `location.hash='#/poster/'+encodeURIComponent(${FX}.poster);${wait(500)}`],
  ['poster-blacklist-dialog', `location.hash='#/poster/'+encodeURIComponent(${FX}.poster);${wait(500)};document.querySelector('[data-blacklist]').click();${wait(300)}`],
  ['poster-blacklisted-sweep', `location.hash='#/poster/'+encodeURIComponent(${FX}.poster);${wait(500)};document.querySelector('[data-blacklist]').click();document.querySelector('#modal input[name=delete_releases]').click();document.querySelector('[data-blform]').requestSubmit();${wait(300)}`],
  ['poster-swept', `location.hash='#/poster/'+encodeURIComponent(${FX}.poster);${wait(500)};document.querySelector('[data-blacklist]').click();document.querySelector('#modal input[name=delete_releases]').click();document.querySelector('[data-blform]').requestSubmit();${wait(4400)}`],
  ['other-list', `location.hash='#/other';${wait(500)}`], ['other-category-menu', `location.hash='#/other';${wait(500)};document.querySelector('[data-ddtoggle=cat]').click()`],
  ['release-film', rel(`${FX}.film`)], ['release-show-reported', rel(`${FX}.reported`)], ['release-other', rel(`${FX}.pw`)],
  ['release-media-dialog', `${rel(`${FX}.media`)};document.querySelector('.rchips [data-mi]').click();${wait(900)}`]];
for (const theme of ['dark', 'light']) for (const [name, pre] of states) {
  const url = BASE + 'releases.html';
  await send('Page.navigate', {url: url + '?r=' + Math.random() + '#/'}); for (let i = 0; i < 60 && !(await js('!!window.READY')); i++) await sleep(200);
  await js(`localStorage.clear()`); await send('Page.navigate', {url: url + '?r=' + Math.random() + '#/'}); for (let i = 0; i < 60 && !(await js('!!window.READY')); i++) await sleep(200); await sleep(400);
  await js(`document.documentElement.dataset.theme='${theme}'`); if (pre) await js(`(async()=>{${pre}})()`); await sleep(500);
  const r = await send('Page.captureScreenshot', {format: 'png'}); const png = `${out}/${name}-${theme}.png`;
  writeFileSync(png, Buffer.from(r.data, 'base64')); execFileSync('cwebp', ['-quiet', '-q', '70', png, '-o', png.replace(/\.png$/, '.webp')]); unlinkSync(png);
}
console.log(`reference: ${states.length} screens x 2 themes`); ws.close(); chrome.kill('SIGKILL'); process.exit(0);
