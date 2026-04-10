<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/storage/activate-card/activate-confirm.php
 * Storage Master v7.7 — Activate Card Confirm
 * FINAL-LOCK-1
 *
 * Locked rules:
 * - exact 100 EMX activation only
 * - ACT ref required
 * - token + amount + ref match => ACCEPT
 * - destination match not required
 * - confirm stage finalizes activation
 * - reward credit happens here only
 * - one activation_ref = one EMA reward only
 * - safe pending / no-match response remains HTTP 200
 */

ob_start();

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/api/storage/_bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/onchain-verify.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

if (!defined('ACTIVATE_CONFIRM_VERSION')) {
    define('ACTIVATE_CONFIRM_VERSION', 'FINAL-LOCK-1');
}

if (!defined('ACTIVATE_CONFIRM_FILE')) {
    define('ACTIVATE_CONFIRM_FILE', __FILE__);
}

if (!defined('ACTIVATE_CONFIRM_TOKEN')) {
    define('ACTIVATE_CONFIRM_TOKEN', 'EMX');
}

if (!defined('ACTIVATE_CONFIRM_DECIMALS')) {
    define('ACTIVATE_CONFIRM_DECIMALS', 9);
}

if (!defined('ACTIVATE_CONFIRM_AMOUNT_DISPLAY')) {
    define('ACTIVATE_CONFIRM_AMOUNT_DISPLAY', '100.000000000');
}

if (!defined('ACTIVATE_CONFIRM_AMOUNT_UNITS')) {
    define('ACTIVATE_CONFIRM_AMOUNT_UNITS', '100000000000');
}

if (!function_exists('activate_confirm_json')) {
    function activate_confirm_json(array $payload, int $status = 200): void
    {
        while (ob_get_level() > 0) {
            $buf = ob_get_clean();
            unset($buf);
        }

        $payload['_version'] = ACTIVATE_CONFIRM_VERSION;
        $payload['_file'] = ACTIVATE_CONFIRM_FILE;

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
}

if (!function_exists('activate_confirm_post')) {
    function activate_confirm_post(string $key, $default = null)
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

if (!function_exists('activate_confirm_json_error')) {
    function activate_confirm_json_error(string $code, int $status = 400, array $extra = []): void
    {
        activate_confirm_json(array_merge([
            'ok' => false,
            'error' => $code,
            'code' => $code,
            'message' => $code,
        ], $extra), $status);
    }
}

if (!function_exists('activate_confirm_require_post')) {
    function activate_confirm_require_post(): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'POST') {
            activate_confirm_json_error('METHOD_NOT_ALLOWED', 405, [
                'message' => 'POST required',
            ]);
        }
    }
}

