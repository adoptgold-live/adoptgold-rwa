<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/storage/fuel-up-emx-confirm.php
 * AdoptGold / POAdo — Fuel Up EMX Confirm API
 * Version: v3.1.0-exact-schema-20260317
 *
 * POST only
 *
 * Purpose:
 * - confirm incoming USDT-TON payment into treasury for a pending Fuel Up EMX request
 * - mark request as paid after verification
 * - no EMX fulfillment signing here
 *
 * Input:
 * - request_ref
 * - tx_hash_in
 */

require_once __DIR__ . '/_bootstrap.php';

storage_assert_ready();
storage_require_post();
storage_require_user();
storage_require_csrf_any('storage_topup_emx');
storage_assert_email_verified();
storage_assert_ton_bound();

$userId = storage_user_id();
$requestRef = trim((string)storage_input('request_ref', ''));
$txHashIn = trim((string)storage_input('tx_hash_in', ''));

if ($requestRef === '') {
    storage_abort('REQUEST_REF_REQUIRED', 422);
}
if ($txHashIn === '') {
    storage_abort('TX_HASH_REQUIRED', 422);
}

$request = fuel_emx_find_request($userId, $requestRef);
if (!$request) {
    storage_abort('REQUEST_NOT_FOUND', 404, ['request_ref' => $requestRef]);
}
if ((string)$request['status'] === 'paid') {
    storage_json_ok([
        'message' => 'REQUEST_ALREADY_PAID',
        'request_ref' => $requestRef,
        'status' => 'paid',
        'tx_hash_in' => (string)($request['tx_hash_in'] ?? ''),
        'token_in' => (string)$request['token_in'],
        'token_out' => (string)$request['token_out'],
        'amount_in' => (string)$request['amount_in'],
        'amount_out' => (string)$request['amount_out'],
    ]);
}
if ((string)$request['status'] !== 'pending') {
    storage_abort('REQUEST_NOT_PENDING', 409, [
        'request_ref' => $requestRef,
        'status' => (string)$request['status'],
    ]);
}

$verification = fuel_emx_verify_incoming_usdt_ton_payment(
    walletAddress: (string)$request['wallet_address'],
    treasuryAddress: (string)$request['treasury_address'],
    expectedAmountIn: (string)$request['amount_in'],
    txHashIn: $txHashIn
);

if (!$verification['ok']) {
    storage_abort('PAYMENT_NOT_VERIFIED', 422, [
        'request_ref' => $requestRef,
        'reason' => $verification['reason'] ?? 'unknown',
    ]);
}

storage_tx_begin();

try {
    $pdo = storage_pdo();

    $stmt = $pdo->prepare(
        "UPDATE rwa_storage_fuel_emx_requests
         SET tx_hash_in = :tx_hash_in,
             status = 'paid',
             paid_at = :paid_at,
             updated_at = :updated_at,
             meta_json = :meta_json
         WHERE user_id = :user_id
           AND request_ref = :request_ref
           AND status = 'pending'
         LIMIT 1"
    );

    $meta = fuel_emx_merge_meta_json(
        (string)($request['meta_json'] ?? ''),
        [
            'verified_at' => storage_now(),
            'verified_by' => 'fuel-up-emx-confirm',
            'tx_hash_in' => $txHashIn,
            'verification' => $verification,
        ]
    );

    $stmt->execute([
        ':tx_hash_in' => $txHashIn,
        ':paid_at' => storage_now(),
        ':updated_at' => storage_now(),
        ':meta_json' => $meta,
        ':user_id' => $userId,
        ':request_ref' => $requestRef,
    ]);

    if ($stmt->rowCount() < 1) {
        storage_abort('REQUEST_UPDATE_FAILED', 409, ['request_ref' => $requestRef]);
    }

    storage_history_add(
        $userId,
        'fuel_up_emx_paid',
        'USDT-TON',
        fuel_emx_confirm_scale_to_4((string)$request['amount_in']),
        [
            'request_ref' => $requestRef,
            'tx_hash_in' => $txHashIn,
            'token_out' => (string)$request['token_out'],
            'amount_out' => (string)$request['amount_out'],
            'status' => 'paid',
        ]
    );

    storage_tx_commit();
} catch (Throwable $e) {
    storage_tx_rollback();
    throw $e;
}

