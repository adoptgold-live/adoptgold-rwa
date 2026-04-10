<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../inc/rwa-session.php';
require_once __DIR__ . '/../../../dashboard/inc/bootstrap.php';
require_once __DIR__ . '/../../../dashboard/inc/session-user.php';

function out(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pdo_conn(): PDO {
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
    if (function_exists('db_connect')) {
        db_connect();
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
    }
    throw new RuntimeException('DB_NOT_READY');
}

try {
    $wallet = function_exists('get_wallet_session') ? trim((string)get_wallet_session()) : '';
    if ($wallet === '') out(['ok' => false, 'error' => 'LOGIN_REQUIRED'], 401);

    $pdo = pdo_conn();

    $userSql = "
      SELECT id
      FROM users
      WHERE wallet_address = :wallet OR wallet = :wallet
      LIMIT 1
    ";
    $stm = $pdo->prepare($userSql);
    $stm->execute([':wallet' => $wallet]);
    $user = $stm->fetch(PDO::FETCH_ASSOC);

    if (!$user) out(['ok' => false, 'error' => 'USER_NOT_FOUND'], 404);

    $sql = "
      SELECT
        company_uid,
        company_name,
        headline,
        website_url,
        country_iso2,
        state_name,
        city_name,
        industry_key,
        status,
        created_at,
        updated_at
      FROM community_companies
      WHERE owner_user_id = :owner_user_id
      ORDER BY id DESC
    ";

    $q = $pdo->prepare($sql);
    $q->execute([':owner_user_id' => (int)$user['id']]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

    out([
      'ok' => true,
      'data' => $rows
    ]);
} catch (Throwable $e) {
    out([
      'ok' => false,
      'error' => 'COMPANY_LIST_FAILED',
      'detail' => $e->getMessage()
    ], 500);
}
