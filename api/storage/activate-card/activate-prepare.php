<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/storage/activate-card/activate-prepare.php
 * Storage Master v7.7 — Activate Card Prepare
 * FINAL-LOCK-1
 *
 * Locked rules:
 * - exact 100 EMX activation only
 * - ACT ref required
 * - destination match not required at verify / confirm stage
 * - prepare returns frontend-ready TON deeplink / QR payload
 * - free EMA reward is preview only here, credited only at confirm stage
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/api/storage/_bootstrap.php';

if (!defined('ACTIVATE_PREPARE_VERSION')) {
    define('ACTIVATE_PREPARE_VERSION', 'FINAL-LOCK-1');
}

if (!defined('ACTIVATE_PREPARE_FILE')) {
    define('ACTIVATE_PREPARE_FILE', __FILE__);
}

if (!defined('ACTIVATE_PREPARE_TOKEN')) {
    define('ACTIVATE_PREPARE_TOKEN', 'EMX');
}

if (!defined('ACTIVATE_PREPARE_DECIMALS')) {
    define('ACTIVATE_PREPARE_DECIMALS', 9);
}

if (!defined('ACTIVATE_PREPARE_AMOUNT_DISPLAY')) {
    define('ACTIVATE_PREPARE_AMOUNT_DISPLAY', '100.000000000');
}

if (!defined('ACTIVATE_PREPARE_AMOUNT_UNITS')) {
    define('ACTIVATE_PREPARE_AMOUNT_UNITS', '100000000000');
}

if (!function_exists('activate_prepare_send_json')) {
    function activate_prepare_send_json(array $payload, int $status = 200): void
    {
        $payload['_version'] = ACTIVATE_PREPARE_VERSION;
        $payload['_file'] = ACTIVATE_PREPARE_FILE;

        if (function_exists('storage_send_json')) {
            storage_send_json($payload, $status);
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('activate_prepare_json_error')) {
    function activate_prepare_json_error(string $code, int $status = 400, array $extra = []): void
    {
        $payload = array_merge([
            'ok' => false,
            'code' => $code,
            'message' => $code,
        ], $extra);

        activate_prepare_send_json($payload, $status);
    }
}

if (!function_exists('activate_prepare_post')) {
    function activate_prepare_post(string $key, $default = null)
    {
        if (array_key_exists($key, $_POST)) {
            return $_POST[$key];
        }

        static $json = null;
        if ($json === null) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode((string)$raw, true);
            $json = is_array($decoded) ? $decoded : [];
        }

        if (array_key_exists($key, $json)) {
            return $json[$key];
        }

        return $default;
    }
}

if (!function_exists('activate_prepare_require_post')) {
    function activate_prepare_require_post(): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'POST') {
            activate_prepare_json_error('METHOD_NOT_ALLOWED', 405, [
                'message' => 'POST required',
            ]);
        }
    }
}

if (!function_exists('activate_prepare_require_csrf')) {
    function activate_prepare_require_csrf(): void
    {
        $token = trim((string)(
            activate_prepare_post('csrf_token',
            activate_prepare_post('_csrf',
            activate_prepare_post('csrf', '')))
        ));

        $ok = false;

        if (function_exists('csrf_validate')) {
            $ok = (bool)csrf_validate($token);
        } elseif (function_exists('verify_csrf_token')) {
            $ok = (bool)verify_csrf_token($token);
        } elseif (function_exists('poado_csrf_validate')) {
            $ok = (bool)poado_csrf_validate($token);
        } elseif (function_exists('storage_require_csrf')) {
            try {
                storage_require_csrf('storage_activate_card');
                $ok = true;
            } catch (\Throwable $e) {
                $ok = false;
            }
        } else {
            $sessionToken = (string)($_SESSION['csrf_token'] ?? $_SESSION['csrf'] ?? '');
            $ok = ($token !== '' && $sessionToken !== '' && hash_equals($sessionToken, $token));
        }

        if (!$ok) {
            activate_prepare_json_error('CSRF_INVALID', 419, [
                'message' => 'Invalid CSRF token',
            ]);
        }
    }
}

if (!function_exists('activate_prepare_require_user')) {
    function activate_prepare_require_user(): array
    {
        if (function_exists('storage_require_user')) {
            $user = storage_require_user();
            if (is_array($user) && (int)($user['id'] ?? 0) > 0) {
                return $user;
            }
        }

        activate_prepare_json_error('AUTH_REQUIRED', 401, [
            'message' => 'Login required',
        ]);
    }
}

