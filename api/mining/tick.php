<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/mining/tick.php
 * Authoritative tick credit endpoint.
 *
 * FIXES:
 * - better runtime self-heal from start session state
 * - no dependency on status endpoint include
 * - force session persistence after runtime mutations
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

function mining_tick_json(array $a, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mining_tick_fail(string $error, string $message, int $code = 400, array $extra = []): never
{
    mining_tick_json(array_merge([
        'ok' => false,
        'error' => $error,
        'message' => $message,
    ], $extra), $code);
}

function mining_tick_wallet(array $user): string
{
    return trim((string)($user['wallet_address'] ?? $user['wallet'] ?? ''));
}

function mining_tick_gate(PDO $pdo, int $userId, string $wallet): array
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
        'is_profile_complete' => ($nickname !== '' && $email !== '' && $country !== ''),
        'is_ton_bound' => $bound,
        'is_mining_eligible' => ($nickname !== '' && $email !== '' && $country !== '' && $bound),
    ];
}

function mining_tick_fallback_tier(PDO $pdo, int $userId): array
{
    $isVerified = 0;
    try {
        $st = $pdo->prepare("SELECT is_fully_verified FROM users WHERE id = ? LIMIT 1");
        $st->execute([$userId]);
        $isVerified = (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
    }

    if ($isVerified === 1) {
        return ['tier' => 'verified', 'label' => 'Verified Miner', 'multiplier' => 2.0, 'daily_cap_wems' => 300.0, 'node_reward_pct' => 0.0];
    }

    return ['tier' => 'free', 'label' => 'Free Miner', 'multiplier' => 1.0, 'daily_cap_wems' => 100.0, 'node_reward_pct' => 0.0];
}

function mining_tick_resolve(PDO $pdo, int $userId, string $wallet): array
{
    if (function_exists('poado_ensure_miner_profile')) {
        try {
            $user = session_user() ?: [];
            poado_ensure_miner_profile($pdo, $user);
        } catch (Throwable $e) {
        }
    }

    if (function_exists('poado_resolve_tier')) {
        try {
            $resolved = poado_resolve_tier($pdo, $userId, $wallet);
            $tierCode = (string)($resolved['miner_tier'] ?? $resolved['tier'] ?? 'free');
            $mul = (float)($resolved['multiplier'] ?? 1);
            $cap = (float)($resolved['daily_cap_wems'] ?? $resolved['daily_cap'] ?? 100);

            $labelMap = [
                'free' => 'Free Miner',
                'verified' => 'Verified Miner',
                'sub' => 'Sub Miner',
                'core' => 'Core Miner',
                'nodes' => 'Nodes Miner',
                'super_node' => 'Super Node Miner',
            ];

            $nodePct = 0.0;
            if ($tierCode === 'nodes') $nodePct = 0.5;
            if ($tierCode === 'super_node') $nodePct = 3.0;

            return [
                'tier' => $tierCode,
                'label' => $labelMap[$tierCode] ?? ucfirst(str_replace('_', ' ', $tierCode)),
                'multiplier' => $mul,
                'daily_cap_wems' => $cap,
                'node_reward_pct' => $nodePct,
            ];
        } catch (Throwable $e) {
        }
    }

    return mining_tick_fallback_tier($pdo, $userId);
}

function mining_tick_profile_totals(PDO $pdo, int $userId, string $wallet): array
{
    try {
        $st = $pdo->prepare("
            SELECT
                COALESCE(today_mined_wems, 0) AS today_mined_wems,
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
                'total_mined_wems' => $gross,
                'storage_unclaimed_wems' => $unclaimed,
            ];
        }
    } catch (Throwable $e) {
    }

    $daily = 0.0;
    $total = 0.0;

    try {
        $st = $pdo->prepare("
            SELECT COALESCE(SUM(amount),0)
            FROM wems_mining_log
            WHERE user_id = ?
              AND reason = 'mining'
              AND DATE(created_at) = UTC_DATE()
        ");
        $st->execute([$userId]);
        $daily = ((int)($st->fetchColumn() ?: 0)) / 100000000;
    } catch (Throwable $e) {
    }

    try {
        $st = $pdo->prepare("
            SELECT COALESCE(SUM(amount),0)
            FROM wems_mining_log
            WHERE user_id = ?
              AND reason = 'mining'
        ");
        $st->execute([$userId]);
        $total = ((int)($st->fetchColumn() ?: 0)) / 100000000;
    } catch (Throwable $e) {
    }

    return [
        'daily_mined_wems' => $daily,
        'total_mined_wems' => $total,
        'storage_unclaimed_wems' => $total,
    ];
}

function mining_tick_credit_legacy(PDO $pdo, int $userId, float $creditWems): void
{
    $creditInt = (int)round($creditWems * 100000000);
    if ($creditInt <= 0) {
        throw new RuntimeException('ZERO_CREDIT');
    }

    $st = $pdo->prepare("
        INSERT INTO wems_mining_log (user_id, amount, reason, created_at)
        VALUES (?, ?, 'mining', CURRENT_TIMESTAMP())
    ");
    $st->execute([$userId, $creditInt]);
}

function mining_tick_runtime_active(array &$runtime, string $wallet): bool
{
    if ((int)($runtime['is_mining'] ?? 0) === 1) {
        return true;
    }

    $startedAt = (int)($runtime['started_at'] ?? 0);
    $startedWallet = trim((string)($runtime['started_wallet'] ?? ''));
    $stoppedAt = (int)($runtime['stopped_at'] ?? 0);

    if ($startedAt > 0 && $startedWallet !== '' && hash_equals($startedWallet, $wallet) && $stoppedAt === 0) {
        $runtime['is_mining'] = 1;
        if (!isset($runtime['battery_pct'])) {
            $runtime['battery_pct'] = 0;
        }
        return true;
    }

    return false;
}

db_connect();
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    mining_tick_fail('NO_DB', 'DB connection unavailable', 500);
}

$user = session_user();
if (!is_array($user) || empty($user)) {
    mining_tick_fail('NO_SESSION', 'Login required', 401);
}

$userId = (int)($user['id'] ?? 0);
$wallet = mining_tick_wallet($user);
if ($userId <= 0 || $wallet === '') {
    mining_tick_fail('NO_SESSION', 'Login required', 401);
}

$gate = mining_tick_gate($pdo, $userId, $wallet);
if (empty($gate['is_mining_eligible'])) {
    mining_tick_fail('MINING_LOCKED', 'Mining access locked', 403, [
        'profile_complete' => (bool)$gate['is_profile_complete'],
        'ton_bound' => (bool)$gate['is_ton_bound'],
        'mining_eligible' => (bool)$gate['is_mining_eligible'],
    ]);
}

$_SESSION['rwa_mining'] = (array)($_SESSION['rwa_mining'] ?? []);
$runtime = &$_SESSION['rwa_mining'];

if (!mining_tick_runtime_active($runtime, $wallet)) {
    session_write_close();
    mining_tick_fail('MINING_NOT_RUNNING', 'Mining is not running', 400);
}

$tier = mining_tick_resolve($pdo, $userId, $wallet);
$baseRate = 0.33;
$ratePerTick = $baseRate * (float)$tier['multiplier'];

$totalsBefore = mining_tick_profile_totals($pdo, $userId, $wallet);
$remaining = max(0, (float)$tier['daily_cap_wems'] - (float)$totalsBefore['daily_mined_wems']);

if ($remaining <= 0) {
    $runtime['is_mining'] = 0;
    $runtime['battery_pct'] = 0;
    $runtime['stopped_at'] = time();
    session_write_close();

    mining_tick_json([
        'ok' => true,
        'is_mining' => 0,
        'battery_pct' => 0,
        'tier' => $tier['label'],
        'tier_code' => $tier['tier'],
        'tier_status' => 'active',
        'multiplier' => (float)$tier['multiplier'],
        'daily_cap_wems' => (float)$tier['daily_cap_wems'],
        'daily_mined_wems' => round((float)$totalsBefore['daily_mined_wems'], 8),
        'rate_wems_per_tick' => round($ratePerTick, 8),
        'node_reward_pct' => (float)$tier['node_reward_pct'],
        'tick_credit_wems' => 0,
        'total_mined_wems' => round((float)$totalsBefore['total_mined_wems'], 8),
        'storage_unclaimed_wems' => round((float)$totalsBefore['storage_unclaimed_wems'], 8),
        'wallet' => $wallet,
        'message' => 'DAILY_CAP_REACHED',
        'profile_complete' => (bool)$gate['is_profile_complete'],
        'ton_bound' => (bool)$gate['is_ton_bound'],
        'mining_eligible' => (bool)$gate['is_mining_eligible'],
    ]);
}

$creditWems = min($ratePerTick, $remaining);
if ($creditWems <= 0) {
    session_write_close();
    mining_tick_fail('ZERO_TICK_CREDIT', 'Zero tick credit', 400);
}

try {
    $pdo->beginTransaction();

    if (function_exists('poado_mining_tick')) {
        $result = poado_mining_tick($pdo, $userId, $wallet, 10);
        if (is_array($result)) {
            $libCredit = (float)($result['credit'] ?? 0);
            if ($libCredit > 0) {
                $creditWems = $libCredit;
            }
        } elseif (is_numeric($result)) {
            $creditWems = (float)$result;
        }
    } else {
        mining_tick_credit_legacy($pdo, $userId, $creditWems);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    session_write_close();
    mining_tick_fail('TICK_WRITE_FAIL', 'Tick write failed', 500, [
        'detail' => $e->getMessage(),
    ]);
}

$runtime['battery_pct'] = 0;
$runtime['last_tick_request_at'] = time();

$totalsAfter = mining_tick_profile_totals($pdo, $userId, $wallet);
$stillRemaining = max(0, (float)$tier['daily_cap_wems'] - (float)$totalsAfter['daily_mined_wems']);
if ($stillRemaining <= 0) {
    $runtime['is_mining'] = 0;
    $runtime['stopped_at'] = time();
}

$isMiningNow = (int)($runtime['is_mining'] ?? 0);
session_write_close();

mining_tick_json([
    'ok' => true,
    'is_mining' => $isMiningNow,
    'battery_pct' => 0,
    'tier' => $tier['label'],
    'tier_code' => $tier['tier'],
    'tier_status' => 'active',
    'multiplier' => (float)$tier['multiplier'],
    'daily_cap_wems' => (float)$tier['daily_cap_wems'],
    'daily_mined_wems' => round((float)$totalsAfter['daily_mined_wems'], 8),
    'rate_wems_per_tick' => round($ratePerTick, 8),
    'node_reward_pct' => (float)$tier['node_reward_pct'],
    'tick_credit_wems' => round($creditWems, 8),
    'total_mined_wems' => round((float)$totalsAfter['total_mined_wems'], 8),
    'storage_unclaimed_wems' => round((float)$totalsAfter['storage_unclaimed_wems'], 8),
    'wallet' => $wallet,
    'profile_complete' => (bool)$gate['is_profile_complete'],
    'ton_bound' => (bool)$gate['is_ton_bound'],
    'mining_eligible' => (bool)$gate['is_mining_eligible'],
]);
