<?php
// /dashboard/inc/gt.php
// POAdo Global Translator — FINAL PRODUCTION (v20260214)
// - 33-language whitelist, 5-column popup
// - STRICT ISO2 flags (no duplicate wrong flags)
// - Layering: Footer 9000, Topbar 9500, Dropdown 9800, GT Popup 20000
// - Auto slide-down whole page when Google Translate topbar appears
// - Kill Google hover snippet/tooltips/highlight overlays
// - Dock GT button into footer-left slot (#poaFooterLeft) if present, else floating bottom-left
// - Click-safe + no SyntaxError: DO NOT use innerHTML for flag rendering
// - UI-only partial: safe include anywhere (no DB / no session / no guards)

if (defined('POADO_GT_LOADED')) return;
define('POADO_GT_LOADED', true);
?>

<style>
/* ===== Kill Google hover snippet / tooltips / highlight overlays ===== */
.goog-tooltip,
.goog-tooltip:hover,
.goog-text-highlight,
iframe.goog-te-balloon-frame{
  display:none !important;
  visibility:hidden !important;
  opacity:0 !important;
  pointer-events:none !important;
}

/* Some GT UIs inject these */
#goog-gt-tt, .goog-te-balloon-frame, .goog-te-spinner-pos{
  display:none !important;
  visibility:hidden !important;
  opacity:0 !important;
  pointer-events:none !important;
}

/* ===== Auto slide-down whole page when Google topbar appears ===== */
body.gt-topbar-shown{
  margin-top:40px !important;
  transition: margin-top .25s ease;
}

/* ===== Translator widget must not be translated ===== */
.poa-gt-nx,
.poa-gt-nx *{
  translate:no;
}
.poa-gt-nx{ -webkit-text-size-adjust:100%; }

/* ===== GT Button ===== */
.poa-gt-btn{
  background:#000;
  border:1px solid rgba(91,255,60,.45);
  color:#5BFF3C;
  padding:8px 12px;
  border-radius:14px;
  font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
  cursor:pointer;
  display:inline-flex;
  align-items:center;
  gap:8px;
  transition:.18s ease;
  user-select:none;
  white-space:nowrap;
  pointer-events:auto; /* critical */
  box-shadow:0 0 0 rgba(91,255,60,0);
}
.poa-gt-btn:hover{
  box-shadow:0 0 18px rgba(91,255,60,.20);
}
.poa-gt-btn:active{
  transform:translateY(1px);
}
.poa-gt-btn img{
  width:18px;height:12px;border-radius:2px;object-fit:cover;
}

/* Floating fallback */
.poa-gt-float{
  position:fixed;
  left:16px;
  bottom:16px;
  z-index:20001; /* above topbar/footer/dropdowns; popup is 20000 */
}

/* When docked into footer-left slot, keep above footer contents */
.poa-gt-docked{
  position:relative;
  z-index:9001; /* footer is 9000 */
  pointer-events:auto;
}

/* ===== Popup (Pure Black) + HIGH z-index ===== */
.poa-gt-popup{
  position:fixed;
  inset:0;
  background:#000;
  display:none;
  align-items:center;
  justify-content:center;
  z-index:20000; /* locked */
}

/* Panel */
.poa-gt-panel{
  background:#000;
  border:1px solid rgba(91,255,60,.25);
  border-radius:16px;
  padding:22px;
  width:min(1100px,94%);
  max-height:80vh;
  overflow:auto;
  -webkit-overflow-scrolling:touch; /* mobile inertia */
  scroll-behavior:smooth;
  box-shadow:
    0 0 0 1px rgba(214,179,90,.06),
    0 0 26px rgba(91,255,60,.10);
}

/* Title bar row optional spacing */
.poa-gt-hint{
  font-size:11px;
  color:rgba(91,255,60,.75);
  margin:0 0 14px 0;
  letter-spacing:.8px;
}

/* 5 columns grid */
.poa-gt-grid{
  display:grid;
  grid-template-columns:repeat(5,1fr);
  gap:14px;
}
@media(max-width:900px){ .poa-gt-grid{grid-template-columns:repeat(3,1fr);} }
@media(max-width:600px){ .poa-gt-grid{grid-template-columns:repeat(2,1fr);} }

.poa-gt-item{
  display:flex;
  align-items:center;
  gap:10px;
  padding:8px 10px;
  border:1px solid rgba(91,255,60,.25);
  border-radius:12px;
  cursor:pointer;
  font-size:12px;
  background:#000;
  color:#5BFF3C;
  transition:.15s ease;
  user-select:none;
}
.poa-gt-item:hover{
  background:rgba(91,255,60,.08);
  box-shadow:0 0 16px rgba(91,255,60,.12);
}
.poa-gt-item.active{
  border-color:rgba(214,179,90,.70);
  background:rgba(214,179,90,.08);
  box-shadow:0 0 18px rgba(214,179,90,.10);
}
.poa-gt-item img{
  width:20px;height:14px;border-radius:2px;object-fit:cover;
}

