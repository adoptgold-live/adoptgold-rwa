<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/index.php
 * Version: v9.0.1-20260409-rwa-cert-index-ui-lock
 *
 * MASTER LOCK
 * - Maintain previous visible design layout
 * - verify.php unchanged
 * - local QR baseline unchanged
 * - Check & Preview owner = cert-actions.js
 * - Issue & Pay owner = cert-actions.js
 * - index.php only wires V9 DOM ids / modal hooks / queue hooks
 * - no router architecture revival
 * - UI-only update:
 *   * ACTIVE CERT -> CERT UID
 *   * AMOUNT NANO -> UNIT OF RESPONSIBILITY
 *   * ITEM INDEX -> MEMO / COMMENT OF TON PAYMENT
 *   * visible NFT factory cert value placeholder = —
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
    'version' => 'v9.0.1-20260409-rwa-cert-index-ui-lock',
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
        'mint_ready_queue' => [
            'list'    => 'cert-list-mint-ready',
            'empty'   => 'cert-empty-mint-ready',
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
  <link rel="stylesheet" href="/rwa/cert/cert.css?v=v9.0.1-20260409-rwa-cert-index-ui-lock">
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
        <div class="step-title" data-i18n="step_3">Mint Ready</div>
      </div>
      <div class="step-card cert-stage" id="factoryStep4" data-flow-stage="minting">
        <div class="step-num">4</div>
        <div class="step-title" data-i18n="step_4">Wallet Sign</div>
      </div>
      <div class="step-card cert-stage" id="factoryStep5" data-flow-stage="issued">
        <div class="step-num">5</div>
        <div class="step-title" data-i18n="step_5">Issued</div>
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
      <button type="button" class="factory-action-btn secondary" id="rwaJumpMintBtn" data-i18n="btn_finalize_mint">—</button>
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
  <!-- NFT FACTORY CORE UI -->
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

        <div class="issue-pay-card payment-status--pending" id="issuePayStatus">
          <div class="gold-mini-k" data-i18n="payment_status">Payment Status</div>
          <div class="gold-mini-v" id="issuePayStatusText" data-i18n="waiting_text">Waiting.</div>
        </div>

        <div class="deeplink-box settlement-card">
          <div class="gold-mini-k" data-i18n="wallet_deeplink">Wallet Deeplink</div>
          <div class="gold-mini-v mono">
            <a id="issuePayWalletLink" href="#" target="_blank" rel="noopener" class="wallet-deeplink-link">—</a>
          </div>
        </div>

        <div class="gold-actions" style="margin-top:0;">
          <a class="gold-btn" id="issuePayWalletBtn" href="#" target="_blank" rel="noopener" data-i18n="open_wallet_btn">Open Wallet</a>
          <button type="button" class="gold-btn secondary" id="issuePayCopyLinkBtn">Copy Link</button>
          <button type="button" class="gold-btn secondary" id="issuePayCopyRefBtn" data-i18n="copy_ref_btn">Copy Ref</button>
        </div>

        <div class="gold-actions" style="margin-top:0;">
          <button type="button" class="gold-btn secondary" id="issuePayVerifyBtn" data-i18n="refresh_verify_btn">Refresh Verify</button>
          <button type="button" class="gold-btn secondary" id="issuePayAutoBtn" data-i18n="auto_issue_modal_btn">Auto Issue Tx (5s)</button>
        </div>

        <div class="deeplink-box settlement-card">
          <div class="gold-mini-k">Auto Verify</div>
          <div class="gold-mini-v" id="issuePayAutoVerifyHint">Auto verify can keep checking payment confirmation.</div>
        </div>

      </div>

      <div class="issue-pay-modal__right">
        <div class="settlement-card issue-pay-qr-only" id="issuePayQrWrap">
          <div class="gold-mini-k" data-i18n="payment_qr">Payment QR</div>
          <a id="issuePayQrLink" href="#" target="_blank" rel="noopener">
            <img id="issuePayQrImage" alt="Payment QR" style="display:none;">
          </a>
          <div id="issuePayQrText" class="gold-mini-v mono" style="display:none;white-space:pre-wrap;word-break:break-word;"></div>
          <div id="issuePayQrPlaceholder" class="gold-mini-v" data-i18n="qr_pending">QR pending.</div>
        </div>
      </div>

    </div>
  </div>

  <section class="card-premium section-block">
    <div class="section-kicker">Factory Console Log</div>
    <h2 class="section-title">Factory Console Log</h2>
    <div id="logBox" style="
      min-height:40px;
      font-size:12px;
      opacity:.8;
      background:rgba(255,255,255,0.03);
      border:1px dashed rgba(255,255,255,0.08);
      border-radius:8px;
      padding:10px;
    ">Ready.</div>
    <div id="factoryConsoleLog" hidden></div>
  </section>

</main>
</div>

<?php if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php')) {
    require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php';
} ?>
<?php if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/gt-inline.php')) {
    require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/gt-inline.php';
} ?>

<script src="/rwa/inc/core/poado-i18n.js"></script>
<script src="https://unpkg.com/@tonconnect/ui@2.0.9/dist/tonconnect-ui.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script type="module" src="/rwa/cert/shared/balance-helper.js?v=v2.0.0-20260406-balance-helper-restore"></script>
<script src="/rwa/cert/cert.js?v=v7.4.3-20260407-router-compat-guard"></script>
<script src="/rwa/cert/cert-actions.js?v=500-balance-local-1"></script>
<script src="/rwa/cert/mint-actions.js?v=500"></script>
<script src="/rwa/cert/cert-router.js?v=20260409-mint-ready-fix-2"></script>

</body>
</html>
