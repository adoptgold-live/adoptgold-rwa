<?php
declare(strict_types=1);

/**
 * POAdo RWA Cert Engine
 * File: /var/www/html/public/rwa/cert/api/my-treasury-retained-summary.php
 *
 * Purpose:
 * - Return Treasury/Admin retained ledger summary
 * - Reads from:
 *     wems_db.poado_rwa_treasury_retained
 * - This is a treasury-ledger read API, not a payout API
 *
 * Current model:
 * - Treasury retained portion is ledgered from royalty events
 * - This endpoint shows:
 *     total retained
 *     retained rows by status
 *     paginated retained event rows
 *
 * Assumed Treasury retained ledger table:
 *   poado_rwa_treasury_retained
 *
 * Fields used:
 *   id
 *   retain_uid
 *   event_uid
 *   cert_uid
 *   marketplace
 *   treasury_wallet
 *   retained_ton
 *   snapshot_time
 *   status
 *   note
 *   created_at
 */

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/validators.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/guards.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/json.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/session-user.php';

if (!function_exists('poado_trsum_exit')) {
    function poado_trsum_exit(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('poado_trsum_norm_decimal')) {
    function poado_trsum_norm_decimal($value, int $scale = 9): string
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

if (!function_exists('poado_trsum_is_admin_like')) {
    function poado_trsum_is_admin_like(array $user): bool
    {
        return !empty($user['is_admin']) || !empty($user['is_senior']);
    }
}

try {
    $wallet = get_wallet_session();
    if (!$wallet) {
        poado_trsum_exit([
            'ok' => false,
            'error' => 'not_logged_in',
            'message' => 'Login required.',
        ], 401);
    }

    db_connect();
    /** @var PDO $pdo */
    $pdo = $GLOBALS['pdo'];

    $userStmt = $pdo->prepare("
        SELECT id, wallet, nickname, role, is_active, is_admin, is_senior
        FROM users
        WHERE wallet = :wallet
        LIMIT 1
    ");
    $userStmt->execute([':wallet' => $wallet]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        poado_trsum_exit([
            'ok' => false,
            'error' => 'user_not_found',
            'message' => 'User not found.',
        ], 404);
    }

    if ((int)($user['is_active'] ?? 0) !== 1) {
        poado_trsum_exit([
            'ok' => false,
            'error' => 'user_inactive',
            'message' => 'User inactive.',
        ], 403);
    }

    if (!poado_trsum_is_admin_like($user)) {
        poado_trsum_exit([
            'ok' => false,
            'error' => 'admin_only',
            'message' => 'Treasury retained summary is restricted to admin/senior operators.',
        ], 403);
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;

    $statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));
    $allowedStatuses = ['retained', 'reconciled', 'void'];

    $where = ['1=1'];
    $params = [];

    if ($statusFilter !== '') {
        if (!in_array($statusFilter, $allowedStatuses, true)) {
            poado_trsum_exit([
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
            COALESCE(SUM(retained_ton), 0) AS retained_total
        FROM poado_rwa_treasury_retained
        WHERE {$whereSql}
    ");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'total_rows' => 0,
        'retained_total' => '0',
    ];

    $retainedTotal = poado_trsum_norm_decimal($summary['retained_total'] ?? '0');

    $statusCountStmt = $pdo->prepare("
        SELECT status, COUNT(*) AS c
        FROM poado_rwa_treasury_retained
        GROUP BY status
    ");
    $statusCountStmt->execute();
    $statusRows = $statusCountStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $statusCounts = [
        'retained' => 0,
        'reconciled' => 0,
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
        FROM poado_rwa_treasury_retained
        WHERE {$whereSql}
    ");
    $countStmt->execute($params);
    $totalRows = (int)($countStmt->fetchColumn() ?: 0);

    $listSql = "
        SELECT
            id,
            retain_uid,
            event_uid,
            cert_uid,
            marketplace,
            treasury_wallet,
            retained_ton,
            snapshot_time,
            status,
            note,
            created_at
        FROM poado_rwa_treasury_retained
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
        $items[] = [
            'id' => (int)($row['id'] ?? 0),
            'retain_uid' => (string)($row['retain_uid'] ?? ''),
            'event_uid' => (string)($row['event_uid'] ?? ''),
            'cert_uid' => (string)($row['cert_uid'] ?? ''),
            'marketplace' => (string)($row['marketplace'] ?? ''),
            'treasury_wallet' => (string)($row['treasury_wallet'] ?? ''),
            'retained_ton' => poado_trsum_norm_decimal($row['retained_ton'] ?? '0'),
            'snapshot_time' => $row['snapshot_time'] ?? null,
            'status' => (string)($row['status'] ?? ''),
            'note' => (string)($row['note'] ?? ''),
            'created_at' => $row['created_at'] ?? null,
        ];
    }

    poado_trsum_exit([
        'ok' => true,
        'user' => [
            'id' => (int)$user['id'],
            'wallet' => (string)$user['wallet'],
            'nickname' => (string)($user['nickname'] ?? ''),
            'role' => (string)($user['role'] ?? ''),
        ],
        'summary' => [
            'total_rows' => (int)($summary['total_rows'] ?? 0),
            'retained_total_ton' => $retainedTotal,
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
    poado_trsum_exit([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'Failed to load Treasury retained summary.',
        'details' => $e->getMessage(),
    ], 500);
}