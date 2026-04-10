<?php
declare(strict_types=1);

/**
 * /rwa/api/mining/binding-summary.php
 * Summary API for:
 * - mining page Binding Your Miner panel
 * - My Miner Binding Dashboard top summary / control blocks
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
if (is_file($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/qr.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/qr.php';
}
if (is_file($_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/qr.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/qr.php';
}

while (ob_get_level() > 0) { @ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');

function bs_json(array $a, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bs_fail(string $error, string $message, int $code = 400, array $extra = []): never
{
    bs_json(array_merge([
        'ok' => false,
        'error' => $error,
        'message' => $message,
    ], $extra), $code);
}

function bs_wallet(array $user): string
{
    return trim((string)($user['wallet_address'] ?? $user['wallet'] ?? ''));
}

function bs_tier_label(string $tier): string
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

function bs_binding_cap_for_tier(string $tier): int
{
    $map = [
        'free'       => 0,
        'verified'   => 10,
        'sub'        => 100,
        'core'       => 300,
        'nodes'      => 1000,
        'super_node' => 3000,
    ];
    return (int)($map[$tier] ?? 0);
}

function bs_resolve_tier(PDO $pdo, int $userId, string $wallet, array $user): array
{
    if (function_exists('poado_ensure_miner_profile')) {
        try { poado_ensure_miner_profile($pdo, $user); } catch (Throwable $e) {}
    }

    $tierCode = 'free';
    $tierLabel = 'Free Miner';

    if (function_exists('poado_resolve_tier')) {
        try {
            $resolved = poado_resolve_tier($pdo, $userId, $wallet);
            $tierCode = (string)($resolved['miner_tier'] ?? $resolved['tier'] ?? 'free');
            $tierLabel = bs_tier_label($tierCode);
            return [
                'tier_code' => $tierCode,
                'tier_label' => $tierLabel,
            ];
        } catch (Throwable $e) {}
    }

    try {
        $st = $pdo->prepare("SELECT is_fully_verified FROM users WHERE id = ? LIMIT 1");
        $st->execute([$userId]);
        if ((int)($st->fetchColumn() ?: 0) === 1) {
            $tierCode = 'verified';
            $tierLabel = 'Verified Miner';
        }
    } catch (Throwable $e) {}

    return [
        'tier_code' => $tierCode,
        'tier_label' => $tierLabel,
    ];
}

function bs_binding_code(int $userId): string
{
    return 'BND-' . str_pad((string)$userId, 6, '0', STR_PAD_LEFT);
}

function bs_binding_link(string $code): string
{
    return 'https://adoptgold.app/rwa/register?bind=' . rawurlencode($code);
}

function bs_qr_data_uri(string $text): string
{
    if (function_exists('poado_qr_svg_data_uri')) {
        try {
            return (string)poado_qr_svg_data_uri($text);
        } catch (Throwable $e) {}
    }
    return '';
}

function bs_bound_miners_count(PDO $pdo, int $userId): int
{
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*)
            FROM poado_adopter_bindings
            WHERE adopter_user_id = ?
              AND (is_active = 1 OR is_active IS NULL)
        ");
        $st->execute([$userId]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function bs_today_binding_wems(PDO $pdo, int $userId): float
{
    try {
        $st = $pdo->prepare("
            SELECT COALESCE(SUM(binding_wems), 0)
            FROM poado_binding_commission_daily
            WHERE adopter_user_id = ?
              AND stat_date = UTC_DATE()
        ");
        $st->execute([$userId]);
        return (float)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0.0;
    }
}

function bs_total_binding_wems(PDO $pdo, int $userId, string $wallet): float
{
    try {
        $st = $pdo->prepare("
            SELECT COALESCE(total_binding_wems, 0)
            FROM poado_miner_profiles
            WHERE user_id = ?
              AND wallet = ?
            LIMIT 1
        ");
        $st->execute([$userId, $wallet]);
        return (float)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0.0;
    }
}

function bs_last_event(PDO $pdo, int $userId): string
{
    try {
        $st = $pdo->prepare("
            SELECT MAX(updated_at)
            FROM poado_binding_commission_daily
            WHERE adopter_user_id = ?
        ");
        $st->execute([$userId]);
        $v = $st->fetchColumn();
        if ($v) return (string)$v . ' UTC';
    } catch (Throwable $e) {}

    try {
        $st = $pdo->prepare("
            SELECT MAX(created_at)
            FROM poado_adopter_bindings
            WHERE adopter_user_id = ?
        ");
        $st->execute([$userId]);
        $v = $st->fetchColumn();
        if ($v) return (string)$v . ' UTC';
    } catch (Throwable $e) {}

    return '—';
}

function bs_status(int $cap, int $remaining): string
{
    if ($cap <= 0) return 'no_binding_cap';
    if ($remaining <= 0) return 'cap_reached';
    return 'active';
}

db_connect();
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    bs_fail('NO_DB', 'DB connection unavailable', 500);
}

$user = session_user();
if (!is_array($user) || empty($user)) {
    bs_fail('NO_SESSION', 'Login required', 401);
}

$userId = (int)($user['id'] ?? 0);
$wallet = bs_wallet($user);
if ($userId <= 0 || $wallet === '') {
    bs_fail('NO_SESSION', 'Login required', 401);
}

$tier = bs_resolve_tier($pdo, $userId, $wallet, $user);
$bindingCap = bs_binding_cap_for_tier((string)$tier['tier_code']);
$boundCount = bs_bound_miners_count($pdo, $userId);
$remaining = max(0, $bindingCap - $boundCount);
$todayBinding = bs_today_binding_wems($pdo, $userId);
$totalBinding = bs_total_binding_wems($pdo, $userId, $wallet);
$code = bs_binding_code($userId);
$link = bs_binding_link($code);
$status = bs_status($bindingCap, $remaining);
$qr = bs_qr_data_uri($link);
$lastEvent = bs_last_event($pdo, $userId);

bs_json([
    'ok' => true,
    'summary' => [
        'tier_code' => (string)$tier['tier_code'],
        'tier_label' => (string)$tier['tier_label'],
        'binding_code' => $code,
        'binding_link' => $link,
        'binding_qr_data_uri' => $qr,
        'binding_cap' => $bindingCap,
        'remaining_binding' => $remaining,
        'bound_miners_count' => $boundCount,
        'today_binding_wems' => round($todayBinding, 8),
        'total_binding_wems' => round($totalBinding, 8),
        'status' => $status,
        'last_event' => $lastEvent,
    ],
]);
