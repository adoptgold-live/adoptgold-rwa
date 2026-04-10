/* /dashboard/inc/calendar.js
 * POAdo Calendar Popup (DD/MM/YYYY)
 * v2026.02.15.1
 * EasyLoad upgrade:
 * - Safe double-load guard
 * - Exposes window.POADO_CALENDAR_VERSION and window.POADO_CALENDAR_READY
 */

/* /dashboard/inc/calendar.js
 * POAdo Calendar Popup (DD/MM/YYYY)
 * - No dependencies (replaces picker.js)
 * - Mobile/Web3 safe-area friendly
 * - Month navigation
 * - Optional server rules via POADO_CALENDAR_RULES_URL (JSON)
 */
(function(){
  'use strict';

  // EasyLoad guard
  if (window.POADO_CALENDAR && window.POADO_CALENDAR.__v) {
    window.POADO_CALENDAR_VERSION = window.POADO_CALENDAR.__v;
    window.POADO_CALENDAR_READY = true;
    return;
  }


  const pad2 = (n)=> String(n).padStart(2,'0');
  const fmtDMY = (y,m,d)=> `${pad2(d)}/${pad2(m)}/${y}`;

  function parseDMY(v){
    const m = /^\s*(\d{1,2})\/(\d{1,2})\/(\d{4})\s*$/.exec(String(v||''));
    if(!m) return null;
    const d = +m[1], mo = +m[2], y = +m[3];
    const dt = new Date(y, mo-1, d);
    if(dt.getFullYear()!==y || (dt.getMonth()+1)!==mo || dt.getDate()!==d) return null;
    return {y, m:mo, d};
  }

  function isoDate(y,m,d){
    return `${y}-${pad2(m)}-${pad2(d)}`;
  }

  // Rules format (best-effort):
  // { ok:true, min_date:'YYYY-MM-DD'?, max_date:'YYYY-MM-DD'?, disabled_dates:['YYYY-MM-DD', ...]?, disabled_weekdays:[0..6]? }
  async function loadRules(url){
    if(!url) return null;
    try{
      const r = await fetch(url, {credentials:'same-origin'});
      if(!r.ok) return null;
      const j = await r.json();
      if(!j || j.ok===false) return null;
      return j;
    }catch(_e){
      return null;
    }
  }

  function dateToYMD(dt){
    return {y:dt.getFullYear(), m:dt.getMonth()+1, d:dt.getDate()};
  }
  function ymdToDate(y,m,d){
    return new Date(y, m-1, d);
  }
  function cmpDate(a,b){
    // a,b are Date
    const ax = a.getFullYear()*10000 + (a.getMonth()+1)*100 + a.getDate();
    const bx = b.getFullYear()*10000 + (b.getMonth()+1)*100 + b.getDate();
    return ax-bx;
  }

  function build(){
    const overlay = document.createElement('div');
    overlay.id = 'poado-cal-overlay';
    overlay.style.cssText = [
      'position:fixed','inset:0','z-index:999999',
      'display:none','align-items:center','justify-content:center',
      'background:rgba(0,0,0,.65)','padding:14px',
      'padding-bottom:calc(14px + env(safe-area-inset-bottom, 0px))',
      'padding-top:calc(14px + env(safe-area-inset-top, 0px))'
    ].join(';');

    const panel = document.createElement('div');
    panel.id = 'poado-cal-panel';
    panel.style.cssText = [
      'width:min(360px, 92vw)','border-radius:14px',
      'background:linear-gradient(180deg,#050607,#000)','border:1px solid rgba(91,255,60,.6)',
      'box-shadow:0 0 18px rgba(91,255,60,.18)','color:#c7ffb8',
      'font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
      'overflow:hidden'
    ].join(';');

    const head = document.createElement('div');
    head.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-bottom:1px solid rgba(91,255,60,.25)';
    const btnPrev = document.createElement('button');
    btnPrev.type = 'button';
    btnPrev.textContent = '◀';
    const btnNext = document.createElement('button');
    btnNext.type = 'button';
    btnNext.textContent = '▶';
    const title = document.createElement('div');
    title.style.cssText = 'font-weight:800;letter-spacing:.4px;font-size:14px';
    const btnClose = document.createElement('button');
    btnClose.type = 'button';
    btnClose.textContent = '✕';
    const styleBtn = (b)=>{
      b.style.cssText = 'background:#000;border:1px solid rgba(91,255,60,.55);color:#5BFF3C;border-radius:10px;padding:6px 10px;cursor:pointer;font-weight:900;min-width:44px;'
    };
    [btnPrev, btnNext, btnClose].forEach(styleBtn);
    const left = document.createElement('div');
    left.style.cssText='display:flex;gap:8px;align-items:center';
    left.appendChild(btnPrev);
    left.appendChild(title);
    left.appendChild(btnNext);
    head.appendChild(left);
    head.appendChild(btnClose);

    const gridWrap = document.createElement('div');
    gridWrap.style.cssText = 'padding:10px 12px 12px';

    const dow = document.createElement('div');
    dow.style.cssText = 'display:grid;grid-template-columns:repeat(7,1fr);gap:6px;margin-bottom:8px;font-size:12px;color:rgba(199,255,184,.75)';
    ['Su','Mo','Tu','We','Th','Fr','Sa'].forEach(x=>{ const c=document.createElement('div'); c.textContent=x; c.style.textAlign='center'; dow.appendChild(c); });

    const grid = document.createElement('div');
    grid.id='poado-cal-grid';
    grid.style.cssText = 'display:grid;grid-template-columns:repeat(7,1fr);gap:6px';

    const foot = document.createElement('div');
    foot.style.cssText = 'display:flex;gap:10px;align-items:center;justify-content:space-between;padding:10px 12px;border-top:1px solid rgba(91,255,60,.25)';
    const hint = document.createElement('div');
    hint.style.cssText = 'font-size:12px;color:rgba(199,255,184,.75)';
    hint.textContent = 'Select date (DD/MM/YYYY)';
    const btnToday = document.createElement('button');
    btnToday.type='button';
    btnToday.textContent='Today';
    styleBtn(btnToday);
    foot.appendChild(hint);
    foot.appendChild(btnToday);

    gridWrap.appendChild(dow);
    gridWrap.appendChild(grid);
    panel.appendChild(head);
    panel.appendChild(gridWrap);
    panel.appendChild(foot);
    overlay.appendChild(panel);
    document.body.appendChild(overlay);

    return {overlay, panel, title, grid, btnPrev, btnNext, btnClose, btnToday};
  }

  const ui = build();
  let activeInput = null;
  let view = {y: new Date().getFullYear(), m: new Date().getMonth()+1};
  let rulesCache = { url:null, rules:null };

  function monthName(m){
    return ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][m-1] || '';
  }

  function isDisabled(dateObj, rules){
    if(!rules) return false;
    const d = ymdToDate(dateObj.y, dateObj.m, dateObj.d);
    if(rules.min_date){
      const p = rules.min_date.split('-');
      if(p.length===3){
        const minD = ymdToDate(+p[0], +p[1], +p[2]);
        if(cmpDate(d, minD) < 0) return true;
      }
    }
    if(rules.max_date){
      const p = rules.max_date.split('-');
      if(p.length===3){
        const maxD = ymdToDate(+p[0], +p[1], +p[2]);
        if(cmpDate(d, maxD) > 0) return true;
      }
    }
    if(Array.isArray(rules.disabled_weekdays)){
      const wd = d.getDay();
      if(rules.disabled_weekdays.includes(wd)) return true;
    }
    if(Array.isArray(rules.disabled_dates)){
      const iso = isoDate(dateObj.y, dateObj.m, dateObj.d);
      if(rules.disabled_dates.includes(iso)) return true;
    }
    return false;
  }

  function render(rules){
    ui.title.textContent = `${monthName(view.m)} ${view.y}`;
    ui.grid.innerHTML = '';
    const first = new Date(view.y, view.m-1, 1);
    const startDay = first.getDay();
    const daysInMonth = new Date(view.y, view.m, 0).getDate();
    const today = dateToYMD(new Date());
    const selected = activeInput ? parseDMY(activeInput.value) : null;

    // blanks
    for(let i=0;i<startDay;i++){
      const c=document.createElement('div');
      c.style.cssText='height:38px';
      ui.grid.appendChild(c);
    }
    for(let d=1; d<=daysInMonth; d++){
      const dateObj = {y:view.y, m:view.m, d};
      const btn=document.createElement('button');
      btn.type='button';
      btn.textContent=String(d);
      const disabled = isDisabled(dateObj, rules);
      const isToday = (dateObj.y===today.y && dateObj.m===today.m && dateObj.d===today.d);
      const isSel = selected && (dateObj.y===selected.y && dateObj.m===selected.m && dateObj.d===selected.d);
      btn.disabled = !!disabled;
      btn.style.cssText = [
        'height:38px','border-radius:12px','cursor:pointer','font-weight:900',
        'background:#000','border:1px solid rgba(91,255,60,.35)','color:#c7ffb8'
      ].join(';');
      if(isToday) btn.style.border='1px solid rgba(255,187,51,.75)';
      if(isSel){
        btn.style.border='1px solid rgba(91,255,60,.9)';
        btn.style.boxShadow='0 0 12px rgba(91,255,60,.18)';
      }
      if(disabled){
        btn.style.opacity='.35';
        btn.style.cursor='not-allowed';
      }
      btn.addEventListener('click', ()=>{
        if(!activeInput) return;
        activeInput.value = fmtDMY(dateObj.y, dateObj.m, dateObj.d);
        activeInput.dispatchEvent(new Event('input', {bubbles:true}));
        activeInput.dispatchEvent(new Event('change', {bubbles:true}));
        close();
      });
      ui.grid.appendChild(btn);
    }
  }

  function openFor(input){
    activeInput = input;
    const parsed = parseDMY(input.value);
    const base = parsed ? new Date(parsed.y, parsed.m-1, 1) : new Date();
    view = {y: base.getFullYear(), m: base.getMonth()+1};
    ui.overlay.style.display='flex';
    ui.overlay.setAttribute('aria-hidden','false');
    document.documentElement.style.overflow='hidden';
    document.body.style.overflow='hidden';

    const rulesUrl = (input.dataset.rulesUrl || window.POADO_CALENDAR_RULES_URL || '').trim();
    if(rulesUrl && rulesCache.url !== rulesUrl){
      rulesCache.url = rulesUrl;
      rulesCache.rules = null;
      loadRules(rulesUrl).then(r=>{ rulesCache.rules = r; render(rulesCache.rules); });
      render(null);
    }else{
      render(rulesCache.rules);
    }
  }

  function close(){
    ui.overlay.style.display='none';
    ui.overlay.setAttribute('aria-hidden','true');
    document.documentElement.style.overflow='';
    document.body.style.overflow='';
    activeInput = null;
  }

  ui.btnClose.addEventListener('click', close);
  ui.overlay.addEventListener('click', (e)=>{
    if(e.target === ui.overlay) close();
  });
  ui.btnPrev.addEventListener('click', ()=>{
    view.m -= 1;
    if(view.m<1){ view.m=12; view.y -= 1; }
    render(rulesCache.rules);
  });
  ui.btnNext.addEventListener('click', ()=>{
    view.m += 1;
    if(view.m>12){ view.m=1; view.y += 1; }
    render(rulesCache.rules);
  });
  ui.btnToday.addEventListener('click', ()=>{
    const t = dateToYMD(new Date());
    view = {y:t.y, m:t.m};
    render(rulesCache.rules);
  });

  document.addEventListener('keydown', (e)=>{
    if(ui.overlay.style.display!=='flex') return;
    if(e.key==='Escape'){ e.preventDefault(); close(); }
  });

  // Auto-bind: inputs with data-poado-calendar or class 'poado-calendar'
  function bindAll(){
    const inputs = Array.from(document.querySelectorAll('input[data-poado-calendar], input.poado-calendar'));
    inputs.forEach(inp=>{
      if(inp.__poadoCalBound) return;
      inp.__poadoCalBound = true;
      inp.setAttribute('inputmode','numeric');
      inp.setAttribute('autocomplete','off');
      inp.addEventListener('focus', ()=> openFor(inp));
      inp.addEventListener('click', ()=> openFor(inp));
    });
  }

  if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded', bindAll);
  }else{
    bindAll();
  }

  // Public API (optional)
  window.POADO_CALENDAR = {
    __v: 'v2026.02.15.1',
    open: (input)=> openFor(input),
    close
  };
  window.POADO_CALENDAR_VERSION = 'v2026.02.15.1';
  window.POADO_CALENDAR_READY = true;
})();
