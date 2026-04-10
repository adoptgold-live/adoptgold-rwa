<?php
require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/bootstrap.php';
require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/ema-stake-lib.php';

db_connect();
$pdo = $GLOBALS['pdo'];

$user = session_user();
if (!$user) die(json_encode(['ok'=>false]));

$tx = $_POST['tx_hash'] ?? '';

if (!$tx) {
    echo json_encode(['ok'=>false,'msg'=>'missing tx']);
    exit;
}

/**
 * TODO:
 * Replace this with real TON verification:
 * - check jetton master = EMA
 * - check amount
 * - check destination (treasury or stake vault)
 */
$verified = true;
$amount = 1000; // mock

if (!$verified) {
    echo json_encode(['ok'=>false,'msg'=>'tx invalid']);
    exit;
}

$tier = ema_resolve_tier($amount);

$stmt = $pdo->prepare("
INSERT INTO poado_ema_stake_records
(user_id, wallet, tx_hash, staked_amount_ema, lock_started_at, lock_expires_at, target_tier, verify_status)
VALUES (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 365 DAY), ?, 'confirmed')
");

$stmt->execute([
    $user['id'],
    $user['wallet_address'],
    $tx,
    $amount,
    $tier
]);

echo json_encode([
    'ok'=>true,
    'tier'=>$tier,
    'amount'=>$amount
]);
