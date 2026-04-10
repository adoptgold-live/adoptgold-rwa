<?php
declare(strict_types=1);

/**
 * /rwa/api/mining/heartbeat.php
 * Safe runtime heartbeat endpoint.
 *
 * FIXES:
 * - no mining credit here
 * - no fatal dependency on anomaly functions
 * - always returns JSON
 * - keeps runtime alive without breaking tick flow
 */

ini_set('display_errors', '0');
error_reporting(0);

require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/session-user.php';

if (is_file($_SERVER['DOCUMENT_ROOT'].'/rwa/inc/mining-config.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/mining-config.php';
}
if (is_file($_SERVER['DOCUMENT_ROOT'].'/rwa/inc/mining-lib.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/mining-lib.php';
}
if (is_file($_SERVER['DOCUMENT_ROOT'].'/rwa/inc/mining-guards.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/mining-guards.php';
}
if (is_file($_SERVER['DOCUMENT_ROOT'].'/rwa/inc/mining-anomaly-lib.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/mining-anomaly-lib.php';
}

while (ob_get_level() > 0) { @ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');

function hb_out(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function hb_fail(string $msg, int $code=400, array $extra = []): never {
    hb_out(array_merge(['ok'=>false,'message'=>$msg], $extra), $code);
}

function hb_wallet(array $user): string
{
    return trim((string)($user['wallet_address'] ?? $user['wallet'] ?? ''));
}

function hb_fallback_tier(PDO $pdo, int $userId): array
{
    $isVerified = 0;
    try {
        $st = $pdo->prepare("SELECT is_fully_verified FROM users WHERE id = ? LIMIT 1");
        $st->execute([$userId]);
        $isVerified = (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
    }

    if ($isVerified === 1) {
        return ['tier'=>'verified','multiplier'=>2.0,'daily_cap_wems'=>300.0];
    }
    return ['tier'=>'free','multiplier'=>1.0,'daily_cap_wems'=>100.0];
}

function hb_resolve_tier(PDO $pdo, int $userId, string $wallet, array $user): array
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
            return [
                'tier' => (string)($resolved['miner_tier'] ?? $resolved['tier'] ?? 'free'),
                'multiplier' => (float)($resolved['multiplier'] ?? 1),
                'daily_cap_wems' => (float)($resolved['daily_cap_wems'] ?? $resolved['daily_cap'] ?? 100),
            ];
        } catch (Throwable $e) {
        }
    }

    return hb_fallback_tier($pdo, $userId);
}

function hb_totals(PDO $pdo, int $userId, string $wallet): array
{
    try {
        $st = $pdo->prepare("
            SELECT
                COALESCE(today_mined_wems, 0) AS today_mined_wems,
                COALESCE(daily_cap_wems, 0) AS daily_cap_wems
            FROM poado_miner_profiles
            WHERE user_id = ? AND wallet = ?
            LIMIT 1
        ");
        $st->execute([$userId, $wallet]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return [
                'today' => (float)$row['today_mined_wems'],
                'cap' => (float)$row['daily_cap_wems'],
            ];
        }
    } catch (Throwable $e) {
    }

    return ['today' => 0.0, 'cap' => 0.0];
}

function hb_runtime_active(array &$runtime, string $wallet): bool
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
if (!$pdo instanceof PDO) hb_fail('DB_FAIL',500);

$user = session_user();
if (!is_array($user)) hb_fail('NO_SESSION',401);

$userId = (int)($user['id'] ?? 0);
$wallet = hb_wallet($user);

if ($userId <= 0 || $wallet === '') {
    hb_fail('INVALID_USER',403);
}

$_SESSION['rwa_mining'] = (array)($_SESSION['rwa_mining'] ?? []);
$runtime = &$_SESSION['rwa_mining'];

$isMining = hb_runtime_active($runtime, $wallet) ? 1 : 0;
$runtime['last_heartbeat_at'] = time();

$tier = hb_resolve_tier($pdo, $userId, $wallet, $user);
$totals = hb_totals($pdo, $userId, $wallet);

$remaining = max(0.0, (float)$totals['cap'] - (float)$totals['today']);
$flags = [];

if (function_exists('poado_check_anomaly')) {
    try {
        $flags = poado_check_anomaly(
            $pdo,
            $userId,
            $wallet,
            0,
            0.0,
            (float)$tier['multiplier']
        );
        if (is_array($flags) && function_exists('poado_log_anomaly')) {
            foreach ($flags as $flag) {
                try {
                    poado_log_anomaly($pdo, $userId, $wallet, $flag, [
                        'source' => 'heartbeat',
                        'tier' => $tier['tier'] ?? 'free',
                    ]);
                } catch (Throwable $e) {
                }
            }
        }
    } catch (Throwable $e) {
        $flags = ['anomaly_check_skipped'];
    }
}

session_write_close();

hb_out([
    'ok' => true,
    'is_mining' => $isMining,
    'battery_pct' => (float)($runtime['battery_pct'] ?? 0),
    'tier' => (string)($tier['tier'] ?? 'free'),
    'multiplier' => (float)($tier['multiplier'] ?? 1),
    'today' => (float)$totals['today'],
    'remaining_cap' => $remaining,
    'wallet' => $wallet,
    'anomaly_flags' => is_array($flags) ? array_values($flags) : [],
    'ts' => date('c')
]);
