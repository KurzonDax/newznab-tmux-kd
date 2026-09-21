// Generates an ENTIRELY INVENTED dataset in the shapes tv.html reads, so the prototype can live in
// the public repository. Nothing here comes from a real catalogue: shows, people, networks,
// release names, groups, posters, NFO text, file lists and media info are all made up from the
// word lists below with a fixed seed. Usage: node gen-demo-data.mjs <out-dir>
import {writeFileSync, mkdirSync} from 'node:fs';
const out = process.argv[2] || 'demo';
mkdirSync(out, {recursive: true});

let seed = 20260921;
const rnd = () => { seed = (seed * 1664525 + 1013904223) % 4294967296; return seed / 4294967296; };
const int = (a, b) => a + Math.floor(rnd() * (b - a + 1));
const pick = a => a[Math.floor(rnd() * a.length)];
const chance = p => rnd() < p;
const sample = (a, n) => { const c = [...a], o = []; while (o.length < n && c.length) o.push(c.splice(Math.floor(rnd() * c.length), 1)[0]); return o; };
const weighted = pairs => { const t = pairs.reduce((s, [, w]) => s + w, 0); let x = rnd() * t; for (const [v, w] of pairs) { if ((x -= w) < 0) return v; } return pairs[0][0]; };
const hex = n => Array.from({length: n}, () => '0123456789abcdef'[int(0, 15)]).join('');
const guid = () => `${hex(8)}-${hex(4)}-${hex(4)}-${hex(4)}-${hex(12)}`;

const A = ['Amber', 'Silent', 'Northern', 'Hollow', 'Golden', 'Paper', 'Distant', 'Crooked', 'Glass', 'Midnight', 'Salt', 'Iron', 'Velvet', 'Lantern', 'Borrowed', 'Winter', 'Second', 'Copper', 'Quiet', 'Electric', 'Broken', 'Little', 'Last', 'Blue', 'Wild'];
const N = ['Harbor', 'Orchard', 'Signal', 'Parish', 'Ledger', 'Tides', 'Kitchen', 'Crossing', 'Garrison', 'Almanac', 'Station', 'Meridian', 'Hours', 'Republic', 'Atlas', 'Foundry', 'Lighthouse', 'County', 'Circuit', 'Archive', 'Pines', 'Bureau', 'Frontier', 'Summit', 'Arcade'];
const FIRST = ['Mara', 'Tobias', 'Ines', 'Callum', 'Yuki', 'Dario', 'Freya', 'Anselm', 'Noor', 'Piet', 'Solveig', 'Emeka', 'Lucia', 'Halvard', 'Priya', 'Oskar', 'Tamsin', 'Bao', 'Ilse', 'Rafael', 'Wren', 'Kofi', 'Marit', 'Jun', 'Elio', 'Saskia', 'Teo', 'Anouk', 'Idris', 'Greta'];
const LAST = ['Vantongeren', 'Oyelaran', 'Brightwater', 'Kasprzak', 'Delacroix-Ng', 'Thorsby', 'Almeida-Ross', 'Quillfeather', 'Mbeki-Stone', 'Halloran', 'Szymborski', 'Arkwright', 'Nakagawa-Lund', 'Fairweather', 'Okonjo', 'Lindqvist', 'Castellanos', 'Wetherby', 'Zielinski', 'Pemberthy'];
const PEOPLE = []; for (const f of FIRST) for (const l of LAST) PEOPLE.push(`${f} ${l}`);
const GENRES = ['Drama', 'Comedy', 'Crime', 'Sci-Fi', 'Fantasy', 'Action', 'Adventure', 'Documentary', 'Reality', 'Family', 'Mystery', 'Thriller', 'Animation', 'Children', 'Horror', 'Romance', 'War'];
const LANGS = [['English', 40], ['Korean', 22], ['Portuguese', 8], ['Japanese', 8], ['Spanish', 7], ['German', 6], ['French', 5], ['Swedish', 2], ['Italian', 2]];
const NETS = ['Northlight TV', 'Kanal Nio', 'Harbor+', 'Meridian One', 'Public Six', 'Tessera', 'Lumen Play', 'Ostrava Net', 'Seabird', 'Atlas Prime', 'Paperhouse', 'Station 12', 'Verdant', 'Kite Stream', 'Halcyon', 'Red Pier', 'Borealis', 'Quarto', 'JBN'];
const RATINGS = ['TV-Y', 'TV-Y7', 'TV-G', 'TV-PG', 'TV-14', 'TV-MA'];
const RLS = ['NTX', 'KOGi', 'PLAiD', 'FLUX', 'OTTER', 'HALO9', 'MiNT', 'BRASS', 'SPRUCE', 'QUILL', 'EMBER', 'TiDE', 'VOLT', 'PiNE'];
const GROUPS = ['alt.binaries.example.tv', 'alt.binaries.example.hd', 'alt.binaries.example.uhd', 'alt.binaries.example.misc', 'alt.binaries.example.foreign'];
const POSTERS = ['courier <courier@example.invalid>', 'nightshift <ns@example.invalid>', 'Kestrel <k@example.invalid>', 'uploader7 <u7@example.invalid>', 'paperboat <pb@example.invalid>', 'anon <anon@example.invalid>'];
const WORDS = ['The', 'Long', 'Way', 'Back', 'House', 'Rules', 'Small', 'Fires', 'Open', 'Water', 'Night', 'Shift', 'Cold', 'Start', 'Good', 'Neighbors', 'Old', 'Debts', 'New', 'Money', 'Second', 'Chances', 'Dead', 'Reckoning', 'Home', 'Truths', 'Fair', 'Warning', 'Late', 'Arrivals', 'Loose', 'Ends'];
const epTitle = () => sample(WORDS, int(2, 4)).join(' ');
const SUMMARY = ['A family-run %s on the edge of town becomes the unlikely centre of a dispute that has been simmering for three generations.', 'When a retired %s returns home, the quiet routines of a coastal village begin to unravel one favour at a time.', 'Four strangers inherit the same %s and discover that the previous owner left more questions than furniture.', 'An overworked %s and an optimistic trainee try to keep a failing institution open through one impossible year.', 'In a city that never quite recovered, a %s keeps a careful record of everything people would rather forget.'];
const THING = ['ferry office', 'surveyor', 'bakery', 'magistrate', 'radio station', 'night clerk', 'boatyard', 'archivist'];

