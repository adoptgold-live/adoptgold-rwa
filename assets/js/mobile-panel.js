/* mobile-panel.js — FINAL (NO speed / NO vibe)
   Mobile-only mining bottom panel controller
   Works with:
   - mining-widget.js (window.MINING_API)
   - addon.js strict gate (window.APP_UNLOCKED + body.web3-on)
*/

(() => {
  if (window._MOBILE_PANEL_INIT_) return;
  window._MOBILE_PANEL_INIT_ = true;

  const $ = (s) => document.querySelector(s);

  const panel = $("#mobileMiningPanel");
  const btnOpen = $("#mobileMineOpen");
  const btnFull = $("#mobileMineFull");

  if (!panel) return;

  function unlocked() {
    return window.APP_UNLOCKED === true || document.body.classList.contains("web3-on");
  }

  function triggerConnect() {
    $("#connectBtn")?.click();
    $("#connectBtn")?.scrollIntoView?.({ behavior: "smooth", block: "center" });
  }

  function openMiningModal() {
    const api = window.MINING_API;
    if (!api || typeof api.openModal !== "function") return;
    api.openModal();
  }

  btnOpen?.addEventListener("click", () => {
    if (!unlocked()) return triggerConnect();
    openMiningModal();
  });

  btnFull?.addEventListener("click", () => {
    if (!unlocked()) return triggerConnect();
    openMiningModal();
  });

  // react to app lock/unlock
  window.addEventListener("app:unlock", (e) => {
    const isOn = !!e?.detail?.unlocked;
    panel.classList.toggle("locked", !isOn);
  });

  // mobile-only visibility
  function updateVisibility() {
    const isMobile = window.matchMedia("(max-width: 768px)").matches;
    panel.style.display = isMobile ? "block" : "none";
    panel.setAttribute("aria-hidden", isMobile ? "false" : "true");
  }

  updateVisibility();
  window.addEventListener("resize", updateVisibility, { passive: true });
})();
