// Draws placeholder box art for the invented Console covers (no third-party artwork).
// Usage: node make-console-art.mjs   (reads cn/art.json, writes cn/covers/<id>.webp; needs Google Chrome and cwebp)
// IGDB's cover_big is 264 x 374; drawn at 2x so the details page's 190 px cover stays sharp.
import {spawn, execFileSync} from 'node:child_process';
import {writeFileSync, readFileSync, mkdirSync, mkdtempSync, unlinkSync} from 'node:fs';
import {tmpdir} from 'node:os';
const art = JSON.parse(readFileSync('cn/art.json', 'utf8')), data = JSON.parse(readFileSync('cn/data.json', 'utf8')), PORT = 9373, W = 528, H = 748;
delete art._note; mkdirSync('cn/covers', {recursive: true});
const plat = Object.fromEntries(data.rel.map(a => [a[0], a[6]]));
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', ['--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(tmpdir() + '/cnart-')}`, 'about:blank'], {stdio: 'ignore'});
const sleep = ms => new Promise(r => setTimeout(r, ms)); let ws, seq = 0; const pending = new Map();
for (let i = 0; i < 40; i++) { try { const t = await (await fetch(`http://localhost:${PORT}/json`)).json(); const p = t.find(x => x.type === 'page'); if (p) { ws = new WebSocket(p.webSocketDebuggerUrl); await new Promise(r => ws.onopen = r); break; } } catch (e) {} await sleep(250); }
ws.onmessage = m => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d.result); pending.delete(d.id); } };
const send = (method, params = {}) => new Promise(r => { const id = ++seq; pending.set(id, r); ws.send(JSON.stringify({id, method, params})); });
const esc = s => s.replace(/[&<>]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;'}[c]));
await send('Page.enable'); await send('Emulation.setDeviceMetricsOverride', {width: W, height: H, deviceScaleFactor: 1, mobile: false});
for (const [id, {t, hue}] of Object.entries(art)) {
  const h2 = (hue + 50) % 360, p = plat[id] || 'Console';
  // a platform band across the top, as console boxes have; a soft scene; the title low on the cover
  const html = `<div style="width:${W}px;height:${H}px;position:relative;overflow:hidden;font-family:Helvetica,Arial,sans-serif;background:radial-gradient(circle at 70% 30%,oklch(0.62 0.15 ${hue}),oklch(0.2 0.06 ${h2}) 75%)">
    <div style="position:absolute;inset:0;background:radial-gradient(circle at 25% 75%,oklch(0.45 0.12 ${h2}/.7),transparent 55%)"></div>
    <div style="position:absolute;left:0;right:0;top:0;height:58px;background:oklch(0.42 0.02 ${hue});color:#fff;font:700 26px/58px Helvetica,Arial,sans-serif;letter-spacing:.06em;padding-left:44px">${esc(p.toUpperCase())}</div>
    <div style="position:absolute;left:36px;right:36px;bottom:70px;color:oklch(0.98 0.02 ${hue});font:800 64px/1.02 Helvetica,Arial,sans-serif;letter-spacing:-.02em;text-shadow:0 4px 18px rgb(0 0 0/.45)">${esc(t)}</div>
    <div style="position:absolute;left:36px;bottom:30px;color:oklch(0.9 0.03 ${hue}/.85);font:600 20px Helvetica,Arial,sans-serif;letter-spacing:.12em">PLACEHOLDER ART</div></div>`;
  await send('Page.navigate', {url: 'data:text/html;charset=utf-8,' + encodeURIComponent(`<body style="margin:0">${html}</body>`)}); await sleep(120);
  const r = await send('Page.captureScreenshot', {format: 'png', clip: {x: 0, y: 0, width: W, height: H, scale: 1}});
  const png = `cn/covers/${id}.png`; writeFileSync(png, Buffer.from(r.data, 'base64')); execFileSync('cwebp', ['-quiet', '-q', '70', png, '-o', `cn/covers/${id}.webp`]); unlinkSync(png);
}
console.log(`covers ${Object.keys(art).length}`); chrome.kill('SIGKILL'); process.exit(0);
