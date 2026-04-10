<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/storage/balances.php
 * Version: v1.0.0-20260328-storage-balances-resolver
 *
 * Changelog:
 * - regenerated full reserved-aware balances resolver for Storage module
 * - returns items[].flow_type + display_amount for current storage.js contract
 * - returns balances summary block for compatibility with updateBalancesUI()
 * - uses live authenticated session only
 * - reads canonical wems_db tables only
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/db.php';

if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';
}
if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/error.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/error.php';
}

date_default_timezone_set('Asia/Kuala_Lumpur');

function sb_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sb_fail(string $error, int $status = 400, array $extra = []): never
{
    sb_json([
        'ok' => false,
        'ts' => date(DATE_ATOM),
        'error' => $error,
    ] + $extra, $status);
}

function sb_pdo(): PDO
{
    if (function_exists('rwa_db')) {
        $pdo = rwa_db();
        if ($pdo instanceof PDO) {
            return $pdo;
        }
    }

    if (function_exists('db')) {
        $pdo = db();
        if ($pdo instanceof PDO) {
            return $pdo;
        }
    }

    if (($GLOBALS['pdo'] ?? null) instanceof PDO) {
        return $GLOBALS['pdo'];
    }

    throw new RuntimeException('PDO_NOT_AVAILABLE');
}

function sb_session_user(): array
{
    if (function_exists('session_user')) {
        $u = session_user();
        if (is_array($u) && !empty($u)) {
            return $u;
        }
    }

    if (function_exists('rwa_require_login')) {
        $u = rwa_require_login();
        if (is_array($u) && !empty($u)) {
            return $u;
        }
    }

    if (function_exists('session_user_get')) {
        $u = session_user_get();
        if (is_array($u) && !empty($u)) {
            return $u;
        }
    }

    sb_fail('AUTH_REQUIRED', 401);
}

function sb_log(string $code, string $message, array $context = []): void
{
    if (!function_exists('poado_error')) {
        return;
    }

    try {
        poado_error('storage', $code, $context, $message);
    } catch (Throwable) {
        // do not break API response path
    }
}

function sb_num(mixed $value, int $scale = 6): string
{
    if ($value === null || $value === '') {
        return number_format(0, $scale, '.', '');
    }
    if (is_string($value)) {
        $value = trim($value);
        if ($value === '') {
            return number_format(0, $scale, '.', '');
        }
    }
    if (!is_numeric($value)) {
        return number_format(0, $scale, '.', '');
    }
    return number_format((float)$value, $scale, '.', '');
}

function sb_columns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $st = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table
    ");
    $st->execute([':table' => $table]);

    $cols = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $col) {
        $cols[(string)$col] = true;
    }

    return $cache[$table] = $cols;
}

function sb_has_table(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare("
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table
        LIMIT 1
    ");
    $st->execute([':table' => $table]);
    return (bool)$st->fetchColumn();
}

function sb_pick_first(array $row, array $keys, mixed $default = null): mixed
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row)) {
            return $row[$key];
        }
    }
    return $default;
}

function sb_load_storage_balance_row(PDO $pdo, int $userId): array
{
    if (!sb_has_table($pdo, 'rwa_storage_balances')) {
        return [];
    }

    $cols = sb_columns($pdo, 'rwa_storage_balances');
    if (!$cols) {
        return [];
    }

    $select = [];
    $wanted = [
        'user_id',
        'card_balance_rwa',
        'onchain_emx',
        'onchain_ema',
        'onchain_wems',
        'unclaim_ema',
        'unclaim_wems',
        'unclaim_gold_packet_usdt',
        'unclaim_tips_emx',
        'fuel_usdt_ton',
        'fuel_ems',
        'fuel_ton_gas',
        'created_at',
        'updated_at',
    ];

    foreach ($wanted as $col) {
        if (isset($cols[$col])) {
            $select[] = $col;
        }
    }

    if (!$select || !isset($cols['user_id'])) {
        return [];
    }

    $sql = "SELECT " . implode(', ', $select) . " FROM rwa_storage_balances WHERE user_id = :uid LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':uid' => $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : [];
}

function sb_row_amount(array $row, string $key): string
{
    return sb_num($row[$key] ?? 0, 6);
}

function sb_flow_item(string $flowType, string $token, string $amount, array $extra = []): array
{
    $display = sb_num($amount, 6);

    return [
        'flow_type' => $flowType,
        'token' => $token,
        'display_amount' => $display,
        'amount' => $display,
        'available_amount' => $display,
        'status' => bccomp($display, '0.000000', 6) > 0 ? 'ready' : 'empty',
    ] + $extra;
}

