<?php
// POAdo Inline Translator (Bottom-nav button + Body-mounted centered popup)
// FINAL FIX v20260304b
// - Button can be placed inline anywhere (eg bottom nav)
// - Popup overlay is force-moved to <body> to avoid transform/stacking-context clipping
// - Popup centers in viewport, ultra-high z-index, no visible scrollbar

if (defined('POADO_GT_INLINE_LOADED')) return;
define('POADO_GT_INLINE_LOADED', true);
?>

<style>
/* kill google overlays */
.goog-tooltip,
.goog-text-highlight,
.goog-te-balloon-frame,
#goog-gt-tt{
  display:none!important;
  visibility:hidden!important;
  opacity:0!important;
  pointer-events:none!important;
}

/* shift page when google banner appears */
body.gt-topbar-shown{
  padding-top:40px!important;
  transition:padding-top .25s ease;
}

/* inline button (fits bottom nav) */
.poa-gt-inline-btn{
  display:inline-flex;
  align-items:center;
  gap:6px;
  height:28px;
  min-width:128px;
  padding:0 10px;
  border-radius:999px;
  background:#000;
  color:#5BFF3C;
  border:1px solid rgba(91,255,60,.55);
  font-size:12px;
  font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
  cursor:pointer;
  user-select:none;
  pointer-events:auto;
  white-space:nowrap;
}
.poa-gt-inline-btn:active{ transform:translateY(1px); }
.poa-gt-inline-btn img{
  width:16px;height:11px;border-radius:2px;object-fit:cover;
}

/* popup overlay (must be on body) */
#poaGtInlinePop{
  position:fixed;
  inset:0;
  width:100vw;
  height:100vh;

  display:none;

  z-index:2147483647;
  background:rgba(0,0,0,.72);

  align-items:center;
  justify-content:center;

  pointer-events:auto;
}

/* panel */
.poa-gt-inline-panel{
  width:min(1000px,92vw);
  max-height:80vh;
  background:#000;
  border:1px solid rgba(91,255,60,.25);
  border-radius:16px;
  padding:16px;
  overflow:auto;
  box-shadow:
    0 0 0 1px rgba(214,179,90,.06),
    0 0 26px rgba(91,255,60,.10);

  /* hide scrollbars (still scrolls) */
  scrollbar-width:none;
  -ms-overflow-style:none;
}
.poa-gt-inline-panel::-webkit-scrollbar{ width:0;height:0; }

/* header */
.poa-gt-inline-hint{
  font-size:11px;
  color:rgba(91,255,60,.8);
  margin:0 0 12px 0;
  letter-spacing:.8px;
}

/* grid */
.poa-gt-inline-grid{
  display:grid;
  grid-template-columns:repeat(5,1fr);
  gap:10px;
}
@media(max-width:900px){ .poa-gt-inline-grid{grid-template-columns:repeat(3,1fr);} }
@media(max-width:600px){ .poa-gt-inline-grid{grid-template-columns:repeat(2,1fr);} }

/* language item */
.poa-gt-inline-item{
  display:flex;
  align-items:center;
  gap:8px;
  padding:8px 10px;
  border:1px solid rgba(91,255,60,.22);
  border-radius:12px;
  cursor:pointer;
  font-size:12px;
  background:#000;
  color:#5BFF3C;
  transition:.12s ease;
  user-select:none;
}
.poa-gt-inline-item:hover{
  background:rgba(91,255,60,.08);
  box-shadow:0 0 14px rgba(91,255,60,.12);
}
.poa-gt-inline-item.active{
  border-color:rgba(214,179,90,.8);
  background:rgba(214,179,90,.08);
}
.poa-gt-inline-item img{
  width:18px;height:13px;border-radius:2px;object-fit:cover;
}

/* close */
.poa-gt-inline-close{
  margin-top:14px;
  text-align:center;
  font-size:12px;
  color:#c7ff73;
  cursor:pointer;
}
</style>

<div id="google_translate_element" style="display:none"></div>

<!-- inline button: put this inside bottom nav wherever you want -->
<button class="poa-gt-inline-btn" id="poaGtInlineBtn" type="button">
  🌍
  <img id="poaGtInlineFlag" src="/dashboard/assets/flags/gb.png" alt="">
  <span id="poaGtInlineLabel">English</span>
</button>

<!-- popup (will be auto-moved to <body> by JS) -->
<div id="poaGtInlinePop" aria-hidden="true">
  <div class="poa-gt-inline-panel" role="dialog" aria-modal="true">
    <div class="poa-gt-inline-hint">SELECT LANGUAGE (33)</div>
    <div class="poa-gt-inline-grid" id="poaGtInlineGrid"></div>
    <div class="poa-gt-inline-close" id="poaGtInlineClose">CLOSE</div>
  </div>
</div>

