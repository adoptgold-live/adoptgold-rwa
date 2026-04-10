<?php
declare(strict_types=1);

/**
 * /rwa/inc/core/guards.php
 * Standalone RWA Guards
 * Version: v1.0.20260314.1
 *
 * Purpose
 * - public path detection
 * - redirect helpers
 * - authenticated page gate
 * - role / active / bind / profile checks
 */

if (!function_exists('poado_guard_path')) {
    function poado_guard_path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : '/';
    }
}

if (!function_exists('poado_is_public_path')) {
    function poado_is_public_path(): bool
    {
        $path = poado_guard_path();

        $exactPublic = [
            '/',
            '/index.php',

            '/favicon.ico',
            '/robots.txt',
            '/dashboard-sitemap.xml',
            '/site.webmanifest',
            '/manifest.json',

            '/rwa',
            '/rwa/',
            '/rwa/index.php',
            '/rwa/tg-pin.php',
            '/rwa/ton-login.php',
            '/rwa/web3-login.php',
            '/rwa/logout.php',
        ];

        if (in_array($path, $exactPublic, true)) {
            return true;
        }

        $prefixPublic = [
            '/.well-known/',
            '/assets/',
            '/static/',
            '/images/',
            '/img/',
            '/css/',
            '/js/',
            '/metadata/',

            '/rwa/assets/',
            '/rwa/metadata/',
            '/rwa/auth/',
            '/rwa/api/common/',
            '/rwa/api/global/',
            '/rwa/api/geo/',
        ];

        foreach ($prefixPublic as $prefix) {
            if (strncmp($path, $prefix, strlen($prefix)) === 0) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('poado_redirect')) {
    function poado_redirect(string $path, int $code = 302): void
    {
        $path = trim($path);
        if ($path === '') {
            $path = '/';
        }

        if (!preg_match('~^https?://~i', $path) && $path[0] !== '/') {
            $path = '/' . $path;
        }

        if (!headers_sent()) {
            header('Location: ' . $path, true, $code);
            exit;
        }

        echo '<script>window.location.href=' . json_encode($path, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '"></noscript>';
        exit;
    }
}

if (!function_exists('poado_guard_login_redirect')) {
    function poado_guard_login_redirect(string $loginPath = '/rwa/index.php'): void
    {
        $next = poado_guard_path();
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        if ($qs !== '') {
            $next .= '?' . $qs;
        }

        $url = $loginPath . '?next=' . rawurlencode($next);
        poado_redirect($url, 302);
    }
}

if (!function_exists('poado_guard_require_db')) {
    function poado_guard_require_db(): ?PDO
    {
        try {
            if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
                return $GLOBALS['pdo'];
            }

            if (function_exists('rwa_db')) {
                $GLOBALS['pdo'] = rwa_db();
                return $GLOBALS['pdo'];
            }

            if (function_exists('db_connect')) {
                $GLOBALS['pdo'] = db_connect();
                return $GLOBALS['pdo'];
            }
        } catch (Throwable $e) {
            return null;
        }

        return null;
    }
}

if (!function_exists('poado_guard_load_user_by_wallet')) {
    function poado_guard_load_user_by_wallet(PDO $pdo, string $wallet): ?array
    {
        try {
            $st = $pdo->prepare("
                SELECT *
                FROM users
                WHERE wallet = :wallet
                   OR wallet_address = :wallet
                LIMIT 1
            ");
            $st->execute([':wallet' => $wallet]);
            $user = $st->fetch(PDO::FETCH_ASSOC);

            return is_array($user) ? $user : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('poado_guard_bool')) {
    function poado_guard_bool(array $row, string $field, int $default = 0): bool
    {
        return ((int)($row[$field] ?? $default)) === 1;
    }
}

if (!function_exists('poado_guard_has_role')) {
    function poado_guard_has_role(array $user): bool
    {
        return trim((string)($user['role'] ?? '')) !== '';
    }
}

if (!function_exists('poado_guard_has_verified_email')) {
    function poado_guard_has_verified_email(array $user): bool
    {
        $v = trim((string)($user['email_verified_at'] ?? ''));
        if ($v !== '') {
            return true;
        }
        return ((int)($user['is_fully_verified'] ?? 0)) === 1;
    }
}

if (!function_exists('poado_guard_has_ton_bind')) {
    function poado_guard_has_ton_bind(array $user): bool
    {
        $candidates = [
            $user['ton_address'] ?? '',
            $user['wallet_ton'] ?? '',
            $user['ton_wallet'] ?? '',
        ];

        foreach ($candidates as $v) {
            if (is_string($v) && trim($v) !== '') {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('poado_guard_profile_complete')) {
    function poado_guard_profile_complete(array $user): bool
    {
        $checks = [
            trim((string)($user['nickname'] ?? '')) !== '',
            trim((string)($user['email'] ?? '')) !== '',
            trim((string)($user['mobile_e164'] ?? '')) !== '',
            trim((string)($user['country_code'] ?? '')) !== '',
        ];

        foreach ($checks as $ok) {
            if (!$ok) {
                return false;
            }
        }

        return true;
    }
}

/**
 * require_gate()
 *
 * Default standalone RWA policy:
 * - must have wallet session
 * - must load users row
 * - must be active
 * - role optional depending on page
 *
 * Returns loaded user row.
 */
if (!function_exists('require_gate')) {
    function require_gate(array $opts = []): array
    {
        $login_path        = (string)($opts['login_path'] ?? '/rwa/index.php');
        $launcher_path     = (string)($opts['launcher_path'] ?? '/rwa/login-select.php');
        $profile_path      = (string)($opts['profile_path'] ?? '/rwa/profile/index.php');
        $ton_bind_path     = (string)($opts['ton_bind_path'] ?? '/rwa/profile/index.php');
        $logout_path       = (string)($opts['logout_path'] ?? '/rwa/logout.php');

        $require_active            = array_key_exists('require_active', $opts) ? (bool)$opts['require_active'] : true;
        $require_role              = array_key_exists('require_role', $opts) ? (bool)$opts['require_role'] : false;
        $require_profile_complete  = array_key_exists('require_profile_complete', $opts) ? (bool)$opts['require_profile_complete'] : false;
        $require_ton_bind          = array_key_exists('require_ton_bind', $opts) ? (bool)$opts['require_ton_bind'] : false;
        $allowed_roles             = is_array($opts['allowed_roles'] ?? null) ? $opts['allowed_roles'] : [];

        $wallet = '';

        if (!empty($opts['wallet']) && is_string($opts['wallet'])) {
            $wallet = trim($opts['wallet']);
        } elseif (function_exists('session_user')) {
            $su = session_user();
            if (!empty($su['ok'])) {
                $wallet = trim((string)($su['wallet'] ?? ''));
            }
        } elseif (function_exists('get_wallet_session')) {
            $ws = get_wallet_session();

            if (is_array($ws)) {
                $wallet = trim((string)($ws['wallet_address'] ?? $ws['wallet'] ?? ''));
            } elseif (is_string($ws)) {
                $wallet = trim($ws);
            }
        }

        if ($wallet === '') {
            poado_guard_login_redirect($login_path);
        }

        $pdo = poado_guard_require_db();
        if (!$pdo instanceof PDO) {
            poado_guard_login_redirect($login_path);
        }

        $user = poado_guard_load_user_by_wallet($pdo, $wallet);
        if (!$user) {
            poado_guard_login_redirect($login_path);
        }

        if ($require_active && !poado_guard_bool($user, 'is_active', 0)) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION = [];
                @session_destroy();
            }
            poado_redirect($logout_path, 302);
        }

        if ($require_role && !poado_guard_has_role($user)) {
            poado_redirect($launcher_path, 302);
        }

        if (!empty($allowed_roles)) {
            $role = strtolower(trim((string)($user['role'] ?? '')));
            $allowed = array_map(
                static fn($v) => strtolower(trim((string)$v)),
                $allowed_roles
            );

            if ($role === '' || !in_array($role, $allowed, true)) {
                poado_redirect($launcher_path, 302);
            }
        }

        if ($require_profile_complete && !poado_guard_profile_complete($user)) {
            poado_redirect($profile_path, 302);
        }

        if ($require_ton_bind && !poado_guard_has_ton_bind($user)) {
            poado_redirect($ton_bind_path, 302);
        }

        $GLOBALS['poado_gate_user'] = $user;
        return $user;
    }
}