<?php
require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/bootstrap.php';

$user = session_user();
if (!$user) die(json_encode(['ok'=>false]));

$amount = (float)($_POST['amount'] ?? 0);

if ($amount <= 0) {
    echo json_encode(['ok'=>false,'msg'=>'invalid amount']);
    exit;
}

$ref = 'EMA-STK-' . date('YmdHis') . '-' . rand(1000,9999);

echo json_encode([
    'ok'=>true,
    'ref'=>$ref,
    'amount'=>$amount,
    'note'=>"Stake EMA for booster"
]);