const NOW = Math.floor(Date.UTC(2026, 8, 19, 22, 0, 0) / 1000), DAY = 86400;
const shows = {}, meta = {}, eps = {}, rel = [], rx = {x: {}, summ: {}}, media = {}, files = {}, nfo = {}, previews = [], repair = [];
let nextEp = 1000, nextRel = 500000;
const titles = new Set();
const title = () => { for (;;) { const t = chance(.5) ? `${pick(A)} ${pick(N)}` : chance(.5) ? `The ${pick(N)}` : `${pick(N)} & ${pick(N)}`; if (!titles.has(t)) { titles.add(t); return t; } } };

// fixtures the checks rely on: id -> shape
const FIX = {
  longShow: 9001, // 7 seasons, well filled, searchable by title
  manySeasons: 9002, // 24 seasons + specials
  tenSeasons: 9003, // exactly 10 seasons
  thinShow: 9004, // no poster, no metadata, episodes without titles, some same-size pairs
  dailyShow: 9005, // date-named releases -> "By air date"
  mergedNetwork: 'jbn', mergedNetworkCount: 16,
};
const specs = [];
specs.push({id: FIX.longShow, t: 'The Glass Meridian', seasons: [1, 2, 3, 4, 5, 6, 7], perSeason: 20, perEp: [2, 3], lang: 'English', rating: 'TV-14', st: 'Ended', prem: 2003, g: ['Drama', 'Crime'], n: 'Northlight TV'});
specs.push({id: FIX.manySeasons, t: 'Copper County', seasons: [0, ...Array.from({length: 24}, (_, i) => i + 1)], perSeason: 5, perEp: [1, 2], lang: 'English', rating: 'TV-14', st: 'Running', prem: 1999, g: ['Comedy', 'Family'], n: 'Harbor+'});
specs.push({id: FIX.tenSeasons, t: 'Last Frontier Arcade', seasons: [1, 3, 4, 5, 6, 7, 11, 12, 13, 14], perSeason: 10, perEp: [1, 2], lang: 'English', rating: 'TV-PG', st: 'Ended', prem: 2009, g: ['Animation', 'Sci-Fi', 'Comedy'], n: 'Tessera'});
specs.push({id: FIX.thinShow, t: 'Quiet Bureau', seasons: [1], perSeason: 25, perEp: [1, 2], thin: true});
specs.push({id: FIX.dailyShow, t: 'Second Signal', daily: 14, lang: 'English', rating: 'TV-G', st: 'Running', prem: 1984, g: ['Reality'], n: 'Public Six'});
let sid = 100;
for (let i = 0; i < 85; i++) {
  const seasons = Array.from({length: weighted([[1, 50], [2, 20], [3, 12], [4, 8], [6, 5], [8, 3]])}, (_, k) => k + 1);
  specs.push({id: sid++, t: title(), seasons, perSeason: int(3, 6), perEp: [1, weighted([[2, 5], [4, 3], [7, 1]])], lang: weighted(LANGS), rating: chance(.57) ? pick(RATINGS) : '', st: chance(.6) ? 'Running' : 'Ended', prem: weighted([[int(2020, 2026), 50], [int(2010, 2019), 28], [int(2000, 2009), 14], [int(1985, 1999), 8]]), g: sample(GENRES, int(1, 3)), n: i < 11 ? 'JBN' : i < 16 ? 'jBN' : chance(.95) ? pick(NETS.slice(0, -1)) : '', noPoster: i % 29 === 7, noMeta: i % 23 === 11});
}

