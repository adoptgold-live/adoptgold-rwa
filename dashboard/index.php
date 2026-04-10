<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/session-user.php';

$user = session_user();
$uid = $user['id'] ?? 0;

$pdo = $GLOBALS['pdo'];

// ===== TOTAL EARNED =====
$total = $pdo->prepare("
SELECT SUM(amount_ton)
FROM poado_rwa_claims
WHERE user_id=?
");
$total->execute([$uid]);
$totalEarned = $total->fetchColumn() ?: 0;

// ===== CLAIMABLE =====
$claim = $pdo->prepare("
SELECT SUM(amount_ton)
FROM poado_rwa_claims
WHERE user_id=? AND claimed=0
");
$claim->execute([$uid]);
$claimable = $claim->fetchColumn() ?: 0;

// ===== NFT PORTFOLIO =====
$nfts = $pdo->prepare("
SELECT cert_uid, nft_item_address, meta_json
FROM poado_rwa_certs
WHERE owner_user_id=? AND status='minted'
ORDER BY minted_at DESC
LIMIT 20
");
$nfts->execute([$uid]);
$nftList = $nfts->fetchAll(PDO::FETCH_ASSOC);

// ===== CLAIM HISTORY =====
$history = $pdo->prepare("
SELECT amount_ton, created_at
FROM poado_rwa_claims
WHERE user_id=? AND claimed=1
ORDER BY created_at DESC
LIMIT 20
");
$history->execute([$uid]);
$hist = $history->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>RWA Dashboard</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{background:#000;color:#0f0;font-family:monospace;padding:15px}
.card{border:1px solid #0f0;margin:10px 0;padding:10px}
button{padding:10px 15px;font-size:16px;background:#0f0;color:#000;border:none}
.small{font-size:12px;color:#aaa}
</style>
</head>
<body>

<h1>RWA DASHBOARD</h1>

<div class="card">
<b>Total Earned:</b><br>
<?= number_format($totalEarned,6) ?> TON
</div>

<div class="card">
<b>Claimable:</b><br>
<?= number_format($claimable,6) ?> TON<br><br>

<button onclick="window.location='/rwa/cert/claim/'">
CLAIM NOW
</button>
</div>

<div class="card">
<b>My NFTs</b><br><br>

<?php foreach($nftList as $n): 
$meta = json_decode($n['meta_json'], true);
$img = $meta['vault']['image'] ?? '';
?>

<div style="margin-bottom:10px">
<img src="<?= htmlspecialchars($img) ?>" width="100%"><br>
<?= htmlspecialchars($n['cert_uid']) ?><br>
<span class="small"><?= htmlspecialchars($n['nft_item_address']) ?></span>
</div>

<?php endforeach; ?>

</div>

<div class="card">
<b>Claim History</b><br><br>

<?php foreach($hist as $h): ?>
<div>
<?= number_format($h['amount_ton'],6) ?> TON<br>
<span class="small"><?= $h['created_at'] ?></span>
</div>
<hr>
<?php endforeach; ?>

</div>

</body>
</html>
