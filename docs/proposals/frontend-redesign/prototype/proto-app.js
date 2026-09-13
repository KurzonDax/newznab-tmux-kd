/* NNTmux interactive prototype. Client-side only; every control on the page changes state and re-renders.
   Decisions applied: 1B top bar only · 2A one browser (Table / Cards / Covers) · 3A scoped search · 4A details header-first with tabs · 6A account/basket. */
(function(){
  'use strict';
  const esc = s => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  const h = s => { let x=7; for (const c of String(s)) x=(x*31+c.charCodeAt(0))>>>0; return x; };
  const pick = (arr, s) => arr[h(s)%arr.length];
  const slug = t => t.replace(/[^A-Za-z0-9]+/g,'.').replace(/^\.|\.$/g,'');
  const MEDIA = ['2160p · HEVC · TrueHD 7.1','1080p · x264 · DTS-HD 5.1','1080p · H.264 · EAC3 5.1','2160p · HEVC · DV · Atmos','720p · x264 · AC3 5.1','1080p · x265 · AAC 2.0'];
  const AGES = [['2 h ago',2],['5 h ago',5],['9 h ago',9],['1 d ago',24],['2 d ago',48],['3 d ago',72],['5 d ago',120],['1 w ago',168],['2 w ago',336],['3 w ago',504],['1 mo ago',720],['2 mo ago',1440]];
  const GENRES = ['Sci-Fi','Drama','Thriller','Horror','Action','Comedy','Crime','Animation'];
  const CATS = {movies:['UHD','HD','SD','Foreign','Other'], tv:['UHD','HD','SD','Anime','Foreign']};
  const CARD_ROOTS = ['movies','tv','music','adult','console','books'];
  const POSTERS = ['yEncBin@poster.com','nzbking@nzb.su','obfuscated@post.er','releaser@usenet.farm','uploader@ngPost.io','auto@bin.post','flac@lossless.net','vidposter@teevee.org'];
  const NOW = new Date('2026-09-13T14:30:00');
  const fmtDT = d => { const M=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']; const p=n=>String(n).padStart(2,'0'); return `${M[d.getMonth()]} ${p(d.getDate())}, ${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}`; };

  // ---------------- Data ----------------
  const ENT = {movies:[], tv:[], music:[], adult:[], console:[], books:[]};
  const LABEL = {movies:'Movies', tv:'TV', music:'Audio', adult:'Adult', console:'Console', books:'Books', all:'All releases'};
  const SHAPE = {movies:'tall', tv:'tall', music:'square', adult:'wide', console:'tall', books:'tall'};
  const ACTORS = ['Timothée Chalamet','Zendaya','Rebecca Ferguson','Cailee Spaeny','Ryan Gosling','Amy Adams','Emily Blunt','Hugh Jackman','Jake Gyllenhaal','Cillian Murphy','Emma Stone','Florence Pugh','Adam Driver','Saoirse Ronan','Daniel Kaluuya','Paul Mescal','Margot Robbie','Mahershala Ali'];
  const plotFor = (e) => e.root==='movies' ? `${esc(e.title)} is a ${e.genre.toLowerCase()} feature directed by ${esc(e.director)}. When an ordinary life is upended by an event no one saw coming, the people at its centre are forced to choose between what they were told and what they can see for themselves. Sample synopsis for the prototype.` : e.root==='tv' ? `${esc(e.title)} follows an ensemble whose lives keep colliding in ways none of them planned. A ${e.genre.toLowerCase()} series from ${esc(e.network)}. Sample synopsis for the prototype.` : e.root==='books' ? `${esc(e.title)} by ${esc(e.author)}. A novel that opens quietly and closes somewhere its reader did not expect. Sample overview for the prototype.` : '';
  MOVIES.filter((s,i,a)=>a.indexOf(s)===i).forEach((s,i)=>{ const [title,year,rating]=s.split('|'); ENT.movies.push({root:'movies', id:i, title, year:+year, rating:+rating, genre:pick(GENRES,title), director:pick(['Denis Villeneuve','Christopher Nolan','Bong Joon-ho','Robert Eggers','Yorgos Lanthimos','Ari Aster','David Fincher'],title), actors:[pick(ACTORS,title+'1'),pick(ACTORS,title+'2'),pick(ACTORS,title+'3')].filter((x,i,a)=>a.indexOf(x)===i).join(', '), runtime:88+(h(title)%80), imdb:'tt'+String(1000000+h(title)%8999999), art:title}); });
  SERIES.filter((s,i,a)=>a.indexOf(s)===i).forEach((s,i)=>{ const [title,network]=s.split('|'); ENT.tv.push({root:'tv', id:i, title, network, year:2015+(h(title)%10), rating:+(7+(h(title)%20)/10).toFixed(1), genre:pick(['Drama','Comedy','Thriller','Sci-Fi','Crime'],title), status:h(title)%3?'Returning':'Ended', seasons:1+(h(title+'s')%4), art:title}); });
  ALBUMS.filter((s,i,a)=>a.indexOf(s)===i).forEach((s,i)=>{ const [title,artist,year]=s.split('|'); ENT.music.push({root:'music', id:i, title, artist, year:+year, tracks:Array.from({length:8+(h(title)%6)},(_,k)=>`${k+1}. ${pick(['Opening','Glass','Static','Harbour','North','Signal','Afterglow','Undertow','Sleeper','Paper Moon','Low Tide','Salt','Blue Hour','Ember'],title+k)} ${pick(['3:12','4:05','2:48','5:31','3:59','6:14'],title+'t'+k)}`), genre:pick(['Alternative','Electronic','Hip-Hop','Indie','Jazz','Pop','Rock'],title), label:pick(['XL','Warp','Def Jam','Columbia','4AD','Domino','Matador'],title), art:title}); });
  CONSOLE.filter((s,i,a)=>a.indexOf(s)===i).forEach((s,i)=>{ const [title,platform,esrb]=s.split('|'); ENT.console.push({root:'console', id:i, title, platform, esrb, year:2015+(h(title)%11), publisher:pick(['Sony','Nintendo','Xbox Game Studios','Capcom','Sega','Bandai Namco','Ubisoft'],title), genre:pick(['Action','Racing','RPG','Adventure','Platformer','Shooter','Sports'],title), art:title}); });
  BOOKS.filter((s,i,a)=>a.indexOf(s)===i).forEach((s,i)=>{ const [title,author,year]=s.split('|'); ENT.books.push({root:'books', id:i, title, author, year:+year, publisher:pick(['Scribner','Tor','Orbit','Penguin','Vintage','Ballantine','Knopf'],title), pages:180+(h(title)%520), isbn:'978'+String(1000000000+h(title)%8999999999), genre:pick(['Science fiction','Fantasy','Literary fiction','Thriller','Horror','Non-fiction'],title), art:title}); });
  for (let i=0;i<96;i++){ const vol=100+(i*7)%900; const title=`${pick(['Private Sessions','After Hours','Late Night Collection','Studio Reel','Backstage','Velvet Series','Night Shift','Blue Hour'],'a'+i)} Vol. ${vol}`; ENT.adult.push({root:'adult', id:i, title, year:2019+(i%7), art:title, kind: i%9===0?'none':(i%3===0?'sample':'image')}); }
  const REL = [];
  function finishRel(e, name, q, res, k, o={}){
    const isMusic = e.root==='music', isAdult = e.root==='adult', isConsole = e.root==='console', isBook = e.root==='books'; const fmt = o.fmt||'FLAC';
    const age = AGES[(h(name)+k)%AGES.length]; const comp = (h(e.title+'c'+k)%9===0)?61+(h(e.title)%35):100;
    const cat = isMusic ? (k===2?'MP3':'Lossless') : isAdult ? (name.includes('1080p')?'HD':'SD') : isConsole ? e.platform : isBook ? (k===1?'Audiobook':'Ebook') : (res==='2160p'?'UHD':res==='720p'?'SD':'HD');
    const media = isMusic ? (k===2?'MP3 · 320 kbps':k===1?'FLAC · 96 kHz · 24-bit':'FLAC · 44.1 kHz · 16-bit') : isAdult ? (name.includes('1080p')?'1080p · H.264 · AAC 2.0':'720p · H.264 · AAC 2.0') : isConsole ? null : isBook ? (k===1?'MP3 · 64 kbps':null) : q;
    const size = isMusic ? `${(0.3+(h(name)%14)/10).toFixed(1)} GB` : isBook ? (k===1?`${200+(h(name)%600)} MB`:`${(1+(h(name)%40)/10).toFixed(1)} MB`) : isConsole ? `${(4+(h(name)%90)).toFixed(1)} GB` : `${(4+(h(name)%56)+(h(e.title)%10)/10).toFixed(1)} GB`;
    const preview = isMusic ? 'audio' : isAdult ? (e.kind==='none'?null:e.kind) : (isConsole||isBook) ? null : ((k===0||k===1)&&!o.pack?(e.root==='movies'?'image':'video'):null);
    const ageH = age[1]+(h(name)%3); const addedAt = new Date(NOW - ageH*3600e3); const postedAt = new Date(addedAt - (1+(h(name)%36))*3600e3);
    const group = e.root==='movies'?pick(['alt.binaries.movies','alt.binaries.moovee','alt.binaries.hdtv.x264'],name):e.root==='tv'?pick(['alt.binaries.teevee','alt.binaries.tv','alt.binaries.hdtv'],name):isMusic?pick(['alt.binaries.sounds.lossless','alt.binaries.sounds.mp3','alt.binaries.mp3'],name):isConsole?pick(['alt.binaries.console.ps5','alt.binaries.console.switch','alt.binaries.games.xbox'],name):isBook?pick(['alt.binaries.e-book','alt.binaries.ebook','alt.binaries.e-book.technical'],name):pick(['alt.binaries.erotica','alt.binaries.multimedia.erotica'],name);
    const r = {guid:`${e.root}-${e.id}-${e.releases.length}`, ent:e, root:e.root, name, res: isMusic?fmt:res, cat, catLabel:`${LABEL[e.root]} > ${cat}`, size, files:isBook?2+(h(name)%40):12+(h(name)%150), added:age[0], ageH, addedAt, postedAt, posted:fmtDT(postedAt), renamed:h(name+'r')%8!==0, ppDone:h(name+'p')%11!==0, grabs:20+(h(name)%4000), comments:h(name)%9===0?1+(h(name)%12):0, complete:comp, media, nfo:h(name)%4!==0, preview, pw:h(name)%23===0?'passworded':'none', group, poster:pick(POSTERS,name+'p'), season:o.season, episode:o.episode, pack:!!o.pack};
    e.releases.push(r); REL.push(r); return r;
  }
  function gen(e){
    e.releases=[];
    if (e.root==='tv'){
      for (let sn=1; sn<=e.seasons; sn++){
        const eps = 2 + (h(e.title+'e'+sn)%3);
        for (let ep=1; ep<=eps; ep++){ const q = pick(MEDIA, e.title+sn+ep); const res=q.split(' · ')[0]; const nm=`${slug(e.title)}.S${String(sn).padStart(2,'0')}E${String(ep).padStart(2,'0')}.${res}.WEB.${q.includes('HEVC')?'H265':'H264'}-${pick(['ABC','DEF','GHI','NTb','FLUX'],e.title+sn+ep)}`; finishRel(e,nm,q,res,ep,{season:sn,episode:ep}); }
        if (h(e.title+'pack'+sn)%2===0){ const q = pick(MEDIA, e.title+'pack'+sn); const res=q.split(' · ')[0]; const nm=`${slug(e.title)}.S${String(sn).padStart(2,'0')}.COMPLETE.${res}.WEB.${q.includes('HEVC')?'H265':'H264'}-${pick(['ABC','DEF','NTb'],e.title+'pk'+sn)}`; finishRel(e,nm,q,res,0,{season:sn,episode:0,pack:true}); }
      }
      e.latest = e.releases.reduce((a,b)=>a.ageH<b.ageH?a:b); return;
    }
    const n = e.root==='adult' ? 1 : 1 + (h(e.title+'n')%3);
    for (let k=0;k<n;k++){
      const q = pick(MEDIA, e.title+k); const res=q.split(' · ')[0]; const src = res==='2160p'?(k===0?'UHD.BluRay':'WEB'):res==='1080p'?(k===0?'BluRay':'WEB'):'WEB';
      const fmt = ['FLAC','24bit-FLAC','320kbps-MP3'][k];
      const name = e.root==='movies' ? `${slug(e.title)}.${e.year}.${res}.${src}.${q.includes('HEVC')?'x265':'x264'}-${['XYZ','ABC','DEF'][k]}`
        : e.root==='music' ? `${slug(e.artist)}-${slug(e.title)}-${fmt}-${e.year}-${['XYZ','ABC','DEF'][k]}`
        : e.root==='console' ? `${slug(e.title)}${k?'.Update.v1.'+k:''}.${e.platform.replace(/\s/g,'')}-${['DUPLEX','VENOM','COMPLEX'][k]}`
        : e.root==='books' ? `${slug(e.author)}-${slug(e.title)}.${k===0?'Retail.EPUB':k===1?'Unabridged.Audiobook.MP3':'PDF'}-${['BOOK','AUDIO','BOOK'][k]}`
        : `${slug(e.title)}.XXX.${h(e.title)%2?'1080p':'720p'}.WEB.MP4-${['KTR','XXX','GRP'][h(e.title)%3]}`;
      finishRel(e, name, q, res, k, {fmt});
    }
    e.latest = e.releases.reduce((a,b)=>a.ageH<b.ageH?a:b);
  }
  ENT.movies.forEach(gen); ENT.tv.forEach(gen); ENT.music.forEach(gen); ENT.adult.forEach(gen); ENT.console.forEach(gen); ENT.books.forEach(gen);
  [...ENT.movies, ...ENT.tv, ...ENT.music, ...ENT.console, ...ENT.books].forEach(e=>{ e.noArt = h(e.title+'art')%7===0; });
  ENT.adult.forEach(e=>{ e.noArt = e.kind==='none'; });
  REL.sort((a,b)=>a.ageH-b.ageH);

  // ---------------- State ----------------
  const S = {
    route:{page:'browse', root:'movies'}, view:{movies:'covers', tv:'covers', music:'covers', adult:'covers', console:'covers', books:'covers', all:'table'}, size:'compact', perPage:48, page:1,
    sort:'newest', year:'', genre:'', network:'', label:'', platform:'', publisher:'', author:'', letter:'', thumbs:false, open:-1, q:'', qual:new Set(),
    watch:{}, basket:new Set(), sel:new Set(), tab:'overview', season:null, wlTab:'movies', wlq:'', menu:null, picker:null, modal:null, toasts:[],
    theme:null, scheme:'blue'
  };
  const key = e => `${e.root}:${e.id}`;
  const isWatched = e => !!S.watch[key(e)];
  const canWatch = e => e.root==='movies' || e.root==='tv';

  // ---------------- Router ----------------
  function parse(){
    const hash = location.hash.replace(/^#\/?/,'') || 'browse/movies';
    const [path, qs] = hash.split('?'); const parts = path.split('/'); const params = Object.fromEntries(new URLSearchParams(qs||''));
    if (parts[0]==='browse') return {page:'browse', root:parts[1]||'movies', params};
    if (parts[0]==='title') return {page:'title', root:parts[1], id:+parts[2], params};
    if (parts[0]==='details') return {page:'details', guid:parts[1], params};
    if (parts[0]==='watchlist') return {page:'watchlist', params};
    if (parts[0]==='basket') return {page:'basket', params};
    if (parts[0]==='search') return {page:'search', params};
    if (parts[0]==='account') return {page:'account', params};
    return {page:'browse', root:'movies', params};
  }
  const go = hash => { location.hash = hash; };
  addEventListener('hashchange', ()=>{ const r = parse(); if (r.page!==S.route.page || r.root!==S.route.root || r.id!==S.route.id || r.guid!==S.route.guid || JSON.stringify(r.params)!==JSON.stringify(S.route.params)) { S.page=1; S.open=-1; S.sel.clear(); S.tab='overview'; S.season=null; S.qual.clear(); } S.route=r; S.menu=null; S.q = r.params.q||''; render(); });

  // ---------------- Pieces ----------------
  const facts = (r,o={}) => { const out=[]; out.push(r.complete===100?`<span class="chip ok" title="All parts present"><i class="fas fa-check"></i> 100%</span>`:`<span class="chip ${r.complete>=95?'warn':'bad'}">${r.complete}%</span>`); if (r.pw==='passworded') out.push(`<span class="chip bad"><i class="fas fa-lock"></i> Password</span>`); if (r.media) out.push(`<span class="chip p action" data-act="mediainfo" data-guid="${r.guid}" title="Media info"><i class="fas fa-circle-info"></i> ${esc(r.media)}</span>`); if (r.nfo) out.push(`<span class="chip nfo action" data-act="nfo" data-guid="${r.guid}" title="View NFO"><i class="fas fa-file-lines"></i> NFO</span>`); if (r.preview) out.push(r.preview==='sample' ? `<span class="chip ok action" data-act="preview" data-guid="${r.guid}" title="View sample image"><i class="fas fa-images"></i> Sample</span>` : `<span class="chip info action" data-act="preview" data-guid="${r.guid}" title="${r.preview==='video'?'Watch video clip':r.preview==='audio'?'Listen to audio preview':'View preview image'}">${r.preview==='video'?'<i class="fas fa-video"></i> Clip':r.preview==='audio'?'<i class="fas fa-headphones"></i> Listen':'<i class="fas fa-image"></i> Preview'}</span>`); if (o.group) out.push(`<span class="chip">${esc(r.group)}</span>`); return out.join(''); };
  const entityChip = r => r.root==='adult' ? '' : `<a class="chip entity" href="#/title/${r.root}/${r.ent.id}" title="Open ${esc(r.ent.title)}"><i class="fas ${rootIcon(r.ent)}"></i> ${esc(r.ent.title)}${r.ent.year&&r.root!=='tv'?' · '+r.ent.year:''}</a>`;
  const origin = r => `<a class="chip origin" href="#/browse/all?group=${encodeURIComponent(r.group)}" title="All releases in ${esc(r.group)}"><i class="fas fa-users"></i> ${esc(r.group)}</a><a class="chip origin" href="#/browse/all?poster=${encodeURIComponent(r.poster)}" title="All posts by ${esc(r.poster)}"><i class="fas fa-user"></i> ${esc(r.poster)}</a>`;
  const stats = r => `<span class="num" title="Grabs"><i class="fas fa-download" style="color:var(--ok)"></i> ${r.grabs.toLocaleString()}</span><span class="num" title="Comments"><i class="fas fa-comment" style="color:var(--p500)"></i> ${r.comments}</span>`;
  const actions = r => `<span class="actions"><button class="ra dl" data-act="download" data-guid="${r.guid}" title="Download NZB"><i class="fas fa-download"></i></button><a class="ra pr" href="#/details/${r.guid}" title="Details"><i class="fas fa-info"></i></a><button class="ra mu" data-act="basket" data-guid="${r.guid}" title="${S.basket.has(r.guid)?'In basket · click to remove':'Add to basket'}" ${S.basket.has(r.guid)?'style="background:var(--ok);color:#fff"':''}><i class="fas fa-shopping-basket"></i></button><button class="ra mu" data-act="report" data-guid="${r.guid}" title="Report release"><i class="fas fa-flag"></i></button>${canWatch(r.ent)?`<button class="ra mu watch ${isWatched(r.ent)?'on':''}" data-act="watch" data-key="${key(r.ent)}" title="${isWatched(r.ent)?'On your watchlist · click to edit':'Add '+esc(r.ent.title)+' to '+(r.root==='movies'?'My Movies':'My Shows')}"><i class="${isWatched(r.ent)?'fas':'far'} fa-heart"></i></button>`:''}</span>`;
  const rootIcon = e => e.root==='movies'?'fa-film':e.root==='tv'?'fa-tv':e.root==='music'?'fa-music':e.root==='adult'?'fa-venus-mars':e.root==='books'?'fa-book-open':'fa-gamepad';
  const shapeOf = e => SHAPE[e.root]||'tall';
  const artLabel = e => e.root==='adult' ? (e.kind==='sample'?'sample':'preview') : '';
  const art = (e, big=false) => { const sh=shapeOf(e); if (e.noArt) return `<div class="cover-art ${sh} no-art" title="${e.root==='adult'?'No preview or sample':'No artwork'}"><i class="fas ${rootIcon(e)}"></i><span class="cover-art-title" style="font-size:${big?15:11}px">${esc(e.title)}</span></div>`; const [a,b]=pick(HUES, e.art); return `<div class="cover-art ${sh}" style="--a:${a};--b:${b}">${artLabel(e)?`<span class="art-tag">${artLabel(e)}</span>`:''}<span class="cover-art-title" style="font-size:${big?15:11}px">${esc(e.title)}</span></div>`; };
  const posterSm = e => { const sh=shapeOf(e); const dims = sh==='square'?'width:40px;height:40px':sh==='wide'?'width:56px;height:32px':'width:40px;height:60px'; if (e.noArt) return `<div class="poster no-art" style="${dims};flex:none;aspect-ratio:auto" title="No artwork"><i class="fas ${rootIcon(e)}"></i></div>`; const [a,b]=pick(HUES,e.art); return `<div class="poster" style="background:linear-gradient(160deg,${a},${b});${dims};flex:none;aspect-ratio:auto"></div>`; };
  const pageBar = (total, per, page, extra='') => { const pages=Math.max(1,Math.ceil(total/per)); S.pages=pages; const nums=[]; for (let p=Math.max(1,page-2); p<=Math.min(pages,page+2); p++) nums.push(p);
    return `<div class="pager"><span class="small muted num">Page <b>${page}</b> of ${pages.toLocaleString()} · ${total.toLocaleString()} ${extra}</span><span class="seg" title="Per page"><span class="small muted" style="padding:0 8px;display:inline-flex;align-items:center">Per page</span>${[24,48,100].map(n=>`<button data-act="perpage" data-n="${n}" aria-pressed="${per===n}">${n}</button>`).join('')}</span><span class="grow"></span><span class="pagination" style="padding:0"><span data-act="page" data-n="${Math.max(1,page-1)}" style="cursor:pointer" ${page<=1?'aria-disabled="true"':''}>‹</span>${page>3?`<span data-act="page" data-n="1" style="cursor:pointer">1</span><span>…</span>`:''}${nums.map(p=>`<span data-act="page" data-n="${p}" class="${p===page?'cur':''}" style="cursor:pointer">${p}</span>`).join('')}${page<pages-2?`<span>…</span><span data-act="page" data-n="${pages}" style="cursor:pointer">${pages}</span>`:''}<span data-act="page" data-n="${Math.min(pages,page+1)}" style="cursor:pointer" ${page>=pages?'aria-disabled="true"':''}>›</span></span><label class="field sm" style="width:110px"><input data-act="goto" placeholder="Go to page" style="width:100%"></label></div>`; };

  function table(rows, {thumbs=false, select=true}={}){
    return `<div style="overflow-x:auto"><table class="rel"><thead><tr>${select?`<th style="width:28px"><input type="checkbox" data-act="selall" ${rows.length&&rows.every(r=>S.sel.has(r.guid))?'checked':''}></th>`:''}<th>Release</th><th>Category</th><th class="r">Size</th><th class="r">Files</th><th>Added</th><th>Posted</th><th>Stats</th><th></th></tr></thead><tbody>
      ${rows.length?rows.map(r=>`<tr>${select?`<td><input type="checkbox" data-act="sel" data-guid="${r.guid}" ${S.sel.has(r.guid)?'checked':''}></td>`:''}<td class="name"><div style="display:flex;gap:10px">${thumbs?posterSm(r.ent):''}<div><div class="titleline"><a href="#/details/${r.guid}" class="title">${esc(r.name)}</a>${facts(r)}</div><div class="subline origin-line">${entityChip(r)}${origin(r)}</div></div></div></td><td><span class="pill">${r.catLabel}</span></td><td class="r num">${r.size}</td><td class="r num"><a href="#" data-act="files" data-guid="${r.guid}">${r.files}</a></td><td class="num">${r.added}</td><td class="num posted">${r.posted}</td><td><div style="display:flex;gap:8px">${stats(r)}</div></td><td>${actions(r)}</td></tr>`).join(''):`<tr><td colspan="9" class="empty">No releases match.</td></tr>`}</tbody></table></div>`;
  }
  function cards(rows){
    return `<div class="rlist">${rows.map(r=>`<div class="rrow ${S.sel.has(r.guid)?'is-sel':''}">
      <input type="checkbox" class="rrow-sel" data-act="sel" data-guid="${r.guid}" ${S.sel.has(r.guid)?'checked':''} title="Select">
      <div class="rrow-art">${r.ent.noArt?`<div class="poster fixed ${shapeOf(r.ent)} no-art" title="No artwork"><i class="fas ${rootIcon(r.ent)}"></i></div>`:`<div class="poster fixed ${shapeOf(r.ent)}" style="background:linear-gradient(160deg,${pick(HUES,r.ent.art).join(',')})"></div>`}</div>
      <div class="rrow-main">
        <a href="#/details/${r.guid}" class="rrow-name">${esc(r.name)}</a>
        <div class="rrow-chips">${facts(r)}</div>
        <div class="rrow-chips origin-line">${entityChip(r)}${origin(r)}</div>
      </div>
      <div class="rrow-col num"><span>Size</span><b>${r.size}</b></div>
      <div class="rrow-col num"><span>Added</span><b>${r.added}</b></div>
      <div class="rrow-col num"><span>Posted</span><b>${r.posted}</b></div>
      <div class="rrow-col num"><span>Grabs</span><b>${r.grabs.toLocaleString()}</b></div>
      <div class="rrow-actions">${actions(r)}</div>
    </div>`).join('')||'<div class="empty">No releases match.</div>'}</div>`;
  }
  const bulkBar = () => S.sel.size ? `<div class="card" style="position:sticky;bottom:12px;margin:12px 14px;padding:10px 14px;display:flex;align-items:center;gap:10px;box-shadow:0 10px 30px -14px rgba(2,6,23,.6);z-index:5"><span class="small num"><b>${S.sel.size} selected</b></span><span class="grow"></span><button class="btn secondary sm" data-act="basket-sel"><i class="fas fa-shopping-basket"></i> Add to basket</button><button class="btn success sm" data-act="download-sel"><i class="fas fa-download"></i> Download ${S.sel.size} NZBs</button><button class="btn ghost sm" data-act="clear-sel">Clear</button></div>` : '';

  function coverCard(e, i){
    const compact = S.size==='compact';
    return `<div class="cover-card ${compact?'compact':''} ${i===S.open?'is-open':''}" data-act="expand" data-i="${i}" tabindex="0">${art(e)}${e.root==='adult'?'':`<span class="cover-count" title="${e.releases.length} releases">${e.releases.length}</span>`}${canWatch(e)?`<button class="cover-heart ${isWatched(e)?'on':''}" data-act="watch" data-key="${key(e)}" title="${isWatched(e)?'On your watchlist · click to edit':'Add to '+(e.root==='movies'?'My Movies':'My Shows')}"><i class="${isWatched(e)?'fas':'far'} fa-heart"></i></button>`:''}<div class="cover-body"><div class="cover-title ${compact?'one':''}">${esc(e.root==='adult'?e.releases[0].name:e.title)}</div><div class="cover-sub">${e.root==='movies'?`${e.year} · ★ ${e.rating}`:e.root==='tv'?`${esc(e.network)} · ${e.status}`:e.root==='music'?`${esc(e.artist)} · ${e.year}`:e.root==='console'?`${e.platform} · ${e.year}`:e.root==='books'?`${esc(e.author)} · ${e.year}`:`${e.releases[0].size} · ${e.releases[0].added}`}</div>${compact?'':`<div class="cover-foot"><span class="meta" style="margin:0"><span class="chip">${e.root==='movies'?esc(e.genre):e.root==='tv'?'★ '+e.rating:e.root==='music'?esc(e.genre):e.root==='console'?e.esrb:e.root==='books'?esc(e.genre):e.releases[0].cat}</span></span><span class="num small" style="font-weight:600;color:var(--link)">${e.root==='adult'?e.releases[0].complete+'%':e.releases.length+' release'+(e.releases.length>1?'s':'')}</span></div>`}</div></div>`;
  }
  function detailCard(e){
    return `<div class="card dc"><div class="dc-art">${art(e,true)}</div><div class="dc-body"><div class="dc-head"><div><div class="dc-title">${e.root==='adult'?`<a href="#/details/${e.releases[0].guid}">${esc(e.title)}</a>`:`<a href="#/title/${e.root}/${e.id}">${esc(e.title)}</a>`} <span class="muted" style="font-weight:400">${e.year}</span></div><div class="meta" style="margin-top:4px">${e.root==='music'?`<span class="chip">${esc(e.artist)}</span><span class="chip">${esc(e.genre)}</span><span class="chip">${esc(e.label)}</span>`:e.root==='console'?`<span class="chip">${e.platform}</span><span class="chip">${esc(e.publisher)}</span><span class="chip">${e.esrb}</span>`:e.root==='books'?`<span class="chip">${esc(e.author)}</span><span class="chip">${esc(e.publisher)}</span>`:e.root==='adult'?`<span class="chip">${e.releases[0].cat}</span>`:`<span class="chip">★ ${e.rating}</span><span class="chip">${esc(e.genre)}</span>${e.root==='movies'?`<span class="chip action">IMDb ↗</span>`:`<span class="chip">${esc(e.network)}</span>`}`}${canWatch(e)?`<button class="chip action ${isWatched(e)?'p':''}" data-act="watch" data-key="${key(e)}"><i class="${isWatched(e)?'fas':'far'} fa-heart"></i> ${isWatched(e)?'Watching':'Watch'}</button>`:''}</div></div><span class="small muted num">${e.releases.length} release${e.releases.length>1?'s':''}</span></div><div class="dc-rels"><div class="eyebrow" style="margin-bottom:2px">Releases · ${e.releases.length}</div>${e.releases.map(r=>`<div class="dc-rel"><div style="min-width:0"><div class="titleline"><a href="#/details/${r.guid}" class="title">${esc(r.name)}</a>${facts(r)}</div><div class="subline"><span class="num">${r.size}</span><span class="num">${r.files} files</span><span class="num">${r.added}</span>${stats(r)}</div></div>${actions(r)}</div>`).join('')}</div></div></div>`;
  }
  function expanded(e){
    const rows = e.releases;
    return `<div class="cover-row-expand"><div class="cover-expanded"><div class="cover-expanded-head"><span style="white-space:nowrap"><b>${esc(e.title)}</b> · ${rows.length} release${rows.length>1?'s':''}</span><span class="grow"></span><button class="btn secondary sm" data-act="sel-many" data-guids="${rows.map(r=>r.guid).join(',')}"><i class="far fa-square-check"></i> Select all</button><button class="btn success sm" data-act="download-sel"><i class="fas fa-download"></i> Download selected</button>${e.root==='adult'?'':`<a class="btn secondary sm" href="#/title/${e.root}/${e.id}"><i class="fas fa-arrow-up-right-from-square"></i> Title page</a>`}<button class="btn ghost sm icon" data-act="collapse" title="Close (Esc)"><i class="fas fa-xmark"></i></button></div>${table(rows)}</div></div>`;
  }

  // ---------------- Pages ----------------
  function filteredEntities(root){
    let list = ENT[root].slice();
    if (S.route.params && S.route.params.watching) list = list.filter(isWatched);
    if (S.year) list = list.filter(e=>String(e.year)===S.year);
    if (S.genre) list = list.filter(e=>e.genre===S.genre);
    if (S.network) list = list.filter(e=>e.network===S.network);
    if (S.label) list = list.filter(e=>e.label===S.label);
    if (S.platform) list = list.filter(e=>e.platform===S.platform);
    if (S.publisher) list = list.filter(e=>e.publisher===S.publisher);
    if (S.author) list = list.filter(e=>e.author===S.author);
    if (S.q) list = list.filter(e=>e.title.toLowerCase().includes(S.q.toLowerCase()));
    if (S.sort==='title' || S.letter) list.sort((a,b)=>a.title.localeCompare(b.title));
    else if (S.sort==='year') list.sort((a,b)=>b.year-a.year);
    else if (S.sort==='rating') list.sort((a,b)=>(b.rating||0)-(a.rating||0));
    else if (S.sort==='artist') list.sort((a,b)=>(a.artist||'').localeCompare(b.artist||''));
    else list.sort((a,b)=>a.latest.ageH-b.latest.ageH);
    return list;
  }
  function toolbar(root, view){
    const R = ENT[root]||[]; const years=[...new Set(R.map(e=>e.year))].sort((a,b)=>b-a); const genres=[...new Set(R.map(e=>e.genre).filter(Boolean))].sort(); const nets=[...new Set(R.map(e=>e.network).filter(Boolean))].sort(); const labels=[...new Set(R.map(e=>e.label).filter(Boolean))].sort(); const platforms=[...new Set(R.map(e=>e.platform).filter(Boolean))].sort(); const publishers=(root==='console'||root==='books')?[...new Set(R.map(e=>e.publisher).filter(Boolean))].sort():[]; const authors=[...new Set(R.map(e=>e.author).filter(Boolean))].sort();
    const label = LABEL[root]||'All releases';
    return `<div class="toolbar sticky"><label class="field" style="width:230px"><i class="fas fa-magnifying-glass muted"></i><input data-act="q" value="${esc(S.q)}" placeholder="Search in ${label}…"></label>
      ${root!=='all'?`<select class="field select" data-act="year"><option value="">Year</option>${years.map(y=>`<option ${S.year==String(y)?'selected':''}>${y}</option>`).join('')}</select>${genres.length?`<select class="field select" data-act="genre"><option value="">Genre</option>${genres.map(g=>`<option ${S.genre===g?'selected':''}>${g}</option>`).join('')}</select>`:''}${nets.length?`<select class="field select" data-act="network"><option value="">Network</option>${nets.map(g=>`<option ${S.network===g?'selected':''}>${esc(g)}</option>`).join('')}</select>`:''}${labels.length?`<select class="field select" data-act="label"><option value="">Label</option>${labels.map(g=>`<option ${S.label===g?'selected':''}>${esc(g)}</option>`).join('')}</select>`:''}${platforms.length>1?`<select class="field select" data-act="platform"><option value="">Platform</option>${platforms.map(g=>`<option ${S.platform===g?'selected':''}>${esc(g)}</option>`).join('')}</select>`:''}${publishers.length?`<select class="field select" data-act="publisher"><option value="">Publisher</option>${publishers.map(g=>`<option ${S.publisher===g?'selected':''}>${esc(g)}</option>`).join('')}</select>`:''}${authors.length?`<select class="field select" data-act="author"><option value="">Author</option>${authors.map(g=>`<option ${S.author===g?'selected':''}>${esc(g)}</option>`).join('')}</select>`:''}`:''}
      ${(S.year||S.genre||S.network||S.label||S.platform||S.publisher||S.author||S.q||S.letter||(S.route.params&&S.route.params.watching))?`<button class="btn ghost sm" data-act="clear-filters"><i class="fas fa-xmark"></i> Clear</button>`:''}
      <span class="grow"></span>
      <select class="field select" data-act="sort"><option value="newest" ${S.sort==='newest'?'selected':''}>Sort: Newest release</option><option value="title" ${S.sort==='title'?'selected':''}>Sort: Title A–Z</option>${root==='movies'||root==='tv'?`<option value="year" ${S.sort==='year'?'selected':''}>Sort: Year</option><option value="rating" ${S.sort==='rating'?'selected':''}>Sort: Rating</option>`:root==='music'?`<option value="year" ${S.sort==='year'?'selected':''}>Sort: Year</option><option value="artist" ${S.sort==='artist'?'selected':''}>Sort: Artist</option>`:''}</select>
      <span class="seg" title="View"><button data-act="view" data-v="table" aria-pressed="${view==='table'}"><i class="fas fa-list"></i> Table</button>${CARD_ROOTS.includes(root)?`<button data-act="view" data-v="cards" aria-pressed="${view==='cards'}" title="Renamed, post-processed releases only"><i class="fas fa-table-cells-large"></i> Cards</button>`:''}${root!=='all'?`<button data-act="view" data-v="covers" aria-pressed="${view==='covers'}"><i class="fas fa-table-cells"></i> Covers</button>`:''}</span>
      ${view==='table'?`<span class="seg" title="Thumbnails"><button data-act="thumbs" aria-pressed="${S.thumbs}" title="Show cover thumbnails"><i class="fas fa-image"></i></button></span>`:''}
      ${view==='covers'?`<span class="seg" title="Cover size"><span class="small muted" style="padding:0 8px;display:inline-flex;align-items:center">Cover size</span><button data-act="size" data-s="compact" aria-pressed="${S.size==='compact'}" title="Small">S</button><button data-act="size" data-s="standard" aria-pressed="${S.size==='standard'}" title="Large">L</button>${root==='adult'?'':`<button data-act="size" data-s="detail" aria-pressed="${S.size==='detail'}" title="Extra large · with releases">XL</button>`}</span>`:''}
    </div>`;
  }
  function browsePage(root){
    const label = LABEL[root]||'All releases'; const view = (S.view[root]==='cards' && !CARD_ROOTS.includes(root)) ? 'table' : (S.view[root]||'table'); const watching = S.route.params && S.route.params.watching; const shape = SHAPE[root]||'tall';
    const COLS = {tall:{compact:10,standard:6}, square:{compact:8,standard:5}, wide:{compact:5,standard:3}};
    let inner='';
    if (root==='all' || view!=='covers'){
      let rows = root==='all' ? REL.slice() : filteredEntities(root).flatMap(e=>e.releases);
      if (root==='all' && S.q) rows = rows.filter(r=>r.name.toLowerCase().includes(S.q.toLowerCase()));
      const gp = S.route.params||{}; if (gp.group) rows = rows.filter(r=>r.group===gp.group); if (gp.poster) rows = rows.filter(r=>r.poster===gp.poster);
      const before = rows.length; if (view==='cards') rows = rows.filter(r=>r.renamed && r.ppDone); const hidden = before-rows.length;
      if (S.sort==='title') rows.sort((a,b)=>a.name.localeCompare(b.name)); else rows.sort((a,b)=>a.ageH-b.ageH);
      const total=rows.length; const start=(S.page-1)*S.perPage; const slice=rows.slice(start,start+S.perPage);
      const extra = view==='cards' ? `releases · renamed and post-processed only${hidden?` (${hidden} not shown)`:''}` : 'releases';
      const originBar = '';
      inner = originBar + pageBar(total,S.perPage,S.page,extra) + (view==='cards'?cards(slice):table(slice,{thumbs:S.thumbs})) + bulkBar() + pageBar(total,S.perPage,S.page,extra);
    } else {
      const list = filteredEntities(root); const total=list.length; const start=(S.page-1)*S.perPage; const slice=list.slice(start,start+S.perPage);
      const letters = `<div class="letters">${['#',...'ABCDEFGHIJKLMNOPQRSTUVWXYZ'].map(l=>`<a href="#" data-act="letter" data-l="${l}" class="${S.letter===l?'on':''}">${l}</a>`).join('')}<span class="small muted" style="margin-left:auto">Jump by initial · sorts by title</span></div>`;
      let grid;
      if (S.size==='detail' && root!=='adult') grid = `<div class="dc-grid" style="--cols:2">${slice.map(detailCard).join('')||'<div class="empty">No titles match.</div>'}</div>`;
      else { const cols = COLS[shape][S.size==='compact'?'compact':'standard']; let out=''; const rowEnd = S.open>=0 ? Math.min(Math.ceil((S.open+1)/cols)*cols, slice.length)-1 : -1; slice.forEach((e,i)=>{ out+=coverCard(e,i); if (i===rowEnd) out+=expanded(slice[S.open]); }); grid = `<div class="cover-grid ${S.size}" style="--cols:${cols}">${out||'<div class="empty" style="grid-column:1/-1">No titles match.</div>'}</div>`; }
      const unit = root==='adult'?'releases':root==='music'?'albums':root==='console'?'games':root==='books'?'books':'titles';
      inner = ((root==='tv'||root==='music'||root==='books')?letters:'') + pageBar(total,S.perPage,S.page,unit) + grid + bulkBar() + pageBar(total,S.perPage,S.page,unit);
    }
    const gpp = S.route.params||{}; const originTitle = gpp.group ? `Releases in ${esc(gpp.group)}` : gpp.poster ? `Posts by ${esc(gpp.poster)}` : '';
    return `<div class="page-head"><div><div class="crumbs"><a href="#/browse/movies">Browse</a> › ${originTitle?`<a href="#/browse/all">${label}</a>`:`<b>${label}</b>${watching?' › <b>Watching</b>':''}`}</div><h1>${originTitle||(label+(watching?' you follow':''))}</h1></div><div style="display:flex;gap:8px">${originTitle?`<a class="btn secondary sm" href="#/browse/all"><i class="fas fa-xmark"></i> Clear filter</a>`:''}${(root==='movies'||root==='tv')?`<a class="btn secondary sm" href="#/browse/${root}?watching=1" ${watching?'style="display:none"':''}><i class="fas fa-heart"></i> Only titles I follow</a>`:''}<button class="btn secondary sm" data-act="toast" data-msg="RSS URL copied for this view"><i class="fas fa-rss"></i> RSS for this view</button></div></div><div class="card">${toolbar(root,view)}${inner}</div>`;
  }
  function titlePage(root, id){
    const e = (ENT[root]||[])[id]; if (!e || root==='adult') return '<div class="empty-page">Unknown title.</div>';
    const w = isWatched(e); const cats = w ? S.watch[key(e)] : null;
    const kv = (rows) => `<div class="kv">${rows.filter(([k,v])=>v!==undefined&&v!==null&&v!=='').map(([k,v])=>`<div><span>${k}</span><b>${v}</b></div>`).join('')}</div>`;
    const links = root==='movies' ? ['IMDb','TMDB','Trakt'] : root==='tv' ? ['TVDB','TVMaze','Trakt'] : root==='music' ? ['MusicBrainz','iTunes'] : root==='console' ? ['IGDB'] : ['Goodreads','ISBNdb'];
    const meta = root==='movies' ? kv([['Year',e.year],['Rating','★ '+e.rating],['Genre',esc(e.genre)],['Runtime',e.runtime+' min'],['Director',esc(e.director)],['Cast',esc(e.actors)]])
      : root==='tv' ? kv([['Network',esc(e.network)],['Status',e.status],['First aired',e.year],['Rating','★ '+e.rating],['Genre',esc(e.genre)],['Seasons',e.seasons]])
      : root==='music' ? kv([['Artist',esc(e.artist)],['Year',e.year],['Label',esc(e.label)],['Genre',esc(e.genre)],['Tracks',e.tracks.length]])
      : root==='console' ? kv([['Platform',e.platform],['Publisher',esc(e.publisher)],['Genre',esc(e.genre)],['Released',e.year],['ESRB',e.esrb]])
      : kv([['Author',esc(e.author)],['Publisher',esc(e.publisher)],['Published',e.year],['Pages',e.pages],['ISBN',e.isbn],['Genre',esc(e.genre)]]);
    const plot = plotFor(e);
    const watchBtn = canWatch(e) ? `<button class="btn ${w?'primary':'secondary'} sm" data-act="watch" data-key="${key(e)}"><i class="${w?'fas':'far'} fa-heart"></i> ${w?'Watching':'Watch'}${w?' <i class="fas fa-chevron-down" style="font-size:11px"></i>':''}</button>` : '';
    const hero = `<div class="card ent-hero"><div class="ent-art">${art(e,true)}</div><div class="ent-body">
        <h1>${esc(e.title)}${root==='music'?` <span class="muted" style="font-weight:400;font-size:16px">${esc(e.artist)}</span>`:root!=='tv'&&e.year?` <span class="muted" style="font-weight:400;font-size:16px">${e.year}</span>`:''}</h1>
        <div class="ent-actions">${watchBtn}${links.map(l=>`<button class="btn secondary sm" data-act="toast" data-msg="Would open ${l}">${l} ↗</button>`).join('')}${root==='movies'?`<button class="btn secondary sm" data-act="toast" data-msg="Trailer modal would open"><i class="fas fa-play"></i> Trailer</button>`:''}</div>
        ${w?`<div class="small muted">On your ${root==='movies'?'My Movies':'My Shows'} for: ${cats.map(c=>`<span class="chip p">${c}</span>`).join(' ')} <a href="#" data-act="watch" data-key="${key(e)}">change</a> · <a href="#" data-act="unwatch" data-key="${key(e)}">remove</a></div>`:''}
        ${meta}
        ${plot?`<p class="ent-plot">${plot}</p>`:''}
        ${root==='music'?`<div class="ent-tracks"><div class="eyebrow" style="margin-bottom:6px">Tracks</div><ol>${e.tracks.map(t=>`<li>${esc(t.replace(/^\d+\. /,''))}</li>`).join('')}</ol></div>`:''}
        <div class="ent-stats"><div><span>Releases</span><b>${e.releases.length}</b></div><div><span>Latest</span><b>${e.latest.added}</b></div>${root==='tv'?`<div><span>Season packs</span><b>${e.releases.filter(r=>r.pack).length}</b></div>`:root==='music'?`<div><span>Best</span><b>${e.releases.some(r=>r.res.includes('24bit'))?'24-bit FLAC':'FLAC'}</b></div>`:root==='movies'?`<div><span>Best</span><b>${e.releases.map(r=>r.res).sort().reverse()[0]}</b></div>`:''}</div>
      </div></div>`;
    let body;
    if (root==='tv'){
      const seasons = [...new Set(e.releases.map(r=>r.season))].sort((a,b)=>a-b);
      const cur = seasons.includes(S.season) ? S.season : seasons[seasons.length-1];
      let rows=e.releases.filter(r=>r.season===cur); if (S.qual.size) rows=rows.filter(r=>S.qual.has(r.res)); rows.sort((a,b)=>(a.episode||99)-(b.episode||99)||a.ageH-b.ageH);
      const eps=new Set(rows.filter(r=>r.episode).map(r=>r.episode)).size; const packs=rows.filter(r=>r.pack).length;
      body = `<div class="card"><div class="tabs season-tabs">${seasons.map(sn=>`<button class="${sn===cur?'active':''}" data-act="season" data-n="${sn}">Season ${sn}<span class="tab-count">${e.releases.filter(r=>r.season===sn).length}</span></button>`).join('')}</div>
        <div class="season-head" style="border-radius:0"><b>Season ${cur}</b><span class="muted small">${eps} episode${eps===1?'':'s'}${packs?` · ${packs} season pack${packs>1?'s':''}`:''} · ${rows.length} release${rows.length===1?'':'s'}</span><span class="grow"></span><button class="btn secondary sm" data-act="sel-many" data-guids="${rows.map(r=>r.guid).join(',')}"><i class="far fa-square-check"></i> Select season</button></div>${table(rows)}</div>`;
    } else {
      let rows = e.releases.slice(); if (S.qual.size) rows = rows.filter(r=>S.qual.has(r.res));
      body = `<div class="card">${table(rows)}</div>`;
    }
    const quals = [...new Set(e.releases.map(r=>r.res))];
    const qbar = quals.length>1 ? `<div class="qchips card" style="margin-bottom:12px"><span class="small muted" style="align-self:center">${root==='music'?'Format':'Quality'}:</span><button class="chip action ${S.qual.size===0?'on':''}" data-act="qual" data-q="">All</button>${quals.map(q=>`<button class="chip action ${S.qual.has(q)?'on':''}" data-act="qual" data-q="${q}">${q}</button>`).join('')}</div>` : '';
    return `<div class="crumbs"><a href="#/browse/${root}">${LABEL[root]}</a> › <b>${esc(e.title)}</b></div>${hero}<h2 class="ent-h2">Releases</h2>${qbar}${body}${bulkBar()}`;
  }
  function detailsPage(guid){
    const r = REL.find(x=>x.guid===guid); if (!r) return '<div class="empty-page">Unknown release.</div>';
    const e=r.ent; const tabs=[['overview','Overview'],['files',`Files (${r.files})`],['media','Media info'],['nfo','NFO'],['comments',`Comments (${r.comments})`]];
    const body = {
      overview:`<div class="card" style="padding:16px"><div class="eyebrow" style="margin-bottom:8px">${e.root==='movies'?'Movie':e.root==='tv'?'Series':e.root==='music'?'Album':'Release'}</div><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px 18px;font-size:13px">${[['Title',e.root==='adult'?esc(e.title):`<a href="#/title/${e.root}/${e.id}">${esc(e.title)}</a> (${e.year})`],...(e.root==='music'?[['Artist',esc(e.artist)],['Label',esc(e.label)],['Genre',esc(e.genre)]]:e.root==='adult'?[['Quality',r.cat]]:[['Rating','★ '+e.rating],['Genre',esc(e.genre)],[e.root==='movies'?'Director':'Network',esc(e.director||e.network)]]),['Group',esc(r.group)],['Poster',esc(r.poster)],['PreDB','Matched'],['Password',r.pw==='passworded'?'Detected':'None detected']].map(([k,v])=>`<div><div class="small muted">${k}</div><div>${v}</div></div>`).join('')}</div></div>`,
      files:`<div class="card" style="padding:16px"><div class="eyebrow">Files (${r.files})</div><div class="small" style="margin-top:8px;line-height:1.8">${Array.from({length:Math.min(r.files,8)},(_,i)=>`${slug(r.name).toLowerCase()}.${i===0?'mkv':'r'+String(i).padStart(2,'0')} · ${(1+(h(r.name+i)%900)/100).toFixed(2)} GB`).join('<br>')}${r.files>8?`<br>… ${r.files-8} more`:''}</div></div>`,
      media:`<div class="card" style="padding:16px"><div class="eyebrow">Media info</div><div class="small" style="margin-top:8px;line-height:1.8">${r.media?`Video: ${esc(r.media)} · 23.976 fps<br>Audio: English · ${r.media.split(' · ')[2]||'AAC 2.0'}<br>Subtitles: English, French, Spanish`:'No media info for this release.'}</div></div>`,
      nfo:`<div class="card" style="padding:16px"><div class="eyebrow">NFO</div><pre class="mono nfo-pre" style="margin-top:8px">${r.nfo?esc(nfoText(r)):'No NFO for this release.'}</pre></div>`,
      comments:`<div class="card" style="padding:16px"><div class="eyebrow">Comments (${r.comments})</div>${r.comments?`<div style="margin-top:8px;display:grid;gap:8px">${Array.from({length:Math.min(r.comments,3)},(_,i)=>`<div style="font-size:13px;border-top:1px solid var(--border-default);padding-top:8px"><b>user${(h(r.name)+i)%900}</b> <span class="small muted">· ${AGES[(i+2)%AGES.length][0]}</span><div>${['Works fine, thanks.','Audio out of sync on my end.','Great release.'][i]}</div></div>`).join('')}</div>`:'<div class="small muted" style="margin-top:8px">No comments yet.</div>'}<div style="margin-top:12px;display:flex;gap:8px"><label class="field" style="flex:1"><input placeholder="Write a comment…"></label><button class="btn primary sm" data-act="toast" data-msg="Comment posted (prototype)">Post</button></div></div>`
    }[S.tab];
    const others = e.releases.filter(x=>x!==r);
    return `<div class="crumbs"><a href="#/browse/${e.root}">${LABEL[e.root]}</a> › ${e.root==='adult'?'':`<a href="#/title/${e.root}/${e.id}">${esc(e.title)}</a> › `}<b>${r.catLabel.split(' > ')[1]}</b></div>
      <div class="card" style="padding:16px;display:grid;grid-template-columns:120px 1fr;gap:16px;margin-top:6px"><div>${art(e,true)}</div><div style="min-width:0"><h1 style="word-break:break-all">${esc(r.name)}</h1><div class="meta" style="margin-top:8px">${facts(r,{group:true})}</div><div class="subline" style="margin-top:8px"><span class="pill" style="height:18px;font-size:11px">${r.catLabel}</span><span class="num">${r.size}</span><span class="num">${r.files} files</span><span class="num">added ${r.added}</span>${stats(r)}</div>
        <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap"><button class="btn success" data-act="download" data-guid="${r.guid}"><i class="fas fa-download"></i> Download NZB</button><button class="btn secondary" data-act="basket" data-guid="${r.guid}"><i class="fas fa-shopping-basket"></i> ${S.basket.has(r.guid)?'Remove from basket':'Add to basket'}</button><button class="btn secondary" data-act="tab" data-t="nfo"><i class="fas fa-file-lines"></i> NFO</button><button class="btn secondary" data-act="tab" data-t="media"><i class="fas fa-circle-info"></i> Media info</button><button class="btn secondary" data-act="tab" data-t="files"><i class="fas fa-folder-open"></i> Files (${r.files})</button>${canWatch(e)?`<button class="btn ${isWatched(e)?'primary':'secondary'}" data-act="watch" data-key="${key(e)}"><i class="${isWatched(e)?'fas':'far'} fa-heart"></i> ${isWatched(e)?'Watching':'Watch'}</button>`:''}<button class="btn ghost" data-act="report" data-guid="${r.guid}"><i class="fas fa-flag"></i> Report</button></div></div></div>
      <div style="display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:14px;margin-top:14px"><div><div class="tabs">${tabs.map(([k,l])=>`<button class="${S.tab===k?'active':''}" data-act="tab" data-t="${k}">${l}</button>`).join('')}</div>${body}</div>
      <div><div class="card" style="padding:14px"><div class="eyebrow" style="margin-bottom:8px">Other releases of this title</div>${others.length?others.map(o=>`<div style="display:flex;justify-content:space-between;gap:8px;padding:6px 0;border-top:1px solid var(--border-default);font-size:13px"><a href="#/details/${o.guid}">${o.res} · ${o.name.includes('BluRay')?'BluRay':'WEB'}</a><span class="num muted">${o.size}</span><span class="chip ${o.complete===100?'ok':'warn'}">${o.complete===100?'✓':o.complete+'%'}</span></div>`).join(''):'<div class="small muted">None.</div>'}</div>
      <div class="card" style="padding:14px;margin-top:12px"><div class="eyebrow" style="margin-bottom:8px">Similar releases</div>${REL.filter(x=>x.root===r.root&&x.ent!==e&&x.ent.genre===e.genre).slice(0,4).map(x=>`<div style="padding:6px 0;border-top:1px solid var(--border-default);font-size:13px"><a href="#/details/${x.guid}">${esc(x.name)}</a></div>`).join('')}</div></div></div>`;
  }
  function watchlistPage(){
    const root = S.wlTab; const mine = ENT[root].filter(isWatched);
    const q = S.wlq.trim().toLowerCase(); const found = q ? ENT[root].filter(e=>e.title.toLowerCase().includes(q) && !isWatched(e)).slice(0,6) : [];
    return `<div class="page-head"><div><div class="crumbs"><b>Watchlist</b></div><h1>Watchlist</h1></div><div style="display:flex;gap:8px"><a class="btn secondary sm" href="#/browse/${root}?watching=1"><i class="fas fa-list"></i> View releases from these</a><button class="btn secondary sm" data-act="toast" data-msg="RSS URL copied for your watchlist"><i class="fas fa-rss"></i> RSS</button></div></div>
      <div class="card"><div class="tabs" style="margin:0;padding:0 14px;align-items:center"><button class="${root==='movies'?'active':''}" data-act="wltab" data-t="movies">My Movies · ${ENT.movies.filter(isWatched).length}</button><button class="${root==='tv'?'active':''}" data-act="wltab" data-t="tv">My Shows · ${ENT.tv.filter(isWatched).length}</button><span class="grow"></span><label class="field sm" style="margin:4px 0;width:260px"><i class="fas fa-magnifying-glass muted"></i><input data-act="wlq" value="${esc(S.wlq)}" placeholder="Find a ${root==='movies'?'movie':'show'} to add…"></label></div>
      ${q?`<div style="padding:10px 14px;border-bottom:1px solid var(--border-default)"><div class="eyebrow" style="margin-bottom:6px">Add</div>${found.length?found.map(e=>`<div style="display:flex;align-items:center;gap:10px;padding:6px 0;font-size:13px">${posterSm(e)}<span style="flex:1"><b>${esc(e.title)}</b> <span class="muted">${e.year}${e.network?' · '+esc(e.network):''}</span></span><button class="btn primary sm" data-act="watch" data-key="${key(e)}"><i class="far fa-heart"></i> Add</button></div>`).join(''):'<div class="small muted">No unfollowed titles match.</div>'}</div>`:''}
      ${mine.length?`<div style="display:grid;gap:10px;padding:14px">${mine.map(e=>`<div class="card wl-card"><div>${art(e)}</div><div><a href="#/title/${e.root}/${e.id}" style="font-weight:600;font-size:13px">${esc(e.title)}</a> <span class="muted small">${e.year}${e.network?' · '+esc(e.network):''}</span><div class="small muted" style="margin-top:2px">Latest: <a href="#/details/${e.latest.guid}">${esc(e.latest.name)}</a> · ${e.latest.added}</div><div class="meta">${S.watch[key(e)].map(c=>`<span class="chip p">${c}</span>`).join('')}</div></div><div style="display:flex;gap:6px"><button class="btn secondary sm" data-act="watch" data-key="${key(e)}"><i class="fas fa-pen"></i> Edit</button><button class="btn ghost sm" data-act="unwatch" data-key="${key(e)}" style="color:var(--bad)"><i class="fas fa-trash"></i> Remove</button></div></div>`).join('')}</div>`:`<div class="empty-page"><i class="far fa-heart" style="font-size:28px"></i><div style="margin-top:8px">Nothing followed yet.</div><div class="small">Use the search box above, the heart on any cover, the Watch button on a title page, or the heart on a release row.</div><div style="margin-top:12px"><a class="btn primary sm" href="#/browse/${root}">Browse ${root==='movies'?'Movies':'TV'}</a></div></div>`}</div>`;
  }
  function basketPage(){
    const rows = REL.filter(r=>S.basket.has(r.guid));
    return `<div class="page-head"><div><div class="crumbs"><b>Basket</b></div><h1>Basket</h1></div></div><div class="card">${rows.length?table(rows)+`<div style="padding:10px 14px;border-top:1px solid var(--border-default);display:flex;align-items:center;gap:10px;background:var(--surface-panel-alt);border-radius:0 0 12px 12px"><span class="small num"><b>${rows.length} in basket</b></span><span class="grow"></span><button class="btn secondary sm" data-act="basket-clear">Empty basket</button><button class="btn success sm" data-act="download-basket"><i class="fas fa-download"></i> Download ${rows.length} NZBs</button></div>`:`<div class="empty-page"><i class="fas fa-shopping-basket" style="font-size:28px"></i><div style="margin-top:8px">Your basket is empty.</div><div class="small">Use the basket button on any release row.</div></div>`}</div>`;
  }
  function searchPage(){
    const q = (S.route.params.q||'').trim(); const scope = S.route.params.t||'all';
    let rows = REL.filter(r=>r.name.toLowerCase().includes(q.toLowerCase()) || r.ent.title.toLowerCase().includes(q.toLowerCase()) || (r.ent.artist||'').toLowerCase().includes(q.toLowerCase())); if (scope!=='all') rows = rows.filter(r=>r.root===scope);
    const total=rows.length; const start=(S.page-1)*S.perPage; const slice=rows.slice(start,start+S.perPage);
    return `<div class="page-head"><div><div class="crumbs"><b>Search</b></div><h1>Results for “${esc(q)}”</h1></div><button class="btn secondary sm" data-act="toast" data-msg="RSS URL copied for this search"><i class="fas fa-rss"></i> RSS for this search</button></div>
      <div class="card"><div style="padding:10px 14px;border-bottom:1px solid var(--border-default);display:flex;gap:6px;flex-wrap:wrap;align-items:center"><span class="chip p" style="height:24px">“${esc(q)}”</span>${scope!=='all'?`<a class="chip p" style="height:24px" href="#/search?q=${encodeURIComponent(q)}">Scope: ${LABEL[scope]||scope} ✕</a>`:''}<span class="small muted">Chips are the filters; add one from the toolbar or with a prefix like <span class="mono">actor:</span>.</span></div>${pageBar(total,S.perPage,S.page,'results')}${table(slice)}${bulkBar()}</div>`;
  }
  function accountPage(){
    return `<div class="page-head"><div><div class="crumbs"><b>Account</b></div><h1>Account</h1></div></div><div style="display:grid;grid-template-columns:200px 1fr;gap:14px"><div class="card" style="padding:8px;align-self:start">${['Profile','Appearance','Security','API & RSS','Downloads','Privacy'].map((s,i)=>`<a href="#" style="display:block;padding:7px 10px;border-radius:8px;font-size:13px;${i===1?'background:var(--p100);color:var(--p800);font-weight:600':'color:var(--text-2)'}">${s}</a>`).join('')}</div>
      <div class="card" style="padding:16px"><h3>Appearance</h3><div style="display:grid;gap:12px;margin-top:10px;font-size:13px"><div><div class="small muted" style="margin-bottom:4px">Theme</div><span class="seg">${[['light','Light'],['dark','Dark'],['system','System']].map(([v,l])=>`<button data-act="theme" data-v="${v}" aria-pressed="${(S.theme||'system')===v}">${l}</button>`).join('')}</span></div><div><div class="small muted" style="margin-bottom:4px">Colour scheme</div><span class="seg">${['blue','emerald','violet'].map(v=>`<button data-act="scheme" data-v="${v}" aria-pressed="${S.scheme===v}">${v[0].toUpperCase()+v.slice(1)}</button>`).join('')}</span></div><div><div class="small muted" style="margin-bottom:4px">Default view per root</div><div class="small">Movies: <b>${S.view.movies}</b> · TV: <b>${S.view.tv}</b> · All: <b>${S.view.all}</b> <span class="muted">(remembered from what you last used)</span></div></div></div></div></div>`;
  }

  // ---------------- Shell ----------------
  function shell(content){
    const r = S.route; const bc = S.basket.size; const wc = Object.keys(S.watch).length;
    const mega = S.menu==='browse' ? `<div class="menu mega" style="left:0">${[['Movies','movies',['UHD','HD','SD','Foreign']],['TV','tv',['UHD','HD','SD','Anime']],['Audio','music',['Lossless','MP3','Audiobook']],['Console','console',['PS5','Switch','Xbox Series']],['Books','books',['Ebook','Audiobook','Comics']],['Adult','adult',['HD','SD']],['All releases','all',['Everything, newest first']],['PC / Games',null,['Games','ISO']]].map(([l,root,subs])=>`<div class="${root?'':'na'}"><a class="root" href="${root?'#/browse/'+root:'#'}">${l}${root?'':' <span class="small muted">(not in prototype)</span>'}</a>${subs.map(x=>`<a class="sub" href="${root?'#/browse/'+root:'#'}">${x}</a>`).join('')}</div>`).join('')}<div style="grid-column:1/-1;border-top:1px solid var(--border-default);padding-top:8px" class="small muted">One category list drives this menu, the mobile drawer and the search scope. No hand-written links.</div></div>` : '';
    const user = S.menu==='user' ? `<div class="menu right" style="min-width:220px"><a class="menu-item" href="#/account"><i class="fas fa-user"></i> Account</a><a class="menu-item" href="#/watchlist"><i class="fas fa-heart"></i> Watchlist · ${wc}</a><a class="menu-item" href="#/basket"><i class="fas fa-shopping-basket"></i> Basket · ${bc}</a><div class="sep"></div><div class="small muted" style="padding:4px 8px">Theme</div><div style="display:flex;gap:4px;padding:0 8px 6px"><span class="seg">${[['light','Light'],['dark','Dark'],['system','System']].map(([v,l])=>`<button data-act="theme" data-v="${v}" aria-pressed="${(S.theme||'system')===v}">${l}</button>`).join('')}</span></div><div class="small muted" style="padding:4px 8px">Scheme</div><div style="display:flex;gap:4px;padding:0 8px 6px"><span class="seg">${['blue','emerald','violet'].map(v=>`<button data-act="scheme" data-v="${v}" aria-pressed="${S.scheme===v}">${v[0].toUpperCase()+v.slice(1)}</button>`).join('')}</span></div><div class="sep"></div><button class="menu-item" data-act="toast" data-msg="Signed out (prototype)"><i class="fas fa-right-from-bracket"></i> Sign out</button></div>` : '';
    return `<div class="proto-banner">Interactive prototype · everything is client-side sample data · decisions 1B · 2A · 3A · 4A · 6A applied</div>
    <header class="topbar"><a class="logo" href="#/browse/movies">NNTmux</a>
      <nav class="nav"><span style="position:relative"><button data-act="menu" data-m="browse" class="${S.menu==='browse'?'is-open':''} ${r.page==='browse'?'active':''}"><i class="fas fa-compass"></i> Browse <i class="fas fa-chevron-down" style="font-size:11px"></i></button>${mega}</span><a class="text ${r.page==='title'&&false?'active':''}" href="#/browse/movies?sort=rating" data-act="toast-link" data-msg="Trending = Covers sorted by grabs this week (prototype uses rating)"><i class="fas fa-fire"></i> Trending</a><a class="text ${r.page==='watchlist'?'active':''}" href="#/watchlist"><i class="fas fa-heart"></i> Watchlist${wc?` · ${wc}`:''}</a></nav>
      <span class="spacer"></span>
      <form class="field search" data-act="search-form"><select class="scope" name="t"><option value="all" ${S.route.params.t==='all'||!S.route.params.t?'selected':''}>All</option><option value="movies" ${S.route.params.t==='movies'?'selected':''}>Movies</option><option value="tv" ${S.route.params.t==='tv'?'selected':''}>TV</option><option value="music" ${S.route.params.t==='music'?'selected':''}>Audio</option><option value="console" ${S.route.params.t==='console'?'selected':''}>Console</option><option value="books" ${S.route.params.t==='books'?'selected':''}>Books</option><option value="adult" ${S.route.params.t==='adult'?'selected':''}>Adult</option></select><input name="q" value="${esc(S.route.page==='search'?(S.route.params.q||''):'')}" placeholder="Search releases…  ( / )" aria-label="Search releases"></form>
      <a class="btn ghost sm count-btn ${r.page==='basket'?'active':''}" href="#/basket"><i class="fas fa-shopping-basket"></i> ${bc}</a>
      <span style="position:relative"><button class="avatar" data-act="menu" data-m="user" title="Account"></button>${user}</span>
    </header><main class="page">${content}</main><div class="toasts">${S.toasts.map(t=>`<div class="toast ${t.kind||''}">${t.icon||'<i class="fas fa-check" style="color:var(--ok)"></i>'} <span>${t.msg}</span>${t.undo?`<a href="#" data-act="undo" data-id="${t.id}">Undo</a>`:''}</div>`).join('')}</div>${modalHtml()}${pickerHtml()}`;
  }
  function nfoText(r){
    const grp = r.name.split('-').pop(); const pad = (l,w) => (l+' '.repeat(w)).slice(0,w);
    const line = (k,v) => `  ${pad(k,14)}: ${v}`;
    const W=62; const box = t => ' █' + pad(t, W) + '█'; const rule = c => ' ' + c.repeat(W+2);
    return [
      rule('▄'), box(''), box('   ' + grp + ' presents'), box(''), rule('▀'),
      '',
      '  ' + r.name,
      '',
      line('Title', r.ent.title + (r.ent.year?' ('+r.ent.year+')':'')),
      line('Source', r.name.includes('BluRay') ? 'BluRay' : 'WEB-DL'),
      r.root==='music' ? line('Format', r.media) : line('Resolution', r.res),
      r.root==='music' ? line('Tracks', String(r.files)) : line('Video', (r.media||'').split(' · ')[1] || 'n/a'),
      r.root==='music' ? line('Label', r.ent.label||'') : line('Audio', ((r.media||'').split(' · ')[2] || 'AAC 2.0') + ' English'),
      line('Subtitles', 'English (SRT)'),
      line('Size', r.size + ' · ' + r.files + ' files'),
      line('Runtime', (90 + (h(r.name)%80)) + ' min'),
      r.ent.imdb ? line('IMDb', 'https://www.imdb.com/title/'+r.ent.imdb+'/') : r.ent.network ? line('Network', r.ent.network) : r.ent.artist ? line('Artist', r.ent.artist) : line('Studio', 'n/a'),
      '',
      '  Notes: ' + (r.pw==='passworded' ? 'Archive is password protected, see release notes.' : 'No password. Verified against retail.'),
      '',
      '  Greets to all the groups still doing it right.',
      '',
      ' ────────────────────────────────────────────────────────────────',
      '  ' + grp + ' · ' + new Date().getFullYear(),
    ].join('\n');
  }
  function modalHtml(){
    if (!S.modal) return ''; const r = REL.find(x=>x.guid===S.modal.guid); if(!r) return ''; const [a,b]=pick(HUES,r.ent.art);
    if (S.modal.kind==='nfo') return `<div class="overlay" data-act="modal-close"><div class="dialog nfo-dialog" data-stop>
      <div class="modal-head"><i class="fas fa-file-lines" style="color:var(--warn)"></i><div style="min-width:0"><div style="font-weight:600">NFO</div><div class="small muted" style="word-break:break-all">${esc(r.name)}</div></div><span class="grow"></span><button class="btn ghost sm icon" data-act="modal-close" title="Close (Esc)"><i class="fas fa-xmark"></i></button></div>
      <div class="nfo-body"><pre class="mono nfo-pre">${esc(nfoText(r))}</pre></div>
      <div class="modal-foot"><button class="btn secondary sm" data-act="nfo-copy" data-guid="${r.guid}"><i class="fas fa-copy"></i> Copy text</button><button class="btn secondary sm" data-act="toast" data-msg="${esc(r.name)}.nfo would download"><i class="fas fa-file-arrow-down"></i> Download .nfo</button><span class="grow"></span><a class="btn secondary sm" href="#/details/${r.guid}" data-act="modal-nav"><i class="fas fa-info"></i> Details</a><button class="btn success sm" data-act="download" data-guid="${r.guid}"><i class="fas fa-download"></i> Download NZB</button></div></div></div>`;
    const isVideo = r.preview==='video', isAudio = r.preview==='audio', isSample = r.preview==='sample';
    const kindLabel = isVideo?'Video preview':isAudio?'Audio preview':isSample?'Sample image':'Preview image';
    return `<div class="overlay" data-act="modal-close"><div class="dialog" data-stop style="width:min(760px,92vw);padding:0;overflow:hidden">
      <div style="display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid var(--border-default)"><i class="fas ${isVideo?'fa-video':isAudio?'fa-headphones':isSample?'fa-images':'fa-image'}" style="color:var(--info)"></i><div style="min-width:0"><div style="font-weight:600;font-size:13px">${kindLabel}</div><div class="small muted" style="word-break:break-all">${esc(r.name)}</div></div><span class="grow"></span><button class="btn ghost sm icon" data-act="modal-close" title="Close (Esc)"><i class="fas fa-xmark"></i></button></div>
      ${isAudio?`<div style="padding:22px 20px;display:grid;grid-template-columns:96px 1fr;gap:18px;align-items:center"><div class="cover-art square" style="--a:${a};--b:${b};border-radius:8px"></div><div><div style="font-weight:600">${esc(r.ent.title)} <span class="muted" style="font-weight:400">· ${esc(r.ent.artist)}</span></div><div class="small muted">${esc(r.media)} · 30-second preview</div><div style="display:flex;align-items:center;gap:12px;margin-top:14px"><button class="btn primary icon" title="Play"><i class="fas fa-play"></i></button><div style="flex:1;height:36px;display:flex;align-items:center;gap:2px">${Array.from({length:60},(_,i)=>`<span style="flex:1;height:${6+((h(r.name+i)%100)/100)*30}px;background:${i<18?'var(--p500)':'var(--border-default)'};border-radius:1px"></span>`).join('')}</div><span class="small num muted">0:09 / 0:30</span><i class="fas fa-volume-high muted"></i></div></div></div>`:`<div style="background:#000;aspect-ratio:16/9;display:flex;align-items:center;justify-content:center;position:relative;background-image:linear-gradient(155deg,${a},${b});">${isVideo?`<div style="width:64px;height:64px;border-radius:50%;background:rgba(255,255,255,.9);display:flex;align-items:center;justify-content:center;color:#0f172a;font-size:22px"><i class="fas fa-play" style="margin-left:4px"></i></div><div style="position:absolute;left:0;right:0;bottom:0;padding:10px 14px;background:linear-gradient(to top,rgba(0,0,0,.7),transparent);color:#fff;font-size:12px;display:flex;gap:10px;align-items:center"><i class="fas fa-play"></i><span style="flex:1;height:4px;background:rgba(255,255,255,.35);border-radius:2px"><span style="display:block;width:30%;height:4px;background:#fff;border-radius:2px"></span></span><span class="num">0:09 / 0:30</span><i class="fas fa-volume-high"></i><i class="fas fa-expand"></i></div>`:`<div style="color:rgba(255,255,255,.85);font-size:13px;text-align:center"><div style="font-size:22px;font-weight:700;text-shadow:0 1px 3px rgba(0,0,0,.6)">${esc(r.ent.title)}</div><div class="small">${isSample?'sample sheet':'sample frame'} · 1920×1080</div></div>`}</div>`}
      <div style="display:flex;align-items:center;gap:8px;padding:10px 16px;background:var(--surface-panel-alt)"><span class="grow"></span>${(isVideo||isAudio)?'':'<button class="btn secondary sm" data-act="toast" data-msg="Full-size image would open"><i class="fas fa-expand"></i> Full size</button>'}<a class="btn secondary sm" href="#/details/${r.guid}" data-act="modal-nav"><i class="fas fa-info"></i> Details</a><button class="btn success sm" data-act="download" data-guid="${r.guid}"><i class="fas fa-download"></i> Download NZB</button></div></div></div>`;
  }
  function pickerHtml(){
    if (!S.picker) return ''; const [root,id]=S.picker.split(':'); const e=ENT[root][+id]; const cur = S.watch[S.picker] || S.last || (root==='movies'?['UHD','HD']:['UHD','HD']);
    return `<div class="overlay" data-act="picker-cancel"><div class="dialog" data-stop><h3>${isWatched(e)?'Edit':'Add'} “${esc(e.title)}” ${isWatched(e)?'on':'to'} ${root==='movies'?'My Movies':'My Shows'}</h3><div class="small muted" style="margin-bottom:8px">Get releases in these categories:</div>${CATS[root].map(c=>`<label><input type="checkbox" name="cat" value="${c}" ${cur.includes(c)?'checked':''}> ${c}</label>`).join('')}<div style="display:flex;gap:6px;margin-top:12px"><button class="btn primary sm" data-act="picker-save"><i class="fas fa-heart"></i> ${isWatched(e)?'Save':'Add'}</button><button class="btn secondary sm" data-act="picker-cancel">Cancel</button>${isWatched(e)?`<button class="btn ghost sm" data-act="unwatch" data-key="${S.picker}" style="color:var(--bad);margin-left:auto">Remove</button>`:''}</div></div></div>`;
  }

  // ---------------- Render + events ----------------
  const app = document.getElementById('app');
  function render(){
    const r = S.route; let content='';
    if (r.page==='browse') content = browsePage(r.root);
    else if (r.page==='title') content = titlePage(r.root, r.id);
    else if (r.page==='details') content = detailsPage(r.guid);
    else if (r.page==='watchlist') content = watchlistPage();
    else if (r.page==='basket') content = basketPage();
    else if (r.page==='search') content = searchPage();
    else if (r.page==='account') content = accountPage();
    const active = document.activeElement; const restore = active && active.dataset && active.dataset.act==='q' ? {v:active.value, s:active.selectionStart} : null;
    app.innerHTML = shell(content);
    if (restore){ const el = app.querySelector('[data-act="q"]'); if (el){ el.focus(); el.setSelectionRange(restore.s, restore.s); } }
    document.documentElement.setAttribute('data-scheme', S.scheme);
    if (S.theme) document.documentElement.setAttribute('data-theme', S.theme); else document.documentElement.removeAttribute('data-theme');
  }
  let toastId=0;
  function toast(msg, opts={}){ const t={id:++toastId, msg, ...opts}; S.toasts.push(t); render(); setTimeout(()=>{ S.toasts = S.toasts.filter(x=>x!==t); render(); }, opts.ms||3200); }
  const undoStack = {};

  app.addEventListener('click', ev=>{
    const el = ev.target.closest('[data-act]'); const a = el && el.dataset.act;
    if (!el){ if (S.menu){ S.menu=null; render(); } return; }
    if (['q','wlq','goto','year','genre','network','label','platform','publisher','author','sort','sel','selall'].includes(a)) return; // inputs handled elsewhere
    if (el.tagName==='A' && el.getAttribute('href') && el.getAttribute('href')!=='#' && !['toast-link','watch','unwatch','modal-nav'].includes(a)) { S.menu=null; return; } // real navigation
    ev.preventDefault();
    switch(a){
      case 'menu': S.menu = S.menu===el.dataset.m ? null : el.dataset.m; render(); break;
      case 'toast': toast(el.dataset.msg, {kind:'info', icon:'<i class="fas fa-circle-info" style="color:var(--p600)"></i>'}); break;
      case 'toast-link': toast(el.dataset.msg, {kind:'info', icon:'<i class="fas fa-circle-info" style="color:var(--p600)"></i>'}); S.sort='rating'; S.page=1; go(el.getAttribute('href')); break;
      case 'view': S.view[S.route.root||'all'] = el.dataset.v; S.page=1; S.open=-1; render(); break;
      case 'noop': break;
      case 'size': S.size = el.dataset.s; S.open=-1; render(); break;
      case 'thumbs': S.thumbs=!S.thumbs; render(); break;
      case 'perpage': S.perPage=+el.dataset.n; S.page=1; S.open=-1; render(); break;
      case 'page': { const n=+el.dataset.n; if (n>=1 && n<=(S.pages||1) && n!==S.page) { S.page=n; S.open=-1; render(); window.scrollTo({top:0}); } break; }
      case 'letter': S.letter = S.letter===el.dataset.l ? '' : el.dataset.l; S.sort='title'; { const list=filteredEntities(S.route.root); const idx=list.findIndex(e=>S.letter==='#'?/^[^A-Za-z]/.test(e.title):e.title.toUpperCase().startsWith(S.letter)); S.page = idx>=0 ? Math.floor(idx/S.perPage)+1 : 1; } S.open=-1; render(); break;
      case 'clear-filters': S.year=S.genre=S.network=S.label=S.platform=S.publisher=S.author=S.letter=S.q=''; S.page=1; S.open=-1; if (S.route.params.watching) go('#/browse/'+S.route.root); else render(); break;
      case 'expand': { const i=+el.dataset.i; S.open = S.open===i ? -1 : i; render(); const row=app.querySelector('.cover-row-expand'); if(row) row.scrollIntoView({block:'nearest',behavior:'smooth'}); break; }
      case 'collapse': S.open=-1; render(); break;
      case 'tab': S.tab=el.dataset.t; render(); break;
      case 'season': S.season=+el.dataset.n; render(); break;
      case 'wltab': S.wlTab=el.dataset.t; S.wlq=''; render(); break;
      case 'qual': { const q=el.dataset.q; if(!q) S.qual.clear(); else if (S.qual.has(q)) S.qual.delete(q); else S.qual.add(q); render(); break; }
      case 'download': { const r=REL.find(x=>x.guid===el.dataset.guid); r.grabs++; toast(`NZB download started: ${esc(r.name)}`); break; }
      case 'download-sel': { if(!S.sel.size){ toast('Nothing selected.',{kind:'warn',icon:'<i class="fas fa-triangle-exclamation" style="color:var(--warn)"></i>'}); break; } toast(`Downloading ${S.sel.size} NZBs as one archive`); S.sel.clear(); render(); break; }
      case 'download-basket': toast(`Downloading ${S.basket.size} NZBs as one archive`); break;
      case 'basket': { const g=el.dataset.guid; if (S.basket.has(g)) { S.basket.delete(g); toast('Removed from basket',{kind:'info',icon:'<i class="fas fa-shopping-basket" style="color:var(--p600)"></i>'}); } else { S.basket.add(g); toast('Added to basket',{icon:'<i class="fas fa-shopping-basket" style="color:var(--ok)"></i>'}); } break; }
      case 'basket-sel': S.sel.forEach(g=>S.basket.add(g)); toast(`Added ${S.sel.size} to basket`); S.sel.clear(); render(); break;
      case 'basket-clear': S.basket.clear(); render(); break;
      case 'report': toast('Report dialog would open (reason, description)',{kind:'info',icon:'<i class="fas fa-flag" style="color:var(--p600)"></i>'}); break;
      case 'preview': S.modal={kind:'preview', guid:el.dataset.guid}; render(); break;
      case 'modal-close': if (ev.target.closest('[data-stop]') && !el.matches('button')) return; S.modal=null; render(); break;
      case 'modal-nav': S.modal=null; go(el.getAttribute('href')); break;
      case 'nfo': S.modal={kind:'nfo', guid:el.dataset.guid}; render(); break;
      case 'nfo-copy': { const r=REL.find(x=>x.guid===el.dataset.guid); try{ navigator.clipboard && navigator.clipboard.writeText(nfoText(r)); }catch(e){} toast('NFO text copied'); break; }
      case 'mediainfo': case 'files': { const r=REL.find(x=>x.guid===el.dataset.guid); S.tab = a==='mediainfo'?'media':a; go('#/details/'+r.guid); break; }
      case 'sel-many': el.dataset.guids.split(',').forEach(g=>S.sel.add(g)); render(); break;
      case 'clear-sel': S.sel.clear(); render(); break;
      case 'watch': S.picker = el.dataset.key; S.menu=null; render(); break;
      case 'unwatch': { const k=el.dataset.key; const prev=S.watch[k]; delete S.watch[k]; S.picker=null; const [root,id]=k.split(':'); const e=ENT[root][+id]; undoStack[toastId+1]={k,prev}; toast(`Removed ${esc(e.title)} from ${root==='movies'?'My Movies':'My Shows'}`,{kind:'info',icon:'<i class="far fa-heart" style="color:var(--p600)"></i>',undo:true}); break; }
      case 'undo': { const u=undoStack[+el.dataset.id]; if(u){ S.watch[u.k]=u.prev; delete undoStack[+el.dataset.id]; S.toasts=[]; toast('Restored'); } break; }
      case 'picker-save': { const cats=[...app.querySelectorAll('.dialog input[name=cat]:checked')].map(i=>i.value); if(!cats.length){ toast('Pick at least one category',{kind:'warn',icon:'<i class="fas fa-triangle-exclamation" style="color:var(--warn)"></i>'}); break; } const k=S.picker; const was=!!S.watch[k]; S.watch[k]=cats; S.last=cats; S.picker=null; const [root,id]=k.split(':'); const e=ENT[root][+id]; toast(`${was?'Updated':'Added'} ${esc(e.title)} ${was?'on':'to'} ${root==='movies'?'My Movies':'My Shows'} · ${cats.join(', ')} <a href="#/watchlist" style="margin-left:6px">Open</a>`); break; }
      case 'picker-cancel': if (ev.target.closest('[data-stop]') && !el.matches('button')) return; S.picker=null; render(); break;
      case 'theme': S.theme = el.dataset.v==='system'?null:el.dataset.v; render(); break;
      case 'scheme': S.scheme = el.dataset.v; render(); break;
    }
  });
  app.addEventListener('change', ev=>{
    const el = ev.target.closest('[data-act]'); if(!el) return; const a=el.dataset.act;
    if (a==='sel'){ el.checked ? S.sel.add(el.dataset.guid) : S.sel.delete(el.dataset.guid); render(); }
    else if (a==='selall'){ app.querySelectorAll('[data-act="sel"]').forEach(c=>{ el.checked ? S.sel.add(c.dataset.guid) : S.sel.delete(c.dataset.guid); }); render(); }
    else if (['year','genre','network','label','platform','publisher','author','sort'].includes(a)){ S[a]=el.value; if (a==='sort') S.letter=''; S.page=1; S.open=-1; render(); }
  });
  let qt; app.addEventListener('input', ev=>{ const el=ev.target.closest('[data-act]'); if(!el) return; if (el.dataset.act==='q'){ clearTimeout(qt); qt=setTimeout(()=>{ S.q=el.value; S.page=1; S.open=-1; render(); },180); } if (el.dataset.act==='wlq'){ clearTimeout(qt); qt=setTimeout(()=>{ S.wlq=el.value; render(); const i=app.querySelector('[data-act="wlq"]'); if(i){ i.focus(); i.setSelectionRange(i.value.length,i.value.length);} },180); } });
  app.addEventListener('keydown', ev=>{ const el=ev.target.closest('[data-act="goto"]'); if (el && ev.key==='Enter'){ const n=parseInt(el.value,10); if(n>0 && n<=(S.pages||1)){ S.page=n; S.open=-1; render(); } else { toast('Page out of range',{kind:'warn',icon:'<i class="fas fa-triangle-exclamation" style="color:var(--warn)"></i>'}); } } });
  app.addEventListener('submit', ev=>{ const f=ev.target.closest('[data-act="search-form"]'); if(!f) return; ev.preventDefault(); const q=f.q.value.trim(); if(!q) return; S.page=1; go(`#/search?q=${encodeURIComponent(q)}&t=${f.t.value}`); });
  document.addEventListener('keydown', ev=>{ if (ev.key==='Escape'){ if (S.modal){ S.modal=null; render(); } else if (S.picker){ S.picker=null; render(); } else if (S.open>=0){ S.open=-1; render(); } else if (S.menu){ S.menu=null; render(); } }
    if (ev.key==='/' && !/input|select|textarea/i.test(ev.target.tagName)){ ev.preventDefault(); const i=app.querySelector('.topbar input[name=q]'); if(i) i.focus(); }
    if ((ev.key==='ArrowRight'||ev.key==='ArrowLeft') && S.open>=0 && !/input|select|textarea/i.test(ev.target.tagName)){ const n = S.open + (ev.key==='ArrowRight'?1:-1); if (n>=0 && n<S.perPage){ S.open=n; render(); const row=app.querySelector('.cover-row-expand'); if(row) row.scrollIntoView({block:'nearest'}); } } });

  // Seed: two titles already followed and one basket item, so pages are not empty on first open.
  S.watch['tv:'+ENT.tv.findIndex(e=>e.title==='The Bear')] = ['UHD','HD'];
  S.watch['movies:'+ENT.movies.findIndex(e=>e.title==='Blade Runner 2049')] = ['UHD'];
  S.basket.add(ENT.movies[0].releases[0].guid);
  try { const dark = matchMedia('(prefers-color-scheme: dark)').matches; S.theme = null; } catch(e){}
  S.route = parse(); S.q = S.route.params.q && S.route.page!=='search' ? S.route.params.q : '';
  render();
})();
