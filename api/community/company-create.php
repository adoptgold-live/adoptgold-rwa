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

function make_uid(string $prefix = 'COMP'): string {
    return strtoupper($prefix . '-' . gmdate('YmdHis') . '-' . substr(bin2hex(random_bytes(6)), 0, 12));
}

try {
    $wallet = function_exists('get_wallet_session') ? trim((string)get_wallet_session()) : '';
    if ($wallet === '') out(['ok' => false, 'error' => 'LOGIN_REQUIRED'], 401);

    $raw = file_get_contents('php://input');
    $in = json_decode($raw ?: '{}', true);
    if (!is_array($in)) $in = [];

    $companyName = trim((string)($in['company_name'] ?? ''));
    $headline    = trim((string)($in['headline'] ?? ''));
    $websiteUrl  = trim((string)($in['website_url'] ?? ''));
    $countryIso2 = strtoupper(trim((string)($in['country_iso2'] ?? '')));
    $stateName   = trim((string)($in['state_name'] ?? ''));
    $cityName    = trim((string)($in['city_name'] ?? ''));
    $industryKey = trim((string)($in['industry_key'] ?? ''));
    $aboutHtml   = trim((string)($in['about_html'] ?? ''));

    if ($companyName === '') out(['ok' => false, 'error' => 'COMPANY_NAME_REQUIRED'], 422);

    $pdo = pdo_conn();

    $userSql = "
      SELECT id, role
      FROM users
      WHERE wallet_address = :wallet OR wallet = :wallet
      LIMIT 1
    ";
    $stm = $pdo->prepare($userSql);
    $stm->execute([':wallet' => $wallet]);
    $user = $stm->fetch(PDO::FETCH_ASSOC);

    if (!$user) out(['ok' => false, 'error' => 'USER_NOT_FOUND'], 404);

    $role = strtolower((string)($user['role'] ?? 'user'));
    if (!in_array($role, ['employer','agent','admin'], true)) {
        out(['ok' => false, 'error' => 'ROLE_NOT_ALLOWED'], 403);
    }

    $companyUid = make_uid('COMP');

    $sql = "
      INSERT INTO community_companies
      (
        company_uid,
        owner_user_id,
        company_name,
        headline,
        about_html,
        website_url,
        country_iso2,
        state_name,
        city_name,
        industry_key,
        status,
        created_at,
        updated_at
      )
      VALUES
      (
        :company_uid,
        :owner_user_id,
        :company_name,
        :headline,
        :about_html,
        :website_url,
        :country_iso2,
        :state_name,
        :city_name,
        :industry_key,
        'active',
        NOW(),
        NOW()
      )
    ";

    $ins = $pdo->prepare($sql);
    $ins->execute([
        ':company_uid'    => $companyUid,
        ':owner_user_id'  => (int)$user['id'],
        ':company_name'   => $companyName,
        ':headline'       => $headline !== '' ? $headline : null,
        ':about_html'     => $aboutHtml !== '' ? $aboutHtml : null,
        ':website_url'    => $websiteUrl !== '' ? $websiteUrl : null,
        ':country_iso2'   => $countryIso2 !== '' ? $countryIso2 : null,
        ':state_name'     => $stateName !== '' ? $stateName : null,
        ':city_name'      => $cityName !== '' ? $cityName : null,
        ':industry_key'   => $industryKey !== '' ? $industryKey : null,
    ]);

    out([
      'ok' => true,
      'company_uid' => $companyUid
    ]);
} catch (Throwable $e) {
    out([
      'ok' => false,
      'error' => 'COMPANY_CREATE_FAILED',
      'detail' => $e->getMessage()
    ], 500);
}
