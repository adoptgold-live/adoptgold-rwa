<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/session-user.php';

function out(array $d, int $status = 200): never {
    http_response_code($status);
    echo json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode((string)$raw, true);
if (!is_array($data)) out(['ok'=>false,'error'=>'INVALID_JSON'], 400);

$certUid = trim((string)($data['cert_uid'] ?? ''));
$handoff = $data['mint_handoff_v9'] ?? null;

if ($certUid === '') out(['ok'=>false,'error'=>'CERT_UID_REQUIRED'], 422);
if (!is_array($handoff)) out(['ok'=>false,'error'=>'HANDOFF_REQUIRED'], 422);

db_connect();
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) out(['ok'=>false,'error'=>'DB_NOT_READY'], 500);

$stmt = $pdo->prepare("SELECT id, meta_json FROM poado_rwa_certs WHERE cert_uid = ? LIMIT 1");
$stmt->execute([$certUid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) out(['ok'=>false,'error'=>'CERT_NOT_FOUND'], 404);

$meta = json_decode((string)($row['meta_json'] ?? '{}'), true);
if (!is_array($meta)) $meta = [];

$meta['mint_handoff_v9'] = $handoff;

$up = $pdo->prepare("UPDATE poado_rwa_certs SET meta_json = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
$up->execute([
    json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    (int)$row['id']
]);

out([
    'ok' => true,
    'cert_uid' => $certUid,
    'mint_handoff_v9' => $handoff
]);
