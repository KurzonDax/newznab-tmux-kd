// Draws placeholder poster and preview art for the invented dataset (no third-party artwork).
// Usage: node make-demo-art.mjs <dir containing art.json>   (needs Google Chrome and cwebp)
import {spawn, execFileSync} from 'node:child_process';
import {writeFileSync, readFileSync, mkdirSync, mkdtempSync, unlinkSync} from 'node:fs';
import {tmpdir} from 'node:os';
const dir = process.argv[2], art = JSON.parse(readFileSync(`${dir}/art.json`, 'utf8')), PORT = 9371;
mkdirSync(`${dir}/posters`, {recursive: true}); mkdirSync(`${dir}/previews/preview`, {recursive: true});
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', ['--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(tmpdir() + '/cdp-')}`, '--window-size=1920,1080', 'about:blank']);
const sleep = ms => new Promise(r => setTimeout(r, ms)); let ws, seq = 0; const pending = new Map();
for (let i = 0; i < 40; i++) { try { const t = await (await fetch(`http://127.0.0.1:${PORT}/json`)).json(); const p = t.find(x => x.type === 'page'); if (p) { ws = new WebSocket(p.webSocketDebuggerUrl); await new Promise(r => ws.onopen = r); break; } } catch (e) {} await sleep(250); }
ws.onmessage = m => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d.result); pending.delete(d.id); } };
const send = (method, params = {}) => new Promise(r => { const id = ++seq; pending.set(id, r); ws.send(JSON.stringify({id, method, params})); });
const esc = s => s.replace(/[&<>]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;'}[c]));
async function draw(html, w, h, file) {
  await send('Emulation.setDeviceMetricsOverride', {width: w, height: h, deviceScaleFactor: 1, mobile: false});
  await send('Page.navigate', {url: 'data:text/html;charset=utf-8,' + encodeURIComponent(`<body style="margin:0">${html}</body>`)}); await sleep(120);
  const r = await send('Page.captureScreenshot', {format: 'png', clip: {x: 0, y: 0, width: w, height: h, scale: 1}});
  const png = `${file}.png`; writeFileSync(png, Buffer.from(r.data, 'base64')); execFileSync('cwebp', ['-quiet', '-q', '60', png, '-o', file]); unlinkSync(png);
}
await send('Page.enable');
for (const p of art.posters) {
  const h2 = (p.hue + 40) % 360;
  await draw(`<div style="width:400px;height:600px;box-sizing:border-box;padding:44px 36px;display:flex;flex-direction:column;justify-content:flex-end;background:linear-gradient(160deg,oklch(0.55 0.13 ${p.hue}),oklch(0.22 0.07 ${h2}));font-family:Georgia,serif;color:oklch(0.97 0.02 ${p.hue})"><div style="width:120px;height:120px;border-radius:60px;margin-bottom:auto;background:oklch(0.8 0.12 ${h2}/.35)"></div><div style="font-size:46px;line-height:1.05;font-weight:700;letter-spacing:-.01em">${esc(p.t)}</div><div style="margin-top:14px;font:600 13px/1 Helvetica,Arial,sans-serif;letter-spacing:.18em;text-transform:uppercase;opacity:.75">Placeholder art</div></div>`, 400, 600, `${dir}/posters/${p.id}.webp`);
}
for (const [i, p] of art.previews.entries()) {
  const [w, h] = p.full ? [1920, 1080] : [640, 360], hue = (i * 53) % 360;
  await draw(`<div style="width:${w}px;height:${h}px;display:grid;place-items:center;background:radial-gradient(circle at 30% 35%,oklch(0.5 0.1 ${hue}),oklch(0.16 0.04 ${hue}));font:600 ${Math.round(h / 14)}px/1.2 Helvetica,Arial,sans-serif;color:oklch(0.95 0.02 ${hue});text-align:center">Placeholder preview frame<br><span style="font-weight:400;opacity:.7;font-size:.6em">${w} × ${h}</span></div>`, w, h, `${dir}/previews/${p.path}`);
}
console.log(`posters ${art.posters.length}, previews ${art.previews.length}`); chrome.kill(); process.exit(0);
