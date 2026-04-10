<?php
declare(strict_types=1);

/*
POAdo RWA Cert Engine
Treasury Monitor

File:
/rwa/cert/admin/treasury-monitor.php

Purpose:
Track treasury inflow and audit royalty ledger
*/

require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/dashboard/inc/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/dashboard/inc/bootstrap.php';

$wallet = get_wallet_session();
if (!$wallet) {
    header("Location: /rwa/index.php");
    exit;
}

db_connect();
$pdo = $GLOBALS['pdo'];

function val($pdo,$sql){
    try{
        return $pdo->query($sql)->fetchColumn() ?: 0;
    }catch(Throwable $e){
        return 0;
    }
}

/* treasury wallet */
$treasury_wallet = "UQBdYfGArtoCUmBs5TjYQtfPFfQuGC2Ydbj2pQr3zIlNrDta";

/* totals */

$total_income = val($pdo,"
SELECT SUM(royalty_amount_ton)
FROM poado_rwa_royalty_events
");

$treasury_retained = val($pdo,"
SELECT SUM(treasury_retained_ton)
FROM poado_rwa_royalty_events
");

$holder_pool = val($pdo,"
SELECT SUM(holder_pool_ton)
FROM poado_rwa_royalty_events
");

$ace_pool = val($pdo,"
SELECT SUM(ace_pool_ton)
FROM poado_rwa_royalty_events
");

$vault_pool = val($pdo,"
SELECT SUM(gold_packet_pool_ton)
FROM poado_rwa_royalty_events
");

/* last transactions */

$stmt = $pdo->query("
SELECT
event_uid,
cert_uid,
sale_amount_ton,
royalty_amount_ton,
treasury_tx_hash,
block_time
FROM poado_rwa_royalty_events
ORDER BY id DESC
LIMIT 50
");

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html>
<head>

<meta charset="utf-8">
<title>Treasury Monitor</title>
<meta name="viewport" content="width=device-width,initial-scale=1">

<style>

body{
background:#000;
color:#e8d9a5;
font-family:system-ui;
margin:0;
}

.wrap{
max-width:1200px;
margin:auto;
padding:20px;
}

.title{
font-size:22px;
color:#d4af37;
margin-bottom:20px;
}

.wallet{
font-family:monospace;
font-size:13px;
margin-bottom:20px;
}

.grid{
display:grid;
grid-template-columns:repeat(3,1fr);
gap:16px;
margin-bottom:30px;
}

.card{
background:#0b0b0b;
border:1px solid #6f5b1d;
padding:18px;
}

.label{
color:#d4af37;
font-size:13px;
}

.value{
font-size:22px;
margin-top:6px;
}

.table{
width:100%;
border-collapse:collapse;
}

.table th{
border-bottom:1px solid #6f5b1d;
padding:8px;
color:#d4af37;
font-size:13px;
}

.table td{
border-bottom:1px solid #222;
padding:8px;
font-size:13px;
}

.hash{
font-family:monospace;
font-size:12px;
}

</style>

</head>

<body>

<?php require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-topbar-nav.php'; ?>

<div class="wrap">

<?php require __DIR__.'/_nav.php'; ?>

<div class="title">
Treasury Monitor
</div>

<div class="wallet">
Treasury Wallet:<br>
<?=$treasury_wallet?>
</div>

<div class="grid">

<div class="card">
<div class="label">Total Royalty Income</div>
<div class="value"><?=$total_income?> TON</div>
</div>

<div class="card">
<div class="label">Treasury Retained</div>
<div class="value"><?=$treasury_retained?> TON</div>
</div>

<div class="card">
<div class="label">Holder Claim Pool</div>
<div class="value"><?=$holder_pool?> TON</div>
</div>

<div class="card">
<div class="label">ACE Pool</div>
<div class="value"><?=$ace_pool?> TON</div>
</div>

<div class="card">
<div class="label">Gold Packet Vault</div>
<div class="value"><?=$vault_pool?> TON</div>
</div>

</div>

<h3 style="color:#d4af37">Latest Royalty Transactions</h3>

<table class="table">

<thead>
<tr>
<th>Event</th>
<th>Cert UID</th>
<th>Sale TON</th>
<th>Royalty TON</th>
<th>TX Hash</th>
<th>Time</th>
</tr>
</thead>

<tbody>

<?php foreach($rows as $r): ?>

<tr>

<td><?=$r['event_uid']?></td>

<td><?=$r['cert_uid']?></td>

<td><?=$r['sale_amount_ton']?></td>

<td><?=$r['royalty_amount_ton']?></td>

<td class="hash"><?=substr($r['treasury_tx_hash'],0,16)?>...</td>

<td><?=$r['block_time']?></td>

</tr>

<?php endforeach ?>

</tbody>

</table>

</div>

<?php require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-bottom-nav.php'; ?>

</body>
</html>