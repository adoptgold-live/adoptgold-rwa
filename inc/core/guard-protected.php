<?php
declare(strict_types=1);

/**
 * /rwa/inc/core/guard-protected.php
 * Standalone RWA Protected Guard
 * Version: v1.0.20260314.1
 *
 * Purpose
 * - protect authenticated standalone RWA pages
 * - avoid redirect loops
 * - keep public entry pages and public APIs accessible
 */

@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');

if (!function_exists('poado_go')) {
    function poado_go(string $to, int $code = 302): never
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Location: ' . $to, true, $code);
        exit;
    }
}

if (!function_exists('poado_rwa_login_url')) {
    function poado_rwa_login_url(string $next = ''): string
    {
        $entry = defined('POADO_RWA_LOGIN_ENTRY')
            ? (string)POADO_RWA_LOGIN_ENTRY
            : '/rwa/index.php';

        $next = trim($next);
        if ($next === '') {
            return $entry;
        }

        // no open redirect
        if (strpos($next, '://') !== false) {
            return $entry;
        }

        if ($next[0] !== '/') {
            $next = '/' . $next;
        }

        // only allow standalone rwa paths as next
        if (strpos($next, '/rwa/') !== 0 && $next !== '/rwa') {
            return $entry;
        }

        return $entry . '?next=' . rawurlencode($next);
    }
}

$uri  = (string)($_SERVER['REQUEST_URI'] ?? '');
$path = (string)(parse_url($uri, PHP_URL_PATH) ?: '/');

/**
 * 0) PUBLIC BYPASS
 * Prevent redirect loops and keep public APIs/pages accessible
 */
$publicExact = [
    '/',
    '/index.php',

    '/rwa',
    '/rwa/',
    '/rwa/index.php',
    '/rwa/tg-pin.php',
    '/rwa/ton-login.php',
    '/rwa/web3-login.php',
    '/rwa/logout.php',
];

if (in_array($path, $publicExact, true)) {
    return;
}

$publicPrefixes = [
    '/rwa/assets/',
    '/rwa/metadata/',
    '/rwa/auth/',
    '/rwa/api/common/',
    '/rwa/api/global/',
    '/rwa/api/geo/',
    '/.well-known/',
];

foreach ($publicPrefixes as $prefix) {
    if (strpos($path, $prefix) === 0) {
        return;
    }
}

/**
 * Wallet session accessor
 */
$wallet = '';

if (function_exists('session_user')) {
    $su = session_user();
    if (!empty($su['ok'])) {
        $wallet = trim((string)($su['wallet'] ?? ''));
    }
}

if ($wallet === '' && function_exists('get_wallet_session')) {
    $ws = get_wallet_session();

    if (is_array($ws)) {
        $wallet = trim((string)($ws['wallet_address'] ?? $ws['wallet'] ?? ''));
    } elseif (is_string($ws)) {
        $wallet = trim($ws);
    }
}

/**
 * 1) No wallet session => unified RWA login entry
 */
if ($wallet === '') {
    poado_go(poado_rwa_login_url($path), 302);
}

/**
 * 2) DB active check
 * Fail open if DB temporarily unavailable
 */
if (function_exists('rwa_db')) {
    try {
        $pdo = rwa_db();

        if ($pdo instanceof PDO) {
            $st = $pdo->prepare("
                SELECT is_active
                FROM users
                WHERE wallet = :wallet
                   OR wallet_address = :wallet
                LIMIT 1
            ");
            $st->execute([':wallet' => $wallet]);
            $isActive = $st->fetchColumn();

            if ($isActive === false || (string)$isActive === '0') {
                poado_go('/rwa/index.php?err=inactive', 302);
            }
        }
    } catch (Throwable $e) {
        // Fail open; page-level logic can still handle it
    }
} elseif (function_exists('db_connect')) {
    try {
        $pdo = db_connect();

        if ($pdo instanceof PDO) {
            $st = $pdo->prepare("
                SELECT is_active
                FROM users
                WHERE wallet = :wallet
                   OR wallet_address = :wallet
                LIMIT 1
            ");
            $st->execute([':wallet' => $wallet]);
            $isActive = $st->fetchColumn();

            if ($isActive === false || (string)$isActive === '0') {
                poado_go('/rwa/index.php?err=inactive', 302);
            }
        }
    } catch (Throwable $e) {
        // Fail open
    }
}

/**
 * If reached: access allowed
 */
return;