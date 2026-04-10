<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/_nft-guard.php';
require_once dirname(__DIR__, 2) . '/inc/core/bootstrap.php';
require_once dirname(__DIR__, 2) . '/inc/core/db.php';

function out($x){
  echo json_encode($x, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
  exit;
}

$uid = $_GET['cert_uid'] ?? '';
$uid = trim($uid);

if ($uid === '') {
  out([
    'ok' => false,
    'error' => 'CERT_UID_REQUIRED'
  ]);
}

$pdo = function_exists('rwa_db') ? rwa_db() : db_connect();

$st = $pdo->prepare("SELECT * FROM poado_rwa_certs WHERE cert_uid = ? LIMIT 1");
$st->execute([$uid]);
$cert = $st->fetch(PDO::FETCH_ASSOC);

if (!$cert) {
  out([
    'ok' => false,
    'error' => 'CERT_NOT_FOUND',
    'cert_uid' => $uid
  ]);
}

/* decode meta */
$cert['meta_json_decoded'] = json_decode($cert['meta_json'] ?? '', true) ?: [];

$res = cert_nft_guard_check($cert);

out([
  'ok' => true,
  'cert_uid' => $uid,
  'guard' => $res
]);
