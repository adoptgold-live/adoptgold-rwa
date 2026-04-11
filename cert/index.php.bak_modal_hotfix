<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/index.php
 * Version: v10.0.0-20260410-locked-queue-ui
 *
 * MASTER LOCK
 * - Maintain previous visible design layout
 * - verify.php unchanged
 * - local QR baseline unchanged
 * - Check & Preview owner = cert-actions.js
 * - Issue & Pay owner = cert-actions.js
 * - cert.js = translator only
 * - cert-router.js = queue/render/routing only
 * - index.php only wires DOM ids / modal hooks / queue hooks
 * - preserve exact existing DOM ids
 * - add new queue cards only
 *
 * QUEUE ORDER LOCK
 * - Issuance Factory
 * - Payment Confirmation Queue
 * - Payment Confirmed Pending Artifact
 * - Mint Ready Queue
 * - NFT Minting Process
 * - Issued / Minted
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';

if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';
} elseif (file_exists($_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/session-user.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/session-user.php';
}

function h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$session = [];
if (function_exists('session_user')) {
    $tmp = session_user();
    if (is_array($tmp)) {
        $session = $tmp;
    }
} elseif (function_exists('rwa_session_user')) {
    $tmp = rwa_session_user();
    if (is_array($tmp)) {
        $session = $tmp;
    }
}

$currentWallet   = trim((string)($session['wallet_address'] ?? $session['wallet'] ?? ''));
$currentOwnerId  = (int)($session['user_id'] ?? $session['id'] ?? 0);
$currentNickname = trim((string)($session['nickname'] ?? $session['name'] ?? 'RWA User'));

$csrfIssue          = function_exists('csrf_token') ? (string) csrf_token('rwa_cert_issue') : '';
$csrfConfirmPayment = function_exists('csrf_token') ? (string) csrf_token('rwa_cert_confirm_payment') : '';
$csrfRepairNft      = function_exists('csrf_token') ? (string) csrf_token('rwa_cert_repair_nft') : '';
$csrfMintInit       = function_exists('csrf_token') ? (string) csrf_token('rwa_cert_mint_init') : '';
$csrfMintVerify     = function_exists('csrf_token') ? (string) csrf_token('rwa_cert_mint_verify') : '';

$certBoot = [
    'version' => 'v10.0.0-20260410-locked-queue-ui',
    'identity' => [
        'wallet'        => $currentWallet,
        'owner_user_id' => (string) $currentOwnerId,
        'nickname'      => $currentNickname !== '' ? $currentNickname : 'RWA User',
    ],
    'activeBucket'    => 'issuance_factory',
    'activeStage'     => 'issue',
    'selectedCertUid' => null,

    'roots' => [
        'app'             => 'cert-app',
        'shell'           => 'cert-shell-root',
        'header'          => 'cert-header-root',
        'queueSummary'    => 'cert-queue-summary-root',
        'queueColumn'     => 'cert-queue-column-root',
        'queueTabs'       => 'cert-queue-tabs-root',
        'queuePanels'     => 'cert-queue-panels-root',
        'globalStatus'    => 'cert-global-status-root',
        'globalStatusBar' => 'cert-global-status-bar',
        'actionStatus'    => 'cert-action-status-root',
        'actionStatusLog' => 'cert-action-status-log',
        'modalRoot'       => 'cert-modal-root',
        'factoryConsole'  => 'factoryConsoleLog',
    ],

    'selectedContext' => [
        'cert_uid'  => 'cert-selected-cert-uid',
        'cert_code' => 'cert-selected-cert-code',
        'bucket'    => 'cert-selected-cert-bucket',
        'stage'     => 'cert-selected-cert-stage',
    ],

    'buckets' => [
        'issuance_factory' => [
            'list'    => 'cert-list-issuance-factory',
            'empty'   => 'cert-empty-issuance-factory',
            'summary' => 'cert-summary-card-issuance-factory',
            'tab'     => 'cert-tab-issuance-factory',
        ],
        'payment_confirmation' => [
            'list'    => 'paymentConfirmationQueueList',
            'empty'   => 'paymentConfirmationQueueEmpty',
            'summary' => 'paymentConfirmationQueueCard',
            'tab'     => 'paymentConfirmationQueueCard',
        ],
        'payment_confirmed_pending_artifact' => [
            'list'    => 'paymentConfirmedPendingArtifactList',
            'empty'   => 'paymentConfirmedPendingArtifactEmpty',
            'summary' => 'paymentConfirmedPendingArtifactCard',
            'tab'     => 'paymentConfirmedPendingArtifactCard',
        ],
        'mint_ready_queue' => [
            'list'    => 'cert-list-mint-ready',
            'empty'   => 'mintReadyEmpty',
            'summary' => 'cert-summary-card-mint-ready',
            'tab'     => 'cert-tab-mint-ready',
        ],
        'minting_process' => [
            'list'    => 'cert-list-minting-process',
            'empty'   => 'cert-empty-minting-process',
            'summary' => 'cert-summary-card-minting-process',
            'tab'     => 'cert-tab-minting-process',
        ],
        'issued' => [
            'list'    => 'cert-list-issued',
            'empty'   => 'cert-empty-issued',
            'summary' => 'cert-summary-card-issued',
            'tab'     => 'cert-tab-issued',
        ],
        'blocked' => [
            'list'    => 'cert-list-blocked',
            'empty'   => 'cert-empty-blocked',
            'summary' => 'cert-summary-card-blocked',
            'tab'     => 'cert-tab-blocked',
        ],
    ],

    'endpoints' => [
        'issue'           => '/rwa/cert/api/issue.php',
        'issuePay'        => '/rwa/cert/api/issue-pay.php',
        'confirmPayment'  => '/rwa/cert/api/confirm-payment.php',
        'repairNft'       => '/rwa/cert/api/repair-nft.php',
        'verifyStatus'    => '/rwa/cert/api/verify-status.php',
        'mintInit'        => '/rwa/cert/api/mint-init.php',
        'mintVerify'      => '/rwa/cert/api/mint-verify.php',
        'queueSummary'    => '/rwa/cert/api/queue-summary.php',
        'certDetail'      => '/rwa/cert/api/cert-detail.php',
        'balanceLocal'    => '/rwa/cert/api/balance-local.php',
        'verifyTool'      => '/rwa/cert/verify.php',
        'storageOverview' => '/rwa/api/storage/overview.php',
        'tonManifestUrl'  => 'https://adoptgold.app/tonconnect-manifest.json',
    ],

    'csrf' => [
        'issue'          => $csrfIssue,
        'confirmPayment' => $csrfConfirmPayment,
        'repairNft'      => $csrfRepairNft,
        'mintInit'       => $csrfMintInit,
        'mintVerify'     => $csrfMintVerify,
    ],
];
?><!doctype html>
<html lang="en" data-lang="en">
<head>
  <meta charset="utf-8">
  <title>RWA Cert Issuance Settlement</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#0a0c12">
  <meta name="color-scheme" content="dark">
  <link rel="stylesheet" href="/rwa/cert/cert.css?v=v10.0.0-20260410-locked-queue-ui">
</head>
<body class="lang-en">
<?php if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php')) {
    require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php';
} ?>