const RESW = [['2160p', 14], ['1080p', 62], ['720p', 13], ['480p', 5], ['', 6]];
const SRCW = [['WEB-DL', 60], ['WEBRip', 12], ['BluRay', 12], ['HDTV', 8], ['DVDRip', 4], ['', 4]];
const ACODEC = [['DDP5.1', 'E-AC-3', 6], ['DDP5.1.Atmos', 'E-AC-3 JOC', 6], ['AAC2.0', 'AAC LC', 2], ['DD5.1', 'AC-3', 6], ['DTS-HD.MA.7.1', 'DTS XLL', 8]];
const SUBL = ['en', 'de', 'es', 'fr', 'it', 'pt-BR', 'ko', 'ja', 'nl', 'sv', 'pl', 'tr', 'ar', 'cs', 'da', 'fi', 'el', 'he', 'hu', 'id', 'ms', 'no', 'ro', 'ru', 'th', 'uk', 'vi', 'zh'];

function addRelease(spec, epRef, s, e, dateStr, t) {
  const id = nextRel++, res = weighted(RESW), srcTok = weighted(SRCW), hevc = res === '2160p' || chance(.3), ac = pick(ACODEC), grp = pick(RLS);
  const base = spec.t.replace(/[^A-Za-z0-9 ]/g, '').replace(/ +/g, '.');
  const tag = dateStr ? dateStr.replace(/-/g, '.') : e == null ? `S${String(s).padStart(2, '0')}` : `S${String(s).padStart(2, '0')}E${String(e).padStart(2, '0')}`;
  const hdrTok = res === '2160p' && chance(.5) ? pick(['HDR', 'DV', 'HDR10Plus']) : '';
  const styled = chance(.85);
  const name = styled ? [base, tag, res, srcTok, ac[0], hdrTok, hevc ? 'H.265' : 'H.264'].filter(Boolean).join('.') + '-' + grp : `${spec.t} ${tag}`;
  const mins = e == null && !dateStr ? 45 * 10 : int(21, 58);
  const mbPerMin = {'2160p': 95, '1080p': 38, '720p': 17, '480p': 7, '': 25}[res] * (hevc ? .6 : 1);
  const size = Math.round(mins * mbPerMin * 1048576 * (0.85 + rnd() * 0.3));
  const comp = chance(.84) ? 100 : chance(.5) ? int(95, 99) + rnd() : chance(.6) ? int(75, 94) + rnd() : int(18, 74) + rnd();
  rel.push([id, spec.id, epRef, name, size, t, +comp.toFixed(1), chance(.7) ? 0 : int(1, 40), 0, 0]);
  const hasNfo = chance(.03), hasPv = chance(.004), nFiles = e == null && !dateStr ? int(8, 14) : weighted([[1, 60], [2, 20], [int(3, 12), 20]]);
  const cat = res === '2160p' ? 'TV > UHD' : spec.lang && spec.lang !== 'English' ? 'TV > Foreign' : res === '480p' ? 'TV > SD' : 'TV > HD';
  const g = guid();
  rx.x[id] = [g, pick(GROUPS), pick(POSTERS), t + int(600, 9 * 3600), hasNfo ? 1 : 0, 0, hasPv ? 1 : 0, chance(.03) ? 1 : chance(.2) ? -1 : 0, 0, nFiles, cat];
  if (comp < 100 && chance(.2)) repair.push(id);
  if (chance(.85)) {
    const w = {'2160p': 3840, '1080p': 1920, '720p': 1280, '480p': 720, '': 1920}[res], h = {'2160p': 2160, '1080p': 1080, '720p': 720, '480p': 480, '': 1080}[res];
    const nA = weighted([[1, 75], [2, 20], [3, 3], [5, 2]]), langs = sample(SUBL, nA), chs = ac[2];
    const hdrStr = hdrTok === 'DV' ? 'Dolby Vision, Version 1.0, Profile 5, dvhe.05.06, BL+RPU' : hdrTok === 'HDR10Plus' ? 'SMPTE ST 2094 App 4, Version 1, HDR10+ Profile B compatible' : hdrTok === 'HDR' ? 'SMPTE ST 2086, HDR10 compatible' : null;
    const v = {fmt: hevc ? 'HEVC' : 'AVC', codec: hevc ? 'V_MPEGH/ISO/HEVC' : 'V_MPEG4/ISO/AVC', w, h, ar: '16:9', fr: pick([23.976, 25, 29.97]), prof: hevc ? 'Main 10@L5@Main' : 'High@L4', bd: hevc ? 10 : 8};
    if (hdrStr) v.hdr = hdrStr;
    const a = langs.map((l, i) => ({lang: l, fmt: i === 0 ? ac[1] : 'E-AC-3', codec: 'A_EAC3', ch: i === 0 ? chs : 6, cl: chs === 2 && i === 0 ? '2/0/0' : '3/2/0.1', sr: 48000, br: pick([192000, 384000, 640000, 768000])}));
    const nS = weighted([[0, 40], [1, 25], [int(2, 5), 28], [int(7, 12), 5], [int(30, 50), 2]]);
    const bare = nS > 20, sl = nS > SUBL.length ? [...SUBL, ...sample(SUBL, nS - SUBL.length)] : sample(SUBL, nS);
    const sArr = sl.map((l, i) => bare ? {lang: l} : {lang: l, fmt: chance(.9) ? 'UTF-8' : 'PGS', codec: 'S_TEXT/UTF8', f: i === 0 && chance(.3) ? 1 : 0, d: i === 0 ? 1 : 0, ...(chance(.25) ? {title: pick(['SDH', 'Forced', 'European', 'Latin American'])} : {})});
    media[id] = {cf: 'Matroska', dur: mins * 60000 + int(0, 59000), v: [v], a, s: sArr};
    rx.summ[id] = `${h}p · ${v.codec === 'V_MPEGH/ISO/HEVC' ? 'HEVC' : 'AVC'} · ${ac[1] === 'AAC LC' ? 'AAC LC 2' : ac[1].replace(' JOC', '') + ' ' + chs}`;
  }
  const fbase = name.replace(/ /g, '.');
  if (chance(.12)) files[id] = nFiles === 1 ? [[fbase + '.mkv', size]] : [...Array.from({length: nFiles - 1}, (_, i) => [`${fbase}/Subs/${fbase}.${SUBL[i % SUBL.length]}.srt`, int(18000, 90000)]), [`${fbase}/${fbase}.mkv`, size]];
  if (hasNfo) nfo[id] = `General\r\nComplete name                            : ${fbase}.mkv\r\nFormat                                   : Matroska\r\nFile size                                : ${(size / 1073741824).toFixed(2)} GiB\r\nDuration                                 : ${mins} min\r\n\r\nNotes\r\nThis is invented sample text for the prototype. It stands in for a release NFO so the\r\ndialog and the NFO tab can be seen with a realistic amount of monospaced content.\r\n\r\nGreetings to nobody in particular.\r\n`;
  if (hasPv) previews.push(`preview/${g}_thumb.webp`);
  return id;
}

