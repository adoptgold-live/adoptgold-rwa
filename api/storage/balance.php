<?php
declare(strict_types=1);

/**
 * /rwa/api/storage/balance.php
 * Storage Balance API
 * Version: v7.3.1-balance-final-aligned-20260319
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/api/storage/_bootstrap.php';

if (storage_request_method() !== 'GET') {
    storage_api_fail('METHOD_NOT_ALLOWED', ['message' => 'GET required'], 405);
}

storage_assert_ready();

$user = storage_require_user();
$userId = (int)($user['id'] ?? 0);

if ($userId <= 0) {
    storage_api_fail('AUTH_REQUIRED', ['message' => 'Login required'], 401);
}

$sync = storage_sync_onchain_balances($user);
$balances = is_array($sync['balances'] ?? null) ? $sync['balances'] : storage_balance_row($userId);
$card = storage_card_row($userId);

storage_api_ok([
    'message' => 'BALANCE_OK',
    'address' => (string)($user['wallet_address'] ?? ''),
    'sync' => $sync['sync'] ?? null,

    'card' => [
        'number' => (string)($card['card_number'] ?? ''),
        'card_number' => (string)($card['card_number'] ?? ''),
        'status' => (string)($card['status'] ?? 'none'),
        'locked' => (int)($card['locked'] ?? 0),
        'active' => (bool)($card['is_active'] ?? false),
        'is_active' => (bool)($card['is_active'] ?? false),
        'balance_rwa' => (string)($balances['card_balance_rwa'] ?? '0.000000'),
    ],

    'tokens' => [
        'EMA' => [
            'on_chain' => (string)($balances['onchain_ema'] ?? '0.000000'),
            'available' => (string)($balances['unclaim_ema'] ?? '0.000000'),
        ],
        'EMX' => [
            'on_chain' => (string)($balances['onchain_emx'] ?? '0.000000'),
            'available' => (string)($balances['unclaim_tips_emx'] ?? '0.000000'),
        ],
        'EMS' => [
            'on_chain' => (string)($balances['fuel_ems'] ?? '0.000000'),
            'available' => '0.000000',
        ],
        'WEMS' => [
            'on_chain' => (string)($balances['onchain_wems'] ?? '0.000000'),
            'available' => (string)($balances['unclaim_wems'] ?? '0.000000'),
        ],
        'USDT_TON' => [
            'on_chain' => (string)($balances['fuel_usdt_ton'] ?? '0.000000'),
            'available' => (string)($balances['unclaim_gold_packet_usdt'] ?? '0.000000'),
        ],
        'TON' => [
            'on_chain' => (string)($balances['fuel_ton_gas'] ?? '0.000000'),
            'available' => '0.000000',
        ],
    ],
], 200);