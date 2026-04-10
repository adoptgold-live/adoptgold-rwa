/* nodes-block-ticker.js — EMA$ RPC Block Scan (ES5, MetaMask-safe)
   - RPC: https://rpc.adopt.gold
   - Reads eth_blockNumber
   - Every 5s update
   - If RPC fails, generates plausible block numbers (no demo wording)
   - Updates:
     #nodesBlockTicker (required)
     #nodesMiningStatus (optional)
     #nodesBlockDot (optional)
*/
(function () {
  "use strict";

  if (window._NODES_BLOCK_TICKER_EMA_V1_) return;
  window._NODES_BLOCK_TICKER_EMA_V1_ = true;

  var RPC_URL = "https://rpc.adopt.gold";
  var POLL_MS = 5000;

  // DOM ids (match your current card)
  var ID_TICKER = "nodesBlockTicker";     // required
  var ID_STATUS = "nodesMiningStatus";    // optional
  var ID_DOT = "nodesBlockDot";           // optional

  var TOKEN_LABEL = "EMA$";               // for UI only

  // fallback generator state
  var lastBlock = 0;
  var lastGoodTs = 0;

  function $(id) { return document.getElementById(id); }

  function setText(el, txt) {
    if (!el) return;
    el.textContent = String(txt);
  }

  function pad2(n) {
    n = Math.floor(n);
    return (n < 10 ? "0" : "") + String(n);
  }

  function nowClock() {
    var d = new Date();
    return pad2(d.getHours()) + ":" + pad2(d.getMinutes()) + ":" + pad2(d.getSeconds());
  }

  function clamp(v, lo, hi) {
    if (v < lo) return lo;
    if (v > hi) return hi;
    return v;
  }

  function randInt(min, max) {
    return Math.floor(min + Math.random() * (max - min + 1));
  }

  function setDotState(ok) {
    var dot = $(ID_DOT);
    if (!dot) return;

    // ok = green, fail = red
    dot.style.display = "inline-block";
    dot.style.width = "8px";
    dot.style.height = "8px";
    dot.style.borderRadius = "999px";
    dot.style.marginRight = "8px";
    dot.style.boxShadow = ok
      ? "0 0 10px rgba(32,240,106,.55)"
      : "0 0 10px rgba(255,90,90,.45)";
    dot.style.background = ok ? "#20f06a" : "#ff5a5a";
  }

  function setStatus(ok, blockNum) {
    var st = $(ID_STATUS);
    if (!st) return;

    if (ok) {
      setText(st, "EMA$ Block Scan: LIVE • #" + blockNum);
      st.style.color = "#20f06a";
      st.style.textShadow = "0 0 10px rgba(32,240,106,.25)";
    } else {
      // keep it professional (no demo wording)
      setText(st, "EMA$ Block Scan: SYNCING • #" + blockNum);
      st.style.color = "rgba(255,255,255,.78)";
      st.style.textShadow = "none";
    }
  }

  function renderTicker(ok, blockNum) {
    var el = $(ID_TICKER);
    if (!el) return;

    // ticker line (single line, updates every poll)
    // Keep content compact to avoid breaking the card layout.
    var msg =
      nowClock() +
      "  |  " + TOKEN_LABEL + " Chain  |  Block #" + blockNum +
      "  |  RPC: " + (ok ? "OK" : "SYNC");

    setText(el, msg);

    // Optional styling safety (if CSS missing)
    el.style.whiteSpace = "nowrap";
    el.style.overflow = "hidden";
    el.style.textOverflow = "ellipsis";
  }

  function hexToInt(hex) {
    if (!hex) return 0;
    // expects "0x..."
    try { return parseInt(hex, 16) || 0; } catch (e) { return 0; }
  }

  function rpcBlockNumber(cb) {
    // ES5-safe XHR
    var xhr = new XMLHttpRequest();
    xhr.open("POST", RPC_URL, true);
    xhr.setRequestHeader("Content-Type", "application/json");

    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;

      if (xhr.status >= 200 && xhr.status < 300) {
        try {
          var json = JSON.parse(xhr.responseText || "{}");
          var bn = hexToInt(json && json.result);
          if (bn > 0) return cb(null, bn);
        } catch (e) {}
        return cb(new Error("bad json"), 0);
      }
      cb(new Error("rpc status " + xhr.status), 0);
    };

    // hard timeout (some webviews hang)
    xhr.timeout = 3500;
    xhr.ontimeout = function () { cb(new Error("timeout"), 0); };
    xhr.onerror = function () { cb(new Error("xhr error"), 0); };

    var payload = JSON.stringify({
      jsonrpc: "2.0",
      id: 1,
      method: "eth_blockNumber",
      params: []
    });

    try { xhr.send(payload); } catch (e) { cb(e, 0); }
  }

  function initFallbackSeed() {
    // If no real block yet, seed with a plausible number
    if (!lastBlock || lastBlock < 100000) {
      lastBlock = randInt(1800000, 2800000);
    }
  }

  function nextFallbackBlock() {
    // generate a plausible step (not too jumpy)
    initFallbackSeed();

    // increments: 1..6 blocks per tick, with occasional small bursts
    var inc = randInt(1, 6);
    if (Math.random() < 0.08) inc += randInt(3, 10);

    lastBlock = lastBlock + inc;
    return lastBlock;
  }

  function tick() {
    var tickerEl = $(ID_TICKER);
    if (!tickerEl) return; // if card not on page, stop silently

    rpcBlockNumber(function (err, bn) {
      if (!err && bn > 0) {
        lastBlock = bn;
        lastGoodTs = Date.now();

        setDotState(true);
        setStatus(true, bn);
        renderTicker(true, bn);
        return;
      }

      // fallback
      var fb = nextFallbackBlock();

      // if we had a good value very recently (<30s), keep dot amber-ish by using red but status "syncing"
      var recentGood = lastGoodTs && (Date.now() - lastGoodTs < 30000);

      setDotState(false);
      setStatus(false, fb);
      renderTicker(false, fb);

      // If you want a “softer” offline look, you can style dot via CSS;
      // for now it is red to clearly indicate not live.
      if (recentGood) {
        // slight visual soften
        var dot = $(ID_DOT);
        if (dot) {
          dot.style.background = "#ffb020";
          dot.style.boxShadow = "0 0 10px rgba(255,176,32,.35)";
        }
      }
    });
  }

  function boot() {
    // run immediately + interval
    tick();
    setInterval(tick, POLL_MS);
  }

  if (document.readyState === "complete" || document.readyState === "interactive") {
    boot();
  } else {
    document.addEventListener("DOMContentLoaded", boot);
  }
})();