<?php
declare(strict_types=1);

/**
 * /rwa/api/storage/claim-wems.php
 * Storage-owned claim entry with reserve lock
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/claim-reserve-lib.php';

header('Content-Type: application/json; charset=utf-8');

function out_json(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail_json(string $message, int $code = 400, array $extra = []): never
{
    out_json(array_merge([
        'ok' => false,
        'message' => $message,
    ], $extra), $code);
}

db_connect();
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    fail_json('DB_FAIL', 500);
}

$user = session_user();
if (!is_array($user) || empty($user)) {
    fail_json('NO_SESSION', 401);
}

$userId = (int)($user['id'] ?? 0);
$wallet = trim((string)($user['wallet_address'] ?? ''));
$isKyc  = (int)($user['is_fully_verified'] ?? 0) === 1;

if ($userId <= 0) {
    fail_json('INVALID_USER', 403);
}
if ($wallet === '') {
    fail_json('TON_NOT_BOUND', 403);
}
if (!$isKyc) {
    fail_json('KYC_REQUIRED', 403);
}

$amount = round((float)($_POST['amount'] ?? 0), 9);
$dest   = trim((string)($_POST['destination_wallet'] ?? ''));

if ($amount <= 0) {
    fail_json('INVALID_AMOUNT');
}
if ($dest === '') {
    fail_json('INVALID_DESTINATION');
}

try {
    $freeBefore = poado_claim_free_wems($pdo, $userId);

    $result = poado_create_claim_reserve(
        $pdo,
        $userId,
        $wallet,
        $dest,
        $amount
    );

    $freeAfter = poado_claim_free_wems($pdo, $userId);

    out_json([
        'ok' => true,
        'request_uid' => $result['request_uid'],
        'amount' => $result['amount_wems'],
        'status' => $result['claim_status'],
        'duplicate' => (bool)$result['duplicate'],
        'free_before' => $freeBefore,
        'free_after' => $freeAfter,
    ]);
} catch (Throwable $e) {
    fail_json($e->getMessage(), 400);
}
