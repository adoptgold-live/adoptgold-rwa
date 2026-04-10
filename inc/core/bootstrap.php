<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/inc/core/bootstrap.php
 * Standalone RWA Core Bootstrap
 * Version: v2.1.1-canonical-csrf-20260319
 *
 * Purpose
 * - standalone RWA bootstrap
 * - env + session + db + helpers + csrf + redirect + guard entry
 * - safe for public pages, pre-login pages, public APIs, and authenticated pages
 *
 * Permanent global lock
 * - canonical auth/session resolver is /rwa/inc/core/session-user.php
 * - bootstrap must not redefine session_user() or session_user_id()
 * - all modules must read auth through session-user.php
 * - /rwa/login-select.php is a public launcher page and must NOT auto-load guards.php
 */

if (!defined('POADO_RWA_BOOTSTRAP_LOADED')) {
    define('POADO_RWA_BOOTSTRAP_LOADED', true);
}

/**
 * ------------------------------------------------------------
 * 0) Core session helper first
 * ------------------------------------------------------------
 */
$__rwa_session_user = __DIR__ . '/session-user.php';
if (is_file($__rwa_session_user)) {
    require_once $__rwa_session_user;
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
 * ------------------------------------------------------------
 * 1) ENV
 * ------------------------------------------------------------
 */
$__rwa_env = __DIR__ . '/env.php';
if (is_file($__rwa_env)) {
    require_once $__rwa_env;
}

if (!function_exists('poado_env')) {
    function poado_env(string $key, $default = null) {
        $v = getenv($key);
        if ($v === false || $v === null || $v === '') {
            return $default;
        }
        return $v;
    }
}

/**
 * ------------------------------------------------------------
 * 2) Path / request helpers
 * ------------------------------------------------------------
 */
if (!function_exists('poado_request_path')) {
    function poado_request_path(): string
    {
        $p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (!is_string($p) || $p === '') {
            return '/';
        }
        return $p;
    }
}

if (!function_exists('poado_request_method')) {
    function poado_request_method(): string
    {
        return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }
}

if (!function_exists('poado_is_cli')) {
    function poado_is_cli(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }
}

