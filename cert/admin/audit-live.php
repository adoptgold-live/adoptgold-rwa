<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/bootstrap.php';

$pdo = $GLOBALS['pdo'];

function val($q) {
    global $pdo;
    return $pdo->query($q)->fetchColumn() ?: 0;
}

$royalty = val("SELECT SUM(royalty_amount_ton) FROM poado_rwa_royalty_events_v2");
$claims  = val("SELECT SUM(amount_ton) FROM poado_rwa_claims");
$unclaimed = val("SELECT SUM(amount_ton) FROM poado_rwa_claims WHERE claimed=0");

$diff = abs($royalty - $claims);

// ===== STATUS =====
$status = "OK";
if ($diff > 0.01) $status = "CRITICAL";
elseif ($diff > 0.001) $status = "WARN";

?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Audit Live</title>
<meta http-equiv="refresh" content="10">
<style>
body{background:#000;color:#0f0;font-family:monospace;padding:20px}
.card{border:1px solid #0f0;margin:10px;padding:10px}
.ok{color:#0f0}
.warn{color:orange}
.crit{color:red}
</style>
</head>
<body>

<h1>AUDIT LIVE</h1>

<div class="card">
Status:
<span class="<?php
echo $status=='OK'?'ok':($status=='WARN'?'warn':'crit');
?>">
<?= $status ?>
</span>
</div>

<div class="card">
Royalty Total: <?= number_format($royalty,6) ?> TON<br>
Claims Total:  <?= number_format($claims,6) ?> TON<br>
Difference:    <?= number_format($diff,6) ?>
</div>

<div class="card">
Unclaimed TON: <?= number_format($unclaimed,6) ?>
</div>

</body>
</html>
