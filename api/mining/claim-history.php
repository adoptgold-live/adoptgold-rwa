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
SELECT request_uid,amount_wems,claim_status,tx_hash,requested_at
FROM poado_wems_claim_requests
WHERE user_id=?
ORDER BY id DESC LIMIT 50
");
$rows->execute([$user['id']]);

echo json_encode(['ok'=>true,'rows'=>$rows->fetchAll(PDO::FETCH_ASSOC)]);
