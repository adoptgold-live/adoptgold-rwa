<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/bootstrap.php';

$user = 1; // session

$pdo = $GLOBALS['pdo'];

$wallet = $pdo->query("SELECT wallet_address FROM users WHERE id={$user}")->fetchColumn();

$total = $pdo->query("
SELECT SUM(amount_ton)
FROM poado_rwa_claims
WHERE user_id={$user} AND claimed=0
")->fetchColumn();

$amountNano = (string)intval($total * 1e9);

// payout = treasury → user
$data = [
    "recipient" => $wallet,
    "amount_nano" => $amountNano,
    "memo" => "RWA CLAIM"
];

echo json_encode($data);
