<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/swap/stv/index.php
 * STV Workspace
 * Version: v2.0.0-step-guide-rwae-progress
 *
 * Rule:
 * - STV accepts ONLY Minted RWA Cert NFTs
 * - standalone /rwa/* workspace
 * - conditional Personal / Business STV form
 * - uploads go to Google Drive STV folder through stv-upload.php
 * - apply precheck goes to stv-apply.php
 * - final submit goes to stv-submit.php
 * - all STV amounts displayed in RWA€
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function stv_page_pdo(): ?PDO
{
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }

    if (function_exists('db_connect')) {
        try {
            db_connect();
            if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
                return $GLOBALS['pdo'];
            }
        } catch (Throwable $e) {
        }
    }

    if (function_exists('db')) {
        try {
            $pdo = db();
            if ($pdo instanceof PDO) {
                $GLOBALS['pdo'] = $pdo;
                return $pdo;
            }
        } catch (Throwable $e) {
        }
    }

    if (function_exists('rwa_db')) {
        try {
            $pdo = rwa_db();
            if ($pdo instanceof PDO) {
                $GLOBALS['pdo'] = $pdo;
                return $pdo;
            }
        } catch (Throwable $e) {
        }
    }

    return null;
}

function stv_page_seed_user(): ?array
{
    if (function_exists('rwa_current_user')) {
        try {
            $u = rwa_current_user();
            if (is_array($u) && !empty($u)) {
                return $u;
            }
        } catch (Throwable $e) {
        }
    }

    if (function_exists('rwa_session_user')) {
        try {
            $u = rwa_session_user();
            if (is_array($u) && !empty($u)) {
                return $u;
            }
        } catch (Throwable $e) {
        }
    }

    if (function_exists('get_wallet_session')) {
        try {
            $u = get_wallet_session();
            if (is_array($u) && !empty($u)) {
                return $u;
            }
            if (is_string($u) && trim($u) !== '') {
                return ['wallet' => trim($u)];
            }
        } catch (Throwable $e) {
        }
    }

    return null;
}

function stv_page_user_hydrate(?array $seed): ?array
{
    if (!$seed || !is_array($seed)) {
        return null;
    }

    $pdo = stv_page_pdo();
    if (!$pdo) {
        return null;
    }

    $userId = (int)($seed['id'] ?? 0);
    $wallet = trim((string)($seed['wallet'] ?? ''));
    $walletAddress = trim((string)($seed['wallet_address'] ?? ''));

    $sql = "
        SELECT
            id,
            wallet,
            wallet_address,
            nickname,
            email,
            role,
            is_active,
            is_fully_verified
        FROM users
    ";

    try {
        if ($userId > 0) {
            $stmt = $pdo->prepare($sql . " WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && !empty($row)) {
                return $row;
            }
        }

        if ($walletAddress !== '') {
            $stmt = $pdo->prepare($sql . " WHERE wallet_address = ? LIMIT 1");
            $stmt->execute([$walletAddress]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && !empty($row)) {
                return $row;
            }
        }

        if ($wallet !== '') {
            $stmt = $pdo->prepare($sql . " WHERE wallet = ? LIMIT 1");
            $stmt->execute([$wallet]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && !empty($row)) {
                return $row;
            }
        }
    } catch (Throwable $e) {
    }

    return null;
}

$seed = stv_page_seed_user();
$user = stv_page_user_hydrate($seed);

if (!$user || (int)($user['id'] ?? 0) <= 0) {
    header('Location: /rwa/index.php');
    exit;
}

$displayName = trim((string)(
    $user['nickname']
    ?? $user['email']
    ?? $user['wallet_address']
    ?? $user['wallet']
    ?? 'STV User'
));
if ($displayName === '') {
    $displayName = 'STV User';
}

$walletAddress = trim((string)($user['wallet_address'] ?? ''));
$langDefault = 'en';
?>
<!doctype html>
<html lang="<?= h($langDefault) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#050607">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="format-detection" content="telephone=no">
  <meta name="color-scheme" content="dark">
  <title>STV · Swap To Value</title>
  <style>
    :root{
      --bg:#050607;
      --panel:#0b0d10;
      --panel-2:#111418;
      --line:rgba(255,255,255,.08);
      --text:#f3f4f6;
      --muted:#9ca3af;
      --gold:#f3d27a;
      --premium:#7c4dff;
      --premium-2:#3b82f6;
      --premium-3:#a855f7;
      --ok:#22c55e;
      --ok-2:#84cc16;
      --warn:#f59e0b;
      --danger:#ef4444;
      --radius:22px;
      --shadow:0 18px 60px rgba(0,0,0,.32);
    }

    *{box-sizing:border-box}
    html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}
    body{padding-bottom:calc(72px + env(safe-area-inset-bottom,0px) + 20px)}
    a{text-decoration:none}

    .stv-shell{
      width:min(1280px,100%);
      margin:0 auto;
      padding:14px 14px 28px;
    }

    .langbar{
      display:flex;
      justify-content:flex-end;
      margin:4px 0 12px;
    }

    .lang-inline{
      display:inline-flex;
      align-items:center;
      gap:8px;
      border:1px solid var(--line);
      border-radius:999px;
      background:rgba(255,255,255,.03);
      padding:6px 10px;
    }

    .lang-btn{
      border:0;
      background:transparent;
      color:var(--muted);
      font-weight:800;
      cursor:pointer;
      padding:0;
    }

    .lang-btn.is-active{color:var(--gold)}
    .lang-sep{color:#71717a}

    .hero{
      border:1px solid rgba(124,77,255,.26);
      border-radius:28px;
      overflow:hidden;
      background:
        radial-gradient(circle at top left, rgba(59,130,246,.22), transparent 28%),
        radial-gradient(circle at top right, rgba(168,85,247,.24), transparent 30%),
        linear-gradient(135deg, rgba(18,22,40,.98), rgba(20,11,32,.98) 52%, rgba(10,11,16,.98));
      box-shadow:
        0 18px 60px rgba(0,0,0,.34),
        inset 0 1px 0 rgba(255,255,255,.05);
      margin-bottom:16px;
      position:relative;
    }

    .hero::before{
      content:"";
      position:absolute;
      inset:0;
      background:
        linear-gradient(90deg, rgba(255,255,255,.04), transparent 22%, transparent 78%, rgba(255,255,255,.03)),
        linear-gradient(180deg, rgba(255,255,255,.03), transparent 35%);
      pointer-events:none;
    }

    .hero__inner{
      display:grid;
      grid-template-columns:1.15fr .85fr;
      gap:18px;
      padding:22px;
      position:relative;
      z-index:1;
    }

    @media (max-width:980px){
      .hero__inner{grid-template-columns:1fr}
    }

    .eyebrow{
      font-size:11px;
      letter-spacing:.18em;
      text-transform:uppercase;
      color:#c9b7ff;
      margin-bottom:8px;
    }

    .hero-title{
      margin:0;
      font-size:34px;
      line-height:1.05;
      font-weight:900;
      color:#eef2ff;
      text-shadow:0 2px 18px rgba(124,77,255,.18);
    }

    .hero-sub{
      margin:10px 0 0;
      color:#d6dbef;
      font-size:14px;
      line-height:1.6;
      max-width:760px;
    }

    .hero-total{
      margin:14px 0 8px;
      font-size:54px;
      line-height:1;
      font-weight:900;
      color:#ffffff;
      letter-spacing:.02em;
      word-break:break-word;
      text-shadow:
        0 0 18px rgba(124,77,255,.22),
        0 0 30px rgba(59,130,246,.12);
    }

    .hero-total small{
      display:block;
      font-size:14px;
      font-weight:700;
      color:#d6dbef;
      margin-bottom:8px;
      letter-spacing:.1em;
    }

    .chip-row{
      display:flex;
      flex-wrap:wrap;
      gap:10px;
      margin-top:14px;
    }

    .chip{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:36px;
      padding:0 12px;
      border-radius:999px;
      border:1px solid rgba(255,255,255,.10);
      background:rgba(255,255,255,.05);
      color:#f3f4f6;
      font-size:12px;
      font-weight:800;
      box-shadow:inset 0 1px 0 rgba(255,255,255,.04);
    }

    .hero-actions{
      display:flex;
      flex-wrap:wrap;
      gap:10px;
      margin-top:16px;
    }

    .btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:46px;
      padding:0 16px;
      border-radius:14px;
      border:1px solid rgba(124,77,255,.24);
      background:linear-gradient(135deg, rgba(124,77,255,.20), rgba(59,130,246,.18));
      color:#f5f3ff;
      font-weight:900;
      cursor:pointer;
      box-shadow:0 10px 24px rgba(59,130,246,.10);
    }

    .btn.secondary{
      background:rgba(255,255,255,.05);
      border-color:rgba(255,255,255,.10);
      color:#f3f4f6;
      box-shadow:none;
    }

    .btn[disabled]{
      opacity:.55;
      cursor:not-allowed;
    }

    .hero-side{
      display:grid;
      gap:12px;
      align-content:start;
    }

    .metric-grid{
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:12px;
    }

    @media (max-width:640px){
      .metric-grid{grid-template-columns:1fr}
    }

    .metric-card{
      border:1px solid rgba(124,77,255,.18);
      border-radius:20px;
      background:linear-gradient(180deg, rgba(255,255,255,.045), rgba(255,255,255,.025));
      padding:16px 18px;
      box-shadow:
        inset 0 1px 0 rgba(255,255,255,.05),
        0 10px 26px rgba(0,0,0,.18);
      min-height:170px;
    }

    .metric-k{
      font-size:11px;
      letter-spacing:.12em;
      text-transform:uppercase;
      color:#c9b7ff;
      margin-bottom:8px;
    }

    .metric-v{
      font-size:24px;
      font-weight:900;
      color:#fff;
      text-shadow:0 0 14px rgba(124,77,255,.16);
    }

    .metric-n{
      font-size:13px;
      line-height:1.55;
      color:#d4d4d8;
      margin-top:8px;
    }

    .progress{
      width:100%;
      height:10px;
      border-radius:999px;
      background:rgba(255,255,255,.08);
      overflow:hidden;
      margin-top:12px;
      border:1px solid rgba(255,255,255,.04);
    }

    .progress__bar{
      height:100%;
      width:0%;
      border-radius:999px;
      background:linear-gradient(90deg, var(--ok), var(--ok-2));
      transition:width .25s ease;
    }

    .progress__bar.warn{
      background:linear-gradient(90deg, #f59e0b, #fbbf24);
    }

    .progress__bar.danger{
      background:linear-gradient(90deg, #ef4444, #fb7185);
    }

    .steps{
      display:grid;
      grid-template-columns:repeat(5, minmax(0,1fr));
      gap:10px;
      margin:0 0 16px;
    }

    @media (max-width:1100px){
      .steps{grid-template-columns:repeat(2, minmax(0,1fr))}
    }

    @media (max-width:620px){
      .steps{grid-template-columns:1fr}
    }

    .step{
      border:1px solid var(--line);
      border-radius:18px;
      background:rgba(255,255,255,.03);
      padding:14px;
      min-height:110px;
    }

    .step.is-done{
      border-color:rgba(34,197,94,.30);
      background:linear-gradient(180deg, rgba(34,197,94,.10), rgba(255,255,255,.03));
    }

    .step.is-current{
      border-color:rgba(124,77,255,.34);
      background:linear-gradient(180deg, rgba(124,77,255,.12), rgba(59,130,246,.08));
      box-shadow:0 10px 24px rgba(59,130,246,.10);
    }

    .step.is-locked{
      opacity:.75;
    }

    .step.is-error{
      border-color:rgba(239,68,68,.34);
      background:linear-gradient(180deg, rgba(239,68,68,.10), rgba(255,255,255,.03));
    }

    .step-no{
      width:32px;
      height:32px;
      border-radius:999px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      font-weight:900;
      font-size:13px;
      background:rgba(255,255,255,.08);
      color:#fff;
      margin-bottom:10px;
    }

    .step-title{
      font-size:15px;
      font-weight:900;
      color:#fff;
      margin:0 0 6px;
    }

    .step-cap{
      font-size:12px;
      line-height:1.55;
      color:#d4d4d8;
      margin:0;
    }

    .dashboard{
      display:grid;
      grid-template-columns:minmax(0,1.25fr) minmax(340px,.75fr);
      gap:16px;
      align-items:start;
    }

    @media (max-width:1080px){
      .dashboard{grid-template-columns:1fr}
    }

    .rail{
      position:sticky;
      top:14px;
      display:grid;
      gap:16px;
    }

    @media (max-width:1080px){
      .rail{position:static}
    }

    .panel{
      border:1px solid var(--line);
      border-radius:var(--radius);
      background:linear-gradient(180deg, rgba(14,16,19,.98), rgba(8,10,12,.98));
      box-shadow:var(--shadow);
      overflow:hidden;
    }

    .panel__head{
      display:flex;
      align-items:flex-end;
      justify-content:space-between;
      gap:12px;
      padding:18px 18px 12px;
      border-bottom:1px solid rgba(255,255,255,.05);
    }

    .panel__title{
      margin:0;
      font-size:22px;
      font-weight:900;
      color:#fff;
    }

    .panel__sub{
      margin:6px 0 0;
      color:var(--muted);
      font-size:13px;
      line-height:1.55;
    }

    .panel__body{
      padding:16px 18px 18px;
    }

    .toolbar{
      display:flex;
      flex-wrap:wrap;
      gap:10px;
      align-items:center;
      justify-content:space-between;
      margin-bottom:14px;
    }

    .search{
      width:min(420px,100%);
      border-radius:12px;
      border:1px solid var(--line);
      background:#0f1318;
      color:#fff;
      padding:12px 14px;
      font-size:14px;
    }

    .status{
      min-height:24px;
      color:#d4d4d8;
      font-size:13px;
    }

    .card-grid{
      display:grid;
      grid-template-columns:repeat(2,minmax(0,1fr));
      gap:12px;
    }

    @media (max-width:780px){
      .card-grid{grid-template-columns:1fr}
    }

    .nft-card{
      border:1px solid var(--line);
      border-radius:18px;
      background:rgba(255,255,255,.03);
      overflow:hidden;
      display:flex;
      flex-direction:column;
      min-height:100%;
    }

    .nft-card__top{
      display:grid;
      grid-template-columns:88px 1fr;
      gap:12px;
      padding:14px;
    }

    .nft-card__thumb{
      width:88px;
      height:88px;
      border-radius:14px;
      background:#101214;
      border:1px solid rgba(255,255,255,.06);
      object-fit:cover;
    }

    .nft-card__code{
      font-size:12px;
      font-weight:900;
      letter-spacing:.08em;
      color:#f3d27a;
      text-transform:uppercase;
      margin-bottom:4px;
    }

    .nft-card__uid{
      font-size:13px;
      line-height:1.45;
      color:#fff;
      word-break:break-word;
      font-weight:800;
      margin-bottom:6px;
    }

    .nft-card__meta{
      display:grid;
      gap:4px;
    }

    .nft-card__row{
      display:flex;
      justify-content:space-between;
      gap:10px;
      font-size:12px;
      color:var(--muted);
    }

    .nft-card__row .value{
      color:#fff;
      font-weight:800;
      text-align:right;
      word-break:break-word;
    }

    .nft-card__value{
      padding:0 14px 14px;
      font-size:28px;
      line-height:1.1;
      font-weight:900;
      color:#fff0b6;
    }

    .nft-card__value small{
      display:block;
      font-size:12px;
      color:#d4d4d8;
      font-weight:700;
      margin-bottom:6px;
      letter-spacing:.08em;
    }

    .nft-card__actions{
      margin-top:auto;
      display:flex;
      flex-wrap:wrap;
      gap:8px;
      padding:0 14px 14px;
    }

    .type-grid{
      display:grid;
      grid-template-columns:repeat(2,minmax(0,1fr));
      gap:12px;
      margin-bottom:16px;
    }

    @media (max-width:760px){
      .type-grid{grid-template-columns:1fr}
    }

    .type-card{
      border:1px solid var(--line);
      border-radius:18px;
      background:rgba(255,255,255,.03);
      padding:16px;
      cursor:pointer;
      transition:transform .15s ease, border-color .15s ease, background .15s ease;
    }

    .type-card:hover{
      transform:translateY(-1px);
      border-color:rgba(124,77,255,.24);
    }

    .type-card.is-active{
      border-color:rgba(124,77,255,.34);
      background:linear-gradient(180deg, rgba(124,77,255,.12), rgba(59,130,246,.08));
      box-shadow:0 10px 24px rgba(59,130,246,.10);
    }

    .type-title{
      font-size:18px;
      font-weight:900;
      color:#fff;
      margin:0 0 6px;
    }

    .type-sub{
      color:#d4d4d8;
      font-size:13px;
      line-height:1.55;
      margin:0;
    }

    .subtype-row{
      display:flex;
      flex-wrap:wrap;
      gap:10px;
      margin:0 0 16px;
    }

    .pill{
      min-height:40px;
      padding:0 14px;
      border-radius:999px;
      border:1px solid rgba(255,255,255,.10);
      background:rgba(255,255,255,.04);
      color:#fff;
      font-weight:800;
      cursor:pointer;
    }

    .pill.is-active{
      border-color:rgba(124,77,255,.34);
      background:linear-gradient(135deg, rgba(124,77,255,.18), rgba(59,130,246,.14));
      color:#f5f3ff;
    }

    .checklist{
      display:grid;
      gap:12px;
    }

    .doc-row{
      border:1px solid var(--line);
      border-radius:18px;
      background:rgba(255,255,255,.03);
      padding:14px;
      display:grid;
      grid-template-columns:1.15fr .85fr;
      gap:14px;
    }

    @media (max-width:860px){
      .doc-row{grid-template-columns:1fr}
    }

    .doc-title{
      font-size:16px;
      font-weight:900;
      color:#fff;
      margin:0 0 6px;
    }

    .doc-note{
      color:#d4d4d8;
      font-size:13px;
      line-height:1.6;
      margin:0;
    }

    .doc-right{
      display:grid;
      gap:10px;
      align-content:start;
    }

    .file-input{
      width:100%;
      border-radius:12px;
      border:1px solid var(--line);
      background:#0f1318;
      color:#fff;
      padding:12px 14px;
      font-size:13px;
    }

    .doc-actions{
      display:flex;
      flex-wrap:wrap;
      gap:10px;
    }

    .doc-status{
      min-height:24px;
      font-size:13px;
      color:#d4d4d8;
    }

    .doc-status.ok{color:#86efac}
    .doc-status.warn{color:#fcd34d}
    .doc-status.err{color:#fda4af}

    .empty{
      border:1px dashed rgba(255,255,255,.10);
      border-radius:18px;
      padding:22px;
      color:var(--muted);
      text-align:center;
      background:rgba(255,255,255,.02);
    }

    .summary-list{
      display:grid;
      gap:10px;
    }

    .summary-row{
      display:flex;
      justify-content:space-between;
      gap:12px;
      padding:10px 0;
      border-bottom:1px dashed rgba(255,255,255,.08);
      font-size:13px;
    }

    .summary-row:last-child{border-bottom:0}
    .summary-row .left{color:var(--muted)}
    .summary-row .right{color:#fff;font-weight:900;text-align:right}

    .readiness{
      display:grid;
      gap:10px;
    }

    .ready-row{
      display:flex;
      justify-content:space-between;
      gap:12px;
      padding:12px 14px;
      border-radius:14px;
      border:1px solid var(--line);
      background:rgba(255,255,255,.03);
      font-size:13px;
    }

    .ready-row.ok{border-color:rgba(34,197,94,.26); background:rgba(34,197,94,.07)}
    .ready-row.warn{border-color:rgba(245,158,11,.26); background:rgba(245,158,11,.07)}
    .ready-row.err{border-color:rgba(239,68,68,.26); background:rgba(239,68,68,.07)}

    .badge{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:32px;
      padding:0 12px;
      border-radius:999px;
      border:1px solid rgba(255,255,255,.10);
      background:rgba(255,255,255,.05);
      color:#fff;
      font-size:12px;
      font-weight:900;
    }

    .badge.draft{border-color:rgba(255,255,255,.10)}
    .badge.submitted{border-color:rgba(59,130,246,.30); color:#dbeafe}
    .badge.approved{border-color:rgba(34,197,94,.30); color:#dcfce7}
    .foot-note{
      margin-top:14px;
      color:#9ca3af;
      font-size:12px;
      line-height:1.6;
    }

    .is-loading .hero-total,
    .is-loading .metric-v{
      opacity:.6;
    }
  </style>
</head>
<body
  data-lang="<?= h($langDefault) ?>"
  data-user-id="<?= h((string)($user['id'] ?? 0)) ?>"
  data-wallet="<?= h((string)($user['wallet'] ?? '')) ?>"
  data-wallet-address="<?= h($walletAddress) ?>"
  data-name="<?= h($displayName) ?>"
>
  <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php'; ?>

  <main class="stv-shell">

    <section class="langbar">
      <div class="lang-inline" role="group" aria-label="Language">
        <button type="button" class="lang-btn is-active" data-lang="en">EN</button>
        <span class="lang-sep">|</span>
        <button type="button" class="lang-btn" data-lang="zh">中</button>
      </div>
    </section>

    <section class="hero is-loading" id="stvHero">
      <div class="hero__inner">
        <div>
          <div class="eyebrow" id="heroEyebrow">STV DASHBOARD</div>
          <h1 class="hero-title" id="heroTitle">Swap To Value from Minted RWA Cert NFTs</h1>
          <p class="hero-sub" id="heroSub">
            STV accepts only Minted RWA Cert NFTs. Follow the guided steps below from application to approval.
          </p>

          <div class="hero-total" id="heroTotal">
            <small id="heroTotalLabel">TOTAL STV VALUE</small>
            RWA€ 0
          </div>

          <div class="chip-row">
            <span class="chip" id="eligibleCountChip">Eligible Minted NFTs: 0</span>
            <span class="chip" id="mintedOnlyChip">Minted NFT Only</span>
            <span class="chip" id="userChip"><?= h($displayName) ?></span>
          </div>

          <div class="hero-actions">
            <a class="btn" href="/rwa/storage/" id="backToStorageBtn">Back to Storage</a>
            <button type="button" class="btn secondary" id="btnReloadStv">Reload STV</button>
          </div>
        </div>

        <div class="hero-side">
          <div class="metric-grid">
            <div class="metric-card">
              <div class="metric-k" id="approvedLabel">APPROVED STV 75%</div>
              <div class="metric-v" id="approvedValue">0 / 0</div>
              <div class="progress">
                <div class="progress__bar" id="approvedBar" style="width:0%"></div>
              </div>
              <div class="metric-n" id="approvedNote">Used / Available</div>
            </div>

            <div class="metric-card">
              <div class="metric-k" id="lockedLabel">LOCKED STV 25%</div>
              <div class="metric-v" id="lockedValue">RWA€ 0</div>
              <div class="metric-n" id="lockedNote">Reserved STV by policy and not immediately usable.</div>
            </div>
          </div>

          <div class="metric-card">
            <div class="metric-k" id="statusLabel">APPLICATION STATUS</div>
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
              <div class="metric-v" id="statusValue">Draft</div>
              <span class="badge draft" id="statusBadge">Draft</span>
            </div>
            <div class="metric-n" id="statusNote">The application progresses from draft to review and approval after document submission.</div>
          </div>
        </div>
      </div>
    </section>

    <section class="steps" id="stepGuide">
      <article class="step is-current" id="step1">
        <div class="step-no">1</div>
        <h3 class="step-title" id="step1Title">Select NFT</h3>
        <p class="step-cap" id="step1Cap">Choose eligible minted RWA Cert NFTs for STV.</p>
      </article>

      <article class="step is-locked" id="step2">
        <div class="step-no">2</div>
        <h3 class="step-title" id="step2Title">Choose Application</h3>
        <p class="step-cap" id="step2Cap">Select Personal STV or Business STV.</p>
      </article>

      <article class="step is-locked" id="step3">
        <div class="step-no">3</div>
        <h3 class="step-title" id="step3Title">Upload Documents</h3>
        <p class="step-cap" id="step3Cap">Upload recent required documents into STV folder.</p>
      </article>

      <article class="step is-locked" id="step4">
        <div class="step-no">4</div>
        <h3 class="step-title" id="step4Title">Review & Submit</h3>
        <p class="step-cap" id="step4Cap">Check readiness, precheck, then submit the application.</p>
      </article>

      <article class="step is-locked" id="step5">
        <div class="step-no">5</div>
        <h3 class="step-title" id="step5Title">Approval</h3>
        <p class="step-cap" id="step5Cap">Track draft, submitted, under review, and approval states.</p>
      </article>
    </section>

    <section class="dashboard">
      <div style="display:grid;gap:16px;">
        <section class="panel">
          <div class="panel__head">
            <div>
              <div class="eyebrow" id="eligibleEyebrow">STEP 1 · ELIGIBLE MINTED NFTS</div>
              <h2 class="panel__title" id="eligibleTitle">Select Eligible NFTs</h2>
              <p class="panel__sub" id="eligibleSub">Choose the minted NFTs you want to use for STV.</p>
            </div>
          </div>

          <div class="panel__body">
            <div class="toolbar">
              <input
                type="text"
                class="search"
                id="stvSearchInput"
                placeholder="Search cert UID / RWA code"
                autocomplete="off"
                spellcheck="false"
              >
              <div class="status" id="stvStatus">Loading eligible NFTs…</div>
            </div>

            <div class="card-grid" id="stvEligibleGrid"></div>
          </div>
        </section>

        <section class="panel">
          <div class="panel__head">
            <div>
              <div class="eyebrow" id="applyEyebrow">STEP 2 · APPLICATION TYPE</div>
              <h2 class="panel__title" id="applyTitle">Choose Personal or Business STV</h2>
              <p class="panel__sub" id="applySub">The checklist and upload form will change based on your STV type.</p>
            </div>
          </div>

          <div class="panel__body">
            <div class="type-grid">
              <button type="button" class="type-card" id="btnTypePersonal" data-type="personal">
                <div class="type-title" id="personalTypeTitle">Personal STV</div>
                <p class="type-sub" id="personalTypeSub">For salary earner or self-employed individual application.</p>
              </button>

              <button type="button" class="type-card" id="btnTypeBusiness" data-type="business">
                <div class="type-title" id="businessTypeTitle">Business STV</div>
                <p class="type-sub" id="businessTypeSub">For company submission with directors and company documents.</p>
              </button>
            </div>

            <div class="subtype-row" id="stvSubtypeRow" hidden></div>
          </div>
        </section>

        <section class="panel">
          <div class="panel__head">
            <div>
              <div class="eyebrow" id="uploadEyebrow">STEP 3 · DOCUMENT UPLOAD</div>
              <h2 class="panel__title" id="uploadTitle">Upload Required Documents</h2>
              <p class="panel__sub" id="uploadSub">Attach recent supporting documents. Different application types show different checklist items.</p>
            </div>
          </div>

          <div class="panel__body">
            <div class="checklist" id="stvChecklistWrap">
              <div class="empty" id="stvChecklistEmpty">Choose Personal or Business STV to show checklist and upload fields.</div>
            </div>

            <div class="foot-note" id="stvChecklistNote">
              Files are uploaded to Google Drive under the STV folder. Only metadata and Drive pointers are stored by the backend.
            </div>
          </div>
        </section>
      </div>

      <aside class="rail">
        <section class="panel">
          <div class="panel__head">
            <div>
              <div class="eyebrow" id="summaryEyebrow">APPLICATION SUMMARY</div>
              <h2 class="panel__title" id="summaryTitle">Summary</h2>
              <p class="panel__sub" id="summarySub">Live summary of selected NFTs, STV value, type, and uploads.</p>
            </div>
          </div>

          <div class="panel__body">
            <div class="summary-list">
              <div class="summary-row"><div class="left" id="sumNftLabel">Selected NFTs</div><div class="right" id="sumNftValue">0</div></div>
              <div class="summary-row"><div class="left" id="sumTotalLabel">Total STV Value</div><div class="right" id="sumTotalValue">RWA€ 0</div></div>
              <div class="summary-row"><div class="left" id="sumApprovedLabel">Approved STV</div><div class="right" id="sumApprovedValue">0 / 0</div></div>
              <div class="summary-row"><div class="left" id="sumLockedLabel">Locked STV</div><div class="right" id="sumLockedValue">RWA€ 0</div></div>
              <div class="summary-row"><div class="left" id="sumTypeLabel">Type</div><div class="right" id="sumTypeValue">-</div></div>
              <div class="summary-row"><div class="left" id="sumSubtypeLabel">Subtype</div><div class="right" id="sumSubtypeValue">-</div></div>
              <div class="summary-row"><div class="left" id="sumDocsLabel">Uploaded Docs</div><div class="right" id="sumDocsValue">0 / 0</div></div>
            </div>
          </div>
        </section>

        <section class="panel">
          <div class="panel__head">
            <div>
              <div class="eyebrow" id="readyEyebrow">READINESS CHECK</div>
              <h2 class="panel__title" id="readyTitle">Readiness</h2>
              <p class="panel__sub" id="readySub">Complete all checks before final submission.</p>
            </div>
          </div>

          <div class="panel__body">
            <div class="readiness" id="readinessWrap">
              <div class="ready-row err" id="readyNft"><span id="readyNftLabel">NFT selected</span><strong id="readyNftValue">Missing</strong></div>
              <div class="ready-row err" id="readyType"><span id="readyTypeLabel">Application type chosen</span><strong id="readyTypeValue">Missing</strong></div>
              <div class="ready-row err" id="readyDocs"><span id="readyDocsLabel">Required docs uploaded</span><strong id="readyDocsValue">Missing</strong></div>
              <div class="ready-row err" id="readySubmit"><span id="readySubmitLabel">Ready for submit</span><strong id="readySubmitValue">No</strong></div>
            </div>
          </div>
        </section>

        <section class="panel">
          <div class="panel__head">
            <div>
              <div class="eyebrow" id="submitEyebrow">STEP 4 · REVIEW & SUBMIT</div>
              <h2 class="panel__title" id="submitTitle">Submit Application</h2>
              <p class="panel__sub" id="submitSub">Precheck selected NFTs first, then finalize the STV application.</p>
            </div>
          </div>

          <div class="panel__body" style="display:grid;gap:10px;">
            <button type="button" class="btn secondary" id="btnPrecheck" disabled>Review STV Precheck</button>
            <button type="button" class="btn" id="btnSubmitApplication" disabled>Submit STV Application</button>
            <div class="status" id="submitStatus"></div>
          </div>
        </section>

        <section class="panel">
          <div class="panel__head">
            <div>
              <div class="eyebrow" id="approvalEyebrow">STEP 5 · APPROVAL</div>
              <h2 class="panel__title" id="approvalTitle">Approval State</h2>
              <p class="panel__sub" id="approvalSub">Track draft, submitted, under review, and approval stages.</p>
            </div>
          </div>

          <div class="panel__body">
            <div class="summary-list">
              <div class="summary-row"><div class="left" id="appUidLabel">Application UID</div><div class="right" id="appUidValue">-</div></div>
              <div class="summary-row"><div class="left" id="appStateLabel">Current State</div><div class="right" id="appStateValue">Draft</div></div>
              <div class="summary-row"><div class="left" id="appStateNoteLabel">Note</div><div class="right" id="appStateNoteValue">Waiting for document upload and submission.</div></div>
            </div>
          </div>
        </section>
      </aside>
    </section>

  </main>

  <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php'; ?>
  <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/gt-inline.php'; ?>

  <script>
    window.STV_PAGE_BOOT = {
      lang: <?= json_encode($langDefault, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      userId: <?= json_encode((string)($user['id'] ?? 0), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      wallet: <?= json_encode((string)($user['wallet'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      walletAddress: <?= json_encode($walletAddress, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      displayName: <?= json_encode($displayName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      endpoints: {
        summary: "/rwa/swap/stv/api/stv-summary.php",
        eligible: "/rwa/swap/stv/api/stv-eligible.php",
        upload: "/rwa/swap/stv/api/stv-upload.php",
        apply: "/rwa/swap/stv/api/stv-apply.php",
        submit: "/rwa/swap/stv/api/stv-submit.php"
      }
    };
  </script>

  <script>
    (function () {
      var boot = window.STV_PAGE_BOOT || {};
      var state = {
        items: [],
        filtered: [],
        selected: {},
        lang: boot.lang || 'en',
        applicationUid: '',
        preapprovedTotal: 0,
        approvedTotal: 0,
        lockedTotal: 0,
        usedApproved: 0,
        stvType: '',
        subtype: '',
        uploadedDocs: {},
        lastPrecheck: null,
        appStatus: 'draft'
      };

      var i18n = {
        en: {
          heroTitle: 'Swap To Value from Minted RWA Cert NFTs',
          heroSub: 'STV accepts only Minted RWA Cert NFTs. Follow the guided steps below from application to approval.',
          totalLabel: 'TOTAL STV VALUE',
          eligibleCount: 'Eligible Minted NFTs',
          mintedOnly: 'Minted NFT Only',
          approvedLabel: 'APPROVED STV 75%',
          lockedLabel: 'LOCKED STV 25%',
          approvedNote: 'Used / Available',
          lockedNote: 'Reserved STV by policy and not immediately usable.',
          loading: 'Loading eligible NFTs…',
          loadFailed: 'STV data load failed',
          noData: 'No eligible minted NFT found.',
          verify: 'Verify',
          select: 'Select',
          selected: 'Selected',
          mintedAt: 'Minted At',
          nftItem: 'NFT Item',
          status: 'Status',
          typePersonal: 'Personal STV',
          typePersonalSub: 'For salary earner or self-employed individual application.',
          typeBusiness: 'Business STV',
          typeBusinessSub: 'For company submission with directors and company documents.',
          salaryEarner: 'Salary Earner',
          selfEmployed: 'Self-Employed',
          businessDefault: 'Business Submission',
          checklistEmpty: 'Choose Personal or Business STV to show checklist and upload fields.',
          checklistNote: 'Files are uploaded to Google Drive under the STV folder. Only metadata and Drive pointers are stored by the backend.',
          chooseFile: 'Choose File',
          upload: 'Upload',
          replace: 'Replace',
          uploaded: 'Uploaded',
          uploadIdle: 'No file uploaded yet.',
          fileRequired: 'Choose file first.',
          uploading: 'Uploading…',
          uploadErr: 'Upload failed.',
          uploadOk: 'Uploaded successfully.',
          precheckBtn: 'Review STV Precheck',
          submitBtn: 'Submit STV Application',
          submitting: 'Submitting STV application…',
          prechecking: 'Preparing STV precheck…',
          submitNeedType: 'Choose Personal or Business STV first.',
          submitNeedSubtype: 'Choose subtype first.',
          submitNeedCert: 'Select at least one eligible minted NFT.',
          precheckOk: 'STV precheck ready.',
          submitOk: 'STV application submitted successfully.',
          step1: 'Select NFT',
          step1Cap: 'Choose eligible minted RWA Cert NFTs for STV.',
          step2: 'Choose Application',
          step2Cap: 'Select Personal STV or Business STV.',
          step3: 'Upload Documents',
          step3Cap: 'Upload recent required documents into STV folder.',
          step4: 'Review & Submit',
          step4Cap: 'Check readiness, precheck, then submit the application.',
          step5: 'Approval',
          step5Cap: 'Track draft, submitted, under review, and approval states.',
          sumSelectedNfts: 'Selected NFTs',
          sumTotal: 'Total STV Value',
          sumApproved: 'Approved STV',
          sumLocked: 'Locked STV',
          sumType: 'Type',
          sumSubtype: 'Subtype',
          sumDocs: 'Uploaded Docs',
          readyNft: 'NFT selected',
          readyType: 'Application type chosen',
          readyDocs: 'Required docs uploaded',
          readySubmit: 'Ready for submit',
          missing: 'Missing',
          ready: 'Ready',
          partial: 'Partial',
          yes: 'Yes',
          no: 'No',
          draft: 'Draft',
          submitted: 'Submitted',
          waitingSubmit: 'Waiting for document upload and submission.',
          personalPayslipsTitle: 'Latest 3 months payslips',
          personalPayslipsNote: 'Required for salary earner.',
          personalEpfTitle: 'EPF statement (most recent year)',
          personalEpfNote: 'Salary earner alternative supporting document.',
          personalSalaryBankTitle: 'Latest 3 months salary-credit bank statements',
          personalSalaryBankNote: 'Salary earner alternative supporting document.',
          personalCompanyStmtTitle: 'Latest 6 months company / personal bank statements',
          personalCompanyStmtNote: 'Self-employed supporting document.',
          personalFormBTitle: 'Form B (most recent year) with acknowledgement receipt',
          personalFormBNote: 'Self-employed alternative supporting document.',
          personalSsmTitle: 'SSM registration documents',
          personalSsmNote: 'Required for self-employed.',
          businessConsentTitle: 'Consent letter signed by all directors',
          businessConsentNote: 'Malaysian only, aged 18–65.',
          businessIcTitle: 'All directors’ IC copy',
          businessIcNote: 'Clear copy required.',
          businessCtosTitle: 'Latest CTOS report',
          businessCtosNote: 'Most recent report required.',
          businessSsmTitle: 'SSM company profile',
          businessSsmNote: 'From SSM e-portal.',
          businessAuditTitle: 'Latest audited annual report',
          businessAuditNote: 'Use the most recent year.',
          businessMgmtTitle: 'Latest management accounts',
          businessMgmtNote: 'Profit & Loss and Balance Sheet.',
          businessAgingTitle: 'Latest debtors and creditors ageing report',
          businessAgingNote: 'Current company aging report.',
          businessBankTitle: 'Latest 6 months company bank statements',
          businessBankNote: 'E-statements only. Include all active business accounts.'
        },
        zh: {
          heroTitle: '以已铸造 RWA Cert NFT 进行 Swap To Value',
          heroSub: 'STV 只接受已铸造的 RWA Cert NFT。请按下方步骤，从申请走到审批。',
          totalLabel: '总 STV 数值',
          eligibleCount: '可用已铸造 NFT',
          mintedOnly: '仅限已铸造 NFT',
          approvedLabel: '已批准 STV 75%',
          lockedLabel: '锁定 STV 25%',
          approvedNote: '已使用 / 可用',
          lockedNote: '按政策锁定保留的 STV 部分，当前不可立即使用。',
          loading: '正在加载可用 NFT…',
          loadFailed: 'STV 数据加载失败',
          noData: '当前没有可用于 STV 的已铸造 NFT。',
          verify: '查看验证',
          select: '选择',
          selected: '已选择',
          mintedAt: '铸造时间',
          nftItem: 'NFT 项目地址',
          status: '状态',
          typePersonal: 'Personal STV',
          typePersonalSub: '适用于受薪人士或个体自雇申请。',
          typeBusiness: 'Business STV',
          typeBusinessSub: '适用于公司文件与董事文件申请。',
          salaryEarner: '受薪人士',
          selfEmployed: '自雇人士',
          businessDefault: '企业申请',
          checklistEmpty: '请选择 Personal 或 Business STV 以显示清单和上传栏。',
          checklistNote: '文件会上传到 Google Drive 的 STV 文件夹。后端只保存元数据和 Drive 指针。',
          chooseFile: '选择文件',
          upload: '上传',
          replace: '替换',
          uploaded: '已上传',
          uploadIdle: '尚未上传文件。',
          fileRequired: '请先选择文件。',
          uploading: '正在上传…',
          uploadErr: '上传失败。',
          uploadOk: '上传成功。',
          precheckBtn: '查看 STV 预检查',
          submitBtn: '提交 STV 申请',
          submitting: '正在提交 STV 申请…',
          prechecking: '正在准备 STV 预检查…',
          submitNeedType: '请先选择 Personal 或 Business STV。',
          submitNeedSubtype: '请先选择子类型。',
          submitNeedCert: '请至少选择一张可用已铸造 NFT。',
          precheckOk: 'STV 预检查已完成。',
          submitOk: 'STV 申请已成功提交。',
          step1: '选择 NFT',
          step1Cap: '选择可用于 STV 的已铸造 RWA Cert NFT。',
          step2: '选择申请类型',
          step2Cap: '选择 Personal STV 或 Business STV。',
          step3: '上传文件',
          step3Cap: '上传近期所需文件到 STV 文件夹。',
          step4: '检查并提交',
          step4Cap: '检查完整度，预检查后再提交申请。',
          step5: '审批',
          step5Cap: '跟踪草稿、已提交、审核中与批准状态。',
          sumSelectedNfts: '已选择 NFT',
          sumTotal: '总 STV 数值',
          sumApproved: '已批准 STV',
          sumLocked: '锁定 STV',
          sumType: '类型',
          sumSubtype: '子类型',
          sumDocs: '已上传文件',
          readyNft: '已选择 NFT',
          readyType: '已选择申请类型',
          readyDocs: '已上传所需文件',
          readySubmit: '可提交',
          missing: '缺少',
          ready: '已完成',
          partial: '部分',
          yes: '是',
          no: '否',
          draft: '草稿',
          submitted: '已提交',
          waitingSubmit: '等待上传文件并提交申请。',
          personalPayslipsTitle: '最近 3 个月薪资单',
          personalPayslipsNote: '受薪人士必需文件。',
          personalEpfTitle: 'EPF 报表（最近年份）',
          personalEpfNote: '受薪人士替代辅助文件。',
          personalSalaryBankTitle: '最近 3 个月工资入账银行月结单',
          personalSalaryBankNote: '受薪人士替代辅助文件。',
          personalCompanyStmtTitle: '最近 6 个月个人 / 公司银行月结单',
          personalCompanyStmtNote: '自雇人士辅助文件。',
          personalFormBTitle: 'Form B（最近年份）及回执',
          personalFormBNote: '自雇人士替代辅助文件。',
          personalSsmTitle: 'SSM 注册文件',
          personalSsmNote: '自雇人士必需文件。',
          businessConsentTitle: '所有董事签署同意书',
          businessConsentNote: '仅限马来西亚人，年龄 18–65。',
          businessIcTitle: '所有董事身份证副本',
          businessIcNote: '需要清晰副本。',
          businessCtosTitle: '最新 CTOS 报告',
          businessCtosNote: '需最新报告。',
          businessSsmTitle: 'SSM 公司资料',
          businessSsmNote: '来自 SSM e-portal。',
          businessAuditTitle: '最新审计年报',
          businessAuditNote: '请使用最近年份。',
          businessMgmtTitle: '最新管理账目',
          businessMgmtNote: '包括 Profit & Loss 和 Balance Sheet。',
          businessAgingTitle: '最新 Debtors / Creditors Aging 报告',
          businessAgingNote: '当前公司 aging 报告。',
          businessBankTitle: '最近 6 个月公司银行月结单',
          businessBankNote: '只接受 e-statement，包含所有活跃业务账户。'
        }
      };

      var DOCS = {
        personal: {
          salary_earner: [
            { key:'payslips', titleKey:'personalPayslipsTitle', noteKey:'personalPayslipsNote', required:true },
            { key:'epf_recent_year', titleKey:'personalEpfTitle', noteKey:'personalEpfNote', required:false },
            { key:'salary_bank_statements', titleKey:'personalSalaryBankTitle', noteKey:'personalSalaryBankNote', required:false }
          ],
          self_employed: [
            { key:'company_bank_statements_6m', titleKey:'personalCompanyStmtTitle', noteKey:'personalCompanyStmtNote', required:false },
            { key:'form_b_recent_year', titleKey:'personalFormBTitle', noteKey:'personalFormBNote', required:false },
            { key:'ssm_doc', titleKey:'personalSsmTitle', noteKey:'personalSsmNote', required:true }
          ]
        },
        business: {
          company: [
            { key:'consent_letter', titleKey:'businessConsentTitle', noteKey:'businessConsentNote', required:true },
            { key:'directors_ic', titleKey:'businessIcTitle', noteKey:'businessIcNote', required:true },
            { key:'ctos_report', titleKey:'businessCtosTitle', noteKey:'businessCtosNote', required:true },
            { key:'ssm_company_profile', titleKey:'businessSsmTitle', noteKey:'businessSsmNote', required:true },
            { key:'audited_annual_report', titleKey:'businessAuditTitle', noteKey:'businessAuditNote', required:true },
            { key:'management_account', titleKey:'businessMgmtTitle', noteKey:'businessMgmtNote', required:true },
            { key:'ageing_report', titleKey:'businessAgingTitle', noteKey:'businessAgingNote', required:true },
            { key:'company_bank_statements_6m', titleKey:'businessBankTitle', noteKey:'businessBankNote', required:true }
          ]
        }
      };

      function t(key) {
        var dict = i18n[state.lang] || i18n.en;
        return dict[key] || i18n.en[key] || key;
      }

      function esc(v) {
        return String(v == null ? '' : v)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      function rwae(n) {
        return 'RWA€ ' + Number(n || 0).toLocaleString();
      }

      var hero = document.getElementById('stvHero');
      var heroTotal = document.getElementById('heroTotal');
      var eligibleCountChip = document.getElementById('eligibleCountChip');
      var approvedValue = document.getElementById('approvedValue');
      var approvedBar = document.getElementById('approvedBar');
      var lockedValue = document.getElementById('lockedValue');
      var statusValue = document.getElementById('statusValue');
      var statusBadge = document.getElementById('statusBadge');
      var statusNote = document.getElementById('statusNote');

      var stvStatus = document.getElementById('stvStatus');
      var searchEl = document.getElementById('stvSearchInput');
      var eligibleGrid = document.getElementById('stvEligibleGrid');

      var btnReloadStv = document.getElementById('btnReloadStv');
      var btnTypePersonal = document.getElementById('btnTypePersonal');
      var btnTypeBusiness = document.getElementById('btnTypeBusiness');
      var subtypeRow = document.getElementById('stvSubtypeRow');
      var checklistWrap = document.getElementById('stvChecklistWrap');
      var btnPrecheck = document.getElementById('btnPrecheck');
      var btnSubmit = document.getElementById('btnSubmitApplication');
      var submitStatus = document.getElementById('submitStatus');

      var state = {
        items: [],
        filtered: [],
        selected: {},
        lang: boot.lang || 'en',
        applicationUid: '',
        preapprovedTotal: 0,
        approvedTotal: 0,
        lockedTotal: 0,
        usedApproved: 0,
        stvType: '',
        subtype: '',
        uploadedDocs: {},
        lastPrecheck: null,
        appStatus: 'draft'
      };

      function setHeroLoading(on) {
        if (hero) hero.classList.toggle('is-loading', !!on);
      }

      function setStatus(text) {
        if (stvStatus) stvStatus.textContent = text || '';
      }

      function fetchJson(url, opts) {
        return fetch(url, opts || {
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { 'Accept': 'application/json' }
        }).then(function (res) {
          return res.json().then(function (json) {
            if (!res.ok || !json || json.ok !== true) {
              throw new Error((json && (json.error || json.message)) || ('HTTP_' + res.status));
            }
            return json;
          });
        });
      }

      function getDocsForCurrentSelection() {
        if (!state.stvType || !state.subtype) return [];
        var group = DOCS[state.stvType] || {};
        return group[state.subtype] || [];
      }

      function getRequiredDocCount() {
        var docs = getDocsForCurrentSelection();
        if (state.stvType === 'personal' && state.subtype === 'salary_earner') {
          return 2;
        }
        if (state.stvType === 'personal' && state.subtype === 'self_employed') {
          return 2;
        }
        return docs.filter(function (d) { return !!d.required; }).length;
      }

      function getUploadedDocCount() {
        return Object.keys(state.uploadedDocs).filter(function (key) {
          return state.uploadedDocs[key] && state.uploadedDocs[key].uploaded;
        }).length;
      }

      function areRequiredDocsReady() {
        if (!state.stvType || !state.subtype) return false;

        var uploaded = {};
        Object.keys(state.uploadedDocs).forEach(function (key) {
          if (state.uploadedDocs[key] && state.uploadedDocs[key].uploaded) uploaded[key] = true;
        });

        if (state.stvType === 'personal' && state.subtype === 'salary_earner') {
          return !!uploaded['payslips'] && (!!uploaded['epf_recent_year'] || !!uploaded['salary_bank_statements']);
        }

        if (state.stvType === 'personal' && state.subtype === 'self_employed') {
          return !!uploaded['ssm_doc'] && (!!uploaded['company_bank_statements_6m'] || !!uploaded['form_b_recent_year']);
        }

        var docs = getDocsForCurrentSelection();
        return docs.filter(function (d) { return !!d.required; }).every(function (d) {
          return !!uploaded[d.key];
        });
      }

      function selectedCount() {
        return Object.keys(state.selected).length;
      }

      function computeSelectedTotal() {
        var total = 0;
        state.items.forEach(function (item) {
          if (state.selected[item.cert_uid]) total += Number(item.stv_value || 0);
        });
        return total;
      }

      function updateApprovedBar() {
        var used = Number(state.usedApproved || 0);
        var total = Number(state.approvedTotal || 0);
        var pct = total > 0 ? Math.min(100, (used / total) * 100) : 0;

        approvedValue.textContent = used.toLocaleString() + ' / ' + total.toLocaleString();
        approvedBar.style.width = pct + '%';
        approvedBar.className = 'progress__bar';
        if (pct >= 80) approvedBar.classList.add('danger');
        else if (pct >= 50) approvedBar.classList.add('warn');
      }

      function fillSummary(summary) {
        var total = Number(summary.preapproved_total || 0);
        var approved = Math.floor(total * 0.75);
        var locked = total - approved;
        var used = Number(summary.used_stv || 0);

        state.preapprovedTotal = total;
        state.approvedTotal = approved;
        state.lockedTotal = locked;
        state.usedApproved = used;

        heroTotal.innerHTML = '<small>' + esc(t('totalLabel')) + '</small>' + esc(rwae(total));
        eligibleCountChip.textContent = t('eligibleCount') + ': ' + String(summary.eligible_count || 0);
        lockedValue.textContent = rwae(locked);
        updateApprovedBar();
      }

      function fillEligible(payload) {
        state.items = Array.isArray(payload.items) ? payload.items.slice() : [];
        filterGrid();
        renderGrid();
        setStatus(t('eligibleCount') + ': ' + state.items.length);
      }

      function filterGrid() {
        var q = String((searchEl && searchEl.value) || '').trim().toLowerCase();
        if (!q) {
          state.filtered = state.items.slice();
          return;
        }
        state.filtered = state.items.filter(function (item) {
          var hay = [
            item.cert_uid,
            item.rwa_code,
            item.rwa_type,
            item.family,
            item.nft_item_address
          ].join(' ').toLowerCase();
          return hay.indexOf(q) >= 0;
        });
      }

      function renderGrid() {
        filterGrid();

        if (!state.filtered.length) {
          eligibleGrid.innerHTML = '<div class="empty">' + esc(t('noData')) + '</div>';
          updateDashboard();
          return;
        }

        eligibleGrid.innerHTML = state.filtered.map(function (item) {
          var isSelected = !!state.selected[item.cert_uid];
          return '' +
            '<article class="nft-card">' +
              '<div class="nft-card__top">' +
                '<img class="nft-card__thumb" src="' + esc(item.nft_image || '/rwa/metadata/ema.png') + '" alt="' + esc(item.rwa_code || 'NFT') + '">' +
                '<div>' +
                  '<div class="nft-card__code">' + esc(item.rwa_code || '-') + '</div>' +
                  '<div class="nft-card__uid">' + esc(item.cert_uid || '-') + '</div>' +
                  '<div class="nft-card__meta">' +
                    '<div class="nft-card__row"><span>' + esc(t('status')) + '</span><span class="value">' + esc(item.status || '-') + '</span></div>' +
                    '<div class="nft-card__row"><span>' + esc(t('mintedAt')) + '</span><span class="value">' + esc(item.minted_at || '-') + '</span></div>' +
                    '<div class="nft-card__row"><span>' + esc(t('nftItem')) + '</span><span class="value">' + esc(item.nft_item_address || '-') + '</span></div>' +
                  '</div>' +
                '</div>' +
              '</div>' +
              '<div class="nft-card__value"><small>STV VALUE</small>' + esc(rwae(item.stv_value || 0)) + '</div>' +
              '<div class="nft-card__actions">' +
                '<a class="btn secondary" href="' + esc(item.verify_url || '#') + '" target="_blank" rel="noopener">' + esc(t('verify')) + '</a>' +
                '<button type="button" class="btn" data-toggle-cert="' + esc(item.cert_uid) + '">' + esc(isSelected ? t('selected') : t('select')) + '</button>' +
              '</div>' +
            '</article>';
        }).join('');

        eligibleGrid.querySelectorAll('[data-toggle-cert]').forEach(function (btn) {
          btn.addEventListener('click', function () {
            var uid = String(btn.getAttribute('data-toggle-cert') || '');
            if (!uid) return;
            if (state.selected[uid]) delete state.selected[uid];
            else state.selected[uid] = true;
            renderGrid();
            updateDashboard();
          });
        });

        updateDashboard();
      }

      function renderSubtypeRow() {
        if (!state.stvType) {
          subtypeRow.hidden = true;
          subtypeRow.innerHTML = '';
          return;
        }

        subtypeRow.hidden = false;
        var html = '';

        if (state.stvType === 'personal') {
          html += '<button type="button" class="pill ' + (state.subtype === 'salary_earner' ? 'is-active' : '') + '" data-subtype="salary_earner">' + esc(t('salaryEarner')) + '</button>';
          html += '<button type="button" class="pill ' + (state.subtype === 'self_employed' ? 'is-active' : '') + '" data-subtype="self_employed">' + esc(t('selfEmployed')) + '</button>';
        } else {
          if (!state.subtype) state.subtype = 'company';
          html += '<button type="button" class="pill is-active" data-subtype="company">' + esc(t('businessDefault')) + '</button>';
        }

        subtypeRow.innerHTML = html;
        subtypeRow.querySelectorAll('[data-subtype]').forEach(function (btn) {
          btn.addEventListener('click', function () {
            state.subtype = String(btn.getAttribute('data-subtype') || '');
            state.uploadedDocs = {};
            renderSubtypeRow();
            renderChecklist();
            updateDashboard();
          });
        });
      }

      function ensureUploadState(docKey) {
        if (!state.uploadedDocs[docKey]) {
          state.uploadedDocs[docKey] = {
            uploaded: false,
            original_name: '',
            google_drive_url: ''
          };
        }
        return state.uploadedDocs[docKey];
      }

      function docRowHtml(doc) {
        var up = ensureUploadState(doc.key);
        var statusText = up.uploaded ? (t('uploaded') + ': ' + (up.original_name || '')) : t('uploadIdle');
        var statusClass = up.uploaded ? 'ok' : '';

        return '' +
          '<div class="doc-row" data-doc-key="' + esc(doc.key) + '">' +
            '<div>' +
              '<h3 class="doc-title">' + esc(t(doc.titleKey)) + (doc.required ? ' *' : '') + '</h3>' +
              '<p class="doc-note">' + esc(t(doc.noteKey)) + '</p>' +
            '</div>' +
            '<div class="doc-right">' +
              '<input type="file" class="file-input" data-file-input="' + esc(doc.key) + '" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp">' +
              '<div class="doc-actions">' +
                '<button type="button" class="btn secondary" data-upload-doc="' + esc(doc.key) + '">' + esc(up.uploaded ? t('replace') : t('upload')) + '</button>' +
                (up.uploaded && up.google_drive_url ? '<a class="btn secondary" href="' + esc(up.google_drive_url) + '" target="_blank" rel="noopener">' + esc(t('uploaded')) + '</a>' : '') +
              '</div>' +
              '<div class="doc-status ' + statusClass + '" data-doc-status="' + esc(doc.key) + '">' + esc(statusText) + '</div>' +
            '</div>' +
          '</div>';
      }

      function renderChecklist() {
        if (!state.stvType || !state.subtype) {
          checklistWrap.innerHTML = '<div class="empty">' + esc(t('checklistEmpty')) + '</div>';
          updateDashboard();
          return;
        }

        var docs = getDocsForCurrentSelection();
        if (!docs.length) {
          checklistWrap.innerHTML = '<div class="empty">' + esc(t('checklistEmpty')) + '</div>';
          updateDashboard();
          return;
        }

        checklistWrap.innerHTML = docs.map(docRowHtml).join('');

        checklistWrap.querySelectorAll('[data-upload-doc]').forEach(function (btn) {
          btn.addEventListener('click', function () {
            uploadDoc(String(btn.getAttribute('data-upload-doc') || ''), btn);
          });
        });

        updateDashboard();
      }

      async function uploadDoc(docKey, btn) {
        if (!state.stvType) {
          alert(t('submitNeedType'));
          return;
        }
        if (!state.subtype) {
          alert(t('submitNeedSubtype'));
          return;
        }

        var input = checklistWrap.querySelector('[data-file-input="' + docKey + '"]');
        var statusNode = checklistWrap.querySelector('[data-doc-status="' + docKey + '"]');

        if (!input || !input.files || !input.files[0]) {
          if (statusNode) {
            statusNode.textContent = t('fileRequired');
            statusNode.className = 'doc-status err';
          }
          return;
        }

        var file = input.files[0];
        var originalText = btn.textContent;
        btn.disabled = true;
        btn.textContent = t('uploading');

        if (statusNode) {
          statusNode.textContent = t('uploading');
          statusNode.className = 'doc-status warn';
        }

        try {
          var fd = new FormData();
          fd.append('stv_type', state.stvType);
          fd.append('subtype', state.subtype);
          fd.append('doc_key', docKey);
          fd.append('application_uid', state.applicationUid || '');
          fd.append('preapproved_total', String(state.preapprovedTotal || 0));
          fd.append('file', file);

          var res = await fetch(boot.endpoints.upload, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
          });

          var json = await res.json();
          if (!res.ok || !json || json.ok !== true) {
            throw new Error((json && (json.error || json.message)) || ('HTTP_' + res.status));
          }

          if (json.application_uid) state.applicationUid = String(json.application_uid);
          state.uploadedDocs[docKey] = {
            uploaded: true,
            original_name: String(json.file && json.file.original_name || file.name || ''),
            google_drive_url: String(json.file && json.file.google_drive_url || '')
          };

          if (statusNode) {
            statusNode.textContent = t('uploaded') + ': ' + state.uploadedDocs[docKey].original_name;
            statusNode.className = 'doc-status ok';
          }

          document.getElementById('appUidValue').textContent = state.applicationUid || '-';
          renderChecklist();
          updateDashboard();
        } catch (e) {
          if (statusNode) {
            statusNode.textContent = t('uploadErr') + ' ' + String(e.message || e);
            statusNode.className = 'doc-status err';
          }
          updateDashboard();
        } finally {
          btn.disabled = false;
          btn.textContent = originalText;
        }
      }

      async function runPrecheck() {
        if (!state.stvType) {
          alert(t('submitNeedType'));
          return;
        }
        if (!state.subtype) {
          alert(t('submitNeedSubtype'));
          return;
        }
        if (!selectedCount()) {
          alert(t('submitNeedCert'));
          return;
        }
        if (!state.applicationUid) {
          alert('Please upload required documents first.');
          return;
        }

        var original = btnPrecheck.textContent;
        btnPrecheck.disabled = true;
        btnPrecheck.textContent = t('prechecking');
        submitStatus.textContent = t('prechecking');

        try {
          var json = await fetchJson(boot.endpoints.apply, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json'
            },
            body: JSON.stringify({
              application_uid: state.applicationUid,
              selected_cert_uids: Object.keys(state.selected)
            })
          });

          state.lastPrecheck = json;
          state.approvedTotal = Number(json.prepared && json.prepared.approved_stv || state.approvedTotal);
          state.lockedTotal = Number(json.prepared && json.prepared.locked_stv || state.lockedTotal);
          state.appStatus = String(json.status || state.appStatus);
          updateApprovedBar();
          updateDashboard();

          submitStatus.textContent = t('precheckOk');
        } catch (e) {
          submitStatus.textContent = String(e.message || e);
        } finally {
          btnPrecheck.disabled = false;
          btnPrecheck.textContent = original;
        }
      }

      async function submitApplication() {
        if (!state.stvType) {
          alert(t('submitNeedType'));
          return;
        }
        if (!state.subtype) {
          alert(t('submitNeedSubtype'));
          return;
        }
        if (!selectedCount()) {
          alert(t('submitNeedCert'));
          return;
        }
        if (!state.applicationUid) {
          alert('Please upload required documents first.');
          return;
        }

        var original = btnSubmit.textContent;
        btnSubmit.disabled = true;
        btnSubmit.textContent = t('submitting');
        submitStatus.textContent = t('submitting');

        try {
          var res = await fetch(boot.endpoints.submit, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json'
            },
            body: JSON.stringify({
              application_uid: state.applicationUid,
              stv_type: state.stvType,
              subtype: state.subtype,
              selected_cert_uids: Object.keys(state.selected)
            })
          });

          var json = await res.json();
          if (!res.ok || !json || json.ok !== true) {
            throw new Error((json && (json.error || json.message)) || ('HTTP_' + res.status));
          }

          state.appStatus = 'submitted';
          statusValue.textContent = t('submitted');
          statusBadge.textContent = t('submitted');
          statusBadge.className = 'badge submitted';
          document.getElementById('appStateValue').textContent = t('submitted');
          document.getElementById('appStateNoteValue').textContent = 'Application submitted and waiting for review.';
          submitStatus.textContent = t('submitOk');
          updateDashboard();
        } catch (e) {
          submitStatus.textContent = String(e.message || e);
        } finally {
          btnSubmit.disabled = false;
          btnSubmit.textContent = original;
        }
      }

      function updateStepStates() {
        var hasNft = selectedCount() > 0;
        var hasType = !!state.stvType && !!state.subtype;
        var docsReady = areRequiredDocsReady();
        var readySubmit = hasNft && hasType && docsReady;
        var isSubmitted = state.appStatus === 'submitted';

        var step1 = document.getElementById('step1');
        var step2 = document.getElementById('step2');
        var step3 = document.getElementById('step3');
        var step4 = document.getElementById('step4');
        var step5 = document.getElementById('step5');

        [step1, step2, step3, step4, step5].forEach(function (el) {
          el.classList.remove('is-done', 'is-current', 'is-locked', 'is-error');
        });

        if (hasNft) step1.classList.add('is-done'); else step1.classList.add('is-current');
        if (hasNft && hasType) step2.classList.add('is-done');
        else if (hasNft) step2.classList.add('is-current');
        else step2.classList.add('is-locked');

        if (hasNft && hasType && docsReady) step3.classList.add('is-done');
        else if (hasNft && hasType && !docsReady) step3.classList.add('is-current');
        else step3.classList.add('is-locked');

        if (readySubmit && !isSubmitted) step4.classList.add('is-current');
        else if (isSubmitted) step4.classList.add('is-done');
        else if (hasNft && hasType) step4.classList.add('is-locked');
        else step4.classList.add('is-locked');

        if (isSubmitted) step5.classList.add('is-current');
        else step5.classList.add('is-locked');
      }

      function setReadyRow(id, okState, text) {
        var row = document.getElementById(id);
        row.classList.remove('ok', 'warn', 'err');
        row.classList.add(okState);
        row.querySelector('strong').textContent = text;
      }

      function updateDashboard() {
        var selCount = selectedCount();
        var selTotal = computeSelectedTotal();
        var docsRequired = getRequiredDocCount();
        var docsUploaded = getUploadedDocCount();
        var docsReady = areRequiredDocsReady();
        var hasType = !!state.stvType && !!state.subtype;
        var readySubmit = selCount > 0 && hasType && docsReady;

        document.getElementById('sumNftValue').textContent = String(selCount);
        document.getElementById('sumTotalValue').textContent = rwae(selTotal);
        document.getElementById('sumApprovedValue').textContent = Number(state.usedApproved || 0).toLocaleString() + ' / ' + Number(state.approvedTotal || 0).toLocaleString();
        document.getElementById('sumLockedValue').textContent = rwae(state.lockedTotal || 0);
        document.getElementById('sumTypeValue').textContent = state.stvType ? (state.stvType === 'personal' ? t('typePersonal') : t('typeBusiness')) : '-';
        document.getElementById('sumSubtypeValue').textContent = state.subtype ? (state.subtype === 'salary_earner' ? t('salaryEarner') : (state.subtype === 'self_employed' ? t('selfEmployed') : t('businessDefault'))) : '-';
        document.getElementById('sumDocsValue').textContent = docsUploaded + ' / ' + docsRequired;
        document.getElementById('appUidValue').textContent = state.applicationUid || '-';
        document.getElementById('appStateValue').textContent = state.appStatus === 'submitted' ? t('submitted') : t('draft');

        setReadyRow('readyNft', selCount > 0 ? 'ok' : 'err', selCount > 0 ? t('ready') : t('missing'));
        setReadyRow('readyType', hasType ? 'ok' : 'err', hasType ? t('ready') : t('missing'));
        setReadyRow('readyDocs', docsReady ? 'ok' : (docsUploaded > 0 ? 'warn' : 'err'), docsReady ? t('ready') : (docsUploaded > 0 ? t('partial') : t('missing')));
        setReadyRow('readySubmit', readySubmit ? 'ok' : 'err', readySubmit ? t('yes') : t('no'));

        btnPrecheck.disabled = !readySubmit;
        btnSubmit.disabled = !readySubmit;

        updateStepStates();
      }

      function renderSubtypeRow() {
        if (!state.stvType) {
          subtypeRow.hidden = true;
          subtypeRow.innerHTML = '';
          return;
        }

        subtypeRow.hidden = false;
        var html = '';
        if (state.stvType === 'personal') {
          html += '<button type="button" class="pill ' + (state.subtype === 'salary_earner' ? 'is-active' : '') + '" data-subtype="salary_earner">' + esc(t('salaryEarner')) + '</button>';
          html += '<button type="button" class="pill ' + (state.subtype === 'self_employed' ? 'is-active' : '') + '" data-subtype="self_employed">' + esc(t('selfEmployed')) + '</button>';
        } else {
          if (!state.subtype) state.subtype = 'company';
          html += '<button type="button" class="pill is-active" data-subtype="company">' + esc(t('businessDefault')) + '</button>';
        }

        subtypeRow.innerHTML = html;
        subtypeRow.querySelectorAll('[data-subtype]').forEach(function (btn) {
          btn.addEventListener('click', function () {
            state.subtype = String(btn.getAttribute('data-subtype') || '');
            state.uploadedDocs = {};
            state.applicationUid = '';
            renderSubtypeRow();
            renderChecklist();
            updateDashboard();
          });
        });
      }

      function setType(type) {
        state.stvType = type;
        state.subtype = '';
        state.uploadedDocs = {};
        state.applicationUid = '';
        btnTypePersonal.classList.toggle('is-active', type === 'personal');
        btnTypeBusiness.classList.toggle('is-active', type === 'business');
        renderSubtypeRow();
        renderChecklist();
        updateDashboard();
      }

      function setLang(lang) {
        state.lang = (lang === 'zh') ? 'zh' : 'en';
        document.documentElement.setAttribute('lang', state.lang === 'zh' ? 'zh-CN' : 'en');

        document.querySelectorAll('.lang-btn').forEach(function (btn) {
          btn.classList.toggle('is-active', btn.getAttribute('data-lang') === state.lang);
        });

        document.getElementById('heroTitle').textContent = t('heroTitle');
        document.getElementById('heroSub').textContent = t('heroSub');
        document.getElementById('heroTotalLabel').textContent = t('totalLabel');
        document.getElementById('eligibleCountChip').textContent = t('eligibleCount') + ': ' + state.items.length;
        document.getElementById('mintedOnlyChip').textContent = t('mintedOnly');
        document.getElementById('approvedLabel').textContent = t('approvedLabel');
        document.getElementById('lockedLabel').textContent = t('lockedLabel');
        document.getElementById('approvedNote').textContent = t('approvedNote');
        document.getElementById('lockedNote').textContent = t('lockedNote');
        document.getElementById('statusValue').textContent = state.appStatus === 'submitted' ? t('submitted') : t('draft');
        document.getElementById('statusBadge').textContent = state.appStatus === 'submitted' ? t('submitted') : t('draft');
        document.getElementById('statusNote').textContent = state.appStatus === 'submitted' ? 'Application submitted and waiting for review.' : t('waitingSubmit');

        document.getElementById('step1Title').textContent = t('step1');
        document.getElementById('step1Cap').textContent = t('step1Cap');
        document.getElementById('step2Title').textContent = t('step2');
        document.getElementById('step2Cap').textContent = t('step2Cap');
        document.getElementById('step3Title').textContent = t('step3');
        document.getElementById('step3Cap').textContent = t('step3Cap');
        document.getElementById('step4Title').textContent = t('step4');
        document.getElementById('step4Cap').textContent = t('step4Cap');
        document.getElementById('step5Title').textContent = t('step5');
        document.getElementById('step5Cap').textContent = t('step5Cap');

        document.getElementById('eligibleTitle').textContent = t('step1');
        document.getElementById('eligibleSub').textContent = t('step1Cap');
        document.getElementById('applyTitle').textContent = t('step2');
        document.getElementById('applySub').textContent = t('step2Cap');
        document.getElementById('uploadTitle').textContent = t('step3');
        document.getElementById('uploadSub').textContent = t('step3Cap');
        document.getElementById('summaryTitle').textContent = 'Summary';
        document.getElementById('readyTitle').textContent = 'Readiness';
        document.getElementById('submitTitle').textContent = t('step4');
        document.getElementById('submitSub').textContent = t('step4Cap');
        document.getElementById('approvalTitle').textContent = t('step5');
        document.getElementById('approvalSub').textContent = t('step5Cap');

        document.getElementById('personalTypeTitle').textContent = t('typePersonal');
        document.getElementById('personalTypeSub').textContent = t('typePersonalSub');
        document.getElementById('businessTypeTitle').textContent = t('typeBusiness');
        document.getElementById('businessTypeSub').textContent = t('typeBusinessSub');

        document.getElementById('stvChecklistNote').textContent = t('checklistNote');
        document.getElementById('sumNftLabel').textContent = t('sumSelectedNfts');
        document.getElementById('sumTotalLabel').textContent = t('sumTotal');
        document.getElementById('sumApprovedLabel').textContent = t('sumApproved');
        document.getElementById('sumLockedLabel').textContent = t('sumLocked');
        document.getElementById('sumTypeLabel').textContent = t('sumType');
        document.getElementById('sumSubtypeLabel').textContent = t('sumSubtype');
        document.getElementById('sumDocsLabel').textContent = t('sumDocs');

        document.getElementById('readyNftLabel').textContent = t('readyNft');
        document.getElementById('readyTypeLabel').textContent = t('readyType');
        document.getElementById('readyDocsLabel').textContent = t('readyDocs');
        document.getElementById('readySubmitLabel').textContent = t('readySubmit');

        document.getElementById('appStateValue').textContent = state.appStatus === 'submitted' ? t('submitted') : t('draft');
        document.getElementById('appStateNoteValue').textContent = state.appStatus === 'submitted' ? 'Application submitted and waiting for review.' : t('waitingSubmit');

        btnPrecheck.textContent = t('precheckBtn');
        btnSubmit.textContent = t('submitBtn');

        renderSubtypeRow();
        renderChecklist();
        renderGrid();
        updateDashboard();
      }

      async function loadAll() {
        setHeroLoading(true);
        setStatus(t('loading'));
        try {
          var summary = await fetchJson(boot.endpoints.summary);
          fillSummary(summary);

          var eligible = await fetchJson(boot.endpoints.eligible);
          fillEligible(eligible);
        } catch (e) {
          setStatus(t('loadFailed') + ': ' + String(e.message || e));
          eligibleGrid.innerHTML = '<div class="empty">' + esc(t('loadFailed')) + ': ' + esc(String(e.message || e)) + '</div>';
        } finally {
          setHeroLoading(false);
        }
      }

      document.querySelectorAll('.lang-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
          setLang(btn.getAttribute('data-lang') || 'en');
        });
      });

      searchEl.addEventListener('input', function () {
        renderGrid();
      });

      btnReloadStv.addEventListener('click', function () {
        loadAll();
      });

      btnTypePersonal.addEventListener('click', function () {
        setType('personal');
      });

      btnTypeBusiness.addEventListener('click', function () {
        setType('business');
      });

      btnPrecheck.addEventListener('click', runPrecheck);
      btnSubmit.addEventListener('click', submitApplication);

      setLang(state.lang);
      loadAll();
    })();
  </script>
</body>
</html>
