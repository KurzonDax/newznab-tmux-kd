// Draws placeholder preview frames and sample images for the invented Adult dataset (no third-party artwork).
// Usage: node make-adult-art.mjs <ad dir containing art.json>   (needs Google Chrome and cwebp)
import {spawn, execFileSync} from 'node:child_process';
import {writeFileSync, readFileSync, mkdirSync, mkdtempSync, unlinkSync} from 'node:fs';
import {tmpdir} from 'node:os';
const dir = process.argv[2], art = JSON.parse(readFileSync(`${dir}/art.json`, 'utf8')), PORT = 9372;
mkdirSync(`${dir}/previews/preview`, {recursive: true}); mkdirSync(`${dir}/previews/sample`, {recursive: true});
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', ['--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(tmpdir() + '/adart-')}`, 'about:blank'], {stdio: 'ignore'});
const sleep = ms => new Promise(r => setTimeout(r, ms)); let ws, seq = 0; const pending = new Map();
for (let i = 0; i < 40; i++) { try { const t = await (await fetch(`http://localhost:${PORT}/json`)).json(); const p = t.find(x => x.type === 'page'); if (p) { ws = new WebSocket(p.webSocketDebuggerUrl); await new Promise(r => ws.onopen = r); break; } } catch (e) {} await sleep(250); }
ws.onmessage = m => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d.result); pending.delete(d.id); } };
const send = (method, params = {}) => new Promise(r => { const id = ++seq; pending.set(id, r); ws.send(JSON.stringify({id, method, params})); });
await send('Page.enable');
for (const [i, p] of art.entries()) {
  const {w, h} = p, hue = (i * 47) % 360, sample = p.kind === 'sample';
  // a preview: one soft frame; a sample: a grid of frames, as a contact sheet is
  const cells = sample ? Array.from({length: 12}, (_, k) => `<div style="background:radial-gradient(circle at 35% 40%,oklch(0.55 0.09 ${(hue + k * 23) % 360}),oklch(0.2 0.04 ${hue}))"></div>`).join('') : '';
  const body = sample
    ? `<div style="width:${w}px;height:${h}px;display:grid;grid-template-columns:repeat(4,1fr);grid-template-rows:repeat(3,1fr);gap:${Math.max(2, Math.round(w / 300))}px;background:#111">${cells}</div>`
    : `<div style="width:${w}px;height:${h}px;display:grid;place-items:center;background:radial-gradient(circle at 30% 35%,oklch(0.5 0.1 ${hue}),oklch(0.16 0.04 ${hue}));font:600 ${Math.round(h / 14)}px/1.2 Helvetica,Arial,sans-serif;color:oklch(0.95 0.02 ${hue});text-align:center">Placeholder preview frame<br><span style="font-weight:400;opacity:.7;font-size:.6em">${w} × ${h}</span></div>`;
  await send('Emulation.setDeviceMetricsOverride', {width: w, height: h, deviceScaleFactor: 1, mobile: false});
  await send('Page.navigate', {url: 'data:text/html;charset=utf-8,' + encodeURIComponent(`<body style="margin:0">${body}</body>`)}); await sleep(80);
  const r = await send('Page.captureScreenshot', {format: 'png', clip: {x: 0, y: 0, width: w, height: h, scale: 1}});
  const png = `${dir}/previews/${p.path}.png`; writeFileSync(png, Buffer.from(r.data, 'base64'));
  execFileSync('cwebp', ['-quiet', '-q', '55', png, '-o', `${dir}/previews/${p.path}`]); unlinkSync(png);
}
console.log(`pictures ${art.length}`); chrome.kill('SIGKILL'); process.exit(0);
