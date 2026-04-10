/* mining-widget.js — FINAL PRODUCTION v4670 (MetaMask/TokenPocket SAFE ES5)
   - Mining NEVER starts until Web3 connected (poll every 600ms + provider events)
   - Button UX:
       • Click when NOT connected: click sound + trigger connectBtn + notice
       • Click when connected (1st): arm (green glow) + notice
       • Click when connected (2nd): open modal
       • Double-click: start mining + open modal (connected only)
   - Status DOT beside button:
       • no web3: grey
       • connected + not mining: red (stopped)
       • mining: green
   - Battery bar loops fast to 100% then resets to 0% (never stops at 100)
     (top widget + modal)
   - Modal UX injected (centered, larger, red close X, glow when mining)
   - Typing marquee (rotating msgs, restart 30s, soft typing sound)
     pauses when modal closed
   - Exposes window.MINING.{wemsTotal,isOn()} and calls window.setMiningOn(on) if exists
*/

/* REQUIRED HTML IDs (must exist, or will be auto-created where possible)
   - miningWidget (container)
   - miningBtn (the "Mining Gold Now" button)
   - topBatteryFill, topBatteryText (top widget battery)
   - wemsCount (wEMS generated number)
   - miningModal (modal overlay container)  [can be empty; we scaffold inside]
   Optional (if you already have them, we reuse):
   - miningClose (close button)
   - miningNotice (top notice text)
   - miningStatusText (modal notice text)
   - batteryFill, batteryText (modal battery)
   - miningMarquee (typing text area)
   - connectBtn (your existing "Connect Wallet" button)
*/

