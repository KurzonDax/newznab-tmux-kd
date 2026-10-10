// Draws placeholder posters (shows and films) and preview pictures for the invented home-page dataset (no third-party artwork).
// Usage: node make-home-art.mjs <hm dir containing art.json>   (needs Google Chrome and cwebp)
import {spawn, execFileSync} from 'node:child_process';
import {writeFileSync, readFileSync, mkdirSync, mkdtempSync, unlinkSync} from 'node:fs';
import {tmpdir} from 'node:os';
const dir = process.argv[2], art = JSON.parse(readFileSync(`${dir}/art.json`, 'utf8')), PORT = 9374;
for (const d of ['art/tv', 'art/mv', 'art/pv']) mkdirSync(`${dir}/${d}`, {recursive: true});
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', ['--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(tmpdir() + '/hmart-')}`, 'about:blank'], {stdio: 'ignore'});
const sleep = ms => new Promise(r => setTimeout(r, ms)); let ws, seq = 0; const pending = new Map();
for (let i = 0; i < 40; i++) { try { const t = await (await fetch(`http://localhost:${PORT}/json`)).json(); const p = t.find(x => x.type === 'page'); if (p) { ws = new WebSocket(p.webSocketDebuggerUrl); await new Promise(r => ws.onopen = r); break; } } catch (e) {} await sleep(250); }
ws.onmessage = m => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d.result); pending.delete(d.id); } };
const send = (method, params = {}) => new Promise(r => { const id = ++seq; pending.set(id, r); ws.send(JSON.stringify({id, method, params})); });
const esc = s => s.replace(/[&<>]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;'}[c]));
async function draw(html, w, h, file) {
  await send('Emulation.setDeviceMetricsOverride', {width: w, height: h, deviceScaleFactor: 1, mobile: false});
  await send('Page.navigate', {url: 'data:text/html;charset=utf-8,' + encodeURIComponent(`<body style="margin:0">${html}</body>`)}); await sleep(90);
  const r = await send('Page.captureScreenshot', {format: 'png', clip: {x: 0, y: 0, width: w, height: h, scale: 1}});
  const png = `${file}.png`; writeFileSync(png, Buffer.from(r.data, 'base64')); execFileSync('cwebp', ['-quiet', '-q', '58', png, '-o', file]); unlinkSync(png);
}
await send('Page.enable');
for (const p of art.posters) {
  const h2 = (p.hue + 40) % 360;
  await draw(`<div style="width:400px;height:600px;box-sizing:border-box;padding:44px 36px;display:flex;flex-direction:column;justify-content:flex-end;background:linear-gradient(160deg,oklch(0.55 0.13 ${p.hue}),oklch(0.22 0.07 ${h2}));font-family:Georgia,serif;color:oklch(0.97 0.02 ${p.hue})"><div style="font-size:40px;line-height:1.1;font-weight:700">${esc(p.t)}</div>${p.y ? `<div style="margin-top:12px;font-size:22px;opacity:.85">${esc(String(p.y))}</div>` : ''}<div style="margin-top:28px;font:14px Helvetica,Arial,sans-serif;letter-spacing:.08em;opacity:.7">PLACEHOLDER POSTER</div></div>`, 400, 600, `${dir}/art/${p.path}`);
}
for (const p of art.previews) {
  await draw(`<div style="width:640px;height:360px;display:grid;place-items:center;background:radial-gradient(circle at 30% 35%,oklch(0.5 0.1 ${p.hue}),oklch(0.16 0.04 ${p.hue}));font:600 26px/1.2 Helvetica,Arial,sans-serif;color:oklch(0.95 0.02 ${p.hue});text-align:center">Placeholder preview</div>`, 640, 360, `${dir}/art/${p.path}`);
}
console.log(`posters ${art.posters.length}, previews ${art.previews.length}`); chrome.kill('SIGKILL'); process.exit(0);
