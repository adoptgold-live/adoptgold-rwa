<?php
declare(strict_types=1);

/**
 * /rwa/api/storage/activate.php
 * Storage Activation API (compat wrapper)
 * Version: v7.2.0-wrapper-final-20260319
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/api/storage/_bootstrap.php';

const STORAGE_ACTIVATION_TOKEN = 'EMX';
const STORAGE_ACTIVATION_DECIMALS = 9;
const STORAGE_ACTIVATION_REQUIRED_EMX = '100.000000';
const STORAGE_ACTIVATION_REQUIRED_UNITS = '100000000000'; // 100 * 10^9
const STORAGE_TREASURY_WALLET = 'UQBdYfGArtoCUmBs5TjYQtfPFfQuGC2Ydbj2pQr3zIlNrDta';

if (!function_exists('storage_activate_emx_master')) {
    function storage_activate_emx_master(): string
    {
        foreach ([
            'EMX_MASTER_ADDRESS',
            'RWA_EMX_MASTER_ADDRESS',
            'POADO_EMX_MASTER_ADDRESS'
        ] as $k) {
            $v = getenv($k);
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
            if (defined($k)) {
                $c = constant($k);
                if (is_string($c) && trim($c) !== '') {
                    return trim($c);
                }
            }
        }

        if (function_exists('poado_token_registry_all')) {
            try {
                $registry = poado_token_registry_all();
                $master = (string)($registry['EMX']['master_raw'] ?? '');
                if ($master !== '') {
                    return $master;
                }
            } catch (\Throwable $e) {
            }
        }

        return '';
    }
}

if (!function_exists('storage_activate_make_ref')) {
    function storage_activate_make_ref(): string
    {
        return 'ACT-' . gmdate('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }
}

if (!function_exists('storage_activate_store_pending')) {
    function storage_activate_store_pending(int $userId, array $payload): void
    {
        if (function_exists('storage_activation_set')) {
            storage_activation_set($userId, $payload);
            return;
        }

        if (!isset($_SESSION['storage_activation']) || !is_array($_SESSION['storage_activation'])) {
            $_SESSION['storage_activation'] = [];
        }
        $_SESSION['storage_activation'][(string)$userId] = $payload;
    }
}

storage_assert_ready();
storage_require_post();
storage_require_csrf('storage_activate_card');

$user = storage_require_user();
$userId = (int)($user['id'] ?? 0);

if ($userId <= 0) {
    storage_abort('AUTH_REQUIRED', 401, [
        'message' => 'Login required',
    ]);
}

$walletAddress = trim((string)($user['wallet_address'] ?? ''));
if ($walletAddress === '') {
    storage_abort('TON_NOT_BOUND', 400, [
        'message' => 'Bind TON first',
    ]);
}

if (!storage_card_bound_for_user($userId)) {
    storage_abort('CARD_NOT_BOUND', 400, [
        'message' => 'Bind card first',
    ]);
}

if (storage_card_is_active_for_user($userId)) {
    storage_json_ok([
        'message' => 'CARD_ALREADY_ACTIVE',
        'action' => 'ACTIVATE_PREPARE',
        'is_active' => true,
        'verified' => true,
    ], 200);
}

$activationRef = storage_activate_make_ref();
$emxMaster = storage_activate_emx_master();

$paymentRequest = [
    'network' => 'TON',
    'payment_type' => 'jetton',
    'token' => STORAGE_ACTIVATION_TOKEN,
    'jetton_master' => $emxMaster,
    'sender_bound_wallet' => $walletAddress,
    'receiver_treasury_wallet' => STORAGE_TREASURY_WALLET,
    'amount' => STORAGE_ACTIVATION_REQUIRED_EMX,
    'amount_units' => STORAGE_ACTIVATION_REQUIRED_UNITS,
    'decimals' => STORAGE_ACTIVATION_DECIMALS,
    'memo' => $activationRef,
    'activation_ref' => $activationRef,
    'rule' => 'EMX_ONLY_EXACT_100'
];

$paymentQrText = implode('|', [
    'TOKEN=' . STORAGE_ACTIVATION_TOKEN,
    'AMOUNT=' . STORAGE_ACTIVATION_REQUIRED_EMX,
    'DECIMALS=' . STORAGE_ACTIVATION_DECIMALS,
    'TREASURY=' . STORAGE_TREASURY_WALLET,
    'SENDER=' . $walletAddress,
    'REF=' . $activationRef
]);

$pending = [
    'activation_ref' => $activationRef,
    'user_id' => $userId,
    'wallet_address' => $walletAddress,
    'treasury' => STORAGE_TREASURY_WALLET,
    'token' => STORAGE_ACTIVATION_TOKEN,
    'jetton_master' => $emxMaster,
    'required_emx' => STORAGE_ACTIVATION_REQUIRED_EMX,
    'required_units' => STORAGE_ACTIVATION_REQUIRED_UNITS,
    'decimals' => STORAGE_ACTIVATION_DECIMALS,
    'memo' => $activationRef,
    'verified' => false,
    'is_active' => false,
    'created_at_utc' => gmdate('c')
];

storage_activate_store_pending($userId, $pending);

if (function_exists('storage_history_record')) {
    try {
        storage_history_record($userId, 'activate_prepare', STORAGE_ACTIVATION_TOKEN, STORAGE_ACTIVATION_REQUIRED_EMX, [
            'activation_ref' => $activationRef,
            'status' => 'pending_payment',
            'required_emx' => STORAGE_ACTIVATION_REQUIRED_EMX,
            'required_units' => STORAGE_ACTIVATION_REQUIRED_UNITS,
            'payment_token' => STORAGE_ACTIVATION_TOKEN,
            'treasury_address' => STORAGE_TREASURY_WALLET,
            'wallet_address' => $walletAddress,
            'verified' => false,
            'payment_rule' => 'EMX_ONLY_EXACT_100'
        ]);
    } catch (\Throwable $e) {
    }
}

storage_json_ok([
    'message' => 'ACTIVATE_PREPARED',
    'action' => 'ACTIVATE_PREPARE',
    'activation_ref' => $activationRef,
    'required_emx' => STORAGE_ACTIVATION_REQUIRED_EMX,
    'required_units' => STORAGE_ACTIVATION_REQUIRED_UNITS,
    'token' => STORAGE_ACTIVATION_TOKEN,
    'decimals' => STORAGE_ACTIVATION_DECIMALS,
    'treasury' => STORAGE_TREASURY_WALLET,
    'wallet_address' => $walletAddress,
    'verified' => false,
    'is_active' => false,
    'payment_rule' => 'EMX_ONLY_EXACT_100',
    'payment_request' => $paymentRequest,
    'payment_qr_text' => $paymentQrText,
    'payment_qr_payload' => $paymentQrText
], 200);