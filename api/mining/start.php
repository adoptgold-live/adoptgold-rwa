<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/mining/start.php
 * Canonical runtime start endpoint.
 *
 * FIXES:
 * - no endpoint-to-endpoint include of status.php
 * - writes runtime state to session only here
 * - forces session persistence before JSON output
 * - returns status-like payload directly
 */

ini_set('display_errors', '0');
error_reporting(0);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

if (is_file($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/mining-config.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/mining-config.php';
}
if (is_file($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/mining-lib.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/mining-lib.php';
}
if (is_file($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/mining-guards.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/mining-guards.php';
}

while (ob_get_level() > 0) { @ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');

function mining_start_json(array $a, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mining_start_fail(string $error, string $message, int $code = 400, array $extra = []): never
{
    mining_start_json(array_merge([
        'ok' => false,
        'error' => $error,
        'message' => $message,
    ], $extra), $code);
}

function mining_start_wallet(array $user): string
{
    return trim((string)($user['wallet_address'] ?? $user['wallet'] ?? ''));
}

function mining_start_gate(PDO $pdo, int $userId, string $wallet): array
{
    if (function_exists('poado_fetch_user_profile_state')) {
        try {
            return poado_fetch_user_profile_state($pdo, $userId, $wallet);
        } catch (Throwable $e) {
        }
    }

    $st = $pdo->prepare("
        SELECT id, nickname, email, country_name, country, wallet_address
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $nickname = trim((string)($row['nickname'] ?? ''));
    $email    = trim((string)($row['email'] ?? ''));
    $country  = trim((string)($row['country_name'] ?? $row['country'] ?? ''));
    $bound    = trim((string)($row['wallet_address'] ?? '')) !== '';

    return [
        'user_id' => $userId,
        'wallet' => $wallet,
        'wallet_short' => $wallet !== '' ? substr($wallet, 0, 6) . '...' . substr($wallet, -4) : 'SESSION: NONE',
        'is_profile_complete' => ($nickname !== '' && $email !== '' && $country !== ''),
        'is_ton_bound' => $bound,
        'is_mining_eligible' => ($nickname !== '' && $email !== '' && $country !== '' && $bound),
    ];
}

function mining_start_fallback_tier(PDO $pdo, int $userId): array
{
    $isVerified = 0;
    try {
        $st = $pdo->prepare("SELECT is_fully_verified FROM users WHERE id = ? LIMIT 1");
        $st->execute([$userId]);
        $isVerified = (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
    }

    if ($isVerified === 1) {
        return [
            'tier' => 'verified',
            'tier_label' => 'Verified Miner',
            'tier_status' => 'active',
            'multiplier' => 2.0,
            'daily_cap_wems' => 300.0,
            'binding_cap' => 10,
            'ema_staked_active' => 0.0,
            'ema_stake_expires_at' => null,
            'node_reward_pct' => 0.0,
        ];
    }

    return [
        'tier' => 'free',
        'tier_label' => 'Free Miner',
        'tier_status' => 'active',
        'multiplier' => 1.0,
        'daily_cap_wems' => 100.0,
        'binding_cap' => 0,
        'ema_staked_active' => 0.0,
        'ema_stake_expires_at' => null,
        'node_reward_pct' => 0.0,
    ];
}

function mining_start_resolve_tier(PDO $pdo, int $userId, string $wallet, array $user): array
{
    if (function_exists('poado_ensure_miner_profile')) {
        try {
            poado_ensure_miner_profile($pdo, $user);
        } catch (Throwable $e) {
        }
    }

    if (function_exists('poado_resolve_tier')) {
        try {
            $resolved = poado_resolve_tier($pdo, $userId, $wallet);
            $tierCode = (string)($resolved['miner_tier'] ?? $resolved['tier'] ?? 'free');
            $tierStatus = (string)($resolved['tier_status'] ?? 'active');
            $mul = (float)($resolved['multiplier'] ?? 1);
            $cap = (float)($resolved['daily_cap_wems'] ?? $resolved['daily_cap'] ?? 100);
            $bindingCap = (int)($resolved['binding_cap'] ?? 0);
            $emaStaked = (float)($resolved['ema_staked_active'] ?? $resolved['ema'] ?? 0);
            $stakeExpiry = $resolved['ema_stake_expires_at'] ?? null;

            $nodePct = 0.0;
            if ($tierCode === 'nodes') {
                $nodePct = 0.5;
            } elseif ($tierCode === 'super_node') {
                $nodePct = 3.0;
            }

            $labelMap = [
                'free' => 'Free Miner',
                'verified' => 'Verified Miner',
                'sub' => 'Sub Miner',
                'core' => 'Core Miner',
                'nodes' => 'Nodes Miner',
                'super_node' => 'Super Node Miner',
            ];

            return [
                'tier' => $tierCode,
                'tier_label' => $labelMap[$tierCode] ?? ucfirst(str_replace('_', ' ', $tierCode)),
                'tier_status' => $tierStatus,
                'multiplier' => $mul,
                'daily_cap_wems' => $cap,
                'binding_cap' => $bindingCap,
                'ema_staked_active' => $emaStaked,
                'ema_stake_expires_at' => $stakeExpiry,
                'node_reward_pct' => $nodePct,
            ];
        } catch (Throwable $e) {
        }
    }

    return mining_start_fallback_tier($pdo, $userId);
}

function mining_start_totals(PDO $pdo, int $userId, string $wallet): array
{
    try {
        $st = $pdo->prepare("
            SELECT
                COALESCE(today_mined_wems, 0) AS today_mined_wems,
                COALESCE(today_binding_wems, 0) AS today_binding_wems,
                COALESCE(today_node_bonus_wems, 0) AS today_node_bonus_wems,
                COALESCE(total_mined_wems, 0) AS total_mined_wems,
                COALESCE(total_binding_wems, 0) AS total_binding_wems,
                COALESCE(total_node_bonus_wems, 0) AS total_node_bonus_wems,
                COALESCE(total_claimed_wems, 0) AS total_claimed_wems
            FROM poado_miner_profiles
            WHERE user_id = ? AND wallet = ?
            LIMIT 1
        ");
        $st->execute([$userId, $wallet]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $gross = (float)$row['total_mined_wems'] + (float)$row['total_binding_wems'] + (float)$row['total_node_bonus_wems'];
            $unclaimed = max(0, $gross - (float)$row['total_claimed_wems']);

            return [
                'daily_mined_wems' => (float)$row['today_mined_wems'],
                'today_binding_wems' => (float)$row['today_binding_wems'],
                'today_node_bonus_wems' => (float)$row['today_node_bonus_wems'],
                'total_mined_wems' => $gross,
                'storage_unclaimed_wems' => $unclaimed,
            ];
        }
    } catch (Throwable $e) {
    }

    return [
        'daily_mined_wems' => 0.0,
        'today_binding_wems' => 0.0,
        'today_node_bonus_wems' => 0.0,
        'total_mined_wems' => 0.0,
        'storage_unclaimed_wems' => 0.0,
    ];
}

function mining_start_booster(array $tier): array
{
    $emaActive = round((float)($tier['ema_staked_active'] ?? 0), 8);
    $currentTierCode = (string)($tier['tier'] ?? 'free');
    $tierStatus = (string)($tier['tier_status'] ?? 'active');

    $eligible = [
        'sub' => $emaActive >= 100,
        'core' => $emaActive >= 1000,
        'nodes' => $emaActive >= 5000,
        'super_node' => $emaActive >= 100000,
    ];

    $reasons = [
        'sub' => $eligible['sub'] ? (($currentTierCode === 'sub' && $tierStatus === 'active') ? 'active_now' : '') : 'need_100_onchain_ema',
        'core' => $eligible['core'] ? (($currentTierCode === 'core' && $tierStatus === 'active') ? 'active_now' : '') : 'need_1000_onchain_ema',
        'nodes' => $eligible['nodes'] ? (($currentTierCode === 'nodes' && $tierStatus === 'active') ? 'active_now' : '') : 'need_5000_onchain_ema',
        'super_node' => $eligible['super_node'] ? (($currentTierCode === 'super_node' && $tierStatus === 'active') ? 'active_now' : '') : 'need_100000_onchain_ema',
    ];

    if ($tierStatus === 'pending_ema') {
        foreach ($reasons as $k => $v) {
            if ($v === '') {
                $reasons[$k] = 'pending_ema';
            }
        }
    }

    return [
        'source' => 'onchain_ema_only',
        'current_ema_active' => $emaActive,
        'current_tier_code' => $currentTierCode,
        'tier_status' => $tierStatus,
        'eligible' => $eligible,
        'reasons' => $reasons,
    ];
}

function mining_start_payload(PDO $pdo, array $user, int $isMining, float $batteryPct): array
{
    $userId = (int)($user['id'] ?? 0);
    $wallet = mining_start_wallet($user);

    $gate = mining_start_gate($pdo, $userId, $wallet);
    $tier = mining_start_resolve_tier($pdo, $userId, $wallet, $user);
    $totals = mining_start_totals($pdo, $userId, $wallet);

    $ratePerTick = round(0.33 * (float)$tier['multiplier'], 8);

    return [
        'ok' => true,
        'is_mining' => $isMining,
        'battery_pct' => $batteryPct,
        'tier' => $tier['tier_label'],
        'tier_code' => $tier['tier'],
        'tier_status' => $tier['tier_status'],
        'multiplier' => (float)$tier['multiplier'],
        'daily_cap_wems' => (float)$tier['daily_cap_wems'],
        'daily_mined_wems' => round((float)$totals['daily_mined_wems'], 8),
        'today_binding_wems' => round((float)$totals['today_binding_wems'], 8),
        'today_node_bonus_wems' => round((float)$totals['today_node_bonus_wems'], 8),
        'rate_wems_per_tick' => $ratePerTick,
        'node_reward_pct' => (float)$tier['node_reward_pct'],
        'total_mined_wems' => round((float)$totals['total_mined_wems'], 8),
        'storage_unclaimed_wems' => round((float)$totals['storage_unclaimed_wems'], 8),
        'binding_cap' => (int)$tier['binding_cap'],
        'ema_staked_active' => round((float)$tier['ema_staked_active'], 8),
        'ema_stake_expires_at' => $tier['ema_stake_expires_at'],
        'wallet' => $wallet,
        'profile_complete' => (bool)$gate['is_profile_complete'],
        'ton_bound' => (bool)$gate['is_ton_bound'],
        'mining_eligible' => (bool)$gate['is_mining_eligible'],
        'booster' => mining_start_booster($tier),
    ];
}

db_connect();
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    mining_start_fail('NO_DB', 'DB connection unavailable', 500);
}

$user = session_user();
if (!is_array($user) || empty($user)) {
    mining_start_fail('NO_SESSION', 'Login required', 401);
}

$userId = (int)($user['id'] ?? 0);
$wallet = mining_start_wallet($user);

if ($userId <= 0 || $wallet === '') {
    mining_start_fail('NO_SESSION', 'Login required', 401);
}

$gate = mining_start_gate($pdo, $userId, $wallet);
if (empty($gate['is_mining_eligible'])) {
    mining_start_fail('MINING_LOCKED', 'Mining access locked', 403, [
        'profile_complete' => (bool)$gate['is_profile_complete'],
        'ton_bound' => (bool)$gate['is_ton_bound'],
        'mining_eligible' => (bool)$gate['is_mining_eligible'],
    ]);
}

$_SESSION['rwa_mining'] = array_merge((array)($_SESSION['rwa_mining'] ?? []), [
    'is_mining' => 1,
    'battery_pct' => 0,
    'started_at' => time(),
    'started_wallet' => $wallet,
    'last_tick_request_at' => 0,
    'stopped_at' => null,
]);

session_write_close();

mining_start_json(mining_start_payload($pdo, $user, 1, 0.0));
