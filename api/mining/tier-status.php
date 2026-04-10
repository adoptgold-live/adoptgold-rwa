<?php
declare(strict_types=1);

require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/bootstrap.php';
require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/session-user.php';

header('Content-Type: application/json');

db_connect();
$pdo = $GLOBALS['pdo'];

$user = session_user();
if (!$user) exit(json_encode(['ok'=>false]));

$st = $pdo->prepare("
SELECT miner_tier, tier_status, ema_staked_active, ema_stake_expires_at
FROM poado_miner_profiles WHERE user_id=?
");
$st->execute([$user['id']]);

echo json_encode(['ok'=>true,'data'=>$st->fetch(PDO::FETCH_ASSOC)]);
