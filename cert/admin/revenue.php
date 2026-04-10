<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/bootstrap.php';

$pdo = $GLOBALS['pdo'];

$data = $pdo->query("
SELECT
SUM(sale_amount_ton) total_sales,
SUM(royalty_amount_ton) total_royalty,
SUM(holder_pool_ton) holder_pool,
SUM(ace_pool_ton) ace_pool,
SUM(gold_packet_pool_ton) gold_pool,
SUM(treasury_retained_ton) treasury
FROM poado_rwa_royalty_events_v2
")->fetch(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html>
<body style="background:#000;color:#0f0;font-family:monospace">
<h1>RWA Revenue Dashboard</h1>

<pre>
Total Sales: <?= $data['total_sales'] ?? 0 ?> TON
Royalty:     <?= $data['total_royalty'] ?? 0 ?> TON

Holder Pool: <?= $data['holder_pool'] ?? 0 ?>
ACE Pool:    <?= $data['ace_pool'] ?? 0 ?>
Gold Vault:  <?= $data['gold_pool'] ?? 0 ?>
Treasury:    <?= $data['treasury'] ?? 0 ?>
</pre>

</body>
</html>
