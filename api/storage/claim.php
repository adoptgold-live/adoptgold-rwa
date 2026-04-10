<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/storage/claim.php
 * AdoptGold / POAdo — Storage Claim API
 * Version: v6.0.0-claim-prepare-20260318
 *
 * Locked rules:
 * - user-triggered only
 * - POST only
 * - CSRF required
 * - TON wallet must be bound
 * - no backend signing
 * - no offchain deduction here
 * - user pays normal TON gas
 * - user also pays fixed 0.10 TON treasury contribution
 * - exact-schema safe
 */

require_once __DIR__ . '/_bootstrap.php';

storage_assert_ready();
storage_require_post();
storage_require_user();
storage_assert_ton_bound();
storage_require_csrf('storage_claim');

$userId = storage_user_id();
$tokenRaw = (string) storage_input('token', '');
$amountRaw = storage_input('amount', '');

$token = storage_claim_token_normalize($tokenRaw);
$amount = storage_require_positive_amount($amountRaw, 6);

$balanceRow = storage_balance_ensure_row($userId);
$claimField = storage_claim_balance_field($token);
$available = storage_claim_balance_value($balanceRow, $claimField);

if (storage_decimal_cmp($available, $amount, 6) < 0) {
    storage_abort('INSUFFICIENT_CLAIMABLE_BALANCE', 422, [
        'token' => $token,
        'amount' => $amount,
        'available' => $available,
        'field' => $claimField,
    ]);
}

$treasuryTon = '0.10';
$treasuryAddress = storage_ton_treasury_address();
$walletAddress = storage_user_ton_address();
$claimRef = storage_claim_ref();

$meta = [
    'claim_ref' => $claimRef,
    'status' => 'prepared',
    'claim_field' => $claimField,
    'available_at_prepare' => $available,
    'treasury_ton' => $treasuryTon,
    'treasury_address' => $treasuryAddress,
    'gas_paid_by_user' => true,
    'wallet_address' => $walletAddress,
    'offchain_deducted' => false,
];

storage_history_add(
    $userId,
    'claim_prepare',
    $token,
    $amount,
    $meta
);

storage_json_ok([
    'message' => 'CLAIM_PREPARED',
    'claim_ref' => $claimRef,
    'token' => $token,
    'amount' => $amount,
    'available' => $available,
    'treasury_ton' => $treasuryTon,
    'treasury_address' => $treasuryAddress,
    'wallet_address' => $walletAddress,
    'gas_note' => 'User also pays normal TON gas',
    'offchain_deducted' => false,
    'next_action' => 'User must complete claim flow and pay TON gas plus 0.10 TON treasury contribution.',
], 200);

/* -------------------------------------------------
 | Claim helpers
 * ------------------------------------------------- */

function storage_claim_token_normalize(string $token): string
{
    $t = storage_normalize_token($token);

    if (in_array($t, ['EMA', 'WEMS', 'USDT-TON', 'EMX'], true)) {
        return $t;
    }

    storage_abort('INVALID_CLAIM_TOKEN', 422, [
        'token' => $token,
        'allowed' => ['EMA', 'WEMS', 'USDT-TON', 'EMX'],
    ]);
}

function storage_claim_balance_field(string $token): string
{
    return match ($token) {
        'EMA' => 'unclaim_ema',
        'WEMS' => 'unclaim_wems',
        'USDT-TON' => 'unclaim_gold_packet_usdt',
        'EMX' => 'unclaim_tips_emx',
        default => throw new RuntimeException('CLAIM_FIELD_MAP_MISSING'),
    };
}

function storage_claim_balance_value(array $row, string $field): string
{
    return storage_decimal_fix((string) ($row[$field] ?? '0'), 6);
}

function storage_claim_ref(): string
{
    try {
        $rand = substr(bin2hex(random_bytes(4)), 0, 8);
    } catch (Throwable $e) {
        $rand = substr(sha1((string) microtime(true) . '-' . mt_rand()), 0, 8);
    }

    return 'CLM-' . gmdate('YmdHis') . '-' . strtoupper($rand);
}

function storage_decimal_fix(string $raw, int $scale = 6): string
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

function storage_decimal_cmp(string $a, string $b, int $scale = 6): int
{
    $ai = storage_decimal_to_scaled_int_string($a, $scale);
    $bi = storage_decimal_to_scaled_int_string($b, $scale);

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

function storage_decimal_to_scaled_int_string(string $raw, int $scale = 6): string
{
    $fixed = storage_decimal_fix($raw, $scale);
    $neg = str_starts_with($fixed, '-');
    if ($neg) {
        $fixed = substr($fixed, 1);
    }

    $out = str_replace('.', '', $fixed);
    $out = ltrim($out, '0');
    $out = ($out === '') ? '0' : $out;

    return $neg && $out !== '0' ? '-' . $out : $out;
}