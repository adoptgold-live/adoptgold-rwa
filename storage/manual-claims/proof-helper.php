<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

function mch_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$csrfToken = '';
if (function_exists('csrf_token')) {
    try {
        $csrfToken = (string)csrf_token('manual_claims_proof_helper');
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
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Proof Helper</title></head><body style="background:#050607;color:#d7ffe9;font-family:ui-monospace,Menlo,Consolas,monospace;padding:24px;">ADMIN REQUIRED</body></html>';
    exit;
}

$claimProofEmxAddress = getenv('CLAIM_PROOF_EMX_ADDRESS') ?: ($_ENV['CLAIM_PROOF_EMX_ADDRESS'] ?? $_SERVER['CLAIM_PROOF_EMX_ADDRESS'] ?? '');
$signerPublicKey = getenv('SIGNER_PUBLIC_KEY') ?: ($_ENV['SIGNER_PUBLIC_KEY'] ?? $_SERVER['SIGNER_PUBLIC_KEY'] ?? '');
$treasuryFeeTon = '0.10';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Manual Claims Proof Helper</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="csrf-token" content="<?= mch_h($csrfToken) ?>">
  <link rel="stylesheet" href="/rwa/storage/manual-claims/proof-helper.css?v=20260324-1">
</head>
<body data-manual-claims-proof-root>
  <?php
  if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php')) {
      require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php';
  }
  ?>

  <main class="mch-page">
    <section class="mch-hero">
      <div class="mch-hero-head">
        <div>
          <div class="mch-kicker">STORAGE / MANUAL CLAIMS / PROOF</div>
          <h1 class="mch-title">ClaimProofEMX Proof Helper</h1>
          <div class="mch-subtitle">Admin-only helper for approved manual requests that need proof submission before payout tracking.</div>
        </div>
        <div class="mch-who">
          <div class="mch-chip">ADMIN</div>
          <div class="mch-mini"><?= mch_h($user['nickname'] ?? $user['email'] ?? $user['wallet_address'] ?? 'Admin') ?></div>
        </div>
      </div>
    </section>

    <section class="mch-panel">
      <div class="mch-config-grid">
        <div class="mch-config-card">
          <div class="mch-config-label">ClaimProofEMX Contract</div>
          <div class="mch-config-value" id="mchContractAddress"><?= mch_h($claimProofEmxAddress) ?></div>
        </div>
        <div class="mch-config-card">
          <div class="mch-config-label">Signer Public Key</div>
          <div class="mch-config-value" id="mchSignerKey"><?= mch_h($signerPublicKey) ?></div>
        </div>
        <div class="mch-config-card">
          <div class="mch-config-label">Treasury Fee</div>
          <div class="mch-config-value"><?= mch_h($treasuryFeeTon) ?> TON</div>
        </div>
      </div>
    </section>

    <section class="mch-panel">
      <div class="mch-filter-grid">
        <label class="mch-field">
          <span>Status</span>
          <select id="mchStatus">
            <option value="approved" selected>approved</option>
            <option value="proof_submitted">proof_submitted</option>
            <option value="">all</option>
          </select>
        </label>

        <label class="mch-field">
          <span>Flow Type</span>
          <select id="mchFlowType">
            <option value="">All</option>
            <option value="claim_ema">claim_ema</option>
            <option value="claim_wems">claim_wems</option>
            <option value="claim_usdt_ton">claim_usdt_ton</option>
            <option value="claim_emx_tips">claim_emx_tips</option>
            <option value="fuel_ems">fuel_ems</option>
          </select>
        </label>

        <label class="mch-field">
          <span>Request UID</span>
          <input id="mchRequestUid" type="text" placeholder="MTR-YYYYMMDD-XXXXXXXX">
        </label>

        <label class="mch-field">
          <span>User ID</span>
          <input id="mchUserId" type="number" min="1" step="1" placeholder="13">
        </label>

        <label class="mch-field">
          <span>Limit</span>
          <select id="mchLimit">
            <option value="50">50</option>
            <option value="100" selected>100</option>
            <option value="200">200</option>
          </select>
        </label>

        <div class="mch-actions">
          <button type="button" id="mchRefreshBtn" class="mch-btn mch-btn-primary">Refresh</button>
          <button type="button" id="mchResetBtn" class="mch-btn">Reset</button>
        </div>
      </div>

      <div id="mchStatusBar" class="mch-statusbar">Ready.</div>
    </section>

    <section class="mch-layout">
      <section class="mch-panel mch-left">
        <div class="mch-panel-head">
          <div>
            <div class="mch-kicker">APPROVED / PROOF NEEDED</div>
            <h2 class="mch-section-title">Eligible Requests</h2>
          </div>
        </div>

        <div class="mch-table-wrap">
          <table class="mch-table">
            <thead>
              <tr>
                <th>Request</th>
                <th>Flow</th>
                <th>Amount</th>
                <th>Recipient</th>
                <th>Status</th>
                <th>Claim</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="mchTableBody">
              <tr><td colspan="7" class="mch-empty">Loading…</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="mch-panel mch-right">
        <div class="mch-panel-head">
          <div>
            <div class="mch-kicker">PROOF PAYLOAD</div>
            <h2 class="mch-section-title">Selected Request</h2>
          </div>
        </div>

        <div id="mchSelectedEmpty" class="mch-empty-box">Select a request to build proof payload.</div>

        <div id="mchPayloadWrap" class="mch-payload-wrap" hidden>
          <div class="mch-meta-grid">
            <div class="mch-meta-card">
              <div class="mch-meta-label">Request UID</div>
              <div class="mch-meta-value" id="mchMetaRequestUid"></div>
            </div>
            <div class="mch-meta-card">
              <div class="mch-meta-label">Flow</div>
              <div class="mch-meta-value" id="mchMetaFlowType"></div>
            </div>
            <div class="mch-meta-card">
              <div class="mch-meta-label">Recipient Owner</div>
              <div class="mch-meta-value" id="mchMetaRecipient"></div>
            </div>
            <div class="mch-meta-card">
              <div class="mch-meta-label">Amount Units</div>
              <div class="mch-meta-value" id="mchMetaAmountUnits"></div>
            </div>
          </div>

          <form id="mchPayloadForm" class="mch-form">
            <label class="mch-field">
              <span>Claim Ref</span>
              <input type="text" id="mchClaimRef" name="claim_ref" placeholder="CLM-YYYYMMDD-XXXXXXXX">
            </label>

            <label class="mch-field">
              <span>Claim Nonce</span>
              <input type="number" id="mchClaimNonce" name="claim_nonce" min="1" step="1" placeholder="1">
            </label>

            <label class="mch-field">
              <span>Valid Until (unix seconds)</span>
              <input type="number" id="mchValidUntil" name="valid_until" min="1" step="1">
            </label>

            <label class="mch-field full">
              <span>Proof Contract</span>
              <input type="text" id="mchProofContract" name="proof_contract" placeholder="EQ...">
            </label>

            <div class="mch-inline-actions full">
              <button type="button" class="mch-btn" id="mchGenerateRefBtn">Generate Claim Ref</button>
              <button type="button" class="mch-btn" id="mchGenerateNonceBtn">Generate Nonce</button>
              <button type="button" class="mch-btn" id="mchGenerateValidBtn">+10 Min Valid Until</button>
            </div>
          </form>

          <div class="mch-code-block">
            <div class="mch-code-head">
              <span>Proof Payload Preview</span>
              <button type="button" class="mch-btn" id="mchCopyPayloadBtn">Copy JSON</button>
            </div>
            <pre id="mchPayloadPreview">{}</pre>
          </div>

          <div class="mch-proof-record">
            <div class="mch-proof-record-head">
              <div>
                <div class="mch-kicker">MARK PROOF</div>
                <h3 class="mch-subsection-title">Record Proof TX</h3>
              </div>
            </div>

            <form id="mchProofRecordForm" class="mch-form">
              <label class="mch-field full">
                <span>Proof TX Hash</span>
                <input type="text" id="mchProofTxHash" name="proof_tx_hash" placeholder="TON tx hash">
              </label>

              <label class="mch-field full">
                <span>Proof Contract</span>
                <input type="text" id="mchProofRecordContract" name="proof_contract" placeholder="EQ...">
              </label>

              <div class="mch-inline-actions full">
                <button type="submit" class="mch-btn mch-btn-primary" id="mchMarkProofBtn">Mark Proof Submitted</button>
              </div>
            </form>
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
    window.MANUAL_CLAIMS_PROOF = {
      csrfToken: <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      apiBase: "/rwa/api/storage/manual-claims",
      contractAddress: <?= json_encode((string)$claimProofEmxAddress, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      signerPublicKey: <?= json_encode((string)$signerPublicKey, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    };
  </script>
  <script src="/rwa/storage/manual-claims/proof-helper.js?v=20260324-1"></script>
</body>
</html>
