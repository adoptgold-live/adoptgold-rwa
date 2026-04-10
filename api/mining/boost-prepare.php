<?php
declare(strict_types=1);

/**
 * /rwa/api/mining/boost-prepare.php
 * EXTRA BOOST WITH EMA$ payment prepare
 *
 * Source of available EMA$:
 *   rwa_storage_balances.onchain_ema
 *
 * Flow:
 *   GET  -> preview current boost data
 *   POST -> create payment request + deeplink + QR
 *
 * No auto-confirm here.
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
if (is_file($_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/qr.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/qr.php';
}
if (is_file($_SERVER['DOCUMENT_ROOT'].'/dashboard/inc/qr.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'].'/dashboard/inc/qr.php';
}

while (ob_get_level() > 0) { @ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');

function bp_json(array $a, int $code = 200): never {
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function bp_fail(string $error, string $message, int $code = 400, array $extra = []): never {
    bp_json(array_merge(['ok'=>false,'error'=>$error,'message'=>$message], $extra), $code);
}
function bp_wallet(array $user): string {
    return trim((string)($user['wallet_address'] ?? $user['wallet'] ?? ''));
}
function bp_input(): array {
    $raw = file_get_contents('php://input');
    $json = [];
    if (is_string($raw) && trim($raw) !== '') {
        $tmp = json_decode($raw, true);
        if (is_array($tmp)) $json = $tmp;
    }
    return array_merge($_GET ?: [], $_POST ?: [], $json);
}
function bp_uuid(): string {
    return 'BST-' . gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(6)), 0, 12);
}
function bp_env(string $key, string $default = ''): string {
    $v = getenv($key);
    if ($v !== false && $v !== '') return (string)$v;
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') return (string)$_ENV[$key];
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return (string)$_SERVER[$key];
    return $default;
}
function bp_tier_min(string $tier): float {
    $map = ['free'=>0.0,'verified'=>0.0,'sub'=>100.0,'core'=>1000.0,'nodes'=>5000.0,'super_node'=>100000.0];
    return (float)($map[$tier] ?? 0.0);
}
function bp_label(string $tier): string {
    $map = ['free'=>'Free Miner','verified'=>'Verified Miner','sub'=>'Sub Miner','core'=>'Core Miner','nodes'=>'Nodes Miner','super_node'=>'Super Node Miner'];
    return (string)($map[$tier] ?? ucfirst(str_replace('_', ' ', $tier)));
}
function bp_gate(PDO $pdo, int $userId, string $wallet): array {
    if (function_exists('poado_fetch_user_profile_state')) {
        try { return poado_fetch_user_profile_state($pdo, $userId, $wallet); } catch (Throwable $e) {}
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
    $email = trim((string)($row['email'] ?? ''));
    $country = trim((string)($row['country_name'] ?? $row['country'] ?? ''));
    $bound = trim((string)($row['wallet_address'] ?? '')) !== '';

    return [
        'is_profile_complete' => ($nickname !== '' && $email !== '' && $country !== ''),
        'is_ton_bound' => $bound,
        'is_mining_eligible' => ($nickname !== '' && $email !== '' && $country !== '' && $bound),
    ];
}
function bp_storage_ema(PDO $pdo, int $userId): float {
    try {
        $st = $pdo->prepare("SELECT COALESCE(onchain_ema,0) FROM rwa_storage_balances WHERE user_id = ? LIMIT 1");
        $st->execute([$userId]);
        return max(0.0, (float)($st->fetchColumn() ?: 0));
    } catch (Throwable $e) {
        return 0.0;
    }
}
function bp_resolve(PDO $pdo, int $userId, string $wallet, array $user): array {
    if (function_exists('poado_ensure_miner_profile')) {
        try { poado_ensure_miner_profile($pdo, $user); } catch (Throwable $e) {}
    }

    $tierCode = 'free';
    $baseMultiplier = 1.0;
    $baseDailyCap = 100.0;
    $tierStatus = 'active';

    if (function_exists('poado_resolve_tier')) {
        try {
            $resolved = poado_resolve_tier($pdo, $userId, $wallet);
            $tierCode = (string)($resolved['miner_tier'] ?? $resolved['tier'] ?? 'free');
            $baseMultiplier = (float)($resolved['multiplier'] ?? 1);
            $baseDailyCap = (float)($resolved['daily_cap_wems'] ?? $resolved['daily_cap'] ?? 100);
            $tierStatus = (string)($resolved['tier_status'] ?? 'active');
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
        'tier_label' => bp_label($tierCode),
        'base_multiplier' => $baseMultiplier,
        'base_daily_cap_wems' => $baseDailyCap,
        'available_onchain_ema' => bp_storage_ema($pdo, $userId),
        'tier_status' => $tierStatus,
        'ema_source' => 'rwa_storage_balances.onchain_ema',
    ];
}
function bp_profile(PDO $pdo, int $userId, string $wallet): array {
    try {
        $st = $pdo->prepare("
            SELECT
                COALESCE(boost_selected_ema,0) AS boost_selected_ema,
                COALESCE(boost_extra_steps,0) AS boost_extra_steps,
                COALESCE(boost_multiplier_add,0) AS boost_multiplier_add,
                COALESCE(boost_daily_cap_add,0) AS boost_daily_cap_add,
                COALESCE(boost_status,'idle') AS boost_status,
                boost_verified_at,
                boost_ref
            FROM poado_miner_profiles
            WHERE user_id = ? AND wallet = ?
            LIMIT 1
        ");
        $st->execute([$userId, $wallet]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) return $row;
    } catch (Throwable $e) {}
    return [
        'boost_selected_ema'=>0,
        'boost_extra_steps'=>0,
        'boost_multiplier_add'=>0,
        'boost_daily_cap_add'=>0,
        'boost_status'=>'idle',
        'boost_verified_at'=>null,
        'boost_ref'=>null,
    ];
}
function bp_qr_data_uri(string $text): string {
    if (function_exists('poado_qr_svg_data_uri')) {
        try { return (string)poado_qr_svg_data_uri($text); } catch (Throwable $e) {}
    }
    return '';
}
function bp_build_tx_url(string $treasury, string $amountEma, string $ref, string $emaMaster): string {
    $base = 'https://adoptgold.app/tx.html';
    $qs = http_build_query([
        'module' => 'mining_boost',
        'token'  => 'EMA',
        'to'     => $treasury,
        'amount' => $amountEma,
        'ref'    => $ref,
        'master' => $emaMaster,
    ]);
    return $base . '?' . $qs;
}
function bp_build_ui(PDO $pdo, int $userId, string $wallet, array $user): array {
    $gate = bp_gate($pdo, $userId, $wallet);
    $resolved = bp_resolve($pdo, $userId, $wallet, $user);
    $profile = bp_profile($pdo, $userId, $wallet);

    $tierCode = (string)$resolved['tier_code'];
    $baseMultiplier = (float)$resolved['base_multiplier'];
    $baseDailyCap = (float)$resolved['base_daily_cap_wems'];
    $available = max(0.0, (float)$resolved['available_onchain_ema']);
    $tierMin = bp_tier_min($tierCode);
    $boostable = max(0.0, $available - $tierMin);

    $activeSelected = min($boostable, max(0.0, (float)($profile['boost_selected_ema'] ?? 0)));
    $activeSteps = (int)floor($activeSelected / 1000);
    $activeMultAdd = $activeSteps * 1.0;
    $activeCapAdd = $activeSteps * 100.0;

    return [
        'label' => 'EXTRA BOOST WITH EMA$',
        'source' => 'onchain_ema_only',
        'ema_source' => (string)$resolved['ema_source'],
        'payment_mode' => 'popup_qr_deeplink',
        'profile_complete' => (bool)$gate['is_profile_complete'],
        'ton_bound' => (bool)$gate['is_ton_bound'],
        'mining_eligible' => (bool)$gate['is_mining_eligible'],
        'tier_code' => $tierCode,
        'tier_label' => (string)$resolved['tier_label'],
        'tier_status' => (string)$resolved['tier_status'],
        'available_onchain_ema' => round($available, 8),
        'tier_min_ema' => round($tierMin, 8),
        'boostable_ema' => round($boostable, 8),
        'active_selected_ema' => round($activeSelected, 8),
        'active_extra_steps' => $activeSteps,
        'active_multiplier_add' => round($activeMultAdd, 8),
        'active_daily_cap_add' => round($activeCapAdd, 8),
        'base_multiplier' => round($baseMultiplier, 8),
        'base_daily_cap_wems' => round($baseDailyCap, 8),
        'effective_multiplier' => round($baseMultiplier + $activeMultAdd, 8),
        'effective_daily_cap_wems' => round($baseDailyCap + $activeCapAdd, 8),
        'boost_status' => (string)($profile['boost_status'] ?? 'idle'),
        'boost_ref' => $profile['boost_ref'] ?? null,
        'boost_verified_at' => $profile['boost_verified_at'] ?? null,
    ];
}

db_connect();
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) bp_fail('NO_DB', 'DB connection unavailable', 500);

$user = session_user();
if (!is_array($user) || empty($user)) bp_fail('NO_SESSION', 'Login required', 401);

$userId = (int)($user['id'] ?? 0);
$wallet = bp_wallet($user);
if ($userId <= 0 || $wallet === '') bp_fail('NO_SESSION', 'Login required', 401);

$gate = bp_gate($pdo, $userId, $wallet);
if (empty($gate['is_mining_eligible'])) bp_fail('MINING_LOCKED', 'Mining access locked', 403, $gate);

$input = bp_input();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    bp_json(['ok' => true, 'boost_ui' => bp_build_ui($pdo, $userId, $wallet, $user)]);
}

$selected = (float)($input['selected_ema'] ?? 0);
$ui = bp_build_ui($pdo, $userId, $wallet, $user);
$boostable = (float)($ui['boostable_ema'] ?? 0);

if ($boostable <= 0) {
    bp_fail('NO_BOOSTABLE_EMA', 'No boostable on-chain EMA$ available', 400, ['boost_ui' => $ui]);
}

$selected = max(0.0, min($selected, $boostable));
$steps = (int)floor($selected / 1000);

if ($steps <= 0) {
    bp_fail('BOOST_TOO_SMALL', 'Selected EMA$ is below one 1000 EMA$ boost step', 400, [
        'selected_ema' => $selected,
        'boost_ui' => $ui,
    ]);
}

$multAdd = $steps * 1.0;
$capAdd = $steps * 100.0;
$requestRef = bp_uuid();
$paymentRef = $requestRef;

$treasury = bp_env('TON_TREASURY_ADDRESS', '');
$emaMaster = bp_env('EMA_MASTER', '');
if ($treasury === '' || $emaMaster === '') {
    bp_fail('ENV_MISSING', 'TON_TREASURY_ADDRESS or EMA_MASTER missing', 500);
}

$expectedAmountEma = round($selected, 8);
$expectedAmountUnits = (string)(int)round($expectedAmountEma * 1000000000);
$deeplink = bp_build_tx_url($treasury, number_format($expectedAmountEma, 8, '.', ''), $paymentRef, $emaMaster);
$qrData = bp_qr_data_uri($deeplink);

try {
    $st = $pdo->prepare("
        INSERT INTO poado_mining_boost_requests
        (
            request_ref, user_id, wallet, tier_code, tier_label,
            available_onchain_ema, tier_min_ema, boostable_ema,
            selected_ema, extra_steps, multiplier_add, daily_cap_add,
            expected_token, treasury_address, ema_master, payment_ref,
            expected_amount_ema, expected_amount_units, payment_status,
            deeplink_url, qr_data_uri, status, created_at, updated_at, expires_at
        )
        VALUES
        (
            ?, ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?,
            'EMA', ?, ?, ?,
            ?, ?, 'payment_pending',
            ?, ?, 'payment_pending', UTC_TIMESTAMP(), UTC_TIMESTAMP(),
            DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)
        )
    ");
    $st->execute([
        $requestRef,
        $userId,
        $wallet,
        (string)$ui['tier_code'],
        (string)$ui['tier_label'],
        (float)$ui['available_onchain_ema'],
        (float)$ui['tier_min_ema'],
        (float)$ui['boostable_ema'],
        $expectedAmountEma,
        $steps,
        $multAdd,
        $capAdd,
        $treasury,
        $emaMaster,
        $paymentRef,
        $expectedAmountEma,
        $expectedAmountUnits,
        $deeplink,
        $qrData
    ]);
} catch (Throwable $e) {
    bp_fail('PREPARE_WRITE_FAIL', 'Failed to create boost payment request', 500, ['detail' => $e->getMessage()]);
}

bp_json([
    'ok' => true,
    'request_ref' => $requestRef,
    'payment_ref' => $paymentRef,
    'status' => 'payment_pending',
    'payment_status' => 'payment_pending',
    'selected_ema' => round($expectedAmountEma, 8),
    'extra_steps' => $steps,
    'multiplier_add' => round($multAdd, 8),
    'daily_cap_add' => round($capAdd, 8),
    'treasury_address' => $treasury,
    'ema_master' => $emaMaster,
    'deeplink_url' => $deeplink,
    'qr_data_uri' => $qrData,
    'expires_in_seconds' => 1800,
    'boost_ui' => $ui,
]);
