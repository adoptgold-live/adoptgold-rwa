<?php
require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/bootstrap.php';
require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/session-user.php';

$user = session_user();
if (!$user || (int)$user['is_admin'] !== 1) {
    http_response_code(403);
    exit('ADMIN ONLY');
}
?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tokenomics Dashboard</title>
<style>
body{background:#000;color:#0f0;font-family:monospace;padding:12px}
.card{border:1px solid #0f0;padding:12px;margin-bottom:12px}
.big{font-size:18px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
</style>
</head>
<body>

<h2>🪙 Tokenomics Dashboard</h2>

<div class="grid">

<div class="card">
<b>Issued</b><br><span id="issued" class="big"></span>
</div>

<div class="card">
<b>Claimed</b><br><span id="claimed" class="big"></span>
</div>

<div class="card">
<b>Burned</b><br><span id="burned" class="big"></span>
</div>

<div class="card">
<b>Circulating</b><br><span id="circulating" class="big"></span>
</div>

<div class="card">
<b>Unclaimed</b><br><span id="unclaimed" class="big"></span>
</div>

<div class="card">
<b>EMA Price</b><br><span id="price" class="big"></span>
</div>

<div class="card">
<b>Liability Value</b><br><span id="liability" class="big"></span>
</div>

</div>

<script>
async function load(){
    const r = await fetch('/rwa/api/admin/tokenomics-summary.php');
    const d = await r.json();
    const m = d.metrics;

    document.getElementById('issued').innerText = m.issued.toFixed(2);
    document.getElementById('claimed').innerText = m.claimed.toFixed(2);
    document.getElementById('burned').innerText = m.burned.toFixed(2);
    document.getElementById('circulating').innerText = m.circulating.toFixed(2);
    document.getElementById('unclaimed').innerText = m.unclaimed.toFixed(2);
    document.getElementById('price').innerText = m.ema_price.toFixed(6);
    document.getElementById('liability').innerText = m.liability_value.toFixed(2) + ' USD';
}

load();
</script>

</body>
</html>
