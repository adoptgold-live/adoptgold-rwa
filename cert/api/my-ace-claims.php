<?php
declare(strict_types=1);

/**
 * POAdo RWA Cert Engine
 * File: /var/www/html/public/rwa/cert/api/my-ace-claims.php
 *
 * Purpose:
 * - Return current logged-in ACE user's claim summary
 * - Reads from ACE claim ledger:
 *     wems_db.poado_rwa_ace_claims
 * - Supports summary + paginated claim rows
 *
 * Assumed ACE claim ledger table:
 *   poado_rwa_ace_claims
 *
 * Fields used:
 *   id
 *   claim_uid
 *   event_uid
 *   ace_user_id
 *   ace_wallet
 *   weight_value
 *   allocated_ton
 *   snapshot_time
 *   status
 *   claimed_ton
 *   claimed_tx_hash
 *   claimed_at
 *   created_at
 */

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/validators.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/guards.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/json.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/session-user.php';

if (!function_exists('poado_my_ace_exit')) {
    function poado_my_ace_exit(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('poado_my_ace_norm_decimal')) {
    function poado_my_ace_norm_decimal($value, int $scale = 9): string
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

if (!function_exists('poado_my_ace_sub')) {
    function poado_my_ace_sub(string $a, string $b, int $scale = 9): string
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
        poado_my_ace_exit([
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
        poado_my_ace_exit([
            'ok' => false,
            'error' => 'user_not_found',
            'message' => 'User not found.',
        ], 404);
    }

    if ((int)($user['is_active'] ?? 0) !== 1) {
        poado_my_ace_exit([
            'ok' => false,
            'error' => 'user_inactive',
            'message' => 'User inactive.',
        ], 403);
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;

    $statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));
    $allowedStatuses = ['claimable', 'partial', 'claimed', 'void'];

    $where = [
        'ace_user_id = :ace_user_id'
    ];
    $params = [
        ':ace_user_id' => (int)$user['id'],
    ];

    if ($statusFilter !== '') {
        if (!in_array($statusFilter, $allowedStatuses, true)) {
            poado_my_ace_exit([
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
            COALESCE(SUM(weight_value), 0) AS weight_total,
            COALESCE(SUM(allocated_ton), 0) AS allocated_total,
            COALESCE(SUM(claimed_ton), 0) AS claimed_total
        FROM poado_rwa_ace_claims
        WHERE {$whereSql}
    ");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'total_rows' => 0,
        'weight_total' => '0',
        'allocated_total' => '0',
        'claimed_total' => '0',
    ];

    $weightTotal = poado_my_ace_norm_decimal($summary['weight_total'] ?? '0');
    $allocatedTotal = poado_my_ace_norm_decimal($summary['allocated_total'] ?? '0');
    $claimedTotal = poado_my_ace_norm_decimal($summary['claimed_total'] ?? '0');
    $claimableTotal = poado_my_ace_sub($allocatedTotal, $claimedTotal, 9);

    $statusCountSql = "
        SELECT status, COUNT(*) AS c
        FROM poado_rwa_ace_claims
        WHERE ace_user_id = :ace_user_id
        GROUP BY status
    ";
    $statusCountStmt = $pdo->prepare($statusCountSql);
    $statusCountStmt->execute([':ace_user_id' => (int)$user['id']]);
    $statusRows = $statusCountStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $statusCounts = [
        'claimable' => 0,
        'partial' => 0,
        'claimed' => 0,
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
        FROM poado_rwa_ace_claims
        WHERE {$whereSql}
    ");
    $countStmt->execute($params);
    $totalRows = (int)($countStmt->fetchColumn() ?: 0);

    $listSql = "
        SELECT
            id,
            claim_uid,
            event_uid,
            ace_user_id,
            ace_wallet,
            weight_value,
            allocated_ton,
            snapshot_time,
            status,
            claimed_ton,
            claimed_tx_hash,
            claimed_at,
            created_at
        FROM poado_rwa_ace_claims
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
        $weightValue = poado_my_ace_norm_decimal($row['weight_value'] ?? '0');
        $allocatedTon = poado_my_ace_norm_decimal($row['allocated_ton'] ?? '0');
        $claimedTon = poado_my_ace_norm_decimal($row['claimed_ton'] ?? '0');
        $remainingTon = poado_my_ace_sub($allocatedTon, $claimedTon, 9);

        $items[] = [
            'id' => (int)($row['id'] ?? 0),
            'claim_uid' => (string)($row['claim_uid'] ?? ''),
            'event_uid' => (string)($row['event_uid'] ?? ''),
            'ace_wallet' => (string)($row['ace_wallet'] ?? ''),
            'weight_value' => $weightValue,
            'allocated_ton' => $allocatedTon,
            'claimed_ton' => $claimedTon,
            'remaining_ton' => $remainingTon,
            'snapshot_time' => $row['snapshot_time'] ?? null,
            'status' => (string)($row['status'] ?? ''),
            'claimed_tx_hash' => (string)($row['claimed_tx_hash'] ?? ''),
            'claimed_at' => $row['claimed_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ];
    }

    poado_my_ace_exit([
        'ok' => true,
        'user' => [
            'id' => (int)$user['id'],
            'wallet' => (string)$user['wallet'],
            'nickname' => (string)($user['nickname'] ?? ''),
            'role' => (string)($user['role'] ?? ''),
        ],
        'summary' => [
            'total_rows' => (int)($summary['total_rows'] ?? 0),
            'weight_total' => $weightTotal,
            'allocated_total_ton' => $allocatedTotal,
            'claimed_total_ton' => $claimedTotal,
            'claimable_total_ton' => $claimableTotal,
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
    poado_my_ace_exit([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'Failed to load ACE claims.',
        'details' => $e->getMessage(),
    ], 500);
}