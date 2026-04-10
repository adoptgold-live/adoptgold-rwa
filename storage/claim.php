<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/storage/claim.php
 * Claim v7.9
 * SAFE version without rwa_require_login() dependency
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

if (!function_exists('rwa_session_user')) {
    header('Location: /rwa/index.php');
    exit;
}

$user = rwa_session_user();
if (!$user || !is_array($user)) {
    header('Location: /rwa/index.php');
    exit;
}

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$displayName   = trim((string)($user['nickname'] ?? 'User'));
$walletAddress = trim((string)($user['wallet_address'] ?? ''));
$walletShort   = $walletAddress !== ''
    ? substr($walletAddress, 0, 8) . '...' . substr($walletAddress, -8)
    : 'NOT BOUND';
?>
<!doctype html>
<html lang="en" data-lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title>Claim EMA$ · 提取 EMA$</title>
  <meta name="theme-color" content="#08080b">
  <style>
    :root{
      --bg:#06070a;
      --panel:#0d1016;
      --panel2:#111522;
      --line:rgba(168,126,255,.18);
      --line2:rgba(255,214,102,.18);
      --txt:#f3f5fb;
      --muted:rgba(243,245,251,.66);
      --gold:#ffd666;
      --gold2:#ffecae;
      --violet:#a87eff;
      --violet2:#c4acff;
      --green:#76f0a0;
      --red:#ff8f8f;
      --amber:#ffcf70;
      --blue:#8fd5ff;
      --shadow:0 0 0 1px rgba(255,255,255,.02), 0 18px 40px rgba(0,0,0,.32);
      --radius:18px;
      --radius2:14px;
      --maxw:1100px;
    }
    *{box-sizing:border-box}
    html,body{margin:0;padding:0;background:var(--bg);color:var(--txt);font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}
    body{
      min-height:100vh;
      background:
        radial-gradient(circle at top left, rgba(168,126,255,.10), transparent 34%),
        radial-gradient(circle at top right, rgba(255,214,102,.08), transparent 30%),
        linear-gradient(180deg, #07080c 0%, #090b10 100%);
    }
    a{color:inherit;text-decoration:none}
    .shell{max-width:var(--maxw);margin:0 auto;padding:18px 14px 110px}
    .hero{
      display:grid;
      grid-template-columns:1.2fr .8fr;
      gap:14px;
      margin-top:14px;
    }
    .card{
      background:linear-gradient(180deg, rgba(19,22,34,.95), rgba(12,15,24,.96));
      border:1px solid var(--line);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
    }
    .hero-card{padding:18px}
    .eyebrow{
      color:var(--violet2);
      font-size:12px;
      letter-spacing:.14em;
      text-transform:uppercase;
      margin-bottom:8px;
    }
    .title{
      font-size:clamp(24px,5vw,36px);
      font-weight:800;
      line-height:1.08;
      margin:0 0 8px;
    }
    .subtitle{
      color:var(--muted);
      font-size:14px;
      line-height:1.55;
      margin:0;
    }
    .quick{
      display:grid;
      grid-template-columns:repeat(2,1fr);
      gap:10px;
      margin-top:16px;
    }
    .pill{
      min-height:64px;
      padding:12px;
      border-radius:14px;
      background:rgba(255,255,255,.03);
      border:1px solid rgba(255,255,255,.06);
    }
    .pill .k{font-size:11px;color:var(--muted);margin-bottom:5px}
    .pill .v{font-size:15px;font-weight:700;word-break:break-word}
    .claim-grid{
      display:grid;
      grid-template-columns:1.05fr .95fr;
      gap:14px;
      margin-top:14px;
    }
    .section{padding:18px}
    .section h2{
      margin:0 0 4px;
      font-size:20px;
      line-height:1.2;
    }
    .section .sub{
      color:var(--muted);
      font-size:13px;
      line-height:1.5;
      margin-bottom:16px;
    }
    .stats{
      display:grid;
      grid-template-columns:repeat(3,1fr);
      gap:10px;
      margin-bottom:14px;
    }
    .stat{
      padding:14px;
      border-radius:14px;
      border:1px solid rgba(255,255,255,.06);
      background:rgba(255,255,255,.03);
    }
    .stat .k{font-size:11px;color:var(--muted);margin-bottom:6px}
    .stat .v{font-size:20px;font-weight:800;word-break:break-word}
    .v.gold{color:var(--gold2);text-shadow:0 0 16px rgba(255,214,102,.16)}
    .field{margin-top:12px}
    .label{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      margin-bottom:8px;
      font-size:13px;
      color:var(--muted);
    }
    .hint-link{
      font-size:12px;
      color:var(--violet2);
      cursor:pointer;
      user-select:none;
    }
    .input-wrap{
      display:flex;
      align-items:center;
      gap:10px;
      min-height:56px;
      padding:0 14px;
      border-radius:14px;
      border:1px solid rgba(255,255,255,.08);
      background:rgba(255,255,255,.03);
    }
    .token{
      flex:0 0 auto;
      min-width:56px;
      padding:8px 10px;
      border-radius:12px;
      border:1px solid rgba(255,214,102,.18);
      color:var(--gold2);
      font-size:13px;
      font-weight:800;
      text-align:center;
      background:rgba(255,214,102,.06);
    }
    .input-wrap input{
      flex:1 1 auto;
      border:0;
      outline:0;
      background:transparent;
      color:var(--txt);
      font-size:18px;
      font-weight:700;
      width:100%;
    }
    .actions{
      display:grid;
      grid-template-columns:1fr auto auto;
      gap:10px;
      margin-top:14px;
    }
    button{
      appearance:none;
      border:0;
      outline:0;
      cursor:pointer;
      min-height:48px;
      border-radius:14px;
      font-weight:800;
      font-size:14px;
      padding:0 16px;
    }
    .btn-primary{
      background:linear-gradient(180deg, #ffd666, #f0bc2c);
      color:#18130a;
      box-shadow:0 10px 24px rgba(255,214,102,.20);
    }
    .btn-ghost{
      background:rgba(255,255,255,.04);
      border:1px solid rgba(255,255,255,.08);
      color:var(--txt);
    }
    .btn-soft{
      background:rgba(168,126,255,.12);
      border:1px solid rgba(168,126,255,.18);
      color:var(--violet2);
    }
    .msg{
      margin-top:14px;
      padding:12px 14px;
      border-radius:14px;
      font-size:13px;
      line-height:1.5;
      display:none;
      word-break:break-word;
    }
    .msg.show{display:block}
    .msg.ok{
      background:rgba(118,240,160,.08);
      border:1px solid rgba(118,240,160,.16);
      color:#cffff0;
    }
    .msg.err{
      background:rgba(255,143,143,.08);
      border:1px solid rgba(255,143,143,.16);
      color:#ffd2d2;
    }
    .status-card{
      border:1px solid var(--line2);
      background:
        radial-gradient(circle at top right, rgba(255,214,102,.08), transparent 38%),
        linear-gradient(180deg, rgba(22,18,10,.35), rgba(14,15,22,.96));
    }
    .status-top{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:12px;
      margin-bottom:14px;
    }
    .badge{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:30px;
      padding:0 12px;
      border-radius:999px;
      font-size:12px;
      font-weight:800;
      letter-spacing:.04em;
      border:1px solid rgba(255,255,255,.08);
      background:rgba(255,255,255,.04);
      text-transform:uppercase;
      white-space:nowrap;
    }
    .badge.prepared{color:var(--amber)}
    .badge.pending_execution{color:var(--violet2)}
    .badge.sent{color:var(--blue)}
    .badge.confirmed{color:var(--green)}
    .badge.failed,.badge.cancelled{color:var(--red)}
    .kv{
      display:grid;
      grid-template-columns:120px 1fr;
      gap:8px 12px;
      font-size:13px;
      line-height:1.45;
    }
    .kv .k{color:var(--muted)}
    .kv .v{word-break:break-word}
    .mini-actions{
      display:grid;
      grid-template-columns:repeat(3,1fr);
      gap:10px;
      margin-top:14px;
    }
    .history{margin-top:14px}
    .history-head{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      margin-bottom:12px;
    }
    .history-list{display:grid;gap:10px}
    .history-item{
      padding:14px;
      border-radius:14px;
      border:1px solid rgba(255,255,255,.06);
      background:rgba(255,255,255,.03);
    }
    .history-item .top{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      margin-bottom:8px;
      flex-wrap:wrap;
    }
    .history-item .type{
      font-size:12px;
      font-weight:800;
      letter-spacing:.04em;
      color:var(--violet2);
    }
    .history-item .amt{
      font-size:13px;
      font-weight:800;
      color:var(--gold2);
    }
    .history-item .meta{
      color:var(--muted);
      font-size:12px;
      line-height:1.5;
      word-break:break-word;
    }
    .empty{
      padding:14px;
      border-radius:14px;
      border:1px dashed rgba(255,255,255,.10);
      color:var(--muted);
      font-size:13px;
      text-align:center;
    }
    .footnote{
      margin-top:12px;
      color:var(--muted);
      font-size:12px;
      line-height:1.6;
    }
    @media (max-width: 860px){
      .hero,.claim-grid{grid-template-columns:1fr}
    }
    @media (max-width: 560px){
      .shell{padding:12px 12px 108px}
      .hero-card,.section{padding:16px}
      .stats{grid-template-columns:1fr}
      .actions{grid-template-columns:1fr 1fr}
      .actions .btn-primary{grid-column:1 / -1}
      .mini-actions{grid-template-columns:1fr}
      .kv{grid-template-columns:96px 1fr}
    }
  </style>
</head>
<body>
  <?php require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php'; ?>

  <main class="shell">
    <section class="hero">
      <div class="card hero-card">
        <div class="eyebrow">Claim Module · v7.9</div>
        <h1 class="title">Claim EMA$ On-Chain<br>提取 EMA$ 到链上</h1>
        <p class="subtitle">
          Prepare a claim from your off-chain entitlement, wait for controlled execution, then verify and finalize with visible history.
          从链下权益准备提取，等待受控执行，再完成链上校验与可见历史记录。
        </p>
        <div class="quick">
          <div class="pill">
            <div class="k">User / 用户</div>
            <div class="v"><?= h($displayName) ?></div>
          </div>
          <div class="pill">
            <div class="k">Wallet / 钱包</div>
            <div class="v"><?= h($walletShort) ?></div>
          </div>
          <div class="pill">
            <div class="k">Claim Token / 提取代币</div>
            <div class="v">EMA$</div>
          </div>
          <div class="pill">
            <div class="k">Flow / 流程</div>
            <div class="v">Prepare → Send → Verify</div>
          </div>
        </div>
      </div>

      <div class="card hero-card status-card">
        <div class="eyebrow">Current Wallet</div>
        <h2 style="margin:0 0 8px;font-size:20px">Bound TON Address / 已绑定 TON 地址</h2>
        <p class="subtitle" style="margin-bottom:14px">
          <?= $walletAddress !== '' ? h($walletAddress) : 'No TON wallet bound yet. / 尚未绑定 TON 钱包。' ?>
        </p>
        <div class="footnote">
          Claim execution will only settle to your bound TON wallet. No destination override in UI.
          提取执行只会结算到已绑定 TON 钱包，前端不可改目标地址。
        </div>
      </div>
    </section>

    <section class="claim-grid">
      <div class="card section">
        <h2>Prepare Claim / 准备提取</h2>
        <div class="sub">
          Minimum claim is 1 EMA$. Preparation creates an auditable claim row and reserve row before any on-chain settlement.
          最低提取为 1 EMA$。准备步骤会先建立可审计的 claim 记录与 reserve 记录，再进入链上结算阶段。
        </div>

        <div class="stats">
          <div class="stat">
            <div class="k">Claimable / 可提取</div>
            <div class="v gold" id="claimableAmount">--</div>
          </div>
          <div class="stat">
            <div class="k">Latest Status / 最新状态</div>
            <div class="v" id="latestStatusText">--</div>
          </div>
          <div class="stat">
            <div class="k">Latest Ref / 最新编号</div>
            <div class="v" id="latestRefText">--</div>
          </div>
        </div>

        <div class="field">
          <div class="label">
            <span>Claim Amount / 提取数量</span>
            <span class="hint-link" id="btnUseMax">Use Max / 全部</span>
          </div>
          <div class="input-wrap">
            <div class="token">EMA$</div>
            <input id="claimAmount" type="text" inputmode="decimal" autocomplete="off" placeholder="1.000000">
          </div>
        </div>

        <div class="actions">
          <button class="btn-primary" id="btnPrepareClaim" type="button">Claim Now / 立即提取</button>
          <button class="btn-ghost" id="btnRefreshAll" type="button">Refresh / 刷新</button>
          <button class="btn-soft" id="btnCheckLatest" type="button">Check Latest / 检查最新</button>
        </div>

        <div class="msg" id="claimMsg"></div>

        <div class="footnote">
          Prepare does not send tokens directly. It creates a claim request for controlled execution and later verification.
          准备步骤不会直接发币，只会建立受控执行与后续校验所需的提取请求。
        </div>
      </div>

      <div class="card section status-card">
        <div class="status-top">
          <div>
            <h2>Latest Claim / 最新提取</h2>
            <div class="sub" style="margin:6px 0 0">
              Track the most recent prepared or sent claim from this account.
              跟踪当前账户最新的提取记录。
            </div>
          </div>
          <span class="badge" id="claimBadge">--</span>
        </div>

        <div class="kv">
          <div class="k">Claim Ref</div><div class="v" id="claimRefView">--</div>
          <div class="k">Amount</div><div class="v" id="claimAmountView">--</div>
          <div class="k">Token</div><div class="v" id="claimTokenView">EMA</div>
          <div class="k">Wallet</div><div class="v" id="claimWalletView"><?= h($walletAddress !== '' ? $walletAddress : '--') ?></div>
          <div class="k">TX Hash</div><div class="v" id="claimTxView">--</div>
          <div class="k">Prepared</div><div class="v" id="claimPreparedView">--</div>
          <div class="k">Sent</div><div class="v" id="claimSentView">--</div>
          <div class="k">Confirmed</div><div class="v" id="claimConfirmedView">--</div>
        </div>

        <div class="mini-actions">
          <button class="btn-ghost" id="btnVerifyLatest" type="button">Verify / 校验</button>
          <button class="btn-ghost" id="btnCopyRef" type="button">Copy Ref / 复制编号</button>
          <button class="btn-ghost" id="btnOpenHistory" type="button">Recent Activity / 最近记录</button>
        </div>
      </div>
    </section>

    <section class="card section history">
      <div class="history-head">
        <div>
          <h2>Recent Claim Activity / 最近提取记录</h2>
          <div class="sub" style="margin:6px 0 0">
            Every state-changing claim event must be visible to the user.
            每一个会改变状态的提取事件都必须对用户可见。
          </div>
        </div>
        <button class="btn-ghost" id="btnReloadHistory" type="button" style="min-height:42px">Reload / 刷新</button>
      </div>
      <div class="history-list" id="historyList">
        <div class="empty">Loading...</div>
      </div>
    </section>
  </main>

  <?php require $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/gt-inline.php'; ?>
  <script src="/dashboard/inc/poado-i18n.js"></script>
  <?php require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php'; ?>

  <script>
  (function () {
    'use strict';

    const API_CLAIM = '/rwa/api/storage/claim.php';
    const API_CLAIMABLE = '/rwa/api/storage/claimable.php';
    const API_OVERVIEW = '/rwa/api/storage/overview.php';
    const API_HISTORY = '/rwa/api/storage/history.php';

    const state = {
      claimable: '0.000000',
      latestRef: localStorage.getItem('rwa_claim_latest_ref') || '',
      latestClaim: null,
      history: [],
      busy: false
    };

    const els = {
      amount: document.getElementById('claimAmount'),
      claimable: document.getElementById('claimableAmount'),
      latestStatusText: document.getElementById('latestStatusText'),
      latestRefText: document.getElementById('latestRefText'),
      btnUseMax: document.getElementById('btnUseMax'),
      btnPrepareClaim: document.getElementById('btnPrepareClaim'),
      btnRefreshAll: document.getElementById('btnRefreshAll'),
      btnCheckLatest: document.getElementById('btnCheckLatest'),
      btnVerifyLatest: document.getElementById('btnVerifyLatest'),
      btnCopyRef: document.getElementById('btnCopyRef'),
      btnOpenHistory: document.getElementById('btnOpenHistory'),
      btnReloadHistory: document.getElementById('btnReloadHistory'),
      msg: document.getElementById('claimMsg'),
      badge: document.getElementById('claimBadge'),
      claimRefView: document.getElementById('claimRefView'),
      claimAmountView: document.getElementById('claimAmountView'),
      claimTokenView: document.getElementById('claimTokenView'),
      claimWalletView: document.getElementById('claimWalletView'),
      claimTxView: document.getElementById('claimTxView'),
      claimPreparedView: document.getElementById('claimPreparedView'),
      claimSentView: document.getElementById('claimSentView'),
      claimConfirmedView: document.getElementById('claimConfirmedView'),
      historyList: document.getElementById('historyList')
    };

    function setMsg(text, type) {
      els.msg.className = 'msg show ' + (type === 'ok' ? 'ok' : 'err');
      els.msg.textContent = text;
    }

    function clearMsg() {
      els.msg.className = 'msg';
      els.msg.textContent = '';
    }

    function badgeClass(status) {
      const s = String(status || '').toLowerCase();
      if (s === 'prepared') return 'badge prepared';
      if (s === 'pending_execution') return 'badge pending_execution';
      if (s === 'sent') return 'badge sent';
      if (s === 'confirmed') return 'badge confirmed';
      if (s === 'failed') return 'badge failed';
      if (s === 'cancelled') return 'badge cancelled';
      return 'badge';
    }

    function formatAmount(v) {
      const n = Number(v || 0);
      if (!Number.isFinite(n)) return '--';
      return n.toFixed(6);
    }

    function safeText(v, fallback = '--') {
      const s = String(v ?? '').trim();
      return s === '' ? fallback : s;
    }

    function escapeHtml(str) {
      return String(str).replace(/[&<>"']/g, function (m) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
      });
    }

    async function postJson(url, body) {
      const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify(body || {})
      });

      const text = await res.text();
      let json = null;
      try { json = JSON.parse(text); } catch (e) {}

      if (!res.ok || !json || json.ok !== true) {
        const err = json && json.error ? json.error : ('HTTP_' + res.status);
        throw new Error(err);
      }
      return json;
    }

    async function getJson(url) {
      const res = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      });

      const text = await res.text();
      let json = null;
      try { json = JSON.parse(text); } catch (e) {}

      if (!res.ok || !json || json.ok !== true) {
        throw new Error((json && json.error) ? json.error : ('HTTP_' + res.status));
      }
      return json;
    }

    async function loadClaimable() {
      try {
        const data = await getJson(API_CLAIMABLE);
        const amount =
          data.claimable_ema ??
          (data.claimable && data.claimable.ema) ??
          data.ema ??
          data.amount ??
          '0.000000';

        state.claimable = formatAmount(amount);
        els.claimable.textContent = state.claimable;
        return;
      } catch (e) {}

      try {
        const data = await getJson(API_OVERVIEW);
        const amount =
          data.unclaimed_ema ??
          (data.balances && data.balances.unclaimed_ema) ??
          (data.storage && data.storage.unclaimed_ema) ??
          (data.overview && data.overview.unclaimed_ema) ??
          '0.000000';
        state.claimable = formatAmount(amount);
        els.claimable.textContent = state.claimable;
      } catch (e) {
        state.claimable = '0.000000';
        els.claimable.textContent = '--';
      }
    }

    function renderLatestClaim() {
      const c = state.latestClaim;
      const ref = c ? safeText(c.claim_ref) : (state.latestRef || '--');
      const status = c ? safeText(c.status, '--') : '--';

      els.latestStatusText.textContent = status;
      els.latestRefText.textContent = ref;

      els.badge.className = badgeClass(status);
      els.badge.textContent = status;

      els.claimRefView.textContent = ref;
      els.claimAmountView.textContent = c ? (formatAmount(c.amount) + ' EMA') : '--';
      els.claimTokenView.textContent = c ? safeText(c.token, 'EMA') : 'EMA';
      els.claimWalletView.textContent = c ? safeText(c.wallet_address) : safeText(els.claimWalletView.textContent);
      els.claimTxView.textContent = c ? safeText(c.tx_hash) : '--';
      els.claimPreparedView.textContent = c ? safeText(c.prepared_at) : '--';
      els.claimSentView.textContent = c ? safeText(c.sent_at) : '--';
      els.claimConfirmedView.textContent = c ? safeText(c.confirmed_at) : '--';
    }

    function renderHistory() {
      const list = Array.isArray(state.history) ? state.history : [];
      if (!list.length) {
        els.historyList.innerHTML = '<div class="empty">No claim history yet.</div>';
        return;
      }

      els.historyList.innerHTML = list.map(function (item) {
        const type = safeText(item.event_type || item.type, 'EVENT');
        const amt = formatAmount(item.amount || item.change_amount || 0);
        const token = safeText(item.token, 'EMA');
        const ref = safeText(item.ref || item.claim_ref, '--');
        const tx = safeText(item.tx_hash, '--');
        const at = safeText(item.created_at || item.ts || item.time, '--');

        return `
          <div class="history-item">
            <div class="top">
              <div class="type">${escapeHtml(type)}</div>
              <div class="amt">${escapeHtml(amt)} ${escapeHtml(token)}</div>
            </div>
            <div class="meta">
              Ref: ${escapeHtml(ref)}<br>
              TX: ${escapeHtml(tx)}<br>
              Time: ${escapeHtml(at)}
            </div>
          </div>
        `;
      }).join('');
    }

    async function loadLatestClaim(forceRef) {
      const ref = (forceRef || state.latestRef || '').trim();
      if (!ref) {
        state.latestClaim = null;
        renderLatestClaim();
        return;
      }

      try {
        const data = await postJson(API_CLAIM, { action: 'status', claim_ref: ref });
        state.latestClaim = data.claim || null;
        state.latestRef = state.latestClaim && state.latestClaim.claim_ref ? state.latestClaim.claim_ref : ref;
        localStorage.setItem('rwa_claim_latest_ref', state.latestRef);
        renderLatestClaim();
      } catch (e) {
        state.latestClaim = null;
        renderLatestClaim();
      }
    }

    async function loadHistory() {
      try {
        const data = await getJson(API_HISTORY);
        const rows = Array.isArray(data.history)
          ? data.history
          : Array.isArray(data.rows)
            ? data.rows
            : Array.isArray(data.items)
              ? data.items
              : [];

        state.history = rows.filter(function (row) {
          const t = String(row.event_type || row.type || '').toUpperCase();
          return t.indexOf('CLAIM_') === 0;
        }).slice(0, 8);

        renderHistory();
      } catch (e) {
        els.historyList.innerHTML = '<div class="empty">History unavailable right now.</div>';
      }
    }

    async function prepareClaim() {
      if (state.busy) return;
      clearMsg();

      const amount = String(els.amount.value || '').trim();
      if (!amount) {
        setMsg('Please enter claim amount.', 'err');
        return;
      }

      state.busy = true;
      els.btnPrepareClaim.disabled = true;

      try {
        const data = await postJson(API_CLAIM, {
          action: 'prepare',
          amount: amount
        });

        const ref = data.claim_ref || '';
        if (ref) {
          state.latestRef = ref;
          localStorage.setItem('rwa_claim_latest_ref', ref);
        }

        setMsg('Claim prepared: ' + ref, 'ok');
        els.amount.value = '';
        await loadClaimable();
        await loadLatestClaim(ref);
        await loadHistory();
      } catch (e) {
        setMsg('Prepare failed: ' + e.message, 'err');
      } finally {
        state.busy = false;
        els.btnPrepareClaim.disabled = false;
      }
    }

    async function verifyLatest() {
      if (!state.latestRef) {
        setMsg('No latest claim ref found.', 'err');
        return;
      }

      try {
        const data = await postJson(API_CLAIM, {
          action: 'verify',
          claim_ref: state.latestRef
        });
        setMsg('Verify result: ' + (data.status || 'CONFIRMED'), 'ok');
        await loadClaimable();
        await loadLatestClaim(state.latestRef);
        await loadHistory();
      } catch (e) {
        setMsg('Verify failed: ' + e.message, 'err');
      }
    }

    async function refreshAll() {
      clearMsg();
      await loadClaimable();
      await loadLatestClaim();
      await loadHistory();
    }

    function bind() {
      els.btnUseMax.addEventListener('click', function () {
        if (state.claimable && state.claimable !== '--') {
          els.amount.value = state.claimable;
        }
      });

      els.btnPrepareClaim.addEventListener('click', prepareClaim);
      els.btnVerifyLatest.addEventListener('click', verifyLatest);
      els.btnRefreshAll.addEventListener('click', refreshAll);
      els.btnCheckLatest.addEventListener('click', function () { loadLatestClaim(); });
      els.btnReloadHistory.addEventListener('click', loadHistory);

      els.btnCopyRef.addEventListener('click', async function () {
        const ref = state.latestRef || '';
        if (!ref) {
          setMsg('No claim ref to copy.', 'err');
          return;
        }
        try {
          await navigator.clipboard.writeText(ref);
          setMsg('Copied claim ref: ' + ref, 'ok');
        } catch (e) {
          setMsg('Copy failed.', 'err');
        }
      });

      els.btnOpenHistory.addEventListener('click', function () {
        const target = document.getElementById('historyList');
        if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    }

    async function init() {
      bind();
      renderLatestClaim();
      await refreshAll();
    }

    init();
  })();
  </script>
</body>
</html>