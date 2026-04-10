<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/admin/royalty-claims.php
 *
 * Admin claim monitor.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

$user = rwa_require_login();

if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

function rcl_pdo(): PDO
{
    if (function_exists('db')) return db();
    if (function_exists('rwa_db')) return rwa_db();
    if (($GLOBALS['pdo'] ?? null) instanceof PDO) return $GLOBALS['pdo'];
    throw new RuntimeException('PDO_NOT_AVAILABLE');
}

$pdo = rcl_pdo();

$summary = $pdo->query("
    SELECT
      COALESCE(COUNT(*),0) AS total_claims,
      COALESCE(SUM(amount_ton),0) AS total_amount_ton,
      COALESCE(SUM(CASE WHEN status='pending' THEN amount_ton ELSE 0 END),0) AS pending_ton,
      COALESCE(SUM(CASE WHEN status='queued' THEN amount_ton ELSE 0 END),0) AS queued_ton,
      COALESCE(SUM(CASE WHEN status='approved' THEN amount_ton ELSE 0 END),0) AS approved_ton,
      COALESCE(SUM(CASE WHEN status='paid' THEN amount_ton ELSE 0 END),0) AS paid_ton
    FROM poado_rwa_royalty_claims
")->fetch(PDO::FETCH_ASSOC) ?: [];

$rows = $pdo->query("
    SELECT id, claim_ref, owner_user_id, ton_wallet, claim_type, amount_ton, treasury_fee_ton, kyc_verified, claim_tx_hash, status, created_at
    FROM poado_rwa_royalty_claims
    ORDER BY id DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Royalty Claims</title>
  <style>
    body{margin:0;background:#09070f;color:#eee;font:14px/1.45 Arial,sans-serif}
    .wrap{max-width:1200px;margin:0 auto;padding:18px}
    .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
    .card{background:#151021;border:1px solid rgba(166,120,255,.25);border-radius:16px;padding:14px}
    .k{font-size:12px;color:#bca9ff}.v{font-size:22px;font-weight:700;margin-top:6px}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px 8px;border-bottom:1px solid rgba(255,255,255,.08);text-align:left;font-size:13px}
    h1,h2{margin:0 0 12px}
    .mt{margin-top:16px}
    @media(max-width:900px){.grid{grid-template-columns:1fr}}
  </style>
</head>
<body>
<div class="wrap">
  <h1>Royalty Claims Monitor</h1>

  <div class="grid">
    <div class="card"><div class="k">Total Claims</div><div class="v"><?= htmlspecialchars((string)$summary['total_claims']) ?></div></div>
    <div class="card"><div class="k">Total Claim TON</div><div class="v"><?= htmlspecialchars((string)$summary['total_amount_ton']) ?></div></div>
    <div class="card"><div class="k">Pending TON</div><div class="v"><?= htmlspecialchars((string)$summary['pending_ton']) ?></div></div>
    <div class="card"><div class="k">Queued TON</div><div class="v"><?= htmlspecialchars((string)$summary['queued_ton']) ?></div></div>
    <div class="card"><div class="k">Approved TON</div><div class="v"><?= htmlspecialchars((string)$summary['approved_ton']) ?></div></div>
    <div class="card"><div class="k">Paid TON</div><div class="v"><?= htmlspecialchars((string)$summary['paid_ton']) ?></div></div>
  </div>

  <div class="card mt">
    <h2>Latest Claims</h2>
    <table>
      <thead>
      <tr>
        <th>ID</th>
        <th>Claim Ref</th>
        <th>User</th>
        <th>Wallet</th>
        <th>Type</th>
        <th>Amount TON</th>
        <th>Treasury Fee</th>
        <th>KYC</th>
        <th>TX Hash</th>
        <th>Status</th>
        <th>Created</th>
      </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= htmlspecialchars((string)$r['id']) ?></td>
          <td><?= htmlspecialchars((string)$r['claim_ref']) ?></td>
          <td><?= htmlspecialchars((string)$r['owner_user_id']) ?></td>
          <td><?= htmlspecialchars((string)$r['ton_wallet']) ?></td>
          <td><?= htmlspecialchars((string)$r['claim_type']) ?></td>
          <td><?= htmlspecialchars((string)$r['amount_ton']) ?></td>
          <td><?= htmlspecialchars((string)$r['treasury_fee_ton']) ?></td>
          <td><?= htmlspecialchars((string)$r['kyc_verified']) ?></td>
          <td><?= htmlspecialchars((string)$r['claim_tx_hash']) ?></td>
          <td><?= htmlspecialchars((string)$r['status']) ?></td>
          <td><?= htmlspecialchars((string)$r['created_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