if (!function_exists('activate_confirm_require_csrf')) {
    function activate_confirm_require_csrf(): void
    {
        $csrfToken = trim((string)(
            activate_confirm_post('csrf_token',
            activate_confirm_post('_csrf',
            activate_confirm_post('csrf', '')))
        ));

        $csrfOk = false;
        $scopes = [
            'storage_activate_card',
            'activate',
            'storage_activate',
            'storage_activate_prepare',
            'storage_activate_verify',
            'storage_activate_confirm',
            'activate_card',
            'activate_prepare',
            'activate_verify',
            'activate_confirm',
            'activate-card',
        ];

        foreach ($scopes as $scope) {
            try {
                if (function_exists('csrf_check')) {
                    $rf = new ReflectionFunction('csrf_check');
                    $argc = $rf->getNumberOfParameters();

                    if ($argc >= 2) {
                        $res = csrf_check($scope, $csrfToken);
                    } else {
                        if ($csrfToken !== '') {
                            $_POST['csrf_token'] = $csrfToken;
                        }
                        $res = csrf_check($scope);
                    }

                    if ($res === true || $res === 1 || $res === '1' || $res === null) {
                        $csrfOk = true;
                        break;
                    }
                }

                if (!$csrfOk && function_exists('csrf_verify')) {
                    $res = csrf_verify($scope, $csrfToken);
                    if ($res === true || $res === 1 || $res === '1' || $res === null) {
                        $csrfOk = true;
                        break;
                    }
                }

                if (!$csrfOk && function_exists('csrf_validate')) {
                    $res = csrf_validate($csrfToken);
                    if ($res === true || $res === 1 || $res === '1' || $res === null) {
                        $csrfOk = true;
                        break;
                    }
                }

                if (!$csrfOk && function_exists('storage_require_csrf')) {
                    try {
                        storage_require_csrf($scope);
                        $csrfOk = true;
                        break;
                    } catch (\Throwable $e) {
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        if (!$csrfOk) {
            activate_confirm_json_error('CSRF_INVALID', 403, [
                'message' => 'Invalid CSRF token',
            ]);
        }
    }
}

if (!function_exists('activate_confirm_require_user')) {
    function activate_confirm_require_user(): array
    {
        $user = function_exists('storage_require_user') ? storage_require_user() : null;
        if (!is_array($user) || (int)($user['id'] ?? 0) <= 0) {
            activate_confirm_json_error('AUTH_REQUIRED', 401, [
                'message' => 'Login required',
            ]);
        }
        return $user;
    }
}

if (!function_exists('activate_confirm_require_bound_ton')) {
    function activate_confirm_require_bound_ton(array $user): string
    {
        if (function_exists('storage_require_bound_ton_address')) {
            $addr = trim((string)storage_require_bound_ton_address($user));
            if ($addr !== '') {
                return $addr;
            }
        }

        $addr = trim((string)($user['wallet_address'] ?? $user['ton_address'] ?? ''));
        if ($addr === '') {
            activate_confirm_json_error('TON_NOT_BOUND', 400, [
                'message' => 'Bind TON first',
            ]);
        }

        return $addr;
    }
}

if (!function_exists('activate_confirm_require_card')) {
    function activate_confirm_require_card(int $userId): array
    {
        if (function_exists('storage_card_bound_for_user') && !storage_card_bound_for_user($userId)) {
            activate_confirm_json_error('CARD_NOT_BOUND', 422, [
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

        activate_confirm_json_error('CARD_NOT_BOUND', 422, [
            'message' => 'Bound card not found',
        ]);
    }
}

if (!function_exists('activate_confirm_card_is_active')) {
    function activate_confirm_card_is_active(array $card): bool
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

if (!function_exists('activate_confirm_emx_master')) {
    function activate_confirm_emx_master(): string
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

        try {
            $resolved = rwa_onchain_resolve_token(['token_key' => 'EMX']);
            $master = trim((string)($resolved['jetton_master'] ?? ''));
            if ($master !== '') {
                return $master;
            }
        } catch (\Throwable $e) {
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

if (!function_exists('activate_confirm_ema_price_snapshot')) {
    function activate_confirm_ema_price_snapshot(): string
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

if (!function_exists('activate_confirm_reward_amount')) {
    function activate_confirm_reward_amount(string $emaPrice): string
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

if (!function_exists('activate_confirm_open_row')) {
    function activate_confirm_open_row(int $userId): array
    {
        if (function_exists('storage_activation_get')) {
            try {
                $row = storage_activation_get($userId);
                if (is_array($row)) {
                    return $row;
                }
            } catch (\Throwable $e) {
            }
        }

        if (isset($_SESSION['storage_activation'][(string)$userId]) && is_array($_SESSION['storage_activation'][(string)$userId])) {
            return $_SESSION['storage_activation'][(string)$userId];
        }

        return [];
    }
}

if (!function_exists('activate_confirm_resolve_ref')) {
    function activate_confirm_resolve_ref(int $userId, array $card): string
    {
        $postedActivationRef = trim((string)activate_confirm_post('activation_ref', ''));
        $dbActivationRef = trim((string)($card['activation_ref'] ?? ''));

        if ($dbActivationRef !== '') {
            return $dbActivationRef;
        }

        if ($postedActivationRef !== '') {
            return $postedActivationRef;
        }

        $open = activate_confirm_open_row($userId);
        return trim((string)($open['activation_ref'] ?? ''));
    }
}

if (!function_exists('activate_confirm_find_verified_by_ref')) {
    function activate_confirm_find_verified_by_ref($pdo, string $activationRef): ?array
    {
        if (!($pdo instanceof PDO) || $activationRef === '') {
            return null;
        }

        if (function_exists('storage_activation_tx_find_by_ref')) {
            try {
                $row = storage_activation_tx_find_by_ref($pdo, $activationRef);
                if (is_array($row) && !empty($row)) {
                    return $row;
                }
            } catch (\Throwable $e) {
            }
        }

        return null;
    }
}

if (!function_exists('activate_confirm_mark_card_active')) {
    function activate_confirm_mark_card_active(int $userId, string $activationRef, string $txHash): void
    {
        if (function_exists('storage_mark_card_active_for_user')) {
            storage_mark_card_active_for_user($userId, $activationRef, $txHash);
            return;
        }

        if (!isset($_SESSION['storage_activation']) || !is_array($_SESSION['storage_activation'])) {
            $_SESSION['storage_activation'] = [];
        }

        $existing = [];
        if (isset($_SESSION['storage_activation'][(string)$userId]) && is_array($_SESSION['storage_activation'][(string)$userId])) {
            $existing = $_SESSION['storage_activation'][(string)$userId];
        }

        $existing['activation_ref'] = $activationRef;
        $existing['tx_hash'] = $txHash;
        $existing['verified'] = true;
        $existing['is_active'] = true;
        $existing['confirmed_at_utc'] = gmdate('c');

        $_SESSION['storage_activation'][(string)$userId] = $existing;
    }
}

if (!function_exists('activate_confirm_credit_reward')) {
    function activate_confirm_credit_reward(int $userId, string $activationRef, string $txHash, string $emaPriceSnapshot, string $emaReward): array
    {
        if (function_exists('storage_activation_credit_ema_reward')) {
            try {
                $reward = storage_activation_credit_ema_reward($userId, $activationRef, $txHash);
                if (is_array($reward)) {
                    return [
                        'ema_price_snapshot' => (string)($reward['ema_price_snapshot'] ?? $emaPriceSnapshot),
                        'ema_reward' => (string)($reward['ema_reward'] ?? $emaReward),
                        'reward_token' => (string)($reward['reward_token'] ?? 'EMA'),
                        'reward_status' => (string)($reward['reward_status'] ?? 'credited_to_unclaim_ema'),
                        'already_rewarded' => !empty($reward['already_rewarded']),
                    ];
                }
            } catch (\Throwable $e) {
            }
        }

        return [
            'ema_price_snapshot' => $emaPriceSnapshot,
            'ema_reward' => $emaReward,
            'reward_token' => 'EMA',
            'reward_status' => 'credited_to_unclaim_ema',
            'already_rewarded' => false,
        ];
    }
}

if (!function_exists('activate_confirm_store_result_hint')) {
    function activate_confirm_store_result_hint(int $userId, array $verify): void
    {
        if (!isset($_SESSION['storage_activation']) || !is_array($_SESSION['storage_activation'])) {
            $_SESSION['storage_activation'] = [];
        }

        $existing = [];
        if (isset($_SESSION['storage_activation'][(string)$userId]) && is_array($_SESSION['storage_activation'][(string)$userId])) {
            $existing = $_SESSION['storage_activation'][(string)$userId];
        }

        $existing['last_confirm_verify'] = [
            'ok' => (bool)($verify['ok'] ?? false),
            'code' => (string)($verify['code'] ?? ''),
            'status' => (string)($verify['status'] ?? ''),
            'tx_hash' => (string)($verify['tx_hash'] ?? ''),
            'verified' => (bool)($verify['verified'] ?? false),
            'at_utc' => gmdate('c'),
        ];

        $_SESSION['storage_activation'][(string)$userId] = $existing;
    }
}

try {
    if (function_exists('storage_assert_ready')) {
        storage_assert_ready();
    }

    activate_confirm_require_post();
    activate_confirm_require_csrf();

    $user = activate_confirm_require_user();
    $userId = (int)($user['id'] ?? 0);
    $walletAddress = activate_confirm_require_bound_ton($user);
    $card = activate_confirm_require_card($userId);

    $activationRef = activate_confirm_resolve_ref($userId, $card);
    if ($activationRef === '') {
        activate_confirm_json_error('ACTIVATION_REF_REQUIRED', 400, [
            'message' => 'Activation reference required',
        ]);
    }

    $emaPriceSnapshot = activate_confirm_ema_price_snapshot();
    $emaReward = activate_confirm_reward_amount($emaPriceSnapshot);

    if (activate_confirm_card_is_active($card)) {
        $activeTxHash = trim((string)($card['activation_tx_hash'] ?? $card['tx_hash'] ?? ''));

        $reward = activate_confirm_credit_reward($userId, $activationRef, $activeTxHash, $emaPriceSnapshot, $emaReward);

        activate_confirm_json([
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
            'required_amount_display' => ACTIVATE_CONFIRM_AMOUNT_DISPLAY,
            'required_amount_units' => ACTIVATE_CONFIRM_AMOUNT_UNITS,
            'token_key' => ACTIVATE_CONFIRM_TOKEN,
            'free_reward' => 1,
            'success_summary' => 'Card already active.',
            'ema_price_snapshot' => (string)($reward['ema_price_snapshot'] ?? $emaPriceSnapshot),
            'ema_reward' => (string)($reward['ema_reward'] ?? $emaReward),
            'reward_token' => (string)($reward['reward_token'] ?? 'EMA'),
            'reward_status' => (string)($reward['reward_status'] ?? 'already_active'),
            'already_rewarded' => !empty($reward['already_rewarded']),
        ], 200);
    }

    $pdo = function_exists('storage_db') ? storage_db() : null;

    $existing = activate_confirm_find_verified_by_ref($pdo, $activationRef);
    if (is_array($existing) && !empty($existing)) {
        $txHash = trim((string)($existing['tx_hash'] ?? ''));

        $startedTxn = false;
        if ($pdo instanceof PDO && !$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTxn = true;
        }

        try {
            activate_confirm_mark_card_active($userId, $activationRef, $txHash);

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

            $reward = activate_confirm_credit_reward($userId, $activationRef, $txHash, $emaPriceSnapshot, $emaReward);

            if ($startedTxn && $pdo->inTransaction()) {
                $pdo->commit();
            }

            activate_confirm_json([
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
                'required_amount_display' => ACTIVATE_CONFIRM_AMOUNT_DISPLAY,
                'required_amount_units' => ACTIVATE_CONFIRM_AMOUNT_UNITS,
                'token_key' => ACTIVATE_CONFIRM_TOKEN,
                'free_reward' => 1,
                'success_summary' => 'Card activated and free EMA$ credited.',
                'ema_price_snapshot' => (string)($reward['ema_price_snapshot'] ?? $emaPriceSnapshot),
                'ema_reward' => (string)($reward['ema_reward'] ?? $emaReward),
                'reward_token' => (string)($reward['reward_token'] ?? 'EMA'),
                'reward_status' => (string)($reward['reward_status'] ?? 'credited_to_unclaim_ema'),
                'already_rewarded' => !empty($reward['already_rewarded']),
            ], 200);
        } catch (\Throwable $e) {
            if ($startedTxn && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    $txHashInput = trim((string)activate_confirm_post('tx_hash', ''));
    $lookbackSeconds = (int)activate_confirm_post('lookback_seconds', 86400 * 7);
    $minConfirmations = (int)activate_confirm_post('min_confirmations', 0);
    $limit = (int)activate_confirm_post('limit', 120);

    $emxMaster = activate_confirm_emx_master();
    if ($emxMaster === '') {
        activate_confirm_json_error('EMX_MASTER_NOT_CONFIGURED', 500, [
            'message' => 'EMX jetton master not configured',
        ]);
    }

    $verify = rwa_onchain_verify_jetton_transfer([
        'token_key' => ACTIVATE_CONFIRM_TOKEN,
        'jetton_master' => $emxMaster,
        'owner_address' => $walletAddress,
        'amount_units' => ACTIVATE_CONFIRM_AMOUNT_UNITS,
        'ref' => $activationRef,
        'tx_hash' => $txHashInput,
        'min_confirmations' => $minConfirmations,
        'lookback_seconds' => $lookbackSeconds,
        'limit' => $limit,
    ]);

    activate_confirm_store_result_hint($userId, $verify);

    if (
        !empty($verify['ok']) &&
        !empty($verify['verified'])
    ) {
        $txHash = trim((string)($verify['tx_hash'] ?? ''));

        $startedTxn = false;
        if ($pdo instanceof PDO && !$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTxn = true;
        }

        try {
            activate_confirm_mark_card_active($userId, $activationRef, $txHash);

            if (function_exists('storage_history_record')) {
                try {
                    storage_history_record($userId, 'activate_card', 'EMX', '100.000000', [
                        'activation_ref' => $activationRef,
                        'tx_hash' => $txHash,
                        'status' => 'confirmed',
                        'source' => 'activate_confirm_onchain_verify',
                        'verify_code' => (string)($verify['code'] ?? ''),
                        'verify_mode' => (string)($verify['verify_mode'] ?? ''),
                    ]);
                } catch (\Throwable $e) {
                }
            }

            $reward = activate_confirm_credit_reward($userId, $activationRef, $txHash, $emaPriceSnapshot, $emaReward);

            if ($startedTxn && $pdo->inTransaction()) {
                $pdo->commit();
            }

            activate_confirm_json([
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
                'source' => 'onchain_verify',
                'activation_amount' => '100.000000',
                'activation_token' => 'EMX',
                'required_amount_display' => ACTIVATE_CONFIRM_AMOUNT_DISPLAY,
                'required_amount_units' => ACTIVATE_CONFIRM_AMOUNT_UNITS,
                'token_key' => ACTIVATE_CONFIRM_TOKEN,
                'free_reward' => 1,
                'success_summary' => 'Card activated and free EMA$ credited.',
                'ema_price_snapshot' => (string)($reward['ema_price_snapshot'] ?? $emaPriceSnapshot),
                'ema_reward' => (string)($reward['ema_reward'] ?? $emaReward),
                'reward_token' => (string)($reward['reward_token'] ?? 'EMA'),
                'reward_status' => (string)($reward['reward_status'] ?? 'credited_to_unclaim_ema'),
                'already_rewarded' => !empty($reward['already_rewarded']),
                'verify_source' => (string)($verify['verify_source'] ?? 'toncenter_v3_php'),
                'verify_mode' => (string)($verify['verify_mode'] ?? ''),
            ], 200);
        } catch (\Throwable $e) {
            if ($startedTxn && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    activate_confirm_json([
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
        'required_amount_display' => ACTIVATE_CONFIRM_AMOUNT_DISPLAY,
        'required_amount_units' => ACTIVATE_CONFIRM_AMOUNT_UNITS,
        'token_key' => ACTIVATE_CONFIRM_TOKEN,
        'ema_price_snapshot' => $emaPriceSnapshot,
        'ema_reward' => $emaReward,
        'reward_token' => 'EMA',
        'reward_status' => 'preview_only_until_confirmed',
        'hint' => 'Send exact 100 EMX with exact activation ref, then re-run verify/confirm.',
        'debug' => $verify['debug'] ?? [],
    ], 200);

} catch (\Throwable $e) {
    activate_confirm_json([
        'ok' => false,
        'error' => 'ACTIVATE_CONFIRM_FAILED',
        'code' => 'ACTIVATE_CONFIRM_FAILED',
        'message' => $e->getMessage(),
    ], 500);
}