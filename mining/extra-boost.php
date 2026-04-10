<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/gt.php';

$user = function_exists('session_user') ? (session_user() ?: []) : [];
$userId = (int)($user['id'] ?? 0);
$wallet = trim((string)($user['wallet_address'] ?? $user['wallet'] ?? ''));

if ($userId <= 0 || $wallet === '') {
    header('Location: /rwa/index.php');
    exit;
}

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"/>
  <title>Extra Boost Premium Lounge</title>
  <style>
    :root{
      --bg:#060509;
      --panel:#0f0a17;
      --panel2:#151024;
      --fg:#f6ecff;
      --mut:#bdaed2;
      --line:rgba(183,126,255,.18);
      --gold1:#f7df95;
      --gold2:#d7a93d;
      --gold3:#7a5714;
      --green:#34d399;
      --red:#fb7185;
      --violet:#8b5cf6;
      --cyan:#22d3ee;
      --shadow:0 20px 50px rgba(0,0,0,.45);
    }
    *{box-sizing:border-box}
    html,body{
      margin:0;
      background:
        radial-gradient(900px 500px at 15% 0%, rgba(139,92,246,.20), transparent 60%),
        radial-gradient(700px 420px at 100% 10%, rgba(247,223,149,.12), transparent 52%),
        var(--bg);
      color:var(--fg);
      font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    }
    body{
      padding-bottom:110px;
    }
    .page{
      max-width:1180px;
      margin:0 auto;
      padding:14px 12px 110px;
    }
    .lang-switch{
      display:flex;
      justify-content:flex-end;
      align-items:center;
      gap:8px;
      margin:0 2px 12px 2px;
      font-size:12px;
      color:var(--mut);
    }
    .lang-switch button{
      appearance:none;
      border:none;
      background:transparent;
      color:var(--mut);
      font:inherit;
      font-weight:900;
      cursor:pointer;
      padding:0;
    }
    .lang-switch button.active{color:#fff}
    .lang-switch .sep{opacity:.6}

    .hero{
      position:relative;
      border:1px solid rgba(247,223,149,.22);
      border-radius:22px;
      background:
        radial-gradient(180px 70px at 18% 18%, rgba(255,255,255,.18), transparent 60%),
        linear-gradient(180deg, rgba(247,223,149,.08), rgba(139,92,246,.08) 45%, rgba(0,0,0,.30));
      box-shadow:var(--shadow);
      overflow:hidden;
      padding:18px 18px 16px;
    }
    .hero::before{
      content:"";
      position:absolute;
      top:0; left:-28%;
      width:28%; height:100%;
      background:linear-gradient(90deg, rgba(255,255,255,0), rgba(255,245,214,.18), rgba(255,255,255,0));
      transform:skewX(-18deg);
      animation:shine 4.2s linear infinite;
    }
    @keyframes shine{
      0%{left:-34%}
      100%{left:134%}
    }
    .hero-top{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:14px;
      flex-wrap:wrap;
    }
    .hero-kicker{
      font-size:12px;
      color:var(--gold1);
      letter-spacing:.18em;
      margin-bottom:8px;
      text-transform:uppercase;
    }
    .hero h1{
      margin:0;
      font-size:28px;
      line-height:1.1;
      letter-spacing:.03em;
    }
    .hero-sub{
      margin-top:10px;
      max-width:760px;
      color:var(--mut);
      font-size:13px;
      line-height:1.65;
    }
    .wallet-chip{
      border:1px solid rgba(255,255,255,.10);
      background:rgba(0,0,0,.22);
      border-radius:999px;
      padding:10px 14px;
      font-size:12px;
      color:#efe4c0;
      white-space:nowrap;
    }

    .stats{
      margin-top:14px;
      display:grid;
      grid-template-columns:repeat(4,1fr);
      gap:12px;
    }
    @media (max-width:980px){
      .stats{grid-template-columns:repeat(2,1fr)}
    }
    @media (max-width:560px){
      .stats{grid-template-columns:1fr}
    }
    .stat{
      border:1px solid var(--line);
      border-radius:18px;
      background:linear-gradient(180deg, rgba(255,255,255,.03), rgba(0,0,0,.22));
      padding:14px;
      min-height:92px;
    }
    .stat .k{
      font-size:11px;
      color:var(--mut);
      letter-spacing:.10em;
      text-transform:uppercase;
    }
    .stat .v{
      margin-top:8px;
      font-size:26px;
      font-weight:1000;
    }
    .stat .s{
      margin-top:6px;
      font-size:12px;
      color:#d9cfa8;
    }

    .grid{
      margin-top:14px;
      display:grid;
      grid-template-columns:1.12fr .88fr;
      gap:14px;
    }
    @media (max-width:980px){
      .grid{grid-template-columns:1fr}
    }

    .card{
      border:1px solid var(--line);
      border-radius:20px;
      background:linear-gradient(180deg, rgba(124,58,237,.10), rgba(0,0,0,.26));
      box-shadow:var(--shadow);
      overflow:hidden;
    }
    .card-hd{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      padding:14px 16px;
      border-bottom:1px solid rgba(255,255,255,.06);
    }
    .card-hd .t{
      font-size:12px;
      font-weight:1000;
      letter-spacing:.12em;
      color:#ead8ff;
      text-transform:uppercase;
    }
    .card-hd .r{
      font-size:12px;
      color:var(--mut);
    }
    .card-bd{padding:16px}

    .boost-main{
      display:grid;
      grid-template-columns:1fr;
      gap:14px;
    }

    .premium-amount{
      border:1px solid rgba(247,223,149,.22);
      border-radius:18px;
      background:
        radial-gradient(180px 60px at 20% 0%, rgba(255,255,255,.08), transparent 60%),
        linear-gradient(180deg, rgba(247,223,149,.06), rgba(0,0,0,.20));
      padding:16px;
    }
    .premium-amount .headline{
      font-size:12px;
      color:#f5dfad;
      letter-spacing:.10em;
      text-transform:uppercase;
      margin-bottom:10px;
    }
    .big-ema{
      font-size:34px;
      font-weight:1000;
      color:#fff4d1;
    }
    .subline{
      margin-top:8px;
      color:var(--mut);
      font-size:12px;
      line-height:1.6;
    }

    .slider-card{
      border:1px solid rgba(255,255,255,.08);
      border-radius:18px;
      background:rgba(0,0,0,.24);
      padding:16px;
    }
    .slider-top{
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:10px;
      font-size:12px;
      color:var(--mut);
      margin-bottom:10px;
    }
    .slider-value{
      font-size:20px;
      font-weight:1000;
      color:#fff0c7;
    }
    .slider{
      width:100%;
      accent-color:#d7a93d;
      cursor:pointer;
    }
    .slider-meta{
      margin-top:10px;
      display:flex;
      justify-content:space-between;
      gap:8px;
      font-size:11px;
      color:#d5c9ec;
    }

    .calc-grid{
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:10px;
      margin-top:14px;
    }
    @media (max-width:560px){
      .calc-grid{grid-template-columns:1fr}
    }
    .mini{
      border:1px solid rgba(255,255,255,.07);
      border-radius:14px;
      background:rgba(0,0,0,.22);
      padding:12px;
    }
    .mini .k{
      font-size:11px;
      color:var(--mut);
      margin-bottom:6px;
    }
    .mini .v{
      font-size:18px;
      font-weight:1000;
      color:#f8f2ff;
    }

    .actions{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      margin-top:14px;
    }
    .btn{
      appearance:none;
      border:1px solid rgba(255,255,255,.10);
      background:rgba(0,0,0,.24);
      color:var(--fg);
      padding:13px 16px;
      border-radius:14px;
      font:inherit;
      font-weight:1000;
      letter-spacing:.05em;
      cursor:pointer;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:50px;
    }
    .btn:disabled{
      opacity:.55;
      cursor:not-allowed;
    }
    .btn-gold{
      position:relative;
      overflow:hidden;
      border-color:rgba(247,223,149,.45);
      background:
        radial-gradient(140px 48px at 18% 18%, rgba(255,255,255,.18), transparent 60%),
        linear-gradient(180deg, rgba(255,224,130,.20), rgba(184,134,11,.22) 42%, rgba(60,38,6,.88));
      color:#fff3cf;
      box-shadow:
        inset 0 0 0 1px rgba(255,239,184,.12),
        0 0 18px rgba(245,214,123,.18),
        0 0 34px rgba(184,134,11,.12);
      text-shadow:0 1px 0 rgba(0,0,0,.45), 0 0 10px rgba(255,221,128,.18);
      min-width:240px;
    }
    .btn-gold::before{
      content:"";
      position:absolute;
      top:0; left:-34%;
      width:34%; height:100%;
      background:linear-gradient(90deg, rgba(255,255,255,0), rgba(255,247,210,.34), rgba(255,255,255,0));
      transform:skewX(-20deg);
      animation:goldShine 3.1s linear infinite;
    }
    @keyframes goldShine{
      0%{left:-38%}
      100%{left:132%}
    }
    .btn-ghost{
      border-color:rgba(183,126,255,.24);
      color:#ead8ff;
    }

    .lounge-note{
      margin-top:12px;
      border:1px solid rgba(255,255,255,.06);
      border-radius:14px;
      background:rgba(0,0,0,.20);
      padding:12px;
      font-size:12px;
      color:var(--mut);
      line-height:1.7;
    }

    .right-stack{
      display:grid;
      gap:14px;
    }

    .rule-list{
      display:grid;
      gap:10px;
    }
    .rule{
      border:1px solid rgba(255,255,255,.06);
      border-radius:14px;
      background:rgba(0,0,0,.22);
      padding:12px;
    }
    .rule .k{
      font-size:11px;
      color:#efd9a0;
      margin-bottom:6px;
      letter-spacing:.08em;
      text-transform:uppercase;
    }
    .rule .v{
      font-size:13px;
      color:#f3ebff;
      line-height:1.65;
    }

    .status-pills{
      display:flex;
      gap:8px;
      flex-wrap:wrap;
      margin-top:12px;
    }
    .pill{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      padding:7px 10px;
      border-radius:999px;
      border:1px solid rgba(255,255,255,.10);
      background:rgba(0,0,0,.22);
      font-size:11px;
      color:#efe6ff;
    }
    .pill.ok{
      border-color:rgba(52,211,153,.26);
      color:#cbffe7;
    }
    .pill.gold{
      border-color:rgba(247,223,149,.26);
      color:#fff0c2;
    }

    .history-table{
      width:100%;
      border-collapse:collapse;
      font-size:12px;
    }
    .history-table th,
    .history-table td{
      padding:10px 12px;
      text-align:left;
      border-bottom:1px solid rgba(255,255,255,.06);
      vertical-align:top;
    }
    .history-table th{
      color:#e4d2ff;
      background:rgba(255,255,255,.03);
      font-weight:1000;
    }
    .empty{
      padding:18px;
      color:var(--mut);
      font-size:12px;
    }

    .navSpace{height:90px}
  </style>
</head>
<body>

<div class="page">
  <div class="hero">
    <div class="hero-top">
      <div>
        <div class="hero-kicker" data-i18n="hero_kicker">Premium Mining Suite</div>
        <h1 data-i18n="hero_title">Extra Boost Premium Lounge</h1>
        <div class="hero-sub" data-i18n="hero_sub">
          Configure your EMA$ extra boost in a premium lounge flow. Drag your preferred boost ratio, review projected multiplier and daily cap, then continue to payment and auto verification.
        </div>
      </div>
      <div class="wallet-chip">Wallet: <?=h($wallet)?></div>
    </div>

    <div class="stats">
      <div class="stat">
        <div class="k" data-i18n="stat_available">Available On-Chain EMA$</div>
        <div class="v" id="boostAvailable">0</div>
        <div class="s" data-i18n="stat_available_sub">Storage-synced source</div>
      </div>
      <div class="stat">
        <div class="k" data-i18n="stat_selected">Selected Boost EMA$</div>
        <div class="v" id="boostSelected">0</div>
        <div class="s" data-i18n="stat_selected_sub">Live drag selection</div>
      </div>
      <div class="stat">
        <div class="k" data-i18n="stat_multiplier">Projected Multiplier</div>
        <div class="v" id="boostMultiplier">x1</div>
        <div class="s" data-i18n="stat_multiplier_sub">Preview only before payment</div>
      </div>
      <div class="stat">
        <div class="k" data-i18n="stat_cap">Projected Daily Cap</div>
        <div class="v" id="boostCap">100</div>
        <div class="s" data-i18n="stat_cap_sub">Stable live preview</div>
      </div>
    </div>
  </div>

  <div class="lang-switch">
    <button type="button" id="lang-en" class="active">EN</button>
    <span class="sep">|</span>
    <button type="button" id="lang-zh">中</button>
  </div>

  <div class="grid">
    <div class="card">
      <div class="card-hd">
        <div class="t" data-i18n="panel_title">Boost Configuration Deck</div>
        <div class="r" id="boostStatus">IDLE</div>
      </div>
      <div class="card-bd">
        <div class="boost-main">
          <div class="premium-amount">
            <div class="headline" data-i18n="lounge_title">Extra Boost with EMA$</div>
            <div class="big-ema" id="boostHeadlineAmount">0 EMA$</div>
            <div class="subline" data-i18n="lounge_sub">
              Premium lounge mode allows flexible boost selection from 0% to 100% of your boostable on-chain EMA$. Minimum effective boost step is guided by backend rules.
            </div>
          </div>

          <div class="slider-card">
            <div class="slider-top">
              <div data-i18n="slider_label">Drag boost from 0% to 100%</div>
              <div class="slider-value" id="boostPct">0%</div>
            </div>

            <input id="boostSlider" class="slider" type="range" min="0" max="100" step="1" value="0"/>

            <div class="slider-meta">
              <span>0%</span>
              <span id="boostStepHint">0 steps</span>
              <span>100%</span>
            </div>

            <div class="calc-grid">
              <div class="mini">
                <div class="k" data-i18n="calc_tier_min">Tier Min / Max Boostable</div>
                <div class="v"><span id="tierMinEma">0</span> / <span id="boostMax">0</span></div>
              </div>
              <div class="mini">
                <div class="k" data-i18n="calc_extra_add">Extra Multiplier / Cap Add</div>
                <div class="v">+<span id="boostMulAdd">0</span>x / +<span id="boostCapAdd">0</span></div>
              </div>
              <div class="mini">
                <div class="k" data-i18n="calc_effective_rate">Effective Rate</div>
                <div class="v"><span id="boostRate">0.33</span> wEMS / 10s</div>
              </div>
              <div class="mini">
                <div class="k" data-i18n="calc_payment">Expected Payment</div>
                <div class="v" id="boostPayment">0 EMA$</div>
              </div>
            </div>

            <div class="actions">
              <button class="btn btn-gold" id="boostNowBtn" type="button" data-i18n="btn_prepare">PREPARE PREMIUM BOOST</button>
              <a class="btn btn-ghost" href="/rwa/mining/" data-i18n="btn_back">BACK TO MINING</a>
            </div>

            <div class="lounge-note" data-i18n="lounge_note">
              Premium lounge flow: select amount → prepare request → show QR / deeplink → pay EMA$ on-chain → verify via backend → activate boost immediately after confirmation.
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="right-stack">
      <div class="card">
        <div class="card-hd">
          <div class="t" data-i18n="rules_title">Premium Lounge Rules</div>
          <div class="r" data-i18n="rules_live">Live</div>
        </div>
        <div class="card-bd">
          <div class="rule-list">
            <div class="rule">
              <div class="k" data-i18n="rule_source_k">Source</div>
              <div class="v" data-i18n="rule_source_v">Only on-chain EMA$ is valid. Storage-synced EMA$ display is used as the canonical source preview.</div>
            </div>
            <div class="rule">
              <div class="k" data-i18n="rule_drag_k">Flexible Drag</div>
              <div class="v" data-i18n="rule_drag_v">User can drag from 0% to 100% of boostable EMA$. Minimum effective boost logic is still controlled by backend prepare/verify rules.</div>
            </div>
            <div class="rule">
              <div class="k" data-i18n="rule_activation_k">Activation</div>
              <div class="v" data-i18n="rule_activation_v">Boost must not activate before successful payment verification. After verified payment, multiplier and cap should update immediately.</div>
            </div>
            <div class="rule">
              <div class="k" data-i18n="rule_payment_k">Payment Mode</div>
              <div class="v" data-i18n="rule_payment_v">Premium flow should use QR + deeplink + auto verification. The lounge is a preparation and monitoring deck, not direct balance deduction.</div>
            </div>
          </div>

          <div class="status-pills">
            <span class="pill gold" data-i18n="pill_premium">PREMIUM LOUNGE</span>
            <span class="pill ok" data-i18n="pill_auto_verify">AUTO VERIFY</span>
            <span class="pill" data-i18n="pill_onchain">ON-CHAIN EMA$ ONLY</span>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-hd">
          <div class="t" data-i18n="history_title">Recent Boost Requests</div>
          <div class="r" data-i18n="history_r">Latest</div>
        </div>
        <div class="card-bd">
          <table class="history-table">
            <thead>
              <tr>
                <th data-i18n="col_ref">Ref</th>
                <th data-i18n="col_selected">Selected EMA$</th>
                <th data-i18n="col_status">Status</th>
                <th data-i18n="col_time">Time</th>
              </tr>
            </thead>
            <tbody id="boostHistoryBody">
              <tr>
                <td colspan="4" class="empty" data-i18n="history_empty">No premium boost requests yet.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="navSpace"></div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php'; ?>
<script src="/dashboard/inc/poado-i18n.js?v=1"></script>
<script>
(() => {
  const dict = {
    en: {
      hero_kicker: 'Premium Mining Suite',
      hero_title: 'Extra Boost Premium Lounge',
      hero_sub: 'Configure your EMA$ extra boost in a premium lounge flow. Drag your preferred boost ratio, review projected multiplier and daily cap, then continue to payment and auto verification.',
      stat_available: 'Available On-Chain EMA$',
      stat_available_sub: 'Storage-synced source',
      stat_selected: 'Selected Boost EMA$',
      stat_selected_sub: 'Live drag selection',
      stat_multiplier: 'Projected Multiplier',
      stat_multiplier_sub: 'Preview only before payment',
      stat_cap: 'Projected Daily Cap',
      stat_cap_sub: 'Stable live preview',
      panel_title: 'Boost Configuration Deck',
      lounge_title: 'Extra Boost with EMA$',
      lounge_sub: 'Premium lounge mode allows flexible boost selection from 0% to 100% of your boostable on-chain EMA$. Minimum effective boost step is guided by backend rules.',
      slider_label: 'Drag boost from 0% to 100%',
      calc_tier_min: 'Tier Min / Max Boostable',
      calc_extra_add: 'Extra Multiplier / Cap Add',
      calc_effective_rate: 'Effective Rate',
      calc_payment: 'Expected Payment',
      btn_prepare: 'PREPARE PREMIUM BOOST',
      btn_back: 'BACK TO MINING',
      lounge_note: 'Premium lounge flow: select amount → prepare request → show QR / deeplink → pay EMA$ on-chain → verify via backend → activate boost immediately after confirmation.',
      rules_title: 'Premium Lounge Rules',
      rules_live: 'Live',
      rule_source_k: 'Source',
      rule_source_v: 'Only on-chain EMA$ is valid. Storage-synced EMA$ display is used as the canonical source preview.',
      rule_drag_k: 'Flexible Drag',
      rule_drag_v: 'User can drag from 0% to 100% of boostable EMA$. Minimum effective boost logic is still controlled by backend prepare/verify rules.',
      rule_activation_k: 'Activation',
      rule_activation_v: 'Boost must not activate before successful payment verification. After verified payment, multiplier and cap should update immediately.',
      rule_payment_k: 'Payment Mode',
      rule_payment_v: 'Premium flow should use QR + deeplink + auto verification. The lounge is a preparation and monitoring deck, not direct balance deduction.',
      pill_premium: 'PREMIUM LOUNGE',
      pill_auto_verify: 'AUTO VERIFY',
      pill_onchain: 'ON-CHAIN EMA$ ONLY',
      history_title: 'Recent Boost Requests',
      history_r: 'Latest',
      col_ref: 'Ref',
      col_selected: 'Selected EMA$',
      col_status: 'Status',
      col_time: 'Time',
      history_empty: 'No premium boost requests yet.',
    },
    zh: {
      hero_kicker: '高级挖矿套件',
      hero_title: '额外加速贵宾厅',
      hero_sub: '在贵宾厅模式中配置你的 EMA$ 额外加速。拖动你希望的加速比例，预览倍数与每日上限，然后进入支付与自动验证流程。',
      stat_available: '可用链上 EMA$',
      stat_available_sub: 'Storage 同步来源',
      stat_selected: '已选加速 EMA$',
      stat_selected_sub: '实时拖动选择',
      stat_multiplier: '预估倍数',
      stat_multiplier_sub: '支付前预览',
      stat_cap: '预估每日上限',
      stat_cap_sub: '稳定实时预览',
      panel_title: '加速配置面板',
      lounge_title: 'EMA$ 额外加速',
      lounge_sub: '贵宾厅模式允许用户从 0% 到 100% 灵活拖动可加速的链上 EMA$。实际最小有效加速规则仍由后端控制。',
      slider_label: '从 0% 拖动到 100%',
      calc_tier_min: '等级门槛 / 最大可加速',
      calc_extra_add: '额外倍数 / 上限增加',
      calc_effective_rate: '有效速率',
      calc_payment: '预计支付',
      btn_prepare: '准备高级加速',
      btn_back: '返回挖矿',
      lounge_note: '贵宾厅流程：选择金额 → 准备请求 → 显示二维码 / Deeplink → 链上支付 EMA$ → 后端验证 → 验证后立即激活加速。',
      rules_title: '贵宾厅规则',
      rules_live: '实时',
      rule_source_k: '来源',
      rule_source_v: '只有链上 EMA$ 有效。Storage 同步的 EMA$ 显示作为规范预览来源。',
      rule_drag_k: '灵活拖动',
      rule_drag_v: '用户可从 0% 拖动到 100% 的可加速 EMA$。最小有效加速逻辑仍由后端 prepare/verify 控制。',
      rule_activation_k: '激活',
      rule_activation_v: '在支付验证成功之前，不得激活加速。验证成功后，倍数与每日上限应立即更新。',
      rule_payment_k: '支付模式',
      rule_payment_v: '高级流程应使用二维码 + Deeplink + 自动验证。贵宾厅是准备与监控面板，不是直接扣减余额。',
      pill_premium: '高级贵宾厅',
      pill_auto_verify: '自动验证',
      pill_onchain: '仅限链上 EMA$',
      history_title: '最近加速请求',
      history_r: '最新',
      col_ref: '编号',
      col_selected: '已选 EMA$',
      col_status: '状态',
      col_time: '时间',
      history_empty: '暂时没有高级加速请求。',
    }
  };

  const $ = (id) => document.getElementById(id);
  const langEn = $('lang-en');
  const langZh = $('lang-zh');

  function applyLang(lang){
    document.querySelectorAll('[data-i18n]').forEach((el) => {
      const key = el.getAttribute('data-i18n');
      if (dict[lang] && dict[lang][key]) el.textContent = dict[lang][key];
    });
    langEn?.classList.toggle('active', lang === 'en');
    langZh?.classList.toggle('active', lang === 'zh');
    try { localStorage.setItem('rwa_extra_boost_lang', lang); } catch {}
  }

  langEn?.addEventListener('click', () => applyLang('en'));
  langZh?.addEventListener('click', () => applyLang('zh'));
  try {
    const saved = localStorage.getItem('rwa_extra_boost_lang');
    applyLang(saved === 'zh' ? 'zh' : 'en');
  } catch {
    applyLang('en');
  }

  // demo premium lounge preview values
  const available = 1000;
  const tierMin = 0;
  const boostable = 1000;
  const baseMul = 1;
  const baseCap = 100;

  const slider = $('boostSlider');
  const boostPct = $('boostPct');
  const boostAvailable = $('boostAvailable');
  const boostSelected = $('boostSelected');
  const boostMultiplier = $('boostMultiplier');
  const boostCap = $('boostCap');
  const boostHeadlineAmount = $('boostHeadlineAmount');
  const boostStepHint = $('boostStepHint');
  const tierMinEma = $('tierMinEma');
  const boostMax = $('boostMax');
  const boostMulAdd = $('boostMulAdd');
  const boostCapAdd = $('boostCapAdd');
  const boostRate = $('boostRate');
  const boostPayment = $('boostPayment');
  const boostNowBtn = $('boostNowBtn');

  function fmt(n, dp = 8){
    return Number(n || 0).toFixed(dp).replace(/0+$/,'').replace(/\.$/,'');
  }

  function recalc(){
    const pct = Number(slider?.value || 0);
    const selected = boostable * (pct / 100);
    const steps = Math.floor(selected / 100);
    const mulAdd = steps * 0.1;
    const capAdd = steps * 10;
    const finalMul = baseMul + mulAdd;
    const finalCap = baseCap + capAdd;
    const rate = 0.33 * finalMul;

    boostPct.textContent = pct + '%';
    boostAvailable.textContent = fmt(available, 8);
    boostSelected.textContent = fmt(selected, 8);
    boostHeadlineAmount.textContent = fmt(selected, 8) + ' EMA$';
    boostMultiplier.textContent = 'x' + fmt(finalMul, 2);
    boostCap.textContent = fmt(finalCap, 8);
    boostStepHint.textContent = steps + ' steps';
    tierMinEma.textContent = fmt(tierMin, 8);
    boostMax.textContent = fmt(boostable, 8);
    boostMulAdd.textContent = fmt(mulAdd, 2);
    boostCapAdd.textContent = fmt(capAdd, 8);
    boostRate.textContent = fmt(rate, 8);
    boostPayment.textContent = fmt(selected, 8) + ' EMA$';

    if (boostNowBtn) boostNowBtn.disabled = selected <= 0;
  }

  slider?.addEventListener('input', recalc);
  recalc();
})();
</script>
</body>
</html>
