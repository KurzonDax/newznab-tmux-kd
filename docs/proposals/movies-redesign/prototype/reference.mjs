// Reference screenshots of the Movies prototype: each state in dark and light at 1600 x 1000, as webp.
// node mvreference.mjs <out-dir>   (serves nothing itself: MV_URL points at a served copy; needs Google Chrome and cwebp)
import {spawn, execFileSync} from 'node:child_process'; import {mkdtempSync, mkdirSync, writeFileSync, unlinkSync} from 'node:fs'; import {tmpdir} from 'node:os';
const URL_ = process.env.MV_URL || 'http://127.0.0.1:8766/movies.html', out = (process.argv[2] || '.') + '/reference', PORT = 9383;
mkdirSync(out, {recursive: true});
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', ['--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(tmpdir() + '/mvref-')}`, 'about:blank'], {stdio: 'ignore'});
const sleep = ms => new Promise(r => setTimeout(r, ms)); let ws, seq = 0; const pending = new Map();
for (let i = 0; i < 40; i++) { try { const t = await (await fetch(`http://127.0.0.1:${PORT}/json`)).json(); const p = t.find(x => x.type === 'page'); if (p) { ws = new WebSocket(p.webSocketDebuggerUrl); await new Promise(r => ws.onopen = r); break; } } catch {} await sleep(250); }
ws.onmessage = m => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d.result); pending.delete(d.id); } };
const send = (method, params = {}) => new Promise(r => { const id = ++seq; pending.set(id, r); ws.send(JSON.stringify({id, method, params})); });
const js = async e => (await send('Runtime.evaluate', {expression: e, awaitPromise: true, returnByValue: true})).result?.value;
await send('Page.enable'); await send('Emulation.setDeviceMetricsOverride', {width: 1600, height: 1000, deviceScaleFactor: 1, mobile: false});
const FILM = `(await (await fetch('mv/fixtures.json')).json())`;
const states = [
  ['releases', ``], ['releases-genre-menu', `document.querySelector('[data-ddtoggle=genre]').click()`], ['releases-year-menu', `document.querySelector('[data-ddtoggle=year]').click()`],
  ['releases-filters-set', `state.genre.add('Drama');state.aud.add('English');state.comp='95';route()`], ['releases-selection', `document.querySelector('.feed input[data-select]').click()`],
  ['releases-media-info', `document.querySelector('.feed [data-mi]').click();await new Promise(r=>setTimeout(r,700))`],
  ['films', `location.hash='#/films';await new Promise(r=>setTimeout(r,600))`], ['films-filters-set', `location.hash='#/films';await new Promise(r=>setTimeout(r,400));state.wgenre.add('Drama');state.wscore.add('7');route()`],
  ['films-person', `location.hash='#/films?person='+encodeURIComponent(Object.keys(PEOPLE).find(n=>PEOPLE[n].length>2));await new Promise(r=>setTimeout(r,600))`],
  ['film', `location.hash='#/film/'+${FILM}.film;await new Promise(r=>setTimeout(r,2500))`],
  ['film-source-menu', `location.hash='#/film/'+${FILM}.film;await new Promise(r=>setTimeout(r,2500));state.pres.add('1080p');route();scrollTo(0,420);document.querySelector('[data-ddtoggle=psrc]').click()`],
  ['film-similar', `location.hash='#/film/'+${FILM}.film;await new Promise(r=>setTimeout(r,2500));scrollTo(0,99999)`],
  ['details', `location.hash='#/release/'+${FILM}.release;await new Promise(r=>setTimeout(r,2500))`], ['details-tables', `location.hash='#/release/'+${FILM}.release;await new Promise(r=>setTimeout(r,2500));scrollTo(0,document.querySelector('#sibs').offsetTop-90)`],
  ['details-pressed', `location.hash='#/release/'+${FILM}.release;await new Promise(r=>setTimeout(r,2500));document.querySelector('.dacts [data-cart]').click();document.querySelector('.dacts [data-watch]').click()`],
  ['details-paged-table', `location.hash='#/release/'+${FILM}.bigRelease;await new Promise(r=>setTimeout(r,2500));scrollTo(0,document.querySelector('#sibs').offsetTop-90)`],
  ['details-predb', `location.hash='#/release/'+${FILM}.predbRelease;await new Promise(r=>setTimeout(r,2500));scrollTo(0,420)`], ['details-no-film', `location.hash='#/release/'+${FILM}.noFilmRelease;await new Promise(r=>setTimeout(r,2500))`],
  ['film-last-page', `location.hash='#/film/'+${FILM}.big+'/p/'+Math.ceil(BYFILM[${FILM}.big]?.length/50||4);await new Promise(r=>setTimeout(r,2500));scrollTo(0,99999)`]];
for (const theme of ['dark', 'light']) for (const [name, pre] of states) {
  await send('Page.navigate', {url: URL_ + '?r=' + Math.random() + '#/'}); for (let i = 0; i < 60 && !(await js('!!window.READY')); i++) await sleep(200); await sleep(400);
  await js(`document.documentElement.dataset.theme='${theme}'`); if (pre) await js(`(async()=>{${pre}})()`); await sleep(500);
  if (name === 'film-last-page') { await js(`(async()=>{location.hash='#/film/'+${FILM}.big+'/p/'+Math.ceil(BYFILM[${FILM}.big].length/50);})()`); await sleep(800); await js('scrollTo(0,99999)'); await sleep(300); }
  const r = await send('Page.captureScreenshot', {format: 'png'}); const png = `${out}/${name}-${theme}.png`;
  writeFileSync(png, Buffer.from(r.data, 'base64')); execFileSync('cwebp', ['-quiet', '-q', '70', png, '-o', png.replace(/\.png$/, '.webp')]); unlinkSync(png);
}
console.log(`reference: ${states.length} states x 2 themes`); ws.close(); chrome.kill('SIGKILL'); process.exit(0);
