<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/storage/fuel.php
 * AdoptGold / POAdo — Storage Fuel API
 * Version: v6.0.0-fuel-prepare-20260318
 *
 * Locked rules:
 * - user-triggered only
 * - POST only
 * - CSRF required
 * - exact-schema only
 * - no guessed tables/columns
 * - no fake onchain/offchain settlement
 * - no direct balance mutation here
 *
 * Supported modes:
 * - mode=emx : prepare onchain USDT-TON -> onchain EMX flow
 * - mode=ems : prepare offchain wEMS -> fuel EMS flow
 * - mode=emx_confirm : record confirm receipt only, still no settlement mutation
 *
 * Current exact-schema-safe behavior:
 * - PREPARE only
 * - read balances from rwa_storage_balances
 * - write history prepare row only
 * - return computed intent + warnings
 */

require_once __DIR__ . '/_bootstrap.php';

storage_assert_ready();
storage_require_post();
storage_require_user();
storage_assert_ton_bound();

$modeRaw = trim((string) storage_input('mode', ''));
$mode = storage_fuel_mode_normalize($modeRaw);

if ($mode === 'EMX') {
    storage_require_csrf('storage_fuel_up_emx');
} elseif ($mode === 'EMX_CONFIRM') {
    storage_require_csrf('storage_fuel_up_emx_confirm');
} elseif ($mode === 'EMS') {
    storage_require_csrf('storage_fuel_up_ems');
} else {
    storage_abort('INVALID_FUEL_MODE', 422, [
        'mode' => $modeRaw,
        'allowed' => ['emx', 'ems', 'emx_confirm'],
    ]);
}

$userId = storage_user_id();
$walletAddress = storage_user_ton_address();
$balanceRow = storage_balance_ensure_row($userId);

if ($mode === 'EMX') {
    storage_fuel_prepare_emx($userId, $walletAddress, $balanceRow);
}

if ($mode === 'EMX_CONFIRM') {
    storage_fuel_prepare_emx_confirm($userId, $walletAddress, $balanceRow);
}

storage_fuel_prepare_ems($userId, $walletAddress, $balanceRow);

/* -------------------------------------------------
 | EMX prepare
 * ------------------------------------------------- */

function storage_fuel_prepare_emx(int $userId, string $walletAddress, array $balanceRow): void
{
    $amountRaw = storage_input('amount', '');
    $amount = storage_require_positive_amount($amountRaw, 6);

    $availableUsdt = storage_fuel_decimal_fix((string) ($balanceRow['fuel_usdt_ton'] ?? '0'), 6);
    $availableEmx = storage_fuel_decimal_fix((string) ($balanceRow['onchain_emx'] ?? '0'), 6);

    $fuelRef = storage_fuel_ref('FEMX');
    $warnings = [];

    if (storage_fuel_decimal_cmp($availableUsdt, $amount, 6) < 0) {
        $warnings[] = 'FUEL_USDT_TON_BELOW_REQUEST';
    }

    $meta = [
        'fuel_ref' => $fuelRef,
        'mode' => 'EMX',
        'status' => 'prepared',
        'wallet_address' => $walletAddress,
        'requested_usdt_ton' => $amount,
        'expected_emx' => $amount,
        'available_fuel_usdt_ton_at_prepare' => $availableUsdt,
        'available_onchain_emx_at_prepare' => $availableEmx,
        'balances_mutated' => false,
        'warnings' => $warnings,
    ];

    storage_history_add(
        $userId,
        'fuel_prepare_emx',
        'USDT-TON',
        $amount,
        $meta
    );

    storage_json_ok([
        'message' => 'FUEL_EMX_PREPARED',
        'fuel_ref' => $fuelRef,
        'mode' => 'EMX',
        'wallet_address' => $walletAddress,
        'requested_usdt_ton' => $amount,
        'expected_emx' => $amount,
        'available_fuel_usdt_ton' => $availableUsdt,
        'available_onchain_emx' => $availableEmx,
        'balances_mutated' => false,
        'warnings' => $warnings,
        'next_action' => 'Real EMX fuel confirm/settlement flow must complete before any balance mutation.',
    ], 200);
}

/* -------------------------------------------------
 | EMX confirm prepare
 * ------------------------------------------------- */

function storage_fuel_prepare_emx_confirm(int $userId, string $walletAddress, array $balanceRow): void
{
    $fuelRef = trim((string) storage_input('fuel_ref', ''));
    $txHash = trim((string) storage_input('tx_hash', ''));

    if ($fuelRef === '') {
        storage_abort('FUEL_REF_REQUIRED', 422, []);
    }
    if ($txHash === '') {
        storage_abort('TX_HASH_REQUIRED', 422, []);
    }

    $availableUsdt = storage_fuel_decimal_fix((string) ($balanceRow['fuel_usdt_ton'] ?? '0'), 6);
    $availableEmx = storage_fuel_decimal_fix((string) ($balanceRow['onchain_emx'] ?? '0'), 6);

    $meta = [
        'fuel_ref' => $fuelRef,
        'mode' => 'EMX_CONFIRM',
        'status' => 'confirm_received',
        'wallet_address' => $walletAddress,
        'tx_hash' => $txHash,
        'available_fuel_usdt_ton_at_confirm' => $availableUsdt,
        'available_onchain_emx_at_confirm' => $availableEmx,
        'balances_mutated' => false,
    ];

    storage_history_add(
        $userId,
        'fuel_prepare_emx_confirm',
        'SYSTEM',
        '0.000000',
        $meta
    );

    storage_json_ok([
        'message' => 'FUEL_EMX_CONFIRM_RECORDED',
        'fuel_ref' => $fuelRef,
        'mode' => 'EMX_CONFIRM',
        'wallet_address' => $walletAddress,
        'tx_hash' => $txHash,
        'available_fuel_usdt_ton' => $availableUsdt,
        'available_onchain_emx' => $availableEmx,
        'balances_mutated' => false,
        'next_action' => 'Replace prepare-only confirm with real chain verification before settlement.',
    ], 200);
}

