<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/cert/royalty-claims-history.php
 *
 * User API:
 * - returns royalty claim history for logged-in user
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

function rch_json(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rch_pdo(): PDO
{
    if (function_exists('db')) return db();
    if (function_exists('rwa_db')) return rwa_db();
    if (($GLOBALS['pdo'] ?? null) instanceof PDO) return $GLOBALS['pdo'];
    throw new RuntimeException('PDO_NOT_AVAILABLE');
}

try {
    $user = rwa_require_login();
    $pdo = rch_pdo();

    $userId = (int)($user['id'] ?? $user['user_id'] ?? 0);
    $wallet = (string)($user['wallet_address'] ?? $user['wallet'] ?? $user['ton_wallet'] ?? '');
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));

    if ($userId <= 0 && $wallet === '') {
        rch_json(['ok' => false, 'error' => 'USER_IDENTITY_MISSING'], 400);
    }

    $sql = "
        SELECT
            id, claim_ref, snapshot_id, owner_user_id, ton_wallet, claim_type,
            amount_ton, treasury_fee_ton, claim_tx_hash, status, approved_at, paid_at, created_at
        FROM poado_rwa_royalty_claims
        WHERE 1=1
    ";
    $params = [];

    if ($userId > 0) {
        $sql .= " AND owner_user_id = :user_id";
        $params[':user_id'] = $userId;
    } else {
        $sql .= " AND ton_wallet = :ton_wallet";
        $params[':ton_wallet'] = $wallet;
    }

    $sql .= " ORDER BY id DESC LIMIT " . $limit;

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $sum = $pdo->prepare("
        SELECT
            COALESCE(COUNT(*),0) AS total_claims,
            COALESCE(SUM(amount_ton),0) AS total_amount_ton,
            COALESCE(SUM(CASE WHEN status='paid' THEN amount_ton ELSE 0 END),0) AS paid_amount_ton,
            COALESCE(SUM(CASE WHEN status IN ('pending','queued','approved','kyc_required') THEN amount_ton ELSE 0 END),0) AS open_amount_ton
        FROM poado_rwa_royalty_claims
        WHERE " . ($userId > 0 ? "owner_user_id = :user_id" : "ton_wallet = :ton_wallet")
    );
    $sum->execute($params);
    $summary = $sum->fetch(PDO::FETCH_ASSOC) ?: [];

    rch_json([
        'ok' => true,
        'summary' => $summary,
        'rows' => $rows,
    ]);
} catch (Throwable $e) {
    rch_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
