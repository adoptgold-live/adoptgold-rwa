<?php
declare(strict_types=1);

/**
 * /rwa/mining/binding-dashboard.php
 * MY MINER BINDING DASHBOARD
 * Instant EN | 中 switcher, no reload
 */

require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

db_connect();
$pdo = $GLOBALS['pdo'] ?? null;

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$user = function_exists('session_user') ? (session_user() ?: []) : [];
$userId = (int)($user['id'] ?? 0);
$wallet = trim((string)($user['wallet_address'] ?? $user['wallet'] ?? ''));

if ($userId <= 0 || $wallet === '') {
    header('Location: /rwa/index.php');
    exit;
}

require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
<title>My Miner Binding Dashboard · POAdo</title>
<style>
:root{
  --bg:#05050a;--fg:#efe7ff;--mut:#bda9df;--p1:#7c3aed;--p2:#a855f7;
  --ok:#34d399;--err:#fb7185;--br:rgba(168,85,247,.25);--shadow:0 18px 40px rgba(0,0,0,.55);
  --solid:#120b1a;--blue:rgba(96,165,250,.18)
}
html,body{
  margin:0;
  background:radial-gradient(900px 520px at 18% 0%, rgba(168,85,247,.18), transparent 60%), var(--bg);
  color:var(--fg);
  font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace
}
.page{max-width:1180px;margin:0 auto;padding:14px 12px 110px}
.title{display:flex;align-items:flex-end;justify-content:space-between;gap:10px;margin:8px 2px 12px}
.title h1{margin:0;font-size:18px;letter-spacing:.06em}
.title .sub{color:var(--mut);font-size:12px}

