<?php
declare(strict_types=1);

/**
 * RWA Cert Engine
 * Admin Navigation
 *
 * File:
 * /rwa/cert/admin/_nav.php
 */

?>

<style>

.admin-nav{
display:flex;
flex-wrap:wrap;
gap:12px;
margin:20px 0;
}

.admin-nav a{
text-decoration:none;
padding:10px 14px;
border:1px solid #6f5b1d;
background:#0b0b0b;
color:#e8d9a5;
font-size:13px;
border-radius:6px;
}

.admin-nav a:hover{
background:#151515;
}

</style>

<div class="admin-nav">

<a href="/rwa/cert/admin/financial-center.php">Financial Center</a>

<a href="/rwa/cert/admin/royalty-dashboard.php">Royalty Dashboard</a>

<a href="/rwa/cert/admin/royalty-scanner.php">Royalty Scanner</a>

<a href="/rwa/cert/admin/cert-monitor.php">Cert Monitor</a>

<a href="/rwa/cert/admin/mint-monitor.php">Mint Monitor</a>

</div>