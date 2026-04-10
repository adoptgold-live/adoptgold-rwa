<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/bootstrap.php';

$pdo = $GLOBALS['pdo'];

$list = $pdo->query("
SELECT cert_uid,status,created_at,minted_at
FROM poado_rwa_certs
ORDER BY id DESC LIMIT 50
")->fetchAll();

?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Mint Monitor</title>
<style>
body{background:#000;color:#0f0;font-family:monospace}
.card{border:1px solid #0f0;margin:10px;padding:10px}
.ok{color:#0f0}
.bad{color:red}
</style>
</head>
<body>

<h1>RWA Mint Monitor</h1>

<?php foreach($list as $c): ?>
<div class="card">
<b><?= htmlspecialchars($c['cert_uid']) ?></b><br>
Status: <?= $c['status'] ?><br>

<?php
$base = "/rwa/metadata/cert/_fallback_vault/".$c['cert_uid'];
$meta = $base."/metadata.json";
$verify = $base."/verify/verify.json";
?>

Meta: <?= file_exists($_SERVER['DOCUMENT_ROOT'].$meta) ? '<span class="ok">OK</span>' : '<span class="bad">MISSING</span>' ?><br>
Verify: <?= file_exists($_SERVER['DOCUMENT_ROOT'].$verify) ? '<span class="ok">OK</span>' : '<span class="bad">MISSING</span>' ?><br>

<a href="/rwa/cert/api/verify-status.php?uid=<?= urlencode($c['cert_uid']) ?>" target="_blank">Verify On-chain</a>

</div>
<?php endforeach; ?>

</body>
</html>
