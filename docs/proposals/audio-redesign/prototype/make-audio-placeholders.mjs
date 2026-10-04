// The public copy's stand-ins for the real previews and spectrograms: one generated tone (au/previews/placeholder.wav,
// 30 s, 8 kHz mono, a soft three-note phrase) and one placeholder picture in a spectrogram's shape
// (au/spectra/placeholder.webp, 1338 x 384, labelled PLACEHOLDER). Usage: node make-audio-placeholders.mjs (needs Google Chrome and cwebp)
import {spawn, execFileSync} from 'node:child_process'; import {writeFileSync, mkdirSync, mkdtempSync, unlinkSync} from 'node:fs'; import {tmpdir} from 'node:os';
mkdirSync('au/previews', {recursive: true}); mkdirSync('au/spectra', {recursive: true});
const rate = 8000, secs = 30, n = rate * secs, pcm = Buffer.alloc(n);
const notes = [220, 277.18, 329.63];
for (let i = 0; i < n; i++) { const t = i / rate, f = notes[Math.floor(t / 1.5) % 3], env = Math.min(1, (t % 1.5) * 8) * Math.exp(-(t % 1.5) * 1.6);
  pcm[i] = 128 + Math.round(52 * env * Math.sin(2 * Math.PI * f * t)); }
const h = Buffer.alloc(44); h.write('RIFF', 0); h.writeUInt32LE(36 + n, 4); h.write('WAVE', 8); h.write('fmt ', 12); h.writeUInt32LE(16, 16); h.writeUInt16LE(1, 20); h.writeUInt16LE(1, 22);
h.writeUInt32LE(rate, 24); h.writeUInt32LE(rate, 28); h.writeUInt16LE(1, 32); h.writeUInt16LE(8, 34); h.write('data', 36); h.writeUInt32LE(n, 40);
writeFileSync('au/previews/placeholder.wav', Buffer.concat([h, pcm]));
const PORT = 9464, W = 1338, H = 384;
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', ['--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(tmpdir() + '/auph-')}`, 'about:blank'], {stdio: 'ignore'});
const sleep = ms => new Promise(r => setTimeout(r, ms)); let ws, seq = 0; const pending = new Map();
for (let i = 0; i < 40; i++) { try { const t = await (await fetch(`http://localhost:${PORT}/json`)).json(); const p = t.find(x => x.type === 'page'); if (p) { ws = new WebSocket(p.webSocketDebuggerUrl); await new Promise(r => ws.onopen = r); break; } } catch {} await sleep(250); }
ws.onmessage = m => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d.result); pending.delete(d.id); } };
const send = (method, params = {}) => new Promise(r => { const id = ++seq; pending.set(id, r); ws.send(JSON.stringify({id, method, params})); });
await send('Page.enable'); await send('Emulation.setDeviceMetricsOverride', {width: W, height: H, deviceScaleFactor: 1, mobile: false});
// a spectrogram's layout: black ground, a plot area with a warm band low and cool fading high, axis ticks, a scale bar
const cols = Array.from({length: 120}, (_, i) => { const x = 160 + i * 9.5, hgt = 40 + ((i * 37) % 23) * 3; return `<div style="position:absolute;left:${x}px;bottom:66px;width:7px;height:${hgt}px;background:linear-gradient(oklch(0.45 0.2 300),oklch(0.75 0.2 40),oklch(0.92 0.15 95))"></div>`; }).join('');
const html = `<div style="width:${W}px;height:${H}px;position:relative;background:#000;font:600 12px Helvetica,Arial,sans-serif;color:#ddd">
  <div style="position:absolute;left:150px;top:62px;width:1110px;height:256px;border:1px solid #888;background:linear-gradient(oklch(0.18 0.08 280),oklch(0.32 0.16 300) 55%,oklch(0.5 0.2 20))"></div>${cols}
  <div style="position:absolute;left:1280px;top:62px;width:14px;height:256px;background:linear-gradient(#fff,oklch(0.85 0.17 90),oklch(0.6 0.22 30),oklch(0.35 0.18 300),#000)"></div>
  <div style="position:absolute;right:60px;top:28px;letter-spacing:.12em">PLACEHOLDER SPECTROGRAM</div>
  <div style="position:absolute;left:640px;top:340px;letter-spacing:.12em">TIME</div></div>`;
await send('Page.navigate', {url: 'data:text/html;charset=utf-8,' + encodeURIComponent(`<body style="margin:0">${html}</body>`)}); await sleep(300);
const r = await send('Page.captureScreenshot', {format: 'png', clip: {x: 0, y: 0, width: W, height: H, scale: 1}});
writeFileSync('au/spectra/placeholder.png', Buffer.from(r.data, 'base64')); execFileSync('cwebp', ['-quiet', '-q', '75', 'au/spectra/placeholder.png', '-o', 'au/spectra/placeholder.webp']); unlinkSync('au/spectra/placeholder.png');
console.log('placeholders: au/previews/placeholder.wav, au/spectra/placeholder.webp'); chrome.kill('SIGKILL'); process.exit(0);
