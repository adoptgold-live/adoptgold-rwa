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
<title>Revenue Dashboard</title>
<script src="/rwa/assets/js/chart.umd.min.js"></script>
<style>
body{background:#000;color:#0f0;font-family:monospace;padding:12px}
.card{border:1px solid #0f0;padding:12px;margin-bottom:12px}
.big{font-size:20px;font-weight:bold}
</style>
</head>
<body>

<h2>💰 Treasury + Mining Economy</h2>

<div class="card">
    <div>Total Issued: <span id="issued" class="big"></span></div>
    <div>Total Claimed: <span id="claimed" class="big"></span></div>
    <div>Unclaimed (Liability): <span id="unclaimed" class="big"></span></div>
    <div>Net Exposure: <span id="net" class="big"></span></div>
</div>

<div class="card">
    <canvas id="chart"></canvas>
</div>

<script>
async function load() {
    const r = await fetch('/rwa/api/admin/revenue-summary.php');
    const d = await r.json();

    const t = d.totals;

    document.getElementById('issued').innerText = t.issued.toFixed(2);
    document.getElementById('claimed').innerText = t.claimed.toFixed(2);
    document.getElementById('unclaimed').innerText = t.unclaimed.toFixed(2);
    document.getElementById('net').innerText = t.net_liability.toFixed(2);

    const labels = d.daily.map(x => x.ref_date);
    const mined = d.daily.map(x => parseFloat(x.mined));

    new Chart(document.getElementById('chart'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Daily Mining',
                data: mined
            }]
        }
    });
}

load();
</script>

</body>
</html>
