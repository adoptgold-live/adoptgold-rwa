<?php
declare(strict_types=1);

/*
POAdo RWA Cert Engine
System Health Monitor

File:
/rwa/cert/admin/system-health.php

Purpose:
Server health diagnostics
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

/* PHP */

$php_version = phpversion();

/* DB */

$db_status = "OK";
try{
    $pdo->query("SELECT 1");
}catch(Throwable $e){
    $db_status = "ERROR";
}

/* disk */

$disk_total = disk_total_space("/");
$disk_free = disk_free_space("/");

/* directories */

$dirs = [
"/rwa/cert/tmp",
"/rwa/cert/pdf",
"/rwa/cert/issued",
"/rwa/cert/json",
"/rwa/cert/metadata"
];

$dir_status = [];

foreach($dirs as $d){
    $path = $_SERVER['DOCUMENT_ROOT'].$d;
    $dir_status[$d] = is_dir($path) ? "OK" : "MISSING";
}

/* ton rpc */

$ton_rpc = "UNKNOWN";
$rpc = @file_get_contents("https://toncenter.com/api/v3/blocks?limit=1");

if($rpc){
    $ton_rpc = "OK";
}else{
    $ton_rpc = "FAIL";
}

?>
<!DOCTYPE html>
<html>
<head>

<meta charset="utf-8">
<title>System Health</title>
<meta name="viewport" content="width=device-width,initial-scale=1">

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

.title{
font-size:22px;
color:#d4af37;
margin-bottom:20px;
}

.table{
width:100%;
border-collapse:collapse;
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

.ok{color:#6cff6c;}
.fail{color:#ff6c6c;}

</style>

</head>

<body>

<?php require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-topbar-nav.php'; ?>

<div class="wrap">

<?php require __DIR__.'/_nav.php'; ?>

<div class="title">
System Health Monitor
</div>

<table class="table">

<tr>
<th>Component</th>
<th>Status</th>
</tr>

<tr>
<td>PHP Version</td>
<td class="ok"><?=$php_version?></td>
</tr>

<tr>
<td>Database Connection</td>
<td class="<?=$db_status=="OK"?"ok":"fail"?>">
<?=$db_status?>
</td>
</tr>

<tr>
<td>TON RPC</td>
<td class="<?=$ton_rpc=="OK"?"ok":"fail"?>">
<?=$ton_rpc?>
</td>
</tr>

<tr>
<td>Disk Total</td>
<td><?=round($disk_total/1024/1024/1024,2)?> GB</td>
</tr>

<tr>
<td>Disk Free</td>
<td><?=round($disk_free/1024/1024/1024,2)?> GB</td>
</tr>

</table>

<h3 style="color:#d4af37;margin-top:30px">Directory Check</h3>

<table class="table">

<tr>
<th>Directory</th>
<th>Status</th>
</tr>

<?php foreach($dir_status as $k=>$v): ?>

<tr>

<td><?=$k?></td>

<td class="<?=$v=="OK"?"ok":"fail"?>">
<?=$v?>
</td>

</tr>

<?php endforeach ?>

</table>

</div>

<?php require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-bottom-nav.php'; ?>

</body>
</html>