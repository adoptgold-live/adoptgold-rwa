<?php
declare(strict_types=1);

require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/bootstrap.php';
require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/session-user.php';

header('Content-Type: application/json');

db_connect();
$pdo = $GLOBALS['pdo'];

$user = session_user();
if (!$user) exit(json_encode(['ok'=>false]));

$adopter = $user['wallet_address'];
$adoptee = trim($_POST['wallet'] ?? '');

if ($adopter === $adoptee) exit(json_encode(['ok'=>false,'error'=>'SELF_BIND']));

$exists = $pdo->prepare("
SELECT id FROM poado_adopter_bindings WHERE adoptee_wallet=? AND is_active=1
");
$exists->execute([$adoptee]);
if ($exists->fetch()) exit(json_encode(['ok'=>false,'error'=>'ALREADY_BOUND']));

$pdo->prepare("
INSERT INTO poado_adopter_bindings (adopter_wallet,adoptee_wallet,created_at)
VALUES (?,?,NOW())
")->execute([$adopter,$adoptee]);

echo json_encode(['ok'=>true]);
