<?php
declare(strict_types=1);

/**
 * /rwa/api/storage/history.php
 * Storage History API
 * Version: v7.3.1-history-final-aligned-20260319
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

$items = storage_history_items($userId, 20);

storage_api_ok([
    'message' => 'HISTORY_OK',
    'items' => $items,
], 200);