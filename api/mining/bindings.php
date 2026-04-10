<?php
declare(strict_types=1);

require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/bootstrap.php';
require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/session-user.php';

header('Content-Type: application/json');

db_connect();
$pdo = $GLOBALS['pdo'];

$user = session_user();
if (!$user) exit(json_encode(['ok'=>false]));

$wallet = $user['wallet_address'];

$total = $pdo->prepare("
SELECT SUM(amount_wems) FROM poado_mining_ledger
WHERE wallet=? AND entry_type='binding_commission'
");
$total->execute([$wallet]);

echo json_encode([
  'ok'=>true,
  'total_binding_wems'=>(float)$total->fetchColumn()
]);