/* Close */
.poa-gt-close{
  margin-top:18px;
  text-align:center;
  cursor:pointer;
  color:#c7ff73;
  font-size:12px;
  padding:10px 0 0 0;
}
.poa-gt-close:hover{ opacity:.9; }
</style>

<div id="google_translate_element" style="display:none;"></div>

<!-- Button (floating by default; will dock into #poaFooterLeft if exists) -->
<button class="poa-gt-btn poa-gt-float poa-gt-nx" id="poaGtBtn" type="button" aria-haspopup="dialog" aria-controls="poaGtPopup">
  <span aria-hidden="true">🌍</span>
  <img id="poaGtFlag" src="/dashboard/assets/flags/gb.png" alt="">
  <span id="poaGtLabel">English</span>
</button>

<!-- Popup -->
<div class="poa-gt-popup poa-gt-nx" id="poaGtPopup" role="dialog" aria-modal="true">
  <div class="poa-gt-panel" id="poaGtPanel">
    <div class="poa-gt-hint">SELECT LANGUAGE (33)</div>
    <div class="poa-gt-grid" id="poaGtGrid"></div>
    <div class="poa-gt-close" id="poaGtCloseBtn">CLOSE</div>
  </div>
</div>

<script>
(function(){
  // Prevent duplicate loading even if partial is included twice
  if (window.__POADO_GT_BOOTED) return;
  window.__POADO_GT_BOOTED = true;

  /* 33 languages (locked list) */
  const POA_LANGS = [
    ['en','English'],['ms','Bahasa Melayu'],['zh-CN','简体中文'],['zh-TW','繁體中文'],
    ['id','Bahasa Indonesia'],['th','ภาษาไทย'],['vi','Tiếng Việt'],['tl','Filipino'],
    ['my','မြန်မာ'],['km','ភាសាខ្មែរ'],['ja','日本語'],['ko','한국어'],
    ['hi','हिन्दी'],['bn','বাংলা'],['ta','தமிழ்'],['ur','اردو'],
    ['ar','العربية'],['fa','فارسی'],['tr','Türkçe'],['ru','Русский'],
    ['uk','Українська'],['pl','Polski'],['de','Deutsch'],['fr','Français'],
    ['es','Español'],['pt','Português'],['nl','Nederlands'],['it','Italiano'],
    ['sv','Svenska'],['no','Norsk'],['da','Dansk'],['fi','Suomi'],['el','Ελληνικά']
  ];

  /* STRICT lang -> ISO2 flags (no guessing, no duplicates) */
  const POA_FLAG_ISO2 = {
    'en':'gb','ms':'my','zh-CN':'cn','zh-TW':'tw','id':'id','th':'th','vi':'vn','tl':'ph','my':'mm','km':'kh',
    'ja':'jp','ko':'kr','hi':'in','bn':'bd','ta':'in','ur':'pk','ar':'sa','fa':'ir','tr':'tr','ru':'ru','uk':'ua',
    'pl':'pl','de':'de','fr':'fr','es':'es','pt':'pt','nl':'nl','it':'it','sv':'se','no':'no','da':'dk','fi':'fi','el':'gr'
  };

  function poaFlagPathForLang(lang){
    const iso2 = POA_FLAG_ISO2[lang] || 'gb';
    return '/dashboard/assets/flags/' + iso2 + '.png';
  }

  /* ===== Popup controls ===== */
  function poaOpenGT(){
    const p = document.getElementById('poaGtPopup');
    if (p) p.style.display = 'flex';
  }
  function poaCloseGT(){
    const p = document.getElementById('poaGtPopup');
    if (p) p.style.display = 'none';
  }

  /* ===== Build grid (NO innerHTML to avoid SyntaxError) ===== */
  function poaBuildLangGrid(){
    const grid = document.getElementById('poaGtGrid');
    if(!grid) return;
    grid.textContent = '';

    const current = localStorage.getItem('poa_lang') || 'en';

    POA_LANGS.forEach(([lang,label])=>{
      const div = document.createElement('div');
      div.className = 'poa-gt-item' + (lang===current ? ' active' : '');

      const flag = poaFlagPathForLang(lang);

      // Build DOM safely (no HTML escaping issues)
      const img = document.createElement('img');
      img.src = flag;
      img.alt = '';
      img.onerror = () => { img.src = '/dashboard/assets/flags/gb.png'; };

      div.appendChild(img);
      div.appendChild(document.createTextNode(label));

      div.addEventListener('click', ()=>poaSetLang(lang,label,flag));
      grid.appendChild(div);
    });
  }

  /* ===== Google Translate init (element.js only) ===== */
  function googleTranslateElementInit(){
    try{
      const included = POA_LANGS.map(x=>x[0]).join(',');
      new google.translate.TranslateElement(
        { pageLanguage:'en', autoDisplay:false, includedLanguages: included },
        'google_translate_element'
      );
    }catch(e){}
  }
  window.googleTranslateElementInit = googleTranslateElementInit;

  (function loadGT(){
    // Avoid duplicate script injections
    if (document.querySelector('script[data-poado-gt-element="1"]')) return;

    const s = document.createElement('script');
    s.setAttribute('data-poado-gt-element','1');
    s.src = "//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit";
    document.body.appendChild(s);
  })();

  /* ===== Apply language robustly ===== */
  function poaSetCookie(lang){
    const v = (lang === 'en') ? '/en/en' : ('/en/' + lang);
    document.cookie = 'googtrans=' + v + ';path=/';
  }

  function poaApplyToCombo(lang){
    const combo = document.querySelector('.goog-te-combo');
    if(!combo) return false;
    combo.value = lang;
    combo.dispatchEvent(new Event('change'));
    return true;
  }

  function poaSetLang(lang, label, flag){
    localStorage.setItem('poa_lang', lang);

    const lbl = document.getElementById('poaGtLabel');
    const img = document.getElementById('poaGtFlag');
    if (lbl) lbl.textContent = label;
    if (img) img.src = flag;

    poaSetCookie(lang);

    // Try apply immediately; if GT not ready, retry a few times then hard refresh once
    let tries = 0;
    const maxTries = 18;
    (function retry(){
      tries++;
      if (poaApplyToCombo(lang)) {
        poaBuildLangGrid();
        poaCloseGT();
        return;
      }
      if (tries < maxTries) return setTimeout(retry, 250);

      // As last-resort, reload once (cookie persists)
      try{ poaBuildLangGrid(); }catch(e){}
      try{ poaCloseGT(); }catch(e){}
      location.reload();
    })();
  }

  /* ===== Dock button into footer-left slot if exists ===== */
  function poaDockGT(){
    const slot = document.getElementById('poaFooterLeft');
    const btn  = document.getElementById('poaGtBtn');
    if(slot && btn){
      btn.classList.remove('poa-gt-float');
      btn.classList.add('poa-gt-docked');
      slot.appendChild(btn);
    }
  }

  /* ===== Scroll inertia (desktop momentum) ===== */
  function poaEnableInertia(){
    const panel = document.getElementById('poaGtPanel');
    if(!panel) return;

    let target = 0;
    let raf = 0;

    function step(){
      raf = 0;
      // ease toward target
      const cur = panel.scrollTop;
      const next = cur + (target - cur) * 0.18;
      panel.scrollTop = next;

      if (Math.abs(target - next) > 0.5) raf = requestAnimationFrame(step);
    }

    panel.addEventListener('wheel', function(e){
      // allow normal scrolling but add momentum feel
      target = panel.scrollTop + e.deltaY * 0.9;
      if (!raf) raf = requestAnimationFrame(step);
    }, {passive:true});
  }

  /* ===== Auto slide-down: detect Google banner ===== */
  function poaBannerWatcher(){
    if (window.POA_GT_BANNER_OBS) return;
    window.POA_GT_BANNER_OBS = true;

    function update(){
      const banner = document.querySelector('.goog-te-banner-frame');
      document.body.classList.toggle('gt-topbar-shown', !!banner);
    }

    update();

    try{
      const obs = new MutationObserver(()=>update());
      obs.observe(document.documentElement, { childList:true, subtree:true });
    }catch(e){
      setInterval(update, 800);
    }
  }

  /* ===== Kill tooltip nodes that sometimes re-inject ===== */
  function poaTooltipKiller(){
    if (window.POA_GT_TTKILL) return;
    window.POA_GT_TTKILL = true;

    setInterval(function(){
      const tt = document.getElementById('goog-gt-tt');
      if (tt) tt.remove();
      const frames = document.querySelectorAll('iframe.goog-te-balloon-frame');
      frames.forEach(f=>{ try{ f.remove(); }catch(e){} });
    }, 1200);
  }

  document.addEventListener('DOMContentLoaded', function(){
    poaDockGT();

    // Bind click handlers (no inline onclick dependency)
    const btn = document.getElementById('poaGtBtn');
    if (btn) btn.addEventListener('click', poaOpenGT);

    const close = document.getElementById('poaGtCloseBtn');
    if (close) close.addEventListener('click', poaCloseGT);

    // Clicking outside panel closes
    const popup = document.getElementById('poaGtPopup');
    if (popup) popup.addEventListener('click', function(e){
      if (e.target === popup) poaCloseGT();
    });

    // Init label/flag from stored lang
    const current = localStorage.getItem('poa_lang') || 'en';
    const found = POA_LANGS.find(x=>x[0]===current) || POA_LANGS[0];
    const lbl = document.getElementById('poaGtLabel');
    const img = document.getElementById('poaGtFlag');
    if (lbl) lbl.textContent = found[1];
    if (img) img.src = poaFlagPathForLang(found[0]);

    // Build grid
    poaBuildLangGrid();

    // Enable inertia
    poaEnableInertia();

    // Watch banner + kill tooltip overlays
    poaBannerWatcher();
    poaTooltipKiller();
  });

})();
</script>
