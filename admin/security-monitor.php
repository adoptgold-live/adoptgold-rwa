<?php
declare(strict_types=1);

/*
POAdo RWA Cert Engine
Security Monitor

File:
/rwa/cert/admin/security-monitor.php

Purpose:
Audit console for login events, mint operations and security signals
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

/* statistics */

$total_users = val($pdo,"SELECT COUNT(*) FROM users");
$total_certs = val($pdo,"SELECT COUNT(*) FROM poado_rwa_certs");
$total_royalty = val($pdo,"SELECT COUNT(*) FROM poado_rwa_royalty_events");

/* recent login activity */

$stmt = $pdo->query("
SELECT
identity_key,
identity_type,
created_at
FROM poado_identity_links
ORDER BY created_at DESC
LIMIT 30
");

$logins = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* recent mint activity */

$stmt = $pdo->query("
SELECT
cert_uid,
cert_type,
status,
created_at
FROM poado_rwa_certs
ORDER BY id DESC
LIMIT 30
");

$mints = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html>
<head>

<meta charset="utf-8">
<title>Security Monitor</title>
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

.section{
margin-top:30px;
}

.table{
width:100%;
border-collapse:collapse;
margin-top:10px;
}

.table th{
border-bottom:1px solid #6f5b1d;
padding:8px;
font-size:13px;
color:#d4af37;
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

.badge.initiated{color:#ddd;}
.badge.paid{color:#ffcf66;}
.badge.minted{color:#6cff6c;}

</style>

</head>

<body>

<?php require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-topbar-nav.php'; ?>

<div class="wrap">

<?php require __DIR__.'/_nav.php'; ?>

<div class="title">
Security Monitor
</div>

<div class="grid">

<div class="card">
<div class="label">Total Users</div>
<div class="value"><?=$total_users?></div>
</div>

<div class="card">
<div class="label">Total Certificates</div>
<div class="value"><?=$total_certs?></div>
</div>

<div class="card">
<div class="label">Royalty Events</div>
<div class="value"><?=$total_royalty?></div>
</div>

</div>

<div class="section">

<h3 style="color:#d4af37">Recent Login Activity</h3>

<table class="table">

<thead>
<tr>
<th>Identity</th>
<th>Type</th>
<th>Login Time</th>
</tr>
</thead>

<tbody>

<?php foreach($logins as $r): ?>

<tr>

<td><?=$r['identity_key']?></td>

<td><?=$r['identity_type']?></td>

<td><?=$r['created_at']?></td>

</tr>

<?php endforeach ?>

</tbody>

</table>

</div>

<div class="section">

<h3 style="color:#d4af37">Recent Mint Activity</h3>

<table class="table">

<thead>
<tr>
<th>Cert UID</th>
<th>Type</th>
<th>Status</th>
<th>Time</th>
</tr>
</thead>

<tbody>

<?php foreach($mints as $r): ?>

<tr>

<td><?=$r['cert_uid']?></td>

<td><?=$r['cert_type']?></td>

<td>
<span class="badge <?=$r['status']?>"><?=$r['status']?></span>
</td>

<td><?=$r['created_at']?></td>

</tr>

<?php endforeach ?>

</tbody>

</table>

</div>

</div>

<?php require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-bottom-nav.php'; ?>

</body>
</html>