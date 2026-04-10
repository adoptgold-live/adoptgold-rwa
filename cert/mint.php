<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/mint.php
 * Version: v1.1.0-20260329-verify-json-sync
 * Changelog:
 * - NFT health now uses verify/verify.json as the single source of truth
 * - removed independent UI artifact recomputation logic
 * - Finalize Mint button only enables when payment is confirmed and NFT is ready
 * - added compact EN/ZH language switcher support
 * - preserved standalone RWA shell structure (topbar + bottom nav)
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

$user = rwa_require_login();

function h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function cert_status_badge_class(string $status): string
{
    $s = strtolower(trim($status));
    return match ($s) {
        'confirmed', 'paid', 'issued', 'ready', 'mint_ready', 'minted' => 'is-green',
        'pending', 'payment_pending', 'mint_pending' => 'is-amber',
        'failed', 'broken', 'revoked', 'error' => 'is-red',
        default => 'is-dim',
    };
}

$uid = trim((string)($_GET['uid'] ?? ''));
if ($uid === '') {
    http_response_code(400);
    echo 'Missing cert uid';
    exit;
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo 'Database not ready';
    exit;
}

$cert = null;
$payment = null;
$certMeta = [];

try {
    $st = $pdo->prepare("
        SELECT
            c.*
        FROM poado_rwa_certs c
        WHERE c.cert_uid = :uid
        LIMIT 1
    ");
    $st->execute([':uid' => $uid]);
    $cert = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $cert = null;
}

if (!$cert) {
    http_response_code(404);
    echo 'Certificate not found';
    exit;
}

try {
    $st = $pdo->prepare("
        SELECT *
        FROM poado_rwa_cert_payments
        WHERE cert_uid = :uid
        ORDER BY id DESC
        LIMIT 1
    ");
    $st->execute([':uid' => $uid]);
    $payment = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $payment = null;
}

$certMeta = [];
if (!empty($cert['meta_json'])) {
    $decoded = json_decode((string)$cert['meta_json'], true);
    if (is_array($decoded)) {
        $certMeta = $decoded;
    }
}

$paymentMeta = [];
if ($payment && !empty($payment['meta_json'])) {
    $decoded = json_decode((string)$payment['meta_json'], true);
    if (is_array($decoded)) {
        $paymentMeta = $decoded;
    }
}

/**
 * Canonical artifact base detection.
 * Keep this tolerant because stored paths may differ between rows/versions.
 */
$artifactBase = '';
$candidates = [
    $certMeta['artifact_base'] ?? null,
    $certMeta['vault_local_base'] ?? null,
    $certMeta['local_artifact_base'] ?? null,
    $paymentMeta['artifact_base'] ?? null,
    $paymentMeta['vault_local_base'] ?? null,
    $paymentMeta['local_artifact_base'] ?? null,
];

foreach ($candidates as $candidate) {
    $candidate = is_string($candidate) ? trim($candidate) : '';
    if ($candidate !== '' && is_dir($candidate)) {
        $artifactBase = rtrim($candidate, '/');
        break;
    }
}

if ($artifactBase === '') {
    $prefix = strtoupper((string)($cert['cert_type'] ?? $cert['rwa_type'] ?? 'RWA'));
    $year   = date('Y');
    $month  = date('m');
    $userId = (int)($cert['user_id'] ?? 0);
    $fallbackGuess = $_SERVER['DOCUMENT_ROOT']
        . '/rwa/metadata/cert/_fallback_vault/RWA_CERT/GENESIS/'
        . $prefix
        . '/TON/'
        . $year
        . '/'
        . $month
        . '/U'
        . $userId
        . '/'
        . $uid;
    if (is_dir($fallbackGuess)) {
        $artifactBase = $fallbackGuess;
    }
}

/**
 * MASTER LOCK:
 * verify.json is the ONLY truth for NFT health.
 */
$verifyJsonPath = $artifactBase !== '' ? ($artifactBase . '/verify/verify.json') : '';
$verify = [];
if ($verifyJsonPath !== '' && is_file($verifyJsonPath)) {
    $decoded = json_decode((string)file_get_contents($verifyJsonPath), true);
    if (is_array($decoded)) {
        $verify = $decoded;
    }
}

$nftReady = !empty($verify['ok']) && empty($verify['used_fallback_placeholder']);
$nftStatusLabel = $nftReady ? 'NFT Ready' : 'NFT Broken';
$nftStatusClass = $nftReady ? 'is-green' : 'is-red';
$qrOk       = !empty($verify['qr_png']);
$imageOk    = !empty($verify['image_url']);

$imageUrl = '';
$verifyImage = trim((string)($verify['image_url'] ?? ''));
if ($verifyImage !== '') {
    $imageUrl = $verifyImage;
} elseif (!empty($certMeta['image_url'])) {
    $imageUrl = (string)$certMeta['image_url'];
} elseif (!empty($paymentMeta['image_url'])) {
    $imageUrl = (string)$paymentMeta['image_url'];
}

$verifyUrl = '';
foreach ([
    $verify['verify_url'] ?? null,
    $certMeta['verify_url'] ?? null,
    $paymentMeta['verify_url'] ?? null,
] as $candidate) {
    $candidate = is_string($candidate) ? trim($candidate) : '';
    if ($candidate !== '') {
        $verifyUrl = $candidate;
        break;
    }
}
if ($verifyUrl === '') {
    $verifyUrl = '/rwa/cert/verify.php?uid=' . rawurlencode($uid);
}

$paymentStatus = strtolower(trim((string)(
    $payment['payment_status']
    ?? $payment['status']
    ?? $certMeta['payment_status']
    ?? $paymentMeta['payment_status']
    ?? ''
)));

$paymentConfirmed = in_array($paymentStatus, ['confirmed', 'paid', 'success', 'completed'], true);

$certStatus = strtolower(trim((string)($cert['status'] ?? '')));
$allowFinalizeMint = $paymentConfirmed && $nftReady && !in_array($certStatus, ['minted', 'revoked'], true);

$finalizeApi = '/rwa/cert/api/mint-init.php';
$verifyApi   = '/rwa/cert/api/mint-verify.php';

$displayType = (string)($cert['cert_type'] ?? $cert['rwa_type'] ?? $certMeta['cert_type'] ?? 'RWA');
$displayCode = (string)($cert['rwa_code'] ?? $cert['cert_code'] ?? $certMeta['rwa_code'] ?? $displayType);
$displayChain = (string)($certMeta['chain_name'] ?? 'TON');
$displayOwner = (string)($cert['wallet_address'] ?? $cert['wallet'] ?? $certMeta['wallet'] ?? '');
$displayMintValueTon = (string)(
    $certMeta['mint_value_ton']
    ?? $paymentMeta['mint_value_ton']
    ?? $certMeta['tx_value_ton']
    ?? $paymentMeta['tx_value_ton']
    ?? '0.30'
);

$collectionAddress = (string)($certMeta['collection_address'] ?? $paymentMeta['collection_address'] ?? '');
$nftItemAddress = (string)($cert['nft_item_address'] ?? $certMeta['nft_item_address'] ?? '');
$txHash = (string)($cert['tx_hash'] ?? $certMeta['tx_hash'] ?? '');
$explorerUrl = $nftItemAddress !== '' ? ('https://getgems.io/collection/' . rawurlencode($collectionAddress)) : '';
?>
<!doctype html>
<html lang="en" data-lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#06080d">
  <title><?= h($uid) ?> · Finalize Mint</title>
  <style>
    :root{
      --bg:#06080d;
      --panel:#0d121a;
      --panel-2:#111826;
      --line:rgba(255,255,255,.08);
      --text:#eef3ff;
      --muted:#94a3b8;
      --gold:#e9c46a;
      --green:#22c55e;
      --amber:#f59e0b;
      --red:#ef4444;
      --cyan:#38bdf8;
      --radius:20px;
      --shadow:0 20px 50px rgba(0,0,0,.32);
    }
    *{box-sizing:border-box}
    html,body{margin:0;padding:0;background:
      radial-gradient(circle at top right, rgba(56,189,248,.12), transparent 28%),
      radial-gradient(circle at top left, rgba(233,196,106,.10), transparent 25%),
      linear-gradient(180deg,#05070b,#0a0f17 42%,#06080d 100%);
      color:var(--text);font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}
    a{color:#f7d67c;text-decoration:none}
    .page{
      width:min(1180px,calc(100% - 24px));
      margin:18px auto 110px;
      display:grid;
      gap:16px;
    }
    .langbar{
      display:flex;justify-content:flex-end;align-items:center;gap:8px;
    }
    .langbtn{
      appearance:none;border:1px solid var(--line);background:rgba(255,255,255,.03);
      color:var(--text);padding:10px 14px;border-radius:999px;cursor:pointer;font-weight:700
    }
    .langbtn.is-active{
      border-color:rgba(233,196,106,.45);
      background:rgba(233,196,106,.12);
      color:#ffe8a3;
      box-shadow:0 0 0 1px rgba(233,196,106,.12) inset;
    }
    .hero{
      background:linear-gradient(180deg,rgba(255,255,255,.04),rgba(255,255,255,.02));
      border:1px solid var(--line);
      border-radius:28px;
      box-shadow:var(--shadow);
      overflow:hidden;
    }
    .hero-inner{
      display:grid;
      grid-template-columns:1.2fr .8fr;
      gap:18px;
      padding:22px;
    }
    .eyebrow{
      display:inline-flex;align-items:center;gap:8px;
      font-size:12px;font-weight:800;letter-spacing:.14em;text-transform:uppercase;
      color:#d6def7;
      padding:8px 12px;border-radius:999px;border:1px solid var(--line);
      background:rgba(255,255,255,.03);
    }
    .title{margin:14px 0 8px;font-size:clamp(28px,4vw,42px);line-height:1.05}
    .subtitle{margin:0;color:var(--muted);max-width:720px;font-size:15px}
    .hero-actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:18px}
    .btn{
      appearance:none;border:0;border-radius:16px;padding:14px 18px;
      font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:10px
    }
    .btn-primary{background:linear-gradient(180deg,#1fd16b,#159b4f);color:#04110a}
    .btn-secondary{background:rgba(255,255,255,.05);color:var(--text);border:1px solid var(--line)}
    .btn[disabled]{opacity:.45;cursor:not-allowed}
    .hero-side{
      background:linear-gradient(180deg,rgba(255,255,255,.04),rgba(255,255,255,.01));
      border:1px solid var(--line);border-radius:22px;padding:18px;
      display:grid;gap:14px;align-content:start;
    }
    .grid{
      display:grid;
      grid-template-columns:1.05fr .95fr;
      gap:16px;
    }
    .card{
      background:linear-gradient(180deg,rgba(255,255,255,.04),rgba(255,255,255,.02));
      border:1px solid var(--line);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      overflow:hidden;
    }
    .card-head{
      padding:18px 18px 0;
      display:flex;align-items:center;justify-content:space-between;gap:10px
    }
    .card-title{margin:0;font-size:18px}
    .card-body{padding:18px}
    .status-row{display:flex;flex-wrap:wrap;gap:10px}
    .badge{
      display:inline-flex;align-items:center;gap:8px;
      border-radius:999px;padding:9px 12px;font-size:12px;font-weight:800;
      border:1px solid var(--line);background:rgba(255,255,255,.04)
    }
    .dot{width:10px;height:10px;border-radius:50%}
    .is-green{color:#d7ffe5;border-color:rgba(34,197,94,.3);background:rgba(34,197,94,.12)}
    .is-green .dot{background:var(--green)}
    .is-amber{color:#fff1cf;border-color:rgba(245,158,11,.28);background:rgba(245,158,11,.12)}
    .is-amber .dot{background:var(--amber)}
    .is-red{color:#ffd9d9;border-color:rgba(239,68,68,.28);background:rgba(239,68,68,.12)}
    .is-red .dot{background:var(--red)}
    .is-dim{color:#d7e0f2;border-color:var(--line);background:rgba(255,255,255,.04)}
    .is-dim .dot{background:#94a3b8}
    .kv{
      display:grid;grid-template-columns:160px 1fr;gap:10px 14px;
      align-items:start
    }
    .kv + .kv{margin-top:12px}
    .k{color:var(--muted);font-size:13px}
    .v{font-weight:700;word-break:break-word}
    .preview{
      display:grid;gap:14px
    }
    .preview-frame{
      border-radius:18px;overflow:hidden;border:1px solid var(--line);
      min-height:260px;background:#0b1118;display:flex;align-items:center;justify-content:center
    }
    .preview-frame img{display:block;width:100%;height:auto}
    .preview-empty{padding:30px;color:var(--muted);text-align:center}
    .indicator-grid{
      display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px
    }
    .indicator{
      border:1px solid var(--line);border-radius:16px;padding:14px;background:rgba(255,255,255,.03)
    }
    .indicator .label{font-size:12px;color:var(--muted);margin-bottom:8px}
    .indicator .value{font-weight:800}
    .cta-panel{
      display:grid;gap:12px
    }
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
    .small{font-size:12px;color:var(--muted)}
    .notice{
      border-left:3px solid rgba(56,189,248,.55);
      background:rgba(56,189,248,.08);
      padding:12px 14px;border-radius:12px;color:#d8f3ff
    }
    .json-ok{color:#b9ffd1}
    .footer-gap{height:18px}
    [data-i18n]{visibility:hidden}
    html[data-lang] [data-i18n]{visibility:visible}

    @media (max-width: 980px){
      .hero-inner,.grid{grid-template-columns:1fr}
    }
    @media (max-width: 640px){
      .page{width:min(100% - 14px,100%)}
      .hero-inner,.card-body,.card-head{padding:14px}
      .kv{grid-template-columns:1fr}
      .indicator-grid{grid-template-columns:1fr}
      .hero-actions{flex-direction:column}
      .btn{width:100%}
    }
  </style>
</head>
<body>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php'; ?>

<main class="page">
  <div class="langbar" aria-label="Language switcher">
    <button type="button" class="langbtn is-active" data-lang-btn="en">English</button>
    <button type="button" class="langbtn" data-lang-btn="zh">中文</button>
  </div>

  <section class="hero">
    <div class="hero-inner">
      <div>
        <div class="eyebrow">
          <span>◆</span>
          <span data-i18n="hero_badge">RWA Cert Finalize Mint</span>
        </div>

        <h1 class="title mono"><?= h($uid) ?></h1>

        <p class="subtitle" data-i18n="hero_subtitle">
          Finalize the TON mint step only after business payment is confirmed and verify.json reports NFT Ready.
        </p>

        <div class="hero-actions">
          <button
            id="btnFinalizeMint"
            class="btn btn-primary"
            <?= $allowFinalizeMint ? '' : 'disabled' ?>
            data-uid="<?= h($uid) ?>"
            data-finalize-api="<?= h($finalizeApi) ?>"
            data-verify-api="<?= h($verifyApi) ?>"
          >
            <span data-i18n="btn_finalize">Finalize Mint</span>
          </button>

          <a class="btn btn-secondary" href="<?= h($verifyUrl) ?>" target="_blank" rel="noopener">
            <span data-i18n="btn_open_verify">Open Verify Lounge</span>
          </a>
        </div>
      </div>

      <aside class="hero-side">
        <div class="status-row">
          <span class="badge <?= h(cert_status_badge_class($paymentConfirmed ? 'confirmed' : ($paymentStatus ?: 'pending'))) ?>">
            <span class="dot"></span>
            <span data-i18n="status_payment">Payment</span>: <?= h($paymentConfirmed ? 'Confirmed' : ($paymentStatus !== '' ? ucfirst($paymentStatus) : 'Pending')) ?>
          </span>

          <span class="badge <?= h($nftStatusClass) ?>">
            <span class="dot"></span>
            <?= h($nftStatusLabel) ?>
          </span>

          <span class="badge <?= h(cert_status_badge_class($certStatus)) ?>">
            <span class="dot"></span>
            <span data-i18n="status_cert">Cert</span>: <?= h($certStatus !== '' ? ucfirst($certStatus) : 'Unknown') ?>
          </span>
        </div>

        <div class="notice">
          <strong data-i18n="notice_title">Locked rule:</strong>
          <span data-i18n="notice_text">verify.json is the single source of truth for NFT health. The UI must not recompute artifact health independently.</span>
        </div>

        <div class="kv">
          <div class="k" data-i18n="kv_type">Type</div>
          <div class="v"><?= h($displayType) ?></div>
        </div>
        <div class="kv">
          <div class="k" data-i18n="kv_code">Code</div>
          <div class="v"><?= h($displayCode) ?></div>
        </div>
        <div class="kv">
          <div class="k" data-i18n="kv_chain">Chain</div>
          <div class="v"><?= h($displayChain) ?></div>
        </div>
        <div class="kv">
          <div class="k" data-i18n="kv_mint_fee">Mint Gas Reference</div>
          <div class="v"><?= h($displayMintValueTon) ?> TON</div>
        </div>
      </aside>
    </div>
  </section>

  <section class="grid">
    <article class="card">
      <div class="card-head">
        <h2 class="card-title" data-i18n="card_preview">NFT Preview</h2>
        <span class="badge <?= h($nftStatusClass) ?>">
          <span class="dot"></span>
          <?= h($nftStatusLabel) ?>
        </span>
      </div>
      <div class="card-body preview">
        <div class="preview-frame">
          <?php if ($imageUrl !== ''): ?>
            <img src="<?= h($imageUrl) ?>" alt="NFT Preview">
          <?php else: ?>
            <div class="preview-empty" data-i18n="preview_empty">No preview image available yet.</div>
          <?php endif; ?>
        </div>

        <div class="indicator-grid">
          <div class="indicator">
            <div class="label" data-i18n="ind_template">Template</div>
            <div class="value"><?= $templateOk ? 'OK' : '—' ?></div>
          </div>
          <div class="indicator">
            <div class="label" data-i18n="ind_qr">QR</div>
            <div class="value"><?= $qrOk ? 'OK' : '—' ?></div>
          </div>
          <div class="indicator">
            <div class="label" data-i18n="ind_image">Image</div>
            <div class="value"><?= $imageOk ? 'OK' : '—' ?></div>
          </div>
        </div>

        <div class="small">
          <span data-i18n="verify_source">Health source:</span>
          <span class="mono json-ok"><?= h($verifyJsonPath !== '' ? $verifyJsonPath : 'verify.json not found') ?></span>
        </div>
      </div>
    </article>

    <article class="card">
      <div class="card-head">
        <h2 class="card-title" data-i18n="card_mint">Mint Readiness</h2>
      </div>
      <div class="card-body cta-panel">
        <div class="kv">
          <div class="k" data-i18n="kv_payment_confirmed">Payment Confirmed</div>
          <div class="v"><?= $paymentConfirmed ? 'Yes' : 'No' ?></div>
        </div>

        <div class="kv">
          <div class="k" data-i18n="kv_nft_ready">NFT Ready</div>
          <div class="v"><?= $nftReady ? 'Yes' : 'No' ?></div>
        </div>

        <div class="kv">
          <div class="k" data-i18n="kv_allow_finalize">Allow Finalize Mint</div>
          <div class="v"><?= $allowFinalizeMint ? 'Yes' : 'No' ?></div>
        </div>

        <div class="kv">
          <div class="k" data-i18n="kv_wallet">Owner Wallet</div>
          <div class="v mono"><?= h($displayOwner !== '' ? $displayOwner : '—') ?></div>
        </div>

        <div class="kv">
          <div class="k" data-i18n="kv_collection">Collection</div>
          <div class="v mono"><?= h($collectionAddress !== '' ? $collectionAddress : '—') ?></div>
        </div>

        <div class="kv">
          <div class="k" data-i18n="kv_nft_item">NFT Item</div>
          <div class="v mono"><?= h($nftItemAddress !== '' ? $nftItemAddress : '—') ?></div>
        </div>

        <div class="kv">
          <div class="k" data-i18n="kv_tx_hash">Tx Hash</div>
          <div class="v mono"><?= h($txHash !== '' ? $txHash : '—') ?></div>
        </div>

        <?php if (!$allowFinalizeMint): ?>
          <div class="notice">
            <strong data-i18n="ready_blocked_title">Mint not enabled yet.</strong>
            <span data-i18n="ready_blocked_text">The Finalize Mint action is available only when payment is confirmed and verify.json reports NFT Ready.</span>
          </div>
        <?php else: ?>
          <div class="notice">
            <strong data-i18n="ready_enabled_title">Mint available.</strong>
            <span data-i18n="ready_enabled_text">Business payment is confirmed and verify.json reports NFT Ready. User-paid TON mint can proceed.</span>
          </div>
        <?php endif; ?>

        <?php if ($explorerUrl !== ''): ?>
          <a class="btn btn-secondary" href="<?= h($explorerUrl) ?>" target="_blank" rel="noopener">
            <span data-i18n="btn_open_getgems">Open GetGems</span>
          </a>
        <?php endif; ?>

        <div id="mintResult" class="small"></div>
      </div>
    </article>
  </section>

  <div class="footer-gap"></div>
</main>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php'; ?>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/gt-inline.php'; ?>
<script src="/rwa/inc/core/poado-i18n.js"></script>
<script>
(function () {
  const I18N = {
    en: {
      hero_badge: 'RWA Cert Finalize Mint',
      hero_subtitle: 'Finalize the TON mint step only after business payment is confirmed and verify.json reports NFT Ready.',
      btn_finalize: 'Finalize Mint',
      btn_open_verify: 'Open Verify Lounge',
      status_payment: 'Payment',
      status_cert: 'Cert',
      notice_title: 'Locked rule:',
      notice_text: 'verify.json is the single source of truth for NFT health. The UI must not recompute artifact health independently.',
      kv_type: 'Type',
      kv_code: 'Code',
      kv_chain: 'Chain',
      kv_mint_fee: 'Mint Gas Reference',
      card_preview: 'NFT Preview',
      preview_empty: 'No preview image available yet.',
      ind_template: 'Template',
      ind_qr: 'QR',
      ind_image: 'Image',
      verify_source: 'Health source:',
      card_mint: 'Mint Readiness',
      kv_payment_confirmed: 'Payment Confirmed',
      kv_nft_ready: 'NFT Ready',
      kv_allow_finalize: 'Allow Finalize Mint',
      kv_wallet: 'Owner Wallet',
      kv_collection: 'Collection',
      kv_nft_item: 'NFT Item',
      kv_tx_hash: 'Tx Hash',
      ready_blocked_title: 'Mint not enabled yet.',
      ready_blocked_text: 'The Finalize Mint action is available only when payment is confirmed and verify.json reports NFT Ready.',
      ready_enabled_title: 'Mint available.',
      ready_enabled_text: 'Business payment is confirmed and verify.json reports NFT Ready. User-paid TON mint can proceed.',
      btn_open_getgems: 'Open GetGems',
      mint_running: 'Submitting finalize request...',
      mint_done: 'Finalize request accepted.',
      mint_failed: 'Finalize request failed.',
      network_error: 'Network error while calling finalize API.'
    },
    zh: {
      hero_badge: 'RWA 证书最终铸造',
      hero_subtitle: '只有在业务付款已确认，且 verify.json 报告 NFT Ready 后，才可进入 TON 最终铸造步骤。',
      btn_finalize: 'Finalize Mint',
      btn_open_verify: '打开 Verify Lounge',
      status_payment: '付款',
      status_cert: '证书',
      notice_title: '锁定规则：',
      notice_text: 'NFT 健康状态只能以 verify.json 为唯一真相来源，前端不可自行重新判断工件状态。',
      kv_type: '类型',
      kv_code: '编码',
      kv_chain: '链',
      kv_mint_fee: '铸造 Gas 参考',
      card_preview: 'NFT 预览',
      preview_empty: '暂时没有可显示的预览图。',
      ind_template: '模板',
      ind_qr: '二维码',
      ind_image: '图片',
      verify_source: '健康来源：',
      card_mint: '铸造就绪状态',
      kv_payment_confirmed: '付款已确认',
      kv_nft_ready: 'NFT 已就绪',
      kv_allow_finalize: '允许 Finalize Mint',
      kv_wallet: '持有人钱包',
      kv_collection: '合集地址',
      kv_nft_item: 'NFT 项地址',
      kv_tx_hash: '交易哈希',
      ready_blocked_title: '暂未开放铸造。',
      ready_blocked_text: '只有当付款确认且 verify.json 报告 NFT Ready 时，Finalize Mint 才可启用。',
      ready_enabled_title: '可以铸造。',
      ready_enabled_text: '业务付款已确认，verify.json 也已报告 NFT Ready，可继续用户自付 TON Gas 的铸造流程。',
      btn_open_getgems: '打开 GetGems',
      mint_running: '正在提交 finalize 请求...',
      mint_done: 'Finalize 请求已接受。',
      mint_failed: 'Finalize 请求失败。',
      network_error: '调用 finalize API 时发生网络错误。'
    }
  };

  function currentLang() {
    const htmlLang = document.documentElement.getAttribute('data-lang');
    if (htmlLang && I18N[htmlLang]) return htmlLang;
    try {
      if (window.POADO_I18N && typeof window.POADO_I18N.getLang === 'function') {
        const x = window.POADO_I18N.getLang();
        if (I18N[x]) return x;
      }
    } catch (e) {}
    return 'en';
  }

  function applyLang(lang) {
    const dict = I18N[lang] || I18N.en;
    document.documentElement.setAttribute('lang', lang);
    document.documentElement.setAttribute('data-lang', lang);
    document.querySelectorAll('[data-i18n]').forEach(function (node) {
      const key = node.getAttribute('data-i18n');
      if (key && dict[key]) node.textContent = dict[key];
    });
    document.querySelectorAll('[data-lang-btn]').forEach(function (btn) {
      btn.classList.toggle('is-active', btn.getAttribute('data-lang-btn') === lang);
    });
  }

  document.querySelectorAll('[data-lang-btn]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const lang = btn.getAttribute('data-lang-btn') || 'en';
      if (window.POADO_I18N && typeof window.POADO_I18N.setLang === 'function') {
        window.POADO_I18N.setLang(lang);
      }
      applyLang(lang);
    });
  });

  document.addEventListener('poado:langchange', function (e) {
    applyLang((e.detail && e.detail.lang) || 'en');
  });

  applyLang(currentLang());

  const btn = document.getElementById('btnFinalizeMint');
  const result = document.getElementById('mintResult');

  if (btn) {
    btn.addEventListener('click', async function () {
      if (btn.disabled) return;

      const lang = currentLang();
      const t = I18N[lang] || I18N.en;

      btn.disabled = true;
      result.textContent = t.mint_running;

      try {
        const body = new URLSearchParams();
        body.set('uid', btn.dataset.uid || '');

        const resp = await fetch(btn.dataset.finalizeApi || '/rwa/cert/api/mint-init.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
          body: body.toString(),
          credentials: 'same-origin'
        });

        const text = await resp.text();
        let json = {};
        try { json = JSON.parse(text); } catch (e) {}

        if (resp.ok && json && json.ok) {
          result.textContent = t.mint_done;
          window.setTimeout(function () { window.location.reload(); }, 900);
          return;
        }

        result.textContent = (json && (json.error || json.message)) ? String(json.error || json.message) : t.mint_failed;
        btn.disabled = false;
      } catch (err) {
        result.textContent = t.network_error;
        btn.disabled = false;
      }
    });
  }
})();
</script>
</body>
</html>
