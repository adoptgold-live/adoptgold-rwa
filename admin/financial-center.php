<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/dashboard/inc/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/dashboard/inc/bootstrap.php';

db_connect();
$pdo = $GLOBALS['pdo'];

$total = $pdo->query("
SELECT SUM(royalty_amount_ton)
FROM poado_rwa_royalty_events
")->fetchColumn();

$holder = $pdo->query("
SELECT SUM(holder_pool_ton)
FROM poado_rwa_royalty_events
")->fetchColumn();

$ace = $pdo->query("
SELECT SUM(ace_pool_ton)
FROM poado_rwa_royalty_events
")->fetchColumn();

$vault = $pdo->query("
SELECT SUM(gold_packet_pool_ton)
FROM poado_rwa_royalty_events
")->fetchColumn();

$treasury = $pdo->query("
SELECT SUM(treasury_retained_ton)
FROM poado_rwa_royalty_events
")->fetchColumn();

?>

<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Financial Center</title>

<style>

body{
background:#000;
color:#e8d9a5;
font-family:system-ui;
margin:0;
}

.wrap{
max-width:1000px;
margin:auto;
padding:20px;
}

.grid{
display:grid;
grid-template-columns:repeat(2,1fr);
gap:20px;
}

.card{
background:#0b0b0b;
border:1px solid #6f5b1d;
padding:20px;
}

.label{
color:#d4af37;
font-size:14px;
}

.value{
font-size:22px;
margin-top:6px;
}

</style>

</head>

<body>

<?php require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-topbar-nav.php'; ?>

<div class="wrap">

<?php require __DIR__.'/_nav.php'; ?>

<div class="grid">

<div class="card">
<div class="label">Total Royalties</div>
<div class="value"><?=$total?> TON</div>
</div>

<div class="card">
<div class="label">Holder Pool</div>
<div class="value"><?=$holder?> TON</div>
</div>

<div class="card">
<div class="label">ACE Pool</div>
<div class="value"><?=$ace?> TON</div>
</div>

<div class="card">
<div class="label">Gold Packet Vault</div>
<div class="value"><?=$vault?> TON</div>
</div>

<div class="card">
<div class="label">Treasury Retained</div>
<div class="value"><?=$treasury?> TON</div>
</div>

</div>

</div>

<?php require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-bottom-nav.php'; ?>

</body>
</html>