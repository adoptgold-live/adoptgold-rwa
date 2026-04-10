<?php
declare(strict_types=1);

/**
 * /var/www/html/public/dashboard/api/global/vault-total.php
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

function stat_value(PDO $pdo, string $key): float
{
    $st = $pdo->prepare("SELECT stat_value FROM poado_global_stats WHERE stat_key = :key LIMIT 1");
    $st->execute([':key' => $key]);
    return (float)($st->fetchColumn() ?: 0);
}

echo json_encode([
    'ok' => true,
    'ts' => time(),
    'vault_total_usdt' => stat_value($pdo, 'vault_total_usdt'),
    'vault_today_contribution_usdt' => stat_value($pdo, 'vault_today_contribution_usdt'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);