function sb_card_info(PDO $pdo, int $userId): array
{
    if (!sb_has_table($pdo, 'rwa_storage_cards')) {
        return [
            'status' => 'none',
            'active' => false,
            'is_active' => false,
            'locked' => 0,
            'card_number' => '',
            'number' => '',
        ];
    }

    $cols = sb_columns($pdo, 'rwa_storage_cards');
    if (!$cols || !isset($cols['user_id'])) {
        return [
            'status' => 'none',
            'active' => false,
            'is_active' => false,
            'locked' => 0,
            'card_number' => '',
            'number' => '',
        ];
    }

    $select = [];
    foreach (['card_hash', 'card_last4', 'card_masked', 'is_active', 'created_at', 'updated_at'] as $col) {
        if (isset($cols[$col])) {
            $select[] = $col;
        }
    }

    $sql = "SELECT " . ($select ? implode(', ', $select) : 'user_id') . " FROM rwa_storage_cards WHERE user_id = :uid ORDER BY updated_at DESC, created_at DESC LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':uid' => $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row) || !$row) {
        return [
            'status' => 'none',
            'active' => false,
            'is_active' => false,
            'locked' => 0,
            'card_number' => '',
            'number' => '',
        ];
    }

    $masked = (string)sb_pick_first($row, ['card_masked'], '');
    $last4 = (string)sb_pick_first($row, ['card_last4'], '');
    $isActive = (int)sb_pick_first($row, ['is_active'], 0) === 1;

    $number = $masked !== '' ? $masked : ($last4 !== '' ? ('**** **** **** ' . $last4) : '');

    return [
        'status' => $isActive ? 'active' : 'inactive',
        'active' => $isActive,
        'is_active' => $isActive,
        'locked' => $isActive ? 1 : 0,
        'card_number' => $number,
        'number' => $number,
    ];
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        sb_fail('METHOD_NOT_ALLOWED', 405);
    }

    $pdo = sb_pdo();
    $user = sb_session_user();

    $userId = (int)sb_pick_first($user, ['id', 'user_id'], 0);
    if ($userId <= 0) {
        sb_fail('AUTH_REQUIRED', 401);
    }

    $walletAddress = trim((string)sb_pick_first($user, ['wallet_address', 'wallet'], ''));

    $row = sb_load_storage_balance_row($pdo, $userId);

    $balances = [
        'card_balance_rwa' => sb_row_amount($row, 'card_balance_rwa'),
        'onchain_emx' => sb_row_amount($row, 'onchain_emx'),
        'onchain_ema' => sb_row_amount($row, 'onchain_ema'),
        'onchain_wems' => sb_row_amount($row, 'onchain_wems'),
        'unclaim_ema' => sb_row_amount($row, 'unclaim_ema'),
        'unclaim_wems' => sb_row_amount($row, 'unclaim_wems'),
        'unclaim_gold_packet_usdt' => sb_row_amount($row, 'unclaim_gold_packet_usdt'),
        'unclaim_tips_emx' => sb_row_amount($row, 'unclaim_tips_emx'),
        'fuel_usdt_ton' => sb_row_amount($row, 'fuel_usdt_ton'),
        'fuel_ems' => sb_row_amount($row, 'fuel_ems'),
        'fuel_ton_gas' => sb_row_amount($row, 'fuel_ton_gas'),
    ];

    $items = [
        sb_flow_item('claim_ema', 'EMA', $balances['unclaim_ema'], [
            'title' => 'Unclaimed EMA$',
        ]),
        sb_flow_item('claim_wems', 'WEMS', $balances['unclaim_wems'], [
            'title' => 'Unclaimed wEMS',
        ]),
        sb_flow_item('claim_usdt_ton', 'USDT-TON', $balances['unclaim_gold_packet_usdt'], [
            'title' => 'Unclaimed Gold Packet USDT-TON',
        ]),
        sb_flow_item('claim_emx_tips', 'EMX', $balances['unclaim_tips_emx'], [
            'title' => 'Unclaimed Tips EMX',
        ]),
        sb_flow_item('fuel_ems', 'EMS', $balances['fuel_ems'], [
            'title' => 'Fuel EMS',
        ]),
    ];

    $card = sb_card_info($pdo, $userId);
    $card['balance_rwa'] = $balances['card_balance_rwa'];
    $card['card_balance_rwa'] = $balances['card_balance_rwa'];

    sb_json([
        'ok' => true,
        'ts' => date(DATE_ATOM),
        'message' => 'BALANCES_OK',
        'user_id' => $userId,
        'wallet_address' => $walletAddress,
        'balances' => $balances,
        'items' => $items,
        'card' => $card,
        'meta' => [
            'source' => 'rwa_storage_balances',
            'resolver' => 'reserved_aware',
            'flows' => array_column($items, 'flow_type'),
        ],
    ]);
} catch (Throwable $e) {
    sb_log('STORAGE_BALANCES_FATAL', $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    sb_fail('STORAGE_BALANCES_FAILED', 500, [
        'message' => $e->getMessage(),
    ]);
}
