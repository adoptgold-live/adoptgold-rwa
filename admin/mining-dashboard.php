<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/session-user.php';

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
<title>Mining Admin</title>
<style>
body{background:#000;color:#0f0;font-family:monospace;padding:12px}
.card{border:1px solid #0f0;padding:10px;margin-bottom:12px}
.btn{padding:6px 10px;border:1px solid #0f0;cursor:pointer;margin-right:6px}
.btn.red{color:#f00;border-color:#f00}
</style>
</head>
<body>

<h2>🟢 Mining Admin Panel</h2>

<div id="stats" class="card">Loading stats...</div>
<div id="users"></div>

<script>
async function load() {
    const r = await fetch('/rwa/api/admin/mining-users.php');
    const d = await r.json();

    document.getElementById('stats').innerHTML =
        'Total Users: ' + d.total +
        '<br>Active: ' + d.active;

    let html = '';

    d.users.forEach(u => {
        html += `
        <div class="card">
            <b>${u.wallet}</b><br>
            Tier: ${u.tier} | Multiplier: x${u.multiplier}<br>
            Today: ${u.today} / ${u.cap}<br>
            Anomalies: ${u.anomalies}<br>

            <button class="btn red" onclick="act(${u.user_id},'freeze')">Freeze</button>
            <button class="btn" onclick="act(${u.user_id},'restore')">Restore</button>
            <button class="btn" onclick="act(${u.user_id},'downgrade')">Downgrade</button>
        </div>
        `;
    });

    document.getElementById('users').innerHTML = html;
}

async function act(uid, action) {
    await fetch('/rwa/api/admin/mining-action.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'user_id='+uid+'&action='+action
    });
    load();
}

load();
</script>

</body>
</html>
