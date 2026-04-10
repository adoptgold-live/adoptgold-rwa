<?php
declare(strict_types=1);

/**
 * /rwa/api/mining/binding-list.php
 * My Binding Listing
 */

ini_set('display_errors', '0');
error_reporting(0);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

while (ob_get_level() > 0) { @ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');

function bl_json(array $a, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bl_fail(string $error, string $message, int $code = 400, array $extra = []): never
{
    bl_json(array_merge([
        'ok' => false,
        'error' => $error,
        'message' => $message,
    ], $extra), $code);
}

function bl_wallet(array $user): string
{
    return trim((string)($user['wallet_address'] ?? $user['wallet'] ?? ''));
}

function bl_wallet_short(string $wallet): string
{
    $wallet = trim($wallet);
    if ($wallet === '') return '—';
    return mb_substr($wallet, 0, 6) . '...' . mb_substr($wallet, -4);
}

function bl_like_escape(string $s): string
{
    return strtr($s, ['\\' => '\\\\', '%' => '\%', '_' => '\_']);
}

db_connect();
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    bl_fail('NO_DB', 'DB connection unavailable', 500);
}

$user = session_user();
if (!is_array($user) || empty($user)) {
    bl_fail('NO_SESSION', 'Login required', 401);
}

$userId = (int)($user['id'] ?? 0);
$wallet = bl_wallet($user);
if ($userId <= 0 || $wallet === '') {
    bl_fail('NO_SESSION', 'Login required', 401);
}

$q = trim((string)($_GET['q'] ?? ''));
$rows = [];

try {
    $sql = "
        SELECT
            b.bound_user_id,
            COALESCE(u.nickname, '—') AS nickname,
            COALESCE(u.wallet_address, '') AS wallet,
            COALESCE(b.status, 'active') AS status,
            b.created_at AS bound_at,
            COALESCE(mp.today_mined_wems, 0) AS today_mined_wems,
            COALESCE(mp.total_binding_wems, 0) AS total_binding_wems,
            mp.updated_at AS last_active_at
        FROM poado_adopter_bindings b
        LEFT JOIN users u
            ON u.id = b.bound_user_id
        LEFT JOIN poado_miner_profiles mp
            ON mp.user_id = b.bound_user_id
           AND mp.wallet = u.wallet_address
        WHERE b.adopter_user_id = ?
    ";

    $params = [$userId];

    if ($q !== '') {
        $like = '%' . bl_like_escape($q) . '%';
        $sql .= "
          AND (
            u.nickname LIKE ? ESCAPE '\\'
            OR u.wallet_address LIKE ? ESCAPE '\\'
          )
        ";
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= " ORDER BY b.created_at DESC, b.id DESC LIMIT 500";

    $st = $pdo->prepare($sql);
    $st->execute($params);

    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $todayCommission = round((float)($r['today_mined_wems'] ?? 0) * 0.01, 8);

        $rows[] = [
            'nickname' => (string)($r['nickname'] ?? '—'),
            'wallet' => (string)($r['wallet'] ?? ''),
            'wallet_short' => bl_wallet_short((string)($r['wallet'] ?? '')),
            'status' => strtoupper((string)($r['status'] ?? 'ACTIVE')),
            'bound_at' => (string)($r['bound_at'] ?? '—'),
            'today_mined_wems' => round((float)($r['today_mined_wems'] ?? 0), 8),
            'today_binding_wems' => $todayCommission,
            'total_binding_wems' => round((float)($r['total_binding_wems'] ?? 0), 8),
            'last_active_at' => (string)($r['last_active_at'] ?? '—'),
        ];
    }
} catch (Throwable $e) {
    bl_fail('LIST_READ_FAIL', 'Failed to read binding listing', 500, [
        'detail' => $e->getMessage(),
    ]);
}

bl_json([
    'ok' => true,
    'rows' => $rows,
]);
