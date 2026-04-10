<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30, s-maxage=30');

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';

function poado_miner_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function poado_miner_col_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function poado_mask_wallet(?string $wallet): string
{
    $wallet = trim((string)$wallet);
    if ($wallet === '') {
        return 'Anonymous';
    }
    if (strlen($wallet) <= 12) {
        return $wallet;
    }
    return substr($wallet, 0, 6) . '...' . substr($wallet, -4);
}

try {
    if (function_exists('db_connect')) {
        db_connect();
    }

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database unavailable');
    }

    $items = [];

    if (poado_miner_table_exists($pdo, 'poado_miner_profiles')) {
        $walletCol = poado_miner_col_exists($pdo, 'poado_miner_profiles', 'wallet') ? 'wallet' : (poado_miner_col_exists($pdo, 'poado_miner_profiles', 'user_wallet') ? 'user_wallet' : null);
        $tierCol = poado_miner_col_exists($pdo, 'poado_miner_profiles', 'tier_key') ? 'tier_key' : (poado_miner_col_exists($pdo, 'poado_miner_profiles', 'miner_tier') ? 'miner_tier' : null);
        $multCol = poado_miner_col_exists($pdo, 'poado_miner_profiles', 'effective_multiplier') ? 'effective_multiplier' : (poado_miner_col_exists($pdo, 'poado_miner_profiles', 'multiplier') ? 'multiplier' : null);
        $timeCol = poado_miner_col_exists($pdo, 'poado_miner_profiles', 'updated_at') ? 'updated_at' : (poado_miner_col_exists($pdo, 'poado_miner_profiles', 'created_at') ? 'created_at' : null);

        $select = [];
        $select[] = $walletCol ? "`$walletCol` AS wallet" : "'' AS wallet";
        $select[] = $tierCol ? "`$tierCol` AS tier" : "'' AS tier";
        $select[] = $multCol ? "`$multCol` AS multiplier" : "0 AS multiplier";
        $select[] = $timeCol ? "`$timeCol` AS created_at" : "UTC_TIMESTAMP() AS created_at";

        $order = 'id DESC';
        if ($multCol) {
            $order = "`$multCol` DESC";
            if ($timeCol) {
                $order .= ", `$timeCol` DESC";
            }
        } elseif ($timeCol) {
            $order = "`$timeCol` DESC";
        }

        $sql = "SELECT " . implode(', ', $select) . " FROM `poado_miner_profiles` ORDER BY $order LIMIT 20";
        $stmt = $pdo->query($sql);
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        foreach ($rows as $row) {
            $items[] = [
                'wallet_masked' => poado_mask_wallet($row['wallet'] ?? ''),
                'tier' => (string)($row['tier'] ?? ''),
                'multiplier' => is_numeric((string)($row['multiplier'] ?? null)) ? (float)$row['multiplier'] : 0,
                'created_at' => !empty($row['created_at']) ? gmdate('c', strtotime((string)$row['created_at'])) : gmdate('c'),
            ];
        }
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