/* -------------------------------------------------
 | EMS prepare
 * ------------------------------------------------- */

function storage_fuel_prepare_ems(int $userId, string $walletAddress, array $balanceRow): void
{
    $amountRaw = storage_input('amount', '');
    $amount = storage_require_positive_amount($amountRaw, 6);

    $availableWems = storage_fuel_decimal_fix((string) ($balanceRow['onchain_wems'] ?? '0'), 6);
    $availableEms = storage_fuel_decimal_fix((string) ($balanceRow['fuel_ems'] ?? '0'), 6);

    $fuelRef = storage_fuel_ref('FEMS');
    $warnings = [];

    if (storage_fuel_decimal_cmp($availableWems, $amount, 6) < 0) {
        $warnings[] = 'ONCHAIN_WEMS_BELOW_REQUEST';
    }

    $meta = [
        'fuel_ref' => $fuelRef,
        'mode' => 'EMS',
        'status' => 'prepared',
        'wallet_address' => $walletAddress,
        'requested_wems' => $amount,
        'expected_ems' => $amount,
        'available_onchain_wems_at_prepare' => $availableWems,
        'available_fuel_ems_at_prepare' => $availableEms,
        'balances_mutated' => false,
        'warnings' => $warnings,
    ];

    storage_history_add(
        $userId,
        'fuel_prepare_ems',
        'WEMS',
        $amount,
        $meta
    );

    storage_json_ok([
        'message' => 'FUEL_EMS_PREPARED',
        'fuel_ref' => $fuelRef,
        'mode' => 'EMS',
        'wallet_address' => $walletAddress,
        'requested_wems' => $amount,
        'expected_ems' => $amount,
        'available_onchain_wems' => $availableWems,
        'available_fuel_ems' => $availableEms,
        'balances_mutated' => false,
        'warnings' => $warnings,
        'next_action' => 'Real EMS fuel settlement/email-confirm flow must complete before any balance mutation.',
    ], 200);
}

/* -------------------------------------------------
 | Helpers
 * ------------------------------------------------- */

function storage_fuel_mode_normalize(string $mode): string
{
    $m = strtoupper(trim($mode));

    return match ($m) {
        'EMX' => 'EMX',
        'EMS' => 'EMS',
        'EMX_CONFIRM' => 'EMX_CONFIRM',
        default => '',
    };
}

function storage_fuel_ref(string $prefix): string
{
    try {
        $rand = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    } catch (Throwable $e) {
        $rand = strtoupper(substr(sha1((string) microtime(true) . '-' . mt_rand()), 0, 8));
    }

    return $prefix . '-' . gmdate('YmdHis') . '-' . $rand;
}

function storage_fuel_decimal_fix(string $raw, int $scale = 6): string
{
    $raw = trim($raw);
    if ($raw === '' || !preg_match('/^-?\d+(\.\d+)?$/', $raw)) {
        $raw = '0';
    }

    $neg = false;
    if (str_starts_with($raw, '-')) {
        $neg = true;
        $raw = substr($raw, 1);
    }

    [$int, $frac] = array_pad(explode('.', $raw, 2), 2, '');
    $int = ltrim($int, '0');
    $int = ($int === '') ? '0' : $int;

    $frac = preg_replace('/\D+/', '', $frac) ?? '';
    $frac = substr(str_pad($frac, $scale, '0'), 0, $scale);

    $out = $int . '.' . $frac;

    if ($neg && $out !== '0.' . str_repeat('0', $scale)) {
        $out = '-' . $out;
    }

    return $out;
}

function storage_fuel_decimal_cmp(string $a, string $b, int $scale = 6): int
{
    $ai = storage_fuel_decimal_to_scaled_int_string($a, $scale);
    $bi = storage_fuel_decimal_to_scaled_int_string($b, $scale);

    $aNeg = str_starts_with($ai, '-');
    $bNeg = str_starts_with($bi, '-');

    if ($aNeg && !$bNeg) {
        return -1;
    }
    if (!$aNeg && $bNeg) {
        return 1;
    }

    $ai = ltrim($ai, '-');
    $bi = ltrim($bi, '-');

    $ai = ltrim($ai, '0');
    $bi = ltrim($bi, '0');

    $ai = ($ai === '') ? '0' : $ai;
    $bi = ($bi === '') ? '0' : $bi;

    if (!$aNeg && !$bNeg) {
        if (strlen($ai) < strlen($bi)) {
            return -1;
        }
        if (strlen($ai) > strlen($bi)) {
            return 1;
        }

        return $ai <=> $bi;
    }

    if (strlen($ai) < strlen($bi)) {
        return 1;
    }
    if (strlen($ai) > strlen($bi)) {
        return -1;
    }

    return $bi <=> $ai;
}

function storage_fuel_decimal_to_scaled_int_string(string $raw, int $scale = 6): string
{
    $fixed = storage_fuel_decimal_fix($raw, $scale);
    $neg = str_starts_with($fixed, '-');
    if ($neg) {
        $fixed = substr($fixed, 1);
    }

    $out = str_replace('.', '', $fixed);
    $out = ltrim($out, '0');
    $out = ($out === '') ? '0' : $out;

    return $neg && $out !== '0' ? '-' . $out : $out;
}