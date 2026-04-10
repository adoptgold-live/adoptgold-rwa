<?php
declare(strict_types=1);

/**
 * POAdo RWA Cert Engine
 * Admin Royalty Scanner Dashboard
 *
 * File:
 * /rwa/cert/admin/royalty-scanner.php
 *
 * Purpose:
 * - Manual trigger for TON royalty scanner
 * - View latest royalty events
 * - Monitor treasury inflow
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

$stmt = $pdo->query("
SELECT
event_uid,
treasury_tx_hash,
sale_amount_ton,
royalty_amount_ton,
marketplace,
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
<title>Royalty Scanner</title>
<meta name="viewport" content="width=device-width,initial-scale=1">

<style>

body{
background:#000;
color:#e8d9a5;
font-family:system-ui;
margin:0;
}

.wrap{
max-width:1100px;
margin:auto;
padding:20px;
}

.title{
font-size:22px;
color:#d4af37;
margin-bottom:20px;
}

.btn{
background:#111;
border:1px solid #6f5b1d;
color:#e8d9a5;
padding:10px 16px;
cursor:pointer;
}

.table{
width:100%;
border-collapse:collapse;
margin-top:20px;
}

.table th{
border-bottom:1px solid #6f5b1d;
color:#d4af37;
padding:8px;
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

<div class="wrap">

<div class="title">
RWA Royalty Scanner
</div>

<button class="btn" onclick="runScan()">
Run Royalty Scan
</button>

<div id="result" style="margin-top:10px;"></div>

<table class="table">

<thead>
<tr>
<th>Event UID</th>
<th>Tx Hash</th>
<th>Sale TON</th>
<th>Royalty TON</th>
<th>Marketplace</th>
<th>Block Time</th>
</tr>
</thead>

<tbody>

<?php foreach($rows as $r): ?>

<tr>

<td><?=htmlspecialchars($r["event_uid"])?></td>

<td class="hash">
<?=substr($r["treasury_tx_hash"],0,16)?>...
</td>

<td><?=$r["sale_amount_ton"]?></td>

<td><?=$r["royalty_amount_ton"]?></td>

<td><?=$r["marketplace"]?></td>

<td><?=$r["block_time"]?></td>

</tr>

<?php endforeach ?>

</tbody>

</table>

</div>

<script>

async function runScan(){

let res = await fetch('/rwa/cert/api/scan-royalties.php');

let j = await res.json();

document.getElementById("result").innerHTML =
"Inserted events: "+j.inserted;

}

</script>

</body>
</html>