storage_json_ok([
    'message' => 'PAYMENT_VERIFIED',
    'request_ref' => $requestRef,
    'status' => 'paid',
    'tx_hash_in' => $txHashIn,
    'token_in' => (string)$request['token_in'],
    'token_out' => (string)$request['token_out'],
    'amount_in' => (string)$request['amount_in'],
    'amount_out' => (string)$request['amount_out'],
    'next_step' => 'FULFILL_EMX_TO_USER',
]);

function fuel_emx_find_request(int $userId, string $requestRef): ?array
{
    return storage_fetch_one(
        "SELECT
            id,
            user_id,
            request_ref,
            wallet_address,
            token_in,
            token_out,
            amount_in,
            amount_out,
            rate_text,
            treasury_address,
            tx_hash_in,
            tx_hash_out,
            status,
            meta_json,
            created_at,
            updated_at,
            paid_at,
            fulfilled_at
         FROM rwa_storage_fuel_emx_requests
         WHERE user_id = :user_id
           AND request_ref = :request_ref
         LIMIT 1",
        [
            ':user_id' => $userId,
            ':request_ref' => $requestRef,
        ]
    );
}

function fuel_emx_verify_incoming_usdt_ton_payment(
    string $walletAddress,
    string $treasuryAddress,
    string $expectedAmountIn,
    string $txHashIn
): array {
    $expectedNormalized = fuel_emx_confirm_amount6($expectedAmountIn);
    $usdtMaster = storage_jetton_master_usdt_ton();

    try {
        $json = storage_toncenter_get_json('/transactions', [
            'hash' => $txHashIn,
            'limit' => 1,
        ], 20);
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'reason' => 'TONCENTER_REQUEST_FAILED',
            'detail' => $e->getMessage(),
        ];
    }

    $tx = fuel_emx_extract_transaction_row($json);
    if (!$tx) {
        return [
            'ok' => false,
            'reason' => 'TX_NOT_FOUND',
        ];
    }

    $master = fuel_emx_extract_jetton_master($tx);
    if ($master !== '' && $master !== $usdtMaster) {
        return [
            'ok' => false,
            'reason' => 'WRONG_JETTON_MASTER',
            'expected' => $usdtMaster,
            'actual' => $master,
        ];
    }

    $to = fuel_emx_extract_recipient($tx);
    if ($to !== '' && $to !== $treasuryAddress) {
        return [
            'ok' => false,
            'reason' => 'WRONG_RECIPIENT',
            'expected' => $treasuryAddress,
            'actual' => $to,
        ];
    }

    $from = fuel_emx_extract_sender($tx);
    if ($from !== '' && $from !== $walletAddress) {
        return [
            'ok' => false,
            'reason' => 'WRONG_SENDER',
            'expected' => $walletAddress,
            'actual' => $from,
        ];
    }

    $amountRaw = fuel_emx_extract_amount_raw($tx);
    if ($amountRaw === '') {
        return [
            'ok' => false,
            'reason' => 'AMOUNT_NOT_FOUND',
        ];
    }

    $actualAmount = fuel_emx_confirm_from_base_units($amountRaw, 6);
    if (!fuel_emx_confirm_amount6_eq($actualAmount, $expectedNormalized)) {
        return [
            'ok' => false,
            'reason' => 'AMOUNT_MISMATCH',
            'expected' => $expectedNormalized,
            'actual' => $actualAmount,
        ];
    }

    return [
        'ok' => true,
        'tx_hash_in' => $txHashIn,
        'sender' => $from,
        'recipient' => $to,
        'jetton_master' => $master,
        'amount_in' => $actualAmount,
    ];
}

