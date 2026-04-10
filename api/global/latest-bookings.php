<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30, s-maxage=30');

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';

function poado_booking_col_exists(PDO $pdo, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'poado_bookings' AND column_name = ?");
    $stmt->execute([$column]);
    return (int)$stmt->fetchColumn() > 0;
}

function poado_mask_name(?string $name): string
{
    $name = trim((string)$name);
    if ($name === '') {
        return 'Guest';
    }
    $len = mb_strlen($name);
    if ($len <= 1) {
        return $name;
    }
    return mb_substr($name, 0, 1) . str_repeat('*', max(1, $len - 1));
}

try {
    if (function_exists('db_connect')) {
        db_connect();
    }

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database unavailable');
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'poado_bookings'");
    if ((int)$stmt->fetchColumn() <= 0) {
        throw new RuntimeException('Table missing');
    }

    $select = [];
    $select[] = poado_booking_col_exists($pdo, 'booking_uid') ? "`booking_uid`" : "'' AS booking_uid";
    $select[] = poado_booking_col_exists($pdo, 'customer_name') ? "`customer_name`" : "'' AS customer_name";
    $select[] = poado_booking_col_exists($pdo, 'package_key') ? "`package_key`" : (poado_booking_col_exists($pdo, 'rwa_package') ? "`rwa_package` AS package_key" : "'' AS package_key");
    $select[] = poado_booking_col_exists($pdo, 'status') ? "`status`" : "'pending' AS status";
    $select[] = poado_booking_col_exists($pdo, 'created_at') ? "`created_at`" : "UTC_TIMESTAMP() AS created_at";

    $sql = "SELECT " . implode(', ', $select) . " FROM `poado_bookings` ORDER BY `created_at` DESC LIMIT 20";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    foreach ($rows as $row) {
        $items[] = [
            'booking_uid' => (string)($row['booking_uid'] ?? ''),
            'customer_name' => poado_mask_name($row['customer_name'] ?? ''),
            'package_key' => (string)($row['package_key'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
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