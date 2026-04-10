// /rwa/assets/js/profile-ton-bind.js
// v1.0.20260314-rwa-profile-ton-bind-final-reset-fix
(function () {
  'use strict';

  const $ = (id) => document.getElementById(id);

  const els = {
    btnBindTon: $('btnOpenTonModal'),
    btnResetTonSession: $('btnResetTonSession'),
    tonAddressText: $('tonAddressText'),
    tonStatusPill: $('tonStatusPill'),
    tonBindLabel: $('tonBindLabel'),
    tonBindNote: $('tonBindNote'),
    tonReadyText: $('tonReadyText'),
    btnCopyTon: $('btnCopyTon'),
    profileMsg: $('profileMsg'),
    csrfInput: document.querySelector('#profileForm [name="csrf_token"]')
  };

  if (!els.btnBindTon || !els.csrfInput) return;

  let tonConnectUI = null;
  let bindNonce = null;
  let bindPayload = null;

  function setProfileMsg(type, text) {
    if (!els.profileMsg) return;
    els.profileMsg.className = 'msg ' + type;
    els.profileMsg.textContent = text || '';
  }

  function normalizeAddress(v) {
    return String(v || '').trim();
  }

  function syncTonUi(addr) {
    const has = !!normalizeAddress(addr);

    if (els.tonAddressText) {
      els.tonAddressText.textContent = has ? normalizeAddress(addr) : '—';
    }

    if (els.tonStatusPill) {
      els.tonStatusPill.textContent = has ? 'BOUND' : 'NOT BOUND';
      els.tonStatusPill.className = 'pill ' + (has ? 'ok' : 'warn');
    }

    if (els.tonBindLabel) {
      els.tonBindLabel.textContent = has ? 'TON READY' : 'NO TON';
    }

    if (els.tonBindNote) {
      els.tonBindNote.textContent = has
        ? 'Primary ecosystem address is present.'
        : 'Bind TON to unlock Storage, Mining claim, Cert and Market.';
    }

    if (els.tonReadyText) {
      els.tonReadyText.textContent = has ? 'READY' : 'NEEDED';
    }

    if (els.btnBindTon) {
      els.btnBindTon.textContent = has ? 'RE-CHECK TON' : 'BIND TON';
    }

    if (els.btnCopyTon) {
      els.btnCopyTon.disabled = !has;
    }
  }

  async function postJson(url, data) {
    const res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify(data || {})
    });

    const text = await res.text();

    try {
      return JSON.parse(text);
    } catch (e) {
      return { ok: false, error: text || 'Invalid server response' };
    }
  }

  async function getNonce() {
    const js = await postJson('/rwa/api/profile/ton-bind-nonce.php', {
      csrf_token: els.csrfInput.value
    });

    if (!js || !js.ok) {
      throw new Error((js && (js.error || js.message)) ? (js.error || js.message) : 'Unable to prepare TON bind');
    }

    bindNonce = js.nonce || '';
    bindPayload = js.payload || '';

    if (!bindNonce || !bindPayload) {
      throw new Error('Invalid bind nonce response');
    }

    return js;
  }

  function ensureTonConnect() {
    if (tonConnectUI) return tonConnectUI;

    let TonConnectUICtor = null;

    if (
      typeof window.TON_CONNECT_UI !== 'undefined' &&
      window.TON_CONNECT_UI &&
      typeof window.TON_CONNECT_UI.TonConnectUI === 'function'
    ) {
      TonConnectUICtor = window.TON_CONNECT_UI.TonConnectUI;
    } else if (typeof window.TonConnectUI === 'function') {
      TonConnectUICtor = window.TonConnectUI;
    } else if (
      typeof window.TonConnectUI !== 'undefined' &&
      window.TonConnectUI &&
      typeof window.TonConnectUI.TonConnectUI === 'function'
    ) {
      TonConnectUICtor = window.TonConnectUI.TonConnectUI;
    }

    if (!TonConnectUICtor) {
      throw new Error('TonConnect UI library is not loaded');
    }

    tonConnectUI = new TonConnectUICtor({
      manifestUrl: window.location.origin + '/tonconnect-manifest.json'
    });

    return tonConnectUI;
  }

  function extractWalletAddress(result, ui) {
    const candidates = [
      result?.account?.address,
      result?.address,
      result?.wallet?.account?.address,
      result?.wallet?.address,
      ui?.account?.address,
      ui?.wallet?.account?.address,
      ui?.wallet?.address,
      window?.tonConnectUI?.wallet?.account?.address
    ];

    for (const value of candidates) {
      const addr = normalizeAddress(value);
      if (addr) return addr;
    }

    return '';
  }

  function extractProof(result, ui) {
    const candidates = [
      result?.connectItems?.tonProof?.proof,
      result?.tonProof?.proof,
      result?.tonProof,
      result?.wallet?.connectItems?.tonProof?.proof,
      result?.wallet?.tonProof?.proof,
      result?.wallet?.tonProof,
      ui?.wallet?.connectItems?.tonProof?.proof,
      ui?.wallet?.tonProof?.proof,
      ui?.wallet?.tonProof
    ];

    for (const item of candidates) {
      if (item && typeof item === 'object') return item;
    }

    return null;
  }

  async function connectWalletAndGetProof(payload) {
    const ui = ensureTonConnect();

    let result = null;

    if (typeof ui.connectWallet === 'function') {
      result = await ui.connectWallet({
        tonProof: payload
      });
    } else {
      throw new Error('TonConnect UI connectWallet method is not available');
    }

    const address = extractWalletAddress(result, ui);
    const proof = extractProof(result, ui);

    if (!address) {
      console.error('TON bind address extraction failed', { result, wallet: ui?.wallet, account: ui?.account });
      throw new Error('TON wallet address not returned');
    }

    if (!proof) {
      console.error('TON bind proof extraction failed', { result, wallet: ui?.wallet, account: ui?.account });
      throw new Error('TON wallet proof missing');
    }

    return { address, proof };
  }

  async function verifyAndBind(address, proof) {
    const js = await postJson('/rwa/api/profile/bind-ton.php', {
      csrf_token: els.csrfInput.value,
      address: address,
      proof: {
        ...proof,
        payload: bindPayload
      }
    });

    if (!js || !js.ok) {
      throw new Error((js && (js.error || js.message)) ? (js.error || js.message) : 'TON bind failed');
    }

    return js;
  }

  async function runTonBindFlow() {
    els.btnBindTon.disabled = true;
    setProfileMsg('warn', 'Opening TON wallet...');

    try {
      await getNonce();
      const walletData = await connectWalletAndGetProof(bindPayload);
      const js = await verifyAndBind(walletData.address, walletData.proof);

      syncTonUi(js.wallet_address || js.address || walletData.address);
      setProfileMsg('ok', js.msg || 'TON address bound successfully.');
    } catch (err) {
      setProfileMsg('err', (err && err.message) ? err.message : 'TON bind failed.');
    } finally {
      els.btnBindTon.disabled = false;
    }
  }

  async function runTonResetFlow() {
    if (els.btnResetTonSession) els.btnResetTonSession.disabled = true;
    setProfileMsg('warn', 'Resetting TON session...');

    try {
      const ui = ensureTonConnect();

      if (typeof ui.disconnect === 'function') {
        try { await ui.disconnect(); } catch (_) {}
      }

      try {
        localStorage.removeItem('ton-connect-storage_bridge-connection');
        localStorage.removeItem('ton-connect-ui_wallet-info');
        localStorage.removeItem('tonconnect-wallet');
        sessionStorage.removeItem('tonconnect-wallet');
      } catch (_) {}

      const res = await fetch('/rwa/auth/ton/reset.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      });

      const text = await res.text();
      let js = {};
      try { js = JSON.parse(text); } catch (_) {}

      syncTonUi('');
      bindNonce = null;
      bindPayload = null;

      setProfileMsg('ok', (js && js.msg) ? js.msg : 'TON session reset completed.');
    } catch (err) {
      setProfileMsg('err', (err && err.message) ? err.message : 'TON reset failed.');
    } finally {
      if (els.btnResetTonSession) els.btnResetTonSession.disabled = false;
    }
  }

  els.btnBindTon.addEventListener('click', runTonBindFlow);

  if (els.btnResetTonSession) {
    els.btnResetTonSession.addEventListener('click', runTonResetFlow);
  }
})();