(function () {
  "use strict";

  if (window.MINING_WIDGET_PROD_4670) return;
  window.MINING_WIDGET_PROD_4670 = true;

  // ---------------- IDs ----------------
  var ID_WIDGET = "miningWidget";
  var ID_BTN = "miningBtn";
  var ID_TOP_FILL = "topBatteryFill";
  var ID_TOP_TEXT = "topBatteryText";
  var ID_TOP_NOTICE = "miningNotice"; // optional
  var ID_WEMS = "wemsCount";

  var ID_MODAL = "miningModal";
  var ID_MODAL_CLOSE = "miningClose";
  var ID_STATUS = "miningStatusText";
  var ID_BATTERY_FILL = "batteryFill";
  var ID_BATTERY_TEXT = "batteryText";
  var ID_MARQUEE = "miningMarquee";

  // Connect button id (your page)
  var ID_CONNECT_BTN = "connectBtn";

  // ---------------- Config ----------------
  var WEB3_POLL_MS = 600;

  var TICK_MS = 10000;
  var WEMS_PER_TICK = 0.033;
  var DAILY_CAP = 300;

  // Battery (fast loop)
  var BATTERY_TICK_MS = 180;
  var BATTERY_STEP_ON = 9; // fast feel

  // Typing board
  var TYPE_SPEED_MS = 22;
  var TYPE_RESTART_MS = 30000;
  var TYPE_PAUSE_BETWEEN_MS = 800;

  var CLASS_MINING_ON = "mining-on";

  // ---------------- State ----------------
  var mining = false;
  var wemsTotal = 0;
  var battery = 0;
  var trialTimer = 0;

  var web3Connected = false;
  var web3Address = "";
  var web3ChainId = "";

  var timerMine = 0;
  var timerBattery = 0;

  var _btnArmed = false;

  // typing state
  var typingActive = false;
  var typingPaused = true;
  var typingTimer = 0;
  var typingRestartTimer = 0;
  var typingMsgIndex = 0;
  var typingCharIndex = 0;

  // Expose
  window.MINING = window.MINING || {};
  window.MINING.wemsTotal = 0;
  window.MINING.isOn = function () { return !!mining; };

  // ---------------- Helpers ----------------
  function $(id) { return document.getElementById(id); }

  function setText(el, txt) {
    if (!el) return;
    el.textContent = String(txt);
  }

  function clamp(v, lo, hi) {
    if (v < lo) return lo;
    if (v > hi) return hi;
    return v;
  }

  function fmt(n, decimals) {
    decimals = typeof decimals === "number" ? decimals : 3;
    var p = Math.pow(10, decimals);
    return (Math.round(n * p) / p).toFixed(decimals);
  }

  function addClass(el, c) {
    if (!el || !el.classList) return;
    el.classList.add(c);
  }

  function removeClass(el, c) {
    if (!el || !el.classList) return;
    el.classList.remove(c);
  }

  function hasClass(el, c) {
    if (!el || !el.classList) return false;
    return el.classList.contains(c);
  }

  function isModalOpen() {
    var m = $(ID_MODAL);
    if (!m) return false;
    if (m.style && m.style.display === "none") return false;
    if (m.getAttribute("aria-hidden") === "true") return false;
    return true;
  }

  // ---------------- Chart hook ----------------
  function callChartHook(on) {
    try {
      if (typeof window.setMiningOn === "function") window.setMiningOn(!!on);
    } catch (e) {}
  }

  // ---------------- WebAudio sounds ----------------
  var audioCtx = null;
  function getAudioCtx() {
    if (audioCtx) return audioCtx;
    try {
      audioCtx = new (window.AudioContext || window.webkitAudioContext)();
      return audioCtx;
    } catch (e) { return null; }
  }

  function beep(freq, durMs, gainVal, type) {
    var ctx = getAudioCtx();
    if (!ctx) return;
    if (ctx.state === "suspended") { try { ctx.resume(); } catch (e) {} }
    try {
      var o = ctx.createOscillator();
      var g = ctx.createGain();
      o.type = type || "square";
      o.frequency.value = freq;
      g.gain.value = gainVal;
      o.connect(g);
      g.connect(ctx.destination);
      o.start();
      o.stop(ctx.currentTime + (durMs / 1000));
    } catch (e) {}
  }

  function playClickSound() { beep(880, 40, 0.03, "square"); }
  function playStartSound() {
    beep(660, 60, 0.04, "square");
    setTimeout(function () { beep(990, 60, 0.04, "square"); }, 70);
  }
  function playTypingSound() { beep(1800, 14, 0.008, "square"); }

  // ---------------- CSS inject (modal + glow + clickability) ----------------
  function injectCSS() {
    if (document.getElementById("mw_prod_4670_css")) return;

    var css = ""
      + "/* mining widget production css v4670 */"
      + "#"+ID_MODAL+".mining-modal{position:fixed;inset:0;z-index:999999;display:none;"
      + "background:rgba(0,0,0,.55);backdrop-filter:blur(2px);}"
      + ".mining-modal-card{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);"
      + "width:min(860px,92vw);max-height:84vh;overflow:auto;"
      + "border-radius:18px;padding:16px 16px 18px;"
      + "border:1px solid rgba(212,175,55,.28);"
      + "background:linear-gradient(180deg,rgba(18,18,22,.96),rgba(10,10,12,.98));"
      + "box-shadow:0 18px 70px rgba(0,0,0,.55), 0 0 22px rgba(32,240,106,.08);}"
      + ".mining-modal-card.mining-glow{box-shadow:0 18px 70px rgba(0,0,0,.55),0 0 26px rgba(32,240,106,.22);}"
      + ".mining-modal-head{display:flex;align-items:center;justify-content:space-between;gap:10px;"
      + "padding-bottom:10px;margin-bottom:10px;border-bottom:1px solid rgba(255,255,255,.08);}"
      + ".mining-modal-title{font-weight:900;letter-spacing:.6px;color:#d4af37;}"
      + ".mining-close{width:42px;height:42px;border-radius:12px;cursor:pointer;"
      + "border:1px solid rgba(255,80,80,.55);background:rgba(255,70,70,.12);"
      + "color:#ff5a5a;font-size:22px;line-height:1;font-weight:900;}"
      + ".mining-close:hover{background:rgba(255,70,70,.18);}"
      + ".mining-sub{opacity:.92;color:#cfcfcf;font-weight:800;margin:10px 0 8px;}"
      + ".mining-marquee{font-family:ui-monospace,Menlo,Monaco,Consolas,\"Liberation Mono\",\"Courier New\",monospace;"
      + "font-size:13px;line-height:1.55;padding:12px 12px;border-radius:14px;"
      + "border:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.03);"
      + "color:#d6d6d6;text-shadow:0 0 10px rgba(32,240,106,.06);}"
      + ".mining-marquee.mining-on{color:#20f06a;text-shadow:0 0 12px rgba(32,240,106,.25);}"
      + ".mining-status{margin:12px 0 10px;color:#cfcfcf;opacity:.92;font-weight:800;}"
      + ".mining-status.mining-on{color:#20f06a;}"
      + ".mining-battery{display:flex;align-items:center;gap:10px;margin:12px 0 10px;}"
      + ".mining-bar{flex:1;height:12px;border-radius:999px;overflow:hidden;"
      + "background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.10);}"
      + ".mining-bar>i{display:block;height:100%;width:0%;"
      + "background:linear-gradient(90deg,rgba(212,175,55,.85),rgba(32,240,106,.85));}"
      + ".mining-bpct{min-width:44px;text-align:right;font-weight:900;color:#cfcfcf;}"
      + ".mining-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;}"
      + ".mining-actions button{border-radius:999px;padding:10px 16px;font-weight:900;cursor:pointer;"
      + "border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.04);color:#fff;}"
      + ".mining-actions button:hover{background:rgba(255,255,255,.08);}"
      + ".mining-btn-armed{box-shadow:0 0 18px rgba(32,240,106,.25);border-color:rgba(32,240,106,.55)!important;}"
      + ".mining-dot{width:10px;height:10px;border-radius:999px;display:inline-block;vertical-align:middle;"
      + "margin-right:10px;border:1px solid rgba(255,255,255,.18);}"
      + "/* ensure button clickable */"
      + "#"+ID_BTN+"{pointer-events:auto!important;touch-action:manipulation;}"
      + "";

    var style = document.createElement("style");
    style.id = "mw_prod_4670_css";
    style.type = "text/css";
    style.appendChild(document.createTextNode(css));
    document.head.appendChild(style);
  }

  // ---------------- Modal scaffold (non-destructive) ----------------
  function ensureModalScaffold() {
    var modal = $(ID_MODAL);
    if (!modal) return;

    if (!modal.className || modal.className.indexOf("mining-modal") === -1) {
      modal.className = (modal.className ? modal.className + " " : "") + "mining-modal";
    }

    // Card wrapper
    var card = modal.querySelector(".mining-modal-card");
    if (!card) {
      card = document.createElement("div");
      card.className = "mining-modal-card";
      while (modal.firstChild) card.appendChild(modal.firstChild);
      modal.appendChild(card);
    }

    // Header
    var head = card.querySelector(".mining-modal-head");
    if (!head) {
      head = document.createElement("div");
      head.className = "mining-modal-head";

      var title = document.createElement("div");
      title.className = "mining-modal-title";
      title.textContent = "WEB3 GOLD MINING REWARD";

      var close = document.createElement("button");
      close.id = ID_MODAL_CLOSE;
      close.className = "mining-close";
      close.type = "button";
      close.setAttribute("aria-label", "Close");
      close.textContent = "×";

      head.appendChild(title);
      head.appendChild(close);
      card.insertBefore(head, card.firstChild);
    }

    // Sub + marquee
    if (!$(ID_MARQUEE)) {
      var sub = document.createElement("div");
      sub.className = "mining-sub";
      sub.textContent = "RWA Adoption • RK92-EMA Genesis";

      var mq = document.createElement("div");
      mq.id = ID_MARQUEE;
      mq.className = "mining-marquee";
      mq.textContent = ""; // typing fills it

      card.insertBefore(sub, head.nextSibling);
      card.insertBefore(mq, sub.nextSibling);
    }

    // Status
    if (!$(ID_STATUS)) {
      var st = document.createElement("div");
      st.id = ID_STATUS;
      st.className = "mining-status";
      st.textContent = "Connect Wallet to start mining.";
      card.appendChild(st);
    }

    // Battery
    if (!$(ID_BATTERY_FILL)) {
      var sec = document.createElement("div");
      sec.className = "mining-battery";

      var bar = document.createElement("div");
      bar.className = "mining-bar";
      var i = document.createElement("i");
      i.id = ID_BATTERY_FILL;
      bar.appendChild(i);

      var pct = document.createElement("div");
      pct.id = ID_BATTERY_TEXT;
      pct.className = "mining-bpct";
      pct.textContent = "0%";

      sec.appendChild(bar);
      sec.appendChild(pct);
      card.appendChild(sec);
    }

    // If your Pause/Resume/Reset already exist, we don’t overwrite them.
    // If not, you can add a .mining-actions block in HTML.
  }

  // ---------------- Notice + UI updates ----------------
  function setNotice(msg) {
    var st = $(ID_STATUS);
    var topN = $(ID_TOP_NOTICE);
    if (st) setText(st, msg);
    if (topN) setText(topN, msg);
  }

  function updateWemsUI() {
    var w = $(ID_WEMS);
    if (w) setText(w, fmt(wemsTotal, 3));
  }

  function updateBatteryUI() {
    var fillTop = $(ID_TOP_FILL);
    var textTop = $(ID_TOP_TEXT);

    if (fillTop) fillTop.style.width = String(battery) + "%";
    if (textTop) setText(textTop, String(battery) + "%");

    var fillM = $(ID_BATTERY_FILL);
    var textM = $(ID_BATTERY_TEXT);

    if (fillM) fillM.style.width = String(battery) + "%";
    if (textM) setText(textM, String(battery) + "%");
  }

  function applyMiningClass(on) {
    var w = $(ID_WIDGET);
    if (on) {
      addClass(w, CLASS_MINING_ON);
      if (document.body) addClass(document.body, CLASS_MINING_ON);
    } else {
      removeClass(w, CLASS_MINING_ON);
      if (document.body) removeClass(document.body, CLASS_MINING_ON);
    }

    // modal glow
    var m = $(ID_MODAL);
    if (m) {
      var card = m.querySelector(".mining-modal-card");
      if (card) {
        if (on) addClass(card, "mining-glow");
        else removeClass(card, "mining-glow");
      }
    }

    // status green
    var st = $(ID_STATUS);
    if (st) {
      if (on) addClass(st, "mining-on");
      else removeClass(st, "mining-on");
    }

    // marquee green
    var mq = $(ID_MARQUEE);
    if (mq) {
      if (on) addClass(mq, "mining-on");
      else removeClass(mq, "mining-on");
    }
  }

  function ensureDot(btn) {
    if (!btn) return null;
    var dot = btn.querySelector(".mining-dot");
    if (!dot) {
      dot = document.createElement("span");
      dot.className = "mining-dot";
      btn.insertBefore(dot, btn.firstChild);
    }
    return dot;
  }

  function updateDotStatus() {
    var btn = $(ID_BTN);
    if (!btn) return;
    var dot = ensureDot(btn);
    if (!dot) return;

    if (!window.ethereum) {
      // no provider
      dot.style.background = "rgba(200,200,200,0.55)";
      dot.style.boxShadow = "none";
      return;
    }

    if (!web3Connected) {
      dot.style.background = "rgba(200,200,200,0.55)";
      dot.style.boxShadow = "none";
      return;
    }

    if (mining) {
      dot.style.background = "#20f06a";
      dot.style.boxShadow = "0 0 12px rgba(32,240,106,.35)";
      return;
    }

    // connected but stopped
    dot.style.background = "#ff4d4d";
    dot.style.boxShadow = "0 0 10px rgba(255,77,77,.22)";
  }

  function armButton(btn) {
    _btnArmed = true;
    addClass(btn, "mining-btn-armed");
    btn.style.color = "#20f06a";
  }

  function disarmButton(btn) {
    _btnArmed = false;
    removeClass(btn, "mining-btn-armed");
    btn.style.color = "";
  }

  // ---------------- Modal open/close (fix aria/focus warning) ----------------
  function openModal() {
    var m = $(ID_MODAL);
    if (!m) return;

    // remove aria-hidden before focusing any child
    m.style.display = "block";
    m.setAttribute("aria-hidden", "false");

    // allow typing
    typingPaused = false;
    startTypingLoop();

    // focus close button for accessibility
    setTimeout(function () {
      var c = $(ID_MODAL_CLOSE);
      if (c && c.focus) { try { c.focus(); } catch (e) {} }
    }, 0);
  }

  function closeModal() {
    var m = $(ID_MODAL);
    if (!m) return;

    // stop typing
    typingPaused = true;
    stopTypingLoop();

    m.setAttribute("aria-hidden", "true");
    m.style.display = "none";

    // return focus to main button (avoids aria-hidden focus retained warning)
    setTimeout(function () {
      var btn = $(ID_BTN);
      if (btn && btn.focus) { try { btn.focus(); } catch (e) {} }
    }, 0);
  }

  function bindOverlayClose() {
    var m = $(ID_MODAL);
    if (!m || m.dataset.overlayBound) return;
    m.dataset.overlayBound = "1";

    m.addEventListener("click", function (e) {
      if (e && e.target === m) closeModal();
    });

    // ESC close
    document.addEventListener("keydown", function (e) {
      e = e || window.event;
      if (!isModalOpen()) return;
      var k = e.key || e.keyCode;
      if (k === "Escape" || k === "Esc" || k === 27) closeModal();
    });
  }

  // ---------------- Mining + loops ----------------
  function stopMineLoop() {
    if (timerMine) { clearInterval(timerMine); timerMine = 0; }
  }

  function startMineLoop() {
    stopMineLoop();
    timerMine = setInterval(function () {
      if (!mining) return;

      if (wemsTotal >= DAILY_CAP) {
        setMining(false, "Daily cap reached. Mining paused.");
        return;
      }

      wemsTotal += WEMS_PER_TICK;
      window.MINING.wemsTotal = wemsTotal;
      updateWemsUI();
    }, TICK_MS);
  }

  function stopBatteryLoop() {
    if (timerBattery) { clearInterval(timerBattery); timerBattery = 0; }
  }

  function startBatteryLoop() {
    stopBatteryLoop();
    timerBattery = setInterval(function () {
      // Battery should feel alive only when mining ON
      if (!mining) return;

      battery += BATTERY_STEP_ON;
      if (battery >= 100) battery = 0; // never stop at 100
      battery = clamp(battery, 0, 100);
      updateBatteryUI();
    }, BATTERY_TICK_MS);
  }

  function setMining(on, reasonMsg) {
    on = !!on;

    // Hard rule: cannot start without web3
    if (on && !web3Connected) {
      setNotice("Connect Wallet to start mining.");
      applyMiningClass(false);
      callChartHook(false);
      updateDotStatus();
      return;
    }

    mining = on;

    if (mining) {
      playStartSound();
      setNotice("WEB3 Mining Active...");
      applyMiningClass(true);
      callChartHook(true);
      startMineLoop();
      startBatteryLoop();
    } else {
      stopMineLoop();
      stopBatteryLoop();
      applyMiningClass(false);
      callChartHook(false);

      setNotice(
        reasonMsg ||
          (web3Connected ? "Ready to Mine. Click to start (1 min trial)." : "Connect Wallet to start mining.")
      );
    }

    updateDotStatus();
  }


  function stopTrialTimer() {
    if (trialTimer) { clearTimeout(trialTimer); trialTimer = 0; }
  }

  function startTrial60s() {
    stopTrialTimer();
    // Start mining and auto-stop after 60s
    setMining(true);
    trialTimer = setTimeout(function () {
      setMining(false, "Trial complete. You can start again anytime.");
      stopTrialTimer();
    }, 60 * 1000);
  }

  // ---------------- Typing notice board ----------------
  function getMessages() {
    return [
      "WEB3 Gold Mining Reward Mode | wEMS mined every 10s | Daily cap: 300 wEMS/address | Network cap: 3,000,000 wEMS/day",
      "Genesis RWA: RK92-EMA Gold Mining | Stake EMA → Mine wEMS → R92K-EMA Gold in Progress",
      "Target: 30,000 Tons of RWA Gold (~USD 2.1B) | EMA Burned, Supply Shrinking Toward 21,000",
      "Value Accumulation → EMX Digital Bank | Powered by the RWA Adoption Economy | Long-Term RWA Value Engine Activated"
    ];
  }

  function stopTypingLoop() {
    typingActive = false;
    if (typingTimer) { clearTimeout(typingTimer); typingTimer = 0; }
    if (typingRestartTimer) { clearTimeout(typingRestartTimer); typingRestartTimer = 0; }
  }

  function startTypingLoop() {
    if (typingPaused) return;
    if (!isModalOpen()) return;

    var el = $(ID_MARQUEE);
    if (!el) return;

    if (typingActive) return;
    typingActive = true;

    var msgs = getMessages();
    if (!msgs.length) return;

    if (typingMsgIndex >= msgs.length) typingMsgIndex = 0;

    function tick() {
      if (!typingActive) return;
      if (typingPaused || !isModalOpen()) { stopTypingLoop(); return; }

      var msg = msgs[typingMsgIndex] || "";

      if (typingCharIndex === 0) el.textContent = "";

      if (typingCharIndex < msg.length) {
        el.textContent = msg.slice(0, typingCharIndex + 1);
        playTypingSound();
        typingCharIndex += 1;
        typingTimer = setTimeout(tick, TYPE_SPEED_MS);
        return;
      }

      typingCharIndex = 0;
      typingMsgIndex = (typingMsgIndex + 1) % msgs.length;
      typingTimer = setTimeout(tick, TYPE_PAUSE_BETWEEN_MS);
    }

    tick();

    typingRestartTimer = setTimeout(function () {
      if (!typingActive) return;
      typingMsgIndex = 0;
      typingCharIndex = 0;
      el.textContent = "";
      // tick loop continues
    }, TYPE_RESTART_MS);
  }

  // ---------------- Web3 state (poll 600ms + events) ----------------
  function readWeb3State(cb) {
    var provider = window.ethereum;
    if (!provider || !provider.request) {
      cb(false, "", "");
      return;
    }

    // eth_accounts = no popup (MetaMask + TokenPocket usually support)
    provider.request({ method: "eth_accounts" }).then(function (accounts) {
      var connected = !!(accounts && accounts.length);
      var addr = connected ? (accounts[0] || "") : "";

      provider.request({ method: "eth_chainId" }).then(function (cid) {
        cb(connected, addr, cid || "");
      }).catch(function () {
        cb(connected, addr, "");
      });
    }).catch(function () {
      cb(false, "", "");
    });
  }

  function onWeb3Changed(connected, addr, chainId) {
    var changed =
      (web3Connected !== !!connected) ||
      (web3Address !== (addr || "")) ||
      (web3ChainId !== (chainId || ""));

    web3Connected = !!connected;
    web3Address = addr || "";
    web3ChainId = chainId || "";

    if (!changed) return;

    // If web3 drops while mining => stop (hard rule)
    if (!web3Connected && mining) {
      setMining(false, "Web3 disconnected. Mining stopped.");
    } else if (web3Connected && !mining) {
      setNotice("Ready to Mine. Click to start (1 min trial).");
    } else if (!web3Connected && !mining) {
      setNotice("Connect Wallet to start mining.");
    }

    // if web3 not connected, disarm button
    var btn = $(ID_BTN);
    if (btn && !web3Connected) disarmButton(btn);

    updateDotStatus();
  }

  function pollWeb3() {
    readWeb3State(function (connected, addr, chainId) {
      onWeb3Changed(connected, addr, chainId);
    });
  }

  function bindProviderEvents() {
    var provider = window.ethereum;
    if (!provider || provider._mwBound4670) return;
    provider._mwBound4670 = true;

    // MetaMask/TP: accountsChanged + chainChanged
    if (provider.on) {
      try {
        provider.on("accountsChanged", function () { pollWeb3(); });
        provider.on("chainChanged", function () { pollWeb3(); });
        provider.on("disconnect", function () { onWeb3Changed(false, "", ""); });
        provider.on("connect", function () { pollWeb3(); });
      } catch (e) {}
    }
  }

  // ---------------- Bind events ----------------
  function bindOnce() {
    var btn = $(ID_BTN);
    if (btn && !btn.dataset.bound4670) {
      btn.dataset.bound4670 = "1";

      // Ensure dot exists immediately
      ensureDot(btn);
      updateDotStatus();

      btn.addEventListener("click", function (e) {
        // Always allow click even if nested in topbar/menu
        try { if (e && e.stopPropagation) e.stopPropagation(); } catch(_){}
        playClickSound();

        // If NOT connected -> trigger connect, do not open modal
        if (!web3Connected) {
          setNotice("Connect Wallet to start mining.");
          try {
            var c = $(ID_CONNECT_BTN);
            if (c && c.click) c.click();
          } catch (e2) {}
          updateDotStatus();
          return;
        }

        // Connected: open panel + start 1-min trial immediately
        openModal();

        // If already mining, treat as stop
        if (mining) {
          stopTrialTimer();
          setMining(false, "Stopped. Click again to start a 1 min trial.");
          return;
        }

        startTrial60s();
      });

      // Dblclick: start mining + open modal
      btn.addEventListener("dblclick", function () {
        if (!web3Connected) return;
        openModal();
        startTrial60s();
      });
    }

    // close button
    var closeBtn = $(ID_MODAL_CLOSE);
    if (closeBtn && !closeBtn.dataset.bound4670) {
      closeBtn.dataset.bound4670 = "1";
      closeBtn.addEventListener("click", function () {
        playClickSound();
        closeModal();
      });
    }

    bindOverlayClose();

    // cleanup on pagehide
    window.addEventListener("pagehide", function () {
      stopMineLoop();
      stopBatteryLoop();
      stopTypingLoop();
    });
  }

  // ---------------- Boot ----------------
  function boot() {
    injectCSS();
    ensureModalScaffold();
    bindProviderEvents();

    // initial UI
    updateWemsUI();
    battery = 0;
    updateBatteryUI();

    // default notice
    setNotice("Connect Wallet to start mining.");
    applyMiningClass(false);
    updateDotStatus();

    // Web3 polling (600ms)
    pollWeb3();
    setInterval(pollWeb3, WEB3_POLL_MS);

    // Ensure mining off on load
    setMining(false);

    // Bind clicks
    bindOnce();

    // If modal already open
    if (isModalOpen()) {
      typingPaused = false;
      startTypingLoop();
    }
  }

  if (document.readyState === "complete" || document.readyState === "interactive") boot();
  else document.addEventListener("DOMContentLoaded", boot);

})();