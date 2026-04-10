<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30, s-maxage=30');

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';

function poado_stats_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function poado_stats_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function poado_stats_scalar(PDO $pdo, string $sql, array $params = []): float
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $val = $stmt->fetchColumn();
    return is_numeric((string)$val) ? (float)$val : 0.0;
}

try {
    if (function_exists('db_connect')) {
        db_connect();
    }

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database unavailable');
    }

    $globalWemsMined = 0.0;
    $totalMiners = 0;
    $goldPacketVault = 0.0;
    $sources = [];

    if (poado_stats_table_exists($pdo, 'poado_mining_ledger')) {
        $amountCol = null;
        foreach (['amount_wems', 'wems_amount', 'amount', 'credited_wems'] as $col) {
            if (poado_stats_column_exists($pdo, 'poado_mining_ledger', $col)) {
                $amountCol = $col;
                break;
            }
        }

        if ($amountCol !== null) {
            $globalWemsMined = poado_stats_scalar($pdo, "SELECT COALESCE(SUM(`$amountCol`),0) FROM `poado_mining_ledger`");
            $sources[] = 'poado_mining_ledger.' . $amountCol;
        }
    }

    if (poado_stats_table_exists($pdo, 'poado_miner_profiles')) {
        $activeFilter = '';
        if (poado_stats_column_exists($pdo, 'poado_miner_profiles', 'is_active')) {
            $activeFilter = " WHERE is_active = 1";
        }
        $totalMiners = (int)poado_stats_scalar($pdo, "SELECT COUNT(*) FROM `poado_miner_profiles`" . $activeFilter);
        $sources[] = 'poado_miner_profiles';
    } elseif (poado_stats_table_exists($pdo, 'users')) {
        $where = [];
        if (poado_stats_column_exists($pdo, 'users', 'wallet_address')) {
            $where[] = "wallet_address IS NOT NULL";
            $where[] = "wallet_address <> ''";
        }
        $sql = "SELECT COUNT(*) FROM `users`" . ($where ? " WHERE " . implode(' AND ', $where) : '');
        $totalMiners = (int)poado_stats_scalar($pdo, $sql);
        $sources[] = 'users';
    }

    if (poado_stats_table_exists($pdo, 'poado_deals')) {
        $amountCol = null;
        foreach (['amount_emx', 'amount_usd'] as $col) {
            if (poado_stats_column_exists($pdo, 'poado_deals', $col)) {
                $amountCol = $col;
                break;
            }
        }

        if ($amountCol !== null) {
            $statusWhere = '';
            if (poado_stats_column_exists($pdo, 'poado_deals', 'status')) {
                $statusWhere = " WHERE status IN ('paid','confirmed','completed')";
            }
            $goldPacketVault = poado_stats_scalar($pdo, "SELECT COALESCE(SUM(`$amountCol`),0) FROM `poado_deals`" . $statusWhere);
            $sources[] = 'poado_deals.' . $amountCol;
        }
    }

    echo json_encode([
        'ok' => true,
        'global_wems_mined' => round($globalWemsMined, 6),
        'total_miners' => $totalMiners,
        'gold_packet_vault' => round($goldPacketVault, 6),
        'updated_at' => gmdate('c'),
        'sources' => $sources,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode([
        'ok' => false,
        'global_wems_mined' => 0,
        'total_miners' => 0,
        'gold_packet_vault' => 0,
        'updated_at' => gmdate('c'),
        'sources' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}