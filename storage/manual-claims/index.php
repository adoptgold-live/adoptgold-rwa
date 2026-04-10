<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

function mc_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$csrfToken = '';
if (function_exists('csrf_token')) {
    try {
        $csrfToken = (string)csrf_token('manual_claims_admin');
    } catch (Throwable $e) {
        $csrfToken = '';
    }
}
if ($csrfToken === '' && isset($_SESSION['csrf_token'])) {
    $csrfToken = (string)$_SESSION['csrf_token'];
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

if (!$isAdmin) {
    http_response_code(403);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Manual Claims Admin</title></head><body style="background:#050607;color:#d7ffe9;font-family:ui-monospace,Menlo,Consolas,monospace;padding:24px;">ADMIN REQUIRED</body></html>';
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Manual Claims Admin</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="csrf-token" content="<?= mc_h($csrfToken) ?>">
  <link rel="stylesheet" href="/rwa/storage/manual-claims/manual-claims.css?v=20260324-1">
</head>
<body data-manual-claims-admin-root>
  <?php
  if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php')) {
      require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php';
  }
  ?>

  <main class="mca-page">
    <section class="mca-hero">
      <div class="mca-hero-head">
        <div>
          <div class="mca-kicker">STORAGE / ADMIN</div>
          <h1 class="mca-title">Manual Claims Admin</h1>
          <div class="mca-subtitle">Unified admin panel for EMA, wEMS, USDT-TON, EMX tips, and Fuel EMS requests.</div>
        </div>
        <div class="mca-who">
          <div class="mca-chip">ADMIN</div>
          <div class="mca-mini"><?= mc_h($user['nickname'] ?? $user['email'] ?? $user['wallet_address'] ?? 'Admin') ?></div>
        </div>
      </div>
    </section>

    <section class="mca-panel">
      <div class="mca-filter-grid">
        <label class="mca-field">
          <span>Status</span>
          <select id="mcaStatus">
            <option value="">All</option>
            <option value="requested">requested</option>
            <option value="approved">approved</option>
            <option value="proof_submitted">proof_submitted</option>
            <option value="paid">paid</option>
            <option value="rejected">rejected</option>
            <option value="failed">failed</option>
            <option value="cancelled">cancelled</option>
          </select>
        </label>

        <label class="mca-field">
          <span>Flow Type</span>
          <select id="mcaFlowType">
            <option value="">All</option>
            <option value="claim_ema">claim_ema</option>
            <option value="claim_wems">claim_wems</option>
            <option value="claim_usdt_ton">claim_usdt_ton</option>
            <option value="claim_emx_tips">claim_emx_tips</option>
            <option value="fuel_ems">fuel_ems</option>
          </select>
        </label>

        <label class="mca-field">
          <span>Request UID</span>
          <input id="mcaRequestUid" type="text" placeholder="MTR-YYYYMMDD-XXXXXXXX">
        </label>

        <label class="mca-field">
          <span>User ID</span>
          <input id="mcaUserId" type="number" min="1" step="1" placeholder="13">
        </label>

        <label class="mca-field">
          <span>Limit</span>
          <select id="mcaLimit">
            <option value="50">50</option>
            <option value="100" selected>100</option>
            <option value="200">200</option>
            <option value="500">500</option>
          </select>
        </label>

        <div class="mca-actions">
          <button type="button" id="mcaRefreshBtn" class="mca-btn mca-btn-primary">Refresh</button>
          <button type="button" id="mcaResetBtn" class="mca-btn">Reset</button>
        </div>
      </div>

      <div id="mcaStatusBar" class="mca-statusbar">Ready.</div>
    </section>

    <section class="mca-panel">
      <div class="mca-table-wrap">
        <table class="mca-table" id="mcaTable">
          <thead>
            <tr>
              <th>Request</th>
              <th>User</th>
              <th>Flow</th>
              <th>Settle</th>
              <th>Amount</th>
              <th>Recipient</th>
              <th>Status</th>
              <th>Claim</th>
              <th>Proof</th>
              <th>Payout</th>
              <th>Created</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="mcaTableBody">
            <tr><td colspan="12" class="mca-empty">Loading…</td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </main>

  <div id="mcaModal" class="mca-modal" hidden>
    <div class="mca-modal-card">
      <div class="mca-modal-head">
        <h2 id="mcaModalTitle">Action</h2>
        <button type="button" id="mcaModalClose" class="mca-icon-btn" aria-label="Close">×</button>
      </div>
      <div id="mcaModalMeta" class="mca-modal-meta"></div>
      <form id="mcaModalForm" class="mca-form">
        <input type="hidden" id="mcaActionType">
        <input type="hidden" id="mcaActionRequestUid">
        <div id="mcaFormFields" class="mca-form-fields"></div>
        <div class="mca-form-actions">
          <button type="button" id="mcaCancelBtn" class="mca-btn">Cancel</button>
          <button type="submit" id="mcaSubmitBtn" class="mca-btn mca-btn-primary">Submit</button>
        </div>
      </form>
    </div>
  </div>

  <?php
  if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php')) {
      require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php';
  }
  ?>

  <script>
    window.MANUAL_CLAIMS_ADMIN = {
      csrfToken: <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      apiBase: "/rwa/api/storage/manual-claims"
    };
  </script>
  <script src="/rwa/storage/manual-claims/manual-claims.js?v=20260324-1"></script>
</body>
</html>
