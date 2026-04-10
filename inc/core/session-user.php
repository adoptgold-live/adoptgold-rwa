<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/inc/core/session-user.php
 * AdoptGold / POAdo — Canonical + Compatibility Session Resolver
 * Version: v1.2.0-locked-20260318
 *
 * Permanent global lock:
 * - canonical auth reader for all standalone RWA modules
 * - preserve legacy compatibility for wallet_session / wallet / user_id flows
 * - provide session_user_clear() for logout and old modules
 * - authenticated state must be user-id first
 */

if (!function_exists('poado_session_start')) {
    function poado_session_start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.cookie_secure', '1');
        @ini_set('session.cookie_samesite', 'Lax');
        @session_start();
    }
}

if (!function_exists('poado_session_boot')) {
    function poado_session_boot(): void
    {
        poado_session_start();
    }
}

if (!function_exists('poado_session_value')) {
    function poado_session_value(array $path, $default = null)
    {
        poado_session_boot();

        $cur = $_SESSION ?? [];
        foreach ($path as $seg) {
            if (!is_array($cur) || !array_key_exists($seg, $cur)) {
                return $default;
            }
            $cur = $cur[$seg];
        }

        return $cur;
    }
}

if (!function_exists('poado_session_string_candidates')) {
    function poado_session_string_candidates(array $paths): string
    {
        foreach ($paths as $path) {
            $v = poado_session_value($path, '');
            if (is_scalar($v)) {
                $s = trim((string)$v);
                if ($s !== '') {
                    return $s;
                }
            }
        }
        return '';
    }
}

if (!function_exists('session_wallet')) {
    function session_wallet(): string
    {
        $legacyWalletSession = $_SESSION['wallet_session'] ?? null;

        if (is_array($legacyWalletSession)) {
            $wallet = trim((string)($legacyWalletSession['wallet'] ?? $legacyWalletSession['wallet_address'] ?? ''));
            if ($wallet !== '') {
                return $wallet;
            }
        }

        if (is_string($legacyWalletSession) && trim($legacyWalletSession) !== '') {
            return trim($legacyWalletSession);
        }

        return poado_session_string_candidates([
            ['rwa_user', 'wallet'],
            ['user', 'wallet'],
            ['wallet'],
        ]);
    }
}

if (!function_exists('session_wallet_address')) {
    function session_wallet_address(): string
    {
        $legacyWalletSession = $_SESSION['wallet_session'] ?? null;

        if (is_array($legacyWalletSession)) {
            $walletAddress = trim((string)($legacyWalletSession['wallet_address'] ?? $legacyWalletSession['wallet'] ?? ''));
            if ($walletAddress !== '') {
                return $walletAddress;
            }
        }

        if (is_string($legacyWalletSession) && trim($legacyWalletSession) !== '') {
            return trim($legacyWalletSession);
        }

        return poado_session_string_candidates([
            ['rwa_user', 'wallet_address'],
            ['user', 'wallet_address'],
            ['wallet_address'],
        ]);
    }
}

if (!function_exists('session_nickname')) {
    function session_nickname(): string
    {
        return poado_session_string_candidates([
            ['rwa_user', 'nickname'],
            ['user', 'nickname'],
            ['nickname'],
        ]);
    }
}

if (!function_exists('session_email')) {
    function session_email(): string
    {
        return poado_session_string_candidates([
            ['rwa_user', 'email'],
            ['user', 'email'],
            ['email'],
        ]);
    }
}

