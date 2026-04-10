<?php
declare(strict_types=1);

/**
 * /rwa/api/storage/address.php
 * Storage Bound TON Address API
 * Version: v7.0.0-address-20260319
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/api/storage/_bootstrap.php';

if (storage_request_method() !== 'GET') {
    storage_api_fail('METHOD_NOT_ALLOWED', [], 405);
}

$user = storage_require_user();
$userId = (int)($user['id'] ?? 0);
$walletAddress = storage_user_bound_ton_address($user);

storage_api_ok([
    'message' => 'ADDRESS_OK',
    'user_id' => $userId,
    'address' => $walletAddress,
    'wallet_address' => $walletAddress,
    'has_ton_bind' => ($walletAddress !== ''),
]);