function fuel_emx_extract_transaction_row(array $json): ?array
{
    foreach (['transactions', 'data'] as $key) {
        if (!empty($json[$key]) && is_array($json[$key])) {
            $first = $json[$key][0] ?? null;
            return is_array($first) ? $first : null;
        }
    }
    if (isset($json['hash']) && is_array($json)) {
        return $json;
    }
    return null;
}

function fuel_emx_extract_jetton_master(array $tx): string
{
    $candidates = [
        $tx['jetton_master'] ?? null,
        $tx['jetton']['address'] ?? null,
        $tx['jetton_master_address'] ?? null,
        $tx['token']['address'] ?? null,
    ];

    foreach ($candidates as $value) {
        $v = trim((string)$value);
        if ($v !== '') {
            return $v;
        }
    }

    return '';
}

function fuel_emx_extract_recipient(array $tx): string
{
    $candidates = [
        $tx['destination'] ?? null,
        $tx['to'] ?? null,
        $tx['account'] ?? null,
        $tx['in_msg']['destination'] ?? null,
    ];

    foreach ($candidates as $value) {
        $v = trim((string)$value);
        if ($v !== '') {
            return $v;
        }
    }

    return '';
}

function fuel_emx_extract_sender(array $tx): string
{
    $candidates = [
        $tx['source'] ?? null,
        $tx['from'] ?? null,
        $tx['in_msg']['source'] ?? null,
        $tx['sender'] ?? null,
    ];

    foreach ($candidates as $value) {
        $v = trim((string)$value);
        if ($v !== '') {
            return $v;
        }
    }

    return '';
}

function fuel_emx_extract_amount_raw(array $tx): string
{
    $candidates = [
        $tx['amount'] ?? null,
        $tx['jetton_amount'] ?? null,
        $tx['in_msg']['amount'] ?? null,
        $tx['value'] ?? null,
    ];

    foreach ($candidates as $value) {
        $v = trim((string)$value);
        if ($v !== '') {
            return $v;
        }
    }

    return '';
}

function fuel_emx_confirm_amount6(string $value): string
{
    $raw = trim($value);
    if ($raw === '' || !preg_match('/^\d+(\.\d+)?$/', $raw)) {
        return '0.000000';
    }

    [$int, $frac] = array_pad(explode('.', $raw, 2), 2, '');
    $int = ltrim($int, '0');
    $int = ($int === '') ? '0' : $int;
    $frac = preg_replace('/\D+/', '', $frac) ?? '';
    $frac = substr(str_pad($frac, 6, '0'), 0, 6);

    return $int . '.' . $frac;
}

function fuel_emx_confirm_amount6_eq(string $a, string $b): bool
{
    return fuel_emx_confirm_amount6($a) === fuel_emx_confirm_amount6($b);
}

function fuel_emx_confirm_from_base_units(string $raw, int $decimals): string
{
    $raw = trim($raw);
    if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
        return '0.000000';
    }

    $raw = ltrim($raw, '0');
    $raw = ($raw === '') ? '0' : $raw;

    if (strlen($raw) <= $decimals) {
        $raw = str_pad($raw, $decimals + 1, '0', STR_PAD_LEFT);
    }

    $int = substr($raw, 0, -$decimals);
    $frac = substr($raw, -$decimals);

    $int = ltrim($int, '0');
    $int = ($int === '') ? '0' : $int;
    $frac = substr(str_pad($frac, 6, '0'), 0, 6);

    return $int . '.' . $frac;
}

function fuel_emx_merge_meta_json(string $existing, array $merge): string
{
    $base = [];
    if ($existing !== '') {
        $decoded = json_decode($existing, true);
        if (is_array($decoded)) {
            $base = $decoded;
        }
    }

    return json_encode(
        array_merge($base, $merge),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}

function fuel_emx_confirm_scale_to_4(string $value): string
{
    $v = fuel_emx_confirm_amount6($value);
    [$int, $frac] = explode('.', $v, 2);
    return $int . '.' . substr(str_pad($frac, 4, '0'), 0, 4);
}