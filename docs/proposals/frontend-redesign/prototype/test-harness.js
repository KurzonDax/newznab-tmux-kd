window.addEventListener('error', e => { (window.__errs=window.__errs||[]).push(e.message+' @'+e.lineno); });
window.addEventListener('load', () => {
  const log = []; const $ = s => document.querySelector(s); const $$ = s => [...document.querySelectorAll(s)];
  const step = (name, fn) => { try { fn(); log.push('OK  '+name); } catch(e){ log.push('FAIL '+name+': '+e.message); } };
  const click = s => { const el = typeof s==='string' ? $(s) : s; if(!el) throw new Error('missing '+s); el.click(); };
  setTimeout(()=>{
    step('covers rendered', ()=>{ if ($$('.cover-card').length < 40) throw new Error('only '+$$('.cover-card').length); });
    step('expand a cover', ()=>{ click($$('.cover-card')[3]); if(!$('.cover-row-expand')) throw new Error('no expand'); });
    step('heart opens picker', ()=>{ click($$('.cover-card')[3].querySelector('.cover-heart')); if(!$('.dialog')) throw new Error('no picker'); });
    step('picker add', ()=>{ click('[data-act="picker-save"]'); if ($('.dialog')) throw new Error('still open'); if(!$$('.cover-card')[3].querySelector('.cover-heart.on')) throw new Error('heart not on'); });
    step('watchlist count in nav', ()=>{ const t=$('.topbar a[href="#/watchlist"]').textContent; if(!/3/.test(t)) throw new Error(t); });
    step('basket add from row', ()=>{ click($('.cover-row-expand [data-act="basket"]')); if(!/2/.test($('.topbar a[href="#/basket"]').textContent)) throw new Error($('.topbar a[href="#/basket"]').textContent); });
    step('switch to table view', ()=>{ click('[data-act="view"][data-v="table"]'); if(!$('table.rel')) throw new Error('no table'); });
    step('switch to cards view', ()=>{ click('[data-act="view"][data-v="cards"]'); if(!$('.rlist .rrow')) throw new Error('no cards'); });
    step('per page 100', ()=>{ click('[data-act="view"][data-v="covers"]'); click('[data-act="perpage"][data-n="100"]'); if($$('.cover-card').length!==100) throw new Error($$('.cover-card').length); });
    step('size L', ()=>{ click('[data-act="size"][data-s="standard"]'); if(!$('.cover-grid.standard')) throw new Error('no L'); });
    step('size XL', ()=>{ click('[data-act="size"][data-s="detail"]'); if(!$('.dc-grid')) throw new Error('no XL'); });
    step('navigate watchlist', ()=>{ location.hash='#/watchlist'; });
    setTimeout(()=>{
      step('watchlist lists 2 movies', ()=>{ if($$('.wl-card').length!==2) throw new Error($$('.wl-card').length+' cards'); });
      step('watchlist remove', ()=>{ click($('.wl-card [data-act="unwatch"]')); if($$('.wl-card').length!==1) throw new Error('still '+$$('.wl-card').length); });
      step('undo', ()=>{ click('[data-act="undo"]'); if($$('.wl-card').length!==2) throw new Error('not restored'); });
      step('basket page', ()=>{ location.hash='#/basket'; });
      setTimeout(()=>{
        step('basket has 2 rows', ()=>{ if($$('table.rel tbody tr').length!==2) throw new Error($$('table.rel tbody tr').length); });
        step('search submit', ()=>{ const f=$('[data-act="search-form"]'); f.q.value='dune'; f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})); });
        setTimeout(()=>{
          step('search results', ()=>{ if(!/Results for/.test($('h1').textContent)) throw new Error($('h1').textContent); if(!$$('table.rel tbody tr').length) throw new Error('no rows'); });
          step('details page', ()=>{ location.hash='#/'+$('table.rel a[href^="#/details/"]').getAttribute('href').slice(2); });
          setTimeout(()=>{
            step('details tabs', ()=>{ click('[data-act="tab"][data-t="files"]'); if(!/Files/.test($('.tabs button.active').textContent)) throw new Error('tab'); });
            step('title page', ()=>{ location.hash='#/title/movies/0'; });
            setTimeout(()=>{
              step('title page watch', ()=>{ click($('.btn[data-act="watch"]')); click('[data-act="picker-save"]'); if(!/Watching/.test($('.btn[data-act="watch"]').textContent)) throw new Error('not watching'); });
              step('quality chip', ()=>{ const c=$$('[data-act="qual"]')[1]; if(!c) return; click(c); if(!$$('[data-act="qual"].on').length) throw new Error('chip'); });
              step('mega menu', ()=>{ click('[data-act="menu"][data-m="browse"]'); if(!$('.mega')) throw new Error('no mega'); });
              step('scheme switch', ()=>{ click('[data-act="menu"][data-m="user"]'); click('[data-act="scheme"][data-v="violet"]'); if(document.documentElement.dataset.scheme!=='violet') throw new Error('scheme'); });
              const d=document.createElement('pre'); d.id='testlog'; d.textContent=log.join('\n')+'\nERRORS: '+JSON.stringify(window.__errs||[]); document.body.appendChild(d);
            },200); },200); },200); },200); },200);
  },400);
});
