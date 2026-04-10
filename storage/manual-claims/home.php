<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

function mchh_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$user = null;
if (function_exists('session_user')) {
    try {
        $u = session_user();
        if (is_array($u)) {
            $user = $u;
        }
    } catch (Throwable $e) {
    }
}
if (!$user && isset($GLOBALS['session_user']) && is_array($GLOBALS['session_user'])) {
    $user = $GLOBALS['session_user'];
}
$user = is_array($user) ? $user : [];

$isAdmin = false;
foreach ([
    $_SESSION['is_admin'] ?? null,
    $_SESSION['is_super_admin'] ?? null,
    $_SESSION['admin'] ?? null,
    $_SESSION['role'] ?? null,
    $_SESSION['user_role'] ?? null,
    $user['is_admin'] ?? null,
    $user['role'] ?? null,
] as $v) {
    if ($v === true || $v === 1 || $v === '1' || $v === 'admin' || $v === 'super_admin') {
        $isAdmin = true;
        break;
    }
}

$cards = [
    [
        'title' => 'User Panel',
        'path' => '/rwa/storage/manual-claims/panel.php',
        'desc' => 'User-facing Off Chain Unclaimed panel for claim_ema, claim_wems, claim_usdt_ton, claim_emx_tips, and fuel_ems.',
        'tag' => 'USER'
    ],
    [
        'title' => 'Admin Panel',
        'path' => '/rwa/storage/manual-claims/index.php',
        'desc' => 'Approve, reject, mark proof, and mark paid for all shared manual claim flows.',
        'tag' => 'ADMIN'
    ],
    [
        'title' => 'Proof Helper',
        'path' => '/rwa/storage/manual-claims/proof-helper.php',
        'desc' => 'Prepare ClaimProofEMX proof payload data and record proof_tx_hash for approved rows requiring proof.',
        'tag' => 'ADMIN'
    ],
    [
        'title' => 'Report Ledger',
        'path' => '/rwa/storage/manual-claims/report.php',
        'desc' => 'Unified payout ledger and reporting view for all manual claim and fuel flows.',
        'tag' => 'ADMIN'
    ],
    [
        'title' => 'Anomalies Dashboard',
        'path' => '/rwa/storage/manual-claims/anomalies.php',
        'desc' => 'Read-only monitoring for stale approved rows, stale proof_submitted rows, and cron anomalies.',
        'tag' => 'ADMIN'
    ],
];

$flowTypes = [
    'claim_ema',
    'claim_wems',
    'claim_usdt_ton',
    'claim_emx_tips',
    'fuel_ems',
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Manual Claims Hub</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/rwa/storage/manual-claims/home.css?v=20260324-1">
</head>
<body data-manual-claims-home-root>
  <?php
  if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php')) {
      require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php';
  }
  ?>

  <main class="mchh-page">
    <section class="mchh-hero">
      <div class="mchh-hero-head">
        <div>
          <div class="mchh-kicker">STORAGE / MANUAL CLAIMS</div>
          <h1 class="mchh-title">Manual Claims Module Hub</h1>
          <div class="mchh-subtitle">
            Canonical navigation page for the shared Storage manual claims subsystem.
          </div>
        </div>
        <div class="mchh-who">
          <div class="mchh-chip"><?= $isAdmin ? 'ADMIN' : 'USER' ?></div>
          <div class="mchh-mini"><?= mchh_h($user['nickname'] ?? $user['email'] ?? $user['wallet_address'] ?? 'User') ?></div>
        </div>
      </div>
    </section>

    <section class="mchh-grid">
      <?php foreach ($cards as $card): ?>
        <?php if ($card['tag'] === 'ADMIN' && !$isAdmin) continue; ?>
        <article class="mchh-card">
          <div class="mchh-card-top">
            <div class="mchh-card-title"><?= mchh_h($card['title']) ?></div>
            <div class="mchh-chip small"><?= mchh_h($card['tag']) ?></div>
          </div>
          <div class="mchh-card-desc"><?= mchh_h($card['desc']) ?></div>
          <div class="mchh-card-path"><?= mchh_h($card['path']) ?></div>
          <div class="mchh-card-actions">
            <a class="mchh-btn mchh-btn-primary" href="<?= mchh_h($card['path']) ?>">Open</a>
          </div>
        </article>
      <?php endforeach; ?>
    </section>

    <section class="mchh-panel">
      <div class="mchh-panel-head">
        <div>
          <div class="mchh-kicker">SYSTEM SUMMARY</div>
          <h2 class="mchh-section-title">Locked Shared Architecture</h2>
        </div>
      </div>

      <div class="mchh-summary-grid">
        <div class="mchh-summary-card">
          <div class="mchh-summary-label">Request Table</div>
          <div class="mchh-summary-value">wems_db.poado_token_manual_requests</div>
        </div>
        <div class="mchh-summary-card">
          <div class="mchh-summary-label">Reserve Table</div>
          <div class="mchh-summary-value">wems_db.poado_token_manual_reserves</div>
        </div>
        <div class="mchh-summary-card">
          <div class="mchh-summary-label">Canonical API Path</div>
          <div class="mchh-summary-value">/rwa/api/storage/manual-claims/</div>
        </div>
        <div class="mchh-summary-card">
          <div class="mchh-summary-label">Optional Proof Contract</div>
          <div class="mchh-summary-value">ClaimProofEMX</div>
        </div>
      </div>
    </section>

    <section class="mchh-layout">
      <section class="mchh-panel">
        <div class="mchh-panel-head">
          <div>
            <div class="mchh-kicker">FLOW FAMILY</div>
            <h2 class="mchh-section-title">Supported Flow Types</h2>
          </div>
        </div>

        <div class="mchh-flow-list">
          <?php foreach ($flowTypes as $flow): ?>
            <div class="mchh-flow-item"><?= mchh_h($flow) ?></div>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="mchh-panel">
        <div class="mchh-panel-head">
          <div>
            <div class="mchh-kicker">LIFECYCLE</div>
            <h2 class="mchh-section-title">Request Status Model</h2>
          </div>
        </div>

        <div class="mchh-code">
requested → approved → proof_submitted → paid
requested → approved → paid
requested → rejected
requested → cancelled
requested/approved/proof_submitted → failed
        </div>
      </section>

      <section class="mchh-panel">
        <div class="mchh-panel-head">
          <div>
            <div class="mchh-kicker">RESERVE MODEL</div>
            <h2 class="mchh-section-title">Reserve Lifecycle</h2>
          </div>
        </div>

        <div class="mchh-code">
ACTIVE    = reserved
RELEASED  = released back
CONSUMED  = permanently used by payout
        </div>
      </section>
    </section>

    <section class="mchh-panel">
      <div class="mchh-panel-head">
        <div>
          <div class="mchh-kicker">OPS NOTES</div>
          <h2 class="mchh-section-title">Operational Rules</h2>
        </div>
      </div>

      <div class="mchh-notes">
        <div class="mchh-note">All manual claim and fuel flows must use the shared Storage manual-claims API path.</div>
        <div class="mchh-note">ClaimProofEMX is optional and used only when proof_required = 1.</div>
        <div class="mchh-note">Reserve reconcile cron is the canonical self-heal layer for manual-claims reservation safety.</div>
        <div class="mchh-note">No duplicate per-token claim modules or duplicate claim tables are allowed unless explicitly unlocked.</div>
      </div>
    </section>
  </main>

  <?php
  if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php')) {
      require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php';
  }
  ?>
</body>
</html>