if (!function_exists('activate_prepare_require_bound_ton')) {
    function activate_prepare_require_bound_ton(array $user): string
    {
        if (function_exists('storage_require_bound_ton_address')) {
            $addr = trim((string)storage_require_bound_ton_address($user));
            if ($addr !== '') {
                return $addr;
            }
        }

        $addr = trim((string)($user['wallet_address'] ?? $user['ton_address'] ?? ''));
        if ($addr === '') {
            activate_prepare_json_error('TON_NOT_BOUND', 400, [
                'message' => 'Bind TON first',
            ]);
        }

        return $addr;
    }
}

if (!function_exists('activate_prepare_emx_master')) {
    function activate_prepare_emx_master(): string
    {
        foreach ([
            'EMX_MASTER_ADDRESS',
            'RWA_EMX_MASTER_ADDRESS',
            'POADO_EMX_MASTER_ADDRESS',
            'EMX_JETTON_MASTER_RAW',
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

        if (function_exists('rwa_onchain_resolve_token')) {
            try {
                $resolved = rwa_onchain_resolve_token(['token_key' => 'EMX']);
                $master = trim((string)($resolved['jetton_master'] ?? ''));
                if ($master !== '') {
                    return $master;
                }
            } catch (\Throwable $e) {
            }
        }

        if (function_exists('poado_token_registry_all')) {
            try {
                $registry = poado_token_registry_all();
                $master = trim((string)($registry['EMX']['master_raw'] ?? $registry['EMX']['jetton_master'] ?? ''));
                if ($master !== '') {
                    return $master;
                }
            } catch (\Throwable $e) {
            }
        }

        return '';
    }
}

if (!function_exists('activate_prepare_treasury_wallet')) {
    function activate_prepare_treasury_wallet(): string
    {
        foreach ([
            'RWA_TREASURY_TON_ADDRESS',
            'TON_TREASURY_ADDRESS',
            'STORAGE_TREASURY_WALLET',
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

        return '';
    }
}

if (!function_exists('activate_prepare_make_ref')) {
    function activate_prepare_make_ref(): string
    {
        try {
            $rand = strtoupper(bin2hex(random_bytes(4)));
        } catch (\Throwable $e) {
            $rand = strtoupper(substr(md5((string)microtime(true)), 0, 8));
        }

        return 'ACT-' . gmdate('YmdHis') . '-' . $rand;
    }
}

if (!function_exists('activate_prepare_normalize_card_number')) {
    function activate_prepare_normalize_card_number(string $v): string
    {
        return preg_replace('/\D+/', '', $v) ?? '';
    }
}

if (!function_exists('activate_prepare_require_card')) {
    function activate_prepare_require_card(int $userId): array
    {
        if (function_exists('storage_card_bound_for_user') && !storage_card_bound_for_user($userId)) {
            activate_prepare_json_error('CARD_NOT_BOUND', 422, [
                'message' => 'Bind card first',
            ]);
        }

        if (function_exists('storage_require_bound_card')) {
            $card = storage_require_bound_card($userId);
            if (is_array($card) && !empty($card)) {
                return $card;
            }
        }

        if (function_exists('storage_card_row')) {
            $card = storage_card_row($userId);
            if (is_array($card) && !empty($card)) {
                return $card;
            }
        }

        activate_prepare_json_error('CARD_NOT_BOUND', 422, [
            'message' => 'Bound card not found',
        ]);
    }
}

if (!function_exists('activate_prepare_card_number_from_row')) {
    function activate_prepare_card_number_from_row(array $card): string
    {
        foreach (['card_number', 'card_no', 'number', 'storage_card_number'] as $k) {
            $v = activate_prepare_normalize_card_number((string)($card[$k] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        return '';
    }
}

if (!function_exists('activate_prepare_card_is_active')) {
    function activate_prepare_card_is_active(array $card): bool
    {
        if (function_exists('storage_card_is_active')) {
            try {
                return (bool)storage_card_is_active($card);
            } catch (\Throwable $e) {
            }
        }

        foreach (['is_active', 'card_active', 'is_card_active'] as $k) {
            if (array_key_exists($k, $card)) {
                $v = $card[$k];
                if (is_bool($v)) {
                    return $v;
                }
                if (is_numeric($v)) {
                    return ((int)$v) === 1;
                }
                $s = strtolower(trim((string)$v));
                if (in_array($s, ['1', 'true', 'yes', 'active'], true)) {
                    return true;
                }
            }
        }

        return false;
    }
}

if (!function_exists('activate_prepare_ema_price_snapshot')) {
    function activate_prepare_ema_price_snapshot(): string
    {
        if (function_exists('storage_activation_ema_price_snapshot')) {
            try {
                $v = (string)storage_activation_ema_price_snapshot();
                if ($v !== '') {
                    return $v;
                }
            } catch (\Throwable $e) {
            }
        }

        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $urls = [];

        if ($host !== '') {
            $urls[] = $scheme . '://' . $host . '/rwa/api/global/ema-price.php';
        }
        $urls[] = 'http://127.0.0.1/rwa/api/global/ema-price.php';

        foreach ($urls as $url) {
            $ctx = stream_context_create([
                'http' => ['timeout' => 6, 'ignore_errors' => true],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);

            $raw = @file_get_contents($url, false, $ctx);
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }

            $json = json_decode($raw, true);
            if (!is_array($json)) {
                continue;
            }

            foreach ([
                $json['price'] ?? null,
                $json['ema_price'] ?? null,
                $json['data']['price'] ?? null,
                $json['data']['ema_price'] ?? null,
            ] as $candidate) {
                if (is_numeric($candidate) && (float)$candidate > 0) {
                    return number_format((float)$candidate, 9, '.', '');
                }
            }
        }

        return '';
    }
}

if (!function_exists('activate_prepare_reward_amount')) {
    function activate_prepare_reward_amount(string $emaPrice): string
    {
        if ($emaPrice === '' || !is_numeric($emaPrice) || (float)$emaPrice <= 0) {
            return '';
        }

        if (function_exists('storage_activation_reward_amount')) {
            try {
                $v = (string)storage_activation_reward_amount($emaPrice);
                if ($v !== '') {
                    return $v;
                }
            } catch (\Throwable $e) {
            }
        }

        if (function_exists('bcdiv')) {
            return bcdiv('100', $emaPrice, 9);
        }

        return number_format(100 / (float)$emaPrice, 9, '.', '');
    }
}

if (!function_exists('activate_prepare_store_pending')) {
    function activate_prepare_store_pending(int $userId, array $payload): void
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

try {
    if (function_exists('storage_assert_ready')) {
        storage_assert_ready();
    }

    activate_prepare_require_post();
    activate_prepare_require_csrf();

    $user = activate_prepare_require_user();
    $userId = (int)($user['id'] ?? 0);
    $walletAddress = activate_prepare_require_bound_ton($user);
    $card = activate_prepare_require_card($userId);
    $cardNumber = activate_prepare_card_number_from_row($card);

    if ($cardNumber === '' || !preg_match('/^\d{16}$/', $cardNumber)) {
        activate_prepare_json_error('CARD_NUMBER_INVALID', 422, [
            'message' => 'Bound 16-digit card number required',
        ]);
    }

    if (activate_prepare_card_is_active($card)) {
        $rewardPreviewPrice = activate_prepare_ema_price_snapshot();
        $rewardPreviewAmount = activate_prepare_reward_amount($rewardPreviewPrice);

        activate_prepare_send_json([
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

    $activationRef = activate_prepare_make_ref();
    $treasury = activate_prepare_treasury_wallet();
    $jettonMaster = activate_prepare_emx_master();

    if ($treasury === '') {
        activate_prepare_json_error('TREASURY_NOT_CONFIGURED', 500, [
            'message' => 'Treasury wallet not configured',
        ]);
    }

    if ($jettonMaster === '') {
        activate_prepare_json_error('EMX_MASTER_NOT_CONFIGURED', 500, [
            'message' => 'EMX jetton master not configured',
        ]);
    }

    $tonTransferUri = 'ton://transfer/' . rawurlencode($treasury)
        . '?jetton=' . rawurlencode($jettonMaster)
        . '&amount=' . rawurlencode(ACTIVATE_PREPARE_AMOUNT_UNITS)
        . '&text=' . rawurlencode($activationRef);

    $payload = [
        'activation_ref' => $activationRef,
        'user_id' => $userId,
        'wallet_address' => $walletAddress,
        'card_number' => $cardNumber,
        'treasury' => $treasury,
        'token' => ACTIVATE_PREPARE_TOKEN,
        'jetton_master' => $jettonMaster,
        'required_emx' => ACTIVATE_PREPARE_AMOUNT_DISPLAY,
        'required_amount_display' => ACTIVATE_PREPARE_AMOUNT_DISPLAY,
        'required_units' => ACTIVATE_PREPARE_AMOUNT_UNITS,
        'required_amount_units' => ACTIVATE_PREPARE_AMOUNT_UNITS,
        'decimals' => ACTIVATE_PREPARE_DECIMALS,
        'text' => $activationRef,
        'memo' => $activationRef,
        'ton_transfer_uri' => $tonTransferUri,
        'verified' => false,
        'is_active' => false,
        'reward_token' => 'EMA',
        'reward_status' => 'preview_only_until_confirmed',
        'created_at_utc' => gmdate('c'),
        'payment_rule' => 'EMX_ONLY_EXACT_100',
    ];

    activate_prepare_store_pending($userId, $payload);

    $emaPriceSnapshot = activate_prepare_ema_price_snapshot();
    $emaReward = activate_prepare_reward_amount($emaPriceSnapshot);

    if (function_exists('storage_history_record')) {
        try {
            storage_history_record($userId, 'activate_prepare', 'EMX', '100.000000', [
                'activation_ref' => $activationRef,
                'status' => 'pending_payment',
                'required_emx' => '100.000000',
                'required_units' => ACTIVATE_PREPARE_AMOUNT_UNITS,
                'payment_token' => 'EMX',
                'treasury_address' => $treasury,
                'wallet_address' => $walletAddress,
                'verified' => false,
                'payment_rule' => 'EMX_ONLY_EXACT_100',
                'jetton_master' => $jettonMaster,
                'memo_text' => $activationRef,
                'ton_transfer_uri' => $tonTransferUri,
                'ema_price_snapshot' => $emaPriceSnapshot,
                'ema_reward_preview' => $emaReward,
                'reward_token' => 'EMA',
                'source' => 'activate_prepare',
                'card_number' => $cardNumber,
            ]);
        } catch (\Throwable $e) {
        }
    }

    activate_prepare_send_json([
        'ok' => true,
        'code' => 'ACTIVATION_PREPARED',
        'message' => 'ACTIVATE_PREPARED',
        'action' => 'ACTIVATE_PREPARE',
        'activation_ref' => $activationRef,
        'card_number' => $cardNumber,
        'required_emx' => '100.000000',
        'required_units' => ACTIVATE_PREPARE_AMOUNT_UNITS,
        'required_amount_display' => ACTIVATE_PREPARE_AMOUNT_DISPLAY,
        'required_amount_units' => ACTIVATE_PREPARE_AMOUNT_UNITS,
        'activation_amount' => '100.000000',
        'activation_token' => 'EMX',
        'token' => 'EMX',
        'token_key' => 'EMX',
        'decimals' => ACTIVATE_PREPARE_DECIMALS,
        'treasury' => $treasury,
        'treasury_address' => $treasury,
        'wallet_address' => $walletAddress,
        'verified' => false,
        'is_active' => false,
        'payment_rule' => 'EMX_ONLY_EXACT_100',
        'jetton_master' => $jettonMaster,
        'jetton_master_raw' => $jettonMaster,
        'text' => $activationRef,
        'memo' => $activationRef,
        'memo_text' => $activationRef,
        'payment_qr_text' => $tonTransferUri,
        'payment_qr_payload' => $tonTransferUri,
        'qr_text' => $tonTransferUri,
        'deeplink' => $tonTransferUri,
        'ton_transfer_uri' => $tonTransferUri,
        'free_reward' => 1,
        'ema_price_snapshot' => $emaPriceSnapshot,
        'ema_reward' => $emaReward,
        'reward_token' => 'EMA',
        'reward_status' => 'preview_only_until_confirmed',
        'success_summary' => 'Prepare completed. Free EMA$ will be credited after successful activation.',
        'card' => $card,
    ], 200);

} catch (\Throwable $e) {
    activate_prepare_json_error('ACTIVATE_PREPARE_FAILED', 500, [
        'message' => $e->getMessage(),
    ]);
}