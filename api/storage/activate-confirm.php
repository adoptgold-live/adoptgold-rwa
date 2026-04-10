<?php
declare(strict_types=1);

/**
 * /rwa/api/storage/activate-confirm.php
 * Storage Master v7.4b final confirm
 *
 * Behavior:
 * - If already active => OK
 * - If verified tx exists => activate + OK
 * - If re-verify finds match => activate + OK
 * - If not verified yet => HTTP 200 safe warning (not 400)
 * - If activation succeeds => return EMA reward fields
 * - One activation_ref = one EMA reward only
 */

ob_start();

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/api/storage/_bootstrap.php';

function activateconfirm_json(array $payload, int $status = 200): void
{
    while (ob_get_level() > 0) {
        $buf = ob_get_clean();
        unset($buf);
    }

    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    storage_require_post();

    // maintain previous function style
    if (function_exists('storage_require_csrf')) {
        storage_require_csrf('storage_activate_card');
    }

    $user = storage_require_user();
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        activateconfirm_json([
            'ok' => false,
            'error' => 'AUTH_REQUIRED',
            'message' => 'Login required',
        ], 401);
    }

    $card = storage_card_row($userId);

    // Canonical ref = DB ref first, then posted ref
    $postedActivationRef = storage_post('activation_ref', '');
    $dbActivationRef = trim((string)($card['activation_ref'] ?? ''));
    $activationRef = $dbActivationRef !== '' ? $dbActivationRef : trim($postedActivationRef);

    if ($activationRef === '') {
        activateconfirm_json([
            'ok' => false,
            'error' => 'ACTIVATION_REF_REQUIRED',
            'message' => 'Activation reference required',
        ], 400);
    }

    $pdo = storage_db();

    // reward preview fields for already-active / safe responses
    $emaPriceSnapshot = '';
    $emaReward = '';
    try {
        if (function_exists('storage_activation_ema_price_snapshot')) {
            $emaPriceSnapshot = (string)storage_activation_ema_price_snapshot();
        }
        if ($emaPriceSnapshot !== '' && function_exists('storage_activation_reward_amount')) {
            $emaReward = (string)storage_activation_reward_amount($emaPriceSnapshot);
        }
    } catch (\Throwable $e) {
        $emaPriceSnapshot = '';
        $emaReward = '';
    }

    // Already active = success
    if (storage_card_is_active($card)) {
        $activeTxHash = (string)($card['activation_tx_hash'] ?? '');
        $reward = [
            'ema_price_snapshot' => $emaPriceSnapshot,
            'ema_reward' => $emaReward,
            'reward_token' => 'EMA',
            'reward_status' => 'already_active',
            'already_rewarded' => false,
        ];

        try {
            if (function_exists('storage_activation_credit_ema_reward')) {
                $reward = storage_activation_credit_ema_reward($userId, $activationRef, $activeTxHash);
            }
        } catch (\Throwable $e) {
        }

        activateconfirm_json([
            'ok' => true,
            'code' => 'ALREADY_ACTIVE',
            'message' => 'ALREADY_ACTIVE',
            'verified' => true,
            'is_active' => true,
            'card_active' => 1,
            'reload_enabled' => 1,
            'activation_status' => 'confirmed',
            'activation_ref' => $activationRef,
            'tx_hash' => $activeTxHash,
            'activation_amount' => '100.000000',
            'activation_token' => 'EMX',
            'free_reward' => 1,
            'success_summary' => 'Card already active.',
            'ema_price_snapshot' => (string)($reward['ema_price_snapshot'] ?? $emaPriceSnapshot),
            'ema_reward' => (string)($reward['ema_reward'] ?? $emaReward),
            'reward_token' => (string)($reward['reward_token'] ?? 'EMA'),
            'reward_status' => (string)($reward['reward_status'] ?? 'already_active'),
            'already_rewarded' => !empty($reward['already_rewarded']),
        ], 200);
    }

    // Already verified in tx table = success
    $existing = storage_activation_tx_find_by_ref($pdo, $activationRef);
    if ($existing) {
        $txHash = (string)($existing['tx_hash'] ?? '');

        $startedTxn = false;
        if ($pdo instanceof PDO && !$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTxn = true;
        }

        try {
            storage_mark_card_active_for_user($userId, $activationRef, $txHash);

            if (function_exists('storage_history_record')) {
                try {
                    storage_history_record($userId, 'activate_card', 'EMX', '100.000000', [
                        'activation_ref' => $activationRef,
                        'tx_hash' => $txHash,
                        'status' => 'confirmed',
                        'source' => 'activate_confirm_tx_table',
                    ]);
                } catch (\Throwable $e) {
                }
            }

            $reward = [
                'ema_price_snapshot' => $emaPriceSnapshot,
                'ema_reward' => $emaReward,
                'reward_token' => 'EMA',
                'reward_status' => 'credited_to_unclaim_ema',
                'already_rewarded' => false,
            ];

            if (function_exists('storage_activation_credit_ema_reward')) {
                $reward = storage_activation_credit_ema_reward($userId, $activationRef, $txHash);
            }

            if ($startedTxn && $pdo->inTransaction()) {
                $pdo->commit();
            }

            activateconfirm_json([
                'ok' => true,
                'code' => 'ACTIVATION_CONFIRMED',
                'message' => 'ACTIVATION_CONFIRMED',
                'verified' => true,
                'is_active' => true,
                'card_active' => 1,
                'reload_enabled' => 1,
                'activation_status' => 'confirmed',
                'activation_ref' => $activationRef,
                'tx_hash' => $txHash,
                'source' => 'tx_table',
                'activation_amount' => '100.000000',
                'activation_token' => 'EMX',
                'free_reward' => 1,
                'success_summary' => 'Card activated and free EMA$ credited.',
                'ema_price_snapshot' => (string)($reward['ema_price_snapshot'] ?? $emaPriceSnapshot),
                'ema_reward' => (string)($reward['ema_reward'] ?? $emaReward),
                'reward_token' => (string)($reward['reward_token'] ?? 'EMA'),
                'reward_status' => (string)($reward['reward_status'] ?? 'credited_to_unclaim_ema'),
                'already_rewarded' => !empty($reward['already_rewarded']),
            ], 200);
        } catch (\Throwable $e) {
            if ($startedTxn && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // Optional tx hash hint
    $txHashInput = storage_post('tx_hash', '');

    // Re-run canonical verify using canonical ref
    $verify = storage_verify_emx_activation_auto(
        $userId,
        $activationRef,
        ['tx_hint' => $txHashInput]
    );

    if (
        ($verify['ok'] ?? false) === true
        || in_array((string)($verify['code'] ?? ''), [
            'ALREADY_VERIFIED',
            'ALREADY_ACTIVE',
            'ACTIVATION_CONFIRMED',
        ], true)
    ) {
        $txHash = (string)($verify['tx_hash'] ?? '');

        $startedTxn = false;
        if ($pdo instanceof PDO && !$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTxn = true;
        }

        try {
            storage_mark_card_active_for_user($userId, $activationRef, $txHash);

            if (function_exists('storage_history_record')) {
                try {
                    storage_history_record($userId, 'activate_card', 'EMX', '100.000000', [
                        'activation_ref' => $activationRef,
                        'tx_hash' => $txHash,
                        'status' => 'confirmed',
                        'source' => 'activate_confirm_force_accept',
                    ]);
                } catch (\Throwable $e) {
                }
            }

            $reward = [
                'ema_price_snapshot' => $emaPriceSnapshot,
                'ema_reward' => $emaReward,
                'reward_token' => 'EMA',
                'reward_status' => 'credited_to_unclaim_ema',
                'already_rewarded' => false,
            ];

            if (function_exists('storage_activation_credit_ema_reward')) {
                $reward = storage_activation_credit_ema_reward($userId, $activationRef, $txHash);
            }

            if ($startedTxn && $pdo->inTransaction()) {
                $pdo->commit();
            }

            activateconfirm_json([
                'ok' => true,
                'code' => 'ACTIVATION_CONFIRMED',
                'message' => 'ACTIVATION_CONFIRMED',
                'verified' => true,
                'is_active' => true,
                'card_active' => 1,
                'reload_enabled' => 1,
                'activation_status' => 'confirmed',
                'activation_ref' => $activationRef,
                'tx_hash' => $txHash,
                'source' => 'force_accept',
                'activation_amount' => '100.000000',
                'activation_token' => 'EMX',
                'free_reward' => 1,
                'success_summary' => 'Card activated and free EMA$ credited.',
                'ema_price_snapshot' => (string)($reward['ema_price_snapshot'] ?? $emaPriceSnapshot),
                'ema_reward' => (string)($reward['ema_reward'] ?? $emaReward),
                'reward_token' => (string)($reward['reward_token'] ?? 'EMA'),
                'reward_status' => (string)($reward['reward_status'] ?? 'credited_to_unclaim_ema'),
                'already_rewarded' => !empty($reward['already_rewarded']),
            ], 200);
        } catch (\Throwable $e) {
            if ($startedTxn && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // Pre-payment / no-match phase = safe warning, not hard fail
    activateconfirm_json([
        'ok' => true,
        'code' => 'NOT_VERIFIED_YET',
        'message' => 'NOT_VERIFIED_YET',
        'verified' => false,
        'is_active' => false,
        'card_active' => 0,
        'reload_enabled' => 0,
        'activation_status' => 'pending',
        'activation_ref' => $activationRef,
        'activation_amount' => '100.000000',
        'activation_token' => 'EMX',
        'ema_price_snapshot' => $emaPriceSnapshot,
        'ema_reward' => $emaReward,
        'reward_token' => 'EMA',
        'reward_status' => 'preview_only_until_confirmed',
        'hint' => 'Send exact 100 EMX with exact activation ref, then re-run verify/confirm.',
    ], 200);

} catch (Throwable $e) {
    activateconfirm_json([
        'ok' => false,
        'error' => 'ACTIVATE_CONFIRM_FAILED',
        'message' => $e->getMessage(),
    ], 500);
}