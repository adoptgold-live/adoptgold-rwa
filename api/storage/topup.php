<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    storage_fail('Method not allowed', 405);
}

$user   = storage_user();
$userId = storage_user_id($user);
$pdo    = storage_db();

storage_validate_csrf_any((string)($_POST['csrf'] ?? ''), ['storage_topup_emx']);

$action = trim((string)($_POST['action'] ?? ''));
if ($action !== 'topup') {
    storage_fail('Unsupported action', 400);
}

$mode   = strtoupper(trim((string)($_POST['mode'] ?? 'TOPUP')));
$token  = strtoupper(trim((string)($_POST['token'] ?? 'EMX')));
$amount = (float)storage_normalize_amount($_POST['amount'] ?? '0.0000');

if ($amount <= 0) {
    $amount = 100.0000;
}

$balances = storage_balance_row($pdo, $userId);

if ($mode === 'FUEL_EMX') {
    $inputUsdt = number_format($amount, 4, '.', '');
    $convertedEmx = $inputUsdt;
    $creditedUnclaimEma = number_format($amount * 10, 4, '.', '');

    $newFuelUsdt = storage_amount_sub((string)$balances['fuel_usdt_ton'], $inputUsdt);
    if ((float)$newFuelUsdt < 0) {
        $newFuelUsdt = '0.0000';
    }

    $newUnclaimEma = storage_amount_add((string)$balances['unclaim_ema'], $creditedUnclaimEma);

    storage_update_balance_fields($pdo, $userId, [
        'fuel_usdt_ton' => $newFuelUsdt,
        'unclaim_ema' => $newUnclaimEma,
    ]);

    storage_add_history($pdo, $userId, 'fuel_up_emx', 'USDT-TON', $inputUsdt, [
        'converted_emx' => $convertedEmx,
        'credited_unclaim_ema' => $creditedUnclaimEma,
    ]);

    storage_json([
        'ok' => true,
        'message' => 'Fuel up successful',
        'input_usdt_ton' => $inputUsdt,
        'converted_emx' => $convertedEmx,
        'credited_unclaim_ema' => $creditedUnclaimEma,
        'ts' => storage_now(),
    ]);
}

if ($token !== 'EMX') {
    storage_fail('Top up token must be EMX', 422);
}

$inputEmx = number_format($amount, 4, '.', '');
$creditedUnclaimEma = number_format($amount * 10, 4, '.', '');
$newUnclaimEma = storage_amount_add((string)$balances['unclaim_ema'], $creditedUnclaimEma);

storage_update_balance_fields($pdo, $userId, [
    'unclaim_ema' => $newUnclaimEma,
]);

storage_add_history($pdo, $userId, 'topup', 'EMX', $inputEmx, [
    'credited_unclaim_ema' => $creditedUnclaimEma,
]);

storage_json([
    'ok' => true,
    'message' => 'Top up successful',
    'input_emx' => $inputEmx,
    'credited_unclaim_ema' => $creditedUnclaimEma,
    'ts' => storage_now(),
]);