<input type="hidden" id="currentWallet" value="<?= h($currentWallet) ?>">
<input type="hidden" id="currentOwnerId" value="<?= h((string) $currentOwnerId) ?>">
<input type="hidden" id="currentNickname" value="<?= h($currentNickname) ?>">

<input type="hidden" id="endpointIssue" value="/rwa/cert/api/issue.php">
<input type="hidden" id="endpointIssuePay" value="/rwa/cert/api/issue-pay.php">
<input type="hidden" id="endpointConfirmPayment" value="/rwa/cert/api/confirm-payment.php">
<input type="hidden" id="endpointRepairNft" value="/rwa/cert/api/repair-nft.php">
<input type="hidden" id="endpointVerifyStatus" value="/rwa/cert/api/verify-status.php">
<input type="hidden" id="endpointMintInit" value="/rwa/cert/api/mint-init.php">
<input type="hidden" id="endpointMintVerify" value="/rwa/cert/api/mint-verify.php">
<input type="hidden" id="endpointQueueSummary" value="/rwa/cert/api/queue-summary.php">
<input type="hidden" id="endpointCertDetail" value="/rwa/cert/api/cert-detail.php">
<input type="hidden" id="endpointVerifyTool" value="/rwa/cert/verify.php">
<input type="hidden" id="endpointStorageOverview" value="/rwa/api/storage/overview.php">
<input type="hidden" id="endpointBalanceLocal" value="/rwa/cert/api/balance-local.php">
<input type="hidden" id="tonManifestUrl" value="https://adoptgold.app/tonconnect-manifest.json">

<input type="hidden" id="csrfIssue" value="<?= h($csrfIssue) ?>">
<input type="hidden" id="csrfConfirmPayment" value="<?= h($csrfConfirmPayment) ?>">
<input type="hidden" id="csrfRepairNft" value="<?= h($csrfRepairNft) ?>">
<input type="hidden" id="csrfMintInit" value="<?= h($csrfMintInit) ?>">
<input type="hidden" id="csrfMintVerify" value="<?= h($csrfMintVerify) ?>">

