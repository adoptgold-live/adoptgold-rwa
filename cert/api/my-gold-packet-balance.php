<?php
declare(strict_types=1);

/**
 * POAdo RWA Cert Engine
 * File: /var/www/html/public/rwa/cert/api/my-gold-packet-balance.php
 *
 * Purpose:
 * - Return current Gold Packet Vault ledger summary
 * - Reads from:
 *     wems_db.poado_rwa_gold_packet_claims
 * - This is a vault-balance style endpoint, not a direct per-user claim endpoint
 *
 * Current model:
 * - Gold Packet pool is first vaulted
 * - Later distribution engine can allocate from this vault
 * - This endpoint shows:
 *     total vaulted
 *     total distributed
 *     current remaining vault balance
 *     paginated vault event rows
 *
 * Assumed Gold Packet ledger table:
 *   poado_rwa_gold_packet_claims
 *
 * Fields used:
 *   id
 *   claim_uid
 *   event_uid
 *   cert_uid
 *   allocated_ton
 *   snapshot_time
 *   status
 *   distributed_ton
 *   distributed_tx_hash
 *   distributed_at
 *   created_at
 */

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/validators.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/guards.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/json.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/session-user.php';

if (!function_exists('poado_gpbal_exit')) {
    function poado_gpbal_exit(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('poado_gpbal_norm_decimal')) {
    function poado_gpbal_norm_decimal($value, int $scale = 9): string
    {
        if ($value === null || $value === '') {
            return number_format(0, $scale, '.', '');
        }
        $v = trim((string)$value);
        $v = str_replace(',', '', $v);
        if (!is_numeric($v)) {
            return number_format(0, $scale, '.', '');
        }
        return number_format((float)$v, $scale, '.', '');
    }
}

if (!function_exists('poado_gpbal_sub')) {
    function poado_gpbal_sub(string $a, string $b, int $scale = 9): string
    {
        if (function_exists('bcsub')) {
            return bcsub($a, $b, $scale);
        }
        return number_format(((float)$a - (float)$b), $scale, '.', '');
    }
}

try {
    $wallet = get_wallet_session();
    if (!$wallet) {
        poado_gpbal_exit([
            'ok' => false,
            'error' => 'not_logged_in',
            'message' => 'Login required.',
        ], 401);
    }

    db_connect();
    /** @var PDO $pdo */
    $pdo = $GLOBALS['pdo'];

    $userStmt = $pdo->prepare("
        SELECT id, wallet, nickname, role, is_active
        FROM users
        WHERE wallet = :wallet
        LIMIT 1
    ");
    $userStmt->execute([':wallet' => $wallet]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        poado_gpbal_exit([
            'ok' => false,
            'error' => 'user_not_found',
            'message' => 'User not found.',
        ], 404);
    }

    if ((int)($user['is_active'] ?? 0) !== 1) {
        poado_gpbal_exit([
            'ok' => false,
            'error' => 'user_inactive',
            'message' => 'User inactive.',
        ], 403);
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;

    $statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));
    $allowedStatuses = ['vaulted', 'partial', 'distributed', 'void'];

    $where = ['1=1'];
    $params = [];

    if ($statusFilter !== '') {
        if (!in_array($statusFilter, $allowedStatuses, true)) {
            poado_gpbal_exit([
                'ok' => false,
                'error' => 'invalid_status_filter',
                'message' => 'Invalid status filter.',
            ], 422);
        }
        $where[] = 'status = :status';
        $params[':status'] = $statusFilter;
    }

    $whereSql = implode(' AND ', $where);

    $summaryStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_rows,
            COALESCE(SUM(allocated_ton), 0) AS allocated_total,
            COALESCE(SUM(distributed_ton), 0) AS distributed_total
        FROM poado_rwa_gold_packet_claims
        WHERE {$whereSql}
    ");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'total_rows' => 0,
        'allocated_total' => '0',
        'distributed_total' => '0',
    ];

    $allocatedTotal = poado_gpbal_norm_decimal($summary['allocated_total'] ?? '0');
    $distributedTotal = poado_gpbal_norm_decimal($summary['distributed_total'] ?? '0');
    $vaultBalance = poado_gpbal_sub($allocatedTotal, $distributedTotal, 9);

    $statusCountStmt = $pdo->prepare("
        SELECT status, COUNT(*) AS c
        FROM poado_rwa_gold_packet_claims
        GROUP BY status
    ");
    $statusCountStmt->execute();
    $statusRows = $statusCountStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $statusCounts = [
        'vaulted' => 0,
        'partial' => 0,
        'distributed' => 0,
        'void' => 0,
    ];
    foreach ($statusRows as $r) {
        $k = strtolower((string)($r['status'] ?? ''));
        if (isset($statusCounts[$k])) {
            $statusCounts[$k] = (int)($r['c'] ?? 0);
        }
    }

    $countStmt = $pdo->prepare("
        SELECT COUNT(*) AS total_rows
        FROM poado_rwa_gold_packet_claims
        WHERE {$whereSql}
    ");
    $countStmt->execute($params);
    $totalRows = (int)($countStmt->fetchColumn() ?: 0);

    $listSql = "
        SELECT
            id,
            claim_uid,
            event_uid,
            cert_uid,
            allocated_ton,
            snapshot_time,
            status,
            distributed_ton,
            distributed_tx_hash,
            distributed_at,
            created_at
        FROM poado_rwa_gold_packet_claims
        WHERE {$whereSql}
        ORDER BY id DESC
        LIMIT :limit OFFSET :offset
    ";
    $listStmt = $pdo->prepare($listSql);
    foreach ($params as $k => $v) {
        $listStmt->bindValue($k, $v);
    }
    $listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $listStmt->execute();
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = [];
    foreach ($rows as $row) {
        $allocatedTon = poado_gpbal_norm_decimal($row['allocated_ton'] ?? '0');
        $distributedTon = poado_gpbal_norm_decimal($row['distributed_ton'] ?? '0');
        $remainingTon = poado_gpbal_sub($allocatedTon, $distributedTon, 9);

        $items[] = [
            'id' => (int)($row['id'] ?? 0),
            'claim_uid' => (string)($row['claim_uid'] ?? ''),
            'event_uid' => (string)($row['event_uid'] ?? ''),
            'cert_uid' => (string)($row['cert_uid'] ?? ''),
            'allocated_ton' => $allocatedTon,
            'distributed_ton' => $distributedTon,
            'remaining_ton' => $remainingTon,
            'snapshot_time' => $row['snapshot_time'] ?? null,
            'status' => (string)($row['status'] ?? ''),
            'distributed_tx_hash' => (string)($row['distributed_tx_hash'] ?? ''),
            'distributed_at' => $row['distributed_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ];
    }

    poado_gpbal_exit([
        'ok' => true,
        'user' => [
            'id' => (int)$user['id'],
            'wallet' => (string)$user['wallet'],
            'nickname' => (string)($user['nickname'] ?? ''),
            'role' => (string)($user['role'] ?? ''),
        ],
        'summary' => [
            'total_rows' => (int)($summary['total_rows'] ?? 0),
            'vaulted_total_ton' => $allocatedTotal,
            'distributed_total_ton' => $distributedTotal,
            'vault_balance_ton' => $vaultBalance,
            'status_counts' => $statusCounts,
        ],
        'filters' => [
            'status' => $statusFilter !== '' ? $statusFilter : null,
            'page' => $page,
            'per_page' => $perPage,
        ],
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total_rows' => $totalRows,
            'total_pages' => max(1, (int)ceil($totalRows / $perPage)),
            'has_prev' => $page > 1,
            'has_next' => ($offset + $perPage) < $totalRows,
        ],
        'items' => $items,
    ], 200);

} catch (Throwable $e) {
    poado_gpbal_exit([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'Failed to load Gold Packet balance.',
        'details' => $e->getMessage(),
    ], 500);
}