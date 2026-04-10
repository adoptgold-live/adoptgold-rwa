<?php
declare(strict_types=1);

/**
 * POAdo Ecosystem Monitor
 *
 * File:
 * /rwa/cert/admin/ecosystem-monitor.php
 *
 * Purpose:
 * Global system health dashboard
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

$miners = val($pdo,"SELECT COUNT(*) FROM users WHERE role='adoptee'");
$total_certs = val($pdo,"SELECT COUNT(*) FROM poado_rwa_certs");
$minted_certs = val($pdo,"SELECT COUNT(*) FROM poado_rwa_certs WHERE status='minted'");

$royalty = val($pdo,"
SELECT SUM(royalty_amount_ton)
FROM poado_rwa_royalty_events
");

$vault = val($pdo,"
SELECT SUM(gold_packet_pool_ton)
FROM poado_rwa_royalty_events
");

$holders = val($pdo,"
SELECT COUNT(DISTINCT owner_user_id)
FROM poado_rwa_certs
WHERE status='minted'
");

?>
<!DOCTYPE html>
<html>
<head>

<meta charset="utf-8">
<title>Ecosystem Monitor</title>
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

.grid{
display:grid;
grid-template-columns:repeat(3,1fr);
gap:16px;
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
font-size:24px;
margin-top:6px;
}

</style>

</head>

<body>

<?php require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-topbar-nav.php'; ?>

<div class="wrap">

<?php require __DIR__.'/_nav.php'; ?>

<div class="title">
POAdo Ecosystem Monitor
</div>

<div class="grid">

<div class="card">
<div class="label">Active Miners</div>
<div class="value"><?=$miners?></div>
</div>

<div class="card">
<div class="label">Total Certificates</div>
<div class="value"><?=$total_certs?></div>
</div>

<div class="card">
<div class="label">Minted Certificates</div>
<div class="value"><?=$minted_certs?></div>
</div>

<div class="card">
<div class="label">NFT Holders</div>
<div class="value"><?=$holders?></div>
</div>

<div class="card">
<div class="label">Total Royalty TON</div>
<div class="value"><?=$royalty?></div>
</div>

<div class="card">
<div class="label">Gold Packet Vault</div>
<div class="value"><?=$vault?> TON</div>
</div>

</div>

</div>

<?php require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-bottom-nav.php'; ?>

</body>
</html>