<textarea id="certBootPayload" hidden><?= h(json_encode($certBoot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></textarea>

<div id="cert-app">
<main class="cert-shell" id="cert-shell-root">

  <section class="card-premium section-block">
    <div class="section-head" id="cert-header-root">
      <div>
        <div class="section-kicker" data-i18n="storage_kicker">Storage</div>
        <h1 class="section-title" data-i18n="storage_title">My Gold Mining Storage Balance</h1>
        <p class="section-sub" data-i18n="storage_sub">
          Live on-chain balances used for business payment and TON settlement readiness.
        </p>
      </div>
      <div class="lang-switch">
        <button type="button" class="lang-btn is-active" id="langBtnEn">EN</button>
        <button type="button" class="lang-btn" id="langBtnZh">中</button>
      </div>
    </div>

    <div class="storage-grid">
      <div class="balance-box balance-wems">
        <div class="balance-icon"></div>
        <div class="balance-main">
          <div class="balance-k">wEMS</div>
          <div class="balance-v" id="balanceWemsText">—</div>
          <div class="balance-sub" data-i18n="storage_wems_sub">On-chain Genesis mint balance</div>
        </div>
      </div>

      <div class="balance-box balance-ema">
        <div class="balance-icon"></div>
        <div class="balance-main">
          <div class="balance-k">EMA$</div>
          <div class="balance-v" id="balanceEmaText">—</div>
          <div class="balance-sub" data-i18n="storage_ema_sub">On-chain Secondary / Tertiary mint balance</div>
        </div>
      </div>

      <div class="balance-box balance-ton">
        <div class="balance-icon"></div>
        <div class="balance-main">
          <div class="balance-k">TON Gas</div>
          <div class="balance-v" id="balanceTonText">—</div>
          <div class="balance-sub">
            <span data-i18n="storage_ton_sub_prefix">Mint Gas Ready:</span>
            <span id="balanceTonGas">Checking...</span>
          </div>
        </div>
      </div>
    </div>
    <div id="mintResultBanner" class="mint-result-banner" style="display:none;"></div>
  </section>

  <section class="card-premium section-block" id="rwaFactorySection">
    <div class="section-head">
      <div>
        <div class="section-kicker" data-i18n="factory_kicker">RWA FACTORY</div>
        <h2 class="section-title" data-i18n="factory_title">RWA Cert Issuance Settlement</h2>
        <p class="section-sub" data-i18n="factory_sub">
          Business payment only. Already paid certs move out of this section and appear only in Mint Ready Queue / NFT Factory.
        </p>
      </div>

      <div class="factory-user">
        <div class="factory-user-k" data-i18n="operator_label">Operator</div>
        <div class="factory-user-v"><?= h($currentNickname !== '' ? $currentNickname : 'RWA User') ?></div>
        <div class="factory-user-s mono"><?= h($currentWallet !== '' ? $currentWallet : '-') ?></div>
      </div>
    </div>

    <div class="factory-steps" id="certFlowProgress">
      <div class="step-card cert-stage" id="factoryStep1" data-flow-stage="preview">
        <div class="step-num">1</div>
        <div class="step-title" data-i18n="step_1">Check &amp; Preview</div>
      </div>
      <div class="step-card cert-stage" id="factoryStep2" data-flow-stage="payment">
        <div class="step-num">2</div>
        <div class="step-title" data-i18n="step_2">Business Payment</div>
      </div>
      <div class="step-card cert-stage" id="factoryStep3" data-flow-stage="mint_ready">
        <div class="step-num">3</div>
        <div class="step-title">Mint Ready</div>
      </div>
      <div class="step-card cert-stage" id="factoryStep4" data-flow-stage="minting">
        <div class="step-num">4</div>
        <div class="step-title" data-i18n="step_4">Wallet Sign</div>
      </div>
      <div class="step-card cert-stage" id="factoryStep5" data-flow-stage="issued">
        <div class="step-num">5</div>
        <div class="step-title">Issued</div>
      </div>
    </div>

    <div class="progress-wrap">
      <div class="progress-track">
        <div class="progress-fill" id="activeProgressFill" style="width:0%"></div>
      </div>
      <div class="progress-label" id="activeProgressLabel">0 / 5</div>
    </div>

    <div class="factory-action-row">
      <button type="button" class="factory-action-btn" id="rwaIssuePayBtn" data-i18n="btn_issue_pay">Issue &amp; Pay</button>
      <button type="button" class="factory-action-btn secondary" id="rwaAutoIssueBtn" data-i18n="btn_auto_issue">Auto Issue Tx 5s</button>
      <button type="button" class="factory-action-btn secondary" id="rwaJumpMintBtn" data-i18n="btn_finalize_mint">Finalize Mint</button>
    </div>

    <div class="active-card" id="certActivePanel">
      <div class="active-top">
        <div class="active-pill" id="activeFamilyPill">—</div>
        <div class="active-status" id="activeStatusText">IDLE</div>
      </div>

      <div class="active-name" id="activeName" data-i18n="active_empty_title">No payment-pending cert yet</div>
      <div class="active-code" id="activeCode">Factory Waiting</div>
      <div class="active-sub" id="activeSub" data-i18n="active_empty_sub">Choose any of the 8 RWA cards and click Check &amp; Preview.</div>

      <div class="active-grid">
        <div class="mini-card">
          <div class="mini-k" data-i18n="mini_cert_uid">Cert UID</div>
          <div class="mini-v mono" id="activeCertUid">—</div>
        </div>
        <div class="mini-card">
          <div class="mini-k" data-i18n="mini_payment">Payment</div>
          <div class="mini-v" id="activePaymentText">—</div>
        </div>
        <div class="mini-card">
          <div class="mini-k" data-i18n="mini_payment_ref">Payment Ref</div>
          <div class="mini-v mono" id="activePaymentRef">—</div>
        </div>
        <div class="mini-card">
          <div class="mini-k" data-i18n="mini_nft_item">NFT Item</div>
          <div class="mini-v mono" id="activeNftItem">—</div>
        </div>
      </div>

      <div class="next-banner" id="nextStepBanner" data-i18n="next_step_default">
        Next step will highlight automatically when previous step is done.
      </div>

      <div id="certNextStepBanner" class="compat-hidden" aria-hidden="true"></div>
    </div>

    <!-- NEW QUEUE 1 -->
    <section id="paymentConfirmationQueueCard" class="queue-head">
      <div class="section-kicker" id="paymentConfirmationQueueTitle">PAYMENT CONFIRMATION QUEUE</div>
      <div class="section-sub" id="paymentConfirmationQueueHint">
        Business payment done but not yet verified for mint readiness.
      </div>
      <div class="mint-ready-list">
        <div style="display:flex;justify-content:flex-end;align-items:center;margin:0 0 8px 0;">
          <span id="paymentConfirmationQueueCount" class="active-status">0</span>
        </div>
        <div id="paymentConfirmationQueueList"></div>
        <div class="queue-empty" id="paymentConfirmationQueueEmpty" hidden>No payment confirmation items.</div>
      </div>
    </section>

    <!-- NEW QUEUE 2 -->
    <section id="paymentConfirmedPendingArtifactCard" class="queue-head">
      <div class="section-kicker" id="paymentConfirmedPendingArtifactTitle">PAYMENT CONFIRMED PENDING ARTIFACT</div>
      <div class="section-sub" id="paymentConfirmedPendingArtifactHint">
        Payment confirmed, but required mint artifacts are not ready yet.
      </div>
      <div class="mint-ready-list">
        <div style="display:flex;justify-content:flex-end;align-items:center;margin:0 0 8px 0;">
          <span id="paymentConfirmedPendingArtifactCount" class="active-status">0</span>
        </div>
        <div id="paymentConfirmedPendingArtifactList"></div>
        <div class="queue-empty" id="paymentConfirmedPendingArtifactEmpty" hidden>No payment-confirmed pending-artifact items.</div>
      </div>
    </section>

    <!-- EXISTING MINT READY -->
    <div class="queue-head">
      <div class="section-kicker" data-i18n="mint_queue_kicker">Mint Ready Queue</div>
      <div class="section-sub" data-i18n="mint_queue_sub">
        Only payment-confirmed and mint-ready certs appear here.
      </div>
    </div>

    <div class="mint-ready-list" id="mintReadyQueueList">
      <div id="cert-list-mint-ready"></div>
      <div class="queue-empty" id="mintReadyEmpty" data-i18n="mint_queue_empty">No mint-ready cert yet.</div>
    </div>
  </section>

  <section class="card-premium section-block">
    <div class="section-kicker">Genesis x4</div>
    <h2 class="section-title">Genesis</h2>

    <div class="card-grid grid-4">
      <article class="rwa-card" data-rule-key="green" data-rwa-type="green">
        <div class="rwa-card-top"><div class="rwa-icon">🍃</div><div class="rwa-badge">GENESIS</div></div>
        <div class="rwa-name">Green</div>
        <div class="rwa-code">RCO2C-EMA</div>
        <div class="rwa-meta">
          <div class="rwa-line"><span class="k">Token</span><span class="v">wEMS</span></div>
          <div class="rwa-line"><span class="k">Amount</span><span class="v">1000</span></div>
          <div class="rwa-line"><span class="k">Weight</span><span class="v">1</span></div>
          <div class="rwa-line"><span class="k">Unit of Responsibility</span><span class="v">10 kg tCO2e</span></div>
        </div>
        <div class="rwa-rule-note" id="ruleNote-green"></div>
        <button type="button" class="btn-premium issue-btn" id="issueBtn-green" data-action="check-preview" data-i18n="btn_check_preview">Check &amp; Preview</button>
      </article>

      <article class="rwa-card" data-rule-key="blue" data-rwa-type="blue">
        <div class="rwa-card-top"><div class="rwa-icon">💧</div><div class="rwa-badge">GENESIS</div></div>
        <div class="rwa-name">Blue</div>
        <div class="rwa-code">RH2O-EMA</div>
        <div class="rwa-meta">
          <div class="rwa-line"><span class="k">Token</span><span class="v">wEMS</span></div>
          <div class="rwa-line"><span class="k">Amount</span><span class="v">5000</span></div>
          <div class="rwa-line"><span class="k">Weight</span><span class="v">2</span></div>
          <div class="rwa-line"><span class="k">Unit of Responsibility</span><span class="v">100 liters or m³</span></div>
        </div>
        <div class="rwa-rule-note" id="ruleNote-blue"></div>
        <button type="button" class="btn-premium issue-btn" id="issueBtn-blue" data-action="check-preview" data-i18n="btn_check_preview">Check &amp; Preview</button>
      </article>

      <article class="rwa-card" data-rule-key="black" data-rwa-type="black">
        <div class="rwa-card-top"><div class="rwa-icon">⚫</div><div class="rwa-badge">GENESIS</div></div>
        <div class="rwa-name">Black</div>
        <div class="rwa-code">RBLACK-EMA</div>
        <div class="rwa-meta">
          <div class="rwa-line"><span class="k">Token</span><span class="v">wEMS</span></div>
          <div class="rwa-line"><span class="k">Amount</span><span class="v">10000</span></div>
          <div class="rwa-line"><span class="k">Weight</span><span class="v">3</span></div>
          <div class="rwa-line"><span class="k">Unit of Responsibility</span><span class="v">1 MWh or energy-unit</span></div>
        </div>
        <div class="rwa-rule-note" id="ruleNote-black"></div>
        <button type="button" class="btn-premium issue-btn" id="issueBtn-black" data-action="check-preview" data-i18n="btn_check_preview">Check &amp; Preview</button>
      </article>

      <article class="rwa-card" data-rule-key="gold" data-rwa-type="gold">
        <div class="rwa-card-top"><div class="rwa-icon">🏅</div><div class="rwa-badge">GENESIS</div></div>
        <div class="rwa-name">Gold</div>
        <div class="rwa-code">RK92-EMA</div>
        <div class="rwa-meta">
          <div class="rwa-line"><span class="k">Token</span><span class="v">wEMS</span></div>
          <div class="rwa-line"><span class="k">Amount</span><span class="v">50000</span></div>
          <div class="rwa-line"><span class="k">Weight</span><span class="v">5</span></div>
          <div class="rwa-line"><span class="k">Unit of Responsibility</span><span class="v">1 gram Gold Nugget</span></div>
        </div>
        <div class="rwa-rule-note" id="ruleNote-gold"></div>
        <button type="button" class="btn-premium issue-btn" id="issueBtn-gold" data-action="check-preview" data-i18n="btn_check_preview">Check &amp; Preview</button>
      </article>
    </div>
  </section>

  <section class="card-premium section-block">
    <div class="section-kicker">Secondary x3</div>
    <h2 class="section-title">Secondary</h2>

    <div class="card-grid grid-3">
      <article class="rwa-card" data-rule-key="pink" data-rwa-type="pink">
        <div class="rwa-card-top"><div class="rwa-icon">❤</div><div class="rwa-badge">SECONDARY</div></div>
        <div class="rwa-name">Health</div>
        <div class="rwa-code">RLIFE-EMA</div>
        <div class="rwa-meta">
          <div class="rwa-line"><span class="k">Token</span><span class="v">EMA$</span></div>
          <div class="rwa-line"><span class="k">Amount</span><span class="v">100</span></div>
          <div class="rwa-line"><span class="k">Weight</span><span class="v">10</span></div>
          <div class="rwa-line"><span class="k">Unit of Responsibility</span><span class="v">1 day health-right unit by BMI</span></div>
        </div>
        <div class="rwa-rule-note" id="ruleNote-pink"></div>
        <button type="button" class="btn-premium issue-btn" id="issueBtn-pink" data-action="check-preview" data-i18n="btn_check_preview">Check &amp; Preview</button>
      </article>

      <article class="rwa-card" data-rule-key="red" data-rwa-type="red">
        <div class="rwa-card-top"><div class="rwa-icon">✈</div><div class="rwa-badge">SECONDARY</div></div>
        <div class="rwa-name">Travel</div>
        <div class="rwa-code">RTRIP-EMA</div>
        <div class="rwa-meta">
          <div class="rwa-line"><span class="k">Token</span><span class="v">EMA$</span></div>
          <div class="rwa-line"><span class="k">Amount</span><span class="v">100</span></div>
          <div class="rwa-line"><span class="k">Weight</span><span class="v">10</span></div>
          <div class="rwa-line"><span class="k">Unit of Responsibility</span><span class="v">1 km travel-right unit</span></div>
        </div>
        <div class="rwa-rule-note" id="ruleNote-red"></div>
        <button type="button" class="btn-premium issue-btn" id="issueBtn-red" data-action="check-preview" data-i18n="btn_check_preview">Check &amp; Preview</button>
      </article>

      <article class="rwa-card" data-rule-key="royal_blue" data-rwa-type="royal_blue">
        <div class="rwa-card-top"><div class="rwa-icon">🏢</div><div class="rwa-badge">SECONDARY</div></div>
        <div class="rwa-name">Property</div>
        <div class="rwa-code">RPROP-EMA</div>
        <div class="rwa-meta">
          <div class="rwa-line"><span class="k">Token</span><span class="v">EMA$</span></div>
          <div class="rwa-line"><span class="k">Amount</span><span class="v">100</span></div>
          <div class="rwa-line"><span class="k">Weight</span><span class="v">10</span></div>
          <div class="rwa-line"><span class="k">Unit of Responsibility</span><span class="v">1 ft² property-right unit</span></div>
        </div>
        <div class="rwa-rule-note" id="ruleNote-royal_blue"></div>
        <button type="button" class="btn-premium issue-btn" id="issueBtn-royal_blue" data-action="check-preview" data-i18n="btn_check_preview">Check &amp; Preview</button>
      </article>
    </div>
  </section>

  <section class="card-premium section-block">
    <div class="section-kicker">Tertiary x1</div>
    <h2 class="section-title">Tertiary</h2>

    <div class="card-grid grid-1">
      <article class="rwa-card" data-rule-key="yellow" data-rwa-type="yellow">
        <div class="rwa-card-top"><div class="rwa-icon">👷</div><div class="rwa-badge">TERTIARY</div></div>
        <div class="rwa-name">Human Resources</div>
        <div class="rwa-code">RHRD-EMA</div>
        <div class="rwa-meta">
          <div class="rwa-line"><span class="k">Token</span><span class="v">EMA$</span></div>
          <div class="rwa-line"><span class="k">Amount</span><span class="v">100</span></div>
          <div class="rwa-line"><span class="k">Weight</span><span class="v">10</span></div>
          <div class="rwa-line"><span class="k">Unit of Responsibility</span><span class="v">10 hours Labor Contribution</span></div>
        </div>
        <div class="rwa-rule-note" id="ruleNote-yellow"></div>
        <button type="button" class="btn-premium issue-btn" id="issueBtn-yellow" data-action="check-preview" data-i18n="btn_check_preview">Check &amp; Preview</button>
      </article>
    </div>
  </section>

  <section class="gold-factory section-block" id="nftFactorySection">
    <div class="gold-inner">
      <div class="section-kicker" data-i18n="nft_kicker">NFT FACTORY</div>
      <h2 class="section-title" data-i18n="nft_title">NFT Minting Process Settlement</h2>
      <p class="section-sub" data-i18n="nft_sub">
        Mint only. Prepare opens TON wallet directly. QR is hidden to avoid settlement confusion.
      </p>

      <div class="gold-main nft-settlement-layout">
        <div class="gold-left">
          <div class="gold-grid">
            <div class="gold-mini">
              <div class="gold-mini-k">CERT UID</div>
              <div class="gold-mini-v mono" id="certMintTitle">—</div>
            </div>
            <div class="gold-mini compat-hidden">
              <div class="gold-mini-k">Mint Cert UID</div>
              <div class="gold-mini-v mono" id="activeMintCertUid">—</div>
            </div>
            <div class="gold-mini">
              <div class="gold-mini-k" data-i18n="mint_recipient">Recipient</div>
              <div class="gold-mini-v mono" id="certMintRecipient">—</div>
            </div>
            <div class="gold-mini">
              <div class="gold-mini-k" data-i18n="mint_amount_ton">Amount TON</div>
              <div class="gold-mini-v">
                <span class="pay-token-wrap">
                  <img id="certMintTokenImg" class="pay-token-img" src="" alt="TON" style="display:none;">
                  <span id="certMintAmount">—</span>
                </span>
              </div>
            </div>
            <div class="gold-mini">
              <div class="gold-mini-k">UNIT OF RESPONSIBILITY</div>
              <div class="gold-mini-v mono" id="certMintAmountNano">—</div>
            </div>
            <div class="gold-mini">
              <div class="gold-mini-k">MEMO / COMMENT OF TON PAYMENT</div>
              <div class="gold-mini-v mono" id="certMintItemIndex">—</div>
            </div>
            <div class="gold-mini">
              <div class="gold-mini-k" data-i18n="mint_status">Mint Status</div>
              <div class="gold-mini-v" id="certMintStatusText">READY</div>
            </div>
          </div>

          <div class="gold-payload">
            <div class="gold-mini-k" data-i18n="mint_payload">Payload</div>
            <div class="gold-mini-v mono" id="certMintPayloadMini">—</div>
          </div>
        </div>

        <div class="gold-right">
          <div class="deeplink-box settlement-card">
            <div class="gold-mini-k" data-i18n="settlement_flow">Settlement Flow</div>
            <div class="gold-mini-v" data-i18n="settlement_flow_text">
              Step 1 prepares mint payload and opens TON wallet directly.<br>
              Step 2 refreshes on-chain verify loop.<br>
              Step 3 confirms issued state.
            </div>
          </div>

          <div class="deeplink-box settlement-card">
            <div class="gold-mini-k" data-i18n="wallet_deeplink">Wallet Deeplink</div>
            <div class="gold-mini-v mono" id="certMintDeeplink">—</div>
          </div>

          <div class="deeplink-box settlement-card">
            <div class="gold-mini-k" data-i18n="settlement_status">Settlement Status</div>
            <div class="gold-mini-v" id="certMintQrMeta">Wallet settlement waiting.</div>
          </div>
        </div>
      </div>

      <div class="gold-actions">
        <button type="button" class="gold-btn finalize-mint-btn" id="certMintPrepareBtn" data-action="finalize-mint" data-i18n="mint_btn_prepare">Step 1 · Prepare &amp; Mint Now</button>
        <button type="button" class="gold-btn secondary" id="certMintAutoBtn" data-i18n="mint_btn_auto">Step 2 · Auto Mint Tx (5s)</button>
        <button type="button" class="gold-btn secondary compat-hidden" id="certMintRefreshBtn">Refresh Mint Status</button>
        <a class="gold-btn secondary compat-hidden" id="certMintWalletBtn" href="#" target="_blank" rel="noopener">Open Wallet</a>
        <a class="gold-btn secondary" id="certMintGetgemsBtn" href="#" target="_blank" rel="noopener" data-i18n="mint_btn_verify">Step 3 · Verify at Getgems.io</a>
      </div>

      <div id="mintFactoryIndicators" class="mint-factory-indicators">
        <div id="mintRealtimeBar"><div id="mintRealtimeBarFill"></div></div>
        <div id="mintLiveTxStatus">Waiting for wallet…</div>
        <div id="mintStepLadder"></div>
        <div id="mintCountdown"></div>
        <div id="mintConfidenceBadges"></div>
      </div>

      <div class="tonconnect-mount" id="tonConnectMount"></div>

      <div class="compat-hidden" aria-hidden="true">
        <div id="certMintQrWrap" style="display:none !important;"></div>
        <img id="certMintQrImg" alt="Mint QR" style="display:none !important;">
        <div id="certMintQrPlaceholder" style="display:none !important;">QR hidden</div>
      </div>

      <div id="mintFlowPanel"></div>
      <div id="mintSuccessBanner" class="cert-success-banner" style="display:none;"></div>
    </div>
  </section>

  <div class="pay-modal-backdrop issue-pay-modal" id="issuePayModal" aria-hidden="true">
    <div class="pay-modal-card issue-pay-modal__dialog">

      <div class="pay-modal-head issue-pay-modal__head">
        <div>
          <div class="section-kicker" data-i18n="business_payment">BUSINESS PAYMENT</div>
          <h3 class="section-title" style="margin:0;" data-i18n="issue_pay_title">Issue &amp; Pay</h3>
        </div>
        <button type="button" class="gold-btn secondary" id="issuePayCloseBtn" data-i18n="close_btn">Close</button>
      </div>

      <div class="issue-pay-modal__left">
        <div class="issue-pay-card">
          <div class="gold-mini-k" data-i18n="mini_cert_uid">Cert UID</div>
          <div class="gold-mini-v mono" id="issuePayCertUid">—</div>
        </div>

        <div class="issue-pay-card">
          <div class="gold-mini-k" data-i18n="modal_token">Token</div>
          <div class="gold-mini-v">
            <span class="pay-token-wrap">
              <img id="issuePayTokenImg" class="pay-token-img" src="" alt="Token" style="display:none;">
              <span id="issuePayToken">—</span>
            </span>
          </div>
        </div>

        <div class="issue-pay-card">
          <div class="gold-mini-k" data-i18n="modal_amount">Amount</div>
          <div class="gold-mini-v" id="issuePayAmount">—</div>
        </div>

        <div class="issue-pay-card">
          <div class="gold-mini-k" data-i18n="mini_payment_ref">Payment Ref</div>
          <div class="gold-mini-v mono" id="issuePayRef">—</div>
        </div>

        <div class="issue-pay-card" id="issuePayStatus">
          <div class="gold-mini-k" data-i18n="payment_status">Payment Status</div>
          <div class="gold-mini-v" id="issuePayStatusText" data-i18n="waiting_text">Waiting.</div>
        </div>

        <div class="deeplink-box">
          <div class="gold-mini-k" data-i18n="wallet_deeplink">Wallet Deeplink</div>
          <div class="gold-mini-v mono">
            <a id="issuePayWalletLink" class="wallet-deeplink-link" href="#" target="_blank" rel="noopener">—</a>
          </div>
        </div>

        <div class="gold-actions">
          <a class="gold-btn" id="issuePayWalletBtn" href="#" target="_blank" rel="noopener" data-i18n="open_wallet_btn">Open Wallet</a>
          <button type="button" class="gold-btn secondary" id="issuePayCopyRefBtn" data-i18n="copy_ref_btn">Copy Ref</button>
          <button type="button" class="gold-btn secondary" id="issuePayVerifyBtn" data-i18n="refresh_verify_btn">Refresh Verify</button>
          <button type="button" class="gold-btn secondary" id="issuePayAutoBtn" data-i18n="auto_issue_modal_btn">Auto Issue Tx (5s)</button>
        </div>
      </div>

      <div class="issue-pay-modal__right">
        <div class="issue-pay-card issue-pay-qr-only">
          <div class="gold-mini-k" data-i18n="payment_qr">Payment QR</div>
          <div id="issuePayQrWrapInner">
            <a id="issuePayQrLink" href="#" target="_blank" rel="noopener">
              <img id="issuePayQrImage" alt="Payment QR">
            </a>
            <div id="issuePayQrText"></div>
            <div id="issuePayQrPlaceholder" data-i18n="qr_pending">QR pending.</div>
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- existing hidden router / summary roots preserved -->
  <div id="cert-global-status-root" hidden><div id="cert-global-status-bar"></div></div>
  <div id="cert-queue-summary-root" hidden></div>
  <div id="cert-workspace-root" hidden>
    <div id="cert-queue-column-root">
      <div id="cert-queue-tabs-root">
        <button id="cert-tab-issuance-factory">Issuance</button>
        <button id="cert-tab-mint-ready">Mint Ready</button>
        <button id="cert-tab-minting-process">Minting</button>
        <button id="cert-tab-issued">Issued</button>
        <button id="cert-tab-blocked">Blocked</button>
      </div>
      <div id="cert-queue-panels-root">
        <div id="cert-panel-issuance-factory">
          <div id="cert-panel-count-issuance-factory">0</div>
          <div id="cert-list-issuance-factory"></div>
          <div id="cert-empty-issuance-factory"></div>
        </div>
        <div id="cert-panel-mint-ready">
          <div id="cert-panel-count-mint-ready">0</div>
        </div>
        <div id="cert-panel-minting-process">
          <div id="cert-panel-count-minting-process">0</div>
          <div id="cert-list-minting-process"></div>
          <div id="cert-empty-minting-process"></div>
        </div>
        <div id="cert-panel-issued">
          <div id="cert-panel-count-issued">0</div>
          <div id="cert-list-issued"></div>
          <div id="cert-empty-issued"></div>
        </div>
        <div id="cert-panel-blocked">
          <div id="cert-panel-count-blocked">0</div>
          <div id="cert-list-blocked"></div>
          <div id="cert-empty-blocked"></div>
        </div>
      </div>
    </div>
    <div id="cert-stage-column-root">
      <div id="cert-stage-context-root"></div>
    </div>
  </div>

  <div id="cert-selected-context-root" hidden>
    <div id="cert-selected-cert-uid"></div>
    <div id="cert-selected-cert-code"></div>
    <div id="cert-selected-cert-bucket"></div>
    <div id="cert-selected-cert-stage"></div>
  </div>

  <div id="cert-action-status-root" hidden><div id="cert-action-status-log"></div></div>
  <div id="cert-modal-root" hidden></div>

  <section class="card-premium section-block">
    <div class="section-kicker" data-i18n="console_kicker">Factory Console Log</div>
    <h2 class="section-title" data-i18n="console_title">Factory Console Log</h2>
    <div class="log-box" id="factoryConsoleLog"></div>
  </section>

</main>
</div>

<?php if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php')) {
    require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php';
} ?>

<!-- JS ROLE LOCK -->
<!-- 1) cert.js        = translator only -->
<!-- 2) cert-router.js = queue/render/routing only -->
<!-- 3) cert-actions.js = business actions only -->
<script src="/rwa/cert/cert.js?v=v7.5.0-20260410-translator-only-global-lock"></script>
<script src="/rwa/cert/cert-router.js?v=v10.2.0-20260410-pure-router-global-lock"></script>
<script src="/rwa/cert/cert-actions.js?v=v25.0.0-20260410-global-role-lock"></script>
</body>
</html>
