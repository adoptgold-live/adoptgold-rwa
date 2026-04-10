<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/mining/activate.php
 * Backend verify + activate requested booster tier using on-chain EMA$ only.
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

function requested_tier(): string
{
    $tier = trim((string)($_POST['tier'] ?? $_GET['tier'] ?? ''));
    return $tier;
}

function tier_required(string $tier): ?float
{
    $map = [
        'sub' => 100.0,
        'core' => 1000.0,
        'nodes' => 5000.0,
        'super_node' => 100000.0,
    ];
    return $map[$tier] ?? null;
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

$tier = requested_tier();
$required = tier_required($tier);
if ($required === null) {
    fail_json('INVALID_TIER', 'Invalid tier request', 400);
}

if (!function_exists('poado_ensure_miner_profile') || !function_exists('poado_resolve_tier')) {
    fail_json('MINING_LIB_MISSING', 'Mining library not available', 500);
}

try {
    poado_ensure_miner_profile($pdo, $user);
} catch (Throwable $e) {
    fail_json('PROFILE_FAIL', 'Unable to ensure miner profile', 500);
}

try {
    $resolved = poado_resolve_tier($pdo, $userId, $wallet);
} catch (Throwable $e) {
    fail_json('RESOLVE_FAIL', 'Unable to resolve on-chain EMA tier', 500);
}

$emaActive = (float)($resolved['ema'] ?? $resolved['ema_staked_active'] ?? 0);
$currentTier = (string)($resolved['tier'] ?? $resolved['miner_tier'] ?? 'free');
$tierStatus = (string)($resolved['tier_status'] ?? 'active');

if ($emaActive < $required) {
    fail_json('INSUFFICIENT_ONCHAIN_EMA', 'Not enough on-chain EMA$ for requested tier', 403, [
        'requested_tier' => $tier,
        'required_onchain_ema' => $required,
        'current_ema_active' => round($emaActive, 8),
    ]);
}

if ($currentTier === $tier && $tierStatus === 'active') {
    out_json([
        'ok' => true,
        'message' => 'Tier already active',
        'requested_tier' => $tier,
        'current_tier' => $currentTier,
        'current_ema_active' => round($emaActive, 8),
        'already_active' => true,
    ]);
}

try {
    $resolved = poado_resolve_tier($pdo, $userId, $wallet);

    $activeTier = (string)($resolved['tier'] ?? $resolved['miner_tier'] ?? 'free');
    $multiplier = (float)($resolved['multiplier'] ?? 1);
    $dailyCap = (float)($resolved['daily_cap'] ?? $resolved['daily_cap_wems'] ?? 100);
    $bindingCap = (int)($resolved['binding_cap'] ?? 0);

    out_json([
        'ok' => true,
        'message' => 'Booster activation verified by on-chain EMA$',
        'requested_tier' => $tier,
        'active_tier' => $activeTier,
        'multiplier' => $multiplier,
        'daily_cap_wems' => $dailyCap,
        'binding_cap' => $bindingCap,
        'current_ema_active' => round((float)($resolved['ema'] ?? $resolved['ema_staked_active'] ?? $emaActive), 8),
        'source' => 'onchain_ema_only',
    ]);
} catch (Throwable $e) {
    fail_json('ACTIVATION_FAIL', 'Unable to activate booster tier', 500);
}
