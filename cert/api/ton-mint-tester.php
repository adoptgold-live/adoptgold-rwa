<?php
declare(strict_types=1);

require_once __DIR__ . '/../../inc/core/bootstrap.php';

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    die('Session not active');
}

if (empty($_SESSION['csrf_token_rwa_ton_mint']) || !is_string($_SESSION['csrf_token_rwa_ton_mint'])) {
    $_SESSION['csrf_token_rwa_ton_mint'] = bin2hex(random_bytes(16));
}

$csrf = (string)$_SESSION['csrf_token_rwa_ton_mint'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>TON Mint Tester</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{margin:0;background:#05060a;color:#e9e9ff;font-family:ui-monospace,Consolas,monospace}
.wrap{max-width:900px;margin:30px auto;padding:20px}
.card{background:#111325;border:1px solid #6d4cff;border-radius:14px;padding:18px}
input,textarea{width:100%;margin-bottom:10px;padding:10px;background:#090b14;border:1px solid #6d4cff;color:#fff}
button{padding:10px 14px;background:#5f45ee;border:none;color:#fff;cursor:pointer}
</style>
</head>
<body>
<div class="wrap">
<div class="card">
<h2>TON Mint Tester</h2>
<input id="cert_uid" value="RK92-EMA-20260327-REAL0001">
<input id="item_index" value="1">
<input id="owner_address" value="UQBxc1nE_MGtIQpy1wTzVnoQTPfQmv5st_u2QJWSNNAvbYAv">
<input id="csrf_token" value="<?= h($csrf) ?>">
<button onclick="mintNow()">Mint</button>
<textarea id="out" rows="18"></textarea>
</div>
</div>
<script>
async function mintNow() {
  const payload = {
    cert_uid: document.getElementById('cert_uid').value.trim(),
    item_index: document.getElementById('item_index').value.trim(),
    owner_address: document.getElementById('owner_address').value.trim(),
    csrf_token: document.getElementById('csrf_token').value.trim()
  };

  document.getElementById('out').value = 'Submitting...\n\n' + JSON.stringify(payload, null, 2);

  try {
    const res = await fetch('/rwa/cert/api/ton-mint.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      credentials: 'same-origin',
      body: JSON.stringify(payload)
    });

    const text = await res.text();
    document.getElementById('out').value = text;
  } catch (e) {
    document.getElementById('out').value = 'Request failed\n\n' + String(e);
  }
}
</script>
</body>
</html>
