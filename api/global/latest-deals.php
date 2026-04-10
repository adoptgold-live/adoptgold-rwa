<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30, s-maxage=30');

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';

function poado_deal_col_exists(PDO $pdo, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'poado_deals' AND column_name = ?");
    $stmt->execute([$column]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    if (function_exists('db_connect')) {
        db_connect();
    }

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database unavailable');
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'poado_deals'");
    if ((int)$stmt->fetchColumn() <= 0) {
        throw new RuntimeException('Table missing');
    }

    $select = [];
    $select[] = poado_deal_col_exists($pdo, 'deal_uid') ? "`deal_uid`" : "'' AS deal_uid";
    $select[] = poado_deal_col_exists($pdo, 'status') ? "`status`" : "'pending' AS status";
    $select[] = poado_deal_col_exists($pdo, 'amount_emx') ? "`amount_emx`" : "0 AS amount_emx";
    $select[] = poado_deal_col_exists($pdo, 'created_at') ? "`created_at`" : "UTC_TIMESTAMP() AS created_at";

    $sql = "SELECT " . implode(', ', $select) . " FROM `poado_deals` ORDER BY `created_at` DESC LIMIT 20";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    foreach ($rows as $row) {
        $items[] = [
            'deal_uid' => (string)($row['deal_uid'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'amount_emx' => is_numeric((string)($row['amount_emx'] ?? null)) ? (float)$row['amount_emx'] : 0,
            'created_at' => !empty($row['created_at']) ? gmdate('c', strtotime((string)$row['created_at'])) : gmdate('c'),
        ];
    }

    echo json_encode([
        'ok' => true,
        'items' => $items,
        'updated_at' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode([
        'ok' => false,
        'items' => [],
        'updated_at' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}