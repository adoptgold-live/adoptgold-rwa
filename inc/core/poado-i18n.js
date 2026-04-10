/* /dashboard/inc/poado-i18n.js
 * Manual terminology override layer (runs AFTER Google Translate).
 * - Fetches dict from /dashboard/api/i18n.php?lang=xx
 * - Replaces text nodes (excluding inputs/scripts/styles/notranslate)
 * - MutationObserver keeps it stable after GT re-renders
 */
(function () {
  "use strict";

  const API_BASE = "/dashboard/api/i18n.php";
  const CACHE = new Map();
  let lastLang = null;
  let applying = false;

  function getCookie(name) {
    const m = document.cookie.match(new RegExp("(^| )" + name + "=([^;]+)"));
    return m ? decodeURIComponent(m[2]) : "";
  }

  function detectLang() {
    // Prefer explicit html lang if set
    const htmlLang = (document.documentElement.getAttribute("lang") || "").trim();
    if (htmlLang) return htmlLang;

    // Google Translate cookie format: /en/zh-CN
    const gt = getCookie("googtrans");
    if (gt && gt.includes("/")) {
      const parts = gt.split("/");
      const maybe = parts[parts.length - 1];
      if (maybe) return maybe;
    }

    // Fallback
    return "en";
  }

  async function fetchDict(lang) {
    if (CACHE.has(lang)) return CACHE.get(lang);

    const url = API_BASE + "?lang=" + encodeURIComponent(lang);
    const res = await fetch(url, { credentials: "same-origin" });
    const js = await res.json();
    const dict = (js && js.ok && js.dict) ? js.dict : {};
    CACHE.set(lang, dict);
    return dict;
  }

  function shouldSkipNode(node) {
    if (!node || !node.parentElement) return true;

    const p = node.parentElement;

    // Skip within these tags
    const tag = (p.tagName || "").toUpperCase();
    if (tag === "SCRIPT" || tag === "STYLE" || tag === "TEXTAREA" || tag === "INPUT") return true;

    // Skip if inside notranslate
    if (p.closest(".notranslate")) return true;

    // Skip if hidden or empty
    const t = (node.nodeValue || "").trim();
    if (!t) return true;

    return false;
  }

  function applyDict(dict) {
    if (!dict || typeof dict !== "object") return;

    // Sort keys by length desc to prevent partial collisions
    const keys = Object.keys(dict).sort((a, b) => b.length - a.length);
    if (!keys.length) return;

    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null, false);

    let node;
    while ((node = walker.nextNode())) {
      if (shouldSkipNode(node)) continue;

      let text = node.nodeValue;
      let changed = false;

      for (const k of keys) {
        if (!k) continue;
        if (text.includes(k)) {
          text = text.split(k).join(dict[k]);
          changed = true;
        }
      }

      if (changed) node.nodeValue = text;
    }
  }

  async function run() {
    if (applying) return;
    applying = true;

    try {
      const lang = detectLang();
      if (!lang) return;

      if (lang !== lastLang) {
        lastLang = lang;
      }

      const dict = await fetchDict(lang);
      applyDict(dict);
    } catch (e) {
      // silent fail (must not break UI)
    } finally {
      applying = false;
    }
  }

  // Run after load + after GT render cycles
  function scheduleRun(delay) {
    setTimeout(run, delay);
  }

  // Initial runs
  scheduleRun(600);
  scheduleRun(1200);
  scheduleRun(2000);

  // Observe DOM changes (GT re-renders)
  const obs = new MutationObserver(() => {
    scheduleRun(300);
  });
  obs.observe(document.documentElement, { childList: true, subtree: true });

  // Expose manual trigger
  window.POADO_I18N_REFRESH = () => scheduleRun(50);
})();
