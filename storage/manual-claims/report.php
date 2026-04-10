<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

function mcr_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$csrfToken = '';
if (function_exists('csrf_token')) {
    try {
        $csrfToken = (string)csrf_token('manual_claims_report');
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
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Manual Claims Report</title></head><body style="background:#050607;color:#d7ffe9;font-family:ui-monospace,Menlo,Consolas,monospace;padding:24px;">ADMIN REQUIRED</body></html>';
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Manual Claims Report</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="csrf-token" content="<?= mcr_h($csrfToken) ?>">
  <link rel="stylesheet" href="/rwa/storage/manual-claims/report.css?v=20260324-1">
</head>
<body data-manual-claims-report-root>
  <?php
  if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php')) {
      require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php';
  }
  ?>

  <main class="mcr-page">
    <section class="mcr-hero">
      <div class="mcr-hero-head">
        <div>
          <div class="mcr-kicker">STORAGE / MANUAL CLAIMS / REPORT</div>
          <h1 class="mcr-title">Manual Claims Payout Ledger</h1>
          <div class="mcr-subtitle">Unified audit and reporting view for claim_ema, claim_wems, claim_usdt_ton, claim_emx_tips, and fuel_ems.</div>
        </div>
        <div class="mcr-who">
          <div class="mcr-chip">ADMIN</div>
          <div class="mcr-mini"><?= mcr_h($user['nickname'] ?? $user['email'] ?? $user['wallet_address'] ?? 'Admin') ?></div>
        </div>
      </div>
    </section>

    <section class="mcr-summary-grid">
      <div class="mcr-summary-card">
        <div class="mcr-summary-label">Requested</div>
        <div class="mcr-summary-value" id="mcrRequestedCount">0</div>
      </div>
      <div class="mcr-summary-card">
        <div class="mcr-summary-label">Approved</div>
        <div class="mcr-summary-value" id="mcrApprovedCount">0</div>
      </div>
      <div class="mcr-summary-card">
        <div class="mcr-summary-label">Proof Submitted</div>
        <div class="mcr-summary-value" id="mcrProofCount">0</div>
      </div>
      <div class="mcr-summary-card">
        <div class="mcr-summary-label">Paid</div>
        <div class="mcr-summary-value" id="mcrPaidCount">0</div>
      </div>
      <div class="mcr-summary-card">
        <div class="mcr-summary-label">Rejected</div>
        <div class="mcr-summary-value" id="mcrRejectedCount">0</div>
      </div>
    </section>

    <section class="mcr-panel">
      <div class="mcr-filter-grid">
        <label class="mcr-field">
          <span>Status</span>
          <select id="mcrStatus">
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

        <label class="mcr-field">
          <span>Flow Type</span>
          <select id="mcrFlowType">
            <option value="">All</option>
            <option value="claim_ema">claim_ema</option>
            <option value="claim_wems">claim_wems</option>
            <option value="claim_usdt_ton">claim_usdt_ton</option>
            <option value="claim_emx_tips">claim_emx_tips</option>
            <option value="fuel_ems">fuel_ems</option>
          </select>
        </label>

        <label class="mcr-field">
          <span>Request UID</span>
          <input id="mcrRequestUid" type="text" placeholder="MTR-YYYYMMDD-XXXXXXXX">
        </label>

        <label class="mcr-field">
          <span>User ID</span>
          <input id="mcrUserId" type="number" min="1" step="1" placeholder="13">
        </label>

        <label class="mcr-field">
          <span>Limit</span>
          <select id="mcrLimit">
            <option value="50">50</option>
            <option value="100" selected>100</option>
            <option value="200">200</option>
            <option value="500">500</option>
          </select>
        </label>

        <div class="mcr-actions">
          <button type="button" id="mcrRefreshBtn" class="mcr-btn mcr-btn-primary">Refresh</button>
          <button type="button" id="mcrResetBtn" class="mcr-btn">Reset</button>
        </div>
      </div>

      <div id="mcrStatusBar" class="mcr-statusbar">Ready.</div>
    </section>

    <section class="mcr-layout">
      <section class="mcr-panel mcr-left">
        <div class="mcr-panel-head">
          <div>
            <div class="mcr-kicker">LEDGER</div>
            <h2 class="mcr-section-title">Manual Claims Ledger</h2>
          </div>
        </div>

        <div class="mcr-table-wrap">
          <table class="mcr-table">
            <thead>
              <tr>
                <th>Request</th>
                <th>User</th>
                <th>Flow</th>
                <th>Token</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Claim</th>
                <th>Proof</th>
                <th>Payout</th>
                <th>Approved</th>
                <th>Paid</th>
                <th>Created</th>
              </tr>
            </thead>
            <tbody id="mcrTableBody">
              <tr><td colspan="12" class="mcr-empty">Loading…</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="mcr-panel mcr-right">
        <div class="mcr-panel-head">
          <div>
            <div class="mcr-kicker">DETAIL</div>
            <h2 class="mcr-section-title">Selected Row</h2>
          </div>
        </div>

        <div id="mcrSelectedEmpty" class="mcr-empty-box">Select a ledger row to inspect full details.</div>

        <div id="mcrDetailWrap" class="mcr-detail-wrap" hidden>
          <div class="mcr-meta-grid">
            <div class="mcr-meta-card">
              <div class="mcr-meta-label">Request UID</div>
              <div class="mcr-meta-value" id="mcrMetaRequestUid"></div>
            </div>
            <div class="mcr-meta-card">
              <div class="mcr-meta-label">Flow Type</div>
              <div class="mcr-meta-value" id="mcrMetaFlowType"></div>
            </div>
            <div class="mcr-meta-card">
              <div class="mcr-meta-label">User ID</div>
              <div class="mcr-meta-value" id="mcrMetaUserId"></div>
            </div>
            <div class="mcr-meta-card">
              <div class="mcr-meta-label">Status</div>
              <div class="mcr-meta-value" id="mcrMetaStatus"></div>
            </div>
            <div class="mcr-meta-card">
              <div class="mcr-meta-label">Wallet Address</div>
              <div class="mcr-meta-value" id="mcrMetaWallet"></div>
            </div>
            <div class="mcr-meta-card">
              <div class="mcr-meta-label">Recipient Owner</div>
              <div class="mcr-meta-value" id="mcrMetaRecipient"></div>
            </div>
            <div class="mcr-meta-card">
              <div class="mcr-meta-label">Request Token</div>
              <div class="mcr-meta-value" id="mcrMetaRequestToken"></div>
            </div>
            <div class="mcr-meta-card">
              <div class="mcr-meta-label">Settle Token</div>
              <div class="mcr-meta-value" id="mcrMetaSettleToken"></div>
            </div>
            <div class="mcr-meta-card">
              <div class="mcr-meta-label">Amount Display</div>
              <div class="mcr-meta-value" id="mcrMetaAmountDisplay"></div>
            </div>
            <div class="mcr-meta-card">
              <div class="mcr-meta-label">Amount Units</div>
              <div class="mcr-meta-value" id="mcrMetaAmountUnits"></div>
            </div>
            <div class="mcr-meta-card">
              <div class="mcr-meta-label">Claim Ref</div>
              <div class="mcr-meta-value" id="mcrMetaClaimRef"></div>
            </div>
            <div class="mcr-meta-card">
              <div class="mcr-meta-label">Claim Nonce</div>
              <div class="mcr-meta-value" id="mcrMetaClaimNonce"></div>
            </div>
            <div class="mcr-meta-card">
              <div class="mcr-meta-label">Proof Contract</div>
              <div class="mcr-meta-value" id="mcrMetaProofContract"></div>
            </div>
            <div class="mcr-meta-card">
              <div class="mcr-meta-label">Proof TX</div>
              <div class="mcr-meta-value" id="mcrMetaProofTx"></div>
            </div>
            <div class="mcr-meta-card">
              <div class="mcr-meta-label">Payout Wallet</div>
              <div class="mcr-meta-value" id="mcrMetaPayoutWallet"></div>
            </div>
            <div class="mcr-meta-card">
              <div class="mcr-meta-label">Payout TX</div>
              <div class="mcr-meta-value" id="mcrMetaPayoutTx"></div>
            </div>
            <div class="mcr-meta-card">
              <div class="mcr-meta-label">Approved At</div>
              <div class="mcr-meta-value" id="mcrMetaApprovedAt"></div>
            </div>
            <div class="mcr-meta-card">
              <div class="mcr-meta-label">Paid At</div>
              <div class="mcr-meta-value" id="mcrMetaPaidAt"></div>
            </div>
            <div class="mcr-meta-card">
              <div class="mcr-meta-label">Rejected At</div>
              <div class="mcr-meta-value" id="mcrMetaRejectedAt"></div>
            </div>
            <div class="mcr-meta-card">
              <div class="mcr-meta-label">Created At</div>
              <div class="mcr-meta-value" id="mcrMetaCreatedAt"></div>
            </div>
          </div>

          <div class="mcr-json-block">
            <div class="mcr-json-head">Meta JSON</div>
            <pre id="mcrMetaJson">{}</pre>
          </div>
        </div>
      </section>
    </section>
  </main>

  <?php
  if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php')) {
      require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php';
  }
  ?>

  <script>
    window.MANUAL_CLAIMS_REPORT = {
      csrfToken: <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      apiBase: "/rwa/api/storage/manual-claims"
    };
  </script>
  <script src="/rwa/storage/manual-claims/report.js?v=20260324-1"></script>
</body>
</html>
