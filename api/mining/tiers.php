<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/mining/tiers.php
 * Live booster tier options based on on-chain EMA$ only.
 */

require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

if (is_file($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/mining-lib.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/mining-lib.php';
}

header('Content-Type: application/json; charset=utf-8');

function out_json(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail_json(string $error, string $message, int $code = 400): never
{
    out_json([
        'ok' => false,
        'error' => $error,
        'message' => $message,
    ], $code);
}

function current_wallet(array $user): string
{
    return trim((string)($user['wallet_address'] ?? $user['wallet'] ?? ''));
}

db_connect();
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    fail_json('NO_DB', 'DB connection unavailable', 500);
}

$user = session_user();
if (!is_array($user) || empty($user)) {
    fail_json('NO_SESSION', 'Login required', 401);
}

$userId = (int)($user['id'] ?? 0);
$wallet = current_wallet($user);
if ($userId <= 0 || $wallet === '') {
    fail_json('WALLET_NOT_BOUND', 'TON wallet not bound', 403);
}

$emaActive = 0.0;
$tierCode = 'free';
$tierStatus = 'active';

if (function_exists('poado_resolve_tier')) {
    try {
        $resolved = poado_resolve_tier($pdo, $userId, $wallet);
        $emaActive = (float)($resolved['ema'] ?? $resolved['ema_staked_active'] ?? 0);
        $tierCode = (string)($resolved['tier'] ?? $resolved['miner_tier'] ?? 'free');
        $tierStatus = (string)($resolved['tier_status'] ?? 'active');
    } catch (Throwable $e) {
    }
}

$tiers = [
    [
        'tier_code' => 'sub',
        'label' => 'Sub Miner',
        'required_onchain_ema' => 100.0,
        'multiplier' => 3,
        'daily_cap_wems' => 500,
        'binding_cap' => 100,
        'node_reward_pct' => 0.0,
    ],
    [
        'tier_code' => 'core',
        'label' => 'Core Miner',
        'required_onchain_ema' => 1000.0,
        'multiplier' => 5,
        'daily_cap_wems' => 1000,
        'binding_cap' => 300,
        'node_reward_pct' => 0.0,
    ],
    [
        'tier_code' => 'nodes',
        'label' => 'Nodes Miner',
        'required_onchain_ema' => 5000.0,
        'multiplier' => 10,
        'daily_cap_wems' => 3000,
        'binding_cap' => 1000,
        'node_reward_pct' => 0.5,
    ],
    [
        'tier_code' => 'super_node',
        'label' => 'Super Node Miner',
        'required_onchain_ema' => 100000.0,
        'multiplier' => 30,
        'daily_cap_wems' => 10000,
        'binding_cap' => 3000,
        'node_reward_pct' => 3.0,
    ],
];

foreach ($tiers as &$tier) {
    $eligible = $emaActive >= (float)$tier['required_onchain_ema'];
    $activeNow = ($tierCode === $tier['tier_code'] && $tierStatus === 'active');

    $reason = '';
    if ($activeNow) {
        $reason = 'active_now';
    } elseif (!$eligible) {
        $reason = 'need_' . rtrim(rtrim((string)$tier['required_onchain_ema'], '0'), '.') . '_onchain_ema';
    } elseif ($tierStatus === 'pending_ema') {
        $reason = 'pending_ema';
    }

    $tier['eligible'] = $eligible;
    $tier['active_now'] = $activeNow;
    $tier['reason'] = $reason;
}

out_json([
    'ok' => true,
    'source' => 'onchain_ema_only',
    'current_ema_active' => round($emaActive, 8),
    'current_tier_code' => $tierCode,
    'tier_status' => $tierStatus,
    'tiers' => $tiers,
]);
