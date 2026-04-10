<?php
// /rwa/mining/index.php
// v1.0.20260306-rwa-mining-index-final-sfx
//
// LOCK NOTES (per your global rules):
// - RWA standalone page (no dashboard partials)
// - DB: wems_db only (no new tables)
// - Must include: /rwa/inc/rwa-topbar-nav.php and /rwa/inc/rwa-bottom-nav.php
// - Translator: handled by bottom nav (do NOT add floating translator here)

declare(strict_types=1);

// LOCKED RWA include order (latest)
require __DIR__ . '/../inc/rwa-session.php';
require __DIR__ . '/../../dashboard/inc/session-user.php';
require __DIR__ . '/../../dashboard/inc/bootstrap.php';

// Mandatory infra helpers (locked)
require __DIR__ . '/../../dashboard/inc/validators.php';
require __DIR__ . '/../../dashboard/inc/guards.php';
require __DIR__ . '/../../dashboard/inc/json.php';
require __DIR__ . '/../../dashboard/inc/error.php';

// LOCK: topbar + bottom nav on every RWA page
require __DIR__ . '/../inc/rwa-topbar-nav.php';

db_connect();
$pdo = $GLOBALS['pdo'] ?? null;

$userId = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
$wallet = trim((string)($_SESSION['wallet'] ?? $_SESSION['user_wallet'] ?? $_SESSION['poado_wallet'] ?? ''));

