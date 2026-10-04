// Draws placeholder album art for the invented Audio covers (no third-party artwork).
// Usage: node make-audio-art.mjs   (reads au/art.json + au/music.json, writes au/covers/<id>.webp; needs Google Chrome and cwebp)
// Square, 600 x 600: the details page's 200 px cover stays sharp at 2x and up. SMALL=1 writes 300 x 300 (the public copy).
import {spawn, execFileSync} from 'node:child_process';
import {writeFileSync, readFileSync, mkdirSync, mkdtempSync, unlinkSync, existsSync} from 'node:fs';
import {tmpdir} from 'node:os';
const art = JSON.parse(readFileSync('au/art.json', 'utf8')), music = JSON.parse(readFileSync('au/music.json', 'utf8')), PORT = 9374, W = 600;
delete art._note; mkdirSync('au/covers', {recursive: true});
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', ['--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(tmpdir() + '/auart-')}`, 'about:blank'], {stdio: 'ignore'});
const sleep = ms => new Promise(r => setTimeout(r, ms)); let ws, seq = 0; const pending = new Map();
for (let i = 0; i < 40; i++) { try { const t = await (await fetch(`http://localhost:${PORT}/json`)).json(); const p = t.find(x => x.type === 'page'); if (p) { ws = new WebSocket(p.webSocketDebuggerUrl); await new Promise(r => ws.onopen = r); break; } } catch (e) {} await sleep(250); }
ws.onmessage = m => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d.result); pending.delete(d.id); } };
const send = (method, params = {}) => new Promise(r => { const id = ++seq; pending.set(id, r); ws.send(JSON.stringify({id, method, params})); });
const esc = s => s.replace(/[&<>]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;'}[c]));
await send('Page.enable'); await send('Emulation.setDeviceMetricsOverride', {width: W, height: W, deviceScaleFactor: 1, mobile: false});
let n = 0;
for (const [id, {hue, s}] of Object.entries(art)) {
  if (existsSync(`au/covers/${id}.webp`)) continue;
  const mu = music[id] || {}, al = (mu.al || '').slice(0, 60), ar = (mu.a || '').slice(0, 40), h2 = (hue + 140) % 360;
  // four sleeve styles so a page of covers is not one look: a soft field, rings, stripes, a big block
  const bg = [
    `radial-gradient(circle at 30% 30%,oklch(0.7 0.14 ${hue}),oklch(0.22 0.07 ${h2}) 80%)`,
    `repeating-radial-gradient(circle at 50% 55%,oklch(0.3 0.08 ${hue}) 0 18px,oklch(0.5 0.13 ${hue}) 18px 36px)`,
    `repeating-linear-gradient(135deg,oklch(0.6 0.13 ${hue}) 0 40px,oklch(0.85 0.06 ${h2}) 40px 80px)`,
    `linear-gradient(oklch(0.9 0.04 ${hue}),oklch(0.9 0.04 ${hue}))`][s];
  const block = s === 3 ? `<div style="position:absolute;left:60px;top:60px;width:300px;height:300px;border-radius:50%;background:oklch(0.55 0.17 ${hue})"></div>` : '';
  const ink = s === 3 ? `oklch(0.2 0.04 ${hue})` : '#fff', shadow = s === 3 ? 'none' : '0 3px 16px rgb(0 0 0/.5)';
  const html = `<div style="width:${W}px;height:${W}px;position:relative;overflow:hidden;font-family:Helvetica,Arial,sans-serif;background:${bg}">${block}
    <div style="position:absolute;left:40px;right:40px;bottom:96px;color:${ink};font:800 ${al.length > 28 ? 40 : 54}px/1.04 Helvetica,Arial,sans-serif;letter-spacing:-.02em;text-shadow:${shadow}">${esc(al)}</div>
    <div style="position:absolute;left:40px;right:40px;bottom:56px;color:${ink};font:600 24px Helvetica,Arial,sans-serif;opacity:.9;text-shadow:${shadow};white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(ar)}</div>
    <div style="position:absolute;left:40px;top:34px;color:${ink};font:600 15px Helvetica,Arial,sans-serif;letter-spacing:.14em;opacity:.75">PLACEHOLDER ART</div></div>`;
  await send('Page.navigate', {url: 'data:text/html;charset=utf-8,' + encodeURIComponent(`<body style="margin:0">${html}</body>`)}); await sleep(90);
  const r = await send('Page.captureScreenshot', {format: 'png', clip: {x: 0, y: 0, width: W, height: W, scale: 1}});
  const png = `au/covers/${id}.png`; writeFileSync(png, Buffer.from(r.data, 'base64')); execFileSync('cwebp', ['-quiet', ...(process.env.SMALL ? ['-resize', '300', '300', '-q', '62'] : ['-q', '72']), png, '-o', `au/covers/${id}.webp`]); unlinkSync(png); n++;
}
console.log(`covers ${n}`); chrome.kill('SIGKILL'); process.exit(0);