for (const spec of specs) {
  const thin = spec.thin || spec.noMeta;
  shows[spec.id] = {t: spec.t, y: spec.thin ? '2014' : String(spec.prem || ''), n: spec.thin ? '' : spec.n || '', s: SUMMARY[spec.id % SUMMARY.length].replace('%s', THING[spec.id % THING.length]), p: spec.thin || spec.noPoster ? 0 : 1};
  if (!thin) meta[spec.id] = {g: spec.g, type: 'Scripted', st: spec.st, prem: String(spec.prem), end: '', lang: spec.lang, rate: null, cast: sample(PEOPLE, int(4, 12)), us: spec.rating};
  const showAge = int(0, 5) * DAY;
  if (spec.daily) { for (let d = 0; d < spec.daily; d++) { const dt = new Date((NOW - showAge - d * DAY) * 1000).toISOString().slice(0, 10); for (let k = 0; k < int(1, 2); k++) addRelease(spec, -1, null, null, dt, NOW - showAge - d * DAY - int(0, 7200)); } continue; }
  for (const s of spec.seasons) {
    const n = s === 0 ? 3 : spec.perSeason, last = s === spec.seasons[spec.seasons.length - 1];
    for (let e = 1; e <= n; e++) {
      const epId = nextEp++, aired = new Date((NOW - showAge - ((spec.seasons.length - spec.seasons.indexOf(s)) * 200 + (n - e) * 7) * DAY) * 1000).toISOString().slice(0, 10);
      eps[epId] = [spec.id, s, e, spec.thin ? `Episode ${e}` : epTitle(), aired];
      const k = int(spec.perEp[0], spec.perEp[1]);
      // newest season arrives recently and in floods (same-day batches); older seasons were posted long ago
      const t0 = last ? NOW - showAge - int(0, 3) * DAY - int(0, 80000) : NOW - int(20, 50) * DAY - int(0, 80000);
      const firstId = addRelease(spec, epId, s, e, null, t0);
      for (let j = 1; j < k; j++) addRelease(spec, epId, s, e, null, t0 - int(60, 6 * 3600));
      if (spec.thin && e % 4 === 0) { const twin = [...rel[rel.length - 1]]; twin[0] = nextRel++; twin[4] = rel.find(r => r[0] === firstId)[4]; rel.find(r => r[0] === firstId)[4] = twin[4]; rel.push(twin); rx.x[twin[0]] = [...rx.x[firstId]]; rx.x[twin[0]][0] = guid(); }
    }
    if (s > 0 && chance(.35)) addRelease(spec, -1, s, null, null, NOW - int(10, 40) * DAY);
  }
}
// two fixture releases: one with a long multi-file list, one with a full-size preview
const big = rel.find(r => r[1] === FIX.longShow && /S07E0?5\./.test(r[3]));
FIX.filesRelease = big[0];
{ const b = big[3]; files[big[0]] = [...Array.from({length: 8}, (_, i) => [`${b.replace(/S07E\d+/, 'S07')}/Subtitles/${b.replace(/S07E\d+/, 'S07E0' + (i + 1))}.nl.srt`, int(25000, 60000)]), [`${b.replace(/S07E\d+/, 'S07')}/${b.replace(/S07E\d+/, 'S07E01')}.mkv`, 4283574282], [`${b.replace(/S07E\d+/, 'S07')}/${b}.mkv`, 3538732739]]; rx.x[big[0]][9] = 11; }
const pv = rel.find(r => r[1] === FIX.longShow && /S07E0?6\./.test(r[3]));
FIX.fullPreviewRelease = pv[0]; rx.x[pv[0]][6] = 1; previews.push(`preview/${rx.x[pv[0]][0]}.webp`);
// the newest release on the list must exercise the details checks: NFO text, media info, siblings
const newest = [...rel].sort((a, b) => b[5] - a[5])[0];
rx.x[newest[0]][4] = 1; nfo[newest[0]] = nfo[newest[0]] || `General\r\nComplete name : ${newest[3]}.mkv\r\n\r\nInvented sample NFO text for the prototype, long enough to fill the dialog and the NFO tab with a\r\nrealistic amount of monospaced content so its layout can be judged.\r\n`;
// a flood: one show posts 16 releases within minutes, newest on the list (exercises the same-show batch expander)
{ const spec = specs.find(s => s.id === 100), epId = nextEp++; eps[epId] = [spec.id, spec.seasons.at(-1), 99, 'Flood Night', '2026-09-19'];
  for (let i = 0; i < 16; i++) addRelease(spec, epId, spec.seasons.at(-1), 99, null, NOW + 3600 - i * 45); }
