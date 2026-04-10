<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/session-user.php';

$user = session_user();
$uid = $user['id'] ?? 0;

$pdo = $GLOBALS['pdo'];

$pdo->prepare("
UPDATE poado_rwa_claims
SET claimed=1
WHERE user_id=? AND claimed=0
")->execute([$uid]);

echo json_encode(["ok"=>true]);
