<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/inc/rwa-session.php
 * AdoptGold / POAdo — Temporary Compatibility Session Shim
 * Version: v1.0.0-compat-shim-20260318
 *
 * Purpose:
 * - keep old include path stable across many files
 * - bridge old modules into canonical session-user.php
 * - DO NOT redirect
 * - DO NOT mutate auth flow automatically
 * - DO NOT override canonical session resolution
 */

if (!defined('POADO_RWA_SESSION_SHIM_LOADED')) {
    define('POADO_RWA_SESSION_SHIM_LOADED', true);
}

$__sessionUser = __DIR__ . '/core/session-user.php';
if (is_file($__sessionUser)) {
    require_once $__sessionUser;
}

if (function_exists('poado_session_boot')) {
    poado_session_boot();
} elseif (function_exists('poado_session_start')) {
    poado_session_start();
} elseif (session_status() !== PHP_SESSION_ACTIVE) {
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.cookie_secure', '1');
    @ini_set('session.cookie_samesite', 'Lax');
    @session_start();
}

/**
 * Legacy compatibility helpers only.
 * These should not replace canonical auth.
 */

if (!function_exists('rwa_user')) {
    function rwa_user(): ?array
    {
        return function_exists('session_user') ? session_user() : null;
    }
}

if (!function_exists('rwa_user_id')) {
    function rwa_user_id(): int
    {
        return function_exists('session_user_id') ? (int)session_user_id() : 0;
    }
}

if (!function_exists('rwa_wallet_address')) {
    function rwa_wallet_address(): string
    {
        return function_exists('session_wallet_address') ? (string)session_wallet_address() : '';
    }
}

if (!function_exists('rwa_wallet')) {
    function rwa_wallet(): string
    {
        return function_exists('session_wallet') ? (string)session_wallet() : '';
    }
}

/**
 * Legacy wallet_session compatibility.
 */
if (!function_exists('get_wallet_session')) {
    function get_wallet_session()
    {
        if (isset($_SESSION['wallet_session'])) {
            return $_SESSION['wallet_session'];
        }
        if (isset($_SESSION['wallet'])) {
            return $_SESSION['wallet'];
        }

        $wallet = function_exists('session_wallet') ? trim((string)session_wallet()) : '';
        $walletAddress = function_exists('session_wallet_address') ? trim((string)session_wallet_address()) : '';
        $userId = function_exists('session_user_id') ? (int)session_user_id() : 0;

        if ($wallet !== '' || $walletAddress !== '' || $userId > 0) {
            return [
                'user_id' => $userId,
                'wallet' => $wallet !== '' ? $wallet : $walletAddress,
                'wallet_address' => $walletAddress !== '' ? $walletAddress : $wallet,
            ];
        }

        return null;
    }
}

if (!function_exists('set_wallet_session')) {
    function set_wallet_session(string $wallet, array $extra = []): void
    {
        $payload = array_merge([
            'wallet' => $wallet,
            'wallet_address' => $wallet,
            'user_id' => function_exists('session_user_id') ? (int)session_user_id() : 0,
        ], $extra);

        $_SESSION['wallet_session'] = $payload;
        $_SESSION['wallet'] = $payload['wallet'];
        $_SESSION['wallet_address'] = $payload['wallet_address'];
    }
}

if (!function_exists('clear_wallet_session')) {
    function clear_wallet_session(): void
    {
        unset($_SESSION['wallet_session'], $_SESSION['wallet'], $_SESSION['wallet_address']);
    }
}

/**
 * Important:
 * No redirect logic here.
 * No auto-guard logic here.
 * No auth rewriting here.
 */