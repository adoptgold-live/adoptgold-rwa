<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/inc/mining-guards.php
 *
 * Production mining guards for standalone RWA mining module.
 * Locked rules:
 * - standalone session only
 * - canonical DB access via db_connect() + $GLOBALS['pdo']
 * - wallet-bound mining identity
 * - profile + TON bind mining gate
 * - API POST requires CSRF
 * - no direct new PDO
 */

if (defined('POADO_MINING_GUARDS_LOADED')) {
    return;
}
define('POADO_MINING_GUARDS_LOADED', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

if (!function_exists('poado_mining_json')) {
    function poado_mining_json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('poado_mining_fail')) {
    function poado_mining_fail(string $error, string $message = '', int $status = 400, array $extra = []): never
    {
        poado_mining_json(array_merge([
            'ok' => false,
            'error' => $error,
            'message' => $message !== '' ? $message : $error,
        ], $extra), $status);
    }
}

if (!function_exists('poado_mining_db')) {
    function poado_mining_db(): PDO
    {
        db_connect();
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('DB connection not available');
        }
        return $pdo;
    }
}

if (!function_exists('poado_csrf_validate_or_fail')) {
    function poado_csrf_validate_or_fail(?string $token = null): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
        $requestToken = $token;

        if ($requestToken === null || $requestToken === '') {
            $requestToken =
                (string)($_POST['csrf_token'] ?? '') ?:
                (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        }

        if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
            poado_mining_fail('csrf_invalid', 'CSRF validation failed', 403);
        }
    }
}

if (!function_exists('poado_mining_session_user')) {
    function poado_mining_session_user(): array
    {
        $user = session_user();
        if (!is_array($user) || empty($user)) {
            poado_mining_fail('auth_required', 'Please log in first', 401, [
                'redirect' => '/rwa/index.php',
            ]);
        }
        return $user;
    }
}

if (!function_exists('poado_mining_wallet_from_user')) {
    function poado_mining_wallet_from_user(array $user): string
    {
        $wallet = trim((string)($user['wallet_address'] ?? $user['wallet'] ?? ''));
        if ($wallet === '') {
            poado_mining_fail('wallet_not_bound', 'TON wallet not bound', 403, [
                'redirect' => '/rwa/profile/?bind_ton=1',
            ]);
        }
        return $wallet;
    }
}

if (!function_exists('poado_mining_is_profile_complete')) {
    function poado_mining_is_profile_complete(array $user): bool
    {
        $nickname = trim((string)($user['nickname'] ?? ''));
        $email    = trim((string)($user['email'] ?? ''));
        $country  = trim((string)($user['country_name'] ?? $user['country'] ?? ''));

        return $nickname !== '' && $email !== '' && $country !== '';
    }
}

if (!function_exists('poado_fetch_user_profile_state')) {
    function poado_fetch_user_profile_state(PDO $pdo, int $userId, string $wallet): array
    {
        $state = [
            'user_id' => $userId,
            'wallet' => $wallet,
            'wallet_short' => $wallet !== '' ? substr($wallet, 0, 6) . '...' . substr($wallet, -4) : 'SESSION: NONE',
            'is_profile_complete' => false,
            'is_ton_bound' => $wallet !== '',
            'is_mining_eligible' => false,
        ];

        if ($userId <= 0) {
            return $state;
        }

        $st = $pdo->prepare("
            SELECT id, nickname, email, country_name, country, wallet_address
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return $state;
        }

        $state['is_profile_complete'] = poado_mining_is_profile_complete($row);
        $state['is_ton_bound'] = trim((string)($row['wallet_address'] ?? '')) !== '';
        $state['is_mining_eligible'] = $state['is_profile_complete'] && $state['is_ton_bound'];

        return $state;
    }
}

if (!function_exists('poado_require_mining_page_access')) {
    function poado_require_mining_page_access(): array
    {
        $pdo = poado_mining_db();
        $user = poado_mining_session_user();
        $userId = (int)($user['id'] ?? 0);
        $wallet = trim((string)($user['wallet_address'] ?? $user['wallet'] ?? ''));

        $gate = poado_fetch_user_profile_state($pdo, $userId, $wallet);

        return [
            'pdo' => $pdo,
            'user' => $user,
            'user_id' => $userId,
            'wallet' => $wallet,
            'gate' => $gate,
        ];
    }
}

if (!function_exists('poado_require_mining_api_access')) {
    function poado_require_mining_api_access(bool $requirePost = false, bool $requireEligible = true): array
    {
        if ($requirePost && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            poado_mining_fail('method_not_allowed', 'POST required', 405);
        }

        if ($requirePost) {
            poado_csrf_validate_or_fail();
        }

        $ctx = poado_require_mining_page_access();

        if ($requireEligible && empty($ctx['gate']['is_mining_eligible'])) {
            poado_mining_fail('mining_locked', 'Mining access locked', 403, [
                'gate' => $ctx['gate'],
                'redirect' => '/rwa/profile/',
            ]);
        }

        return $ctx;
    }
}

if (!function_exists('poado_throttle_heartbeat_or_fail')) {
    function poado_throttle_heartbeat_or_fail(PDO $pdo, int $userId, string $wallet, int $minSeconds = 8): void
    {
        $st = $pdo->prepare("
            SELECT last_heartbeat_at
            FROM poado_miner_profiles
            WHERE user_id = ? AND wallet = ?
            LIMIT 1
        ");
        $st->execute([$userId, $wallet]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row || empty($row['last_heartbeat_at'])) {
            return;
        }

        $lastTs = strtotime((string)$row['last_heartbeat_at']);
        if ($lastTs === false) {
            return;
        }

        $nowTs = time();
        if (($nowTs - $lastTs) < $minSeconds) {
            poado_mining_fail('heartbeat_throttled', 'Heartbeat too fast', 429, [
                'retry_after_seconds' => $minSeconds - ($nowTs - $lastTs),
            ]);
        }
    }
}