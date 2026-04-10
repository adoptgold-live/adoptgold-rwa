<?php
declare(strict_types=1);

/**
 * AdoptGold RWA
 * TON Bind Debug Tester
 * File: /var/www/html/public/rwa/testers/ton-bind-debug.php
 * Version: v1.0.20260315b
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

function h(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$sessionUser = function_exists('session_user') ? (session_user() ?: []) : [];
$sessionUserId = function_exists('session_user_id') ? (int)session_user_id() : 0;
$sessionSnapshot = [
    'session_user_id()' => $sessionUserId,
    'session_user' => $sessionUser,
    '_SESSION.session_user' => $_SESSION['session_user'] ?? null,
    '_SESSION.user' => $_SESSION['user'] ?? null,
    '_SESSION.rwa_user' => $_SESSION['rwa_user'] ?? null,
    '_SESSION.wallet' => $_SESSION['wallet'] ?? null,
    '_SESSION.wallet_address' => $_SESSION['wallet_address'] ?? null,
    '_SESSION.auth_wallet' => $_SESSION['auth_wallet'] ?? null,
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>TON Bind Debug Tester</title>
<style>
:root{--bg:#0d0816;--bg2:#17102a;--panel:#1b122a;--panel2:#24173b;--line:rgba(178,108,255,.15);--line2:rgba(255,255,255,.08);--text:#f6f1ff;--muted:#c7bbdf;--purple:#b26cff;--purple2:#7d45ff;--gold:#f5d97b;--green:#42ff9d;--red:#ff6f8e;--warn:#ffcf68}
*{box-sizing:border-box}html,body{margin:0;min-height:100%;background:radial-gradient(circle at top right,rgba(178,108,255,.10),transparent 22%),radial-gradient(circle at top left,rgba(255,111,142,.06),transparent 18%),linear-gradient(180deg,var(--bg),var(--bg2));color:var(--text);font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
.page{width:min(1280px,calc(100% - 20px));margin:14px auto 24px}.hero,.panel{border:1px solid var(--line);border-radius:20px;overflow:hidden;background:linear-gradient(180deg,rgba(255,255,255,.03),rgba(255,255,255,.01)),linear-gradient(180deg,var(--panel),var(--panel2));box-shadow:0 18px 40px rgba(0,0,0,.28)}.hero{padding:16px 18px;margin-bottom:14px}.hero h1{margin:0;color:var(--gold);font-size:22px}.hero p{margin:8px 0 0;color:var(--muted);line-height:1.6;font-size:13px}.grid{display:grid;grid-template-columns:1fr;gap:14px}@media (min-width:1080px){.grid{grid-template-columns:420px 1fr;align-items:start}}.panelHead{padding:14px 16px;border-bottom:1px solid var(--line2);display:flex;align-items:center;justify-content:space-between;gap:10px}.panelTitle{font-size:16px;font-weight:800}.badge{display:inline-flex;align-items:center;gap:7px;min-height:30px;padding:0 10px;border-radius:999px;border:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.04);font-size:11px;font-weight:800}.dot{width:9px;height:9px;border-radius:50%;box-shadow:0 0 8px currentColor}.badge.ok{color:#d9ffeb;border-color:rgba(66,255,157,.22);background:rgba(66,255,157,.08)}.badge.ok .dot{background:var(--green);color:var(--green)}.badge.warn{color:#ffeab7;border-color:rgba(255,207,104,.22);background:rgba(255,207,104,.08)}.badge.warn .dot{background:var(--warn);color:var(--warn)}.badge.err{color:#ffd5de;border-color:rgba(255,111,142,.22);background:rgba(255,111,142,.08)}.badge.err .dot{background:var(--red);color:var(--red)}.panelBody{padding:14px 16px 16px}.stack{display:grid;gap:12px}.btnRow{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media (max-width:560px){.btnRow{grid-template-columns:1fr}}.btn{width:100%;min-height:48px;border-radius:14px;border:0;cursor:pointer;font:inherit;font-size:15px;font-weight:800}.btnPrimary{background:linear-gradient(180deg,var(--purple),var(--purple2));color:#fff}.btnGold{background:linear-gradient(180deg,#f5d97b,#d7b259);color:#2b1e05}.btnDanger{background:linear-gradient(180deg,#ac3b57,#7c2438);color:#fff}.card{border:1px solid rgba(255,255,255,.08);border-radius:16px;background:rgba(0,0,0,.16);padding:12px}.card .k{font-size:11px;color:var(--muted)}.card .v{margin-top:7px;font-size:15px;font-weight:800;line-height:1.5;word-break:break-all}.statusBox{min-height:84px;border-radius:14px;border:1px solid rgba(255,255,255,.10);background:#090912;color:#fff;padding:12px;line-height:1.6;white-space:pre-wrap;word-break:break-word}.statusBox.ok{border-color:rgba(66,255,157,.22);background:rgba(14,37,24,.70);color:#d7ffea}.statusBox.warn{border-color:rgba(255,207,104,.22);background:rgba(52,36,8,.62);color:#ffe9b5}.statusBox.err{border-color:rgba(255,111,142,.22);background:rgba(52,15,23,.66);color:#ffd7df}.pre{margin:0;border-radius:16px;border:1px solid rgba(255,255,255,.08);background:#090912;padding:14px;overflow:auto;max-height:760px;font-size:12px;line-height:1.55}.small{font-size:12px;color:var(--muted);line-height:1.6}
</style>
</head>
<body>
<div class="page">
  <section class="hero">
    <h1>TON BIND DEBUG TESTER</h1>
    <p>Same-page tester for reset, nonce, wallet connect, proof, bind API, and session resolution.</p>
  </section>

  <section class="grid">
    <section class="panel">
      <div class="panelHead">
        <div class="panelTitle">TEST CONTROLS</div>
        <div class="badge warn" id="overallBadge"><span class="dot"></span> READY</div>
      </div>
      <div class="panelBody">
        <div class="stack">
          <div class="card">
            <div class="k">SESSION USER ID</div>
            <div class="v"><?= h((string)$sessionUserId) ?></div>
          </div>

          <div class="card">
            <div class="k">CURRENT SAVED TON WALLET</div>
            <div class="v" id="savedWalletText"><?= h(trim((string)($sessionUser['wallet_address'] ?? '')) ?: 'UNBOUND') ?></div>
          </div>

          <div class="btnRow">
            <button class="btn btnDanger" type="button" id="resetBtn">RESET TON SESSION</button>
            <button class="btn btnGold" type="button" id="nonceBtn">GET NONCE</button>
          </div>

          <div class="btnRow">
            <button class="btn btnPrimary" type="button" id="connectBtn">OPEN TON CONNECT</button>
            <button class="btn btnPrimary" type="button" id="bindBtn">SEND TO BIND API</button>
          </div>

          <div>
            <div class="small">Status</div>
            <div class="statusBox warn" id="statusBox">Ready.</div>
          </div>

          <div>
            <div class="small">Proof State</div>
            <div class="statusBox warn" id="proofStateBox">IDLE</div>
          </div>

          <div class="small">
            Manifest URL:
            <br>
            <strong>https://adoptgold.app/tonconnect-manifest.json</strong>
          </div>
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="panelHead">
        <div class="panelTitle">DEBUG OUTPUT</div>
        <div class="badge warn" id="jsonBadge"><span class="dot"></span> WAITING</div>
      </div>
      <div class="panelBody">
        <div class="stack">
          <div class="card">
            <div class="k">NONCE</div>
            <div class="v" id="nonceText">-</div>
          </div>

          <div class="card">
            <div class="k">RETURNED WALLET ADDRESS</div>
            <div class="v" id="walletText">-</div>
          </div>

          <pre class="pre" id="jsonOutput"><?= h(json_encode(['session_snapshot' => $sessionSnapshot], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
        </div>
      </div>
    </section>
  </section>
</div>

<script src="https://unpkg.com/@tonconnect/ui@latest/dist/tonconnect-ui.min.js"></script>
<script>
(() => {
  'use strict';

  const state = { nonce: '', wallet: null, proof: null, tonConnect: null };
  const $ = (id) => document.getElementById(id);

  const el = {
    overallBadge: $('overallBadge'),
    jsonBadge: $('jsonBadge'),
    savedWalletText: $('savedWalletText'),
    statusBox: $('statusBox'),
    proofStateBox: $('proofStateBox'),
    nonceText: $('nonceText'),
    walletText: $('walletText'),
    jsonOutput: $('jsonOutput'),
    resetBtn: $('resetBtn'),
    nonceBtn: $('nonceBtn'),
    connectBtn: $('connectBtn'),
    bindBtn: $('bindBtn')
  };

  function setBadge(node, tone, text) {
    node.className = 'badge ' + tone;
    node.innerHTML = '<span class="dot"></span> ' + text;
  }

  function setBox(node, text, tone = 'warn') {
    node.className = 'statusBox ' + tone;
    node.textContent = text || '';
  }

  function dump(extra = {}) {
    const out = {
      nonce: state.nonce,
      wallet: state.wallet,
      proof: state.proof,
      extra
    };
    el.jsonOutput.textContent = JSON.stringify(out, null, 2);
  }

  async function jfetch(url, options = {}) {
    const res = await fetch(url, {
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        ...(options.body ? {'Content-Type': 'application/json'} : {})
      },
      ...options
    });

    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch (e) {}

    if (!res.ok) {
      throw new Error((json && (json.message || json.error)) || ('HTTP ' + res.status));
    }
    if (!json || typeof json !== 'object') {
      throw new Error('Invalid JSON response');
    }
    return json;
  }

  function ensureTonConnect() {
    if (state.tonConnect) return state.tonConnect;
    if (!window.TON_CONNECT_UI || !window.TON_CONNECT_UI.TonConnectUI) {
      throw new Error('TonConnect UI unavailable.');
    }

    state.tonConnect = new window.TON_CONNECT_UI.TonConnectUI({
      manifestUrl: 'https://adoptgold.app/tonconnect-manifest.json'
    });

    state.tonConnect.onStatusChange(async (wallet) => {
      state.wallet = wallet || null;
      state.proof = extractProof(wallet);

      const addr = String(wallet?.account?.address || wallet?.address || '').trim();
      el.walletText.textContent = addr || '-';

      if (state.proof) {
        setBox(el.proofStateBox, 'PROOF RECEIVED', 'ok');
        setBadge(el.jsonBadge, 'ok', 'PROOF OK');
      } else if (wallet) {
        setBox(el.proofStateBox, 'WALLET CONNECTED BUT PROOF MISSING', 'warn');
        setBadge(el.jsonBadge, 'warn', 'NO PROOF');
      } else {
        setBox(el.proofStateBox, 'NO WALLET', 'warn');
      }

      dump({ event: 'onStatusChange' });
    });

    return state.tonConnect;
  }

  function extractProof(walletInfo) {
    if (!walletInfo || typeof walletInfo !== 'object') return null;
    if (walletInfo.connectItems?.tonProof?.proof) return walletInfo.connectItems.tonProof.proof;
    if (walletInfo.tonProof?.proof) return walletInfo.tonProof.proof;
    if (walletInfo.proof) return walletInfo.proof;
    if (walletInfo.connectItems?.tonProof) return walletInfo.connectItems.tonProof;
    return null;
  }

  async function resetTon() {
    setBox(el.statusBox, 'Resetting TON session...', 'warn');
    try {
      const ton = ensureTonConnect();
      try { await ton.disconnect(); } catch (e) {}

      const json = await jfetch('/rwa/auth/ton/reset.php', {
        method: 'POST',
        body: JSON.stringify({})
      });

      state.nonce = '';
      state.wallet = null;
      state.proof = null;

      el.nonceText.textContent = '-';
      el.walletText.textContent = '-';
      setBox(el.proofStateBox, 'RESET', 'ok');
      setBox(el.statusBox, json.message || 'TON session reset.', 'ok');
      setBadge(el.overallBadge, 'ok', 'RESET OK');
      dump({ reset: json });
    } catch (e) {
      setBox(el.statusBox, e.message || 'Reset failed.', 'err');
      setBadge(el.overallBadge, 'err', 'RESET FAIL');
      dump({ reset_error: e.message || String(e) });
    }
  }

  async function getNonce() {
    setBox(el.statusBox, 'Requesting nonce...', 'warn');
    try {
      const json = await jfetch('/rwa/auth/ton/nonce.php');
      state.nonce = String(json.nonce || json.payload || json.ton_proof_nonce || '').trim();
      if (!state.nonce) throw new Error('Nonce not returned.');

      el.nonceText.textContent = state.nonce;
      setBox(el.statusBox, 'Nonce received.', 'ok');
      setBadge(el.overallBadge, 'ok', 'NONCE OK');
      dump({ nonce_response: json });
    } catch (e) {
      setBox(el.statusBox, e.message || 'Nonce failed.', 'err');
      setBadge(el.overallBadge, 'err', 'NONCE FAIL');
      dump({ nonce_error: e.message || String(e) });
    }
  }

  async function openTonConnect() {
    setBox(el.statusBox, 'Opening TonConnect...', 'warn');
    try {
      if (!state.nonce) await getNonce();

      const ton = ensureTonConnect();
      ton.setConnectRequestParameters({
        state: 'ready',
        value: { tonProof: state.nonce }
      });

      await ton.openModal();

      setBox(el.statusBox, 'Wallet modal opened. Approve in wallet.', 'warn');
      setBadge(el.overallBadge, 'warn', 'WAITING WALLET');
      dump({ connect_requested: true });
    } catch (e) {
      setBox(el.statusBox, e.message || 'TonConnect open failed.', 'err');
      setBadge(el.overallBadge, 'err', 'CONNECT FAIL');
      dump({ connect_error: e.message || String(e) });
    }
  }

  async function sendBind() {
    setBox(el.statusBox, 'Sending bind request...', 'warn');
    try {
      const addr = String(state.wallet?.account?.address || state.wallet?.address || '').trim();
      if (!addr) throw new Error('Wallet address not returned.');
      if (!state.proof) throw new Error('Proof missing from wallet.');

      const json = await jfetch('/rwa/api/profile/bind-ton.php', {
        method: 'POST',
        body: JSON.stringify({
          ton_address: addr,
          proof: state.proof
        })
      });

      el.savedWalletText.textContent = String(json.wallet_address || addr);
      setBox(el.statusBox, json.message || 'TON wallet bound successfully.', 'ok');
      setBox(el.proofStateBox, 'BIND SUCCESS', 'ok');
      setBadge(el.overallBadge, 'ok', 'BIND OK');
      setBadge(el.jsonBadge, 'ok', 'JSON OK');
      dump({ bind_response: json });
    } catch (e) {
      setBox(el.statusBox, e.message || 'Bind failed.', 'err');
      setBox(el.proofStateBox, 'BIND FAILED', 'err');
      setBadge(el.overallBadge, 'err', 'BIND FAIL');
      dump({ bind_error: e.message || String(e) });
    }
  }

  el.resetBtn.addEventListener('click', resetTon);
  el.nonceBtn.addEventListener('click', getNonce);
  el.connectBtn.addEventListener('click', openTonConnect);
  el.bindBtn.addEventListener('click', sendBind);

  setBox(el.statusBox, 'Ready.', 'ok');
  setBox(el.proofStateBox, 'IDLE', 'warn');
})();
</script>
</body>
</html>