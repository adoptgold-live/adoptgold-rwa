<?php
declare(strict_types=1);

require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

$hasMiningConfig = is_file($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/mining-config.php');
$hasMiningLib    = is_file($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/mining-lib.php');
$hasMiningGuards = is_file($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/mining-guards.php');

if ($hasMiningConfig) require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/mining-config.php';
if ($hasMiningLib) require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/mining-lib.php';
if ($hasMiningGuards) require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/mining-guards.php';

db_connect();
$pdo = $GLOBALS['pdo'] ?? null;

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$user = function_exists('session_user') ? (session_user() ?: []) : [];
$userId = (int)($user['id'] ?? 0);
$wallet = trim((string)($user['wallet_address'] ?? $user['wallet'] ?? ''));

$gate = [
    'user_id' => $userId,
    'wallet' => $wallet,
    'wallet_short' => $wallet !== '' ? substr($wallet, 0, 6) . '...' . substr($wallet, -4) : 'SESSION: NONE',
    'is_profile_complete' => false,
    'is_ton_bound' => $wallet !== '',
    'is_mining_eligible' => false,
];

try {
    if ($hasMiningGuards && function_exists('poado_require_mining_page_access')) {
        $ctx = poado_require_mining_page_access();
        $pdo    = $ctx['pdo'] ?? $pdo;
        $user   = $ctx['user'] ?? $user;
        $userId = (int)($ctx['user_id'] ?? $userId);
        $wallet = (string)($ctx['wallet'] ?? $wallet);
        $gate   = is_array($ctx['gate'] ?? null) ? $ctx['gate'] : $gate;
    } elseif ($pdo instanceof PDO && $userId > 0) {
        $st = $pdo->prepare("
            SELECT id, nickname, email, country_name, country, wallet_address
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        $nickname = trim((string)($row['nickname'] ?? ''));
        $email    = trim((string)($row['email'] ?? ''));
        $country  = trim((string)($row['country_name'] ?? $row['country'] ?? ''));
        $bound    = trim((string)($row['wallet_address'] ?? '')) !== '';

        $gate['is_profile_complete'] = ($nickname !== '' && $email !== '' && $country !== '');
        $gate['is_ton_bound'] = $bound;
        $gate['is_mining_eligible'] = $gate['is_profile_complete'] && $gate['is_ton_bound'];
    }
} catch (Throwable $e) {}

try {
    if ($pdo instanceof PDO && $userId > 0 && $hasMiningLib && function_exists('poado_ensure_miner_profile')) {
        poado_ensure_miner_profile($pdo, $user);
    }
} catch (Throwable $e) {}

try {
    if ($pdo instanceof PDO && $userId > 0 && $wallet !== '' && $hasMiningLib && function_exists('poado_resolve_tier')) {
        poado_resolve_tier($pdo, $userId, $wallet);
    }
} catch (Throwable $e) {}

$totalMined = 0.0;
$totalUnclaimedWebGold = 0.0;

try {
    if ($pdo instanceof PDO && $userId > 0 && $wallet !== '') {
        $st = $pdo->prepare("
            SELECT
                COALESCE(total_mined_wems, 0) AS total_mined_wems,
                COALESCE(total_binding_wems, 0) AS total_binding_wems,
                COALESCE(total_node_bonus_wems, 0) AS total_node_bonus_wems,
                COALESCE(total_claimed_wems, 0) AS total_claimed_wems
            FROM poado_miner_profiles
            WHERE user_id = ? AND wallet = ?
            LIMIT 1
        ");
        $st->execute([$userId, $wallet]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $gross = (float)$row['total_mined_wems'] + (float)$row['total_binding_wems'] + (float)$row['total_node_bonus_wems'];
            $claimed = (float)$row['total_claimed_wems'];
            $totalMined = $gross;
            $totalUnclaimedWebGold = max(0, $gross - $claimed);
        }
    }
} catch (Throwable $e) {}

require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
  <title>RWA Mining · POAdo</title>
  <style>
    :root{
      --bg:#05050a; --fg:#efe7ff; --mut:#bda9df; --p1:#7c3aed; --p2:#a855f7; --ok:#34d399; --err:#fb7185;
      --br:rgba(168,85,247,.25); --shadow:0 18px 40px rgba(0,0,0,.55); --solid:#120b1a; --gold:#f5d67b;
      --nonce:#86efac; --nonce2:#c084fc; --nonceBg:rgba(6,10,18,.68);
      --nonceBar1:#06b6d4; --nonceBar2:#3b82f6; --nonceBar3:#8b5cf6;
    }
    html,body{margin:0;background:radial-gradient(900px 520px at 18% 0%, rgba(168,85,247,.18), transparent 60%), var(--bg);color:var(--fg);font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
    .page{max-width:1180px;margin:0 auto;padding:14px 12px 110px}
    .title{display:flex;align-items:flex-end;justify-content:space-between;gap:10px;margin:8px 2px 12px}
    .title h1{margin:0;font-size:18px;letter-spacing:.06em}
    .title .sub{color:var(--mut);font-size:12px}

    .lang-switch{display:flex;justify-content:flex-end;align-items:center;gap:8px;margin:0 2px 12px 2px;font-size:12px;color:var(--mut)}
    .lang-switch button{appearance:none;border:none;background:transparent;color:var(--mut);font:inherit;font-weight:900;cursor:pointer;padding:0}
    .lang-switch button.active{color:#fff}
    .lang-switch .sep{opacity:.6}

    .grid{display:grid;grid-template-columns:1.2fr 1fr;gap:12px}
    @media (max-width:980px){ .grid{grid-template-columns:1fr} }

    .card{border:1px solid var(--br);border-radius:16px;background:linear-gradient(180deg, rgba(124,58,237,.12), rgba(0,0,0,.35));box-shadow:var(--shadow);overflow:hidden}
    .card .hd{padding:12px 14px;border-bottom:1px solid rgba(168,85,247,.18);display:flex;align-items:center;justify-content:space-between;gap:10px}
    .hd .k{font-weight:900;letter-spacing:.08em;font-size:12px;color:#eaddff}
    .hd .r{font-size:12px;color:var(--mut)}
    .bd{padding:14px}

    .engineRow{display:grid;grid-template-columns:220px 1fr;gap:12px;align-items:stretch}
    @media(max-width:620px){ .engineRow{grid-template-columns:1fr} }

    .leftRail{display:flex;flex-direction:column;align-items:stretch;justify-content:flex-start;gap:12px}
    .dial{border:1px solid rgba(168,85,247,.18);border-radius:18px;background:rgba(0,0,0,.35);display:flex;align-items:flex-start;justify-content:center;min-height:220px;position:relative;padding-top:18px}
    .ring{width:190px;height:190px;border-radius:50%;background:conic-gradient(#22c55e, #60a5fa, #a855f7, #fb7185, #22c55e);filter:drop-shadow(0 0 18px rgba(168,85,247,.18));display:flex;align-items:center;justify-content:center}
    .ringInner{width:150px;height:150px;border-radius:50%;background:radial-gradient(100px 100px at 30% 20%, rgba(168,85,247,.22), rgba(0,0,0,.78));border:1px solid rgba(255,255,255,.06);display:flex;flex-direction:column;align-items:center;justify-content:center}
    .rpmVal{font-size:34px;font-weight:1000;line-height:1}
    .rpmLab{font-size:12px;color:var(--mut);letter-spacing:.12em;margin-top:4px}

    .extraBoostBtn{
      appearance:none;position:relative;overflow:hidden;
      border:1px solid rgba(245,214,123,.45);
      background:
        radial-gradient(120px 50px at 18% 18%, rgba(255,255,255,.22), transparent 60%),
        linear-gradient(180deg, rgba(255,224,130,.20), rgba(184,134,11,.22) 42%, rgba(60,38,6,.88));
      color:#fff3cf;padding:14px 16px;border-radius:16px;font-weight:1000;letter-spacing:.06em;cursor:pointer;text-align:center;
      box-shadow:
        inset 0 0 0 1px rgba(255,239,184,.12),
        0 0 18px rgba(245,214,123,.18),
        0 0 34px rgba(184,134,11,.12);
      text-shadow:0 1px 0 rgba(0,0,0,.45), 0 0 10px rgba(255,221,128,.18);
      min-height:76px;font-size:15px;line-height:1.22;
    }
    .extraBoostBtn::before{
      content:"";
      position:absolute;top:0;left:-34%;width:34%;height:100%;
      background:linear-gradient(90deg, rgba(255,255,255,0), rgba(255,247,210,.38), rgba(255,255,255,0));
      transform:skewX(-20deg);
      animation:goldShine 3.1s linear infinite;
    }
    .extraBoostBtn:hover{
      border-color:rgba(255,224,130,.7);
      box-shadow:
        inset 0 0 0 1px rgba(255,239,184,.16),
        0 0 24px rgba(245,214,123,.26),
        0 0 44px rgba(184,134,11,.18);
    }
    @keyframes goldShine{
      0%{left:-38%}
      100%{left:132%}
    }

    .miniGrid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    .mini{border:1px solid rgba(168,85,247,.18);border-radius:14px;background:rgba(0,0,0,.35);padding:12px}
    .mini .t{font-size:12px;color:var(--mut);margin-bottom:6px}
    .mini .v{font-size:16px;font-weight:900}
    .mini .v small{font-size:12px;color:var(--mut);font-weight:700}

    .batteryBox{margin-top:10px;border:1px solid rgba(168,85,247,.18);border-radius:14px;background:rgba(0,0,0,.35);padding:12px}
    .batteryTop{display:flex;align-items:center;justify-content:space-between;color:var(--mut);font-size:12px;margin-bottom:8px}
    .bar{height:14px;border-radius:999px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.06);overflow:hidden}
    .bar > div{height:100%;width:0%;border-radius:999px;background:linear-gradient(90deg, rgba(34,197,94,.95), rgba(168,85,247,.95));transition:width .18s linear}

    .btnRow{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}
    .btn{appearance:none;border:1px solid var(--br);background:rgba(124,58,237,.22);color:var(--fg);padding:12px 14px;border-radius:14px;font-weight:1000;letter-spacing:.06em;cursor:pointer}
    .btn:disabled{opacity:.55;cursor:not-allowed}
    .btn.green{border-color:rgba(52,211,153,.35);background:rgba(52,211,153,.12)}
    .btn.red{border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.12)}
    .btn.ghost{background:rgba(0,0,0,.25)}

    .upgradeBtn{
      flex:1 1 260px;min-height:52px;font-size:14px;
      border-color:rgba(34,197,94,.55)!important;
      background:linear-gradient(90deg, rgba(34,197,94,.28), rgba(16,185,129,.18), rgba(250,204,21,.18))!important;
      box-shadow:0 0 0 1px rgba(34,197,94,.15) inset,0 0 18px rgba(34,197,94,.22),0 0 34px rgba(250,204,21,.12);
      position:relative;overflow:hidden;
    }
    .upgradeBtn::before{
      content:"";position:absolute;top:0;left:-35%;width:35%;height:100%;
      background:linear-gradient(90deg, rgba(255,255,255,0), rgba(255,255,255,.26), rgba(255,255,255,0));
      transform:skewX(-18deg);animation:upgradeShine 2.8s linear infinite;
    }
    @keyframes upgradeShine{0%{left:-40%}100%{left:130%}}

    .bindingCard{margin-top:12px;border:1px solid rgba(96,165,250,.22);border-radius:14px;background:rgba(0,0,0,.28);padding:12px}
    .bindingCard .sectionTitle{margin:0 0 10px 0;font-size:12px;font-weight:900;letter-spacing:.08em;color:#dcecff}
    .bindingQr{min-height:120px;border:1px solid rgba(255,255,255,.07);border-radius:12px;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.25);margin-bottom:10px}
    .bindingQr img,.bindingQr svg{max-width:100%;height:auto}
    .bindingLinkLabel{font-size:11px;color:var(--mut);margin-bottom:6px}
    .bindingLink{border:1px solid rgba(255,255,255,.07);border-radius:12px;background:rgba(0,0,0,.25);padding:10px;font-size:12px;line-height:1.5;word-break:break-all;color:#eef6ff}

    .errBox{display:none;margin-top:10px;border:1px solid rgba(251,113,133,.35);background:rgba(251,113,133,.10);border-radius:14px;padding:10px 12px;color:#ffd0d9;font-size:12px}

    .bigTotal{border:1px solid rgba(168,85,247,.18);border-radius:16px;background:rgba(0,0,0,.40);padding:16px;margin-bottom:12px}
    .bigTotal .label{color:var(--mut);font-size:12px;letter-spacing:.10em}
    .bigTotal .value{margin-top:8px;font-size:36px;font-weight:1100}
    .bigTotal .unit{font-size:14px;color:var(--mut);font-weight:800;margin-left:8px}

    .nonceBox{border:1px solid rgba(168,85,247,.20);border-radius:16px;background:linear-gradient(180deg, rgba(124,58,237,.12), rgba(6,10,18,.75)),radial-gradient(600px 120px at 20% 0%, rgba(52,211,153,.08), transparent 60%);padding:12px;margin-bottom:12px;box-shadow:inset 0 0 0 1px rgba(255,255,255,.02)}
    .nonceTop{display:flex;align-items:center;justify-content:space-between;gap:10px;color:var(--mut);font-size:12px;margin-bottom:8px}
    .nonceStage{font-weight:900;color:#d9ccff;letter-spacing:.08em}
    .nonceStage.is-found{color:#b7ffcf;text-shadow:0 0 12px rgba(52,211,153,.35)}
    .nonceHashWrap{border:1px solid rgba(134,239,172,.14);border-radius:12px;background:var(--nonceBg);padding:10px 12px;overflow:hidden}
    .nonceHashLabel{color:#8fb8a8;font-size:11px;letter-spacing:.10em;margin-bottom:6px}
    .nonceHash{font-size:14px;font-weight:900;color:var(--nonce);word-break:break-all;line-height:1.5;min-height:42px;text-shadow:0 0 8px rgba(134,239,172,.16)}
    .nonceMeta{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px}
    .nonceMini{border:1px solid rgba(168,85,247,.16);border-radius:12px;background:rgba(0,0,0,.28);padding:10px}
    .nonceMini .k{font-size:11px;color:var(--mut);margin-bottom:6px;letter-spacing:.06em}
    .nonceMini .v{font-size:15px;font-weight:900;color:#efe7ff}
    .nonceMini .v.green{color:#b7ffcf}
    .nonceProgress{margin-top:10px;height:8px;border-radius:999px;overflow:hidden;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.05)}
    .nonceProgress > div{width:0%;height:100%;border-radius:999px;background:linear-gradient(90deg, var(--nonceBar1), var(--nonceBar2), var(--nonceBar3));box-shadow:0 0 16px rgba(59,130,246,.22);transition:width .18s linear}
    .nonceFlash{animation:nonceFlashPulse .48s ease}
    @keyframes nonceFlashPulse{0%{box-shadow:0 0 0 rgba(52,211,153,0)}30%{box-shadow:0 0 22px rgba(59,130,246,.35)}100%{box-shadow:0 0 0 rgba(52,211,153,0)}}

    .ledgerLinkBox{border:1px solid rgba(245,214,123,.20);border-radius:16px;background:rgba(245,214,123,.06);padding:12px;margin-bottom:12px}
    .ledgerLinkBox .k{font-size:12px;color:#f5e5ab;letter-spacing:.08em}
    .ledgerLinkBox .v{font-size:22px;font-weight:1000;margin-top:8px;color:#fff1c1}
    .ledgerLinkBox .s{font-size:12px;color:#dccd96;margin-top:8px;line-height:1.5}
    .ledgerLinkBox .a{display:inline-flex;margin-top:10px;border:1px solid rgba(245,214,123,.28);color:#fff1c1;text-decoration:none;padding:10px 12px;border-radius:12px;background:rgba(0,0,0,.28);font-weight:900}

    .rightNote{border:1px solid rgba(168,85,247,.18);border-radius:16px;background:rgba(0,0,0,.35);padding:12px;color:var(--mut);font-size:12px;line-height:1.5}

    .modal{position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.72);z-index:200;padding:16px}
    .modal.show{display:flex}
    .modalCard{width:min(920px, 100%);border-radius:18px;border:1px solid rgba(168,85,247,.35);background:var(--solid);box-shadow:0 30px 80px rgba(0,0,0,.70);overflow:hidden}
    .modalHd{padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:10px;border-bottom:1px solid rgba(168,85,247,.22);background:#0f0816}
    .modalHd .t{font-weight:1100;letter-spacing:.10em}
    .modalHd .x{cursor:pointer;border:1px solid rgba(255,255,255,.10);background:rgba(0,0,0,.45);color:var(--fg);border-radius:12px;padding:8px 10px;font-weight:1000}
    .modalBd{padding:14px 16px}
    .tierGrid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media(max-width:720px){ .tierGrid{grid-template-columns:1fr} .nonceMeta{grid-template-columns:1fr} }
    .tier{border:1px solid rgba(168,85,247,.20);border-radius:16px;background:#140b1e;padding:14px}
    .tier .name{font-size:16px;font-weight:1100}
    .tier .meta{color:var(--mut);font-size:12px;margin-top:6px;line-height:1.5}
    .tier .tags{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
    .pill{border:1px solid rgba(168,85,247,.25);background:#0f0816;border-radius:999px;padding:6px 10px;font-size:12px;color:#eaddff}
    .tier .act{margin-top:12px}
    .tier .act button{width:100%}

    .gateWrap{max-width:920px;margin:16px auto 0}
    .gateCard{border:1px solid rgba(251,113,133,.28);border-radius:18px;background:linear-gradient(180deg, rgba(251,113,133,.10), rgba(0,0,0,.35));box-shadow:var(--shadow);overflow:hidden}
    .gateHd{padding:14px 16px;border-bottom:1px solid rgba(251,113,133,.18);display:flex;align-items:center;justify-content:space-between;gap:10px}
    .gateHd .t{font-weight:1000;letter-spacing:.08em;color:#ffd6de}
    .gateBd{padding:16px}
    .statusGrid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin:12px 0 14px}
    .statusMini{border:1px solid rgba(255,255,255,.08);border-radius:14px;background:rgba(0,0,0,.28);padding:12px}
    .statusMini .k{font-size:12px;color:var(--mut);margin-bottom:6px}
    .statusMini .v{font-size:16px;font-weight:1000}
    .okText{color:var(--ok)}
    .badText{color:#ffb4c2}
    @media (max-width:760px){ .statusGrid{grid-template-columns:1fr} }

    .navSpace{height:90px}
  </style>
</head>
<body>

<div class="page">
  <div class="title">
    <div>
      <h1 data-i18n="title">RWA Mining Engine</h1>
      <div class="sub" data-i18n="subtitle">wEMS tick mining · 10s loop · off-chain ledger · storage-linked unclaimed Web Gold</div>
    </div>
    <div class="sub">Wallet: <?=h($wallet ?: 'SESSION:NONE')?></div>
  </div>

  <div class="lang-switch">
    <button type="button" id="lang-en" class="active">EN</button>
    <span class="sep">|</span>
    <button type="button" id="lang-zh">中</button>
  </div>

  <?php if (!$gate['is_mining_eligible']): ?>
    <div class="gateWrap">
      <div class="gateCard">
        <div class="gateHd">
          <div class="t" data-i18n="locked_title">MINING ACCESS LOCKED</div>
          <div class="sub" data-i18n="locked_sub">Profile gate enforced</div>
        </div>
        <div class="gateBd">
          <div class="statusGrid">
            <div class="statusMini">
              <div class="k" data-i18n="profile_status">PROFILE STATUS</div>
              <div class="v <?= $gate['is_profile_complete'] ? 'okText' : 'badText' ?>">
                <?= $gate['is_profile_complete'] ? 'COMPLETE' : 'INCOMPLETE' ?>
              </div>
            </div>
            <div class="statusMini">
              <div class="k" data-i18n="wallet_status">TON WALLET STATUS</div>
              <div class="v <?= $gate['is_ton_bound'] ? 'okText' : 'badText' ?>">
                <?= $gate['is_ton_bound'] ? 'BOUND' : 'NOT BOUND' ?>
              </div>
            </div>
            <div class="statusMini">
              <div class="k" data-i18n="eligibility">MINING ELIGIBILITY</div>
              <div class="v <?= $gate['is_mining_eligible'] ? 'okText' : 'badText' ?>">
                <?= $gate['is_mining_eligible'] ? 'READY' : 'LOCKED' ?>
              </div>
            </div>
          </div>
          <div class="btnRow" style="margin-top:14px;">
            <button class="btn" type="button" onclick="location.href='/rwa/profile/'" data-i18n="go_profile">GO TO PROFILE</button>
          </div>
        </div>
      </div>
    </div>
  <?php else: ?>

  <div class="grid">
    <div class="card">
      <div class="hd">
        <div class="k" data-i18n="engine_title">ROUND RPM MINING ENGINE</div>
        <div class="r">TICK: 10s · Token: wEMS</div>
      </div>
      <div class="bd">
        <div class="engineRow">
          <div class="leftRail">
            <div class="dial">
              <div class="ring">
                <div class="ringInner">
                  <div class="rpmVal" id="rpmText">120</div>
                  <div class="rpmLab">RPM</div>
                </div>
              </div>
            </div>

            <button class="extraBoostBtn" id="extraBoostBtn" data-i18n="extra_boost">EXTRA BOOST WITH EMA$</button>
          </div>

          <div>
            <div class="miniGrid">
              <div class="mini">
                <div class="t" data-i18n="miner_tier">Miner Tier</div>
                <div class="v" id="tierLabel">—</div>
              </div>
              <div class="mini">
                <div class="t" data-i18n="multiplier">Multiplier</div>
                <div class="v" id="multiplierText">x1</div>
              </div>
              <div class="mini">
                <div class="t" data-i18n="boosted_rate">Boosted Rate</div>
                <div class="v"><span id="ratePerTick">0.33</span> <small>wEMS / 10s</small></div>
              </div>
              <div class="mini">
                <div class="t" data-i18n="daily_cap">Daily Cap</div>
                <div class="v"><span id="dailyCap">0</span> <small>wEMS</small></div>
              </div>
            </div>

            <div class="batteryBox">
              <div class="batteryTop">
                <div data-i18n="battery_label">Battery (fills every 10s tick)</div>
                <div id="batteryPct">0%</div>
              </div>
              <div class="bar"><div id="batteryFill"></div></div>

              <div class="miniGrid" style="margin-top:10px">
                <div class="mini">
                  <div class="t" data-i18n="mined_today">Mined Today</div>
                  <div class="v"><span id="minedToday">0</span> <small>wEMS</small></div>
                </div>
                <div class="mini">
                  <div class="t" data-i18n="remaining">Remaining</div>
                  <div class="v"><span id="remainingToday">0</span> <small>wEMS</small></div>
                </div>
              </div>

              <div class="btnRow">
                <button class="btn green" id="startMiningBtn" data-i18n="start_mining">START MINING</button>
                <button class="btn red" id="stopMiningBtn" disabled data-i18n="stop">STOP</button>
                <button class="btn green upgradeBtn" id="upgradeMinerBtn" data-i18n="upgrade_miner">UPGRADE MINER</button>
                <button class="btn ghost" id="refreshBtn" data-i18n="refresh">REFRESH</button>
              </div>

              <div class="bindingCard">
                <div class="sectionTitle" data-i18n="binding_title">BINDING YOUR MINER</div>
                <div class="bindingQr" id="bindingQr">QR</div>
                <div class="bindingLinkLabel" data-i18n="binding_link">Binding Link</div>
                <div class="bindingLink" id="bindingLink">Loading…</div>
                <div class="btnRow" style="margin-top:10px">
                  <button class="btn green" id="bindingListingBtn" data-i18n="view_binding">VIEW BINDING LISTING</button>
                </div>
              </div>

              <div class="errBox" id="miningError"></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="hd">
        <div class="k" data-i18n="economics">ECONOMICS</div>
        <div class="r" id="statusText">Status: OFFLINE</div>
      </div>
      <div class="bd">
        <div class="bigTotal">
          <div class="label" data-i18n="total_gold">MY TOTAL GOLD MINED</div>
          <div class="value"><span id="totalMined"><?=h(number_format($totalMined, 8, '.', ''))?></span><span class="unit">wEMS</span></div>
        </div>

        <div class="nonceBox" id="nonceBox">
          <div class="nonceTop">
            <div data-i18n="nonce_title">Mining Nonce Search</div>
            <div class="nonceStage" id="nonceStage">STANDBY</div>
          </div>

          <div class="nonceHashWrap">
            <div class="nonceHashLabel" data-i18n="nonce_hash">LIVE NONCE HASH</div>
            <div class="nonceHash" id="nonceHash">0x00000000000000000000000000000000</div>
          </div>

          <div class="nonceMeta">
            <div class="nonceMini">
              <div class="k" data-i18n="search_progress">SEARCH PROGRESS</div>
              <div class="v" id="nonceProgressText">0%</div>
            </div>
            <div class="nonceMini">
              <div class="k" data-i18n="nonce_state">NONCE STATE</div>
              <div class="v green" id="nonceResult">WAITING</div>
            </div>
          </div>

          <div class="nonceProgress"><div id="nonceProgressFill"></div></div>
        </div>

        <div class="ledgerLinkBox">
          <div class="k" data-i18n="unclaimed_title">MY UNCLAIMED WEB GOLD WEMS</div>
          <div class="v" id="storageUnclaimedWems"><?=h(number_format($totalUnclaimedWebGold, 8, '.', ''))?> wEMS</div>
          <div class="s" data-i18n="unclaimed_desc">Mined wEMS is linked to the Storage module ledger as My Unclaimed Web Gold wEMS. Claiming or settlement will reduce this unclaimed amount.</div>
          <a class="a" href="/rwa/storage/" data-i18n="open_storage">OPEN STORAGE LEDGER</a>
        </div>

        <div class="mini" style="margin-bottom:12px">
          <div class="t" data-i18n="node_reward">Node Reward Info</div>
          <div class="v" style="font-size:14px" id="nodeRewardInfo">Nodes Miner: 0.5% Global Node Reward · Super Node: 3%</div>
        </div>

        <div class="mini">
          <div class="t" data-i18n="ledger_title">Rewards Ledger (Last 50)</div>
          <div class="v" style="font-size:12px;color:var(--mut)" id="ledgerBox">Ledger unavailable: waiting…</div>
        </div>

        <div class="rightNote" data-i18n="right_note" style="margin-top:12px">
          Binding Commission: 1% of bound adoptee mining rewards. Extra reward, not counted toward own daily mining cap.
          Claim Rule: Mining is off-chain. On-chain is claim only and KYC is required for withdrawal.
        </div>
      </div>
    </div>
  </div>

  <?php endif; ?>

  <div class="navSpace"></div>
</div>

<div class="modal" id="boosterModal" aria-hidden="true">
  <div class="modalCard">
    <div class="modalHd">
      <div class="t" data-i18n="booster_modal">UPGRADE MINER</div>
      <button class="x" id="boosterClose">CLOSE</button>
    </div>
    <div class="modalBd">
      <div style="color:var(--mut);font-size:12px;margin-bottom:10px" data-i18n="booster_desc">
        Miner upgrade tier is determined by on-chain EMA$ only. Off-chain EMA balances are not valid for miner upgrade.
      </div>

      <div class="tierGrid">
        <div class="tier">
          <div class="name">Sub Miner</div>
          <div class="meta">Required: 100 EMA$ on-chain<br>Multiplier: x3 · Daily Cap: 500 · BC: 100</div>
          <div class="tags"><span class="pill">On-Chain EMA$</span><span class="pill">x3</span><span class="pill">500 cap</span></div>
          <div class="act"><button class="btn" disabled>SELECT (backend verify)</button></div>
        </div>

        <div class="tier">
          <div class="name">Core Miner</div>
          <div class="meta">Required: 1000 EMA$ on-chain<br>Multiplier: x5 · Daily Cap: 1000 · BC: 300</div>
          <div class="tags"><span class="pill">On-Chain EMA$</span><span class="pill">x5</span><span class="pill">1000 cap</span></div>
          <div class="act"><button class="btn" disabled>SELECT (backend verify)</button></div>
        </div>

        <div class="tier">
          <div class="name">Nodes Miner</div>
          <div class="meta">Required: 5000 EMA$ on-chain<br>Multiplier: x10 · Daily Cap: 3000 · BC: 1000<br>Node Reward: 0.5%</div>
          <div class="tags"><span class="pill">On-Chain EMA$</span><span class="pill">x10</span><span class="pill">0.5% pool</span></div>
          <div class="act"><button class="btn" disabled>SELECT (backend verify)</button></div>
        </div>

        <div class="tier">
          <div class="name">Super Node Miner</div>
          <div class="meta">Required: 100000 EMA$ on-chain<br>Multiplier: x30 · Daily Cap: 10000 · BC: 3000<br>Node Reward: 3%</div>
          <div class="tags"><span class="pill">On-Chain EMA$</span><span class="pill">x30</span><span class="pill">3% pool</span></div>
          <div class="act"><button class="btn" disabled>SELECT (backend verify)</button></div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php'; ?>

<script>
window.RWA_MINING_BOOT = {
  eligible: <?= $gate['is_mining_eligible'] ? 'true' : 'false' ?>,
  totalMined: <?= json_encode((float)$totalMined) ?>,
  totalUnclaimedWebGold: <?= json_encode((float)$totalUnclaimedWebGold) ?>,
  userId: <?= json_encode($userId) ?>
};
</script>
<script src="/rwa/mining/mining.js?v=20260327b"></script>

</body>
</html>
