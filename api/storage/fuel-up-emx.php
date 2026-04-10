<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/storage/fuel-up-emx.php
 * AdoptGold / POAdo — Fuel Up EMX Request API
 * Version: v3.1.0-exact-schema-20260317
 *
 * Exact confirmed table:
 *   rwa_storage_fuel_emx_requests
 *   {id, user_id, request_ref, wallet_address, token_in, token_out, amount_in, amount_out,
 *    rate_text, treasury_address, tx_hash_in, tx_hash_out, status, meta_json,
 *    created_at, updated_at, paid_at, fulfilled_at}
 *
 * Locked flow:
 *   Onchain USDT-TON -> Onchain EMX (1:1)
 *   Stage 1 only creates pending request
 *   No private key signing here
 */

require_once __DIR__ . '/_bootstrap.php';

storage_assert_ready();
storage_require_post();
storage_require_user();
storage_require_csrf_any('storage_topup_emx');
storage_assert_email_verified();
storage_assert_ton_bound();

$userId = storage_user_id();
$walletAddress = storage_user_ton_address();
$tokenIn = strtoupper(trim((string)(storage_input('token', 'USDT-TON'))));
$amountIn = fuel_emx_amount6(storage_require_positive_amount(storage_input('amount', ''), 6));

if ($tokenIn !== 'USDT-TON') {
    storage_abort('INVALID_TOKEN_IN', 422, ['token' => $tokenIn]);
}

if (!fuel_emx_amount6_gt_zero($amountIn)) {
    storage_abort('INVALID_AMOUNT', 422, ['amount' => $amountIn]);
}

$amountOut = fuel_emx_scale_to_9($amountIn);
$requestRef = storage_ref_id('FEMX');
$treasury = storage_ton_treasury_address();

storage_tx_begin();

try {
    $pdo = storage_pdo();

    $pendingCount = (int)($pdo->prepare(
        "SELECT COUNT(*) FROM rwa_storage_fuel_emx_requests WHERE user_id = :user_id AND status = 'pending'"
    )->execute([':user_id' => $userId]) ?: 0);

    $stmtCount = $pdo->prepare(
        "SELECT COUNT(*) FROM rwa_storage_fuel_emx_requests WHERE user_id = :user_id AND status = 'pending'"
    );
    $stmtCount->execute([':user_id' => $userId]);
    $pendingCount = (int)$stmtCount->fetchColumn();

    if ($pendingCount >= 5) {
        storage_abort('TOO_MANY_PENDING_REQUESTS', 429, ['pending_count' => $pendingCount]);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO rwa_storage_fuel_emx_requests (
            user_id,
            request_ref,
            wallet_address,
            token_in,
            token_out,
            amount_in,
            amount_out,
            rate_text,
            treasury_address,
            status,
            meta_json,
            created_at,
            updated_at
        ) VALUES (
            :user_id,
            :request_ref,
            :wallet_address,
            :token_in,
            :token_out,
            :amount_in,
            :amount_out,
            :rate_text,
            :treasury_address,
            'pending',
            :meta_json,
            :created_at,
            :updated_at
        )"
    );

    $meta = [
        'flow' => 'fuel_up_emx',
        'note' => 'User must send USDT-TON to treasury; EMX fulfillment happens after verification',
        'wallet_address' => $walletAddress,
    ];

    $stmt->execute([
        ':user_id' => $userId,
        ':request_ref' => $requestRef,
        ':wallet_address' => $walletAddress,
        ':token_in' => 'USDT-TON',
        ':token_out' => 'EMX',
        ':amount_in' => $amountIn,
        ':amount_out' => $amountOut,
        ':rate_text' => '1:1',
        ':treasury_address' => $treasury,
        ':meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':created_at' => storage_now(),
        ':updated_at' => storage_now(),
    ]);

    storage_history_add(
        $userId,
        'fuel_up_emx',
        'USDT-TON',
        fuel_emx_scale_to_4($amountIn),
        [
            'request_ref' => $requestRef,
            'token_out' => 'EMX',
            'amount_out' => $amountOut,
            'treasury_address' => $treasury,
            'status' => 'pending',
        ]
    );

    storage_tx_commit();
} catch (Throwable $e) {
    storage_tx_rollback();
    throw $e;
}

storage_json_ok([
    'mode' => 'wallet_transfer_required',
    'message' => 'Send USDT-TON to treasury to receive EMX',
    'request_ref' => $requestRef,
    'token_in' => 'USDT-TON',
    'token_out' => 'EMX',
    'rate' => '1:1',
    'amount_in' => $amountIn,
    'amount_out' => $amountOut,
    'to_address' => $treasury,
    'wallet_address' => $walletAddress,
    'status' => 'pending',
]);

function fuel_emx_amount6(string $value): string
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

function fuel_emx_amount6_gt_zero(string $value): bool
{
    [$int, $frac] = array_pad(explode('.', fuel_emx_amount6($value), 2), 2, '000000');
    return ltrim($int, '0') !== '' || trim($frac, '0') !== '';
}

function fuel_emx_scale_to_9(string $value): string
{
    $v = fuel_emx_amount6($value);
    [$int, $frac] = explode('.', $v, 2);
    return $int . '.' . str_pad($frac, 9, '0');
}

function fuel_emx_scale_to_4(string $value): string
{
    $v = fuel_emx_amount6($value);
    [$int, $frac] = explode('.', $v, 2);
    return $int . '.' . substr(str_pad($frac, 4, '0'), 0, 4);
}