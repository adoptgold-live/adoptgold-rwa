<?php
declare(strict_types=1);

/**
 * /var/www/html/public/dashboard/api/global/rwa-verify.php
 * v1.0.20260309
 */

$ROOT = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? '/var/www/html/public'), '/');

require $ROOT . '/dashboard/inc/bootstrap.php';
require $ROOT . '/dashboard/inc/validators.php';
require $ROOT . '/dashboard/inc/guards.php';
require $ROOT . '/dashboard/inc/json.php';

db_connect();
/** @var PDO|null $pdo */
$pdo = $GLOBALS['pdo'] ?? null;

header('Content-Type: application/json; charset=utf-8');

if (!$pdo instanceof PDO) {
    echo json_encode(['ok' => false, 'ts' => time(), 'error' => 'DB unavailable']);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));

if ($q === '') {
    echo json_encode([
        'ok' => false,
        'ts' => time(),
        'error' => 'Missing query',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table
    ");
    $st->execute([':table' => $table]);
    return (int)$st->fetchColumn() > 0;
}

if (!table_exists($pdo, 'poado_rwa_certs')) {
    echo json_encode([
        'ok' => false,
        'ts' => time(),
        'error' => 'Cert table missing',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$sql = "
    SELECT
        cert_uid,
        ton_wallet,
        rwa_type,
        status,
        issued_at
    FROM poado_rwa_certs
    WHERE cert_uid = :q
       OR ton_wallet = :q
    ORDER BY issued_at DESC
    LIMIT 1
";

$st = $pdo->prepare($sql);
$st->execute([':q' => $q]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode([
        'ok' => false,
        'ts' => time(),
        'error' => 'Not found',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'ts' => time(),
    'cert_uid' => (string)$row['cert_uid'],
    'owner_wallet' => (string)$row['ton_wallet'],
    'type' => (string)$row['rwa_type'],
    'status' => (string)$row['status'],
    'issued_at' => (string)$row['issued_at'],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);