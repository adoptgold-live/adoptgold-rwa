<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/cert/royalty-claimable.php
 *
 * Returns current royalty claimable summary for logged-in user.
 * Locked rule:
 * - full KYC required at actual claim stage
 * - this endpoint is read-only summary
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

function rc_json(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rc_pdo(): PDO
{
    if (function_exists('db')) return db();
    if (function_exists('rwa_db')) return rwa_db();
    if (($GLOBALS['pdo'] ?? null) instanceof PDO) return $GLOBALS['pdo'];
    throw new RuntimeException('PDO_NOT_AVAILABLE');
}

try {
    $user = rwa_require_login();
    $pdo = rc_pdo();

    $userId = (int)($user['id'] ?? $user['user_id'] ?? 0);
    $wallet = (string)($user['wallet_address'] ?? $user['wallet'] ?? $user['ton_wallet'] ?? '');

    if ($userId <= 0 && $wallet === '') {
        rc_json(['ok' => false, 'error' => 'USER_IDENTITY_MISSING'], 400);
    }

    $sql = "SELECT
              COUNT(*) AS rows_count,
              COALESCE(SUM(rewards_share_ton),0) AS rewards_share_ton,
              COALESCE(SUM(gold_packet_share_ton),0) AS gold_packet_share_ton,
              COALESCE(SUM(claimable_ton),0) AS claimable_ton,
              COALESCE(SUM(claimed_ton),0) AS claimed_ton
            FROM poado_rwa_royalty_allocations
            WHERE status IN ('allocated','claimable','partial')";
    $params = [];

    if ($userId > 0) {
        $sql .= " AND owner_user_id = :user_id";
        $params[':user_id'] = $userId;
    } else {
        $sql .= " AND ton_wallet = :ton_wallet";
        $params[':ton_wallet'] = $wallet;
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $summary = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $sql2 = "SELECT
                id, snapshot_ref, rewards_share_ton, gold_packet_share_ton, claimable_ton, claimed_ton, status, created_at
             FROM poado_rwa_royalty_allocations
             WHERE status IN ('allocated','claimable','partial')";
    if ($userId > 0) {
        $sql2 .= " AND owner_user_id = :user_id";
    } else {
        $sql2 .= " AND ton_wallet = :ton_wallet";
    }
    $sql2 .= " ORDER BY id DESC LIMIT 50";

    $st2 = $pdo->prepare($sql2);
    $st2->execute($params);
    $rows = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];

    rc_json([
        'ok' => true,
        'user_id' => $userId,
        'ton_wallet' => $wallet,
        'summary' => $summary,
        'rows' => $rows,
    ]);
} catch (Throwable $e) {
    rc_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
