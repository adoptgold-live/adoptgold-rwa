<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/admin/royalty-manual-actions.php
 *
 * Admin manual controls for royalty engine.
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
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Royalty Manual Actions</title>
  <style>
    body{margin:0;background:#0a0812;color:#eee;font:14px/1.45 Arial,sans-serif}
    .wrap{max-width:980px;margin:0 auto;padding:18px}
    .card{background:#151021;border:1px solid rgba(166,120,255,.25);border-radius:16px;padding:14px;margin-bottom:16px}
    h1,h2{margin:0 0 12px}
    label{display:block;margin:10px 0 6px;color:#bca9ff}
    input,textarea{width:100%;box-sizing:border-box;background:#0d0a15;color:#fff;border:1px solid rgba(255,255,255,.12);border-radius:10px;padding:10px}
    button{margin-top:12px;background:#7c4dff;color:#fff;border:0;border-radius:10px;padding:10px 14px;cursor:pointer}
    pre{background:#0d0a15;border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:10px;overflow:auto}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    @media(max-width:860px){.grid{grid-template-columns:1fr}}
  </style>
</head>
<body>
<div class="wrap">
  <h1>Royalty Manual Actions</h1>

  <div class="card">
    <h2>Run Engine Jobs</h2>
    <div class="grid">
      <div>
        <button onclick="runApi('/rwa/api/cert/admin-royalty-run-snapshot.php')">Run Snapshot</button>
        <button onclick="runApi('/rwa/api/cert/admin-royalty-run-claim-queue.php')">Run Claim Queue</button>
        <button onclick="runApi('/rwa/api/cert/admin-royalty-run-claim-payout.php')">Run Claim Payout</button>
      </div>
      <div><pre id="jobOut">Ready</pre></div>
    </div>
  </div>

  <div class="card">
    <h2>Create Manual Royalty Event</h2>
    <label>Collection Address</label>
    <input id="collection_address" placeholder="Collection address">
    <label>NFT Item Address</label>
    <input id="nft_item_address" placeholder="NFT item address">
    <label>Sale TX Hash</label>
    <input id="sale_tx_hash" placeholder="Sale tx hash">
    <label>Seller Wallet</label>
    <input id="seller_wallet" placeholder="Seller wallet">
    <label>Buyer Wallet</label>
    <input id="buyer_wallet" placeholder="Buyer wallet">
    <label>Sale Amount TON</label>
    <input id="sale_amount_ton" placeholder="100">
    <label>Royalty Amount TON (leave blank to use 25%)</label>
    <input id="royalty_amount_ton" placeholder="25">
    <button onclick="createEvent()">Create Event</button>
    <pre id="eventOut">Ready</pre>
  </div>

  <div class="card">
    <h2>Approve Claim</h2>
    <label>Claim Ref</label>
    <input id="approve_claim_ref" placeholder="RCLM-...">
    <button onclick="approveClaim()">Approve Claim</button>
    <pre id="approveOut">Ready</pre>
  </div>

  <div class="card">
    <h2>Mark Claim Paid</h2>
    <label>Claim Ref</label>
    <input id="paid_claim_ref" placeholder="RCLM-...">
    <label>Claim TX Hash</label>
    <input id="claim_tx_hash" placeholder="Optional payout tx hash">
    <button onclick="markPaid()">Mark Paid</button>
    <pre id="paidOut">Ready</pre>
  </div>
</div>

<script>
async function runApi(url, payload = {}) {
  const res = await fetch(url, {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  });
  return res.json();
}

async function createEvent() {
  const payload = {
    collection_address: document.getElementById('collection_address').value,
    nft_item_address: document.getElementById('nft_item_address').value,
    sale_tx_hash: document.getElementById('sale_tx_hash').value,
    seller_wallet: document.getElementById('seller_wallet').value,
    buyer_wallet: document.getElementById('buyer_wallet').value,
    sale_amount_ton: document.getElementById('sale_amount_ton').value,
    royalty_amount_ton: document.getElementById('royalty_amount_ton').value
  };
  const out = await runApi('/rwa/api/cert/admin-royalty-event-create.php', payload);
  document.getElementById('eventOut').textContent = JSON.stringify(out, null, 2);
}

async function approveClaim() {
  const payload = { claim_ref: document.getElementById('approve_claim_ref').value };
  const out = await runApi('/rwa/api/cert/admin-royalty-claim-approve.php', payload);
  document.getElementById('approveOut').textContent = JSON.stringify(out, null, 2);
}

async function markPaid() {
  const payload = {
    claim_ref: document.getElementById('paid_claim_ref').value,
    claim_tx_hash: document.getElementById('claim_tx_hash').value
  };
  const out = await runApi('/rwa/api/cert/admin-royalty-claim-mark-paid.php', payload);
  document.getElementById('paidOut').textContent = JSON.stringify(out, null, 2);
}

document.querySelectorAll('button').forEach(btn=>{
  if (btn.textContent.includes('Run Snapshot')) {
    btn.addEventListener('click', async ()=>{ document.getElementById('jobOut').textContent = JSON.stringify(await runApi('/rwa/api/cert/admin-royalty-run-snapshot.php'), null, 2); });
  }
  if (btn.textContent.includes('Run Claim Queue')) {
    btn.addEventListener('click', async ()=>{ document.getElementById('jobOut').textContent = JSON.stringify(await runApi('/rwa/api/cert/admin-royalty-run-claim-queue.php'), null, 2); });
  }
  if (btn.textContent.includes('Run Claim Payout')) {
    btn.addEventListener('click', async ()=>{ document.getElementById('jobOut').textContent = JSON.stringify(await runApi('/rwa/api/cert/admin-royalty-run-claim-payout.php'), null, 2); });
  }
});
</script>
</body>
</html>