// Total mined (lifetime) from wems_mining_log (amount BIGINT scaled 1e8)
$totalMined = 0.0;
try {
  if ($pdo && $userId > 0) {
    $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) AS s FROM wems_mining_log WHERE user_id=? AND reason='mining'");
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $sumInt = (int)($row['s'] ?? 0);
    $totalMined = $sumInt / 100000000; // 1e8
  }
} catch (Throwable $e) {
  $totalMined = 0.0;
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
  <title>RWA Mining · POAdo</title>

  <style>
    :root{
      --bg:#05050a;
      --fg:#efe7ff;
      --mut:#bda9df;
      --p1:#7c3aed;
      --p2:#a855f7;
      --ok:#34d399;
      --err:#fb7185;
      --br:rgba(168,85,247,.25);
      --shadow:0 18px 40px rgba(0,0,0,.55);
      --solid:#120b1a;
    }
    html,body{
      margin:0;
      background:radial-gradient(900px 520px at 18% 0%, rgba(168,85,247,.18), transparent 60%), var(--bg);
      color:var(--fg);
      font-family: ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    }
    .page{max-width:1180px;margin:0 auto;padding:14px 12px 110px;}
    .title{
      display:flex;align-items:flex-end;justify-content:space-between;gap:10px;
      margin:8px 2px 12px;
    }
    .title h1{margin:0;font-size:18px;letter-spacing:.06em}
    .title .sub{color:var(--mut);font-size:12px}

    .grid{
      display:grid;
      grid-template-columns: 1.2fr 1fr;
      gap:12px;
    }
    @media (max-width: 980px){ .grid{grid-template-columns:1fr} }

    .card{
      border:1px solid var(--br);
      border-radius:16px;
      background:linear-gradient(180deg, rgba(124,58,237,.12), rgba(0,0,0,.35));
      box-shadow: var(--shadow);
      overflow:hidden;
    }
    .card .hd{
      padding:12px 14px;
      border-bottom:1px solid rgba(168,85,247,.18);
      display:flex;align-items:center;justify-content:space-between;gap:10px;
    }
    .hd .k{font-weight:900;letter-spacing:.08em;font-size:12px;color:#eaddff}
    .hd .r{font-size:12px;color:var(--mut)}
    .bd{padding:14px;}

    /* Left engine */
    .engineRow{display:grid;grid-template-columns: 220px 1fr;gap:12px;align-items:stretch;}
    @media(max-width: 520px){ .engineRow{grid-template-columns:1fr;} }

    .dial{
      border:1px solid rgba(168,85,247,.18);
      border-radius:18px;
      background:rgba(0,0,0,.35);
      display:flex;align-items:center;justify-content:center;
      min-height:220px;
      position:relative;
    }
    .ring{
      width:190px;height:190px;border-radius:50%;
      background: conic-gradient(#22c55e, #60a5fa, #a855f7, #fb7185, #22c55e);
      filter: drop-shadow(0 0 18px rgba(168,85,247,.18));
      display:flex;align-items:center;justify-content:center;
    }
    .ringInner{
      width:150px;height:150px;border-radius:50%;
      background: radial-gradient(100px 100px at 30% 20%, rgba(168,85,247,.22), rgba(0,0,0,.78));
      border:1px solid rgba(255,255,255,.06);
      display:flex;flex-direction:column;align-items:center;justify-content:center;
    }
    .rpmVal{font-size:34px;font-weight:1000;line-height:1}
    .rpmLab{font-size:12px;color:var(--mut);letter-spacing:.12em;margin-top:4px}

    .miniGrid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
    .mini{
      border:1px solid rgba(168,85,247,.18);
      border-radius:14px;
      background:rgba(0,0,0,.35);
      padding:12px;
    }
    .mini .t{font-size:12px;color:var(--mut);margin-bottom:6px}
    .mini .v{font-size:16px;font-weight:900}
    .mini .v small{font-size:12px;color:var(--mut);font-weight:700}

    .batteryBox{
      margin-top:10px;
      border:1px solid rgba(168,85,247,.18);
      border-radius:14px;
      background:rgba(0,0,0,.35);
      padding:12px;
    }
    .batteryTop{display:flex;align-items:center;justify-content:space-between;color:var(--mut);font-size:12px;margin-bottom:8px}
    .bar{
      height:14px;border-radius:999px;background:rgba(255,255,255,.06);
      border:1px solid rgba(255,255,255,.06);overflow:hidden
    }
    .bar > div{
      height:100%;width:0%;border-radius:999px;
      background:linear-gradient(90deg, rgba(34,197,94,.95), rgba(168,85,247,.95));
      transition:width .2s linear
    }

    .btnRow{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}
    .btn{
      appearance:none;border:1px solid var(--br);background:rgba(124,58,237,.22);
      color:var(--fg);padding:12px 14px;border-radius:14px;font-weight:1000;letter-spacing:.06em;
      cursor:pointer;
    }
    .btn:disabled{opacity:.55;cursor:not-allowed}
    .btn.green{border-color:rgba(52,211,153,.35);background:rgba(52,211,153,.12)}
    .btn.red{border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.12)}
    .btn.ghost{background:rgba(0,0,0,.25)}

    .errBox{
      display:none;margin-top:10px;
      border:1px solid rgba(251,113,133,.35);
      background:rgba(251,113,133,.10);
      border-radius:14px;padding:10px 12px;color:#ffd0d9;font-size:12px;
    }

    /* Right side: total */
    .bigTotal{
      border:1px solid rgba(168,85,247,.18);
      border-radius:16px;
      background:rgba(0,0,0,.40);
      padding:16px;
      margin-bottom:12px;
    }
    .bigTotal .label{color:var(--mut);font-size:12px;letter-spacing:.10em}
    .bigTotal .value{margin-top:8px;font-size:36px;font-weight:1100}
    .bigTotal .unit{font-size:14px;color:var(--mut);font-weight:800;margin-left:8px}

    .rightNote{
      border:1px solid rgba(168,85,247,.18);
      border-radius:16px;
      background:rgba(0,0,0,.35);
      padding:12px;
      color:var(--mut);
      font-size:12px;
      line-height:1.5;
    }

    /* Booster popup: SOLID */
    .modal{
      position:fixed;inset:0;display:none;align-items:center;justify-content:center;
      background:rgba(0,0,0,.72);z-index:200;padding:16px;
    }
    .modal.show{display:flex;}
    .modalCard{
      width:min(920px, 100%);
      border-radius:18px;
      border:1px solid rgba(168,85,247,.35);
      background: var(--solid);
      box-shadow: 0 30px 80px rgba(0,0,0,.70);
      overflow:hidden;
    }
    .modalHd{
      padding:14px 16px;
      display:flex;align-items:center;justify-content:space-between;gap:10px;
      border-bottom:1px solid rgba(168,85,247,.22);
      background:#0f0816;
    }
    .modalHd .t{font-weight:1100;letter-spacing:.10em}
    .modalHd .x{cursor:pointer;border:1px solid rgba(255,255,255,.10);background:rgba(0,0,0,.45);color:var(--fg);border-radius:12px;padding:8px 10px;font-weight:1000}
    .modalBd{padding:14px 16px;}
    .tierGrid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
    @media(max-width:720px){ .tierGrid{grid-template-columns:1fr} }
    .tier{
      border:1px solid rgba(168,85,247,.20);
      border-radius:16px;
      background:#140b1e;
      padding:14px;
    }
    .tier .name{font-size:16px;font-weight:1100}
    .tier .meta{color:var(--mut);font-size:12px;margin-top:6px;line-height:1.5}
    .tier .tags{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
    .pill{border:1px solid rgba(168,85,247,.25);background:#0f0816;border-radius:999px;padding:6px 10px;font-size:12px;color:#eaddff}
    .tier .act{margin-top:12px}
    .tier .act button{width:100%}

    .navSpace{height:90px}
    code{color:#eaddff}
  </style>
</head>
<body>

<div class="page">
  <div class="title">
    <div>
      <h1>RWA Mining Engine</h1>
      <div class="sub">POAdo · wEMS tick mining · 10s loop · standalone /rwa</div>
    </div>
    <div class="sub">Wallet: <?=h($wallet ?: 'SESSION:NONE')?></div>
  </div>

  <div class="grid">
    <!-- LEFT: ENGINE -->
    <div class="card">
      <div class="hd">
        <div class="k">ROUND RPM MINING ENGINE</div>
        <div class="r">TICK: 10s · Token: wEMS</div>
      </div>
      <div class="bd">
        <div class="engineRow">
          <div class="dial">
            <div class="ring">
              <div class="ringInner">
                <div class="rpmVal" id="rpmText">120</div>
                <div class="rpmLab">RPM</div>
              </div>
            </div>
          </div>

          <div>
            <div class="miniGrid">
              <div class="mini">
                <div class="t">Miner Tier</div>
                <div class="v" id="tierLabel">—</div>
              </div>
              <div class="mini">
                <div class="t">Multiplier</div>
                <div class="v" id="multiplierText">x1</div>
              </div>
              <div class="mini">
                <div class="t">Boosted Rate</div>
                <div class="v"><span id="ratePerTick">0.33</span> <small>wEMS / 10s</small></div>
              </div>
              <div class="mini">
                <div class="t">Daily Cap</div>
                <div class="v"><span id="dailyCap">0</span> <small>wEMS</small></div>
              </div>
            </div>

            <div class="batteryBox">
              <div class="batteryTop">
                <div>Battery (fills every 10s tick)</div>
                <div id="batteryPct">0%</div>
              </div>
              <div class="bar"><div id="batteryFill"></div></div>

              <div class="miniGrid" style="margin-top:10px">
                <div class="mini">
                  <div class="t">Mined Today</div>
                  <div class="v"><span id="minedToday">0</span> <small>wEMS</small></div>
                </div>
                <div class="mini">
                  <div class="t">Remaining</div>
                  <div class="v"><span id="remainingToday">0</span> <small>wEMS</small></div>
                </div>
              </div>

              <div class="btnRow">
                <button class="btn green" id="startMiningBtn">START MINING</button>
                <button class="btn red" id="stopMiningBtn" disabled>STOP</button>
                <button class="btn ghost" id="boosterBtn">EMA BOOSTER</button>
                <button class="btn ghost" id="refreshBtn">REFRESH</button>
              </div>

              <div class="errBox" id="miningError"></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- RIGHT: TOTAL + INFO -->
    <div class="card">
      <div class="hd">
        <div class="k">EMA BOOSTER & NODE REWARDS</div>
        <div class="r" id="statusText">Status: OFFLINE</div>
      </div>
      <div class="bd">
        <div class="bigTotal">
          <div class="label">MY TOTAL GOLD MINED</div>
          <div class="value"><span id="totalMined"><?=h(number_format($totalMined, 8, '.', ''))?></span><span class="unit">wEMS</span></div>
        </div>

        <div class="mini" style="margin-bottom:12px">
          <div class="t">Node Reward Info</div>
          <div class="v" style="font-size:14px">
            <span id="nodeRewardInfo">Nodes Miner: 0.5% Global Node Reward · Super Node: 3%</span>
          </div>
          <div class="t" style="margin-top:8px">
            Pool distribution is computed by server (phase-2). UI displays eligibility based on tier.
          </div>
        </div>

        <div class="mini">
          <div class="t">Rewards Ledger (Last 50)</div>
          <div class="v" style="font-size:12px;color:var(--mut)" id="ledgerBox">Ledger unavailable: waiting…</div>
        </div>

        <!-- Binding Commission moved RIGHT -->
        <div class="rightNote" style="margin-top:12px">
          <b>Binding Commission:</b> 1% of bound adoptee mining rewards (NOT counted toward daily cap).<br/>
          Session: mining actions require an active session.
        </div>

        <div class="rightNote" style="margin-top:10px">
          <b>SFX:</b> Start/Stop/Tick sounds are enabled. If your topbar SFX manager exists, this page will use it.
        </div>
      </div>
    </div>
  </div>

  <div class="navSpace"></div>
</div>

<!-- Booster popup (SOLID) -->
<div class="modal" id="boosterModal" aria-hidden="true">
  <div class="modalCard">
    <div class="modalHd">
      <div class="t">EMA BOOSTER ACTIVATION</div>
      <button class="x" id="boosterClose">CLOSE</button>
    </div>
    <div class="modalBd">
      <div style="color:var(--mut);font-size:12px;margin-bottom:10px">
        Select a tier and activate booster. (UI-ready) Server-side on-chain verification can be wired next.
      </div>

      <div class="tierGrid">
        <div class="tier">
          <div class="name">Sub Miner</div>
          <div class="meta">Cost: 100 EMA<br>Multiplier: x3 · Cap: 500 · BC: 10</div>
          <div class="tags">
            <span class="pill">Boost</span><span class="pill">x3</span><span class="pill">500 cap</span>
          </div>
          <div class="act"><button class="btn" disabled>SELECT (phase-2)</button></div>
        </div>

        <div class="tier">
          <div class="name">Core Miner</div>
          <div class="meta">Cost: 1000 EMA<br>Multiplier: x5 · Cap: 1000 · BC: 100</div>
          <div class="tags">
            <span class="pill">Boost</span><span class="pill">x5</span><span class="pill">1000 cap</span>
          </div>
          <div class="act"><button class="btn" disabled>SELECT (phase-2)</button></div>
        </div>

        <div class="tier">
          <div class="name">Nodes Miner</div>
          <div class="meta">Cost: 5000 EMA<br>Multiplier: x10 · Cap: 3000 · BC: 300<br>Node Reward: 0.5%</div>
          <div class="tags">
            <span class="pill">Boost</span><span class="pill">x10</span><span class="pill">0.5% pool</span>
          </div>
          <div class="act"><button class="btn" disabled>SELECT (phase-2)</button></div>
        </div>

        <div class="tier">
          <div class="name">Super Node Miner</div>
          <div class="meta">Cost: 100000 EMA<br>Multiplier: x30 · Cap: 10000 · BC: 1000<br>Node Reward: 3%</div>
          <div class="tags">
            <span class="pill">Boost</span><span class="pill">x30</span><span class="pill">3% pool</span>
          </div>
          <div class="act"><button class="btn" disabled>SELECT (phase-2)</button></div>
        </div>
      </div>

      <div style="margin-top:12px;color:var(--mut);font-size:12px;border-top:1px solid rgba(168,85,247,.16);padding-top:10px">
        Note: This popup is UI-only. Activation requires server verification to update <code>user_miner_tier_wallet</code>.
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../inc/rwa-bottom-nav.php'; ?>

<script src="/dashboard/inc/poado-i18n.js?v=1"></script>

<script>
// v1.0.20260306 mining loop + SFX (page-scoped, uses topbar SFX manager if present)
(() => {
  const API = {
    status: '/rwa/api/mining/status.php',
    start:  '/rwa/api/mining/start.php',
    stop:   '/rwa/api/mining/stop.php',
    tick:   '/rwa/api/mining/tick.php',
    ledger: '/rwa/api/mining/ledger.php',
  };

  const TICK_MS = 10000;

  const $ = (id) => document.getElementById(id);

  const UI = {
    rpmText: $('rpmText'),
    batteryFill: $('batteryFill'),
    batteryPct: $('batteryPct'),
    startBtn: $('startMiningBtn'),
    stopBtn: $('stopMiningBtn'),
    boosterBtn: $('boosterBtn'),
    refreshBtn: $('refreshBtn'),
    tierLabel: $('tierLabel'),
    multiplierText: $('multiplierText'),
    ratePerTick: $('ratePerTick'),
    dailyCap: $('dailyCap'),
    minedToday: $('minedToday'),
    remainingToday: $('remainingToday'),
    statusText: $('statusText'),
    err: $('miningError'),
    ledgerBox: $('ledgerBox'),
    nodeRewardInfo: $('nodeRewardInfo'),
    modal: $('boosterModal'),
    modalClose: $('boosterClose'),
  };

  // --- SFX (uses global manager if present; fallback to local audio files)
  const SFX = (() => {
    const mgr = window.POADO_SFX || window.poadoSfx || window.POADO_SFX_MANAGER || null;

    const aStart = new Audio('/rwa/assets/sfx/engine-start.mp3');
    const aLoop  = new Audio('/rwa/assets/sfx/engine-loop.mp3');
    const aStop  = new Audio('/rwa/assets/sfx/engine-stop.mp3');
    const aTick  = new Audio('/rwa/assets/sfx/tick.mp3');

    aLoop.loop = true;
    aLoop.volume = 0.25;
    aStart.volume = 0.6;
    aStop.volume = 0.6;
    aTick.volume = 0.35;

    function safePlay(a){
      try { a.currentTime = 0; const p = a.play(); if (p && p.catch) p.catch(()=>{}); } catch(e){}
    }
    function safeStop(a){
      try { a.pause(); a.currentTime = 0; } catch(e){}
    }

    return {
      start(){
        if (mgr && typeof mgr.play === 'function') {
          try {
            mgr.play('engine_start');
            if (typeof mgr.loop === 'function') mgr.loop('engine_loop');
          } catch(e){}
          return;
        }
        safePlay(aStart);
        setTimeout(() => safePlay(aLoop), 250);
      },
      stop(){
        if (mgr && typeof mgr.stop === 'function') {
          try {
            mgr.stop('engine_loop');
            if (typeof mgr.play === 'function') mgr.play('engine_stop');
          } catch(e){}
          return;
        }
        safeStop(aLoop);
        safePlay(aStop);
      },
      tick(){
        if (mgr && typeof mgr.play === 'function') {
          try { mgr.play('tick'); } catch(e){}
          return;
        }
        safePlay(aTick);
      },
      hardStopAll(){
        safeStop(aLoop);
      }
    };
  })();

  let st = {
    isMining: false,
    multiplier: 1,
    cap: 0,
    minedToday: 0,
    ratePerTick: 0.33,
    batteryPct: 0,
    nodeRewardPct: 0,
  };

  let raf = null;
  let last = performance.now();
  let tickGuard = false;

  function showErr(msg){
    if (!UI.err) return;
    UI.err.style.display = msg ? 'block' : 'none';
    UI.err.textContent = msg || '';
  }

  async function fetchJSON(url, opts={}){
    const res = await fetch(url, { credentials:'include', cache:'no-store', ...opts });
    const txt = await res.text();
    if (txt.trim().startsWith('<')) {
      throw new Error('SERVER_RETURNED_HTML');
    }
    let j;
    try { j = JSON.parse(txt); } catch { throw new Error('INVALID_JSON'); }
    return j;
  }

  function fmt(n, dp=8){
    n = Number(n || 0);
    return n.toFixed(dp).replace(/0+$/,'').replace(/\.$/,'');
  }

  function setBattery(p){
    p = Math.max(0, Math.min(100, p));
    st.batteryPct = p;
    if (UI.batteryFill) UI.batteryFill.style.width = p + '%';
    if (UI.batteryPct) UI.batteryPct.textContent = Math.round(p) + '%';
  }

  function setRPM(){
    const base = 120;
    const mul = Math.min(30, Math.max(1, Number(st.multiplier || 1)));
    const ramp = (st.batteryPct/100) * (220 + mul*60);
    const rpm = Math.round(base + mul*110 + ramp*10);
    if (UI.rpmText) UI.rpmText.textContent = String(rpm);
  }

  function renderStatus(s){
    st.isMining = !!Number(s.is_mining || 0);
    st.multiplier = Number(s.multiplier || 1);
    st.cap = Number(s.daily_cap_wems || 0);
    st.minedToday = Number(s.daily_mined_wems || 0);
    st.ratePerTick = Number(s.rate_wems_per_tick || 0.33);
    st.nodeRewardPct = Number(s.node_reward_pct || 0);

    setBattery(Number(s.battery_pct || 0));

    if (UI.statusText) UI.statusText.textContent = 'Status: ' + (st.isMining ? 'RUNNING' : 'STOPPED');
    if (UI.tierLabel) UI.tierLabel.textContent = String((s.tier || '—')).toUpperCase();
    if (UI.multiplierText) UI.multiplierText.textContent = 'x' + fmt(st.multiplier, 2);
    if (UI.ratePerTick) UI.ratePerTick.textContent = fmt(st.ratePerTick, 8);
    if (UI.dailyCap) UI.dailyCap.textContent = String(st.cap);

    if (UI.minedToday) UI.minedToday.textContent = fmt(st.minedToday, 8);
    const rem = (st.cap > 0) ? Math.max(0, st.cap - st.minedToday) : 0;
    if (UI.remainingToday) UI.remainingToday.textContent = fmt(rem, 8);

    if (UI.startBtn) UI.startBtn.disabled = st.isMining || (st.minedToday >= st.cap && st.cap > 0);
    if (UI.stopBtn) UI.stopBtn.disabled = !st.isMining;

    if (UI.nodeRewardInfo){
      if (st.nodeRewardPct > 0) UI.nodeRewardInfo.textContent = `Node Reward Pool: ${fmt(st.nodeRewardPct, 1)}%`;
      else UI.nodeRewardInfo.textContent = `Nodes Miner: 0.5% Global Node Reward · Super Node: 3%`;
    }

    setRPM();
  }

  async function refreshStatus(){
    try{
      const s = await fetchJSON(API.status);
      if (!s.ok) throw new Error(s.message || s.error || 'STATUS_FAIL');
      showErr('');
      renderStatus(s);
    }catch(e){
      if (UI.statusText) UI.statusText.textContent = 'Status: OFFLINE';
      showErr(`STATUS: ${String(e.message || e)} (open ${API.status} in browser)`);
    }
  }

  async function refreshLedger(){
    if (!UI.ledgerBox) return;
    try{
      const r = await fetchJSON(API.ledger);
      if (!r.ok) throw new Error(r.message || r.error || 'LEDGER_FAIL');
      UI.ledgerBox.textContent = (r.rows && r.rows.length) ? `OK · ${r.rows.length} rows` : 'No mining records yet.';
    }catch(e){
      UI.ledgerBox.textContent = 'Ledger unavailable: ' + String(e.message || e);
    }
  }

  async function doStart(){
    showErr('');
    try{
      const r = await fetchJSON(API.start, { method:'POST' });
      if (!r.ok) throw new Error(r.message || r.error || 'START_FAIL');
      renderStatus(r);
      setBattery(0);
      SFX.start(); // SOUND ON START
    }catch(e){
      showErr(`START: ${String(e.message || e)} (open ${API.start} in browser)`);
      await refreshStatus();
      SFX.hardStopAll();
    }
  }

  async function doStop(){
    showErr('');
    try{
      const r = await fetchJSON(API.stop, { method:'POST' });
      if (!r.ok) throw new Error(r.message || r.error || 'STOP_FAIL');
      renderStatus(r);
      setBattery(0);
      SFX.stop(); // SOUND ON STOP
    }catch(e){
      showErr(`STOP: ${String(e.message || e)} (open ${API.stop} in browser)`);
      await refreshStatus();
      SFX.hardStopAll();
    }
  }

  async function doTickIfReady(){
    if (!st.isMining) return;
    if (tickGuard) return;
    if (st.batteryPct < 100) return;

    tickGuard = true;
    try{
      const r = await fetchJSON(API.tick, { method:'POST' });
      if (!r.ok) throw new Error(r.message || r.error || 'TICK_FAIL');
      SFX.tick(); // SOUND ON TICK
      setBattery(0);
      await refreshStatus();
      await refreshLedger();
    }catch(e){
      showErr(`TICK: ${String(e.message || e)} (open ${API.tick} in browser)`);
      st.isMining = false;
      if (UI.startBtn) UI.startBtn.disabled = false;
      if (UI.stopBtn) UI.stopBtn.disabled = true;
      SFX.stop();
    } finally {
      tickGuard = false;
    }
  }

  function anim(now){
    const dt = now - last;
    last = now;

    if (st.isMining){
      const pctPerMs = 100 / TICK_MS;
      const next = st.batteryPct + dt * pctPerMs;
      setBattery(Math.min(100, next));
    } else {
      if (st.batteryPct !== 0) setBattery(0);
    }

    setRPM();
    doTickIfReady();
    raf = requestAnimationFrame(anim);
  }

  function openModal(){ if (UI.modal) UI.modal.classList.add('show'); }
  function closeModal(){ if (UI.modal) UI.modal.classList.remove('show'); }

  document.addEventListener('DOMContentLoaded', async () => {
    if (UI.startBtn) UI.startBtn.addEventListener('click', (e)=>{ e.preventDefault(); doStart(); });
    if (UI.stopBtn) UI.stopBtn.addEventListener('click', (e)=>{ e.preventDefault(); doStop(); });
    if (UI.refreshBtn) UI.refreshBtn.addEventListener('click', (e)=>{ e.preventDefault(); refreshStatus(); refreshLedger(); });
    if (UI.boosterBtn) UI.boosterBtn.addEventListener('click', (e)=>{ e.preventDefault(); openModal(); });
    if (UI.modalClose) UI.modalClose.addEventListener('click', (e)=>{ e.preventDefault(); closeModal(); });
    if (UI.modal) UI.modal.addEventListener('click', (e)=>{ if (e.target === UI.modal) closeModal(); });

    await refreshStatus();
    await refreshLedger();

    raf = requestAnimationFrame((t)=>{ last=t; anim(t); });
  });

  // Safety: stop loop sound on navigation
  window.addEventListener('beforeunload', () => {
    try { SFX.hardStopAll(); } catch(e){}
  });
})();
</script>

</body>
</html>