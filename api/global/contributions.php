<?php
declare(strict_types=1);

/**
 * /var/www/html/public/dashboard/api/global/contributions.php
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
    echo json_encode(['ok' => false, 'ts' => time(), 'items' => [], 'error' => 'DB unavailable']);
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

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table
          AND COLUMN_NAME = :column
    ");
    $st->execute([':table' => $table, ':column' => $column]);
    return (int)$st->fetchColumn() > 0;
}

$items = [];

if (table_exists($pdo, 'poado_pos_deals')) {
    $table = 'poado_pos_deals';

    $industryCol = column_exists($pdo, $table, 'industry') ? 'industry' : null;
    $amountCol = column_exists($pdo, $table, 'amount_total') ? 'amount_total' : (
        column_exists($pdo, $table, 'amount_total_units') ? 'amount_total_units' : null
    );
    $timeCol = column_exists($pdo, $table, 'created_at') ? 'created_at' : null;

    if ($amountCol !== null && $timeCol !== null) {
        $selectIndustry = $industryCol !== null
            ? "`{$industryCol}` AS industry"
            : "'Unknown' AS industry";

        $sql = "
            SELECT {$selectIndustry}, `{$amountCol}` AS amount, UNIX_TIMESTAMP(`{$timeCol}`) AS ts
            FROM `{$table}`
            ORDER BY `{$timeCol}` DESC
            LIMIT 20
        ";

        $st = $pdo->query($sql);
        if ($st) {
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $items[] = [
                    'industry' => (string)($row['industry'] ?? 'Unknown'),
                    'amount' => (float)($row['amount'] ?? 0),
                    'ts' => (int)($row['ts'] ?? 0),
                ];
            }
        }
    }
}

echo json_encode([
    'ok' => true,
    'ts' => time(),
    'items' => $items,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);