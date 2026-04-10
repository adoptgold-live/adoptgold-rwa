<?php
declare(strict_types=1);
/**
 * /var/www/html/public/rwa/storage/index.php
 * AdoptGold / POAdo — Storage Index
 * Version: FINAL-LOCK-5
 *
 * Layout rules:
 * - maintain previous locked Storage shell, DOM flow, and modal structure
 * - maintain previous card preview / bind / left-right panel layout
 * - maintain previous reload / commit / claim / fuel / history structure
 * - preserve EN / 中 switch
 * - add Storage-owned claim UI for mining-linked unclaimed wEMS
 * - do not break existing storage.js / reload helper / commit helper flow
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function storage_page_pdo(): ?PDO
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

    if (function_exists('rwa_db')) {
        try {
            $tmp = rwa_db();
            if ($tmp instanceof PDO) {
                $GLOBALS['pdo'] = $tmp;
                return $tmp;
            }
        } catch (Throwable $e) {
        }
    }

    return null;
}

function storage_page_user_seed(): ?array
{
    if (function_exists('rwa_current_user')) {
        try {
            $tmp = rwa_current_user();
            if (is_array($tmp) && !empty($tmp)) {
                return $tmp;
            }
        } catch (Throwable $e) {
        }
    }

    if (function_exists('rwa_session_user')) {
        try {
            $tmp = rwa_session_user();
            if (is_array($tmp) && !empty($tmp)) {
                return $tmp;
            }
        } catch (Throwable $e) {
        }
    }

    if (function_exists('get_wallet_session')) {
        try {
            $tmp = get_wallet_session();
            if (is_array($tmp) && !empty($tmp)) {
                return $tmp;
            }
            if (is_string($tmp) && trim($tmp) !== '') {
                return ['wallet' => trim($tmp)];
            }
        } catch (Throwable $e) {
        }
    }

    return null;
}

function storage_page_user_hydrate(?array $seed): ?array
{
    if (!$seed || !is_array($seed)) {
        return null;
    }

    $pdo = storage_page_pdo();
    if (!$pdo) {
        return null;
    }

    $userId = (int)($seed['id'] ?? 0);
    $wallet = trim((string)($seed['wallet'] ?? ''));
    $walletAddress = trim((string)($seed['wallet_address'] ?? ''));

    $select = "
        SELECT
            id,
            wallet,
            is_registered,
            nickname,
            email,
            email_verified_at,
            verify_token,
            verify_sent_at,
            mobile_e164,
            mobile,
            country_code,
            country_name,
            state,
            country,
            region,
            salesmartly_email,
            role,
            is_active,
            is_fully_verified,
            is_senior,
            wallet_address,
            created_at,
            updated_at
        FROM users
    ";

    try {
        if ($userId > 0) {
            $stmt = $pdo->prepare($select . " WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && !empty($row)) {
                return $row;
            }
        }

        if ($walletAddress !== '') {
            $stmt = $pdo->prepare($select . " WHERE wallet_address = ? LIMIT 1");
            $stmt->execute([$walletAddress]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && !empty($row)) {
                return $row;
            }
        }

        if ($wallet !== '') {
            $stmt = $pdo->prepare($select . " WHERE wallet = ? LIMIT 1");
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

$seed = storage_page_user_seed();
$user = storage_page_user_hydrate($seed);

if ((!$user || (int)($user['id'] ?? 0) <= 0) && is_array($seed) && !empty($seed)) {
    $user = [
        'id' => (int)($seed['id'] ?? 0),
        'wallet' => (string)($seed['wallet'] ?? ''),
        'is_registered' => (int)($seed['is_registered'] ?? 0),
        'nickname' => (string)($seed['nickname'] ?? 'Storage User'),
        'email' => (string)($seed['email'] ?? ''),
        'email_verified_at' => (string)($seed['email_verified_at'] ?? ''),
        'verify_token' => '',
        'verify_sent_at' => '',
        'mobile_e164' => '',
        'mobile' => '',
        'country_code' => '',
        'country_name' => '',
        'state' => '',
        'country' => '',
        'region' => '',
        'salesmartly_email' => '',
        'role' => (string)($seed['role'] ?? ''),
        'is_active' => (int)($seed['is_active'] ?? 1),
        'is_fully_verified' => (int)($seed['is_fully_verified'] ?? 0),
        'is_senior' => (int)($seed['is_senior'] ?? 0),
        'wallet_address' => (string)($seed['wallet_address'] ?? ''),
        'created_at' => '',
        'updated_at' => '',
    ];
}

if (!$user || (empty($user['wallet']) && empty($user['wallet_address']) && (int)($user['id'] ?? 0) <= 0)) {
    $user = [
        'id' => 0,
        'wallet' => '',
        'is_registered' => 0,
        'nickname' => 'Storage User',
        'email' => '',
        'email_verified_at' => '',
        'verify_token' => '',
        'verify_sent_at' => '',
        'mobile_e164' => '',
        'mobile' => '',
        'country_code' => '',
        'country_name' => '',
        'state' => '',
        'country' => '',
        'region' => '',
        'salesmartly_email' => '',
        'role' => '',
        'is_active' => 0,
        'is_fully_verified' => 0,
        'is_senior' => 0,
        'wallet_address' => '',
        'created_at' => '',
        'updated_at' => '',
    ];
}

$displayName = trim((string)(
    $user['nickname']
    ?? $user['email']
    ?? $user['wallet_address']
    ?? $user['wallet']
    ?? 'Storage User'
));
if ($displayName === '') {
    $displayName = 'Storage User';
}

$walletAddr = trim((string)($user['wallet_address'] ?? ''));
$hasTonBind = ($walletAddr !== '');
$email = trim((string)($user['email'] ?? ''));
$emailVerifiedAt = trim((string)($user['email_verified_at'] ?? ''));
$emailVerified = ($email !== '' && $emailVerifiedAt !== '');

$csrfBind = function_exists('csrf_token') ? csrf_token('storage_bind_card') : '';
$csrfActivate = function_exists('csrf_token') ? csrf_token('storage_activate_card') : '';
$csrfReload = function_exists('csrf_token') ? csrf_token('storage_reload') : '';
$csrfCommit = function_exists('csrf_token') ? csrf_token('storage_commit_emx') : '';
$csrfClaim = function_exists('csrf_token') ? csrf_token('storage_claim') : '';
$csrfFuelEmx = function_exists('csrf_token') ? csrf_token('storage_fuel_up_emx') : '';
$csrfFuelEmxConfirm = function_exists('csrf_token') ? csrf_token('storage_fuel_up_emx_confirm') : '';
$csrfFuelEms = function_exists('csrf_token') ? csrf_token('storage_fuel_up_ems') : '';
$csrfClaimWems = function_exists('csrf_token') ? csrf_token('storage_claim_wems') : '';

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
  <title>Storage</title>
  <link rel="stylesheet" href="/rwa/storage/storage.css?v=final-lock-5">
  <link rel="stylesheet" href="/rwa/storage/reload-card-emx/style.css?v=7.6.2">
  <link rel="stylesheet" href="/rwa/storage/commit/style.css?v=final-lock-1">
  <style>
    .storage-claim-modal[hidden]{display:none!important}
    .storage-claim-modal{
      position:fixed; inset:0; z-index:220; display:flex; align-items:center; justify-content:center; padding:16px;
    }
    .storage-claim-modal__backdrop{
      position:absolute; inset:0; background:rgba(0,0,0,.72);
    }
    .storage-claim-modal__panel{
      position:relative; width:min(720px,100%); max-height:90vh; overflow:auto;
      border-radius:18px; border:1px solid rgba(180,150,80,.28);
      background:linear-gradient(180deg,#0f1114,#08090b);
      box-shadow:0 20px 80px rgba(0,0,0,.6);
      padding:18px;
    }
    .storage-claim-modal__close{
      position:absolute; top:10px; right:12px; background:transparent; border:0; color:#d9d9d9; font-size:28px; cursor:pointer;
    }
    .claim-wems-grid{
      display:grid; grid-template-columns:1.15fr .85fr; gap:14px;
    }
    @media (max-width:900px){ .claim-wems-grid{grid-template-columns:1fr;} }
    .claim-wems-card{
      border:1px solid rgba(255,255,255,.08);
      border-radius:16px;
      background:rgba(255,255,255,.02);
      padding:14px;
    }
    .claim-wems-k{font-size:12px; color:#9ca3af; margin-bottom:6px}
    .claim-wems-v{font-size:24px; font-weight:800; color:#f5d67b}
    .claim-wems-note{font-size:12px; color:#a7a7a7; line-height:1.55}
    .claim-wems-form{display:grid; gap:12px}
    .claim-wems-input{
      width:100%; border-radius:12px; border:1px solid rgba(255,255,255,.10);
      background:#0f1318; color:#fff; padding:12px 14px; font-size:14px;
    }
    .claim-wems-actions{display:flex; flex-wrap:wrap; gap:10px}
    .claim-wems-status{
      min-height:24px; font-size:13px; color:#d1d5db; padding-top:4px;
    }
    .claim-wems-mini{
      display:grid; gap:10px;
    }
    .claim-wems-row{
      display:flex; justify-content:space-between; gap:12px; padding:10px 0; border-bottom:1px dashed rgba(255,255,255,.08);
    }
    .claim-wems-row:last-child{border-bottom:0}
    .claim-wems-row .left{color:#9ca3af; font-size:12px}
    .claim-wems-row .right{color:#fff; font-weight:700; text-align:right}
  </style>
</head>
<body
  class="wallet-mobile-safe"
  data-lang="<?= h($langDefault) ?>"
  data-user-id="<?= h((string)($user['id'] ?? 0)) ?>"
  data-wallet="<?= h((string)($user['wallet'] ?? '')) ?>"
  data-wallet-address="<?= h($walletAddr) ?>"
  data-name="<?= h($displayName) ?>"
  data-bind-csrf="<?= h($csrfBind) ?>"
  data-activate-csrf="<?= h($csrfActivate) ?>"
  data-reload-csrf="<?= h($csrfReload) ?>"
  data-commit-csrf="<?= h($csrfCommit) ?>"
  data-claim-csrf="<?= h($csrfClaim) ?>"
  data-claim-wems-csrf="<?= h($csrfClaimWems) ?>"
  data-fuel-emx-csrf="<?= h($csrfFuelEmx) ?>"
  data-fuel-emx-confirm-csrf="<?= h($csrfFuelEmxConfirm) ?>"
  data-fuel-ems-csrf="<?= h($csrfFuelEms) ?>"
  data-email-verified="<?= $emailVerified ? '1' : '0' ?>"
  data-has-ton-bind="<?= $hasTonBind ? '1' : '0' ?>"
  data-trade-url="https://app.ston.fi/swap?chartVisible=true&chartInterval=1w&ft=EQA8dAgNtnsfGF0M-MJfnqii5AhxcRe73M8nCkkxuq85Tr-Q&tt=USD%E2%82%AE"
>
  <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php'; ?>

  <main class="storage-shell">

    <section class="storage-langbar">
      <div class="storage-lang-inline" role="group" aria-label="Language">
        <button type="button" class="lang-inline-btn is-active" id="langEnBtn" data-lang="en">EN</button>
        <span class="lang-sep">|</span>
        <button type="button" class="lang-inline-btn" id="langZhBtn" data-lang="zh">中</button>
      </div>
    </section>

    <section class="wallet-hero">
      <div class="wallet-hero-head">
        <div class="wallet-hero-copy">
          <div class="eyebrow" id="heroEyebrow">Storage Card</div>
          <h1 id="heroTitle">RWA Adoption Card</h1>
          <p class="wallet-hero-sub" id="heroSubtitle">Reload Card with EMX</p>
        </div>

        <button
          type="button"
          class="wallet-btn wallet-btn-primary"
          id="btnTopupEmx"
          data-click-sfx
        >
          <span id="btnTopupLabel">Reload Card with EMX</span>
        </button>
      </div>

      <div class="wallet-card-area">
        <section class="wallet-card-box">
          <div class="wallet-card-visual" id="rwaStorageCard" role="img" aria-label="RWA Adoption Card">
            <div class="wallet-card-overlay"></div>
            <div class="wallet-card-number" id="storageCardNumber">0000 - 0000 - 0000 - 0000</div>
            <div class="wallet-card-meta">
              <span class="wallet-card-holder" id="storageCardHolder"><?= h($displayName) ?></span>
              <span class="wallet-card-note" id="cardCaption">Public Swap Number</span>
            </div>
          </div>

          <div class="wallet-card-under" id="cardUnderMetaWrap">
            <div class="wallet-card-under-panel wallet-card-rate-panel" id="cardLiveRatePanel">
              <div class="wallet-card-under-head">
                <div class="eyebrow">LIVE RATE</div>
              </div>

              <div class="wallet-rate-lines">
                <div class="wallet-rate-line">
                  <span class="wallet-rate-label">1 EMX</span>
                  <span class="wallet-rate-sep">=</span>
                  <span class="wallet-rate-value" id="cardLiveRate">- RWA€</span>
                </div>

                <div class="wallet-rate-line">
                  <span class="wallet-rate-label">1 RWA€</span>
                  <span class="wallet-rate-sep">=</span>
                  <span class="wallet-rate-value" id="cardReverseRate">- EMX</span>
                </div>
              </div>

              <div class="wallet-rate-meta">
                <div class="wallet-rate-meta-row">
                  <span class="wallet-rate-meta-key">Updated</span>
                  <span class="wallet-rate-meta-val" id="cardLiveRateUpdated">-</span>
                </div>
                <div class="wallet-rate-meta-row">
                  <span class="wallet-rate-meta-key">Source</span>
                  <span class="wallet-rate-meta-val" id="cardLiveRateSource">-</span>
                </div>
              </div>
            </div>

            <div class="wallet-card-under-panel wallet-card-mode-panel" id="cardModePanel">
              <div class="wallet-card-under-head">
                <div class="eyebrow">CARD MODE</div>
              </div>

              <div class="wallet-mode-chip-row">
                <span class="wallet-mode-chip is-active" id="cardModeChipU">U€</span>
                <span class="wallet-mode-chip" id="cardModeChipM">M€</span>
                <span class="wallet-mode-chip" id="cardModeChipV">V€</span>
                <span class="wallet-lock-chip" id="cardModeLockedBadge" hidden>LOCKED</span>
              </div>

              <div class="wallet-mode-value" id="cardModeValue">U€</div>
            </div>
          </div>
        </section>

        <section class="wallet-card-bind">
          <div class="wallet-balance-head">
            <div class="wallet-balance-label" id="cardBalanceLabel">CARD BALANCE</div>
            <div class="wallet-balance-value balance-gold" id="cardBalanceValue">RWA€ 0.0000</div>
          </div>

          <div class="bind-headline">
            <div class="eyebrow" id="bindEyebrow">Bind Card</div>
            <div class="bind-title" id="bindTitle">Card Number</div>
          </div>

          <div class="card-inline-row" id="cardInputRow" aria-label="16 digit card number">
            <div class="card-group">
<?php for ($i = 0; $i < 4; $i++): ?>
              <input class="card-digit" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="off" data-index="<?= $i ?>" aria-label="Card digit <?= ($i + 1) ?>">
<?php endfor; ?>
            </div>

            <span class="card-sep" aria-hidden="true">-</span>

            <div class="card-group">
<?php for ($i = 4; $i < 8; $i++): ?>
              <input class="card-digit" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="off" data-index="<?= $i ?>" aria-label="Card digit <?= ($i + 1) ?>">
<?php endfor; ?>
            </div>

            <span class="card-sep" aria-hidden="true">-</span>

            <div class="card-group">
<?php for ($i = 8; $i < 12; $i++): ?>
              <input class="card-digit" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="off" data-index="<?= $i ?>" aria-label="Card digit <?= ($i + 1) ?>">
<?php endfor; ?>
            </div>

            <span class="card-sep" aria-hidden="true">-</span>

            <div class="card-group">
<?php for ($i = 12; $i < 16; $i++): ?>
              <input class="card-digit" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="off" data-index="<?= $i ?>" aria-label="Card digit <?= ($i + 1) ?>">
<?php endfor; ?>
            </div>
          </div>

          <div class="wallet-inline-actions">
            <button type="button" class="wallet-btn wallet-btn-secondary" id="btnBindCard" data-click-sfx>Bind Card</button>
            <button type="button" class="wallet-btn wallet-btn-secondary" id="btnClearCardInput" data-click-sfx>Clear</button>
            <button type="button" class="wallet-btn wallet-btn-primary" id="btnActivateCard" data-click-sfx>Activate Card (100 EMX)</button>
          </div>

          <div class="wallet-soft-note" id="cardNotice">
            Please verify your email before binding or activating card.<br>
            Card number is a public deposit number and remains fully visible.
          </div>

          <div class="wallet-status" id="cardBindStatus" aria-live="polite"></div>

          <div class="wallet-fee-notice" id="rwaFeeNotice">
            <div class="wallet-fee-head">
              <span class="eyebrow">Fee Reference</span>
            </div>
            <div class="wallet-fee-box">
              <div class="wallet-fee-line">
                <span>UPC Gift Card (UPC)</span>
                <span class="fee-old">~0.3% – 1.75%</span>
              </div>
              <div class="wallet-fee-line highlight">
                <span>RWA Adoption Card</span>
                <span class="fee-new">0.1% EMX + 0.1% EMS</span>
              </div>
              <a href="/rwa/storage/upc-fees.php" class="wallet-fee-link">View full UPC fee reference →</a>
            </div>
          </div>

          <div class="wallet-activation-panel" id="activationConfirmPanel" style="display:none;">
            <div class="eyebrow">Activation Confirm</div>
            <div class="wallet-status" id="activationSummary" aria-live="polite">Activation confirmation is not required right now.</div>
            <div class="wallet-status wallet-status-success" id="activationSuccessSummary" aria-live="polite" style="display:none;"></div>

            <div class="wallet-activation-grid">
              <div class="wallet-activation-row"><div class="wallet-activation-label">Activation Ref</div><div class="wallet-activation-value" id="activationRefValue" title="">-</div></div>
              <div class="wallet-activation-row"><div class="wallet-activation-label">Treasury</div><div class="wallet-activation-value" id="activationTreasuryValue" title="">-</div></div>
              <div class="wallet-activation-row"><div class="wallet-activation-label">Required EMX</div><div class="wallet-activation-value" id="activationAmountValue">100.000000</div></div>
              <div class="wallet-activation-row"><div class="wallet-activation-label">Memo / Ref</div><div class="wallet-activation-value" id="activationMemoValue" title="">-</div></div>
              <div class="wallet-activation-row"><div class="wallet-activation-label">Reward</div><div class="wallet-activation-value" id="activationRewardValue">Free EMA$ after confirmed activation</div></div>
              <div class="wallet-activation-row"><div class="wallet-activation-label">EMA Price Snapshot</div><div class="wallet-activation-value" id="activationEmaPriceValue">-</div></div>
            </div>

            <div class="wallet-inline-actions">
              <button type="button" class="wallet-btn wallet-btn-secondary" id="btnCopyActivationRef" data-click-sfx>Copy Ref</button>
              <button type="button" class="wallet-btn wallet-btn-secondary" id="btnCopyActivationMemo" data-click-sfx>Copy Memo</button>
              <button type="button" class="wallet-btn wallet-btn-secondary" id="btnCloseActivationPanel" data-click-sfx>Close</button>
            </div>

            <div class="wallet-activation-qr-block">
              <div class="wallet-activation-label">Payment QR</div>
              <div class="wallet-qr-wrap wallet-activation-qr-wrap" id="activationQrWrap" data-empty-text="Activation QR will appear after prepare">
                <div class="wallet-empty" id="activationQrEmptyText">Activation QR will appear after prepare</div>
              </div>
            </div>

            <div class="wallet-inline-actions">
              <a href="#" class="wallet-btn wallet-btn-primary" id="btnActivationDeepLink" target="_blank" rel="noopener" style="display:none;">Open TON Pay</a>
            </div>

            <div class="wallet-status" id="activationAutoStatus" aria-live="polite"></div>

            <div class="wallet-activation-input-wrap">
              <label class="wallet-activation-label" for="activationTxHashInput">TX Hash</label>
              <input id="activationTxHashInput" class="wallet-activation-input" type="text" inputmode="text" autocomplete="off" spellcheck="false" placeholder="Paste transaction hash">
            </div>

            <div class="wallet-inline-actions">
              <button type="button" class="wallet-btn wallet-btn-primary" id="btnActivationConfirm" data-click-sfx>Confirm Activation</button>
              <button type="button" class="wallet-btn wallet-btn-secondary" id="btnActivationRefreshAutoConfirm" data-click-sfx>Refresh Auto Confirm</button>
            </div>

            <div class="wallet-status" id="activationStatus" aria-live="polite"></div>
          </div>
        </section>
      </div>
    </section>

    <section class="wallet-main-grid">

      <section class="wallet-panel wallet-panel-left">
        <div class="panel-head">
          <div>
            <div class="eyebrow" id="onChainEyebrow">On Chain</div>
            <h2 id="onChainTitle">My Digital Rights 数权</h2>
          </div>
        </div>

        <div class="wallet-chain-sync-panel" id="chainSyncStatusWrap">
          <div class="eyebrow">Chain Sync</div>
          <div class="wallet-status is-idle" id="chainSyncStatus" aria-live="polite">Chain sync ready</div>
        </div>

        <div class="rights-strip">
          <div class="rights-tile gold">
            <div class="rights-tile-left"><img class="token-icon" src="/rwa/metadata/emx.png" alt="EMX"><div class="rights-label" id="labelEmx">EMX 金权</div></div>
            <div class="rights-value" id="balEMX">0.000000</div>
          </div>

          <div class="rights-tile orange">
            <div class="rights-tile-left"><img class="token-icon" src="/rwa/metadata/ema.png" alt="EMA$"><div class="rights-label" id="labelEma">EMA$ 数权</div></div>
            <div class="rights-value" id="balEMA">0.000000</div>
          </div>

          <div class="rights-tile green">
            <div class="rights-tile-left"><img class="token-icon" src="/rwa/metadata/wems.png" alt="wEMS"><div class="rights-label" id="labelWems">wEMS 网金</div></div>
            <div class="rights-value" id="balWEMS">0.000000</div>
          </div>
        </div>

<?php if (!$hasTonBind): ?>
        <div class="wallet-alert">
          <div class="wallet-alert-title" id="bindTonTitle">Bind TON First</div>
          <div class="wallet-alert-text" id="bindTonText">On-chain address, token balances, and chain actions need a bound TON address.</div>
        </div>
<?php endif; ?>

        <div class="wallet-address-qr-grid">
          <div class="wallet-address-box">
            <div class="eyebrow" id="boundAddressLabel">BOUND TON ADDRESS</div>
            <div class="wallet-address-topline">
              <div class="wallet-address-line" id="storageTonAddress" title="<?= $hasTonBind ? h($walletAddr) : '' ?>">
                <?= $hasTonBind ? h($walletAddr) : '-' ?>
              </div>
              <button type="button" class="wallet-btn wallet-btn-secondary wallet-btn-compact" id="btnCopyAddress" data-click-sfx <?= $hasTonBind ? '' : 'disabled' ?>>Copy Address</button>
            </div>

            <div class="wallet-scope-card">
              <div class="wallet-scope-head">
                <div class="eyebrow" id="commitmentLabel">RWA ADOPTION COMMITMENT</div>
                <div class="wallet-action-scope" id="chainTokenScope"></div>
              </div>

              <div class="wallet-ewallet-grid wallet-commit-grid">
                <button type="button" class="ewallet-action" id="btnCommitEmx" data-click-sfx data-open-commit-modal>
                  <span class="action-head">
                    <span class="action-left" aria-hidden="true"><span class="action-symbol symbol-commit">⚡</span></span>
                    <span class="action-main"><span class="ewallet-title" id="commitEmxLabel">COMMIT EMX</span></span>
                    <span class="action-right" aria-hidden="true"><img class="action-token-icon-lg" src="/rwa/metadata/emx.png" alt=""></span>
                  </span>
                  <span class="ewallet-sub" id="commitEmxSub">1 EMX = dynamic RWA€ · 0.1% EMX fee + 0.1% EMS fee</span>
                </button>

                <button type="button" class="ewallet-action ewallet-action-wems" id="btnTradeWebGold" data-click-sfx>
                  <span class="action-head">
                    <span class="action-left" aria-hidden="true"><span class="action-symbol symbol-trade">⇄</span></span>
                    <span class="action-main"><span class="ewallet-title" id="tradeWebGoldLabel">TRADE WEB GOLD</span></span>
                    <span class="action-right" aria-hidden="true"><img class="action-token-icon-lg" src="/rwa/metadata/wems.png" alt=""></span>
                  </span>
                  <span class="ewallet-sub" id="tradeWebGoldSub">Transfer wEMS to STON.fi</span>
                </button>
              </div>

              <div class="wallet-soft-note" id="commitmentNote">
                EMA$ can boost miner multiplier and mint Secondary RWA Cert. wEMS can mint Genesis RWA and trade on STON.fi.
              </div>

              <div class="wallet-status" id="actionStatus" aria-live="polite"></div>
            </div>

            <div class="wallet-status" id="copyStatus" aria-live="polite"></div>
          </div>

          <div class="wallet-qr-box">
            <div class="wallet-qr-head"><div class="eyebrow">QR</div></div>
            <div class="wallet-qr-wrap" id="storageQrWrap" data-empty-text="Bind TON to view QR">
<?php if (!$hasTonBind): ?>
              <div class="wallet-empty" id="qrEmptyText">Bind TON to view QR</div>
<?php endif; ?>
            </div>
          </div>
        </div>
      </section>

      <section class="wallet-panel wallet-panel-right">
        <div class="panel-head">
          <div>
            <div class="eyebrow" id="offChainEyebrow">Off Chain</div>
            <h2 id="offChainTitle">Off Chain Unclaimed</h2>
          </div>
        </div>

        <div class="unclaim-list">
          <div class="unclaim-row dim">
            <div class="unclaim-left">
              <img class="token-icon" src="/rwa/metadata/ema.png" alt="EMA$">
              <div class="unclaim-label" id="unclaimEmaLabel">My Unclaimed EMA$</div>
            </div>
            <div class="unclaim-actions">
              <div class="unclaim-value" id="unclaimEMA">0.000000</div>
              <button type="button" class="wallet-btn wallet-btn-secondary wallet-btn-compact" id="btnClaimEMA" data-token="EMA" data-click-sfx>CLAIM NOW</button>
            </div>
          </div>

          <div class="unclaim-row dim">
            <div class="unclaim-left">
              <img class="token-icon" src="/rwa/metadata/wems.png" alt="wEMS">
              <div class="unclaim-label" id="unclaimWemsLabel">My Unclaimed Web Gold wEMS</div>
            </div>
            <div class="unclaim-actions">
              <div class="unclaim-value" id="unclaimWEMS">0.000000</div>
              <button type="button" class="wallet-btn wallet-btn-secondary wallet-btn-compact" id="btnClaimWEMS" data-token="WEMS" data-click-sfx>CLAIM NOW</button>
            </div>
          </div>

          <div class="unclaim-row dim">
            <div class="unclaim-left">
              <img class="token-icon" src="/rwa/metadata/usdt_ton.png" alt="USDT-TON">
              <div class="unclaim-label" id="unclaimPacketLabel">My Unclaimed Gold Packet USDT-TON</div>
            </div>
            <div class="unclaim-actions">
              <div class="unclaim-value" id="unclaimPacket">0.000000</div>
              <button type="button" class="wallet-btn wallet-btn-secondary wallet-btn-compact" id="btnClaimPacket" data-token="USDT-TON" data-click-sfx>CLAIM NOW</button>
            </div>
          </div>

          <div class="unclaim-row dim">
            <div class="unclaim-left">
              <img class="token-icon" src="/rwa/metadata/emx.png" alt="EMX">
              <div class="unclaim-label" id="unclaimTipsLabel">My Unclaimed Tips EMX</div>
            </div>
            <div class="unclaim-actions">
              <div class="unclaim-value" id="unclaimTips">0.000000</div>
              <button type="button" class="wallet-btn wallet-btn-secondary wallet-btn-compact" id="btnClaimTips" data-token="EMX" data-click-sfx>CLAIM NOW</button>
            </div>
          </div>
        </div>

        <div class="wallet-alert compact">
          <div class="wallet-alert-title" id="claimNoticeTitle">Claim Notice</div>
          <div class="wallet-alert-text" id="claimNoticeText">
            All claim actions must be user-triggered and user-paid in TON gas.<br>
            Each claim also requires an additional fixed 0.10 TON treasury contribution.
          </div>
        </div>

        <div class="wallet-alert compact" id="miningClaimOwnerNotice">
          <div class="wallet-alert-title">Mining Claim Owner</div>
          <div class="wallet-alert-text">
            Mined wEMS is claimable from Storage only. Mining module is for mining + booster display, while Storage owns
            <b>My Unclaimed Web Gold wEMS</b> claim flow.
          </div>
        </div>
      </section>
    </section>

    <section class="wallet-fuel-panel">
      <div class="panel-head">
        <div>
          <div class="eyebrow" id="fuelEyebrow">Fuel</div>
          <h2 id="fuelTitle">MY FUEL STATIONS</h2>
        </div>
      </div>

      <div class="fuel-grid">
        <div class="fuel-tile">
          <div class="fuel-tile-head"><img class="token-icon" src="/rwa/metadata/usdt_ton.png" alt="USDT-TON"><div class="fuel-label" id="fuelUsdtTitle">My TON USDT</div></div>
          <div class="fuel-value" id="fuelUSDT">0.000000</div>
        </div>

        <div class="fuel-tile">
          <div class="fuel-tile-head"><img class="token-icon" src="/rwa/metadata/ems.png" alt="EMS"><div class="fuel-label" id="fuelEmsTitle">EMS (Non Gas Fees)</div></div>
          <div class="fuel-value" id="fuelEMS">0.000000</div>
        </div>

        <div class="fuel-tile">
          <div class="fuel-tile-head"><img class="token-icon" src="/rwa/metadata/ton.png" alt="TON"><div class="fuel-label" id="fuelTonTitle">My TON GAS</div></div>
          <div class="fuel-value" id="fuelTON">0.000000</div>
        </div>
      </div>

      <div class="fuel-action-row">
        <button type="button" class="fuel-action" id="btnFuelUpEmx" data-click-sfx>
          <span class="fuel-action-head">
            <span class="fuel-action-left" aria-hidden="true"><span class="fuel-station-symbol">⛽</span></span>
            <span class="fuel-action-main"><span class="fuel-action-title" id="fuelUpEmxLabel">FUEL UP EMX</span></span>
            <span class="fuel-action-right" aria-hidden="true"><img class="fuel-token-icon-lg" src="/rwa/metadata/emx.png" alt=""></span>
          </span>
          <span class="fuel-action-sub" id="fuelUpEmxSub">Onchain USDT-TON → Onchain EMX (1:1)</span>
        </button>

        <button type="button" class="fuel-action" id="btnFuelUpEms" data-click-sfx>
          <span class="fuel-action-head">
            <span class="fuel-action-left" aria-hidden="true"><span class="fuel-station-symbol">⛽</span></span>
            <span class="fuel-action-main"><span class="fuel-action-title" id="fuelUpEmsLabel">FUEL UP EMS</span></span>
            <span class="fuel-action-right" aria-hidden="true"><img class="fuel-token-icon-lg" src="/rwa/metadata/ems.png" alt=""></span>
          </span>
          <span class="fuel-action-sub" id="fuelUpEmsSub">Offchain wEMS direct swap with email confirmation</span>
        </button>
      </div>

      <div class="wallet-soft-note fuel-note" id="fuelFeeText">Card reload rule: EMX converts to RWA€ at locked prepare-time rate · 0.1% EMX fee + 0.1% EMS fee</div>
      <div class="wallet-status" id="fuelStatus" aria-live="polite"></div>
    </section>

    <section class="wallet-history-panel">
      <div class="panel-head">
        <div>
          <div class="eyebrow" id="historyEyebrow">History</div>
          <h2 id="historyTitle">Recent Activity</h2>
        </div>
      </div>
      <div class="wallet-history-list" id="storageHistoryList"></div>
    </section>

    <div class="storage-reload-modal" id="reloadCardEmxModal" hidden aria-hidden="true">
      <div class="storage-reload-modal__backdrop" data-reload-emx-close></div>
      <div class="storage-reload-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="reloadEmxModalTitle">
        <button type="button" class="storage-reload-modal__close" id="btnCloseReloadEmxModal" data-reload-emx-close aria-label="Close">×</button>

        <section data-reload-emx-module data-last-reload-ref="" data-last-deeplink="" data-last-qr="">
          <div class="reload-emx-shell">
            <div class="reload-emx-head">
              <div>
                <h2 class="reload-emx-title" id="reloadEmxModalTitle">Reload Card with EMX</h2>
                <p class="reload-emx-subtitle">Prepare reload, open TON wallet, then verify after EMX transfer is sent.</p>
              </div>
              <div class="reload-emx-status is-idle" data-reload-status>Idle</div>
            </div>

            <div class="reload-emx-grid-simple">
              <div class="reload-emx-card reload-emx-left">
                <div><h3 class="reload-emx-card-title">Reload Prepare</h3></div>
                <input type="hidden" data-reload-csrf value="<?= h($csrfReload) ?>">

                <div class="reload-emx-field">
                  <label class="reload-emx-label" for="reloadAmountInput">EMX Amount</label>
                  <input id="reloadAmountInput" class="reload-emx-input" type="text" inputmode="decimal" autocomplete="off" spellcheck="false" placeholder="Enter EMX amount" data-reload-amount>
                </div>

                <div class="reload-emx-field">
                  <label class="reload-emx-label" for="reloadCardNumberInput">Card Number</label>
                  <input id="reloadCardNumberInput" class="reload-emx-input" type="text" inputmode="numeric" autocomplete="off" spellcheck="false" placeholder="Bound card number" data-reload-card-number>
                </div>

                <div class="reload-emx-actions">
                  <button type="button" class="reload-emx-btn is-primary" data-reload-prepare>PREPARE RELOAD</button>
                  <button type="button" class="reload-emx-btn is-success" data-reload-verify disabled>VERIFY RELOAD</button>
                </div>

                <div class="reload-emx-note">Reload uses EMX jetton transfer with comment = reload reference.</div>
                <pre class="reload-emx-log" data-reload-log></pre>
              </div>

              <div class="reload-emx-card reload-emx-right">
                <div><h3 class="reload-emx-card-title">Deeplink &amp; QR</h3></div>

                <div class="reload-emx-detail-stack">
                  <div class="reload-emx-detail"><div class="reload-emx-detail-key">Reload Ref</div><div class="reload-emx-detail-val" data-reload-ref>-</div></div>
                  <div class="reload-emx-detail"><div class="reload-emx-detail-key">Wallet Deeplink</div><a class="reload-emx-detail-val is-link" href="#" data-reload-link>-</a></div>
                </div>

                <div class="reload-emx-qr-wrap" data-reload-qr-wrap><img class="reload-emx-qr" data-reload-qr-img alt="QR not ready"></div>

                <div class="reload-emx-actions">
                  <button type="button" class="reload-emx-btn is-blue" data-reload-open-wallet disabled>OPEN WALLET</button>
                  <button type="button" class="reload-emx-btn" data-reload-copy-ref disabled>COPY REF</button>
                  <button type="button" class="reload-emx-btn" data-reload-copy-link disabled>COPY LINK</button>
                </div>

                <div class="reload-emx-confirm" data-reload-confirm-box hidden>Reload confirmed successfully.</div>

                <div hidden>
                  <div data-reload-amount-view>-</div>
                  <div data-reload-units-view>-</div>
                  <div data-reload-treasury>-</div>
                  <div data-reload-jetton>-</div>
                  <div data-reload-qr-text>-</div>
                </div>
              </div>
            </div>
          </div>
        </section>
      </div>
    </div>

    <div class="storage-modal" id="commitModal" hidden aria-hidden="true">
      <div class="storage-modal__backdrop" data-close-commit-modal></div>
      <div class="storage-modal__panel" role="dialog" aria-modal="true" aria-labelledby="commitModalTitle" data-commit-module>
        <button type="button" class="storage-modal__close" data-close-commit-modal aria-label="Close Commit">×</button>

        <div class="storage-modal__head">
          <h3 id="commitModalTitle">Commit with EMX</h3>
          <p>Prepare commit, open TON wallet, then verify after EMX transfer is sent.</p>
        </div>

        <div class="storage-modal__body">
          <div class="storage-grid-2">
            <div class="storage-card">
              <h4>Commit Prepare</h4>

              <input type="hidden" data-commit-csrf value="<?= htmlspecialchars((string)$csrfCommit, ENT_QUOTES) ?>">

              <div class="storage-field">
                <label class="storage-label" for="commitAmountInput">EMX Amount</label>
                <input id="commitAmountInput" type="text" class="storage-input" data-commit-amount placeholder="Enter EMX amount">
              </div>

              <div class="storage-btn-row">
                <button type="button" class="storage-btn storage-btn--gold" data-commit-prepare>PREPARE COMMIT</button>
                <button type="button" class="storage-btn storage-btn--green" data-commit-verify>VERIFY COMMIT</button>
                <button type="button" class="storage-btn" data-commit-auto-refresh>AUTO REFRESH (5s)</button>
              </div>

              <p class="storage-note">Commit uses EMX jetton transfer with comment = commit reference.</p>
              <pre class="storage-log" data-commit-log></pre>
            </div>

            <div class="storage-card">
              <h4>Deeplink &amp; QR</h4>

              <div class="storage-info-block">
                <div class="storage-info-label">Commit Ref</div>
                <div class="storage-info-value" data-commit-ref>-</div>
              </div>

              <div class="storage-info-block">
                <div class="storage-info-label">Wallet Deeplink</div>
                <a class="storage-link-break" data-commit-link href="#" target="_blank" rel="noopener">-</a>
              </div>

              <div class="storage-btn-row">
                <button type="button" class="storage-btn" data-commit-open-wallet>OPEN WALLET</button>
                <button type="button" class="storage-btn" data-commit-copy-ref>COPY REF</button>
                <button type="button" class="storage-btn" data-commit-copy-link>COPY LINK</button>
              </div>

              <div class="storage-qr-wrap" data-commit-qr></div>

              <div class="storage-status-line">
                <div class="storage-live-pill is-idle" data-commit-live-status>Idle</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="storage-claim-modal" id="claimWemsModal" hidden aria-hidden="true">
      <div class="storage-claim-modal__backdrop" data-close-claim-wems></div>
      <div class="storage-claim-modal__panel" role="dialog" aria-modal="true" aria-labelledby="claimWemsTitle">
        <button type="button" class="storage-claim-modal__close" data-close-claim-wems aria-label="Close Claim">×</button>

        <div class="storage-modal__head">
          <h3 id="claimWemsTitle">Claim My Unclaimed Web Gold wEMS</h3>
          <p>Storage owns the claim flow for mined wEMS. This action creates a claim request only. Settlement is processed later by backend.</p>
        </div>

        <div class="claim-wems-grid">
          <div class="claim-wems-card">
            <div class="claim-wems-form">
              <div>
                <div class="claim-wems-k">AVAILABLE UNCLAIMED WEMS</div>
                <div class="claim-wems-v" id="claimWemsAvailable">0.000000</div>
              </div>

              <div>
                <label class="claim-wems-k" for="claimWemsAmountInput">CLAIM AMOUNT</label>
                <input id="claimWemsAmountInput" class="claim-wems-input" type="text" inputmode="decimal" autocomplete="off" placeholder="Enter wEMS amount">
              </div>

              <div>
                <label class="claim-wems-k" for="claimWemsDestInput">DESTINATION TON WALLET</label>
                <input id="claimWemsDestInput" class="claim-wems-input" type="text" autocomplete="off" spellcheck="false" value="<?= h($walletAddr) ?>" placeholder="Destination TON wallet">
              </div>

              <div class="claim-wems-actions">
                <button type="button" class="wallet-btn wallet-btn-primary" id="btnSubmitClaimWems" data-click-sfx>SUBMIT CLAIM</button>
                <button type="button" class="wallet-btn wallet-btn-secondary" id="btnClaimWemsMax" data-click-sfx>MAX</button>
                <button type="button" class="wallet-btn wallet-btn-secondary" data-close-claim-wems data-click-sfx>CLOSE</button>
              </div>

              <div class="claim-wems-status" id="claimWemsStatus" aria-live="polite"></div>
            </div>
          </div>

          <div class="claim-wems-card">
            <div class="claim-wems-mini">
              <div class="claim-wems-row"><div class="left">Source</div><div class="right">Mining-linked Storage bucket</div></div>
              <div class="claim-wems-row"><div class="left">Token</div><div class="right">wEMS</div></div>
              <div class="claim-wems-row"><div class="left">Claim Owner</div><div class="right">Storage Module</div></div>
              <div class="claim-wems-row"><div class="left">KYC</div><div class="right"><?= ((int)($user['is_fully_verified'] ?? 0) === 1) ? 'Verified' : 'Required' ?></div></div>
              <div class="claim-wems-row"><div class="left">Bound TON</div><div class="right"><?= $hasTonBind ? 'Ready' : 'Required' ?></div></div>
              <div class="claim-wems-note">
                Claim uses request-only flow. No browser-side payout happens here.
                Mined wEMS remains linked from Mining into Storage as <b>My Unclaimed Web Gold wEMS</b>.
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

  </main>

  <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php'; ?>
  <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/gt-inline.php'; ?>

  <script>
    window.STORAGE_PAGE_BOOT = {
      lang: <?= json_encode($langDefault, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      userId: <?= json_encode((string)($user['id'] ?? 0), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      wallet: <?= json_encode((string)($user['wallet'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      walletAddress: <?= json_encode($walletAddr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      boundTonAddress: <?= json_encode($walletAddr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      displayName: <?= json_encode($displayName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      hasTonBind: <?= $hasTonBind ? 'true' : 'false' ?>,
      emailVerified: <?= $emailVerified ? 'true' : 'false' ?>,
      isFullyVerified: <?= ((int)($user['is_fully_verified'] ?? 0) === 1) ? 'true' : 'false' ?>,
      lastChainSyncAt: 0,
      syncEnabled: true,
      activation: {
        prepareUrl: "/rwa/api/storage/activate-card/activate-prepare.php",
        verifyUrl: "/rwa/api/storage/activate-card/activate-verify.php",
        confirmUrl: "/rwa/api/storage/activate-card/activate-confirm.php",
        routerUrl: "/rwa/api/storage/activate-card/activate.php"
      },
      storage: {
        balanceUrl: "/rwa/api/storage/balance.php",
        balancesUrl: "/rwa/api/storage/balances.php",
        overviewUrl: "/rwa/api/storage/overview.php",
        addressUrl: "/rwa/api/storage/address.php",
        historyUrl: "/rwa/api/storage/history.php",
        ratesUrl: "/rwa/api/global/rates.php",
        cardModeUrl: "/rwa/api/storage/card-mode.php",
        unclaimedWemsUrl: "/rwa/api/storage/unclaimed-wems.php",
        claimWemsUrl: "/rwa/api/storage/claim-wems.php"
      },
      reload: {
        endpoint: "/rwa/api/storage/reload-card-emx.php",
        verifyEndpoint: "/rwa/api/storage/reload-card-emx-verify.php",
        csrf: <?= json_encode($csrfReload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
      },
      commit: {
        endpoint: "/rwa/api/storage/commit.php",
        csrf: <?= json_encode($csrfCommit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
      },
      csrf: {
        bind: <?= json_encode($csrfBind, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        activate: <?= json_encode($csrfActivate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        reload: <?= json_encode($csrfReload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        commit: <?= json_encode($csrfCommit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        claim: <?= json_encode($csrfClaim, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        claimWems: <?= json_encode($csrfClaimWems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        fuelEmx: <?= json_encode($csrfFuelEmx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        fuelEmxConfirm: <?= json_encode($csrfFuelEmxConfirm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        fuelEms: <?= json_encode($csrfFuelEms, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
      }
    };
  </script>
  <script src="/rwa/inc/core/poado-i18n.js"></script>
  <script src="/rwa/storage/storage.js?v=FINAL-LOCK-6"></script>
  <script src="/rwa/storage/reload-card-emx/helper.js?v=7.6.2"></script>
  <script src="/rwa/storage/commit/helper.js?v=final-lock-1"></script>
  <script>
    (function () {
      var btnOpen = document.getElementById('btnTopupEmx');
      var modal = document.getElementById('reloadCardEmxModal');
      var cardNumberEl = document.getElementById('storageCardNumber');
      var reloadCardInput = document.getElementById('reloadCardNumberInput');

      function syncReloadCardNumber() {
        if (!cardNumberEl || !reloadCardInput) return;
        var raw = (cardNumberEl.textContent || '').replace(/\D+/g, '');
        if (raw) reloadCardInput.value = raw;
      }

      function openModal() {
        if (!modal) return;
        modal.removeAttribute('hidden');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
        syncReloadCardNumber();
      }

      function closeModal() {
        if (!modal) return;
        modal.setAttribute('hidden', 'hidden');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
      }

      if (btnOpen) {
        btnOpen.addEventListener('click', function () {
          openModal();
        });
      }

      document.addEventListener('click', function (e) {
        var target = e.target;
        if (!target || !modal || modal.hasAttribute('hidden')) return;
        if (target.hasAttribute('data-reload-emx-close')) {
          closeModal();
        }
      });

      document.addEventListener('keydown', function (e) {
        if (!modal || modal.hasAttribute('hidden')) return;
        if (e.key === 'Escape') {
          closeModal();
        }
      });

      if (cardNumberEl && reloadCardInput && window.MutationObserver) {
        var observer = new MutationObserver(syncReloadCardNumber);
        observer.observe(cardNumberEl, { childList: true, characterData: true, subtree: true });
      }
    })();

    (function () {
      var boot = window.STORAGE_PAGE_BOOT || {};
      var claimModal = document.getElementById('claimWemsModal');
      var btnClaimWems = document.getElementById('btnClaimWEMS');
      var btnSubmit = document.getElementById('btnSubmitClaimWems');
      var btnMax = document.getElementById('btnClaimWemsMax');
      var availableEl = document.getElementById('claimWemsAvailable');
      var amountEl = document.getElementById('claimWemsAmountInput');
      var destEl = document.getElementById('claimWemsDestInput');
      var statusEl = document.getElementById('claimWemsStatus');
      var sourceDisplayEl = document.getElementById('unclaimWEMS');

      function setStatus(msg, ok) {
        if (!statusEl) return;
        statusEl.textContent = msg || '';
        statusEl.style.color = ok ? '#86efac' : '#d1d5db';
      }

      function openClaimModal() {
        if (!claimModal) return;
        claimModal.removeAttribute('hidden');
        claimModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
        syncAvailableFromPage();
        setStatus('', false);
      }

      function closeClaimModal() {
        if (!claimModal) return;
        claimModal.setAttribute('hidden', 'hidden');
        claimModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
      }

      function parseNum(v) {
        var n = parseFloat(String(v || '').replace(/[^0-9.\-]/g, ''));
        return isNaN(n) ? 0 : n;
      }

      function syncAvailableFromPage() {
        if (!availableEl || !sourceDisplayEl) return;
        availableEl.textContent = sourceDisplayEl.textContent || '0.000000';
      }

      async function readUnclaimedWems() {
        if (!boot.storage || !boot.storage.unclaimedWemsUrl) return;
        try {
          var res = await fetch(boot.storage.unclaimedWemsUrl, { credentials: 'same-origin', cache: 'no-store' });
          var json = await res.json();
          if (json && json.ok) {
            var val = Number(json.unclaimed_wems || 0).toFixed(6);
            if (availableEl) availableEl.textContent = val;
            if (sourceDisplayEl) sourceDisplayEl.textContent = val;
          }
        } catch (e) {}
      }

      async function submitClaim() {
        if (!boot.storage || !boot.storage.claimWemsUrl) {
          setStatus('Claim endpoint not configured.', false);
          return;
        }

        if (!boot.hasTonBind) {
          setStatus('Bind TON wallet first.', false);
          return;
        }

        if (!boot.isFullyVerified) {
          setStatus('KYC required before claiming wEMS.', false);
          return;
        }

        var amount = parseNum(amountEl ? amountEl.value : '');
        var dest = destEl ? String(destEl.value || '').trim() : '';
        if (amount <= 0) {
          setStatus('Enter valid claim amount.', false);
          return;
        }
        if (!dest) {
          setStatus('Destination wallet required.', false);
          return;
        }

        setStatus('Submitting claim request...', false);

        try {
          var fd = new FormData();
          fd.append('amount', String(amount));
          fd.append('destination_wallet', dest);
          if (boot.csrf && boot.csrf.claimWems) {
            fd.append('csrf_token', boot.csrf.claimWems);
          }

          var res = await fetch(boot.storage.claimWemsUrl, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
          });

          var json = await res.json();
          if (!json || !json.ok) {
            throw new Error((json && (json.message || json.error)) || 'Claim request failed');
          }

          setStatus('Claim request created: ' + (json.request_uid || '-'), true);
          await readUnclaimedWems();
        } catch (e) {
          setStatus(String(e.message || e), false);
        }
      }

      if (btnClaimWems) {
        btnClaimWems.addEventListener('click', function () {
          openClaimModal();
          readUnclaimedWems();
        });
      }

      if (btnSubmit) {
        btnSubmit.addEventListener('click', function () {
          submitClaim();
        });
      }

      if (btnMax) {
        btnMax.addEventListener('click', function () {
          if (!amountEl || !availableEl) return;
          amountEl.value = String(parseNum(availableEl.textContent || '0'));
        });
      }

      document.addEventListener('click', function (e) {
        var target = e.target;
        if (!target || !claimModal || claimModal.hasAttribute('hidden')) return;
        if (target.hasAttribute('data-close-claim-wems')) {
          closeClaimModal();
        }
      });

      document.addEventListener('keydown', function (e) {
        if (!claimModal || claimModal.hasAttribute('hidden')) return;
        if (e.key === 'Escape') {
          closeClaimModal();
        }
      });

      readUnclaimedWems();
    })();
  </script>
</body>
</html>
