<?php
declare(strict_types=1);

require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/bootstrap.php';
require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/session-user.php';

header('Content-Type: application/json');

db_connect();
$pdo = $GLOBALS['pdo'];

$user = session_user();
if (!$user) exit(json_encode(['ok'=>false]));

$rows = $pdo->prepare("
SELECT reward_wems,stat_date,pool_tier
FROM poado_node_pool_daily
WHERE wallet=?
ORDER BY id DESC LIMIT 20
");
$rows->execute([$user['wallet_address']]);

echo json_encode(['ok'=>true,'rows'=>$rows->fetchAll(PDO::FETCH_ASSOC)]);
