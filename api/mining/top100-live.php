<?php
declare(strict_types=1);

/**
 * /rwa/api/mining/top100-live.php
 * Top 100 Miners Live
 */

ini_set('display_errors', '0');
error_reporting(0);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

while (ob_get_level() > 0) { @ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');

function tl_json(array $a, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tl_fail(string $error, string $message, int $code = 400, array $extra = []): never
{
    tl_json(array_merge([
        'ok' => false,
        'error' => $error,
        'message' => $message,
    ], $extra), $code);
}

function tl_wallet(array $user): string
{
    return trim((string)($user['wallet_address'] ?? $user['wallet'] ?? ''));
}

function tl_wallet_short(string $wallet): string
{
    $wallet = trim($wallet);
    if ($wallet === '') return '—';
    return mb_substr($wallet, 0, 6) . '...' . mb_substr($wallet, -4);
}

function tl_tier_label(string $tier): string
{
    $map = [
        'free'       => 'Free Miner',
        'verified'   => 'Verified Miner',
        'sub'        => 'Sub Miner',
        'core'       => 'Core Miner',
        'nodes'      => 'Nodes Miner',
        'super_node' => 'Super Node Miner',
    ];
    return (string)($map[$tier] ?? ucfirst(str_replace('_', ' ', $tier)));
}

db_connect();
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    tl_fail('NO_DB', 'DB connection unavailable', 500);
}

$user = session_user();
if (!is_array($user) || empty($user)) {
    tl_fail('NO_SESSION', 'Login required', 401);
}

$userId = (int)($user['id'] ?? 0);
$wallet = tl_wallet($user);
if ($userId <= 0 || $wallet === '') {
    tl_fail('NO_SESSION', 'Login required', 401);
}

$rows = [];

try {
    $sql = "
        SELECT
            mp.user_id,
            COALESCE(u.nickname, '—') AS nickname,
            COALESCE(mp.wallet, u.wallet_address, '') AS wallet,
            COALESCE(mp.miner_tier, 'free') AS miner_tier,
            COALESCE(mp.today_mined_wems, 0) AS today_mined_wems,
            COALESCE(mp.total_mined_wems, 0) AS total_mined_wems,
            COALESCE(mp.total_binding_wems, 0) AS total_binding_wems,
            (
              SELECT COUNT(*)
              FROM poado_adopter_bindings b
              WHERE b.adopter_user_id = mp.user_id
                AND (b.is_active = 1 OR b.is_active IS NULL)
            ) AS bound_count
        FROM poado_miner_profiles mp
        LEFT JOIN users u
          ON u.id = mp.user_id
        ORDER BY mp.today_mined_wems DESC, mp.total_mined_wems DESC, mp.user_id ASC
        LIMIT 100
    ";

    $st = $pdo->query($sql);
    $rank = 1;

    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $rows[] = [
            'rank' => $rank++,
            'nickname' => (string)($r['nickname'] ?? '—'),
            'wallet' => (string)($r['wallet'] ?? ''),
            'wallet_short' => tl_wallet_short((string)($r['wallet'] ?? '')),
            'tier' => (string)($r['miner_tier'] ?? 'free'),
            'tier_label' => tl_tier_label((string)($r['miner_tier'] ?? 'free')),
            'today_mined_wems' => round((float)($r['today_mined_wems'] ?? 0), 8),
            'total_mined_wems' => round((float)($r['total_mined_wems'] ?? 0), 8),
            'bound_count' => (int)($r['bound_count'] ?? 0),
            'today_binding_wems' => round((float)($r['total_binding_wems'] ?? 0), 8),
        ];
    }
} catch (Throwable $e) {
    tl_fail('TOP100_READ_FAIL', 'Failed to read top 100 miners', 500, [
        'detail' => $e->getMessage(),
    ]);
}

tl_json([
    'ok' => true,
    'live_ts' => gmdate('Y-m-d H:i:s') . ' UTC',
    'rows' => $rows,
]);