<script>
(function(){
  if (window.__POADO_GT_INLINE_BOOTED) return;
  window.__POADO_GT_INLINE_BOOTED = true;

  const LANGS=[
    ['en','English'],['ms','Bahasa Melayu'],['zh-CN','简体中文'],['zh-TW','繁體中文'],
    ['id','Bahasa Indonesia'],['th','ภาษาไทย'],['vi','Tiếng Việt'],['tl','Filipino'],
    ['my','မြန်မာ'],['km','ភាសាខ្មែរ'],['ja','日本語'],['ko','한국어'],
    ['hi','हिन्दी'],['bn','বাংলা'],['ta','தமிழ்'],['ur','اردو'],
    ['ar','العربية'],['fa','فارسی'],['tr','Türkçe'],['ru','Русский'],
    ['uk','Українська'],['pl','Polski'],['de','Deutsch'],['fr','Français'],
    ['es','Español'],['pt','Português'],['nl','Nederlands'],['it','Italiano'],
    ['sv','Svenska'],['no','Norsk'],['da','Dansk'],['fi','Suomi'],['el','Ελληνικά']
  ];

  const FLAGS={
    'en':'gb','ms':'my','zh-CN':'cn','zh-TW':'tw','id':'id','th':'th','vi':'vn','tl':'ph','my':'mm','km':'kh',
    'ja':'jp','ko':'kr','hi':'in','bn':'bd','ta':'in','ur':'pk','ar':'sa','fa':'ir','tr':'tr','ru':'ru','uk':'ua',
    'pl':'pl','de':'de','fr':'fr','es':'es','pt':'pt','nl':'nl','it':'it','sv':'se','no':'no','da':'dk','fi':'fi','el':'gr'
  };

  function flagPath(lang){
    const iso = FLAGS[lang] || 'gb';
    return '/dashboard/assets/flags/' + iso + '.png';
  }

  function setCookie(lang){
    const v = (lang==='en') ? '/en/en' : ('/en/'+lang);
    document.cookie = 'googtrans=' + v + ';path=/';
  }

  function applyCombo(lang){
    const combo = document.querySelector('.goog-te-combo');
    if(!combo) return false;
    combo.value = lang;
    combo.dispatchEvent(new Event('change'));
    return true;
  }

  function buildGrid(){
    const grid = document.getElementById('poaGtInlineGrid');
    if(!grid) return;
    grid.textContent = '';

    const cur = localStorage.getItem('poa_lang') || 'en';

    for(const [l,name] of LANGS){
      const item = document.createElement('div');
      item.className = 'poa-gt-inline-item' + (l===cur ? ' active' : '');

      const img = document.createElement('img');
      img.src = flagPath(l);
      img.alt = '';
      img.onerror = ()=>{ img.src='/dashboard/assets/flags/gb.png'; };

      item.appendChild(img);
      item.appendChild(document.createTextNode(name));
      item.addEventListener('click', ()=>setLang(l,name));

      grid.appendChild(item);
    }
  }

  function openPop(){
    const pop = document.getElementById('poaGtInlinePop');
    if(!pop) return;
    pop.style.display = 'flex';
    pop.setAttribute('aria-hidden','false');
  }

  function closePop(){
    const pop = document.getElementById('poaGtInlinePop');
    if(!pop) return;
    pop.style.display = 'none';
    pop.setAttribute('aria-hidden','true');
  }

  function setLang(lang,name){
    localStorage.setItem('poa_lang', lang);

    const label = document.getElementById('poaGtInlineLabel');
    const flag  = document.getElementById('poaGtInlineFlag');
    if(label) label.textContent = name;
    if(flag)  flag.src = flagPath(lang);

    setCookie(lang);

    let tries = 0;
    (function retry(){
      tries++;
      if (applyCombo(lang)){
        buildGrid();
        closePop();
        return;
      }
      if (tries < 20) return setTimeout(retry, 200);
      location.reload();
    })();
  }

  // Critical: move popup to body to escape any transformed bottom-nav stacking context
  function forcePopupToBody(){
    const pop = document.getElementById('poaGtInlinePop');
    if (pop && pop.parentElement !== document.body){
      document.body.appendChild(pop);
    }
  }

  function initCurrentLabel(){
    const cur = localStorage.getItem('poa_lang') || 'en';
    const found = LANGS.find(x=>x[0]===cur) || LANGS[0];
    const label = document.getElementById('poaGtInlineLabel');
    const flag  = document.getElementById('poaGtInlineFlag');
    if(label) label.textContent = found[1];
    if(flag)  flag.src = flagPath(found[0]);
  }

  // Google Translate init
  window.googleTranslateElementInit = function(){
    try{
      new google.translate.TranslateElement(
        { pageLanguage:'en', autoDisplay:false },
        'google_translate_element'
      );
    }catch(e){}
  };

  (function loadGT(){
    if (document.querySelector('script[data-poado-gt-inline="1"]')) return;
    const s = document.createElement('script');
    s.setAttribute('data-poado-gt-inline','1');
    s.src = "//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit";
    document.body.appendChild(s);
  })();

  // watch Google topbar banner -> add padding
  function bannerWatcher(){
    function update(){
      const banner = document.querySelector('.goog-te-banner-frame');
      document.body.classList.toggle('gt-topbar-shown', !!banner);
    }
    update();
    try{
      const obs = new MutationObserver(update);
      obs.observe(document.documentElement, {childList:true,subtree:true});
    }catch(e){
      setInterval(update, 800);
    }
  }

  document.addEventListener('DOMContentLoaded', function(){
    forcePopupToBody();
    initCurrentLabel();
    buildGrid();
    bannerWatcher();

    const btn = document.getElementById('poaGtInlineBtn');
    const close = document.getElementById('poaGtInlineClose');
    const pop = document.getElementById('poaGtInlinePop');

    if(btn) btn.addEventListener('click', openPop);
    if(close) close.addEventListener('click', closePop);
    if(pop) pop.addEventListener('click', function(e){
      if (e.target === pop) closePop();
    });
  });

})();
</script>
