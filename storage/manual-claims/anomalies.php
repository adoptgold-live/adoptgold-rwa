<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

function mca_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$csrfToken = '';
if (function_exists('csrf_token')) {
    try {
        $csrfToken = (string)csrf_token('manual_claims_anomalies');
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
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Manual Claims Anomalies</title></head><body style="background:#050607;color:#d7ffe9;font-family:ui-monospace,Menlo,Consolas,monospace;padding:24px;">ADMIN REQUIRED</body></html>';
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Manual Claims Anomalies</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="csrf-token" content="<?= mca_h($csrfToken) ?>">
  <link rel="stylesheet" href="/rwa/storage/manual-claims/anomalies.css?v=20260324-1">
</head>
<body data-manual-claims-anomalies-root>
  <?php
  if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php')) {
      require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php';
  }
  ?>

  <main class="mca-page">
    <section class="mca-hero">
      <div class="mca-hero-head">
        <div>
          <div class="mca-kicker">STORAGE / MANUAL CLAIMS / ANOMALIES</div>
          <h1 class="mca-title">Manual Claims Health Dashboard</h1>
          <div class="mca-subtitle">Read-only monitoring for stale approved rows, stale proof-submitted rows, reserve mismatches, and cron anomaly logs.</div>
        </div>
        <div class="mca-who">
          <div class="mca-chip">ADMIN</div>
          <div class="mca-mini"><?= mca_h($user['nickname'] ?? $user['email'] ?? $user['wallet_address'] ?? 'Admin') ?></div>
        </div>
      </div>
    </section>

    <section class="mca-summary-grid">
      <div class="mca-summary-card">
        <div class="mca-summary-label">Approved &gt; 48h</div>
        <div class="mca-summary-value" id="mcaApprovedStaleCount">0</div>
      </div>
      <div class="mca-summary-card">
        <div class="mca-summary-label">Proof Submitted &gt; 72h</div>
        <div class="mca-summary-value" id="mcaProofStaleCount">0</div>
      </div>
      <div class="mca-summary-card">
        <div class="mca-summary-label">Auto-Cancelled 7d</div>
        <div class="mca-summary-value" id="mcaAutoCancelledCount">0</div>
      </div>
      <div class="mca-summary-card">
        <div class="mca-summary-label">Cron Errors 7d</div>
        <div class="mca-summary-value" id="mcaCronErrorCount">0</div>
      </div>
    </section>

    <section class="mca-panel">
      <div class="mca-filter-grid">
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
          <span>User ID</span>
          <input id="mcaUserId" type="number" min="1" step="1" placeholder="13">
        </label>

        <label class="mca-field">
          <span>Lookback Days</span>
          <select id="mcaDays">
            <option value="1">1</option>
            <option value="3">3</option>
            <option value="7" selected>7</option>
            <option value="14">14</option>
            <option value="30">30</option>
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
      <div class="mca-panel-head">
        <div>
          <div class="mca-kicker">STALE APPROVED</div>
          <h2 class="mca-section-title">Approved &gt; 48 Hours</h2>
        </div>
      </div>
      <div class="mca-table-wrap">
        <table class="mca-table">
          <thead>
            <tr>
              <th>Request</th>
              <th>User</th>
              <th>Flow</th>
              <th>Amount</th>
              <th>Hours Old</th>
              <th>Claim</th>
              <th>Approved</th>
              <th>Created</th>
            </tr>
          </thead>
          <tbody id="mcaApprovedBody">
            <tr><td colspan="8" class="mca-empty">Loading…</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="mca-panel">
      <div class="mca-panel-head">
        <div>
          <div class="mca-kicker">STALE PROOF SUBMITTED</div>
          <h2 class="mca-section-title">Proof Submitted &gt; 72 Hours</h2>
        </div>
      </div>
      <div class="mca-table-wrap">
        <table class="mca-table">
          <thead>
            <tr>
              <th>Request</th>
              <th>User</th>
              <th>Flow</th>
              <th>Amount</th>
              <th>Hours Old</th>
              <th>Proof TX</th>
              <th>Payout TX</th>
              <th>Created</th>
            </tr>
          </thead>
          <tbody id="mcaProofBody">
            <tr><td colspan="8" class="mca-empty">Loading…</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="mca-panel">
      <div class="mca-panel-head">
        <div>
          <div class="mca-kicker">AUTO ACTIONS</div>
          <h2 class="mca-section-title">Recent Auto-Cancel / Release / Consume Signals</h2>
        </div>
      </div>
      <div class="mca-table-wrap">
        <table class="mca-table">
          <thead>
            <tr>
              <th>Request</th>
              <th>User</th>
              <th>Flow</th>
              <th>Request Status</th>
              <th>Reserve Status</th>
              <th>Released</th>
              <th>Consumed</th>
              <th>Updated</th>
            </tr>
          </thead>
          <tbody id="mcaAutoBody">
            <tr><td colspan="8" class="mca-empty">Loading…</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="mca-panel">
      <div class="mca-panel-head">
        <div>
          <div class="mca-kicker">CRON LOGS</div>
          <h2 class="mca-section-title">Cron Anomaly Errors</h2>
        </div>
      </div>
      <div class="mca-table-wrap">
        <table class="mca-table">
          <thead>
            <tr>
              <th>Time</th>
              <th>Code</th>
              <th>Hint</th>
              <th>Context</th>
            </tr>
          </thead>
          <tbody id="mcaLogBody">
            <tr><td colspan="4" class="mca-empty">Loading…</td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </main>

  <?php
  if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php')) {
      require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php';
  }
  ?>

  <script>
    window.MANUAL_CLAIMS_ANOMALIES = {
      apiBase: "/rwa/api/storage/manual-claims",
      daysDefault: 7
    };
  </script>
  <script src="/rwa/storage/manual-claims/anomalies.js?v=20260324-1"></script>
</body>
</html>
