<?php
declare(strict_types=1);

/**
 * POAdo RWA Cert Engine
 * File: /rwa/cert/api/admin-holder-claims.php
 *
 * Purpose:
 * - Canonical admin-wide holder claim ledger list API
 * - Reads from:
 *     wems_db.poado_rwa_holder_claims
 * - Supports summary + filters + pagination
 */

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/validators.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/guards.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/json.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/session-user.php';

if (!function_exists('poado_ahc_exit')) {
    function poado_ahc_exit(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('poado_ahc_norm_decimal')) {
    function poado_ahc_norm_decimal($value, int $scale = 9): string
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

if (!function_exists('poado_ahc_sub')) {
    function poado_ahc_sub(string $a, string $b, int $scale = 9): string
    {
        if (function_exists('bcsub')) {
            return bcsub($a, $b, $scale);
        }
        return number_format(((float)$a - (float)$b), $scale, '.', '');
    }
}

if (!function_exists('poado_ahc_is_admin_like')) {
    function poado_ahc_is_admin_like(array $user): bool
    {
        return !empty($user['is_admin']) || !empty($user['is_senior']);
    }
}

try {
    $wallet = get_wallet_session();
    if (!$wallet) {
        poado_ahc_exit([
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
        poado_ahc_exit([
            'ok' => false,
            'error' => 'user_not_found',
            'message' => 'User not found.',
        ], 404);
    }

    if ((int)($user['is_active'] ?? 0) !== 1) {
        poado_ahc_exit([
            'ok' => false,
            'error' => 'user_inactive',
            'message' => 'User inactive.',
        ], 403);
    }

    if (!poado_ahc_is_admin_like($user)) {
        poado_ahc_exit([
            'ok' => false,
            'error' => 'admin_only',
            'message' => 'Admin holder claim ledger is restricted to admin/senior operators.',
        ], 403);
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;

    $statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));
    $allowedStatuses = ['claimable', 'partial', 'claimed', 'void'];

    $ownerWallet = trim((string)($_GET['owner_wallet'] ?? ''));
    $claimUid = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($_GET['claim_uid'] ?? '')) ?: '';
    $eventUid = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($_GET['event_uid'] ?? '')) ?: '';
    $certUid = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($_GET['cert_uid'] ?? '')) ?: '';

    $where = ['1=1'];
    $params = [];

    if ($statusFilter !== '') {
        if (!in_array($statusFilter, $allowedStatuses, true)) {
            poado_ahc_exit([
                'ok' => false,
                'error' => 'invalid_status_filter',
                'message' => 'Invalid status filter.',
            ], 422);
        }
        $where[] = 'status = :status';
        $params[':status'] = $statusFilter;
    }

    if ($ownerWallet !== '') {
        $where[] = 'owner_wallet = :owner_wallet';
        $params[':owner_wallet'] = $ownerWallet;
    }

    if ($claimUid !== '') {
        $where[] = 'claim_uid = :claim_uid';
        $params[':claim_uid'] = $claimUid;
    }

    if ($eventUid !== '') {
        $where[] = 'event_uid = :event_uid';
        $params[':event_uid'] = $eventUid;
    }

    if ($certUid !== '') {
        $where[] = 'cert_uid = :cert_uid';
        $params[':cert_uid'] = $certUid;
    }

    $whereSql = implode(' AND ', $where);

    $summaryStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_rows,
            COALESCE(SUM(allocated_ton), 0) AS allocated_total,
            COALESCE(SUM(claimed_ton), 0) AS claimed_total
        FROM poado_rwa_holder_claims
        WHERE {$whereSql}
    ");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'total_rows' => 0,
        'allocated_total' => '0',
        'claimed_total' => '0',
    ];

    $allocatedTotal = poado_ahc_norm_decimal($summary['allocated_total'] ?? '0');
    $claimedTotal = poado_ahc_norm_decimal($summary['claimed_total'] ?? '0');
    $claimableTotal = poado_ahc_sub($allocatedTotal, $claimedTotal, 9);

    $statusCountStmt = $pdo->query("
        SELECT status, COUNT(*) AS c
        FROM poado_rwa_holder_claims
        GROUP BY status
    ");
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
        FROM poado_rwa_holder_claims
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
            owner_user_id,
            owner_wallet,
            allocated_ton,
            snapshot_time,
            status,
            claimed_ton,
            claimed_tx_hash,
            claimed_at,
            created_at
        FROM poado_rwa_holder_claims
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
        $allocatedTon = poado_ahc_norm_decimal($row['allocated_ton'] ?? '0');
        $claimedTon = poado_ahc_norm_decimal($row['claimed_ton'] ?? '0');
        $remainingTon = poado_ahc_sub($allocatedTon, $claimedTon, 9);

        $items[] = [
            'id' => (int)($row['id'] ?? 0),
            'claim_uid' => (string)($row['claim_uid'] ?? ''),
            'event_uid' => (string)($row['event_uid'] ?? ''),
            'cert_uid' => (string)($row['cert_uid'] ?? ''),
            'owner_user_id' => (int)($row['owner_user_id'] ?? 0),
            'owner_wallet' => (string)($row['owner_wallet'] ?? ''),
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

    poado_ahc_exit([
        'ok' => true,
        'user' => [
            'id' => (int)$user['id'],
            'wallet' => (string)$user['wallet'],
            'nickname' => (string)($user['nickname'] ?? ''),
            'role' => (string)($user['role'] ?? ''),
        ],
        'summary' => [
            'total_rows' => (int)($summary['total_rows'] ?? 0),
            'allocated_total_ton' => $allocatedTotal,
            'claimed_total_ton' => $claimedTotal,
            'claimable_total_ton' => $claimableTotal,
            'status_counts' => $statusCounts,
        ],
        'filters' => [
            'owner_wallet' => $ownerWallet !== '' ? $ownerWallet : null,
            'claim_uid' => $claimUid !== '' ? $claimUid : null,
            'event_uid' => $eventUid !== '' ? $eventUid : null,
            'cert_uid' => $certUid !== '' ? $certUid : null,
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
    poado_ahc_exit([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'Failed to load admin holder claims.',
        'details' => $e->getMessage(),
    ], 500);
}