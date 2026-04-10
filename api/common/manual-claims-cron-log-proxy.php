<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

function mclog_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mclog_fail(string $code, string $message, int $status = 400, array $extra = []): never
{
    mclog_json(['ok' => false, 'code' => $code, 'message' => $message, 'ts' => gmdate('c')] + $extra, $status);
}

function mclog_ok(array $data = []): never
{
    mclog_json(['ok' => true, 'ts' => gmdate('c')] + $data, 200);
}

function mclog_session_user(): array
{
    $candidates = [];

    if (function_exists('session_user')) {
        try {
            $u = session_user();
            if (is_array($u)) {
                $candidates[] = $u;
            }
        } catch (Throwable $e) {
        }
    }

    if (isset($GLOBALS['session_user']) && is_array($GLOBALS['session_user'])) {
        $candidates[] = $GLOBALS['session_user'];
    }

    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
        $candidates[] = $_SESSION['user'];
    }

    foreach ($candidates as $u) {
        $id = (int)($u['id'] ?? $u['user_id'] ?? 0);
        if ($id > 0) {
            return $u;
        }
    }

    mclog_fail('AUTH_REQUIRED', 'Authentication required.', 401);
}

function mclog_is_admin(array $user): bool
{
    $checks = [
        $_SESSION['is_admin'] ?? null,
        $_SESSION['is_super_admin'] ?? null,
        $_SESSION['admin'] ?? null,
        $_SESSION['role'] ?? null,
        $_SESSION['user_role'] ?? null,
        $_SESSION['session_user']['is_admin'] ?? null,
        $_SESSION['session_user']['role'] ?? null,
        $user['is_admin'] ?? null,
        $user['role'] ?? null,
    ];

    foreach ($checks as $v) {
        if ($v === true || $v === 1 || $v === '1' || $v === 'admin' || $v === 'super_admin') {
            return true;
        }
    }

    return false;
}

$user = mclog_session_user();
if (!mclog_is_admin($user)) {
    mclog_fail('ADMIN_REQUIRED', 'Administrator access required.', 403);
}

if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
    if (function_exists('db_connect')) {
        db_connect();
    }
}
if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
    mclog_fail('DB_UNAVAILABLE', 'Database connection unavailable.', 500);
}

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'];
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$days = (int)($_GET['days'] ?? 7);
$days = max(1, min(30, $days));

$sql = "SELECT
            id,
            module,
            error_code,
            context,
            public_hint,
            created_at
        FROM wems_db.poado_api_errors
        WHERE module = 'manual_claims_reserve_cron'
          AND created_at >= (UTC_TIMESTAMP() - INTERVAL :days DAY)
        ORDER BY id DESC
        LIMIT 500";

$st = $pdo->prepare($sql);
$st->bindValue(':days', $days, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

foreach ($rows as &$row) {
    $ctx = $row['context'] ?? null;
    if (is_string($ctx) && $ctx !== '') {
        $decoded = json_decode($ctx, true);
        $row['context'] = is_array($decoded) ? $decoded : $ctx;
    }
}
unset($row);

mclog_ok([
    'items' => $rows,
    'count' => count($rows),
    'days' => $days,
]);
