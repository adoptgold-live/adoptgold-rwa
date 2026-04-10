<?php
declare(strict_types=1);

/**
 * /rwa/api/mining/boost-verify.php
 * EXTRA BOOST WITH EMA$ payment verify
 *
 * Preferred:
 *   /rwa/inc/core/onchain-verify.php helper
 *
 * Fallback:
 *   toncenter v3 treasury-side scan with exact amount + ref matching
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
if (is_file($_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/onchain-verify.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/onchain-verify.php';
}

while (ob_get_level() > 0) { @ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');

function bv_json(array $a, int $code = 200): never {
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function bv_fail(string $error, string $message, int $code = 400, array $extra = []): never {
    bv_json(array_merge(['ok'=>false,'error'=>$error,'message'=>$message], $extra), $code);
}
function bv_wallet(array $user): string {
    return trim((string)($user['wallet_address'] ?? $user['wallet'] ?? ''));
}
function bv_input(): array {
    $raw = file_get_contents('php://input');
    $json = [];
    if (is_string($raw) && trim($raw) !== '') {
        $tmp = json_decode($raw, true);
        if (is_array($tmp)) $json = $tmp;
    }
    return array_merge($_GET ?: [], $_POST ?: [], $json);
}
function bv_env(string $key, string $default = ''): string {
    $v = getenv($key);
    if ($v !== false && $v !== '') return (string)$v;
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') return (string)$_ENV[$key];
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return (string)$_SERVER[$key];
    return $default;
}
function bv_tier_min(string $tier): float {
    $map = ['free'=>0.0,'verified'=>0.0,'sub'=>100.0,'core'=>1000.0,'nodes'=>5000.0,'super_node'=>100000.0];
    return (float)($map[$tier] ?? 0.0);
}
function bv_label(string $tier): string {
    $map = ['free'=>'Free Miner','verified'=>'Verified Miner','sub'=>'Sub Miner','core'=>'Core Miner','nodes'=>'Nodes Miner','super_node'=>'Super Node Miner'];
    return (string)($map[$tier] ?? ucfirst(str_replace('_', ' ', $tier)));
}
function bv_storage_ema(PDO $pdo, int $userId): float {
    try {
        $st = $pdo->prepare("SELECT COALESCE(onchain_ema,0) FROM rwa_storage_balances WHERE user_id = ? LIMIT 1");
        $st->execute([$userId]);
        return max(0.0, (float)($st->fetchColumn() ?: 0));
    } catch (Throwable $e) {
        return 0.0;
    }
}
function bv_resolve(PDO $pdo, int $userId, string $wallet, array $user): array {
    if (function_exists('poado_ensure_miner_profile')) {
        try { poado_ensure_miner_profile($pdo, $user); } catch (Throwable $e) {}
    }

    $tierCode = 'free';
    $baseMultiplier = 1.0;
    $baseDailyCap = 100.0;

    if (function_exists('poado_resolve_tier')) {
        try {
            $resolved = poado_resolve_tier($pdo, $userId, $wallet);
            $tierCode = (string)($resolved['miner_tier'] ?? $resolved['tier'] ?? 'free');
            $baseMultiplier = (float)($resolved['multiplier'] ?? 1);
            $baseDailyCap = (float)($resolved['daily_cap_wems'] ?? $resolved['daily_cap'] ?? 100);
        } catch (Throwable $e) {}
    } else {
        try {
            $st = $pdo->prepare("SELECT is_fully_verified FROM users WHERE id = ? LIMIT 1");
            $st->execute([$userId]);
            if ((int)($st->fetchColumn() ?: 0) === 1) {
                $tierCode = 'verified';
                $baseMultiplier = 2.0;
                $baseDailyCap = 300.0;
            }
        } catch (Throwable $e) {}
    }

    return [
        'tier_code' => $tierCode,
        'tier_label' => bv_label($tierCode),
        'base_multiplier' => $baseMultiplier,
        'base_daily_cap_wems' => $baseDailyCap,
        'available_onchain_ema' => bv_storage_ema($pdo, $userId),
        'ema_source' => 'rwa_storage_balances.onchain_ema',
    ];
}
function bv_profile_ui(PDO $pdo, int $userId, string $wallet, array $resolved): array {
    $tierCode = (string)$resolved['tier_code'];
    $tierMin = bv_tier_min($tierCode);
    $available = max(0.0, (float)$resolved['available_onchain_ema']);
    $boostable = max(0.0, $available - $tierMin);

    $selected = 0.0;
    $steps = 0;
    $multAdd = 0.0;
    $capAdd = 0.0;
    $status = 'idle';
    $ref = null;
    $verifiedAt = null;

    try {
        $st = $pdo->prepare("
            SELECT
              COALESCE(boost_selected_ema,0) AS boost_selected_ema,
              COALESCE(boost_extra_steps,0) AS boost_extra_steps,
              COALESCE(boost_multiplier_add,0) AS boost_multiplier_add,
              COALESCE(boost_daily_cap_add,0) AS boost_daily_cap_add,
              COALESCE(boost_status,'idle') AS boost_status,
              boost_ref, boost_verified_at
            FROM poado_miner_profiles
            WHERE user_id = ? AND wallet = ?
            LIMIT 1
        ");
        $st->execute([$userId, $wallet]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $selected = min($boostable, max(0.0, (float)($row['boost_selected_ema'] ?? 0)));
            $steps = (int)($row['boost_extra_steps'] ?? floor($selected / 1000));
            $multAdd = (float)($row['boost_multiplier_add'] ?? ($steps * 1.0));
            $capAdd = (float)($row['boost_daily_cap_add'] ?? ($steps * 100.0));
            $status = (string)($row['boost_status'] ?? 'idle');
            $ref = $row['boost_ref'] ?? null;
            $verifiedAt = $row['boost_verified_at'] ?? null;
        }
    } catch (Throwable $e) {}

    return [
        'label' => 'EXTRA BOOST WITH EMA$',
        'source' => 'onchain_ema_only',
        'ema_source' => (string)($resolved['ema_source'] ?? 'unknown'),
        'payment_mode' => 'popup_qr_deeplink',
        'tier_code' => $tierCode,
        'tier_label' => (string)$resolved['tier_label'],
        'available_onchain_ema' => round($available, 8),
        'tier_min_ema' => round($tierMin, 8),
        'boostable_ema' => round($boostable, 8),
        'active_selected_ema' => round($selected, 8),
        'active_extra_steps' => $steps,
        'active_multiplier_add' => round($multAdd, 8),
        'active_daily_cap_add' => round($capAdd, 8),
        'base_multiplier' => round((float)$resolved['base_multiplier'], 8),
        'base_daily_cap_wems' => round((float)$resolved['base_daily_cap_wems'], 8),
        'effective_multiplier' => round((float)$resolved['base_multiplier'] + $multAdd, 8),
        'effective_daily_cap_wems' => round((float)$resolved['base_daily_cap_wems'] + $capAdd, 8),
        'boost_status' => $status,
        'boost_ref' => $ref,
        'boost_verified_at' => $verifiedAt,
    ];
}
function bv_http_json(string $url, array $headers = []): ?array {
    $ch = curl_init($url);
    if ($ch === false) return null;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($body) || $body === '' || $code < 200 || $code >= 300) return null;
    $json = json_decode($body, true);
    return is_array($json) ? $json : null;
}
function bv_match_ref_in_value($value, string $ref): bool {
    if (is_string($value) && stripos($value, $ref) !== false) return true;
    if (is_array($value)) {
        foreach ($value as $v) {
            if (bv_match_ref_in_value($v, $ref)) return true;
        }
    }
    return false;
}
function bv_toncenter_fallback_verify(array $req): array {
    $base = rtrim(bv_env('TONCENTER_BASE', 'https://toncenter.com/api/v3'), '/');
    $apiKey = bv_env('TONCENTER_API_KEY', '');
    $treasury = trim((string)($req['treasury_address'] ?? ''));
    $expectedUnits = trim((string)($req['expected_amount_units'] ?? ''));
    $ref = trim((string)($req['payment_ref'] ?? $req['request_ref'] ?? ''));

    if ($treasury === '' || $expectedUnits === '' || $ref === '') {
        return ['ok'=>false,'reason'=>'VERIFY_CONTEXT_MISSING'];
    }

    $headers = [];
    if ($apiKey !== '') $headers[] = 'X-API-Key: ' . $apiKey;

    $url = $base . '/transactions?account=' . rawurlencode($treasury) . '&limit=30&sort=desc';
    $json = bv_http_json($url, $headers);
    if (!is_array($json)) {
        return ['ok'=>false,'reason'=>'TONCENTER_HTTP_FAIL'];
    }

    $rows = [];
    foreach (['transactions', 'result', 'data'] as $k) {
        if (isset($json[$k]) && is_array($json[$k])) {
            $rows = $json[$k];
            break;
        }
    }
    if (!$rows && isset($json[0]) && is_array($json[0])) $rows = $json;

    foreach ($rows as $row) {
        if (!is_array($row)) continue;

        $amountCandidates = [
            (string)($row['amount'] ?? ''),
            (string)($row['value'] ?? ''),
            (string)($row['in_msg']['value'] ?? ''),
            (string)($row['in_msg']['amount'] ?? ''),
        ];
        $amountMatch = false;
        foreach ($amountCandidates as $cand) {
            if ($cand !== '' && $cand === $expectedUnits) {
                $amountMatch = true;
                break;
            }
        }
        if (!$amountMatch) continue;

        if (!bv_match_ref_in_value($row, $ref)) continue;

        return [
            'ok' => true,
            'tx_hash' => (string)($row['hash'] ?? $row['tx_hash'] ?? ''),
            'confirmations' => (int)($row['confirmations'] ?? 1),
            'verify_source' => 'toncenter_v3_fallback',
        ];
    }

    return ['ok'=>false,'reason'=>'PAYMENT_NOT_FOUND'];
}

db_connect();
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) bv_fail('NO_DB', 'DB connection unavailable', 500);

$user = session_user();
if (!is_array($user) || empty($user)) bv_fail('NO_SESSION', 'Login required', 401);

$userId = (int)($user['id'] ?? 0);
$wallet = bv_wallet($user);
if ($userId <= 0 || $wallet === '') bv_fail('NO_SESSION', 'Login required', 401);

$input = bv_input();
$requestRef = trim((string)($input['request_ref'] ?? ''));
if ($requestRef === '') bv_fail('NO_REQUEST_REF', 'Missing request_ref', 400);

try {
    $st = $pdo->prepare("
        SELECT *
        FROM poado_mining_boost_requests
        WHERE request_ref = ?
          AND user_id = ?
          AND wallet = ?
        LIMIT 1
    ");
    $st->execute([$requestRef, $userId, $wallet]);
    $req = $st->fetch(PDO::FETCH_ASSOC);
    if (!$req) bv_fail('REQUEST_NOT_FOUND', 'Boost request not found', 404);
} catch (Throwable $e) {
    bv_fail('REQUEST_READ_FAIL', 'Failed to read boost request', 500, ['detail'=>$e->getMessage()]);
}

$status = (string)($req['status'] ?? '');
if ($status === 'confirmed') {
    $resolved = bv_resolve($pdo, $userId, $wallet, $user);
    bv_json([
        'ok' => true,
        'status' => 'confirmed',
        'request_ref' => $requestRef,
        'boost_ui' => bv_profile_ui($pdo, $userId, $wallet, $resolved),
    ]);
}
if (!in_array($status, ['payment_pending','prepared'], true)) {
    bv_fail('INVALID_REQUEST_STATUS', 'Request is not pending payment', 400, ['request_status'=>$status]);
}

$resolved = bv_resolve($pdo, $userId, $wallet, $user);
$tierCode = (string)$resolved['tier_code'];
$tierMin = bv_tier_min($tierCode);
$available = max(0.0, (float)$resolved['available_onchain_ema']);
$boostable = max(0.0, $available - $tierMin);

$selected = max(0.0, min((float)($req['selected_ema'] ?? 0), $boostable));
$steps = (int)floor($selected / 1000);

if ($steps <= 0) {
    try {
        $st = $pdo->prepare("
            UPDATE poado_mining_boost_requests
            SET status='rejected',
                payment_status='rejected',
                verify_message='Selected EMA$ no longer valid at verify time',
                updated_at=UTC_TIMESTAMP()
            WHERE request_ref = ?
        ");
        $st->execute([$requestRef]);
    } catch (Throwable $e) {}
    bv_fail('BOOST_VERIFY_REJECTED', 'Selected EMA$ no longer valid at verify time', 400, [
        'available_onchain_ema' => round($available, 8),
        'tier_min_ema' => round($tierMin, 8),
        'boostable_ema' => round($boostable, 8),
    ]);
}

$verify = ['ok'=>false,'reason'=>'NO_VERIFIER'];

if (function_exists('poado_verify_onchain_payment')) {
    try {
        $v = poado_verify_onchain_payment([
            'token' => 'EMA',
            'amount_units' => (string)($req['expected_amount_units'] ?? ''),
            'amount_decimal' => (float)($req['expected_amount_ema'] ?? 0),
            'ref' => (string)($req['payment_ref'] ?? $requestRef),
            'wallet' => $wallet,
            'destination' => (string)($req['treasury_address'] ?? ''),
            'master' => (string)($req['ema_master'] ?? bv_env('EMA_MASTER', '')),
        ]);
        if (is_array($v)) {
            $verify = [
                'ok' => (bool)($v['ok'] ?? false),
                'tx_hash' => (string)($v['tx_hash'] ?? ''),
                'confirmations' => (int)($v['confirmations'] ?? 0),
                'verify_source' => (string)($v['verify_source'] ?? 'onchain_helper'),
                'reason' => (string)($v['reason'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        $verify = ['ok'=>false,'reason'=>'HELPER_VERIFY_EXCEPTION'];
    }
}

if (empty($verify['ok'])) {
    $verify = bv_toncenter_fallback_verify($req);
}

if (empty($verify['ok'])) {
    try {
        $st = $pdo->prepare("
            UPDATE poado_mining_boost_requests
            SET payment_status='payment_pending',
                status='payment_pending',
                verify_message=?,
                updated_at=UTC_TIMESTAMP()
            WHERE request_ref = ?
        ");
        $st->execute([(string)($verify['reason'] ?? 'PAYMENT_NOT_FOUND'), $requestRef]);
    } catch (Throwable $e) {}
    bv_fail('PAYMENT_NOT_CONFIRMED', 'EMA$ payment not confirmed yet', 400, [
        'request_ref' => $requestRef,
        'verify_reason' => (string)($verify['reason'] ?? 'PAYMENT_NOT_FOUND'),
    ]);
}

$multAdd = $steps * 1.0;
$capAdd  = $steps * 100.0;
$effectiveMultiplier = (float)$resolved['base_multiplier'] + $multAdd;
$effectiveDailyCap = (float)$resolved['base_daily_cap_wems'] + $capAdd;

try {
    $pdo->beginTransaction();

    $st = $pdo->prepare("
        UPDATE poado_miner_profiles
        SET
            boost_selected_ema   = ?,
            boost_extra_steps    = ?,
            boost_multiplier_add = ?,
            boost_daily_cap_add  = ?,
            boost_status         = 'confirmed',
            boost_verified_at    = UTC_TIMESTAMP(),
            boost_ref            = ?,
            multiplier           = ?,
            daily_cap_wems       = ?,
            updated_at           = UTC_TIMESTAMP()
        WHERE user_id = ?
          AND wallet = ?
        LIMIT 1
    ");
    $st->execute([
        $selected,
        $steps,
        $multAdd,
        $capAdd,
        $requestRef,
        $effectiveMultiplier,
        $effectiveDailyCap,
        $userId,
        $wallet
    ]);

    $st = $pdo->prepare("
        UPDATE poado_mining_boost_requests
        SET
            status='confirmed',
            payment_status='paid',
            selected_ema=?,
            extra_steps=?,
            multiplier_add=?,
            daily_cap_add=?,
            verified_available_onchain_ema=?,
            tx_hash=?,
            verify_source=?,
            confirmations=?,
            verify_message='PAYMENT_CONFIRMED',
            paid_at=UTC_TIMESTAMP(),
            confirmed_at=UTC_TIMESTAMP(),
            updated_at=UTC_TIMESTAMP()
        WHERE request_ref = ?
        LIMIT 1
    ");
    $st->execute([
        $selected,
        $steps,
        $multAdd,
        $capAdd,
        $available,
        (string)($verify['tx_hash'] ?? ''),
        (string)($verify['verify_source'] ?? 'unknown'),
        (int)($verify['confirmations'] ?? 0),
        $requestRef
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    bv_fail('BOOST_APPLY_FAIL', 'Failed to apply paid EXTRA BOOST WITH EMA$', 500, ['detail'=>$e->getMessage()]);
}

$_SESSION['rwa_mining_boost'] = [
    'request_ref' => $requestRef,
    'selected_ema' => $selected,
    'extra_steps' => $steps,
    'multiplier_add' => $multAdd,
    'daily_cap_add' => $capAdd,
    'tx_hash' => (string)($verify['tx_hash'] ?? ''),
    'confirmed_at' => gmdate('c'),
];
session_write_close();

$resolved = [
    'tier_code' => $tierCode,
    'tier_label' => bv_label($tierCode),
    'base_multiplier' => (float)$resolved['base_multiplier'],
    'base_daily_cap_wems' => (float)$resolved['base_daily_cap_wems'],
    'available_onchain_ema' => $available,
    'ema_source' => 'rwa_storage_balances.onchain_ema',
];

bv_json([
    'ok' => true,
    'status' => 'confirmed',
    'payment_status' => 'paid',
    'request_ref' => $requestRef,
    'tx_hash' => (string)($verify['tx_hash'] ?? ''),
    'confirmations' => (int)($verify['confirmations'] ?? 0),
    'selected_ema' => round($selected, 8),
    'extra_steps' => $steps,
    'multiplier_add' => round($multAdd, 8),
    'daily_cap_add' => round($capAdd, 8),
    'effective_multiplier' => round($effectiveMultiplier, 8),
    'effective_daily_cap_wems' => round($effectiveDailyCap, 8),
    'boost_ui' => bv_profile_ui($pdo, $userId, $wallet, $resolved),
]);