if (!function_exists('poado_starts_with')) {
    function poado_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

/**
 * ------------------------------------------------------------
 * 3) Public path detection
 * ------------------------------------------------------------
 * Standalone RWA rules:
 * - pre-login pages are public
 * - launcher page is public
 * - auth endpoints are public
 * - selected public api endpoints are public
 * - public assets are public
 */
if (!function_exists('poado_is_public_path')) {
    function poado_is_public_path(): bool
    {
        $path = poado_request_path();

        $exactPublic = [
            '/',
            '/index.php',

            '/rwa',
            '/rwa/',
            '/rwa/index.php',
            '/rwa/login-select.php',
            '/rwa/tg-pin.php',
            '/rwa/ton-login.php',
            '/rwa/web3-login.php',
            '/rwa/logout.php',

            '/privacy/',
            '/terms/',
        ];

        foreach ($exactPublic as $pub) {
            if ($path === $pub) {
                return true;
            }
        }

        $prefixPublic = [
            '/rwa/assets/',
            '/rwa/metadata/',
            '/rwa/auth/',
            '/rwa/api/common/',
            '/rwa/api/global/',
            '/rwa/api/geo/',
        ];

        foreach ($prefixPublic as $prefix) {
            if (poado_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}

/**
 * ------------------------------------------------------------
 * 4) Redirect helper
 * ------------------------------------------------------------
 */
if (!function_exists('poado_go')) {
    function poado_go(string $url, int $code = 302): void
    {
        if (!headers_sent()) {
            header('Location: ' . $url, true, $code);
            exit;
        }

        $safeUrl = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        echo '<script>window.location.href=' . $safeUrl . ';</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></noscript>';
        exit;
    }
}

/**
 * ------------------------------------------------------------
 * 5) DB bootstrap
 * ------------------------------------------------------------
 */
$__rwa_db = __DIR__ . '/db.php';
if (is_file($__rwa_db)) {
    require_once $__rwa_db;
}

/**
 * ------------------------------------------------------------
 * 6) Shared helpers
 * ------------------------------------------------------------
 */
foreach (['validators.php', 'json.php', 'error.php', 'poado-helpers.php'] as $__helper) {
    $__file = __DIR__ . '/' . $__helper;
    if (is_file($__file)) {
        require_once $__file;
    }
}

/**
 * ------------------------------------------------------------
 * 7) Session / wallet compatibility helpers
 * ------------------------------------------------------------
 * Important:
 * - these are compatibility helpers only
 * - canonical auth read path remains session-user.php
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
        return null;
    }
}

if (!function_exists('set_wallet_session')) {
    function set_wallet_session(string $wallet, array $extra = []): void
    {
        $payload = array_merge([
            'wallet_address' => $wallet,
            'wallet' => $wallet,
        ], $extra);

        $_SESSION['wallet_session'] = $payload;
        $_SESSION['wallet'] = $wallet;
    }
}

if (!function_exists('clear_wallet_session')) {
    function clear_wallet_session(): void
    {
        unset($_SESSION['wallet'], $_SESSION['wallet_session']);
    }
}

/**
 * Optional compatibility aliases to canonical auth helpers.
 */
if (!function_exists('poado_is_logged_in')) {
    function poado_is_logged_in(): bool
    {
        if (!function_exists('session_user')) {
            return false;
        }

        $u = session_user();
        return is_array($u) && (int)($u['id'] ?? 0) > 0;
    }
}

if (!function_exists('poado_require_login')) {
    function poado_require_login(string $next = ''): void
    {
        if (poado_is_logged_in()) {
            return;
        }

        if ($next === '') {
            $next = poado_request_path();
            $qs = $_SERVER['QUERY_STRING'] ?? '';
            if ($qs !== '') {
                $next .= '?' . $qs;
            }
        }

        $loginUrl = '/rwa/index.php';
        if ($next !== '') {
            $loginUrl .= '?next=' . rawurlencode($next);
        }

        poado_go($loginUrl, 302);
    }
}

if (!function_exists('poado_safe_next')) {
    function poado_safe_next(?string $next, string $fallback = '/rwa/login-select.php'): string
    {
        $next = trim((string)$next);
        if ($next === '') {
            return $fallback;
        }

        if (preg_match('~^https?://~i', $next)) {
            return $fallback;
        }

        if ($next[0] !== '/') {
            return $fallback;
        }

        return $next;
    }
}

/**
 * ------------------------------------------------------------
 * 8) CSRF helpers
 * ------------------------------------------------------------
 * Canonical source:
 * - /rwa/inc/core/csrf.php
 * - uses $_SESSION['poado_csrf']
 * - POST field name = csrf_token
 */
$__rwa_csrf = __DIR__ . '/csrf.php';
if (is_file($__rwa_csrf)) {
    require_once $__rwa_csrf;
}

/**
 * ------------------------------------------------------------
 * 9) Composer autoload
 * ------------------------------------------------------------
 */
if (!defined('POADO_COMPOSER_AUTOLOADED')) {
    $autoload = '/var/www/html/public/dashboard/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
        define('POADO_COMPOSER_AUTOLOADED', true);
    } else {
        define('POADO_COMPOSER_AUTOLOADED', false);
    }
}

/**
 * ------------------------------------------------------------
 * 10) Time / host / app constants
 * ------------------------------------------------------------
 */
if (!defined('POADO_APP_DOMAIN')) {
    define('POADO_APP_DOMAIN', 'https://adoptgold.app');
}

if (!defined('POADO_RWA_ROOT')) {
    define('POADO_RWA_ROOT', '/rwa');
}

if (!defined('POADO_NOW_UTC')) {
    define('POADO_NOW_UTC', gmdate('Y-m-d H:i:s'));
}

/**
 * ------------------------------------------------------------
 * 11) Optional aliases for legacy callers
 * ------------------------------------------------------------
 */
if (!function_exists('rwa_current_user')) {
    function rwa_current_user(): ?array
    {
        return function_exists('session_user') ? session_user() : null;
    }
}

if (!function_exists('rwa_session_user')) {
    function rwa_session_user(): ?array
    {
        return function_exists('session_user') ? session_user() : null;
    }
}

/**
 * ------------------------------------------------------------
 * 12) Guard bootstrap LAST
 * ------------------------------------------------------------
 * Only non-public web routes should load guards automatically.
 * CLI must skip guards.
 */
if (!poado_is_cli() && !poado_is_public_path()) {
    $__rwa_guards = __DIR__ . '/guards.php';
    if (is_file($__rwa_guards)) {
        require_once $__rwa_guards;
    }
}