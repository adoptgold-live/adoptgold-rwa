<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/testers/storage-tester.php
 * AdoptGold / POAdo — Storage Tester
 * Version: v7.2.0-csrf-fixed-20260319
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/csrf.php';

$user = [];
if (function_exists('session_user')) {
    try {
        $tmp = session_user();
        if (is_array($tmp)) {
            $user = $tmp;
        }
    } catch (Throwable $e) {
    }
}

$userId = (int)($user['id'] ?? 0);
$wallet = (string)($user['wallet'] ?? '');
$walletAddress = (string)($user['wallet_address'] ?? '');
$nickname = (string)($user['nickname'] ?? '');
$email = (string)($user['email'] ?? '');
$emailVerifiedAt = (string)($user['email_verified_at'] ?? '');
$isLoggedIn = ($userId > 0);

$csrfBind = function_exists('csrf_token') ? csrf_token('storage_bind_card') : '';
$csrfActivate = function_exists('csrf_token') ? csrf_token('storage_activate_card') : '';
$csrfReload = function_exists('csrf_token') ? csrf_token('storage_reload_card_emx') : '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Storage Tester v7.2</title>
  <style>
    :root{
      --bg:#050607;
      --panel:#0b0f10;
      --border:rgba(91,255,60,.18);
      --text:#ecfff0;
      --muted:rgba(236,255,240,.65);
      --green:#5bff3c;
      --gold:#f6d768;
      --red:#ff8e7f;
      --blue:#79b8ff;
    }
    *{box-sizing:border-box}
    body{
      margin:0;
      background:var(--bg);
      color:var(--text);
      font:14px/1.45 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    }
    .wrap{
      width:min(1180px,calc(100% - 24px));
      margin:18px auto;
      display:grid;
      gap:16px;
      padding-bottom:40px;
    }
    .panel{
      background:var(--panel);
      border:1px solid var(--border);
      border-radius:16px;
      padding:16px;
    }
    h1,h2{margin:0 0 10px;color:#fff4c8}
    .muted{color:var(--muted)}
    .grid{
      display:grid;
      grid-template-columns:repeat(2,minmax(0,1fr));
      gap:12px;
    }
    .row{
      display:grid;
      gap:8px;
      margin-top:10px;
    }
    .actions{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
    }
    button{
      appearance:none;
      border:1px solid rgba(246,215,104,.24);
      background:rgba(255,255,255,.03);
      color:var(--text);
      padding:10px 14px;
      border-radius:12px;
      cursor:pointer;
      font:inherit;
    }
    button.primary{
      background:linear-gradient(180deg, rgba(246,215,104,.14), rgba(91,255,60,.06));
      color:#fff4c8;
    }
    button:disabled{opacity:.45;cursor:not-allowed}
    input,textarea{
      width:100%;
      background:#050607;
      color:#fff;
      border:1px solid rgba(246,215,104,.2);
      border-radius:12px;
      padding:10px 12px;
      font:inherit;
    }
    textarea{min-height:110px;resize:vertical}
    .status{
      min-height:20px;
      color:var(--muted);
      white-space:pre-wrap;
    }
    .ok{color:var(--green)}
    .fail{color:var(--red)}
    .warn{color:var(--gold)}
    .info{color:var(--blue)}
    .log{
      background:#030404;
      border:1px solid rgba(91,255,60,.12);
      border-radius:12px;
      padding:12px;
      min-height:260px;
      white-space:pre-wrap;
      overflow:auto;
    }
    .kv{
      display:grid;
      grid-template-columns:180px 1fr;
      gap:8px 12px;
      margin-top:8px;
    }
    .k{color:var(--muted)}
    .v{word-break:break-all}
    .badge{
      display:inline-block;
      padding:3px 8px;
      border-radius:999px;
      border:1px solid rgba(255,255,255,.08);
      margin-right:8px;
    }
    .badge.ok{color:var(--green)}
    .badge.fail{color:var(--red)}
    .badge.warn{color:var(--gold)}
    .health-list{
      display:grid;
      gap:8px;
      margin-top:10px;
    }
    .health-item{
      display:grid;
      grid-template-columns:180px 1fr auto;
      gap:10px;
      align-items:center;
      border:1px solid rgba(255,255,255,.06);
      border-radius:12px;
      padding:10px 12px;
      background:rgba(255,255,255,.02);
    }
    .health-name{color:#fff4c8}
    .health-note{color:var(--muted); word-break:break-word}
    .health-pill{
      border-radius:999px;
      padding:3px 10px;
      border:1px solid rgba(255,255,255,.08);
      font-size:12px;
    }
    .health-pill.ok{color:var(--green)}
    .health-pill.fail{color:var(--red)}
    .health-pill.warn{color:var(--gold)}
    @media (max-width: 860px){
      .grid{grid-template-columns:1fr}
      .kv{grid-template-columns:1fr}
      .health-item{grid-template-columns:1fr}
    }
  </style>
</head>
<body>
<div class="wrap">
  <div class="panel">
    <h1>Storage Tester v7.2</h1>
    <div>
      <?php if ($isLoggedIn): ?>
        <span class="badge ok">SESSION OK</span>
      <?php else: ?>
        <span class="badge fail">NO SESSION</span>
      <?php endif; ?>
      <?php if ($walletAddress !== ''): ?>
        <span class="badge ok">TON BOUND</span>
      <?php else: ?>
        <span class="badge warn">TON NOT BOUND</span>
      <?php endif; ?>
      <?php if ($emailVerifiedAt !== ''): ?>
        <span class="badge ok">EMAIL VERIFIED</span>
      <?php else: ?>
        <span class="badge warn">EMAIL NOT VERIFIED</span>
      <?php endif; ?>
    </div>
    <div class="kv">
      <div class="k">User ID</div><div class="v"><?= htmlspecialchars((string)$userId) ?></div>
      <div class="k">Nickname</div><div class="v"><?= htmlspecialchars($nickname !== '' ? $nickname : '-') ?></div>
      <div class="k">Email</div><div class="v"><?= htmlspecialchars($email !== '' ? $email : '-') ?></div>
      <div class="k">Email Verified At</div><div class="v"><?= htmlspecialchars($emailVerifiedAt !== '' ? $emailVerifiedAt : '-') ?></div>
      <div class="k">Wallet</div><div class="v"><?= htmlspecialchars($wallet !== '' ? $wallet : '-') ?></div>
      <div class="k">TON Address</div><div class="v"><?= htmlspecialchars($walletAddress !== '' ? $walletAddress : '-') ?></div>
      <div class="k">CSRF Bind</div><div class="v"><?= htmlspecialchars($csrfBind !== '' ? $csrfBind : '-') ?></div>
      <div class="k">CSRF Activate</div><div class="v"><?= htmlspecialchars($csrfActivate !== '' ? $csrfActivate : '-') ?></div>
      <div class="k">CSRF Reload</div><div class="v"><?= htmlspecialchars($csrfReload !== '' ? $csrfReload : '-') ?></div>
    </div>
  </div>

  <div class="panel grid">
    <div>
      <h2>GET Health</h2>
      <div class="actions">
        <button id="btnHealthGet" class="primary">Run GET Health</button>
      </div>
      <div class="health-list" id="getHealthList"></div>
      <div class="status" id="getHealthStatus"></div>
    </div>

    <div>
      <h2>POST Health</h2>
      <div class="actions">
        <button id="btnHealthPost" class="primary">Run POST Health</button>
      </div>
      <div class="health-list" id="postHealthList"></div>
      <div class="status" id="postHealthStatus"></div>
    </div>
  </div>

  <div class="panel grid">
    <div>
      <h2>Read Endpoints</h2>
      <div class="actions">
        <button id="btnOverview">Overview</button>
        <button id="btnHistory">History</button>
        <button id="btnAddress">Address</button>
        <button id="btnBalance">Balance</button>
        <button id="btnRunReads" class="primary">Run All Reads</button>
      </div>
      <div class="status" id="readStatus"></div>
    </div>

    <div>
      <h2>Live State</h2>
      <div class="row">
        <label>Card Number</label>
        <input id="cardNumberInput" type="text" inputmode="numeric" maxlength="16" placeholder="16 digit public deposit number">
      </div>
      <div class="row">
        <label>Activation Ref</label>
        <input id="activationRefInput" type="text" placeholder="auto-filled after activate prepare">
      </div>
      <div class="row">
        <label>TX Hash</label>
        <input id="txHashInput" type="text" placeholder="manual fallback confirm">
      </div>
    </div>
  </div>

  <div class="panel grid">
    <div>
      <h2>Bind / Activate</h2>
      <div class="actions">
        <button id="btnBindCard" class="primary">Bind Card</button>
        <button id="btnActivatePrepare">Activate Prepare</button>
        <button id="btnActivateVerify">Auto Verify Probe</button>
        <button id="btnActivateConfirm">Manual Confirm</button>
      </div>
      <div class="status" id="activateStatus"></div>
    </div>

    <div>
      <h2>Reload Gate</h2>
      <div class="actions">
        <button id="btnReloadProbe">Reload Probe</button>
      </div>
      <div class="status" id="reloadStatus"></div>
    </div>
  </div>

  <div class="panel">
    <h2>Last JSON</h2>
    <textarea id="jsonBox" readonly></textarea>
  </div>

  <div class="panel">
    <div class="actions">
      <button id="btnCheckLogin">Check Login Info</button>
      <button id="btnClearLog">Clear Log</button>
    </div>
    <div class="log" id="logBox"></div>
  </div>
</div>

<script>
(function () {
  'use strict';

  const state = {
    session: {
      userId: <?= json_encode((string)$userId) ?>,
      wallet: <?= json_encode($wallet) ?>,
      walletAddress: <?= json_encode($walletAddress) ?>,
      nickname: <?= json_encode($nickname) ?>,
      email: <?= json_encode($email) ?>,
      emailVerifiedAt: <?= json_encode($emailVerifiedAt) ?>,
      isLoggedIn: <?= $isLoggedIn ? 'true' : 'false' ?>
    },
    csrf: {
      bind: <?= json_encode($csrfBind) ?>,
      activate: <?= json_encode($csrfActivate) ?>,
      reload: <?= json_encode($csrfReload) ?>
    },
    activation_ref: '',
    tx_hash: '',
    card_number: ''
  };

  const $ = (id) => document.getElementById(id);

  const GET_HEALTH = [
    ['/rwa/api/storage/overview.php', 'Overview'],
    ['/rwa/api/storage/history.php', 'History'],
    ['/rwa/api/storage/address.php', 'Address'],
    ['/rwa/api/storage/balance.php', 'Balance']
  ];

  const POST_HEALTH = [
    ['/rwa/api/storage/bind-card.php', 'Bind Card', () => ({
      csrf_token: state.csrf.bind,
      card_number: state.card_number || '1234567890123456'
    })],
    ['/rwa/api/storage/activate.php', 'Activate Prepare', () => ({
      csrf_token: state.csrf.activate
    })],
    ['/rwa/api/storage/activate-verify.php', 'Activate Verify', () => ({
      csrf_token: state.csrf.activate,
      activation_ref: state.activation_ref || 'TEST-NO-REF'
    })],
    ['/rwa/api/storage/activate-confirm.php', 'Activate Confirm', () => ({
      csrf_token: state.csrf.activate,
      activation_ref: state.activation_ref || '',
      tx_hash: state.tx_hash || ''
    })],
    ['/rwa/api/storage/reload-card-emx.php', 'Reload Probe', () => ({
      csrf_token: state.csrf.reload,
      amount: '1.000000'
    })]
  ];

  function log(msg, type) {
    const box = $('logBox');
    const ts = new Date().toISOString();
    const line = `[${ts}] ${msg}`;
    const div = document.createElement('div');
    div.textContent = line;
    if (type === 'ok') div.className = 'ok';
    else if (type === 'fail') div.className = 'fail';
    else if (type === 'warn') div.className = 'warn';
    else if (type === 'info') div.className = 'info';
    box.appendChild(div);
    box.scrollTop = box.scrollHeight;
  }

  function setJson(obj) {
    $('jsonBox').value = typeof obj === 'string' ? obj : JSON.stringify(obj, null, 2);
  }

  async function fetchJson(url, options) {
    const started = performance.now();
    const res = await fetch(url, options || {});
    const text = await res.text();
    const ms = Math.round(performance.now() - started);

    let json = null;
    try {
      json = JSON.parse(text);
    } catch (_) {
      const err = new Error(`NON_JSON_RESPONSE · HTTP ${res.status} · ${ms}ms`);
      err.code = 'NON_JSON_RESPONSE';
      err.http = res.status;
      err.ms = ms;
      err.raw = text;
      throw err;
    }

    json.__http_status = res.status;
    json.__latency_ms = ms;

    if (!res.ok || json.ok === false) {
      const code = json.error || json.message || `HTTP_${res.status}`;
      const err = new Error(`${code} · HTTP ${res.status} · ${ms}ms`);
      err.code = code;
      err.http = res.status;
      err.ms = ms;
      err.json = json;
      throw err;
    }

    return json;
  }

  async function getApi(url) {
    return fetchJson(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    });
  }

  async function postApi(url, payload) {
    const fd = new FormData();
    Object.keys(payload || {}).forEach((k) => {
      if (payload[k] !== undefined && payload[k] !== null) fd.append(k, String(payload[k]));
    });

    return fetchJson(url, {
      method: 'POST',
      credentials: 'same-origin',
      body: fd,
      headers: { Accept: 'application/json' }
    });
  }

  function syncFieldsFromState() {
    $('activationRefInput').value = state.activation_ref || '';
    $('txHashInput').value = state.tx_hash || '';
    $('cardNumberInput').value = state.card_number || $('cardNumberInput').value || '';
  }

  function pullFieldsToState() {
    state.activation_ref = String($('activationRefInput').value || '').trim();
    state.tx_hash = String($('txHashInput').value || '').trim();
    state.card_number = String($('cardNumberInput').value || '').replace(/\D+/g, '').slice(0, 16);
  }

  function renderHealthItem(hostId, name, note, kind) {
    const host = $(hostId);
    const row = document.createElement('div');
    row.className = 'health-item';
    row.innerHTML = `
      <div class="health-name">${name}</div>
      <div class="health-note">${note}</div>
      <div class="health-pill ${kind}">${kind.toUpperCase()}</div>
    `;
    host.appendChild(row);
  }

  function clearHealth(hostId) {
    $(hostId).innerHTML = '';
  }

  function loginInfoText() {
    return [
      `Session Logged In: ${state.session.isLoggedIn ? 'YES' : 'NO'}`,
      `User ID: ${state.session.userId || '0'}`,
      `Nickname: ${state.session.nickname || '-'}`,
      `Email: ${state.session.email || '-'}`,
      `Email Verified At: ${state.session.emailVerifiedAt || '-'}`,
      `Wallet: ${state.session.wallet || '-'}`,
      `TON Address: ${state.session.walletAddress || '-'}`,
      `CSRF Bind: ${state.csrf.bind || '-'}`,
      `CSRF Activate: ${state.csrf.activate || '-'}`,
      `CSRF Reload: ${state.csrf.reload || '-'}`
    ].join('\n');
  }

  async function runOverview() {
    const json = await getApi('/rwa/api/storage/overview.php');
    state.card_number = String(json?.card?.card_number || state.card_number || '');
    syncFieldsFromState();
    setJson(json);
    log(`Overview => OK · ${json.__latency_ms}ms · HTTP ${json.__http_status}`, 'ok');
    return json;
  }

  async function runHistory() {
    const json = await getApi('/rwa/api/storage/history.php');
    setJson(json);
    log(`History => OK · ${json.__latency_ms}ms · HTTP ${json.__http_status}`, 'ok');
    return json;
  }

  async function runAddress() {
    const json = await getApi('/rwa/api/storage/address.php');
    setJson(json);
    log(`Address => OK · ${json.__latency_ms}ms · HTTP ${json.__http_status}`, 'ok');
    return json;
  }

  async function runBalance() {
    const json = await getApi('/rwa/api/storage/balance.php');
    state.card_number = String(json?.card?.card_number || json?.card?.number || state.card_number || '');
    syncFieldsFromState();
    setJson(json);
    log(`Balance => OK · ${json.__latency_ms}ms · HTTP ${json.__http_status}`, 'ok');
    return json;
  }

  async function runAllReads() {
    $('readStatus').textContent = 'Running read checks...';
    $('readStatus').className = 'status warn';
    try {
      await runOverview();
      await runHistory();
      await runAddress();
      await runBalance();
      $('readStatus').textContent = 'All read checks passed';
      $('readStatus').className = 'status ok';
    } catch (err) {
      $('readStatus').textContent = String(err.message || err);
      $('readStatus').className = 'status fail';
      log(`Read checks => FAIL · ${String(err.message || err)}`, 'fail');
    }
  }

  async function bindCard() {
    pullFieldsToState();
    $('activateStatus').textContent = 'Binding card...';
    $('activateStatus').className = 'status warn';

    try {
      const json = await postApi('/rwa/api/storage/bind-card.php', {
        csrf_token: state.csrf.bind,
        card_number: state.card_number
      });
      state.card_number = String(json.card_number || state.card_number || '');
      syncFieldsFromState();
      setJson(json);
      $('activateStatus').textContent = 'Bind card passed';
      $('activateStatus').className = 'status ok';
      log(`Bind Card => OK · ${json.__latency_ms}ms · HTTP ${json.__http_status}`, 'ok');
    } catch (err) {
      $('activateStatus').textContent = String(err.message || err);
      $('activateStatus').className = 'status fail';
      log(`Bind Card => FAIL · ${String(err.message || err)}`, 'fail');
    }
  }

  async function activatePrepare() {
    pullFieldsToState();
    $('activateStatus').textContent = 'Preparing activation...';
    $('activateStatus').className = 'status warn';

    try {
      const json = await postApi('/rwa/api/storage/activate.php', {
        csrf_token: state.csrf.activate
      });
      state.activation_ref = String(json.activation_ref || '');
      syncFieldsFromState();
      setJson(json);
      $('activateStatus').textContent = 'Activate prepare passed';
      $('activateStatus').className = 'status ok';
      log(`Activate Prepare => OK · ${json.__latency_ms}ms · HTTP ${json.__http_status}`, 'ok');
    } catch (err) {
      $('activateStatus').textContent = String(err.message || err);
      $('activateStatus').className = 'status fail';
      log(`Activate Prepare => FAIL · ${String(err.message || err)}`, 'fail');
    }
  }

  async function activateVerify() {
    pullFieldsToState();
    $('activateStatus').textContent = 'Probing auto verify...';
    $('activateStatus').className = 'status warn';

    try {
      const json = await postApi('/rwa/api/storage/activate-verify.php', {
        csrf_token: state.csrf.activate,
        activation_ref: state.activation_ref
      });
      if (json.tx_hash) state.tx_hash = String(json.tx_hash);
      syncFieldsFromState();
      setJson(json);
      $('activateStatus').textContent = `Auto verify response: ${json.message || 'OK'}`;
      $('activateStatus').className = json.verified ? 'status ok' : 'status warn';
      log(`Activate Verify => OK · ${json.__latency_ms}ms · HTTP ${json.__http_status}`, json.verified ? 'ok' : 'warn');
    } catch (err) {
      $('activateStatus').textContent = String(err.message || err);
      $('activateStatus').className = 'status fail';
      log(`Activate Verify => FAIL · ${String(err.message || err)}`, 'fail');
    }
  }

  async function activateConfirm() {
    pullFieldsToState();
    $('activateStatus').textContent = 'Manual confirm...';
    $('activateStatus').className = 'status warn';

    try {
      const json = await postApi('/rwa/api/storage/activate-confirm.php', {
        csrf_token: state.csrf.activate,
        activation_ref: state.activation_ref,
        tx_hash: state.tx_hash
      });
      if (json.tx_hash) state.tx_hash = String(json.tx_hash);
      syncFieldsFromState();
      setJson(json);
      $('activateStatus').textContent = 'Manual confirm passed';
      $('activateStatus').className = 'status ok';
      log(`Activate Confirm => OK · ${json.__latency_ms}ms · HTTP ${json.__http_status}`, 'ok');
    } catch (err) {
      $('activateStatus').textContent = String(err.message || err);
      $('activateStatus').className = 'status fail';
      log(`Activate Confirm => FAIL · ${String(err.message || err)}`, 'fail');
    }
  }

  async function reloadProbe() {
    $('reloadStatus').textContent = 'Testing reload gate...';
    $('reloadStatus').className = 'status warn';

    try {
      const json = await postApi('/rwa/api/storage/reload-card-emx.php', {
        csrf_token: state.csrf.reload,
        amount: '1.000000'
      });
      setJson(json);
      $('reloadStatus').textContent = 'Reload probe passed';
      $('reloadStatus').className = 'status ok';
      log(`Reload Probe => OK · ${json.__latency_ms}ms · HTTP ${json.__http_status}`, 'ok');
    } catch (err) {
      $('reloadStatus').textContent = String(err.message || err);
      $('reloadStatus').className = 'status fail';
      log(`Reload Probe => FAIL · ${String(err.message || err)}`, 'fail');
    }
  }

  async function runGetHealth() {
    clearHealth('getHealthList');
    $('getHealthStatus').textContent = 'Running GET health...';
    $('getHealthStatus').className = 'status warn';

    let okCount = 0;
    for (const [url, name] of GET_HEALTH) {
      try {
        const json = await getApi(url);
        renderHealthItem('getHealthList', name, `HTTP ${json.__http_status} · ${json.__latency_ms}ms`, 'ok');
        log(`${name} => OK · ${json.__latency_ms}ms · HTTP ${json.__http_status}`, 'ok');
        okCount++;
      } catch (err) {
        const note = String(err.message || err);
        renderHealthItem('getHealthList', name, note, 'fail');
        log(`${name} => FAIL · ${note}`, 'fail');
      }
    }

    $('getHealthStatus').textContent = `GET health done · ${okCount}/${GET_HEALTH.length} passed`;
    $('getHealthStatus').className = okCount === GET_HEALTH.length ? 'status ok' : 'status warn';
  }

  async function runPostHealth() {
    pullFieldsToState();
    clearHealth('postHealthList');
    $('postHealthStatus').textContent = 'Running POST health...';
    $('postHealthStatus').className = 'status warn';

    let okCount = 0;
    for (const [url, name, buildPayload] of POST_HEALTH) {
      try {
        const json = await postApi(url, buildPayload());
        if (name === 'Activate Prepare' && json.activation_ref) {
          state.activation_ref = String(json.activation_ref);
          syncFieldsFromState();
        }
        if ((name === 'Activate Verify' || name === 'Activate Confirm') && json.tx_hash) {
          state.tx_hash = String(json.tx_hash);
          syncFieldsFromState();
        }
        renderHealthItem('postHealthList', name, `HTTP ${json.__http_status} · ${json.__latency_ms}ms · ${json.message || 'OK'}`, 'ok');
        log(`${name} => OK · ${json.__latency_ms}ms · HTTP ${json.__http_status}`, 'ok');
        okCount++;
      } catch (err) {
        const note = String(err.message || err);
        const code = String(err.code || '');
        const kind = (code === 'AUTH_REQUIRED' || code === 'CARD_NOT_ACTIVE' || code === 'ACTIVATION_PAYMENT_NOT_VERIFIED' || code === 'TX_HASH_REQUIRED' || code === 'ACTIVATION_REF_REQUIRED')
          ? 'warn'
          : 'fail';
        renderHealthItem('postHealthList', name, note, kind);
        log(`${name} => ${kind.toUpperCase()} · ${note}`, kind === 'warn' ? 'warn' : 'fail');
      }
    }

    $('postHealthStatus').textContent = `POST health done · ${okCount}/${POST_HEALTH.length} strict passes`;
    $('postHealthStatus').className = okCount > 0 ? 'status ok' : 'status warn';
  }

  function checkLoginInfo() {
    const text = loginInfoText();
    setJson(text);
    log('Login/session info checked.', 'info');
    log(text, 'info');
  }

  function boot() {
    $('btnOverview').addEventListener('click', () => runOverview().catch((e) => log(`Overview => FAIL · ${e.message}`, 'fail')));
    $('btnHistory').addEventListener('click', () => runHistory().catch((e) => log(`History => FAIL · ${e.message}`, 'fail')));
    $('btnAddress').addEventListener('click', () => runAddress().catch((e) => log(`Address => FAIL · ${e.message}`, 'fail')));
    $('btnBalance').addEventListener('click', () => runBalance().catch((e) => log(`Balance => FAIL · ${e.message}`, 'fail')));
    $('btnRunReads').addEventListener('click', runAllReads);
    $('btnBindCard').addEventListener('click', bindCard);
    $('btnActivatePrepare').addEventListener('click', activatePrepare);
    $('btnActivateVerify').addEventListener('click', activateVerify);
    $('btnActivateConfirm').addEventListener('click', activateConfirm);
    $('btnReloadProbe').addEventListener('click', reloadProbe);
    $('btnHealthGet').addEventListener('click', runGetHealth);
    $('btnHealthPost').addEventListener('click', runPostHealth);
    $('btnCheckLogin').addEventListener('click', checkLoginInfo);
    $('btnClearLog').addEventListener('click', () => {
      $('logBox').textContent = '';
      $('jsonBox').value = '';
      clearHealth('getHealthList');
      clearHealth('postHealthList');
      log('Log cleared.');
    });

    syncFieldsFromState();
    log('Storage tester v7.2 ready.');
    log(loginInfoText(), state.session.isLoggedIn ? 'info' : 'warn');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
</script>
</body>
</html>