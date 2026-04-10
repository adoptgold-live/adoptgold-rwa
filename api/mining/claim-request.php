<?php
declare(strict_types=1);

require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-session.php';
require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/bootstrap.php';
require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/session-user.php';

header('Content-Type: application/json');

db_connect();
$pdo = $GLOBALS['pdo'];

$user = session_user();
if (!$user) exit(json_encode(['ok'=>false,'error'=>'NO_SESSION']));

$userId = (int)$user['id'];
$wallet = $user['wallet_address'];

$amount = (float)($_POST['amount'] ?? 0);
$dest   = trim($_POST['destination_wallet'] ?? '');

if ($amount <= 0) exit(json_encode(['ok'=>false,'error'=>'INVALID_AMOUNT']));
if ($dest === '') exit(json_encode(['ok'=>false,'error'=>'NO_DEST']));

$kyc = (int)$pdo->query("SELECT is_fully_verified FROM users WHERE id=$userId")->fetchColumn();
if ($kyc !== 1) exit(json_encode(['ok'=>false,'error'=>'KYC_REQUIRED']));

$st = $pdo->prepare("
SELECT total_mined_wems + total_binding_wems + total_node_bonus_wems - total_claimed_wems
FROM poado_miner_profiles WHERE user_id=?
");
$st->execute([$userId]);
$available = (float)$st->fetchColumn();

if ($amount > $available) exit(json_encode(['ok'=>false,'error'=>'INSUFFICIENT']));

$uid = 'CLM-'.date('YmdHis').'-'.substr(md5(uniqid()),0,6);

$pdo->prepare("
INSERT INTO poado_wems_claim_requests
(user_id,wallet,request_uid,amount_wems,claim_status,destination_wallet,requested_at)
VALUES (?,?,?,?, 'pending', ?, NOW())
")->execute([$userId,$wallet,$uid,$amount,$dest]);

echo json_encode(['ok'=>true,'request_uid'=>$uid]);
