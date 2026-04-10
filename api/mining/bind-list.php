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
SELECT adoptee_wallet,created_at
FROM poado_adopter_bindings
WHERE adopter_wallet=? AND is_active=1
");
$rows->execute([$user['wallet_address']]);

echo json_encode(['ok'=>true,'rows'=>$rows->fetchAll(PDO::FETCH_ASSOC)]);
