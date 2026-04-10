<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/admin/royalty-dashboard.php
 *
 * Locked admin royalty control center.
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

function rd_pdo(): PDO
{
    if (function_exists('db')) return db();
    if (function_exists('rwa_db')) return rwa_db();
    if (($GLOBALS['pdo'] ?? null) instanceof PDO) return $GLOBALS['pdo'];
    throw new RuntimeException('PDO_NOT_AVAILABLE');
}

$pdo = rd_pdo();

$summary = $pdo->query("
    SELECT
      COALESCE(COUNT(*),0) AS total_events,
      COALESCE(SUM(sale_amount_ton),0) AS total_sales_ton,
      COALESCE(SUM(royalty_amount_ton),0) AS total_royalty_ton,
      COALESCE(SUM(treasury_ton),0) AS treasury_ton,
      COALESCE(SUM(rewards_pool_ton),0) AS rewards_pool_ton,
      COALESCE(SUM(gold_packet_ton),0) AS gold_packet_ton
    FROM poado_rwa_royalty_events_v2
")->fetch(PDO::FETCH_ASSOC) ?: [];

$claimSummary = $pdo->query("
    SELECT
      COALESCE(COUNT(*),0) AS total_claims,
      COALESCE(SUM(amount_ton),0) AS total_claim_amount_ton,
      COALESCE(SUM(CASE WHEN status='paid' THEN amount_ton ELSE 0 END),0) AS total_paid_ton,
      COALESCE(SUM(CASE WHEN status='pending' THEN amount_ton ELSE 0 END),0) AS total_pending_ton
    FROM poado_rwa_royalty_claims
")->fetch(PDO::FETCH_ASSOC) ?: [];

$recent = $pdo->query("
    SELECT id, event_ref, sale_tx_hash, sale_amount_ton, royalty_amount_ton, treasury_ton, rewards_pool_ton, gold_packet_ton, snapshot_ref, created_at
    FROM poado_rwa_royalty_events_v2
    ORDER BY id DESC
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Royalty Dashboard</title>
  <style>
    body{margin:0;background:#0b0713;color:#eee;font:14px/1.45 Arial,sans-serif}
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
  <h1>Royalty Dashboard</h1>

  <div class="grid">
    <div class="card"><div class="k">Total Events</div><div class="v"><?= htmlspecialchars((string)$summary['total_events']) ?></div></div>
    <div class="card"><div class="k">Total Sales TON</div><div class="v"><?= htmlspecialchars((string)$summary['total_sales_ton']) ?></div></div>
    <div class="card"><div class="k">Total Royalty TON</div><div class="v"><?= htmlspecialchars((string)$summary['total_royalty_ton']) ?></div></div>
    <div class="card"><div class="k">Treasury TON</div><div class="v"><?= htmlspecialchars((string)$summary['treasury_ton']) ?></div></div>
    <div class="card"><div class="k">Rewards Pool TON</div><div class="v"><?= htmlspecialchars((string)$summary['rewards_pool_ton']) ?></div></div>
    <div class="card"><div class="k">Gold Packet TON</div><div class="v"><?= htmlspecialchars((string)$summary['gold_packet_ton']) ?></div></div>
  </div>

  <div class="grid mt">
    <div class="card"><div class="k">Total Claims</div><div class="v"><?= htmlspecialchars((string)$claimSummary['total_claims']) ?></div></div>
    <div class="card"><div class="k">Claim Amount TON</div><div class="v"><?= htmlspecialchars((string)$claimSummary['total_claim_amount_ton']) ?></div></div>
    <div class="card"><div class="k">Pending Claims TON</div><div class="v"><?= htmlspecialchars((string)$claimSummary['total_pending_ton']) ?></div></div>
  </div>

  <div class="card mt">
    <h2>Recent Royalty Events</h2>
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Event Ref</th>
          <th>Sale TX</th>
          <th>Sale TON</th>
          <th>Royalty TON</th>
          <th>Treasury</th>
          <th>Rewards</th>
          <th>Gold Packet</th>
          <th>Snapshot</th>
          <th>Created</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($recent as $r): ?>
        <tr>
          <td><?= htmlspecialchars((string)$r['id']) ?></td>
          <td><?= htmlspecialchars((string)$r['event_ref']) ?></td>
          <td><?= htmlspecialchars((string)$r['sale_tx_hash']) ?></td>
          <td><?= htmlspecialchars((string)$r['sale_amount_ton']) ?></td>
          <td><?= htmlspecialchars((string)$r['royalty_amount_ton']) ?></td>
          <td><?= htmlspecialchars((string)$r['treasury_ton']) ?></td>
          <td><?= htmlspecialchars((string)$r['rewards_pool_ton']) ?></td>
          <td><?= htmlspecialchars((string)$r['gold_packet_ton']) ?></td>
          <td><?= htmlspecialchars((string)$r['snapshot_ref']) ?></td>
          <td><?= htmlspecialchars((string)$r['created_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
