<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/bootstrap.php';

$user = 1; // replace with session

$pdo = $GLOBALS['pdo'];

$amount = $pdo->prepare("
SELECT SUM(amount_ton)
FROM poado_rwa_claims
WHERE user_id=? AND claimed=0
");
$amount->execute([$user]);

$total = (float)$amount->fetchColumn();

echo json_encode([
    "claimable_ton" => $total
]);
