<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/avatar.php';

/**
 * v6.1.1 image-mode fix
 * This lets the same profile page path serve a real SVG image for avatar refresh.
 * JS can safely use:
 *   /rwa/profile/?_avatar_wallet=...&_avatar_nickname=...
 * as <img src>, and this block will return image/svg+xml instead of HTML.
 */
if (isset($_GET['_avatar_wallet']) || isset($_GET['_avatar_nickname'])) {
    $wallet = trim((string)($_GET['_avatar_wallet'] ?? ''));
    $nickname = trim((string)($_GET['_avatar_nickname'] ?? ''));

    header('Content-Type: image/svg+xml; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
    header('X-Content-Type-Options: nosniff');

    if ($wallet !== '') {
        echo rwa_ton_identicon_svg($wallet);
    } else {
        echo rwa_avatar_placeholder_svg($nickname !== '' ? $nickname : 'U');
    }
    exit;
}

if (!function_exists('session_user_id') || (int) session_user_id() <= 0) {
    header('Location: /rwa/?m=login_required');
    exit;
}

$userId = (int) session_user_id();
$sessionUser = function_exists('session_user') && is_array(session_user()) ? session_user() : [];
$sessionWallet = (string)($sessionUser['wallet_address'] ?? $sessionUser['wallet'] ?? '');
$sessionNickname = (string)($sessionUser['nickname'] ?? '');
$firstPaintAvatar = rwa_avatar_src($sessionWallet, $sessionNickname);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>RWA Profile</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#0b0613">
  <link rel="stylesheet" href="/rwa/assets/css/rwa-design-system.css?v=20260316-final-profile-v6.1.1">
  <style>
    :root{
      --line:rgba(173,112,255,.26);
      --text:#f5ecff;
      --muted:#bca8df;
      --soft:#8c77af;
      --ok:#22c55e;
      --warn:#f59e0b;
      --bad:#ef4444;
      --gold:#f5d56b;
      --shadow:0 0 0 1px rgba(176,108,255,.10), 0 10px 32px rgba(0,0,0,.34), 0 0 32px rgba(124,77,255,.10);
      --radius:18px;
      --field-h:52px;
    }
    *{box-sizing:border-box}
    html,body{
      margin:0;padding:0;
      background:
        radial-gradient(circle at top, rgba(124,77,255,.10), transparent 28%),
        linear-gradient(180deg, #09050f 0%, #0b0613 45%, #0a0613 100%);
      color:var(--text);
      font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
      min-height:100%;
    }
    body{padding-bottom:108px}
    .page{width:min(1180px, calc(100% - 24px));margin:16px auto 0}
    .langbar{
      display:flex;justify-content:flex-end;align-items:center;gap:8px;
      margin:0 0 12px;
    }
    .langbtn{
      min-height:34px;padding:0 12px;border-radius:10px;
      border:1px solid rgba(173,112,255,.22);
      background:#161021;color:#fff;cursor:pointer;font-size:12px;
    }
    .langbtn.active{
      background:linear-gradient(180deg, #b06cff, #7d4dff);
      border-color:transparent;
    }
    .hero{
      display:flex;align-items:center;justify-content:space-between;gap:12px;
      margin:14px 0 16px;padding:16px 18px;
      border:1px solid var(--line);border-radius:20px;
      background:linear-gradient(180deg, rgba(24,17,38,.94), rgba(13,9,21,.96));
      box-shadow:var(--shadow);
    }
    .hero h1{margin:0;font-size:18px;color:#fff}
    .hero p{margin:5px 0 0;color:var(--muted);font-size:12px}
    .hero-ledger{text-align:right;min-width:132px}
    .hero-ledger .k{font-size:10px;color:var(--soft);text-transform:uppercase;letter-spacing:.14em}
    .hero-ledger .v{font-size:12px;color:var(--gold);margin-top:5px}
    .grid{
      display:grid;
      grid-template-columns:1.25fr .9fr;
      gap:16px;
      align-items:stretch;
    }
    .card{
      border:1px solid var(--line);
      border-radius:var(--radius);
      background:linear-gradient(180deg, rgba(18,13,31,.96), rgba(12,8,20,.97));
      box-shadow:var(--shadow);
      overflow:hidden;
      height:100%;
      display:flex;
      flex-direction:column;
    }
    .card-head{
      display:flex;align-items:center;justify-content:space-between;gap:10px;
      padding:14px 16px;border-bottom:1px solid rgba(173,112,255,.14);
      background:linear-gradient(180deg, rgba(255,255,255,.02), rgba(255,255,255,0));
    }
    .card-title{font-size:13px;color:#fff;letter-spacing:.08em;text-transform:uppercase}
    .card-sub{font-size:11px;color:var(--soft);margin-top:4px}
    .card-body{padding:16px;flex:1 1 auto;display:flex;flex-direction:column}
    .stack{display:grid;gap:12px}
    .full-row{display:grid;grid-template-columns:1fr;gap:12px}
    .row-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .row-3{
      display:grid;
      grid-template-columns:92px 160px 1fr;
      gap:10px;
      align-items:center;
    }
    .field{display:grid;gap:7px}
    .field.is-locked .label::after{
      content:" LOCKED";
      color:var(--gold);
      font-size:10px;
      letter-spacing:.08em;
    }
    html[lang="zh"] .field.is-locked .label::after{content:" 已锁定"}
    .label{font-size:11px;color:var(--muted);letter-spacing:.06em;text-transform:uppercase}
    input,select,button{font:inherit}
    .input,.select{
      width:100%;
      min-height:var(--field-h);
      border-radius:14px;
      border:1px solid rgba(173,112,255,.20);
      background:#0d0915;
      color:#fff;
      outline:none;
      transition:border-color .16s ease, box-shadow .16s ease, opacity .16s ease;
    }
    .input{padding:0 14px}
    .select{
      padding:0 34px 0 14px;
      appearance:none;-webkit-appearance:none;
      background-image:
        linear-gradient(45deg, transparent 50%, #c9b7ff 50%),
        linear-gradient(135deg, #c9b7ff 50%, transparent 50%);
      background-position:calc(100% - 18px) calc(50% - 3px), calc(100% - 12px) calc(50% - 3px);
      background-size:6px 6px, 6px 6px;
      background-repeat:no-repeat;
    }
    .input[readonly], .input[disabled], .select[disabled]{
      opacity:.82;cursor:not-allowed;background:#0b0812;color:#dacdf0;
    }
    .flag-box{
      min-height:var(--field-h);
      border-radius:14px;border:1px solid rgba(173,112,255,.20);background:#0d0915;
      display:flex;align-items:center;justify-content:center;gap:8px;padding:0 10px;
    }
    .flag-box img,.chip-flag img{
      width:22px;height:16px;object-fit:cover;border-radius:3px;border:1px solid rgba(255,255,255,.12);
      background:#111;
    }
    .flag-empty{color:var(--soft);font-size:11px;letter-spacing:.06em;text-transform:uppercase}
    .chip-flag{
      display:none;align-items:center;gap:8px;min-height:30px;padding:0 10px;
      border:1px solid rgba(173,112,255,.18);border-radius:999px;background:#0d0915;
    }
    .actions{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .btn{
      min-height:52px;border:none;border-radius:14px;padding:0 14px;cursor:pointer;color:#fff;font-weight:700;
      letter-spacing:.04em;transition:transform .16s ease, opacity .16s ease;
    }
    .btn:hover{transform:translateY(-1px)}
    .btn:disabled{opacity:.55;cursor:not-allowed;transform:none}
    .btn-primary{background:linear-gradient(180deg, #b06cff, #7d4dff)}
    .btn-secondary{background:linear-gradient(180deg, #302044, #21152f);border:1px solid rgba(173,112,255,.22)}
    .btn-warn{background:linear-gradient(180deg, #624321, #3a2511);border:1px solid rgba(245,213,107,.24);color:#ffeaa3}
    .btn-ok{background:linear-gradient(180deg, #19693a, #12502d)}
    .tiny-btn{
      min-height:34px;padding:0 12px;border-radius:12px;border:1px solid rgba(173,112,255,.20);
      background:#161021;color:#fff;cursor:pointer;font-size:11px;
    }
    .badge{
      display:inline-flex;align-items:center;gap:8px;min-height:28px;padding:0 10px;border-radius:999px;font-size:11px;
      letter-spacing:.04em;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.03);color:#e9defd;
    }
    .dot{width:8px;height:8px;border-radius:50%;box-shadow:0 0 10px currentColor}
    .status-box{
      border:1px solid rgba(173,112,255,.18);background:linear-gradient(180deg, rgba(255,255,255,.02), rgba(255,255,255,.01));
      border-radius:16px;padding:14px;min-height:84px;
    }
    .status-box .title{font-size:11px;color:var(--soft);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px}
    .status-box .body{font-size:13px;color:#fff;line-height:1.5;word-break:break-word}
    .lock-banner{
      display:flex;align-items:flex-start;justify-content:space-between;gap:10px;
      padding:12px 13px;border:1px solid rgba(245,213,107,.18);border-radius:14px;
      background:linear-gradient(180deg, rgba(245,213,107,.06), rgba(245,213,107,.02));
    }
    .lock-banner .k{font-size:11px;color:var(--gold);letter-spacing:.08em;text-transform:uppercase;margin-bottom:6px}
    .lock-banner .v{font-size:12px;color:#f3ebff;line-height:1.5}
    .mini-list{display:grid;gap:10px}
    .mini-item{border:1px solid rgba(173,112,255,.14);border-radius:14px;background:#0e0a16;padding:12px 13px}
    .mini-item .k{font-size:10px;color:var(--soft);text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px}
    .mini-item .v{font-size:13px;color:#fff;word-break:break-word}
    .nick-helper{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap}
    .nick-msg{font-size:11px;line-height:1.45}
    .nick-msg.ok{color:var(--ok)}
    .nick-msg.warn{color:var(--warn)}
    .nick-msg.bad{color:var(--bad)}
    .hint{font-size:11px;color:var(--soft);line-height:1.45}
    .sep{height:1px;background:rgba(173,112,255,.12);margin:14px 0}
    .hidden{display:none !important}
    .saving{opacity:.76;pointer-events:none}
    .profile-shell{
      display:grid;
      grid-template-columns:88px 1fr;
      gap:14px;
      align-items:center;
      margin-bottom:4px;
    }
    .avatar-wrap{
      width:88px;height:88px;border-radius:24px;overflow:hidden;
      border:1px solid rgba(173,112,255,.22);
      box-shadow:0 0 0 1px rgba(176,108,255,.08), 0 8px 24px rgba(0,0,0,.28);
      background:#f7f3ff;
      display:flex;align-items:center;justify-content:center;
    }
    .avatar-wrap img{width:100%;height:100%;object-fit:cover;display:block;background:#f7f3ff}
    .avatar-meta{display:grid;gap:8px}
    .avatar-title{font-size:13px;color:#fff;letter-spacing:.08em;text-transform:uppercase}
    .avatar-sub{font-size:12px;color:var(--muted);line-height:1.5}
    .qr-block{
      margin-top:14px;
      border:1px solid rgba(173,112,255,.18);
      border-radius:16px;
      background:linear-gradient(180deg, rgba(255,255,255,.02), rgba(255,255,255,.01));
      padding:14px;
    }
    .qr-title{
      font-size:11px;color:var(--soft);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px
    }
    .qr-shell{
      display:grid;
      grid-template-columns:132px 1fr;
      gap:14px;
      align-items:start;
    }
    .qr-box{
      width:132px;height:132px;border-radius:14px;
      border:1px solid rgba(173,112,255,.18);
      background:#fff;
      display:flex;align-items:center;justify-content:center;
      padding:8px;
      overflow:hidden;
    }
    .qr-box img{
      max-width:100%;max-height:100%;display:block;border-radius:8px;background:#fff;
    }
    .qr-empty{
      width:100%;height:100%;
      display:flex;align-items:center;justify-content:center;
      background:#0d0915;color:var(--soft);font-size:11px;text-align:center;
      border-radius:10px;border:1px dashed rgba(173,112,255,.16);
      padding:8px;
    }
    .qr-meta{display:grid;gap:10px}
    .qr-address{
      min-height:44px;
      border:1px solid rgba(173,112,255,.16);
      border-radius:12px;
      background:#0d0915;
      padding:10px 12px;
      color:#fff;font-size:12px;line-height:1.5;word-break:break-word;
    }
    .copy-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .copy-status{font-size:11px;line-height:1.45;color:var(--soft)}
    .copy-status.ok{color:var(--ok)}
    .copy-status.bad{color:var(--bad)}
    @media (max-width:980px){
      .grid{grid-template-columns:1fr}
      .card{height:auto}
    }
    @media (max-width:640px){
      .page{width:min(100% - 16px, 100%)}
      .row-2,.actions{grid-template-columns:1fr}
      .row-3{grid-template-columns:74px 132px 1fr}
      .profile-shell{grid-template-columns:72px 1fr}
      .avatar-wrap{width:72px;height:72px;border-radius:20px}
      .qr-shell{grid-template-columns:1fr}
      .qr-box{width:100%;height:auto;aspect-ratio:1/1}
    }
  </style>
</head>
<body>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php'; ?>

<main class="page">
  <div class="langbar">
    <button type="button" class="langbtn active" data-lang="en">EN</button>
    <button type="button" class="langbtn" data-lang="zh">中文</button>
  </div>

  <section class="hero">
    <div>
      <h1 id="pageTitle"></h1>
      <p id="pageSub"></p>
    </div>
    <div class="hero-ledger">
      <div class="k" id="userIdLabel"></div>
      <div class="v">#<?php echo (int)$userId; ?></div>
    </div>
  </section>

  <section class="grid">
    <div class="card">
      <div class="card-head">
        <div>
          <div class="card-title" id="profileCardTitle"></div>
          <div class="card-sub" id="profileCardSub"></div>
        </div>
        <span id="emailBadge" class="badge"><span class="dot" style="color:var(--warn)"></span><span></span></span>
      </div>

      <div class="card-body">
        <div class="lock-banner">
          <div>
            <div class="k" id="lockTitle"></div>
            <div class="v" id="lockBannerText"></div>
          </div>
          <span id="profileModeBadge" class="badge"><span class="dot" style="color:var(--warn)"></span><span>LOCKED</span></span>
        </div>

        <div class="profile-shell" style="margin-top:14px">
          <div class="avatar-wrap">
            <img id="profileAvatar" src="<?php echo htmlspecialchars($firstPaintAvatar, ENT_QUOTES, 'UTF-8'); ?>" alt="avatar">
          </div>
          <div class="avatar-meta">
            <div class="avatar-title" id="avatarTitle"></div>
            <div class="avatar-sub" id="avatarSub"></div>
          </div>
        </div>

        <form id="profileForm" class="stack" style="margin-top:12px" novalidate>
          <div class="full-row">
            <div class="field is-locked" id="nicknameFieldWrap">
              <label class="label" for="nickname" id="nicknameLabel"></label>
              <input id="nickname" name="nickname" type="text" class="input" maxlength="80" readonly>
              <div class="nick-helper">
                <div id="nicknameMsg" class="nick-msg warn"></div>
                <button id="suggestNickBtn" type="button" class="tiny-btn"></button>
              </div>
            </div>
          </div>

          <div class="full-row">
            <div class="field is-locked" id="emailFieldWrap">
              <label class="label" for="email" id="emailLabel"></label>
              <input id="email" name="email" type="email" class="input" maxlength="190" readonly>
            </div>
          </div>

          <div class="field is-locked" id="mobileFieldWrap">
            <label class="label" id="mobileLabel"></label>
            <div class="row-3">
              <div id="mobileFlagBox" class="flag-box"><span class="flag-empty" id="flagEmptyText"></span></div>
              <select id="prefixIso2" class="select" disabled></select>
              <input id="mobile" name="mobile" type="tel" inputmode="numeric" pattern="[0-9]*" maxlength="15" class="input" readonly>
            </div>
            <div class="hint" id="mobileHint"></div>
          </div>

          <div class="field is-locked" id="countryFieldWrap">
            <label class="label" id="countryLabel"></label>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
              <select id="countryIso2" class="select" style="flex:1 1 auto" disabled></select>
              <span id="countryChip" class="chip-flag">
                <img id="countryFlagImg" src="" alt="flag">
                <span id="countryFlagTxt"></span>
              </span>
            </div>
          </div>

          <div class="row-2">
            <div class="field is-locked" id="stateFieldWrap">
              <label class="label" id="stateLabel"></label>
              <select id="stateId" class="select" disabled></select>
            </div>
            <div class="field is-locked" id="areaFieldWrap">
              <label class="label" id="areaLabel"></label>
              <select id="areaId" class="select" disabled></select>
            </div>
          </div>

          <div id="profileActionNormal" class="actions">
            <button id="changeProfileBtn" type="button" class="btn btn-primary"></button>
            <button id="verifyBtn" type="button" class="btn btn-secondary"></button>
          </div>

          <div id="profileActionEdit" class="actions hidden">
            <button id="submitChangesBtn" type="submit" class="btn btn-ok"></button>
            <button id="cancelEditBtn" type="button" class="btn btn-secondary"></button>
          </div>

          <div class="status-box">
            <div class="title" id="profileStatusTitle"></div>
            <div class="body" id="profileStatusText"></div>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <div>
          <div class="card-title" id="tonCardTitle"></div>
          <div class="card-sub" id="tonCardSub"></div>
        </div>
        <span id="tonBadge" class="badge"><span class="dot" style="color:var(--warn)"></span><span></span></span>
      </div>

      <div class="card-body">
        <div class="mini-list">
          <div class="mini-item">
            <div class="k" id="tonCurrentLabel"></div>
            <div class="v" id="tonAddressText">—</div>
          </div>
          <div class="mini-item">
            <div class="k" id="proofStateLabel"></div>
            <div class="v" id="proofStateText"></div>
          </div>
          <div class="mini-item">
            <div class="k" id="tonValidationLabel"></div>
            <div class="v" id="tonValidationText"></div>
          </div>
        </div>

        <div class="sep"></div>

        <div id="profileTonConnectRoot" class="hidden"></div>

        <div class="actions">
          <button id="bindTonBtn" type="button" class="btn btn-primary"></button>
          <button id="resetTonBtn" type="button" class="btn btn-warn"></button>
        </div>

        <div class="status-box" style="margin-top:14px">
          <div class="title" id="tonStatusTitle"></div>
          <div class="body" id="tonStatusText"></div>
        </div>

        <div class="qr-block">
          <div class="qr-title" id="qrTitle"></div>
          <div class="qr-shell">
            <div id="tonQrBox" class="qr-box">
              <div id="tonQrEmpty" class="qr-empty"></div>
              <img id="tonQrImg" class="hidden" alt="TON QR">
            </div>
            <div class="qr-meta">
              <div id="tonQrAddress" class="qr-address">—</div>
              <div class="copy-row">
                <button id="copyTonBtn" type="button" class="tiny-btn"></button>
                <span id="copyTonStatus" class="copy-status"></span>
              </div>
            </div>
          </div>
        </div>

        <div class="hint" style="margin-top:14px" id="tonHint"></div>
      </div>
    </div>
  </section>
</main>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php'; ?>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/gt-inline.php'; ?>
<script src="/rwa/inc/core/poado-i18n.js"></script>
<script src="/rwa/assets/js/proof.js?v=20260316-v1"></script>

<script>
(() => {
  'use strict';

  const FLAG_BASE = '/rwa/assets/flags/';
  const API = {
    profileLoad: '/rwa/api/profile/load.php',
    profileSave: '/rwa/api/profile/save.php',
    sendVerifyEmail: '/rwa/api/profile/send-verify-email.php',
    checkNickname: '/rwa/api/profile/check-nickname.php',
    countries: '/rwa/api/geo/countries.php',
    prefixes: '/rwa/api/geo/prefixes.php',
    states: '/rwa/api/geo/states.php',
    areas: '/rwa/api/geo/areas.php',
    tonReset: '/rwa/auth/ton/reset.php',
    tonNonce: '/rwa/auth/ton/nonce.php',
    tonBind: '/rwa/api/profile/bind-ton.php'
  };

  const SESSION = {
    uid: <?php echo (int)$userId; ?>,
    wallet: <?php echo json_encode($sessionWallet, JSON_UNESCAPED_SLASHES); ?>,
    nickname: <?php echo json_encode($sessionNickname, JSON_UNESCAPED_SLASHES); ?>
  };

  const SERVER = {
    firstPaintAvatar: <?php echo json_encode($firstPaintAvatar, JSON_UNESCAPED_SLASHES); ?>
  };

  const I18N = {
    en: {
      pageTitle: 'Profile',
      pageSub: 'Manage profile, verify changes by email, and bind TON wallet.',
      userIdLabel: 'User ID',
      profileCardTitle: 'Profile',
      profileCardSub: 'Locked by default. Press Change Profile to edit.',
      emailPending: 'Email Pending',
      emailVerified: 'Verified',
      lockTitle: 'Profile Locked',
      lockTextLocked: 'Tap Change Profile to edit. Any profile change requires email verification before activation.',
      lockTextEdit: 'Edit fields, then submit. Changes remain pending until email verification succeeds.',
      modeLocked: 'LOCKED',
      modeEdit: 'EDIT MODE',
      nicknameLabel: 'Nickname',
      emailLabel: 'Email',
      mobileLabel: 'Mobile',
      countryLabel: 'Country',
      stateLabel: 'State / Province',
      areaLabel: 'Area / Region',
      avatarTitle: 'Profile Avatar',
      avatarTon: 'Default avatar uses TON identicon when wallet is bound.',
      avatarPlaceholder: 'No TON wallet yet. Placeholder avatar active.',
      flag: 'Flag',
      mobileHint: 'Digits only, max 15.',
      suggestNickname: 'Suggest Nickname',
      nicknamePending: 'Nickname check pending.',
      nicknameRequired: 'Nickname is required.',
      nicknameHelperDown: 'Nickname helper unavailable. Profile can still load.',
      nicknameAvailable: 'Nickname available.',
      nicknameUsed: 'Nickname already used. Please choose another.',
      changeProfile: 'Change Profile',
      verifyCurrentEmail: 'Verify Current Email',
      submitChanges: 'Submit Changes',
      cancel: 'Cancel',
      profileStatusTitle: 'Profile Status',
      loadingProfile: 'Loading profile...',
      profileLoaded: 'Profile loaded.',
      editEnabled: 'Edit mode enabled. Submit changes to start email verification.',
      editCancelled: 'Edit cancelled.',
      verifyMailSent: 'Verification email sent.',
      tonCardTitle: 'TON Bind',
      tonCardSub: 'Separate proof-based wallet bind.',
      tonBound: 'Bound',
      tonNotBound: 'Not Bound',
      tonCurrentLabel: 'Current Bound TON',
      proofStateLabel: 'Proof Session State',
      tonValidationLabel: 'TON Validation',
      tonStatusTitle: 'TON Bind Status',
      tonReady: 'Ready.',
      tonIdle: 'Idle',
      tonValidationPending: 'Address + proof check pending.',
      tonValidationOk: 'Bound TON address present.',
      tonValidationMissing: 'No TON wallet bound yet.',
      bindTon: 'Bind / Rebind TON',
      resetTon: 'Reset TON',
      tonHint: 'Mobile wallet approval may complete first. This page finalizes by status change, focus, visibility return, and polling fallback.',
      selectPrefix: 'Select prefix',
      selectCountry: 'Select country',
      selectCountryFirst: 'Select country first',
      selectState: 'Select state',
      selectStateFirst: 'Select state first',
      selectArea: 'Select area',
      loading: 'Loading...',
      tonPreparingFresh: 'Preparing fresh TON bind session...',
      tonWaitingWallet: 'Waiting approval of wallet...',
      tonWaitingProof: 'Waiting wallet proof...',
      tonSubmittingProof: 'Submitting proof...',
      tonResetFailed: 'TON reset failed.',
      tonSavedOk: 'TON wallet saved successfully.',
      tonBindFailed: 'TON bind failed.',
      tonIdleText: 'Idle',
      qrTitle: 'Current Bound TON QR',
      qrEmpty: 'No bound TON address yet.',
      copyAddress: 'Copy Address',
      copied: 'Address copied.',
      copyFailed: 'Copy failed.'
    },
    zh: {
      pageTitle: '资料',
      pageSub: '管理资料、通过邮箱确认修改，并绑定 TON 钱包。',
      userIdLabel: '用户编号',
      profileCardTitle: '资料',
      profileCardSub: '默认锁定，按 Change Profile 才可编辑。',
      emailPending: '邮箱未验证',
      emailVerified: '已验证',
      lockTitle: '资料锁定',
      lockTextLocked: '按 Change Profile 后才能编辑，所有资料修改都需邮箱确认后才生效。',
      lockTextEdit: '编辑后提交，变更会等待邮箱确认成功后才生效。',
      modeLocked: '已锁定',
      modeEdit: '编辑中',
      nicknameLabel: '昵称',
      emailLabel: '邮箱',
      mobileLabel: '手机',
      countryLabel: '国家',
      stateLabel: '州 / 省',
      areaLabel: '地区',
      avatarTitle: '头像',
      avatarTon: '绑定 TON 钱包后默认使用 TON identicon 头像。',
      avatarPlaceholder: '尚未绑定 TON 钱包，当前使用占位头像。',
      flag: '旗帜',
      mobileHint: '只限数字，最多 15 位。',
      suggestNickname: '建议昵称',
      nicknamePending: '昵称检查等待中。',
      nicknameRequired: '请输入昵称。',
      nicknameHelperDown: '昵称助手不可用，但资料仍可继续加载。',
      nicknameAvailable: '昵称可用。',
      nicknameUsed: '昵称已被使用，请更换。',
      changeProfile: '修改资料',
      verifyCurrentEmail: '验证当前邮箱',
      submitChanges: '提交修改',
      cancel: '取消',
      profileStatusTitle: '资料状态',
      loadingProfile: '正在加载资料...',
      profileLoaded: '资料已加载。',
      editEnabled: '已进入编辑模式，提交后将启动邮箱确认流程。',
      editCancelled: '已取消编辑。',
      verifyMailSent: '验证邮件已发送。',
      tonCardTitle: 'TON 绑定',
      tonCardSub: '独立的钱包证明绑定流程。',
      tonBound: '已绑定',
      tonNotBound: '未绑定',
      tonCurrentLabel: '当前绑定 TON',
      proofStateLabel: '证明状态',
      tonValidationLabel: 'TON 校验',
      tonStatusTitle: 'TON 绑定状态',
      tonReady: '已准备。',
      tonIdle: '空闲',
      tonValidationPending: '地址与证明等待校验。',
      tonValidationOk: '已存在绑定 TON 地址。',
      tonValidationMissing: '还未绑定 TON 钱包。',
      bindTon: '绑定 / 重绑 TON',
      resetTon: '重置 TON',
      tonHint: '手机钱包可能先批准，本页会通过状态变化、焦点返回、页面可见与轮询补偿完成绑定。',
      selectPrefix: '选择区号',
      selectCountry: '选择国家',
      selectCountryFirst: '先选择国家',
      selectState: '选择州/省',
      selectStateFirst: '先选择州/省',
      selectArea: '选择地区',
      loading: '加载中...',
      tonPreparingFresh: '正在准备全新的 TON 绑定会话...',
      tonWaitingWallet: '等待钱包批准...',
      tonWaitingProof: '等待钱包证明...',
      tonSubmittingProof: '正在提交证明...',
      tonResetFailed: 'TON 重置失败。',
      tonSavedOk: 'TON 钱包已成功保存。',
      tonBindFailed: 'TON 绑定失败。',
      tonIdleText: '空闲',
      qrTitle: '当前绑定 TON 二维码',
      qrEmpty: '还没有绑定 TON 地址。',
      copyAddress: '复制地址',
      copied: '地址已复制。',
      copyFailed: '复制失败。'
    }
  };

  let activeLang = localStorage.getItem('rwa_lang') || 'en';

  const el = {
    pageTitle: document.getElementById('pageTitle'),
    pageSub: document.getElementById('pageSub'),
    userIdLabel: document.getElementById('userIdLabel'),
    profileCardTitle: document.getElementById('profileCardTitle'),
    profileCardSub: document.getElementById('profileCardSub'),
    emailBadge: document.getElementById('emailBadge'),
    lockTitle: document.getElementById('lockTitle'),
    lockBannerText: document.getElementById('lockBannerText'),
    profileModeBadge: document.getElementById('profileModeBadge'),
    nicknameLabel: document.getElementById('nicknameLabel'),
    emailLabel: document.getElementById('emailLabel'),
    mobileLabel: document.getElementById('mobileLabel'),
    countryLabel: document.getElementById('countryLabel'),
    stateLabel: document.getElementById('stateLabel'),
    areaLabel: document.getElementById('areaLabel'),
    avatarTitle: document.getElementById('avatarTitle'),
    avatarSub: document.getElementById('avatarSub'),
    profileAvatar: document.getElementById('profileAvatar'),
    flagEmptyText: document.getElementById('flagEmptyText'),
    mobileHint: document.getElementById('mobileHint'),
    suggestNickBtn: document.getElementById('suggestNickBtn'),
    changeProfileBtn: document.getElementById('changeProfileBtn'),
    verifyBtn: document.getElementById('verifyBtn'),
    submitChangesBtn: document.getElementById('submitChangesBtn'),
    cancelEditBtn: document.getElementById('cancelEditBtn'),
    profileStatusTitle: document.getElementById('profileStatusTitle'),
    tonCardTitle: document.getElementById('tonCardTitle'),
    tonCardSub: document.getElementById('tonCardSub'),
    tonCurrentLabel: document.getElementById('tonCurrentLabel'),
    proofStateLabel: document.getElementById('proofStateLabel'),
    tonValidationLabel: document.getElementById('tonValidationLabel'),
    tonStatusTitle: document.getElementById('tonStatusTitle'),
    tonHint: document.getElementById('tonHint'),
    bindTonBtn: document.getElementById('bindTonBtn'),
    resetTonBtn: document.getElementById('resetTonBtn'),
    profileForm: document.getElementById('profileForm'),
    nickname: document.getElementById('nickname'),
    email: document.getElementById('email'),
    mobile: document.getElementById('mobile'),
    prefixIso2: document.getElementById('prefixIso2'),
    countryIso2: document.getElementById('countryIso2'),
    stateId: document.getElementById('stateId'),
    areaId: document.getElementById('areaId'),
    mobileFlagBox: document.getElementById('mobileFlagBox'),
    countryChip: document.getElementById('countryChip'),
    countryFlagImg: document.getElementById('countryFlagImg'),
    countryFlagTxt: document.getElementById('countryFlagTxt'),
    nicknameMsg: document.getElementById('nicknameMsg'),
    profileActionNormal: document.getElementById('profileActionNormal'),
    profileActionEdit: document.getElementById('profileActionEdit'),
    profileStatusText: document.getElementById('profileStatusText'),
    nicknameFieldWrap: document.getElementById('nicknameFieldWrap'),
    emailFieldWrap: document.getElementById('emailFieldWrap'),
    mobileFieldWrap: document.getElementById('mobileFieldWrap'),
    countryFieldWrap: document.getElementById('countryFieldWrap'),
    stateFieldWrap: document.getElementById('stateFieldWrap'),
    areaFieldWrap: document.getElementById('areaFieldWrap'),
    tonBadge: document.getElementById('tonBadge'),
    tonAddressText: document.getElementById('tonAddressText'),
    proofStateText: document.getElementById('proofStateText'),
    tonValidationText: document.getElementById('tonValidationText'),
    tonStatusText: document.getElementById('tonStatusText'),
    profileTonConnectRoot: document.getElementById('profileTonConnectRoot'),
    qrTitle: document.getElementById('qrTitle'),
    tonQrEmpty: document.getElementById('tonQrEmpty'),
    tonQrImg: document.getElementById('tonQrImg'),
    tonQrAddress: document.getElementById('tonQrAddress'),
    copyTonBtn: document.getElementById('copyTonBtn'),
    copyTonStatus: document.getElementById('copyTonStatus')
  };

  const state = {
    profile: null,
    countries: [],
    prefixes: [],
    states: [],
    areas: [],
    editMode: false,
    nicknameAvailable: true,
    nicknameTimer: null,
    draftKey: 'rwa_profile_draft_v6_1_1',
    tonProofClient: null,
    copyStatusTimer: null,
    lastAvatarKey: '',
    avatarRefreshSeq: 0
  };

  function t(key) {
    return (I18N[activeLang] && I18N[activeLang][key]) || I18N.en[key] || key;
  }

  function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, ch => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
    }[ch]));
  }

  async function fetchJson(url, options = {}) {
    const res = await fetch(url, {
      credentials: 'same-origin',
      cache: 'no-store',
      ...options,
      headers: {'Accept':'application/json', ...(options.headers || {})}
    });
    const text = await res.text();
    if (text.trim().startsWith('<')) throw new Error('Non-JSON response from ' + url);
    const json = JSON.parse(text);
    if (!res.ok || !json.ok) throw new Error(json.error || json.message || ('HTTP ' + res.status));
    return json;
  }

  function setProfileStatus(msg, type = 'info') {
    const color = type === 'ok' ? 'var(--ok)' : type === 'warn' ? 'var(--warn)' : type === 'bad' ? 'var(--bad)' : '#fff';
    el.profileStatusText.innerHTML = '<span style="color:' + color + '">' + escapeHtml(msg) + '</span>';
  }

  function setTonStatus(msg, type = 'info') {
    const color = type === 'ok' ? 'var(--ok)' : type === 'warn' ? 'var(--warn)' : type === 'bad' ? 'var(--bad)' : '#fff';
    el.tonStatusText.innerHTML = '<span style="color:' + color + '">' + escapeHtml(msg) + '</span>';
  }

  function setTonValidation(msg, type = 'info') {
    const color = type === 'ok' ? 'var(--ok)' : type === 'warn' ? 'var(--warn)' : type === 'bad' ? 'var(--bad)' : '#fff';
    el.tonValidationText.innerHTML = '<span style="color:' + color + '">' + escapeHtml(msg) + '</span>';
  }

  function setCopyStatus(msg = '', type = '') {
    clearTimeout(state.copyStatusTimer);
    el.copyTonStatus.className = 'copy-status' + (type ? ' ' + type : '');
    el.copyTonStatus.textContent = msg;
    if (msg) {
      state.copyStatusTimer = setTimeout(() => {
        el.copyTonStatus.className = 'copy-status';
        el.copyTonStatus.textContent = '';
      }, 1200);
    }
  }

  function normalizeIso2(v){ return String(v || '').trim().toUpperCase(); }
  function flagUrl(iso2){ return FLAG_BASE + normalizeIso2(iso2).toLowerCase() + '.png'; }
  function isCn(iso2){ return normalizeIso2(iso2) === 'CN'; }

  function pickName(obj, iso2) {
    if (!obj || typeof obj !== 'object') return '';
    if (isCn(iso2)) return String(obj.name_local || obj.label_local || obj.name_zh || obj.name_en || obj.name || '');
    return String(obj.name_en || obj.label_en || obj.name || obj.name_local || '');
  }

  function optionHtml(value, label, selected = false) {
    return '<option value="' + escapeHtml(value) + '"' + (selected ? ' selected' : '') + '>' + escapeHtml(label) + '</option>';
  }

  function renderMobileFlag(iso2) {
    const iso = normalizeIso2(iso2);
    if (!iso) {
      el.mobileFlagBox.innerHTML = '<span class="flag-empty">' + escapeHtml(t('flag')) + '</span>';
      return;
    }
    el.mobileFlagBox.innerHTML =
      '<img src="' + flagUrl(iso) + '" alt="' + iso + ' flag" onerror="this.style.display=\'none\'">' +
      '<span>' + escapeHtml(iso) + '</span>';
  }

  function renderCountryChip(iso2, label) {
    const iso = normalizeIso2(iso2);
    if (!iso) {
      el.countryChip.style.display = 'none';
      return;
    }
    el.countryChip.style.display = 'inline-flex';
    el.countryFlagImg.style.display = '';
    el.countryFlagImg.onerror = () => { el.countryFlagImg.style.display = 'none'; };
    el.countryFlagImg.src = flagUrl(iso);
    el.countryFlagTxt.textContent = label || iso;
  }

  function realQrUrl(text) {
    const v = String(text || '').trim();
    if (!v) return '';
    return 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=8&format=png&data=' + encodeURIComponent(v);
  }

  function resolveAvatarWallet(fallbackWallet = '') {
    return String(fallbackWallet || '').trim()
      || String(state.profile?.wallet_address || '').trim()
      || String(SESSION.wallet || '').trim();
  }

  function resolveAvatarNickname(fallbackNickname = '') {
    return String(fallbackNickname || '').trim()
      || String(el.nickname?.value || '').trim()
      || String(state.profile?.nickname || '').trim()
      || String(SESSION.nickname || '').trim();
  }

  function makeAvatarSrc(walletAddress, nickname) {
    const wallet = String(walletAddress || '').trim();
    const nick = String(nickname || '').trim();
    const path = window.location.pathname.endsWith('/') ? window.location.pathname : (window.location.pathname + (window.location.pathname.endsWith('/index.php') ? '' : '/'));
    const url = new URL(path, window.location.origin);
    url.searchParams.set('_avatar_wallet', wallet);
    url.searchParams.set('_avatar_nickname', nick);
    url.searchParams.set('_avatar_lang', activeLang);
    url.searchParams.set('_avatar_seq', String(Date.now()));
    return url.toString();
  }

  async function refreshAvatar(walletAddress = '', nickname = '', force = false) {
    const wallet = resolveAvatarWallet(walletAddress);
    const nick = resolveAvatarNickname(nickname);
    const key = wallet + '|' + nick;

    if (!force && key === state.lastAvatarKey) return;

    state.lastAvatarKey = key;
    const seq = ++state.avatarRefreshSeq;
    const src = makeAvatarSrc(wallet, nick);

    const probe = new Image();
    probe.decoding = 'sync';

    await new Promise((resolve) => {
      probe.onload = resolve;
      probe.onerror = resolve;
      probe.src = src;
    });

    if (seq !== state.avatarRefreshSeq) return;
    el.profileAvatar.src = src;
  }

  function renderAvatarMeta(hasWallet) {
    el.avatarSub.textContent = hasWallet ? t('avatarTon') : t('avatarPlaceholder');
  }

  function renderTonQr(walletAddress) {
    const addr = String(walletAddress || '').trim();
    el.qrTitle.textContent = t('qrTitle');
    el.copyTonBtn.textContent = t('copyAddress');

    if (!addr) {
      el.tonQrImg.classList.add('hidden');
      el.tonQrImg.removeAttribute('src');
      el.tonQrEmpty.classList.remove('hidden');
      el.tonQrEmpty.textContent = t('qrEmpty');
      el.tonQrAddress.textContent = '—';
      el.copyTonBtn.disabled = true;
      return;
    }

    el.tonQrImg.src = realQrUrl(addr);
    el.tonQrImg.classList.remove('hidden');
    el.tonQrEmpty.classList.add('hidden');
    el.tonQrAddress.textContent = addr;
    el.copyTonBtn.disabled = false;
  }

  function setReadonlyGroup(locked) {
    state.editMode = !locked;

    [el.nicknameFieldWrap, el.emailFieldWrap, el.mobileFieldWrap, el.countryFieldWrap, el.stateFieldWrap, el.areaFieldWrap]
      .forEach(w => w.classList.toggle('is-locked', locked));

    el.nickname.readOnly = locked;
    el.email.readOnly = locked;
    el.mobile.readOnly = locked;
    el.prefixIso2.disabled = locked;
    el.countryIso2.disabled = locked;
    el.stateId.disabled = locked;
    el.areaId.disabled = locked;

    el.profileActionNormal.classList.toggle('hidden', !locked);
    el.profileActionEdit.classList.toggle('hidden', locked);

    if (locked) {
      el.profileModeBadge.innerHTML = '<span class="dot" style="color:var(--warn)"></span><span>' + escapeHtml(t('modeLocked')) + '</span>';
      el.lockBannerText.textContent = t('lockTextLocked');
    } else {
      el.profileModeBadge.innerHTML = '<span class="dot" style="color:var(--ok)"></span><span>' + escapeHtml(t('modeEdit')) + '</span>';
      el.lockBannerText.textContent = t('lockTextEdit');
    }
  }

  function setEmailBadge(verified) {
    el.emailBadge.innerHTML = verified
      ? '<span class="dot" style="color:var(--ok)"></span><span>' + escapeHtml(t('emailVerified')) + '</span>'
      : '<span class="dot" style="color:var(--warn)"></span><span>' + escapeHtml(t('emailPending')) + '</span>';
    el.verifyBtn.disabled = !!verified;
    el.verifyBtn.textContent = verified ? t('emailVerified') : t('verifyCurrentEmail');
  }

  function setTonBadge(bound) {
    el.tonBadge.innerHTML = bound
      ? '<span class="dot" style="color:var(--ok)"></span><span>' + escapeHtml(t('tonBound')) + '</span>'
      : '<span class="dot" style="color:var(--warn)"></span><span>' + escapeHtml(t('tonNotBound')) + '</span>';
  }

  function setNicknameMessage(msg, type) {
    el.nicknameMsg.className = 'nick-msg ' + type;
    el.nicknameMsg.textContent = msg;
  }

  function saveDraft() {
    const draft = {
      nickname: el.nickname.value.trim(),
      email: el.email.value.trim(),
      mobile: el.mobile.value.replace(/\D+/g, '').slice(0, 15),
      prefix_iso2: normalizeIso2(el.prefixIso2.value),
      country_iso2: normalizeIso2(el.countryIso2.value),
      state_id: String(el.stateId.value || ''),
      area_id: String(el.areaId.value || '')
    };
    localStorage.setItem(state.draftKey, JSON.stringify(draft));
  }

  function clearDraft() {
    localStorage.removeItem(state.draftKey);
  }

  function restoreDraftIfAny() {
    try {
      const raw = localStorage.getItem(state.draftKey);
      if (!raw) return null;
      const draft = JSON.parse(raw);
      return draft && typeof draft === 'object' ? draft : null;
    } catch (_) {
      return null;
    }
  }

  async function loadCountries() {
    const json = await fetchJson(API.countries + '?lang=' + encodeURIComponent(activeLang));
    state.countries = Array.isArray(json.items) ? json.items : (Array.isArray(json.countries) ? json.countries : []);
  }

  async function loadPrefixes() {
    const json = await fetchJson(API.prefixes + '?lang=' + encodeURIComponent(activeLang));
    state.prefixes = Array.isArray(json.items) ? json.items : (Array.isArray(json.prefixes) ? json.prefixes : []);
  }

  function renderCountriesOptions(selectedIso2) {
    const chosen = normalizeIso2(selectedIso2);
    let html = optionHtml('', t('selectCountry'), !chosen);
    for (const item of state.countries) {
      const iso2 = normalizeIso2(item.iso2 || item.country_code || item.code);
      html += optionHtml(iso2, pickName(item, iso2) || iso2, iso2 === chosen);
    }
    el.countryIso2.innerHTML = html;
  }

  function renderPrefixOptions(selectedIso2) {
    const chosen = normalizeIso2(selectedIso2);
    let html = optionHtml('', t('selectPrefix'), !chosen);
    for (const item of state.prefixes) {
      const iso2 = normalizeIso2(item.iso2);
      const code = String(item.calling_code || item.prefix_label || '').replace(/^\+?/, '');
      const label = code ? ('+' + code) : ('+' + iso2);
      html += optionHtml(iso2, label, iso2 === chosen);
    }
    el.prefixIso2.innerHTML = html;
    renderMobileFlag(chosen);
  }

  async function loadStatesForCountry(countryIso2, selectedStateId = '') {
    const iso2 = normalizeIso2(countryIso2);

    state.states = [];
    state.areas = [];

    el.stateId.innerHTML = optionHtml('', iso2 ? t('loading') : t('selectCountryFirst'), true);
    el.areaId.innerHTML = optionHtml('', t('selectStateFirst'), true);

    if (!iso2) return;

    const json = await fetchJson(API.states + '?country_iso2=' + encodeURIComponent(iso2) + '&lang=' + encodeURIComponent(activeLang));
    const list = Array.isArray(json.items) ? json.items : (Array.isArray(json.states) ? json.states : []);
    state.states = list;

    const cn = iso2 === 'CN';
    let html = optionHtml('', t('selectState'), !selectedStateId);

    for (const s of list) {
      const sid = String(s.id ?? s.state_id ?? '');
      const label = cn
        ? String(s.name_local || s.name_en || sid)
        : String(s.name_en || s.name_local || sid);
      html += optionHtml(sid, label, sid === String(selectedStateId || ''));
    }

    el.stateId.innerHTML = html;
  }

  async function loadAreasForState(stateId, selectedAreaId = '') {
    const sid = String(stateId || '').trim();

    state.areas = [];
    el.areaId.innerHTML = optionHtml('', sid ? t('loading') : t('selectStateFirst'), true);

    if (!sid) return;

    const json = await fetchJson(API.areas + '?state_id=' + encodeURIComponent(sid) + '&lang=' + encodeURIComponent(activeLang));
    const list = Array.isArray(json.items) ? json.items : (Array.isArray(json.areas) ? json.areas : []);
    state.areas = list;

    const cn = normalizeIso2(el.countryIso2.value) === 'CN';
    let html = optionHtml('', t('selectArea'), !selectedAreaId);

    for (const a of list) {
      const aid = String(a.id ?? a.area_id ?? '');
      const label = cn
        ? String(a.name_local || a.name_en || aid)
        : String(a.name_en || a.name_local || aid);
      html += optionHtml(aid, label, aid === String(selectedAreaId || ''));
    }

    el.areaId.innerHTML = html;
  }

  function baseSuggestedNickname() {
    const wallet = String(state.profile?.wallet_address || SESSION.wallet || '').trim();
    if (wallet) return ('TON' + wallet.replace(/[^A-Za-z0-9]/g, '').slice(0, 10)).slice(0, 64);
    return ('USER' + String(SESSION.uid)).slice(0, 64);
  }

  async function checkNicknameAvailable(nickname, updateUi = true) {
    const value = String(nickname || '').trim();
    if (!value) {
      state.nicknameAvailable = false;
      if (updateUi) setNicknameMessage(t('nicknameRequired'), 'bad');
      toggleSubmitAvailability();
      return false;
    }

    try {
      const json = await fetchJson(API.checkNickname + '?nickname=' + encodeURIComponent(value) + '&lang=' + encodeURIComponent(activeLang));
      const available = !!(json.available ?? false);
      state.nicknameAvailable = available;

      if (updateUi) {
        setNicknameMessage(
          json.message || (available ? t('nicknameAvailable') : t('nicknameUsed')),
          available ? 'ok' : 'bad'
        );
      }
      toggleSubmitAvailability();
      return available;
    } catch (e) {
      state.nicknameAvailable = true;
      if (updateUi) setNicknameMessage(t('nicknameHelperDown'), 'warn');
      toggleSubmitAvailability();
      return true;
    }
  }

  async function suggestUniqueNickname() {
    const base = baseSuggestedNickname();
    let candidate = base;
    let i = 2;
    while (i < 100) {
      const ok = await checkNicknameAvailable(candidate, false);
      if (ok) return candidate;
      candidate = (base + i).slice(0, 64);
      i++;
    }
    return (base + Date.now().toString().slice(-4)).slice(0, 64);
  }

  function toggleSubmitAvailability() {
    el.submitChangesBtn.disabled = !(state.editMode && state.nicknameAvailable && !!el.nickname.value.trim());
  }

  async function applyProfile(user) {
    state.profile = user;
    el.nickname.value = String(user.nickname || '');
    el.email.value = String(user.email || '');
    el.mobile.value = String(user.mobile || '').replace(/\D+/g, '').slice(0, 15);
    el.tonAddressText.textContent = String(user.wallet_address || '') || '—';
    setEmailBadge(!!user.email_verified_at);
    setTonBadge(!!user.wallet_address);
    setTonValidation(user.wallet_address ? t('tonValidationOk') : t('tonValidationMissing'), user.wallet_address ? 'ok' : 'warn');
    await refreshAvatar(user.wallet_address || '', user.nickname || '', true);
    renderAvatarMeta(!!user.wallet_address);
    renderTonQr(user.wallet_address || '');
  }

  async function initProfileSequence() {
    setProfileStatus(t('loadingProfile'), 'warn');
    setReadonlyGroup(true);

    const json = await fetchJson(API.profileLoad + '?lang=' + encodeURIComponent(activeLang));
    if (!json.ok || !json.user) throw new Error(json.message || 'Profile load failed.');

    await loadCountries();
    await loadPrefixes();

    await applyProfile(json.user);

    renderPrefixOptions(json.user.prefix_iso2 || '');
    el.prefixIso2.value = normalizeIso2(json.user.prefix_iso2 || '');
    renderMobileFlag(el.prefixIso2.value);

    renderCountriesOptions(json.user.country_iso2 || '');
    el.countryIso2.value = normalizeIso2(json.user.country_iso2 || '');
    const selectedCountry = state.countries.find(c => normalizeIso2(c.iso2 || c.country_code || c.code) === el.countryIso2.value);
    renderCountryChip(el.countryIso2.value, selectedCountry ? pickName(selectedCountry, el.countryIso2.value) : '');

    await loadStatesForCountry(el.countryIso2.value, json.user.state_id || '');
    el.stateId.value = String(json.user.state_id || '');

    await loadAreasForState(el.stateId.value, json.user.area_id || '');
    el.areaId.value = String(json.user.area_id || '');

    if (!el.nickname.value.trim()) {
      el.nickname.value = await suggestUniqueNickname();
      setNicknameMessage(t('nicknameAvailable'), 'ok');
      await refreshAvatar(resolveAvatarWallet(), el.nickname.value, true);
    } else {
      setNicknameMessage(t('nicknamePending'), 'warn');
    }

    await checkNicknameAvailable(el.nickname.value, true);
    el.proofStateText.textContent = t('tonIdle');
    setTonStatus(t('tonReady'), 'ok');
    setProfileStatus(t('profileLoaded'), 'ok');
  }

  async function enterEditMode() {
    setReadonlyGroup(false);

    const draft = restoreDraftIfAny();
    if (draft) {
      el.nickname.value = String(draft.nickname || el.nickname.value || '');
      el.email.value = String(draft.email || el.email.value || '');
      el.mobile.value = String(draft.mobile || el.mobile.value || '').replace(/\D+/g, '').slice(0, 15);
      el.prefixIso2.value = normalizeIso2(draft.prefix_iso2 || el.prefixIso2.value || '');
      renderMobileFlag(el.prefixIso2.value);
      el.countryIso2.value = normalizeIso2(draft.country_iso2 || el.countryIso2.value || '');

      const selected = state.countries.find(c => normalizeIso2(c.iso2 || c.country_code || c.code) === normalizeIso2(el.countryIso2.value));
      renderCountryChip(el.countryIso2.value, selected ? pickName(selected, el.countryIso2.value) : el.countryIso2.value);

      await loadStatesForCountry(el.countryIso2.value, String(draft.state_id || ''));
      el.stateId.value = String(draft.state_id || '');
      await loadAreasForState(el.stateId.value, String(draft.area_id || ''));
      el.areaId.value = String(draft.area_id || '');
    }

    if (!el.nickname.value.trim()) {
      el.nickname.value = await suggestUniqueNickname();
    }

    await refreshAvatar(resolveAvatarWallet(), el.nickname.value, true);
    await checkNicknameAvailable(el.nickname.value, true);
    saveDraft();
    setProfileStatus(t('editEnabled'), 'warn');
  }

  function cancelEditMode() {
    setReadonlyGroup(true);
    if (state.profile) initProfileSequence().catch(err => setProfileStatus(err.message, 'bad'));
    setProfileStatus(t('editCancelled'), 'warn');
  }

  async function sendVerifyEmail() {
    el.verifyBtn.disabled = true;
    try {
      const json = await fetchJson(API.sendVerifyEmail, {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({lang: activeLang})
      });
      setProfileStatus(json.message || t('verifyMailSent'), 'ok');
    } catch (e) {
      setProfileStatus(e.message || t('verifyMailSent'), 'bad');
      if (!(state.profile && state.profile.email_verified_at)) el.verifyBtn.disabled = false;
    }
  }

  function findSelectedCountryLabel() {
    const iso2 = normalizeIso2(el.countryIso2.value);
    const found = state.countries.find(c => normalizeIso2(c.iso2 || c.country_code || c.code) === iso2);
    return found ? pickName(found, iso2) : '';
  }

  function findSelectedStateLabel() {
    const sid = String(el.stateId.value || '');
    const found = state.states.find(s => String(s.id ?? s.state_id ?? '') === sid);
    return found ? pickName(found, el.countryIso2.value) : '';
  }

  function findSelectedAreaLabel() {
    const aid = String(el.areaId.value || '');
    const found = state.areas.find(a => String(a.id ?? a.area_id ?? '') === aid);
    return found ? pickName(found, el.countryIso2.value) : '';
  }

  async function submitProfile(ev) {
    ev.preventDefault();
    if (!state.editMode) return;

    el.submitChangesBtn.disabled = true;
    el.profileForm.classList.add('saving');

    try {
      const nickOk = await checkNicknameAvailable(el.nickname.value, true);
      if (!nickOk) throw new Error(t('nicknameUsed'));

      const payload = {
        lang: activeLang,
        nickname: el.nickname.value.trim(),
        email: el.email.value.trim(),
        mobile: el.mobile.value.replace(/\D+/g, '').slice(0, 15),
        prefix_iso2: normalizeIso2(el.prefixIso2.value),
        country_iso2: normalizeIso2(el.countryIso2.value),
        country_name: findSelectedCountryLabel(),
        state_id: String(el.stateId.value || ''),
        state_name: findSelectedStateLabel(),
        area_id: String(el.areaId.value || ''),
        area_name: findSelectedAreaLabel(),
        change_mode: 1,
        require_email_reverify: 1
      };

      const json = await fetchJson(API.profileSave, {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify(payload)
      });

      clearDraft();
      setReadonlyGroup(true);
      setProfileStatus(json.message || t('verifyMailSent'), 'ok');
      await initProfileSequence();
    } catch (e) {
      setProfileStatus(e.message || 'Profile submit failed.', 'bad');
      toggleSubmitAvailability();
    } finally {
      el.submitChangesBtn.disabled = false;
      el.profileForm.classList.remove('saving');
    }
  }

  function setLang(lang) {
    activeLang = (lang === 'zh') ? 'zh' : 'en';
    localStorage.setItem('rwa_lang', activeLang);
    document.documentElement.lang = activeLang;

    document.querySelectorAll('.langbtn').forEach(btn => {
      btn.classList.toggle('active', btn.dataset.lang === activeLang);
    });

    el.pageTitle.textContent = t('pageTitle');
    el.pageSub.textContent = t('pageSub');
    el.userIdLabel.textContent = t('userIdLabel');
    el.profileCardTitle.textContent = t('profileCardTitle');
    el.profileCardSub.textContent = t('profileCardSub');
    el.lockTitle.textContent = t('lockTitle');
    el.nicknameLabel.textContent = t('nicknameLabel');
    el.emailLabel.textContent = t('emailLabel');
    el.mobileLabel.textContent = t('mobileLabel');
    el.countryLabel.textContent = t('countryLabel');
    el.stateLabel.textContent = t('stateLabel');
    el.areaLabel.textContent = t('areaLabel');
    el.avatarTitle.textContent = t('avatarTitle');
    el.flagEmptyText.textContent = t('flag');
    el.mobileHint.textContent = t('mobileHint');
    el.suggestNickBtn.textContent = t('suggestNickname');
    el.changeProfileBtn.textContent = t('changeProfile');
    el.verifyBtn.textContent = t('verifyCurrentEmail');
    el.submitChangesBtn.textContent = t('submitChanges');
    el.cancelEditBtn.textContent = t('cancel');
    el.profileStatusTitle.textContent = t('profileStatusTitle');
    el.tonCardTitle.textContent = t('tonCardTitle');
    el.tonCardSub.textContent = t('tonCardSub');
    el.tonCurrentLabel.textContent = t('tonCurrentLabel');
    el.proofStateLabel.textContent = t('proofStateLabel');
    el.tonValidationLabel.textContent = t('tonValidationLabel');
    el.tonStatusTitle.textContent = t('tonStatusTitle');
    el.tonHint.textContent = t('tonHint');
    el.bindTonBtn.textContent = t('bindTon');
    el.resetTonBtn.textContent = t('resetTon');
    el.qrTitle.textContent = t('qrTitle');
    el.copyTonBtn.textContent = t('copyAddress');

    setReadonlyGroup(!state.editMode);

    if (!state.profile) {
      el.profileStatusText.textContent = t('loadingProfile');
      el.tonStatusText.textContent = t('tonReady');
      el.tonValidationText.textContent = t('tonValidationPending');
      el.nicknameMsg.textContent = t('nicknamePending');
      el.proofStateText.textContent = t('tonIdle');
      renderAvatarMeta(!!String(SESSION.wallet || '').trim());
      renderTonQr(SESSION.wallet || '');
      state.lastAvatarKey = '';
      el.profileAvatar.src = SERVER.firstPaintAvatar;
    } else {
      renderAvatarMeta(!!String(state.profile.wallet_address || '').trim());
      setEmailBadge(!!state.profile.email_verified_at);
      setTonBadge(!!state.profile.wallet_address);
      setTonValidation(state.profile.wallet_address ? t('tonValidationOk') : t('tonValidationMissing'), state.profile.wallet_address ? 'ok' : 'warn');
      renderTonQr(state.profile.wallet_address || '');
      refreshAvatar(resolveAvatarWallet(state.profile.wallet_address || ''), resolveAvatarNickname(state.profile.nickname || ''), true);
    }
  }

  function installTonProofClient() {
    if (!window.RwaTonProof || typeof window.RwaTonProof.create !== 'function') {
      throw new Error('proof.js not loaded');
    }

    state.tonProofClient = window.RwaTonProof.create({
      manifestUrl: 'https://adoptgold.app/tonconnect-manifest.json',
      buttonRootId: 'profileTonConnectRoot',

      async getNoncePayload() {
        const nonceJson = await fetchJson(API.tonNonce, {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({ lang: activeLang })
        });

        return String(
          nonceJson.payload || nonceJson.nonce || nonceJson.ton_proof_payload || ''
        ).trim();
      },

      async resetServerSession() {
        await fetchJson(API.tonReset, {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({ lang: activeLang })
        });
      },

      onStatus(message, type) {
        const msg = String(message || '');
        if (msg.includes('Preparing fresh TON bind session')) {
          el.proofStateText.textContent = t('tonPreparingFresh');
        } else if (msg.includes('Waiting wallet proof')) {
          el.proofStateText.textContent = t('tonWaitingProof');
        } else if (msg.includes('Submitting proof')) {
          el.proofStateText.textContent = t('tonSubmittingProof');
        }
        setTonStatus(msg, type || 'info');
      },

      onDebug(snapshot) {
        console.log('PROFILE TON DEBUG', snapshot);
      },

      onWalletChange(wallet) {
        const addr = wallet?.account?.address ? String(wallet.account.address) : '';
        el.tonAddressText.textContent = addr || (state.profile?.wallet_address || '—');
        if (addr) {
          el.proofStateText.textContent = t('tonWaitingWallet');
        }
      },

      async onProofReady({ address, proof }) {
        el.proofStateText.textContent = t('tonSubmittingProof');

        const json = await fetchJson(API.tonBind, {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({
            wallet_address: address,
            ton_proof: proof,
            lang: activeLang
          })
        });

        const finalAddress = String(json.wallet_address || address || '').trim();

        if (state.profile) {
          state.profile.wallet_address = finalAddress;
        }

        el.tonAddressText.textContent = finalAddress || '—';
        setTonBadge(!!finalAddress);
        setTonValidation(finalAddress ? t('tonValidationOk') : t('tonValidationMissing'), finalAddress ? 'ok' : 'warn');
        setTonStatus(json.message || t('tonSavedOk'), 'ok');
        el.proofStateText.textContent = t('tonIdleText');

        await refreshAvatar(finalAddress, el.nickname.value || state.profile?.nickname || SESSION.nickname || '', true);
        renderAvatarMeta(!!finalAddress);
        renderTonQr(finalAddress);

        el.bindTonBtn.disabled = false;
        return json;
      }
    });
  }

  function installEvents() {
    document.querySelectorAll('.langbtn').forEach(btn => {
      btn.addEventListener('click', async () => {
        const wasEditing = state.editMode;
        const currentCountry = el.countryIso2.value;
        const currentState = el.stateId.value;
        const currentArea = el.areaId.value;
        const currentPrefix = el.prefixIso2.value;
        setLang(btn.dataset.lang);

        if (wasEditing) {
          renderCountriesOptions(currentCountry);
          renderPrefixOptions(currentPrefix);
          el.countryIso2.value = normalizeIso2(currentCountry);
          el.prefixIso2.value = normalizeIso2(currentPrefix);
          renderMobileFlag(el.prefixIso2.value);

          const selected = state.countries.find(c => normalizeIso2(c.iso2 || c.country_code || c.code) === normalizeIso2(currentCountry));
          renderCountryChip(currentCountry, selected ? pickName(selected, currentCountry) : currentCountry);

          await loadStatesForCountry(currentCountry, currentState);
          el.stateId.value = String(currentState || '');
          await loadAreasForState(currentState, currentArea);
          el.areaId.value = String(currentArea || '');
          await refreshAvatar(resolveAvatarWallet(), resolveAvatarNickname(), true);
          setProfileStatus(t('editEnabled'), 'warn');
          toggleSubmitAvailability();
          return;
        }

        try {
          await initProfileSequence();
        } catch (e) {
          setProfileStatus(e.message || 'Profile submit failed.', 'bad');
        }
      });
    });

    el.changeProfileBtn.addEventListener('click', enterEditMode);
    el.cancelEditBtn.addEventListener('click', cancelEditMode);
    el.verifyBtn.addEventListener('click', sendVerifyEmail);
    el.profileForm.addEventListener('submit', submitProfile);

    el.suggestNickBtn.addEventListener('click', async () => {
      el.nickname.value = await suggestUniqueNickname();
      await refreshAvatar(resolveAvatarWallet(), el.nickname.value, true);
      await checkNicknameAvailable(el.nickname.value, true);
      toggleSubmitAvailability();
    });

    el.nickname.addEventListener('input', () => {
      saveDraft();
      clearTimeout(state.nicknameTimer);
      state.nicknameTimer = setTimeout(async () => {
        await refreshAvatar(resolveAvatarWallet(), el.nickname.value, true);
        await checkNicknameAvailable(el.nickname.value, true);
      }, 400);
    });

    el.mobile.addEventListener('input', () => {
      const cleaned = (el.mobile.value || '').replace(/\D+/g, '').slice(0, 15);
      if (el.mobile.value !== cleaned) el.mobile.value = cleaned;
      saveDraft();
    });

    el.email.addEventListener('input', saveDraft);

    el.prefixIso2.addEventListener('change', () => {
      renderMobileFlag(el.prefixIso2.value);
      saveDraft();
    });

    el.countryIso2.addEventListener('change', async () => {
      const iso2 = normalizeIso2(el.countryIso2.value);
      const selected = state.countries.find(c => normalizeIso2(c.iso2 || c.country_code || c.code) === iso2);
      renderCountryChip(iso2, selected ? pickName(selected, iso2) : iso2);

      el.stateId.value = '';
      el.areaId.value = '';
      await loadStatesForCountry(iso2, '');
      await loadAreasForState('', '');
      saveDraft();
    });

    el.stateId.addEventListener('change', async () => {
      el.areaId.value = '';
      await loadAreasForState(String(el.stateId.value || ''), '');
      saveDraft();
    });

    el.areaId.addEventListener('change', saveDraft);

    el.bindTonBtn.addEventListener('click', async () => {
      el.bindTonBtn.disabled = true;
      try {
        el.proofStateText.textContent = t('tonPreparingFresh');
        setTonValidation(t('tonValidationPending'), 'warn');
        await state.tonProofClient.start();
      } catch (e) {
        setTonStatus(e.message || t('tonBindFailed'), 'bad');
        el.proofStateText.textContent = t('tonIdleText');
        el.bindTonBtn.disabled = false;
      }
    });

    el.resetTonBtn.addEventListener('click', async () => {
      el.resetTonBtn.disabled = true;
      try {
        await state.tonProofClient.fullReset();
        el.proofStateText.textContent = t('tonIdleText');
        el.tonAddressText.textContent = state.profile?.wallet_address || '—';
        setTonValidation(
          state.profile?.wallet_address ? t('tonValidationOk') : t('tonValidationMissing'),
          state.profile?.wallet_address ? 'ok' : 'warn'
        );
        renderTonQr(state.profile?.wallet_address || '');
        await refreshAvatar(resolveAvatarWallet(), resolveAvatarNickname(), true);
      } catch (e) {
        setTonStatus(e.message || t('tonResetFailed'), 'bad');
      } finally {
        el.resetTonBtn.disabled = false;
        el.bindTonBtn.disabled = false;
      }
    });

    el.copyTonBtn.addEventListener('click', async () => {
      const addr = String(state.profile?.wallet_address || '').trim();
      if (!addr) return;
      try {
        await navigator.clipboard.writeText(addr);
        setCopyStatus(t('copied'), 'ok');
      } catch (_) {
        try {
          const ta = document.createElement('textarea');
          ta.value = addr;
          ta.setAttribute('readonly', 'readonly');
          ta.style.position = 'fixed';
          ta.style.opacity = '0';
          document.body.appendChild(ta);
          ta.select();
          document.execCommand('copy');
          document.body.removeChild(ta);
          setCopyStatus(t('copied'), 'ok');
        } catch (e) {
          setCopyStatus(t('copyFailed'), 'bad');
        }
      }
    });
  }

  async function boot() {
    setLang(activeLang);
    installTonProofClient();
    installEvents();

    el.profileStatusText.textContent = t('loadingProfile');
    el.tonStatusText.textContent = t('tonReady');
    el.tonValidationText.textContent = t('tonValidationPending');
    el.nicknameMsg.textContent = t('nicknamePending');
    renderAvatarMeta(!!String(SESSION.wallet || '').trim());
    renderTonQr(SESSION.wallet || '');

    try {
      await initProfileSequence();
    } catch (e) {
      setProfileStatus(e.message || 'Profile submit failed.', 'bad');
    }
  }

  boot();
})();
</script>
</body>
</html>