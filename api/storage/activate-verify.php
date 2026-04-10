<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/storage/activate-verify.php
 * Storage Master v7.4b
 * Final locked verify + reward version
 *
 * Final locked rule:
 * jetton + amount + ref match => ACCEPT
 * destination match not required
 *
 * v7.4b add-on:
 * - return EMA reward fields on success
 * - one activation_ref = one EMA reward only
 * - keep safe 200 response on pre-payment / no-match
 */

ob_start();

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/api/storage/_bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

function activateverify_json(array $payload, int $status = 200): void
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
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        activateverify_json([
            'ok' => false,
            'error' => 'METHOD_NOT_ALLOWED',
            'message' => 'POST required',
        ], 405);
    }

    // Accept both new and legacy activate scopes
    $csrfToken = isset($_POST['csrf_token']) && !is_array($_POST['csrf_token'])
        ? trim((string)$_POST['csrf_token'])
        : '';

    $csrfOk = false;
    $scopes = [
        'activate',
        'storage_activate_card',
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
        } catch (Throwable $e) {
        }
    }

    if (!$csrfOk) {
        activateverify_json([
            'ok' => false,
            'error' => 'CSRF_INVALID',
            'message' => 'Invalid CSRF token',
        ], 403);
    }

    $user = storage_require_user();
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        activateverify_json([
            'ok' => false,
            'error' => 'AUTH_REQUIRED',
            'message' => 'Login required',
        ], 401);
    }

    storage_require_bound_ton_address($user);
    $card = storage_require_bound_card($userId);

    $emaPriceSnapshot = '';
    $emaReward = '';
    try {
        if (function_exists('storage_activation_ema_price_snapshot')) {
            $emaPriceSnapshot = (string)storage_activation_ema_price_snapshot();
        }
        if ($emaPriceSnapshot !== '' && function_exists('storage_activation_reward_amount')) {
            $emaReward = (string)storage_activation_reward_amount($emaPriceSnapshot);
        }
    } catch (Throwable $e) {
        $emaPriceSnapshot = '';
        $emaReward = '';
    }

    if (storage_card_is_active($card)) {
        $activationRefActive = (string)($card['activation_ref'] ?? '');
        $activeTxHash = (string)($card['activation_tx_hash'] ?? '');

        $reward = [
            'ema_price_snapshot' => $emaPriceSnapshot,
            'ema_reward' => $emaReward,
            'reward_token' => 'EMA',
            'reward_status' => 'already_active',
            'already_rewarded' => false,
        ];

        try {
            if ($activationRefActive !== '' && function_exists('storage_activation_credit_ema_reward')) {
                $reward = storage_activation_credit_ema_reward($userId, $activationRefActive, $activeTxHash);
            }
        } catch (Throwable $e) {
        }

        activateverify_json([
            'ok' => true,
            'code' => 'ALREADY_ACTIVE',
            'message' => 'ALREADY_ACTIVE',
            'activation_ref' => $activationRefActive,
            'tx_hash' => $activeTxHash,
            'verified' => true,
            'is_active' => true,
            'card_active' => 1,
            'reload_enabled' => true,
            'activation_status' => 'confirmed',
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

    // Canonical ref = DB ref first, then posted ref, then open activation row
    $postedActivationRef = isset($_POST['activation_ref']) && !is_array($_POST['activation_ref'])
        ? trim((string)$_POST['activation_ref'])
        : '';

    $dbActivationRef = trim((string)($card['activation_ref'] ?? ''));

    if ($dbActivationRef !== '') {
        $activationRef = $dbActivationRef;
    } elseif ($postedActivationRef !== '') {
        $activationRef = $postedActivationRef;
    } else {
        $open = storage_activation_get($userId);
        $activationRef = trim((string)($open['activation_ref'] ?? ''));
    }

    if ($activationRef === '') {
        activateverify_json([
            'ok' => false,
            'error' => 'ACTIVATION_NOT_FOUND',
            'message' => 'No activation reference found',
        ], 404);
    }

    $txHint = isset($_POST['tx_hash']) && !is_array($_POST['tx_hash'])
        ? trim((string)$_POST['tx_hash'])
        : '';

    $result = storage_verify_emx_activation_auto(
        $userId,
        $activationRef,
        ['tx_hint' => $txHint]
    );

    if (($result['ok'] ?? false) === true) {
        $code = (string)($result['code'] ?? 'OK');

        if (in_array($code, ['ACTIVATION_CONFIRMED', 'ALREADY_VERIFIED', 'ALREADY_ACTIVE'], true)) {
            $txHash = (string)($result['tx_hash'] ?? '');

            $pdo = storage_db();
            $startedTxn = false;

            if ($pdo instanceof PDO && !$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $startedTxn = true;
            }

            try {
                if (function_exists('storage_mark_card_active_for_user')) {
                    storage_mark_card_active_for_user($userId, $activationRef, $txHash);
                }

                if (function_exists('storage_history_record')) {
                    try {
                        storage_history_record($userId, 'activate_card', 'EMX', '100.000000', [
                            'activation_ref' => $activationRef,
                            'tx_hash' => $txHash,
                            'status' => 'verified',
                            'source' => 'activate_verify',
                            'verify_code' => $code,
                        ]);
                    } catch (Throwable $e) {
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

                activateverify_json([
                    'ok' => true,
                    'code' => $code,
                    'message' => $code,
                    'activation_ref' => $activationRef,
                    'tx_hash' => $txHash,
                    'verified' => true,
                    'is_active' => true,
                    'card_active' => 1,
                    'reload_enabled' => true,
                    'activation_status' => 'confirmed',
                    'activation_amount' => '100.000000',
                    'activation_token' => 'EMX',
                    'free_reward' => 1,
                    'success_summary' => 'Card activated and free EMA$ credited.',
                    'ema_price_snapshot' => (string)($reward['ema_price_snapshot'] ?? $emaPriceSnapshot),
                    'ema_reward' => (string)($reward['ema_reward'] ?? $emaReward),
                    'reward_token' => (string)($reward['reward_token'] ?? 'EMA'),
                    'reward_status' => (string)($reward['reward_status'] ?? 'credited_to_unclaim_ema'),
                    'already_rewarded' => !empty($reward['already_rewarded']),
                    'debug' => $result['debug'] ?? [],
                ], 200);
            } catch (Throwable $e) {
                if ($startedTxn && $pdo instanceof PDO && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        activateverify_json(array_merge([
            'activation_ref' => $activationRef,
            'verified' => false,
            'is_active' => false,
            'ema_price_snapshot' => $emaPriceSnapshot,
            'ema_reward' => $emaReward,
            'reward_token' => 'EMA',
            'reward_status' => 'preview_only_until_confirmed',
        ], $result), 200);
    }

    // Safe non-match before payment/indexing
    activateverify_json([
        'ok' => true,
        'code' => (string)($result['code'] ?? 'NO_MATCH'),
        'message' => (string)($result['message'] ?? 'No matching activation transfer found'),
        'activation_ref' => $activationRef,
        'verified' => false,
        'is_active' => false,
        'card_active' => 0,
        'reload_enabled' => false,
        'activation_status' => 'pending',
        'activation_amount' => '100.000000',
        'activation_token' => 'EMX',
        'ema_price_snapshot' => $emaPriceSnapshot,
        'ema_reward' => $emaReward,
        'reward_token' => 'EMA',
        'reward_status' => 'preview_only_until_confirmed',
        'debug' => $result['debug'] ?? [],
    ], 200);

} catch (Throwable $e) {
    activateverify_json([
        'ok' => false,
        'error' => 'ACTIVATE_VERIFY_FAILED',
        'message' => $e->getMessage(),
    ], 500);
}