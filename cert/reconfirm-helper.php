<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/session-user.php';

function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function out_json(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

db_connect();
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    out_json(['ok' => false, 'error' => 'DB_NOT_READY'], 500);
}

$certUid = trim((string)($_GET['cert_uid'] ?? $_POST['cert_uid'] ?? ''));
if ($certUid === '') {
    out_json(['ok' => false, 'error' => 'CERT_UID_REQUIRED'], 422);
}

$stmt = $pdo->prepare("
SELECT
  c.cert_uid,
  c.payment_ref AS cert_payment_ref,
  c.status AS cert_status,
  p.payment_ref,
  p.token_symbol,
  p.amount,
  p.amount_units,
  p.status AS payment_status,
  p.verified,
  p.tx_hash
FROM poado_rwa_certs c
LEFT JOIN poado_rwa_cert_payments p
  ON p.id = (
      SELECT p2.id
      FROM poado_rwa_cert_payments p2
      WHERE p2.cert_uid = c.cert_uid
      ORDER BY p2.id DESC
      LIMIT 1
  )
WHERE c.cert_uid = ?
LIMIT 1
");
$stmt->execute([$certUid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    out_json(['ok' => false, 'error' => 'CERT_NOT_FOUND'], 404);
}

if (isset($_GET['json']) && $_GET['json'] === '1') {
    out_json([
        'ok' => true,
        'row' => [
            'cert_uid' => (string)($row['cert_uid'] ?? ''),
            'payment_ref' => (string)($row['payment_ref'] ?? $row['cert_payment_ref'] ?? ''),
            'token_symbol' => (string)($row['token_symbol'] ?? ''),
            'amount' => (string)($row['amount'] ?? ''),
            'amount_units' => (string)($row['amount_units'] ?? ''),
            'payment_status' => (string)($row['payment_status'] ?? ''),
            'verified' => (int)($row['verified'] ?? 0),
            'tx_hash' => (string)($row['tx_hash'] ?? ''),
            'cert_status' => (string)($row['cert_status'] ?? '')
        ]
    ]);
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title>Reconfirm Payment Helper</title>
  <style>
    :root{
      --bg:#0b0d12;
      --card:#121722;
      --line:rgba(214,185,88,.28);
      --text:#f7f1d0;
      --muted:#a9b0c0;
      --gold:#d9bf62;
      --btn:#1b2436;
    }
    *{box-sizing:border-box}
    html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font-family:Arial,sans-serif}
    body{min-height:100dvh;padding:18px}
    .wrap{max-width:760px;margin:0 auto}
    .card{
      background:linear-gradient(180deg,#15110a 0%, #111722 100%);
      border:1px solid var(--line);
      border-radius:22px;
      padding:20px;
      box-shadow:0 12px 40px rgba(0,0,0,.35);
    }
    .kicker{font-size:12px;letter-spacing:.18em;color:var(--gold);margin-bottom:8px}
    h1{margin:0 0 16px;font-size:28px}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
    .field{background:rgba(24,30,44,.82);border:1px solid rgba(214,185,88,.14);border-radius:16px;padding:14px}
    .label{font-size:11px;letter-spacing:.14em;color:var(--muted);margin-bottom:8px}
    .value{font-size:20px;font-weight:700;word-break:break-word}
    .full{grid-column:1/-1}
    .note{margin-top:14px;color:#f9e8a8;font-size:15px;line-height:1.6}
    .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
    button{
      border:2px solid var(--gold);
      background:var(--gold);
      color:#2b2207;
      font-weight:800;
      border-radius:999px;
      padding:12px 18px;
      cursor:pointer;
    }
    button.secondary{
      background:var(--btn);
      color:#fff;
      border-color:rgba(214,185,88,.35);
    }
    .status{margin-top:16px;background:rgba(24,30,44,.82);border:1px solid rgba(214,185,88,.14);border-radius:16px;padding:14px;min-height:62px}
    .ok{color:#8df0a6}
    .warn{color:#ffd47a}
    .err{color:#ff9e9e}
    @media (max-width:720px){.grid{grid-template-columns:1fr}}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="kicker">PAYMENT REPAIR</div>
      <h1>Reconfirm Payment</h1>

      <div class="grid">
        <div class="field full">
          <div class="label">CERT UID</div>
          <div class="value" id="certUid"><?= h($row['cert_uid'] ?? '') ?></div>
        </div>
        <div class="field">
          <div class="label">TOKEN</div>
          <div class="value" id="tokenSymbol"><?= h($row['token_symbol'] ?? '') ?: '—' ?></div>
        </div>
        <div class="field">
          <div class="label">AMOUNT</div>
          <div class="value" id="amount"><?= h($row['amount'] ?? '') ?: '—' ?></div>
        </div>
        <div class="field full">
          <div class="label">PAYMENT REF</div>
          <div class="value" id="paymentRef"><?= h($row['payment_ref'] ?? $row['cert_payment_ref'] ?? '') ?: '—' ?></div>
        </div>
        <div class="field">
          <div class="label">PAYMENT STATUS</div>
          <div class="value" id="paymentStatus"><?= h($row['payment_status'] ?? '') ?: '—' ?></div>
        </div>
        <div class="field">
          <div class="label">VERIFIED</div>
          <div class="value" id="verified"><?= (int)($row['verified'] ?? 0) ?></div>
        </div>
        <div class="field full">
          <div class="label">TX HASH</div>
          <div class="value" id="txHash"><?= h($row['tx_hash'] ?? '') ?: '—' ?></div>
        </div>
      </div>

      <div class="note">
        This helper is for reconfirmation only. No QR is shown here, to avoid repeated payment.
      </div>

      <div class="actions">
        <button type="button" id="reconfirmBtn">Reconfirm Now</button>
        <button type="button" class="secondary" id="refreshBtn">Refresh</button>
        <button type="button" class="secondary" id="closeBtn">Close</button>
      </div>

      <div class="status" id="statusBox">Ready.</div>
    </div>
  </div>

  <script>
    const certUid = <?= json_encode($certUid, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    function setStatus(msg, cls) {
      const box = document.getElementById('statusBox');
      box.className = 'status ' + (cls || '');
      box.textContent = msg;
    }

    async function refreshView() {
      const r = await fetch('/rwa/cert/reconfirm-helper.php?json=1&cert_uid=' + encodeURIComponent(certUid), {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      });
      const j = await r.json();
      if (!r.ok || !j.ok) {
        throw new Error(j.error || 'REFRESH_FAILED');
      }
      const row = j.row || {};
      document.getElementById('certUid').textContent = row.cert_uid || '—';
      document.getElementById('tokenSymbol').textContent = row.token_symbol || '—';
      document.getElementById('amount').textContent = row.amount || '—';
      document.getElementById('paymentRef').textContent = row.payment_ref || '—';
      document.getElementById('paymentStatus').textContent = row.payment_status || '—';
      document.getElementById('verified').textContent = String(row.verified ?? 0);
      document.getElementById('txHash').textContent = row.tx_hash || '—';
      setStatus('Refreshed.', 'ok');
    }

    async function reconfirmNow() {
      setStatus('Reconfirming payment…', 'warn');
      const r = await fetch('/rwa/cert/api/confirm-payment.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({ cert_uid: certUid })
      });
      const txt = await r.text();
      let j = null;
      try { j = JSON.parse(txt); } catch (_) {}
      if (!r.ok || !j || j.ok === false) {
        throw new Error((j && (j.error || j.detail || j.message)) || ('HTTP_' + r.status));
      }
      await refreshView();
      setStatus('Payment reconfirmed successfully.', 'ok');
      if (window.opener && !window.opener.closed) {
        window.opener.dispatchEvent(new CustomEvent('cert:queue-refresh'));
      }
    }

    document.getElementById('reconfirmBtn').addEventListener('click', async () => {
      try {
        await reconfirmNow();
      } catch (e) {
        setStatus(String(e.message || e), 'err');
      }
    });

    document.getElementById('refreshBtn').addEventListener('click', async () => {
      try {
        await refreshView();
      } catch (e) {
        setStatus(String(e.message || e), 'err');
      }
    });

    document.getElementById('closeBtn').addEventListener('click', () => window.close());
  </script>
</body>
</html>
