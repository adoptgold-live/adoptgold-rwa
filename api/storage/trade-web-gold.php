<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/storage/trade-web-gold.php
 * AdoptGold / POAdo — Trade Web Gold API
 * Version: v3.1.0-exact-schema-20260317
 *
 * Locked behavior:
 * - TRADE WEB GOLD
 * - action means transfer wEMS to STON.fi
 * - stage 1 returns wallet transfer instruction only
 * - no backend signing
 * - no guessed DB columns
 *
 * Exact confirmed schema used:
 * - users
 * - rwa_storage_balances
 * - rwa_storage_history
 */

require_once __DIR__ . '/_bootstrap.php';

storage_assert_ready();
storage_require_post();
storage_require_user();
storage_require_csrf_any('storage_store_action');
storage_assert_email_verified();
storage_assert_ton_bound();

$userId = storage_user_id();
$walletAddress = storage_user_ton_address();
$amountWems = trade_web_gold_decimal4(
    storage_require_positive_amount(
        storage_input('amount_wems', storage_input('amount', '')),
        4
    )
);

if (!trade_web_gold_gt_zero($amountWems)) {
    storage_abort('INVALID_AMOUNT_WEMS', 422, ['amount_wems' => $amountWems]);
}

$balances = storage_balance_ensure_row($userId);
$availableOnchainWems = trade_web_gold_decimal4((string)($balances['onchain_wems'] ?? '0.0000'));

if (!trade_web_gold_gte($availableOnchainWems, $amountWems)) {
    storage_abort('INSUFFICIENT_ONCHAIN_WEMS', 422, [
        'available_onchain_wems' => $availableOnchainWems,
        'requested_amount_wems' => $amountWems,
    ]);
}

$tradeRef = storage_ref_id('TWG');
$stonfiUrl = trade_web_gold_stonfi_url();

storage_history_add(
    $userId,
    'trade_web_gold',
    'WEMS',
    $amountWems,
    [
        'trade_ref' => $tradeRef,
        'wallet_address' => $walletAddress,
        'amount_wems' => $amountWems,
        'destination' => 'STON.fi',
        'status' => 'pending_wallet_transfer',
        'stonfi_url' => $stonfiUrl,
        'jetton_master_wems' => storage_jetton_master_wems(),
        'note' => 'User should transfer or route wEMS to STON.fi using wallet flow',
    ]
);

storage_json_ok([
    'mode' => 'wallet_transfer_required',
    'message' => 'TRANSFER_WEMS_TO_STONFI',
    'trade_ref' => $tradeRef,
    'token_in' => 'WEMS',
    'amount_wems' => $amountWems,
    'wallet_address' => $walletAddress,
    'jetton_master' => storage_jetton_master_wems(),
    'destination' => 'STON.fi',
    'stonfi_url' => $stonfiUrl,
    'status' => 'pending_wallet_transfer',
]);

function trade_web_gold_stonfi_url(): string
{
    foreach ([
        $_ENV['STONFI_SWAP_URL'] ?? null,
        $_SERVER['STONFI_SWAP_URL'] ?? null,
    ] as $value) {
        $v = trim((string)$value);
        if ($v !== '') {
            return $v;
        }
    }

    return 'https://app.ston.fi/';
}

function trade_web_gold_decimal4(string $value): string
{
    $raw = trim($value);
    if ($raw === '' || !preg_match('/^\d+(\.\d+)?$/', $raw)) {
        return '0.0000';
    }

    [$int, $frac] = array_pad(explode('.', $raw, 2), 2, '');
    $int = ltrim($int, '0');
    $int = ($int === '') ? '0' : $int;
    $frac = preg_replace('/\D+/', '', $frac) ?? '';
    $frac = substr(str_pad($frac, 4, '0'), 0, 4);

    return $int . '.' . $frac;
}

function trade_web_gold_gt_zero(string $value): bool
{
    [$int, $frac] = array_pad(explode('.', trade_web_gold_decimal4($value), 2), 2, '0000');
    return ltrim($int, '0') !== '' || trim($frac, '0') !== '';
}

function trade_web_gold_gte(string $a, string $b): bool
{
    [$ai, $af] = trade_web_gold_parts4($a);
    [$bi, $bf] = trade_web_gold_parts4($b);

    return ($ai > $bi) || ($ai === $bi && $af >= $bf);
}

function trade_web_gold_parts4(string $value): array
{
    $v = trade_web_gold_decimal4($value);
    [$int, $frac] = explode('.', $v, 2);
    return [(int)$int, (int)$frac];
}