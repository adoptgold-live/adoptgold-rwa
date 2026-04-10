/* =========================================================
   nodes.js — V5 (LOCKED PUBLIC SITE)
   - RWA Adoption Nodes live counter + country/flag sync
   - Silent-signup DB write (wallet record) via /dashboard/api/session-wallet.php
   - NO /public paths. Flags: /assets/flags/{iso2}.png
   ========================================================= */

(() => {
  'use strict';

  /* -------------------------------
     CONFIG (V5)
  -------------------------------- */
  const BASE_COUNT = 1999;         // baseline (public display)
  const MAX_NODES  = 33000;
  const JITTER_MIN = -5;
  const JITTER_MAX = 5;
  const UPDATE_MS  = 5000;

  const FLAG_BASE_PATH = '/assets/flags/';
  const FLAG_FALLBACK  = 'xx.png'; // optional: if you don't have it, browser will just hide via onerror below

  // Silent signup endpoint (existing dashboard flow)
  const SILENT_ENDPOINT = '/dashboard/api/session-wallet.php';
  const SILENT_KEY      = 'adg_silent_signup_v5_done'; // localStorage marker (per wallet)

  /* -------------------------------
     COUNTRY POOL (ISO2 lowercase)
     You can extend this list safely.
  -------------------------------- */
  const COUNTRIES = [
    { iso2: 'my', name: 'Malaysia' },
    { iso2: 'cn', name: 'China' },
    { iso2: 'sg', name: 'Singapore' },
    { iso2: 'th', name: 'Thailand' },
    { iso2: 'id', name: 'Indonesia' },
    { iso2: 'vn', name: 'Vietnam' },
    { iso2: 'ph', name: 'Philippines' },
    { iso2: 'kr', name: 'South Korea' },
    { iso2: 'jp', name: 'Japan' },
    { iso2: 'us', name: 'United States' },
    { iso2: 'gb', name: 'United Kingdom' }
  ];

  /* -------------------------------
     DOM HOOKS (expected IDs)
     Supports both old/new IDs (safe).
  -------------------------------- */
  const elCounter = document.getElementById('nodesCounter') || document.getElementById('nodesCount');
  const elCountry = document.getElementById('nodesCountry');
  const elFlag    = document.getElementById('nodesCountryFlag') || document.getElementById('nodesFlag');
  const elTicker  = document.getElementById('nodesBlockTicker');
  const elRegBase = document.getElementById('regBase');
  const elOnline  = document.getElementById('onlineNow');
  const elFormula = document.getElementById('nodesFormula');

  if (!elCounter || !elCountry) {
    console.warn('[nodes.js] Required DOM elements missing: nodesCounter/nodesCountry');
    return;
  }

  /* -------------------------------
     HELPERS
  -------------------------------- */
  function randInt(min, max) {
    return Math.floor(Math.random() * (max - min + 1)) + min;
  }

  function pad(n, width) {
    const s = String(n);
    return s.length >= width ? s : ('0'.repeat(width - s.length) + s);
  }

  function normalizeIso2(x) {
    return (x || '').toString().trim().toLowerCase().replace(/[^a-z]/g, '').slice(0, 2) || 'us';
  }

  function pickCountry() {
    return COUNTRIES[randInt(0, COUNTRIES.length - 1)];
  }

  function setFlag(iso2) {
    if (!elFlag) return;
    const code = normalizeIso2(iso2);
    const src = FLAG_BASE_PATH + code + '.png';
    elFlag.src = src;

    elFlag.onerror = () => {
      // If fallback exists, show it; otherwise hide image gracefully.
      elFlag.onerror = null;
      elFlag.src = FLAG_BASE_PATH + FLAG_FALLBACK;
      elFlag.onerror = () => { elFlag.style.display = 'none'; };
    };
  }

  function setText(el, v) {
    if (!el) return;
    el.textContent = v;
  }

  /* -------------------------------
     Silent Signup (DB write)
     - Records wallet using existing /dashboard/api/session-wallet.php
     - Fires once per wallet (localStorage marker)
  -------------------------------- */
  async function silentSignup(wallet, chainId) {
    const w = (wallet || '').toLowerCase();
    if (!w || !w.startsWith('0x') || w.length < 10) return;

    const marker = SILENT_KEY + ':' + w;
    if (localStorage.getItem(marker) === '1') return;

    const payload = {
      wallet: w,
      chainId: chainId || null,
      source: 'public_index_v5',
      ts: Date.now()
    };

    // Best-effort POST JSON
    try {
      const res = await fetch(SILENT_ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify(payload)
      });
      if (res.ok) {
        localStorage.setItem(marker, '1');
        return;
      }
    } catch (e) {}

    // Fallback GET (if endpoint accepts it)
    try {
      const url = SILENT_ENDPOINT + '?wallet=' + encodeURIComponent(w) + (chainId ? ('&chainId=' + encodeURIComponent(chainId)) : '');
      const res2 = await fetch(url, { credentials: 'include' });
      if (res2.ok) {
        localStorage.setItem(marker, '1');
      }
    } catch (e) {}
  }

  async function tryDetectWalletAndSignup() {
    if (!window.ethereum) return;

    // If user already connected, accounts can be available.
    try {
      const accounts = await window.ethereum.request({ method: 'eth_accounts' });
      const chainId  = await window.ethereum.request({ method: 'eth_chainId' });
      if (accounts && accounts[0]) {
        await silentSignup(accounts[0], chainId);
      }
    } catch (e) {}

    // Listen for future connects
    try {
      if (window.ethereum.on) {
        window.ethereum.on('accountsChanged', async (accs) => {
          try {
            const chainId = await window.ethereum.request({ method: 'eth_chainId' });
            if (accs && accs[0]) await silentSignup(accs[0], chainId);
          } catch (e) {}
        });
      }
    } catch (e) {}
  }

  /* -------------------------------
     Nodes Simulation
  -------------------------------- */
  let current = BASE_COUNT + 3; // start with +3 example
  let lastCountry = pickCountry();

  function render() {
    // digits: keep 7–9 width depending on your design
    const width = (String(current).length < 7) ? 7 : String(current).length;
    setText(elCounter, pad(current, width));

    // Country label
    setText(elCountry, 'ACTIVE IN');

    // Flag
    setFlag(lastCountry.iso2);

    // Online jitter indicator (±5)
    const jitter = randInt(JITTER_MIN, JITTER_MAX);
    if (elOnline) setText(elOnline, (jitter >= 0 ? '+' : '') + jitter);

    // Reg base display
    if (elRegBase) setText(elRegBase, String(BASE_COUNT));

    // Formula
    if (elFormula) setText(elFormula, 'RWA Adoption Nodes: ' + current + ' / ' + MAX_NODES);

    // Block ticker (if present)
    if (elTicker) {
      const blk = Math.max(1, Math.floor(Date.now() / 10000));
      setText(elTicker, 'BLOCK: #' + blk + ' · SYNC OK');
    }
  }

  function tick() {
    current += randInt(JITTER_MIN, JITTER_MAX);
    if (current < BASE_COUNT) current = BASE_COUNT;
    if (current > MAX_NODES) current = MAX_NODES;

    if (Math.random() < 0.55) lastCountry = pickCountry();
    render();
  }

  // Init
  render();
  setInterval(tick, UPDATE_MS);

  // Silent signup integration (best-effort)
  tryDetectWalletAndSignup();

})();