.lang-switch{
  display:flex;justify-content:flex-end;align-items:center;gap:8px;
  margin:0 2px 12px 2px;font-size:12px;color:var(--mut)
}
.lang-switch button{
  appearance:none;border:none;background:transparent;color:var(--mut);
  font:inherit;font-weight:900;cursor:pointer;padding:0
}
.lang-switch button.active{color:#fff}
.lang-switch .sep{opacity:.6}

.topActions{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}
.btn{
  appearance:none;border:1px solid var(--br);background:rgba(124,58,237,.22);color:var(--fg);
  padding:12px 14px;border-radius:14px;font-weight:1000;letter-spacing:.06em;cursor:pointer;text-decoration:none
}
.btn.green{border-color:rgba(52,211,153,.35);background:rgba(52,211,153,.12)}
.btn.ghost{background:rgba(0,0,0,.25)}

.grid4{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
@media(max-width:980px){.grid4{grid-template-columns:repeat(2,1fr)}}
@media(max-width:560px){.grid4{grid-template-columns:1fr}}

.stat{
  border:1px solid var(--br);border-radius:16px;background:linear-gradient(180deg, rgba(124,58,237,.12), rgba(0,0,0,.35));
  box-shadow:var(--shadow);padding:14px
}
.stat .k{font-size:12px;color:var(--mut);letter-spacing:.08em}
.stat .v{margin-top:8px;font-size:26px;font-weight:1100}

.twoCol{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px}
@media(max-width:900px){.twoCol{grid-template-columns:1fr}}

.card{
  border:1px solid var(--br);border-radius:16px;background:linear-gradient(180deg, rgba(124,58,237,.12), rgba(0,0,0,.35));
  box-shadow:var(--shadow);overflow:hidden
}
.card .hd{
  padding:12px 14px;border-bottom:1px solid rgba(168,85,247,.18);
  display:flex;align-items:center;justify-content:space-between;gap:10px
}
.card .hd .k{font-weight:900;letter-spacing:.08em;font-size:12px;color:#eaddff}
.card .hd .r{font-size:12px;color:var(--mut)}
.card .bd{padding:14px}

.qrWrap{
  border:1px solid rgba(255,255,255,.08);border-radius:14px;background:rgba(0,0,0,.28);
  min-height:240px;display:flex;align-items:center;justify-content:center
}
.qrWrap img,.qrWrap svg{max-width:100%;height:auto}

.infoGrid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
@media(max-width:560px){.infoGrid{grid-template-columns:1fr}}
.infoMini{
  border:1px solid rgba(255,255,255,.07);border-radius:12px;background:rgba(0,0,0,.28);padding:10px
}
.infoMini .t{font-size:11px;color:var(--mut);margin-bottom:6px}
.infoMini .v{font-size:14px;font-weight:1000;color:#eef6ff}

.linkBox{
  margin-top:10px;border:1px solid rgba(255,255,255,.07);border-radius:12px;background:rgba(0,0,0,.28);padding:10px
}
.linkBox .t{font-size:11px;color:var(--mut);margin-bottom:6px}
.linkValue{font-size:12px;color:#dcecff;word-break:break-all;line-height:1.6}

.section{margin-top:12px}
.tableWrap{
  border:1px solid var(--br);border-radius:16px;background:linear-gradient(180deg, rgba(124,58,237,.12), rgba(0,0,0,.35));
  box-shadow:var(--shadow);overflow:hidden
}
.sectionHd{
  padding:12px 14px;border-bottom:1px solid rgba(168,85,247,.18);
  display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap
}
.sectionHd .k{font-weight:900;letter-spacing:.08em;font-size:12px;color:#eaddff}
.sectionHd .r{font-size:12px;color:var(--mut)}
.filters{display:flex;gap:8px;flex-wrap:wrap}
.inp{
  border:1px solid rgba(255,255,255,.1);background:rgba(0,0,0,.25);color:var(--fg);
  border-radius:12px;padding:10px 12px;font:inherit
}
.tableScroller{overflow:auto}
.table{
  width:100%;border-collapse:collapse;font-size:12px
}
.table th,.table td{
  padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.06);text-align:left;vertical-align:top
}
.table th{color:#d9ccff;font-weight:1000;background:rgba(0,0,0,.16)}
.badge{
  display:inline-flex;align-items:center;gap:6px;border:1px solid rgba(255,255,255,.1);border-radius:999px;
  padding:5px 9px;font-size:11px;background:rgba(0,0,0,.25)
}
.badge.ok{color:#caffdc;border-color:rgba(52,211,153,.25)}
.badge.warn{color:#fff0b3;border-color:rgba(245,214,123,.25)}
.badge.err{color:#ffd0d9;border-color:rgba(251,113,133,.25)}

.empty{padding:18px;color:var(--mut);font-size:12px}
.errBox{
  display:none;margin-top:12px;border:1px solid rgba(251,113,133,.35);background:rgba(251,113,133,.10);
  border-radius:14px;padding:10px 12px;color:#ffd0d9;font-size:12px
}
.navSpace{height:90px}
</style>
</head>
<body>

<div class="page">
  <div class="title">
    <div>
      <h1 data-i18n="page_title">MY MINER BINDING DASHBOARD</h1>
      <div class="sub" data-i18n="page_subtitle">Binding reward = 1% from bound adoptee mining. Extra reward, not counted toward your own daily cap.</div>
    </div>
    <div class="sub">Wallet: <?=h($wallet)?></div>
  </div>

  <div class="lang-switch">
    <button type="button" id="lang-en" class="active">EN</button>
    <span class="sep">|</span>
    <button type="button" id="lang-zh">中</button>
  </div>

  <div class="topActions">
    <a class="btn" href="/rwa/mining/" data-i18n="back_to_mining">BACK TO MINING</a>
    <button class="btn ghost" id="copyBindLinkBtn" type="button" data-i18n="copy_bind_link">COPY BIND LINK</button>
    <button class="btn green" id="refreshAllBtn" type="button" data-i18n="refresh_live">REFRESH LIVE</button>
  </div>

  <div class="grid4">
    <div class="stat"><div class="k" data-i18n="my_binding_cap">MY BINDING CAP</div><div class="v" id="sumBindingCap">0</div></div>
    <div class="stat"><div class="k" data-i18n="remaining_binding">REMAINING BINDING</div><div class="v" id="sumBindingRemaining">0</div></div>
    <div class="stat"><div class="k" data-i18n="today_binding_reward">TODAY BINDING REWARD</div><div class="v" id="sumBindingToday">0</div></div>
    <div class="stat"><div class="k" data-i18n="total_binding_reward">TOTAL BINDING REWARD</div><div class="v" id="sumBindingTotal">0</div></div>
  </div>

  <div class="twoCol">
    <div class="card">
      <div class="hd">
        <div class="k" data-i18n="binding_control">BINDING CONTROL</div>
        <div class="r" id="bindingControlStatus">LOADING</div>
      </div>
      <div class="bd">
        <div class="qrWrap" id="bindingDashboardQrWrap">
          <div id="bindingDashboardQr">QR</div>
        </div>

        <div class="linkBox">
          <div class="t" data-i18n="binding_link">Binding Link</div>
          <div class="linkValue" id="bindingDashboardLink">Loading…</div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="hd">
        <div class="k" data-i18n="binding_capacity">BINDING CAPACITY</div>
        <div class="r" id="bindingTierText">—</div>
      </div>
      <div class="bd">
        <div class="infoGrid">
          <div class="infoMini"><div class="t" data-i18n="binding_code">Binding Code</div><div class="v" id="bindingDashboardCode">—</div></div>
          <div class="infoMini"><div class="t" data-i18n="bound_miners">Bound Miners</div><div class="v" id="bindingDashboardCount">0</div></div>
          <div class="infoMini"><div class="t" data-i18n="binding_cap">Binding Cap</div><div class="v" id="bindingDashboardCap">0</div></div>
          <div class="infoMini"><div class="t" data-i18n="remaining_binding">Remaining Binding</div><div class="v" id="bindingDashboardRemaining">0</div></div>
          <div class="infoMini"><div class="t" data-i18n="today_binding_reward">Today Binding Reward</div><div class="v" id="bindingDashboardToday">0</div></div>
          <div class="infoMini"><div class="t" data-i18n="total_binding_reward">Total Binding Reward</div><div class="v" id="bindingDashboardTotal">0</div></div>
          <div class="infoMini"><div class="t" data-i18n="binding_status">Binding Status</div><div class="v" id="bindingDashboardStatus">—</div></div>
          <div class="infoMini"><div class="t" data-i18n="last_event">Last Event</div><div class="v" id="bindingDashboardLastEvent">—</div></div>
        </div>
      </div>
    </div>
  </div>

  <div class="section tableWrap">
    <div class="sectionHd">
      <div class="k" data-i18n="my_binding_listing">MY BINDING LISTING</div>
      <div class="filters">
        <input class="inp" id="bindingSearch" type="text" placeholder="Search nickname / wallet" />
        <button class="btn ghost" id="bindingSearchBtn" type="button" data-i18n="search">SEARCH</button>
      </div>
    </div>
    <div class="tableScroller">
      <table class="table">
        <thead>
          <tr>
            <th data-i18n="col_nickname">Nickname</th>
            <th data-i18n="col_wallet">Wallet</th>
            <th data-i18n="col_status">Status</th>
            <th data-i18n="col_bound_date">Bound Date</th>
            <th data-i18n="col_today_mined">Today Mined</th>
            <th data-i18n="col_today_commission">Today Commission</th>
            <th data-i18n="col_total_commission">Total Commission</th>
            <th data-i18n="col_last_active">Last Active</th>
          </tr>
        </thead>
        <tbody id="bindingListBody">
          <tr><td colspan="8" class="empty" data-i18n="loading_binding_listing">Loading binding listing…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="section tableWrap">
    <div class="sectionHd">
      <div class="k" data-i18n="top100_live">TOP 100 MINERS LIVE</div>
      <div class="r" id="top100Stamp">Live</div>
    </div>
    <div class="tableScroller">
      <table class="table">
        <thead>
          <tr>
            <th data-i18n="col_rank">Rank</th>
            <th data-i18n="col_miner">Miner</th>
            <th data-i18n="col_tier">Tier</th>
            <th data-i18n="col_today_wems">Today wEMS</th>
            <th data-i18n="col_total_wems">Total wEMS</th>
            <th data-i18n="col_bound_count">Bound Count</th>
            <th data-i18n="col_binding_reward_today">Binding Reward Today</th>
          </tr>
        </thead>
        <tbody id="top100Body">
          <tr><td colspan="7" class="empty" data-i18n="loading_top100">Loading top 100 miners…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="errBox" id="dashboardError"></div>
  <div class="navSpace"></div>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php'; ?>

<script>
(() => {
  const API = {
    summary: '/rwa/api/mining/binding-summary.php',
    list: '/rwa/api/mining/binding-list.php',
    top100: '/rwa/api/mining/top100-live.php',
  };

  const $ = (id) => document.getElementById(id);
  const UI = {
    err: $('dashboardError'),

    sumBindingCap: $('sumBindingCap'),
    sumBindingRemaining: $('sumBindingRemaining'),
    sumBindingToday: $('sumBindingToday'),
    sumBindingTotal: $('sumBindingTotal'),

    bindingControlStatus: $('bindingControlStatus'),
    bindingDashboardQr: $('bindingDashboardQr'),
    bindingDashboardLink: $('bindingDashboardLink'),
    bindingTierText: $('bindingTierText'),
    bindingDashboardCode: $('bindingDashboardCode'),
    bindingDashboardCount: $('bindingDashboardCount'),
    bindingDashboardCap: $('bindingDashboardCap'),
    bindingDashboardRemaining: $('bindingDashboardRemaining'),
    bindingDashboardToday: $('bindingDashboardToday'),
    bindingDashboardTotal: $('bindingDashboardTotal'),
    bindingDashboardStatus: $('bindingDashboardStatus'),
    bindingDashboardLastEvent: $('bindingDashboardLastEvent'),

    bindingSearch: $('bindingSearch'),
    bindingSearchBtn: $('bindingSearchBtn'),
    bindingListBody: $('bindingListBody'),

    top100Body: $('top100Body'),
    top100Stamp: $('top100Stamp'),

    copyBindLinkBtn: $('copyBindLinkBtn'),
    refreshAllBtn: $('refreshAllBtn'),

    langEn: $('lang-en'),
    langZh: $('lang-zh'),
  };

  const dict = {
    en: {
      page_title: 'MY MINER BINDING DASHBOARD',
      page_subtitle: 'Binding reward = 1% from bound adoptee mining. Extra reward, not counted toward your own daily cap.',
      back_to_mining: 'BACK TO MINING',
      copy_bind_link: 'COPY BIND LINK',
      refresh_live: 'REFRESH LIVE',
      my_binding_cap: 'MY BINDING CAP',
      remaining_binding: 'REMAINING BINDING',
      today_binding_reward: 'TODAY BINDING REWARD',
      total_binding_reward: 'TOTAL BINDING REWARD',
      binding_control: 'BINDING CONTROL',
      binding_link: 'Binding Link',
      binding_capacity: 'BINDING CAPACITY',
      binding_code: 'Binding Code',
      bound_miners: 'Bound Miners',
      binding_cap: 'Binding Cap',
      binding_status: 'Binding Status',
      last_event: 'Last Event',
      my_binding_listing: 'MY BINDING LISTING',
      search: 'SEARCH',
      col_nickname: 'Nickname',
      col_wallet: 'Wallet',
      col_status: 'Status',
      col_bound_date: 'Bound Date',
      col_today_mined: 'Today Mined',
      col_today_commission: 'Today Commission',
      col_total_commission: 'Total Commission',
      col_last_active: 'Last Active',
      loading_binding_listing: 'Loading binding listing…',
      top100_live: 'TOP 100 MINERS LIVE',
      col_rank: 'Rank',
      col_miner: 'Miner',
      col_tier: 'Tier',
      col_today_wems: 'Today wEMS',
      col_total_wems: 'Total wEMS',
      col_bound_count: 'Bound Count',
      col_binding_reward_today: 'Binding Reward Today',
      loading_top100: 'Loading top 100 miners…'
    },
    zh: {
      page_title: '我的矿工绑定仪表板',
      page_subtitle: '绑定奖励 = 被绑定矿工挖矿收益的 1%，且不计入你自己的每日挖矿上限。',
      back_to_mining: '返回挖矿页',
      copy_bind_link: '复制绑定链接',
      refresh_live: '刷新实时数据',
      my_binding_cap: '我的绑定上限',
      remaining_binding: '剩余绑定名额',
      today_binding_reward: '今日绑定奖励',
      total_binding_reward: '总绑定奖励',
      binding_control: '绑定控制',
      binding_link: '绑定链接',
      binding_capacity: '绑定容量',
      binding_code: '绑定代码',
      bound_miners: '已绑定矿工',
      binding_cap: '绑定上限',
      binding_status: '绑定状态',
      last_event: '最后事件',
      my_binding_listing: '我的绑定列表',
      search: '搜索',
      col_nickname: '昵称',
      col_wallet: '钱包',
      col_status: '状态',
      col_bound_date: '绑定日期',
      col_today_mined: '今日挖矿',
      col_today_commission: '今日佣金',
      col_total_commission: '总佣金',
      col_last_active: '最后活跃',
      loading_binding_listing: '正在加载绑定列表…',
      top100_live: '实时前100矿工',
      col_rank: '排名',
      col_miner: '矿工',
      col_tier: '等级',
      col_today_wems: '今日 wEMS',
      col_total_wems: '总 wEMS',
      col_bound_count: '绑定人数',
      col_binding_reward_today: '今日绑定奖励',
      loading_top100: '正在加载前100矿工…'
    }
  };

  let currentLang = 'en';

  function applyLang(lang) {
    currentLang = lang;
    document.querySelectorAll('[data-i18n]').forEach((el) => {
      const key = el.getAttribute('data-i18n');
      if (dict[lang] && dict[lang][key]) el.textContent = dict[lang][key];
    });
    UI.langEn?.classList.toggle('active', lang === 'en');
    UI.langZh?.classList.toggle('active', lang === 'zh');
    try { localStorage.setItem('binding_dashboard_lang', lang); } catch {}
  }

  UI.langEn?.addEventListener('click', () => applyLang('en'));
  UI.langZh?.addEventListener('click', () => applyLang('zh'));
  try {
    const saved = localStorage.getItem('binding_dashboard_lang');
    applyLang(saved === 'zh' ? 'zh' : 'en');
  } catch {
    applyLang('en');
  }

  function fmt(n, dp=8) {
    n = Number(n || 0);
    return n.toFixed(dp).replace(/0+$/,'').replace(/\.$/, '');
  }
  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, m => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
    }[m]));
  }
  function showErr(msg) {
    if (!UI.err) return;
    UI.err.style.display = msg ? 'block' : 'none';
    UI.err.textContent = msg || '';
  }
  async function fetchJSON(url, opts={}) {
    const res = await fetch(url, { credentials:'include', cache:'no-store', ...opts });
    const txt = await res.text();
    if (txt.trim().startsWith('<')) throw new Error('SERVER_RETURNED_HTML');
    let j;
    try { j = JSON.parse(txt); } catch { throw new Error('INVALID_JSON'); }
    return j;
  }
  function badge(text, cls='') {
    return `<span class="badge ${cls}">${esc(text)}</span>`;
  }

  function renderSummary(r) {
    const s = r.summary || {};
    UI.sumBindingCap.textContent = String(s.binding_cap ?? 0);
    UI.sumBindingRemaining.textContent = String(s.remaining_binding ?? 0);
    UI.sumBindingToday.textContent = fmt(s.today_binding_wems ?? 0, 8);
    UI.sumBindingTotal.textContent = fmt(s.total_binding_wems ?? 0, 8);

    UI.bindingControlStatus.textContent = String(s.status || 'ACTIVE').toUpperCase().replaceAll('_', ' ');
    UI.bindingTierText.textContent = String(s.tier_label || '—');
    UI.bindingDashboardCode.textContent = String(s.binding_code || '—');
    UI.bindingDashboardLink.textContent = String(s.binding_link || '—');
    UI.bindingDashboardCount.textContent = String(s.bound_miners_count ?? 0);
    UI.bindingDashboardCap.textContent = String(s.binding_cap ?? 0);
    UI.bindingDashboardRemaining.textContent = String(s.remaining_binding ?? 0);
    UI.bindingDashboardToday.textContent = fmt(s.today_binding_wems ?? 0, 8);
    UI.bindingDashboardTotal.textContent = fmt(s.total_binding_wems ?? 0, 8);
    UI.bindingDashboardStatus.textContent = String(s.status || 'ACTIVE').toUpperCase().replaceAll('_', ' ');
    UI.bindingDashboardLastEvent.textContent = String(s.last_event || '—');

    const qr = s.binding_qr_data_uri || '';
    if (qr) {
      UI.bindingDashboardQr.innerHTML = `<img src="${esc(qr)}" alt="Binding QR">`;
    } else {
      UI.bindingDashboardQr.textContent = String(s.binding_code || 'QR');
    }
  }

  function renderBindingList(r) {
    const rows = Array.isArray(r.rows) ? r.rows : [];
    if (!rows.length) {
      UI.bindingListBody.innerHTML = `<tr><td colspan="8" class="empty">${dict[currentLang].loading_binding_listing}</td></tr>`;
      return;
    }

    UI.bindingListBody.innerHTML = rows.map(row => {
      const status = String(row.status || 'active').toLowerCase();
      const badgeCls = status === 'active' ? 'ok' : (status === 'paused' ? 'warn' : '');
      return `
        <tr>
          <td>${esc(row.nickname || '—')}</td>
          <td>${esc(row.wallet_short || row.wallet || '—')}</td>
          <td>${badge(row.status || 'ACTIVE', badgeCls)}</td>
          <td>${esc(row.bound_at || '—')}</td>
          <td>${esc(fmt(row.today_mined_wems || 0, 8))}</td>
          <td>${esc(fmt(row.today_binding_wems || 0, 8))}</td>
          <td>${esc(fmt(row.total_binding_wems || 0, 8))}</td>
          <td>${esc(row.last_active_at || '—')}</td>
        </tr>
      `;
    }).join('');
  }

  function renderTop100(r) {
    const rows = Array.isArray(r.rows) ? r.rows : [];
    UI.top100Stamp.textContent = String(r.live_ts || 'Live');

    if (!rows.length) {
      UI.top100Body.innerHTML = `<tr><td colspan="7" class="empty">${dict[currentLang].loading_top100}</td></tr>`;
      return;
    }

    UI.top100Body.innerHTML = rows.map(row => `
      <tr>
        <td>${esc(row.rank ?? '—')}</td>
        <td>${esc(row.nickname || row.wallet_short || '—')}</td>
        <td>${esc(row.tier_label || row.tier || '—')}</td>
        <td>${esc(fmt(row.today_mined_wems || 0, 8))}</td>
        <td>${esc(fmt(row.total_mined_wems || 0, 8))}</td>
        <td>${esc(row.bound_count ?? 0)}</td>
        <td>${esc(fmt(row.today_binding_wems || 0, 8))}</td>
      </tr>
    `).join('');
  }

  async function loadSummary() {
    const r = await fetchJSON(API.summary);
    if (!r.ok) throw new Error(r.message || r.error || 'SUMMARY_FAIL');
    renderSummary(r);
  }

  async function loadBindingList(search='') {
    const q = search ? ('?q=' + encodeURIComponent(search)) : '';
    const r = await fetchJSON(API.list + q);
    if (!r.ok) throw new Error(r.message || r.error || 'BINDING_LIST_FAIL');
    renderBindingList(r);
  }

  async function loadTop100() {
    const r = await fetchJSON(API.top100);
    if (!r.ok) throw new Error(r.message || r.error || 'TOP100_FAIL');
    renderTop100(r);
  }

  async function refreshAll() {
    try {
      showErr('');
      await loadSummary();
      await loadBindingList(UI.bindingSearch.value || '');
      await loadTop100();
    } catch (e) {
      showErr(String(e.message || e));
    }
  }

  document.addEventListener('DOMContentLoaded', async () => {
    UI.copyBindLinkBtn?.addEventListener('click', async (e) => {
      e.preventDefault();
      try { await navigator.clipboard.writeText(UI.bindingDashboardLink.textContent || ''); } catch {}
    });

    UI.refreshAllBtn?.addEventListener('click', async (e) => {
      e.preventDefault();
      await refreshAll();
    });

    UI.bindingSearchBtn?.addEventListener('click', async (e) => {
      e.preventDefault();
      try {
        showErr('');
        await loadBindingList(UI.bindingSearch.value || '');
      } catch (err) {
        showErr(String(err.message || err));
      }
    });

    await refreshAll();
  });
})();
</script>
</body>
</html>