// Posted and Added orders must differ: the second-newest posted release was indexed last
{ const byT = [...rel].sort((a, b) => b[5] - a[5]); rx.x[byT[0][0]][3] = byT[0][5] + 600; rx.x[byT[1][0]][3] = byT[0][5] + 12 * 3600; }
// the first episode of the long show has incomplete releases (completion chip in an episode table)
for (const r of rel) if (r[1] === FIX.longShow && /S01E01\./.test(r[3])) r[6] = 93.4;
Object.assign(FIX, {searchQuery: 'glass mer', searchTitle: 'Glass Meridian'});

const w = (f, d) => writeFileSync(`${out}/${f}`, JSON.stringify(d));
w('data.json', {shows, eps, rel}); w('relx.json', rx); w('meta.json', meta); w('media.json', media); w('files.json', files); w('nfo.json', nfo); w('previews.json', previews); w('repair.json', repair);
writeFileSync(`${out}/fixtures.json`, JSON.stringify(FIX, null, 1));
writeFileSync(`${out}/art.json`, JSON.stringify({posters: Object.entries(shows).filter(([, s]) => s.p).map(([id, s]) => ({id, t: s.t, hue: (id * 47) % 360})), previews: previews.map(p => ({path: p, full: !/_thumb/.test(p)}))}));
console.log(`shows ${Object.keys(shows).length}, episodes ${Object.keys(eps).length}, releases ${rel.length}, media ${Object.keys(media).length}, previews ${previews.length}`);
