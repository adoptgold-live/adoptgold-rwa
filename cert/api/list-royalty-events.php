<?php
declare(strict_types=1);

/**
 * POAdo RWA Cert Engine
 * File: /rwa/cert/api/list-royalty-events.php
 *
 * Purpose:
 * - Canonical read API for royalty event history
 * - Reads from:
 *     wems_db.poado_rwa_royalty_events_v2
 * - Supports summary + filters + pagination
 *
 * Locked schema:
 *   id
 *   event_uid
 *   cert_uid
 *   nft_item_index
 *   marketplace
 *   sale_amount_ton
 *   royalty_amount_ton
 *   treasury_tx_hash
 *   block_time
 *   holder_pool_ton
 *   ace_pool_ton
 *   gold_packet_pool_ton
 *   treasury_retained_ton
 *   created_at
 */

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/validators.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/guards.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/json.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/session-user.php';

if (!function_exists('poado_lre_exit')) {
    function poado_lre_exit(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('poado_lre_norm_decimal')) {
    function poado_lre_norm_decimal($value, int $scale = 9): string
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

if (!function_exists('poado_lre_is_admin_like')) {
    function poado_lre_is_admin_like(array $user): bool
    {
        return !empty($user['is_admin']) || !empty($user['is_senior']);
    }
}

try {
    $wallet = get_wallet_session();
    if (!$wallet) {
        poado_lre_exit([
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
        poado_lre_exit([
            'ok' => false,
            'error' => 'user_not_found',
            'message' => 'User not found.',
        ], 404);
    }

    if ((int)($user['is_active'] ?? 0) !== 1) {
        poado_lre_exit([
            'ok' => false,
            'error' => 'user_inactive',
            'message' => 'User inactive.',
        ], 403);
    }

    if (!poado_lre_is_admin_like($user)) {
        poado_lre_exit([
            'ok' => false,
            'error' => 'admin_only',
            'message' => 'Royalty event history is restricted to admin/senior operators.',
        ], 403);
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;

    $marketplace = strtolower(trim((string)($_GET['marketplace'] ?? '')));
    $certUid = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($_GET['cert_uid'] ?? '')) ?: '';
    $eventUid = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($_GET['event_uid'] ?? '')) ?: '';
    $treasuryTxHash = trim((string)($_GET['treasury_tx_hash'] ?? ''));
    $dateFrom = trim((string)($_GET['date_from'] ?? ''));
    $dateTo = trim((string)($_GET['date_to'] ?? ''));

    $where = ['1=1'];
    $params = [];

    if ($marketplace !== '') {
        $where[] = 'marketplace = :marketplace';
        $params[':marketplace'] = $marketplace;
    }

    if ($certUid !== '') {
        $where[] = 'cert_uid = :cert_uid';
        $params[':cert_uid'] = $certUid;
    }

    if ($eventUid !== '') {
        $where[] = 'event_uid = :event_uid';
        $params[':event_uid'] = $eventUid;
    }

    if ($treasuryTxHash !== '') {
        $where[] = 'treasury_tx_hash = :treasury_tx_hash';
        $params[':treasury_tx_hash'] = $treasuryTxHash;
    }

    if ($dateFrom !== '') {
        $where[] = 'block_time >= :date_from';
        $params[':date_from'] = $dateFrom;
    }

    if ($dateTo !== '') {
        $where[] = 'block_time <= :date_to';
        $params[':date_to'] = $dateTo;
    }

    $whereSql = implode(' AND ', $where);

    $summaryStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_rows,
            COALESCE(SUM(sale_amount_ton), 0) AS sale_total,
            COALESCE(SUM(royalty_amount_ton), 0) AS royalty_total,
            COALESCE(SUM(holder_pool_ton), 0) AS holder_total,
            COALESCE(SUM(ace_pool_ton), 0) AS ace_total,
            COALESCE(SUM(gold_packet_pool_ton), 0) AS gold_packet_total,
            COALESCE(SUM(treasury_retained_ton), 0) AS treasury_total
        FROM poado_rwa_royalty_events_v2
        WHERE {$whereSql}
    ");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'total_rows' => 0,
        'sale_total' => '0',
        'royalty_total' => '0',
        'holder_total' => '0',
        'ace_total' => '0',
        'gold_packet_total' => '0',
        'treasury_total' => '0',
    ];

    $countStmt = $pdo->prepare("
        SELECT COUNT(*) AS total_rows
        FROM poado_rwa_royalty_events_v2
        WHERE {$whereSql}
    ");
    $countStmt->execute($params);
    $totalRows = (int)($countStmt->fetchColumn() ?: 0);

    $listSql = "
        SELECT
            id,
            event_uid,
            cert_uid,
            nft_item_index,
            marketplace,
            sale_amount_ton,
            royalty_amount_ton,
            treasury_tx_hash,
            block_time,
            holder_pool_ton,
            ace_pool_ton,
            gold_packet_pool_ton,
            treasury_retained_ton,
            created_at
        FROM poado_rwa_royalty_events_v2
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
            'event_uid' => (string)($row['event_uid'] ?? ''),
            'cert_uid' => (string)($row['cert_uid'] ?? ''),
            'nft_item_index' => isset($row['nft_item_index']) ? (int)$row['nft_item_index'] : null,
            'marketplace' => (string)($row['marketplace'] ?? ''),
            'sale_amount_ton' => poado_lre_norm_decimal($row['sale_amount_ton'] ?? '0'),
            'royalty_amount_ton' => poado_lre_norm_decimal($row['royalty_amount_ton'] ?? '0'),
            'treasury_tx_hash' => (string)($row['treasury_tx_hash'] ?? ''),
            'block_time' => $row['block_time'] ?? null,
            'holder_pool_ton' => poado_lre_norm_decimal($row['holder_pool_ton'] ?? '0'),
            'ace_pool_ton' => poado_lre_norm_decimal($row['ace_pool_ton'] ?? '0'),
            'gold_packet_pool_ton' => poado_lre_norm_decimal($row['gold_packet_pool_ton'] ?? '0'),
            'treasury_retained_ton' => poado_lre_norm_decimal($row['treasury_retained_ton'] ?? '0'),
            'created_at' => $row['created_at'] ?? null,
        ];
    }

    poado_lre_exit([
        'ok' => true,
        'user' => [
            'id' => (int)$user['id'],
            'wallet' => (string)$user['wallet'],
            'nickname' => (string)($user['nickname'] ?? ''),
            'role' => (string)($user['role'] ?? ''),
        ],
        'summary' => [
            'total_rows' => (int)($summary['total_rows'] ?? 0),
            'sale_total_ton' => poado_lre_norm_decimal($summary['sale_total'] ?? '0'),
            'royalty_total_ton' => poado_lre_norm_decimal($summary['royalty_total'] ?? '0'),
            'holder_total_ton' => poado_lre_norm_decimal($summary['holder_total'] ?? '0'),
            'ace_total_ton' => poado_lre_norm_decimal($summary['ace_total'] ?? '0'),
            'gold_packet_total_ton' => poado_lre_norm_decimal($summary['gold_packet_total'] ?? '0'),
            'treasury_total_ton' => poado_lre_norm_decimal($summary['treasury_total'] ?? '0'),
        ],
        'filters' => [
            'marketplace' => $marketplace !== '' ? $marketplace : null,
            'cert_uid' => $certUid !== '' ? $certUid : null,
            'event_uid' => $eventUid !== '' ? $eventUid : null,
            'treasury_tx_hash' => $treasuryTxHash !== '' ? $treasuryTxHash : null,
            'date_from' => $dateFrom !== '' ? $dateFrom : null,
            'date_to' => $dateTo !== '' ? $dateTo : null,
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
    poado_lre_exit([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'Failed to load royalty event history.',
        'details' => $e->getMessage(),
    ], 500);
}