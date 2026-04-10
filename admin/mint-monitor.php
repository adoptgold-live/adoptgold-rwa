<?php
declare(strict_types=1);

/*
POAdo RWA Cert Engine
Admin Mint Monitor

File:
/rwa/cert/admin/mint-monitor.php

Purpose:
Monitor mint pipeline and detect stuck mint processes
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
id,
cert_uid,
cert_type,
status,
meta,
created_at,
updated_at
FROM poado_rwa_certs
WHERE status IN ('paid','mint_pending','minted')
ORDER BY id DESC
LIMIT 100
");

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

function meta($meta,$k){
    $m = json_decode($meta,true);
    return $m[$k] ?? '';
}

?>
<!DOCTYPE html>
<html>
<head>

<meta charset="utf-8">
<title>Mint Monitor</title>
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
margin-bottom:10px;
}

.table{
width:100%;
border-collapse:collapse;
margin-top:20px;
}

.table th{
color:#d4af37;
border-bottom:1px solid #6f5b1d;
padding:8px;
font-size:13px;
}

.table td{
border-bottom:1px solid #222;
padding:8px;
font-size:13px;
}

.badge{
padding:3px 8px;
border:1px solid #6f5b1d;
font-size:12px;
border-radius:4px;
}

.badge.paid{color:#ffcf66;}
.badge.mint_pending{color:#f0a0ff;}
.badge.minted{color:#6cff6c;}

</style>

</head>

<body>

<?php require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-topbar-nav.php'; ?>

<div class="wrap">

<?php require __DIR__.'/_nav.php'; ?>

<div class="title">
Mint Pipeline Monitor
</div>

<table class="table">

<thead>
<tr>
<th>ID</th>
<th>Cert UID</th>
<th>Type</th>
<th>Status</th>
<th>NFT Address</th>
<th>TX Hash</th>
<th>Vault</th>
<th>Created</th>
</tr>
</thead>

<tbody>

<?php foreach($rows as $r): ?>

<tr>

<td><?=$r['id']?></td>

<td><?=$r['cert_uid']?></td>

<td><?=$r['cert_type']?></td>

<td>
<span class="badge <?=$r['status']?>">
<?=$r['status']?>
</span>
</td>

<td><?=meta($r['meta'],'nft_item_address')?></td>

<td><?=meta($r['meta'],'tx_hash')?></td>

<td><?=meta($r['meta'],'vault')?></td>

<td><?=$r['created_at']?></td>

</tr>

<?php endforeach ?>

</tbody>

</table>

</div>

<?php require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-bottom-nav.php'; ?>

</body>
</html>