if (!function_exists('session_user_id')) {
    function session_user_id(): int
    {
        poado_session_boot();

        $candidates = [
            (int)poado_session_value(['rwa_user', 'id'], 0),
            (int)poado_session_value(['user', 'id'], 0),
            (int)poado_session_value(['user_id'], 0),
            (int)poado_session_value(['uid'], 0),
        ];

        $legacyWalletSession = $_SESSION['wallet_session'] ?? null;
        if (is_array($legacyWalletSession) && !empty($legacyWalletSession['user_id'])) {
            $candidates[] = (int)$legacyWalletSession['user_id'];
        }

        foreach ($candidates as $id) {
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }
}

if (!function_exists('session_user_seed')) {
    function session_user_seed(): array
    {
        return [
            'id' => session_user_id(),
            'wallet' => session_wallet(),
            'wallet_address' => session_wallet_address(),
            'nickname' => session_nickname(),
            'email' => session_email(),
        ];
    }
}

if (!function_exists('session_user')) {
    function session_user(): ?array
    {
        static $resolved = null;
        static $done = false;

        if ($done) {
            return $resolved;
        }
        $done = true;

        $seed = session_user_seed();
        $id = (int)($seed['id'] ?? 0);
        $wallet = trim((string)($seed['wallet'] ?? ''));
        $walletAddress = trim((string)($seed['wallet_address'] ?? ''));

        if ($id <= 0 && $wallet === '' && $walletAddress === '') {
            $resolved = null;
            return null;
        }

        if (!function_exists('db_connect')) {
            $resolved = $seed;
            return $resolved;
        }

        try {
            db_connect();
            $pdo = $GLOBALS['pdo'] ?? null;

            if (!$pdo instanceof PDO) {
                $resolved = $seed;
                return $resolved;
            }

            $sql = "
                SELECT
                    id,
                    wallet,
                    is_registered,
                    nickname,
                    email,
                    email_verified_at,
                    verify_token,
                    verify_sent_at,
                    mobile_e164,
                    mobile,
                    country_code,
                    country_name,
                    state,
                    country,
                    region,
                    salesmartly_email,
                    role,
                    is_active,
                    is_fully_verified,
                    is_senior,
                    wallet_address,
                    created_at,
                    updated_at
                FROM users
            ";

            if ($id > 0) {
                $st = $pdo->prepare($sql . " WHERE id = ? LIMIT 1");
                $st->execute([$id]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (is_array($row) && !empty($row)) {
                    $resolved = $row;
                    return $resolved;
                }
            }

            if ($walletAddress !== '') {
                $st = $pdo->prepare($sql . " WHERE wallet_address = ? LIMIT 1");
                $st->execute([$walletAddress]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (is_array($row) && !empty($row)) {
                    $resolved = $row;
                    return $resolved;
                }
            }

            if ($wallet !== '') {
                $st = $pdo->prepare($sql . " WHERE wallet = ? LIMIT 1");
                $st->execute([$wallet]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (is_array($row) && !empty($row)) {
                    $resolved = $row;
                    return $resolved;
                }
            }
        } catch (Throwable $e) {
            $resolved = $seed;
            return $resolved;
        }

        $resolved = $seed;
        return $resolved;
    }
}

if (!function_exists('session_user_require')) {
    function session_user_require(): array
    {
        $u = session_user();
        if (!is_array($u) || (int)($u['id'] ?? 0) <= 0) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => 'AUTH_REQUIRED',
                'ts' => time(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        return $u;
    }
}

if (!function_exists('session_attach_user')) {
    function session_attach_user(array $row): void
    {
        poado_session_boot();

        $id = (int)($row['id'] ?? 0);
        $wallet = trim((string)($row['wallet'] ?? ''));
        $walletAddress = trim((string)($row['wallet_address'] ?? ''));
        $nickname = trim((string)($row['nickname'] ?? ''));
        $email = trim((string)($row['email'] ?? ''));

        if (!isset($_SESSION['rwa_user']) || !is_array($_SESSION['rwa_user'])) {
            $_SESSION['rwa_user'] = [];
        }
        if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
            $_SESSION['user'] = [];
        }

        $_SESSION['rwa_user']['id'] = $id;
        $_SESSION['rwa_user']['wallet'] = $wallet;
        $_SESSION['rwa_user']['wallet_address'] = $walletAddress;
        $_SESSION['rwa_user']['nickname'] = $nickname;
        $_SESSION['rwa_user']['email'] = $email;

        $_SESSION['user']['id'] = $id;
        $_SESSION['user']['wallet'] = $wallet;
        $_SESSION['user']['wallet_address'] = $walletAddress;
        $_SESSION['user']['nickname'] = $nickname;
        $_SESSION['user']['email'] = $email;

        $_SESSION['user_id'] = $id;
        $_SESSION['uid'] = $id;
        $_SESSION['wallet'] = $wallet;
        $_SESSION['wallet_address'] = $walletAddress;
        $_SESSION['nickname'] = $nickname;
        $_SESSION['email'] = $email;

        $_SESSION['wallet_session'] = [
            'user_id' => $id,
            'wallet' => $wallet !== '' ? $wallet : $walletAddress,
            'wallet_address' => $walletAddress !== '' ? $walletAddress : $wallet,
            'nickname' => $nickname,
            'email' => $email,
        ];
    }
}

if (!function_exists('session_user_clear')) {
    function session_user_clear(): void
    {
        poado_session_boot();

        unset(
            $_SESSION['rwa_user'],
            $_SESSION['user'],
            $_SESSION['user_id'],
            $_SESSION['uid'],
            $_SESSION['wallet'],
            $_SESSION['wallet_address'],
            $_SESSION['wallet_session'],
            $_SESSION['nickname'],
            $_SESSION['email']
        );
    }
}