<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/storage/activate-prepare.php
 * AdoptGold / POAdo — Storage Activate Prepare
 * Version: v7.4b-final-activation-reward-ui-ready-20260320
 *
 * Locked rules preserved:
 * - exact 100 EMX activation only
 * - no destination-match requirement later at verify/confirm stage
 * - keeps previous prepare flow and response shape
 * - adds v7.4b reward preview fields for frontend
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/api/storage/_bootstrap.php';

try {
    storage_require_post();
    storage_assert_ready();
    storage_require_csrf('activate');

    $user = storage_require_user();
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        storage_json_error('AUTH_REQUIRED', 401);
    }

    $walletAddress = storage_require_bound_ton_address($user);

    if (!storage_card_bound_for_user($userId)) {
        storage_json_error('CARD_NOT_BOUND', 422);
    }

    $card = storage_require_bound_card($userId);

    if (storage_card_is_active($card)) {
        $rewardPreviewPrice = '';
        $rewardPreviewAmount = '';

        try {
            if (function_exists('storage_activation_ema_price_snapshot')) {
                $rewardPreviewPrice = (string)storage_activation_ema_price_snapshot();
            }
            if ($rewardPreviewPrice !== '' && function_exists('storage_activation_reward_amount')) {
                $rewardPreviewAmount = (string)storage_activation_reward_amount($rewardPreviewPrice);
            }
        } catch (\Throwable $e) {
            $rewardPreviewPrice = '';
            $rewardPreviewAmount = '';
        }

        storage_send_json([
            'ok' => true,
            'code' => 'ALREADY_ACTIVE',
            'message' => 'CARD_ALREADY_ACTIVE',
            'action' => 'ACTIVATE_PREPARE',
            'verified' => true,
            'is_active' => true,
            'free_reward' => 1,
            'ema_price_snapshot' => $rewardPreviewPrice,
            'ema_reward' => $rewardPreviewAmount,
            'reward_token' => 'EMA',
            'reward_status' => 'not_applicable_card_already_active',
            'success_summary' => 'Card already active.',
            'card' => $card,
        ], 200);
    }

    $payload = storage_activation_prepare_payload($userId);

    $activationRef = (string)($payload['activation_ref'] ?? '');
    $treasury = (string)($payload['treasury'] ?? '');
    $jettonMaster = (string)($payload['jetton_master'] ?? '');
    $text = (string)($payload['text'] ?? '');
    $tonTransferUri = (string)($payload['ton_transfer_uri'] ?? '');

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

    if (function_exists('storage_history_record')) {
        try {
            storage_history_record($userId, 'activate_prepare', 'EMX', '100.000000', [
                'activation_ref' => $activationRef,
                'status' => 'pending_payment',
                'required_emx' => '100.000000',
                'required_units' => STORAGE_ACTIVATE_UNITS,
                'payment_token' => 'EMX',
                'treasury_address' => $treasury,
                'wallet_address' => $walletAddress,
                'verified' => false,
                'payment_rule' => 'EMX_ONLY_EXACT_100',
                'jetton_master' => $jettonMaster,
                'memo_text' => $text,
                'ton_transfer_uri' => $tonTransferUri,
                'ema_price_snapshot' => $emaPriceSnapshot,
                'ema_reward_preview' => $emaReward,
                'reward_token' => 'EMA',
                'source' => 'activate_prepare',
            ]);
        } catch (\Throwable $e) {
        }
    }

    storage_send_json([
        'ok' => true,
        'code' => 'ACTIVATION_PREPARED',
        'message' => 'ACTIVATE_PREPARED',
        'action' => 'ACTIVATE_PREPARE',
        'activation_ref' => $activationRef,
        'required_emx' => '100.000000',
        'required_units' => STORAGE_ACTIVATE_UNITS,
        'activation_amount' => '100.000000',
        'activation_token' => 'EMX',
        'token' => 'EMX',
        'decimals' => 9,
        'treasury' => $treasury,
        'wallet_address' => $walletAddress,
        'verified' => false,
        'is_active' => false,
        'payment_rule' => 'EMX_ONLY_EXACT_100',
        'jetton_master' => $jettonMaster,
        'text' => $text,
        'memo' => $text,
        'payment_qr_text' => $tonTransferUri !== '' ? $tonTransferUri : $text,
        'payment_qr_payload' => $tonTransferUri !== '' ? $tonTransferUri : $text,
        'ton_transfer_uri' => $tonTransferUri,
        'free_reward' => 1,
        'ema_price_snapshot' => $emaPriceSnapshot,
        'ema_reward' => $emaReward,
        'reward_token' => 'EMA',
        'reward_status' => 'preview_only_until_confirmed',
        'success_summary' => 'Prepare completed. Free EMA$ will be credited after successful activation.',
        'card' => storage_card_row($userId),
    ], 200);

} catch (\Throwable $e) {
    storage_json_error('ACTIVATE_PREPARE_FAILED', 500, [
        'message' => $e->getMessage(),
    ]);
}