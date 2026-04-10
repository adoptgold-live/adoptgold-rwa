<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

if (!function_exists('session_user_id') || (int)session_user_id() <= 0) {
    header('Location: /rwa/?m=login_required');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>TON Rebind Tester</title>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#09050f">
<style>
:root{
  --bg:#09050f;
  --panel:#120b1d;
  --line:rgba(173,112,255,.22);
  --text:#f6edff;
  --muted:#bca8df;
  --ok:#22c55e;
  --warn:#f59e0b;
  --bad:#ef4444;
}
*{box-sizing:border-box}
html,body{
  margin:0;
  background:
    radial-gradient(circle at top, rgba(124,77,255,.10), transparent 28%),
    linear-gradient(180deg,#09050f,#0b0613);
  color:var(--text);
  font-family:ui-monospace,Menlo,Consolas,monospace;
}
.wrap{
  width:min(1100px, calc(100% - 24px));
  margin:auto;
  padding:22px 0 40px;
}
.card{
  border:1px solid #4d3aff;
  border-radius:20px;
  padding:34px 36px;
  background:#0a0117;
}
h1{margin:0 0 16px;font-size:24px;letter-spacing:.03em}
.desc{line-height:1.7;font-size:14px;margin-bottom:26px}
.grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:26px 22px;
}
.box{
  border:1px solid #3920a8;
  border-radius:16px;
  padding:18px 18px 16px;
  min-height:110px;
}
.k{font-weight:700;font-size:15px;margin-bottom:8px}
.v{font-size:15px;line-height:1.65;word-break:break-all}
.actions{
  display:grid;
  grid-template-columns:1fr 1fr 1fr 1fr;
  gap:10px;
  margin-top:18px;
}
.btn{
  height:56px;border:none;border-radius:16px;font:inherit;font-size:14px;cursor:pointer;color:#fff;
  transition:transform .16s ease, opacity .16s ease;
}
.btn:hover{transform:translateY(-1px)}
.btn:disabled{opacity:.55;cursor:not-allowed;transform:none}
.bind{background:#7e5bff}
.rebind{background:#f37438}
.refresh{background:#2f9448}
.reset{background:#3b3640}
.status{
  margin-top:24px;border:1px solid #3c3550;border-radius:12px;min-height:46px;padding:12px 16px;
  display:flex;align-items:center;font-size:14px;
}
.status.info{color:#fff}
.status.ok{color:var(--ok)}
.status.warn{color:var(--warn)}
.status.bad{color:#ff5353}
.mount-label{margin-top:20px;color:#d7cfff;font-size:13px}
.mount{
  margin-top:10px;border:1px solid #3a2a7b;border-radius:16px;min-height:74px;padding:14px;display:flex;align-items:center;
}
.help{
  margin-top:18px;padding:14px 16px;border:1px dashed #4f45a3;border-radius:14px;font-size:13px;line-height:1.7;color:#ddd2ff;
}
.hidden{display:none !important}
@media (max-width:900px){
  .grid{grid-template-columns:1fr}
  .actions{grid-template-columns:1fr 1fr}
}
@media (max-width:620px){
  .card{padding:22px 16px}
  .actions{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>TON Bind / Rebind Tester</h1>
    <div class="desc">
      Bind and Rebind are separated actions. Rebind performs full reset of server bind session and TonConnect client session.
    </div>

    <div class="grid">
      <div class="box">
        <div class="k">Current Saved Wallet</div>
        <div class="v" id="boundWallet">-</div>
      </div>

      <div class="box">
        <div class="k">TonConnect Wallet</div>
        <div class="v" id="tcWallet">-</div>
      </div>

      <div class="box">
        <div class="k">Nonce Payload</div>
        <div class="v" id="noncePayload">-</div>
      </div>

      <div class="box">
        <div class="k">Proof State</div>
        <div class="v" id="proofState">Idle</div>
      </div>
    </div>

    <div class="actions">
      <button class="btn bind" id="bindBtn">Bind Wallet</button>
      <button class="btn rebind hidden" id="rebindBtn">Start Rebind</button>
      <button class="btn refresh" id="refreshBtn">Refresh Saved Wallet</button>
      <button class="btn reset" id="resetBtn">Reset TonConnect Session</button>
    </div>

    <div class="status info" id="statusBox">Idle</div>

    <div class="help">
      <strong>Rebind Guide</strong><br>
      1. Press <strong>Start Rebind</strong><br>
      2. Mobile TON wallet will open<br>
      3. Switch to the <strong>new TON wallet</strong> inside the wallet app<br>
      4. Approve the connection<br>
      5. Return here and press <strong>Refresh Saved Wallet</strong><br>
      6. Current Saved Wallet should change to the new address
    </div>

    <div class="mount-label">TonConnect mount root</div>
    <div class="mount" id="testerTonConnectRoot"></div>
  </div>
</div>

<script>
(() => {
  'use strict';

  const MANIFEST_URL = 'https://adoptgold.app/tonconnect-manifest.json';

  const API = {
    load: '/rwa/api/profile/load.php',
    reset: '/rwa/auth/ton/reset.php',
    nonce: '/rwa/auth/ton/nonce.php',
    bind: '/rwa/api/profile/bind-ton.php'
  };

  const state = {
    tc: null,
    listenerBound: false,
    waiting: false,
    finalizing: false,
    boundWallet: '',
    noncePayload: ''
  };

  const UI = {
    boundWallet: document.getElementById('boundWallet'),
    tcWallet: document.getElementById('tcWallet'),
    noncePayload: document.getElementById('noncePayload'),
    proofState: document.getElementById('proofState'),
    statusBox: document.getElementById('statusBox'),
    bindBtn: document.getElementById('bindBtn'),
    rebindBtn: document.getElementById('rebindBtn'),
    refreshBtn: document.getElementById('refreshBtn'),
    resetBtn: document.getElementById('resetBtn'),
    mount: document.getElementById('testerTonConnectRoot')
  };

  function setStatus(text, type = 'info') {
    UI.statusBox.className = 'status ' + type;
    UI.statusBox.textContent = text;
  }

  function isProbablyTonAddress(addr) {
    const value = String(addr || '').trim();
    return /^(EQ|UQ|kQ|0Q)[A-Za-z0-9_-]{40,}$/.test(value)
      || /^[0-9a-fA-F]{64}$/.test(value)
      || /^-?\d+:[0-9a-fA-F]{64}$/.test(value);
  }

  function updateActionMode() {
    const hasBound = !!String(state.boundWallet || '').trim();
    UI.bindBtn.classList.toggle('hidden', hasBound);
    UI.rebindBtn.classList.toggle('hidden', !hasBound);
  }

  async function fetchJson(url, options = {}) {
    const res = await fetch(url, {
      credentials: 'same-origin',
      cache: 'no-store',
      ...options,
      headers: {
        'Accept': 'application/json',
        ...(options.headers || {})
      }
    });

    const text = await res.text();
    if (text.trim().startsWith('<')) {
      throw new Error('Non-JSON response.');
    }

    let json;
    try {
      json = JSON.parse(text);
    } catch {
      throw new Error('Invalid JSON response.');
    }

    if (!res.ok || !json.ok) {
      throw new Error(json.error || json.message || ('HTTP ' + res.status));
    }
    return json;
  }

  async function loadProfile() {
    try {
      const j = await fetchJson(API.load);
      state.boundWallet = String(j?.user?.wallet_address || '').trim();
      UI.boundWallet.textContent = state.boundWallet || '-';
      updateActionMode();
      setStatus(state.boundWallet ? 'Saved wallet loaded.' : 'No saved wallet yet.', state.boundWallet ? 'ok' : 'warn');
    } catch (e) {
      state.boundWallet = '';
      UI.boundWallet.textContent = '-';
      updateActionMode();
      setStatus('Refresh failed: ' + e.message, 'bad');
    }
  }

  async function resetServerSession() {
    await fetchJson(API.reset, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: '{}'
    });
  }

  function clearTransientUi() {
    state.waiting = false;
    state.finalizing = false;
    state.noncePayload = '';
    UI.tcWallet.textContent = '-';
    UI.noncePayload.textContent = '-';
    UI.proofState.textContent = 'Idle';
  }

  function clearTonConnectStorageOnly() {
    try {
      Object.keys(localStorage).forEach(k => {
        if (k.toLowerCase().includes('tonconnect')) localStorage.removeItem(k);
      });
    } catch (_) {}

    try {
      Object.keys(sessionStorage).forEach(k => {
        if (k.toLowerCase().includes('tonconnect')) sessionStorage.removeItem(k);
      });
    } catch (_) {}
  }

  async function hardDisconnectTonConnect() {
    if (!state.tc) return;

    try {
      await state.tc.disconnect();
    } catch (_) {}

    await new Promise(r => setTimeout(r, 400));
    clearTonConnectStorageOnly();
    UI.tcWallet.textContent = '-';
  }

  async function fullReset() {
    await resetServerSession();
    await hardDisconnectTonConnect();
    clearTransientUi();
  }

  async function getNonce() {
    const j = await fetchJson(API.nonce, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: '{}'
    });

    const payload = String(j.payload || j.nonce || j.ton_proof_payload || '').trim();
    if (!payload) throw new Error('Nonce payload missing.');

    state.noncePayload = payload;
    UI.noncePayload.textContent = payload;
    return payload;
  }

  async function ensureTonConnect() {
    if (state.tc) return state.tc;

    if (!window.TON_CONNECT_UI || !window.TON_CONNECT_UI.TonConnectUI) {
      await new Promise((resolve, reject) => {
        const existing = document.querySelector('script[data-tonconnect-ui="1"]');
        if (existing) {
          let done = false;
          const tick = setInterval(() => {
            if (window.TON_CONNECT_UI && window.TON_CONNECT_UI.TonConnectUI) {
              clearInterval(tick);
              if (!done) {
                done = true;
                resolve();
              }
            }
          }, 120);
          setTimeout(() => {
            clearInterval(tick);
            if (!done) reject(new Error('TonConnect script load timeout.'));
          }, 8000);
          return;
        }

        const s = document.createElement('script');
        s.src = 'https://unpkg.com/@tonconnect/ui@2.0.9/dist/tonconnect-ui.min.js';
        s.async = true;
        s.dataset.tonconnectUi = '1';
        s.onload = resolve;
        s.onerror = () => reject(new Error('TonConnect script load failed.'));
        document.head.appendChild(s);
      });
    }

    state.tc = new window.TON_CONNECT_UI.TonConnectUI({
      manifestUrl: MANIFEST_URL,
      buttonRootId: 'testerTonConnectRoot'
    });

    if (!state.listenerBound) {
      state.tc.onStatusChange(async wallet => {
        if (!wallet || !wallet.account) return;
        UI.tcWallet.textContent = String(wallet.account.address || '-');

        if (state.waiting) {
          await finalizeBind(wallet);
        }
      });
      state.listenerBound = true;
    }

    return state.tc;
  }

  async function finalizeBind(walletObj) {
    if (state.finalizing) return;
    state.finalizing = true;

    try {
      const address = String(walletObj?.account?.address || '').trim();
      const tonProof = walletObj?.connectItems?.tonProof || walletObj?.tonProof || null;

      if (!address || !isProbablyTonAddress(address)) {
        throw new Error('Invalid TON wallet address.');
      }

      if (!tonProof || !(tonProof.proof || tonProof.signature || tonProof.timestamp || tonProof.payload)) {
        UI.proofState.textContent = 'Waiting wallet proof';
        state.finalizing = false;
        return;
      }

      UI.proofState.textContent = 'Submitting proof';

      const j = await fetchJson(API.bind, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          wallet_address: address,
          ton_proof: tonProof
        })
      });

      state.boundWallet = String(j.wallet_address || address || '').trim();
      UI.boundWallet.textContent = state.boundWallet || '-';
      UI.proofState.textContent = 'Verified';
      state.waiting = false;
      updateActionMode();

      setStatus(j.message || 'Wallet saved successfully.', 'ok');
    } catch (e) {
      state.waiting = false;
      setStatus('Bind failed: ' + e.message, 'bad');
    } finally {
      state.finalizing = false;
      UI.bindBtn.disabled = false;
      UI.rebindBtn.disabled = false;
    }
  }

  async function startBindFlow(kind) {
    try {
      UI.bindBtn.disabled = true;
      UI.rebindBtn.disabled = true;

      const tc = await ensureTonConnect();

      if (tc?.wallet?.account?.address) {
        setStatus('Wallet already connected. Reset TonConnect session first.', 'warn');
        UI.bindBtn.disabled = false;
        UI.rebindBtn.disabled = false;
        return;
      }

      setStatus(kind === 'rebind'
        ? 'Preparing rebind. Switch to the new wallet before approving.'
        : 'Preparing bind. Approve wallet connection in mobile app.',
        'warn');

      await getNonce();

      state.waiting = true;
      UI.proofState.textContent = 'Waiting wallet proof';

      if (typeof tc.openModal === 'function') {
        await tc.openModal();
      } else {
        throw new Error('TonConnect modal unavailable.');
      }
    } catch (e) {
      state.waiting = false;
      UI.bindBtn.disabled = false;
      UI.rebindBtn.disabled = false;
      setStatus((kind === 'rebind' ? 'Rebind start failed: ' : 'Bind start failed: ') + e.message, 'bad');
    }
  }

  async function bindNow() {
    await fullReset();
    await startBindFlow('bind');
  }

  async function rebindNow() {
    await fullReset();
    await startBindFlow('rebind');
  }

  async function resetTonSessionOnly() {
    try {
      await hardDisconnectTonConnect();
      clearTransientUi();
      setStatus('TonConnect session reset.', 'ok');
    } catch (e) {
      setStatus('Reset failed: ' + e.message, 'bad');
    }
  }

  UI.bindBtn.addEventListener('click', bindNow);
  UI.rebindBtn.addEventListener('click', rebindNow);
  UI.refreshBtn.addEventListener('click', loadProfile);
  UI.resetBtn.addEventListener('click', resetTonSessionOnly);

  clearTransientUi();
  updateActionMode();
  loadProfile();
})();
</script>
</body>
</html>