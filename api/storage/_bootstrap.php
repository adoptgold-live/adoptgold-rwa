<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/storage/_bootstrap.php
 * AdoptGold / POAdo — Storage API Bootstrap
 * Storage Master v7.4c final aligned
 *
 * Goals:
 * - maintain all previous functions / signatures
 * - preserve current behavior and compatibility
 * - add activation EMA reward helpers
 * - reduce repeated DB / reflection / registry / env work with static caching
 * - keep final locked activation rule:
 *   jetton + amount + ref match => ACCEPT
 *   destination match is NOT required
 * - fix on-chain balance sync selection to avoid drift vs Tonviewer
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/toncenter.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/token-registry.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/csrf.php';

if (!defined('POADO_STORAGE_BOOTSTRAP_VERSION')) {
    define('POADO_STORAGE_BOOTSTRAP_VERSION', 'v7.4c-final-aligned-20260406');
}

if (!defined('STORAGE_DB_NAME')) {
    define('STORAGE_DB_NAME', 'wems_db');
}
if (!defined('STORAGE_EMX_DECIMALS')) {
    define('STORAGE_EMX_DECIMALS', 9);
}
if (!defined('STORAGE_ACTIVATE_EMX')) {
    define('STORAGE_ACTIVATE_EMX', '100.000000000');
}
if (!defined('STORAGE_ACTIVATE_UNITS')) {
    define('STORAGE_ACTIVATE_UNITS', '100000000000');
}
if (!defined('STORAGE_EMX_MASTER_RAW')) {
    define('STORAGE_EMX_MASTER_RAW', '0:63d3319c1cebcde48b013ff040006e4d462b806bf48b06efb18ec267ec078ce2');
}
if (!defined('STORAGE_TREASURY_TON')) {
    define('STORAGE_TREASURY_TON', 'UQBdYfGArtoCUmBs5TjYQtfPFfQuGC2Ydbj2pQr3zIlNrDta');
}
if (!defined('STORAGE_ACTIVATION_REWARD_SCALE')) {
    define('STORAGE_ACTIVATION_REWARD_SCALE', 6);
}

/* -------------------------------------------------
 | internal micro-cache helpers
 * ------------------------------------------------- */

if (!function_exists('storage_runtime_cache')) {
    function storage_runtime_cache(string $bucket): array
    {
        static $cache = [];
        if (!isset($cache[$bucket]) || !is_array($cache[$bucket])) {
            $cache[$bucket] = [];
        }
        return $cache[$bucket];
    }
}

if (!function_exists('storage_runtime_cache_get')) {
    function storage_runtime_cache_get(string $bucket, string $key, $default = null)
    {
        static $cache = [];
        if (!isset($cache[$bucket]) || !array_key_exists($key, $cache[$bucket])) {
            return $default;
        }
        return $cache[$bucket][$key];
    }
}

if (!function_exists('storage_runtime_cache_set')) {
    function storage_runtime_cache_set(string $bucket, string $key, $value)
    {
        static $cache = [];
        if (!isset($cache[$bucket]) || !is_array($cache[$bucket])) {
            $cache[$bucket] = [];
        }
        $cache[$bucket][$key] = $value;
        return $value;
    }
}

/* -------------------------------------------------
 | Core JSON helpers
 * ------------------------------------------------- */

if (!function_exists('storage_send_json')) {
    function storage_send_json(array $data, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('storage_api_json')) {
    function storage_api_json(array $data, int $status = 200): void
    {
        storage_send_json($data, $status);
    }
}

if (!function_exists('storage_api_ok')) {
    function storage_api_ok(array $data = [], int $status = 200): void
    {
        storage_send_json(array_merge([
            'ok' => true,
            'ts' => gmdate('c'),
        ], $data), $status);
    }
}

if (!function_exists('storage_api_fail')) {
    function storage_api_fail(string $error, array $extra = [], int $status = 400): void
    {
        storage_send_json(array_merge([
            'ok' => false,
            'error' => $error,
            'ts' => gmdate('c'),
        ], $extra), $status);
    }
}

if (!function_exists('storage_json_ok')) {
    function storage_json_ok(array $data = [], int $status = 200): void
    {
        storage_api_ok($data, $status);
    }
}

if (!function_exists('storage_json_error')) {
    function storage_json_error(string $code, int $status = 400, array $extra = []): void
    {
        storage_api_fail($code, $extra, $status);
    }
}

if (!function_exists('storage_abort')) {
    function storage_abort(string $error, int $status = 400, array $extra = []): void
    {
        storage_api_fail($error, $extra, $status);
    }
}

/* -------------------------------------------------
 | DB / request / env helpers
 * ------------------------------------------------- */

if (!function_exists('storage_db')) {
    function storage_db(): \PDO
    {
        $cached = storage_runtime_cache_get('db', 'pdo');
        if ($cached instanceof \PDO) {
            return $cached;
        }

        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof \PDO) {
            return storage_runtime_cache_set('db', 'pdo', $GLOBALS['pdo']);
        }

        if (function_exists('rwa_db')) {
            $pdo = rwa_db();
            if ($pdo instanceof \PDO) {
                $GLOBALS['pdo'] = $pdo;
                return storage_runtime_cache_set('db', 'pdo', $pdo);
            }
        }

        if (function_exists('db_connect')) {
            db_connect();
            if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof \PDO) {
                return storage_runtime_cache_set('db', 'pdo', $GLOBALS['pdo']);
            }
        }

        throw new \RuntimeException('DB_CONNECT_FAILED');
    }
}

if (!function_exists('storage_pdo')) {
    function storage_pdo(): \PDO
    {
        return storage_db();
    }
}

if (!function_exists('storage_request_method')) {
    function storage_request_method(): string
    {
        return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }
}

if (!function_exists('storage_require_post')) {
    function storage_require_post(): void
    {
        if (storage_request_method() !== 'POST') {
            storage_abort('METHOD_NOT_ALLOWED', 405);
        }
    }
}

if (!function_exists('storage_post')) {
    function storage_post(string $key, string $default = ''): string
    {
        if (!isset($_POST[$key])) {
            return $default;
        }
        $v = $_POST[$key];
        if (is_array($v)) {
            return $default;
        }
        return trim((string)$v);
    }
}

if (!function_exists('storage_get')) {
    function storage_get(string $key, string $default = ''): string
    {
        if (!isset($_GET[$key])) {
            return $default;
        }
        $v = $_GET[$key];
        if (is_array($v)) {
            return $default;
        }
        return trim((string)$v);
    }
}

if (!function_exists('storage_input')) {
    function storage_input(string $key, string $default = ''): string
    {
        return storage_request_method() === 'POST'
            ? storage_post($key, $default)
            : storage_get($key, $default);
    }
}

if (!function_exists('storage_env')) {
    function storage_env(string $key, string $default = ''): string
    {
        $cacheKey = $key . '|' . $default;
        $cached = storage_runtime_cache_get('env', $cacheKey);
        if ($cached !== null) {
            return (string)$cached;
        }

        $v = getenv($key);
        if (is_string($v) && $v !== '') {
            return storage_runtime_cache_set('env', $cacheKey, $v);
        }
        if (isset($_ENV[$key]) && is_scalar($_ENV[$key])) {
            $tmp = (string)$_ENV[$key];
            if ($tmp !== '') {
                return storage_runtime_cache_set('env', $cacheKey, $tmp);
            }
        }
        if (isset($_SERVER[$key]) && is_scalar($_SERVER[$key])) {
            $tmp = (string)$_SERVER[$key];
            if ($tmp !== '') {
                return storage_runtime_cache_set('env', $cacheKey, $tmp);
            }
        }
        return storage_runtime_cache_set('env', $cacheKey, $default);
    }
}

if (!function_exists('storage_assert_ready')) {
    function storage_assert_ready(): void
    {
        storage_db();
        storage_require_user();
    }
}

/* -------------------------------------------------
 | Session / user helpers
 * ------------------------------------------------- */

if (!function_exists('storage_session_seed')) {
    function storage_session_seed(): ?array
    {
        $cached = storage_runtime_cache_get('session', 'seed');
        if (is_array($cached)) {
            return $cached;
        }

        foreach (['session_user', 'rwa_current_user', 'rwa_session_user'] as $fn) {
            if (function_exists($fn)) {
                try {
                    $u = $fn();
                    if (is_array($u) && !empty($u)) {
                        return storage_runtime_cache_set('session', 'seed', $u);
                    }
                } catch (\Throwable $e) {
                }
            }
        }

        if (function_exists('get_wallet_session')) {
            try {
                $ws = get_wallet_session();
                if (is_array($ws) && !empty($ws)) {
                    return storage_runtime_cache_set('session', 'seed', $ws);
                }
                if (is_string($ws) && trim($ws) !== '') {
                    return storage_runtime_cache_set('session', 'seed', ['wallet' => trim($ws)]);
                }
            } catch (\Throwable $e) {
            }
        }

        return null;
    }
}

if (!function_exists('storage_session_user_row')) {
    function storage_session_user_row(): array
    {
        $cached = storage_runtime_cache_get('session', 'user_row');
        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }

        $pdo = storage_db();
        $seed = storage_session_seed();

        if (!$seed || !is_array($seed)) {
            storage_api_fail('AUTH_REQUIRED', [], 401);
        }

        $userId = (int)($seed['id'] ?? 0);
        $wallet = trim((string)($seed['wallet'] ?? ''));
        $walletAddress = trim((string)($seed['wallet_address'] ?? ''));

        $select = "
            SELECT
                id,
                wallet,
                nickname,
                email,
                email_verified_at,
                role,
                is_active,
                wallet_address
            FROM users
        ";

        try {
            if ($userId > 0) {
                $stmt = $pdo->prepare($select . " WHERE id = ? LIMIT 1");
                $stmt->execute([$userId]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (is_array($row) && !empty($row)) {
                    return storage_runtime_cache_set('session', 'user_row', $row);
                }
            }

            if ($walletAddress !== '') {
                $stmt = $pdo->prepare($select . " WHERE wallet_address = ? LIMIT 1");
                $stmt->execute([$walletAddress]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (is_array($row) && !empty($row)) {
                    return storage_runtime_cache_set('session', 'user_row', $row);
                }
            }

            if ($wallet !== '') {
                $stmt = $pdo->prepare($select . " WHERE wallet = ? LIMIT 1");
                $stmt->execute([$wallet]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (is_array($row) && !empty($row)) {
                    return storage_runtime_cache_set('session', 'user_row', $row);
                }
            }
        } catch (\Throwable $e) {
        }

        storage_api_fail('AUTH_REQUIRED', [], 401);
    }
}

if (!function_exists('storage_require_user')) {
    function storage_require_user(): array
    {
        $user = storage_session_user_row();
        if ((int)($user['id'] ?? 0) <= 0) {
            storage_api_fail('AUTH_REQUIRED', [], 401);
        }
        return $user;
    }
}

if (!function_exists('storage_user_id')) {
    function storage_user_id(): int
    {
        return (int)(storage_require_user()['id'] ?? 0);
    }
}

if (!function_exists('storage_user_bound_ton_address')) {
    function storage_user_bound_ton_address(array $user): string
    {
        return trim((string)($user['wallet_address'] ?? ''));
    }
}

if (!function_exists('storage_require_bound_ton_address')) {
    function storage_require_bound_ton_address(array $user): string
    {
        $addr = storage_user_bound_ton_address($user);
        if ($addr === '') {
            throw new \RuntimeException('TON_NOT_BOUND');
        }
        return $addr;
    }
}

if (!function_exists('storage_assert_ton_bound')) {
    function storage_assert_ton_bound(): string
    {
        $user = storage_require_user();
        $addr = storage_user_bound_ton_address($user);
        if ($addr === '') {
            storage_api_fail('TON_NOT_BOUND', [], 400);
        }
        return $addr;
    }
}

/* -------------------------------------------------
 | CSRF helpers
 * ------------------------------------------------- */

if (!function_exists('storage_csrf_scope_candidates')) {
    function storage_csrf_scope_candidates(string $scope): array
    {
        $scope = trim($scope);
        $candidates = [$scope];

        $map = [
            'bind' => ['storage_bind', 'storage_bind_card', 'bind_card'],
            'activate' => ['storage_activate', 'storage_activate_card', 'activate_card'],
            'reload' => ['storage_reload', 'storage_reload_card', 'reload_card'],
            'storage_activate_card' => [
                'activate',
                'storage_activate',
                'storage_activate_verify',
                'storage_activate_confirm',
                'activate_card',
                'activate_verify',
                'activate_confirm',
                'activate-card',
            ],
        ];

        if (isset($map[$scope])) {
            foreach ($map[$scope] as $alt) {
                $candidates[] = $alt;
            }
        }

        return array_values(array_unique(array_filter($candidates, static fn($v) => $v !== '')));
    }
}

if (!function_exists('storage_require_csrf')) {
    function storage_require_csrf(string $tokenName): void
    {
        $token = storage_post('csrf_token', '');
        if ($token === '') {
            $token = storage_post('csrf', '');
        }

        if ($token === '') {
            storage_api_fail('CSRF_INVALID', ['scope' => $tokenName, 'reason' => 'missing'], 403);
        }

        $candidates = storage_csrf_scope_candidates($tokenName);
        $ok = false;

        $csrfCheckArgc = storage_runtime_cache_get('csrf', 'csrf_check_argc', -1);
        if ($csrfCheckArgc === -1 && function_exists('csrf_check')) {
            try {
                $rf = new \ReflectionFunction('csrf_check');
                $csrfCheckArgc = $rf->getNumberOfParameters();
            } catch (\Throwable $e) {
                $csrfCheckArgc = 2;
            }
            storage_runtime_cache_set('csrf', 'csrf_check_argc', $csrfCheckArgc);
        }

        foreach ($candidates as $scope) {
            try {
                if (function_exists('csrf_check')) {
                    if ($csrfCheckArgc <= 1) {
                        $_POST['csrf_token'] = $token;
                        $res = csrf_check($scope);
                    } else {
                        $res = csrf_check($scope, $token);
                    }

                    if ($res === true || $res === 1 || $res === '1' || $res === null) {
                        $ok = true;
                        break;
                    }
                }

                if (!$ok && function_exists('csrf_verify')) {
                    $res = csrf_verify($scope, $token);
                    if ($res === true || $res === 1 || $res === '1' || $res === null) {
                        $ok = true;
                        break;
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        if (!$ok) {
            storage_api_fail('CSRF_INVALID', [
                'scope' => $tokenName,
                'tested_scopes' => $candidates,
            ], 403);
        }
    }
}

/* -------------------------------------------------
 | Generic helpers
 * ------------------------------------------------- */

if (!function_exists('storage_decimal_string')) {
    function storage_decimal_string($value, int $scale = 6): string
    {
        $raw = trim((string)$value);
        if ($raw === '' || !preg_match('/^-?\d+(\.\d+)?$/', $raw)) {
            $raw = '0';
        }

        $neg = false;
        if (str_starts_with($raw, '-')) {
            $neg = true;
            $raw = substr($raw, 1);
        }

        [$int, $frac] = array_pad(explode('.', $raw, 2), 2, '');
        $int = ltrim($int, '0');
        $int = ($int === '') ? '0' : $int;
        $frac = preg_replace('/\D+/', '', $frac) ?? '';
        $frac = substr(str_pad($frac, $scale, '0'), 0, $scale);

        $out = $int . '.' . $frac;
        if ($neg && $out !== '0.' . str_repeat('0', $scale)) {
            $out = '-' . $out;
        }
        return $out;
    }
}

if (!function_exists('storage_balance_scale_value')) {
    function storage_balance_scale_value(string $value, int $scale = 6): string
    {
        return storage_decimal_string($value, $scale);
    }
}

if (!function_exists('storage_json_extract_value')) {
    function storage_json_extract_value($meta, string $key, string $default = ''): string
    {
        if (is_array($meta)) {
            return isset($meta[$key]) ? trim((string)$meta[$key]) : $default;
        }
        if (!is_string($meta) || trim($meta) === '') {
            return $default;
        }

        $arr = json_decode($meta, true);
        if (is_array($arr) && isset($arr[$key])) {
            return trim((string)$arr[$key]);
        }
        return $default;
    }
}

/* -------------------------------------------------
 | Storage card helpers
 * ------------------------------------------------- */

if (!function_exists('storage_card_hash_value')) {
    function storage_card_hash_value(string $cardNumber): string
    {
        return hash('sha256', trim($cardNumber));
    }
}

if (!function_exists('storage_card_masked_value')) {
    function storage_card_masked_value(string $cardNumber): string
    {
        $digits = preg_replace('/\D+/', '', $cardNumber) ?? '';
        $len = strlen($digits);
        if ($len <= 4) {
            return $digits;
        }
        return str_repeat('*', $len - 4) . substr($digits, -4);
    }
}

if (!function_exists('storage_card_row')) {
    function storage_card_row(int $userId): array
    {
        $cacheKey = (string)$userId;
        $cached = storage_runtime_cache_get('card_row', $cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $pdo = storage_db();

        $stmt = $pdo->prepare("
            SELECT
                user_id,
                bound_ton_address,
                activation_ref,
                activation_tx_hash,
                card_number,
                card_hash,
                card_last4,
                card_masked,
                is_active,
                activated_at,
                created_at,
                updated_at
            FROM rwa_storage_cards
            WHERE user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (is_array($row) && !empty($row)) {
            $isActive = (int)($row['is_active'] ?? 0) === 1;
            $cardNumber = trim((string)($row['card_number'] ?? ''));

            return storage_runtime_cache_set('card_row', $cacheKey, [
                'user_id'            => $userId,
                'bound_ton_address'  => (string)($row['bound_ton_address'] ?? ''),
                'activation_ref'     => (string)($row['activation_ref'] ?? ''),
                'activation_tx_hash' => (string)($row['activation_tx_hash'] ?? ''),
                'card_number'        => $cardNumber,
                'card_hash'          => (string)($row['card_hash'] ?? ''),
                'card_last4'         => (string)($row['card_last4'] ?? ''),
                'card_masked'        => (string)($row['card_masked'] ?? ''),
                'status'             => $isActive ? 'active' : ($cardNumber !== '' ? 'draft' : 'none'),
                'locked'             => $isActive ? 1 : 0,
                'is_active'          => $isActive,
                'activated_at'       => (string)($row['activated_at'] ?? ''),
                'created_at'         => (string)($row['created_at'] ?? ''),
                'updated_at'         => (string)($row['updated_at'] ?? ''),
            ]);
        }

        return storage_runtime_cache_set('card_row', $cacheKey, [
            'user_id'            => $userId,
            'bound_ton_address'  => '',
            'activation_ref'     => '',
            'activation_tx_hash' => '',
            'card_number'        => '',
            'card_hash'          => '',
            'card_last4'         => '',
            'card_masked'        => '',
            'status'             => 'none',
            'locked'             => 0,
            'is_active'          => false,
            'activated_at'       => '',
            'created_at'         => '',
            'updated_at'         => '',
        ]);
    }
}

if (!function_exists('storage_card_get_for_user')) {
    function storage_card_get_for_user(int $userId): ?array
    {
        $row = storage_card_row($userId);
        return trim((string)($row['card_number'] ?? '')) === '' ? null : $row;
    }
}

if (!function_exists('storage_card_bound_for_user')) {
    function storage_card_bound_for_user(?int $userId = null): bool
    {
        $userId = $userId ?: storage_user_id();
        $row = storage_card_row($userId);
        return trim((string)($row['card_number'] ?? '')) !== '';
    }
}

if (!function_exists('storage_require_bound_card')) {
    function storage_require_bound_card(?int $userId = null): array
    {
        $userId = $userId ?: storage_user_id();
        $row = storage_card_row($userId);
        if (trim((string)($row['card_number'] ?? '')) === '') {
            storage_api_fail('CARD_NOT_BOUND', [], 400);
        }
        return $row;
    }
}

if (!function_exists('storage_card_is_active')) {
    function storage_card_is_active(array $cardRow): bool
    {
        return (bool)($cardRow['is_active'] ?? false) === true
            || (int)($cardRow['locked'] ?? 0) === 1
            || strtolower((string)($cardRow['status'] ?? '')) === 'active';
    }
}

if (!function_exists('storage_card_is_active_for_user')) {
    function storage_card_is_active_for_user(int $userId): bool
    {
        return storage_card_is_active(storage_card_row($userId));
    }
}

if (!function_exists('storage_card_is_editable')) {
    function storage_card_is_editable(array $cardRow): bool
    {
        return !storage_card_is_active($cardRow);
    }
}

if (!function_exists('storage_reload_allowed')) {
    function storage_reload_allowed(array $cardRow): bool
    {
        return storage_card_is_active($cardRow);
    }
}

if (!function_exists('storage_assert_card_editable')) {
    function storage_assert_card_editable(int $userId): void
    {
        $card = storage_card_row($userId);
        if ($card && storage_card_is_active($card)) {
            storage_abort('CARD_ALREADY_ACTIVE_BIND_LOCKED', 409, [
                'message' => 'Card is locked after activation. Admin release required',
                'is_active' => true,
                'locked' => 1,
            ]);
        }
    }
}

if (!function_exists('storage_save_card_number')) {
    function storage_save_card_number(int $userId, string $cardNumber): array
    {
        $pdo = storage_db();
        $user = storage_require_user();

        $cardNumber = preg_replace('/\D+/', '', (string)$cardNumber) ?? '';
        if ($userId <= 0) {
            throw new \RuntimeException('AUTH_REQUIRED');
        }

        // NOTE:
        // kept exactly as previous file behavior to avoid breaking current module expectations.
        if (!preg_match('/^\d{12}$/', $cardNumber)) {
            throw new \RuntimeException('CARD_NUMBER_INVALID');
        }

        storage_assert_card_editable($userId);

        $cardHash   = storage_card_hash_value($cardNumber);
        $cardLast4  = substr($cardNumber, -4);
        $cardMasked = storage_card_masked_value($cardNumber);
        $boundTon   = storage_user_bound_ton_address($user);

        $stmt = $pdo->prepare("
            INSERT INTO rwa_storage_cards (
                user_id,
                bound_ton_address,
                card_number,
                card_hash,
                card_last4,
                card_masked,
                is_active,
                created_at,
                updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
            ON DUPLICATE KEY UPDATE
                bound_ton_address = CASE WHEN is_active = 1 THEN bound_ton_address ELSE VALUES(bound_ton_address) END,
                card_number = CASE WHEN is_active = 1 THEN card_number ELSE VALUES(card_number) END,
                card_hash = CASE WHEN is_active = 1 THEN card_hash ELSE VALUES(card_hash) END,
                card_last4 = CASE WHEN is_active = 1 THEN card_last4 ELSE VALUES(card_last4) END,
                card_masked = CASE WHEN is_active = 1 THEN card_masked ELSE VALUES(card_masked) END,
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            $userId,
            $boundTon,
            $cardNumber,
            $cardHash,
            $cardLast4,
            $cardMasked,
        ]);

        storage_runtime_cache_set('card_row', (string)$userId, null);
        return storage_card_row($userId);
    }
}

if (!function_exists('storage_mark_card_active_for_user')) {
    function storage_mark_card_active_for_user(int $userId, string $activationRef = '', string $txHash = ''): void
    {
        $pdo = storage_db();
        $stmt = $pdo->prepare("
            UPDATE rwa_storage_cards
            SET
                is_active = 1,
                activation_ref = ?,
                activation_tx_hash = ?,
                activated_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE user_id = ?
        ");
        $stmt->execute([
            $activationRef !== '' ? $activationRef : null,
            $txHash !== '' ? $txHash : null,
            $userId,
        ]);
        storage_runtime_cache_set('card_row', (string)$userId, null);
    }
}

/* -------------------------------------------------
 | Balances / history
 * ------------------------------------------------- */

if (!function_exists('storage_balance_row')) {
    function storage_balance_row(int $userId): array
    {
        $cacheKey = (string)$userId;
        $cached = storage_runtime_cache_get('balance_row', $cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $pdo = storage_db();

        $stmt = $pdo->prepare("
            SELECT
                user_id,
                card_balance_rwa,
                onchain_emx,
                onchain_ema,
                onchain_wems,
                unclaim_ema,
                unclaim_wems,
                unclaim_gold_packet_usdt,
                unclaim_tips_emx,
                fuel_usdt_ton,
                fuel_ems,
                fuel_ton_gas,
                created_at,
                updated_at
            FROM rwa_storage_balances
            WHERE user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (is_array($row) && !empty($row)) {
            return storage_runtime_cache_set('balance_row', $cacheKey, $row);
        }

        $stmt = $pdo->prepare("
            INSERT INTO rwa_storage_balances (
                user_id,
                card_balance_rwa,
                onchain_emx,
                onchain_ema,
                onchain_wems,
                unclaim_ema,
                unclaim_wems,
                unclaim_gold_packet_usdt,
                unclaim_tips_emx,
                fuel_usdt_ton,
                fuel_ems,
                fuel_ton_gas
            ) VALUES (
                ?,
                '0.000000',
                '0.000000',
                '0.000000',
                '0.000000',
                '0.000000',
                '0.000000',
                '0.000000',
                '0.000000',
                '0.000000',
                '0.000000',
                '0.000000'
            )
        ");
        $stmt->execute([$userId]);
        storage_runtime_cache_set('balance_row', $cacheKey, null);

        return storage_balance_row($userId);
    }
}

if (!function_exists('storage_balance_touch_cache')) {
    function storage_balance_touch_cache(int $userId): void
    {
        storage_runtime_cache_set('balance_row', (string)$userId, null);
    }
}

if (!function_exists('storage_history_items')) {
    function storage_history_items(int $userId, int $limit = 20): array
    {
        $pdo = storage_db();
        $limit = max(1, min(100, $limit));

        $stmt = $pdo->prepare("
            SELECT
                id,
                user_id,
                type,
                token,
                amount,
                meta_json,
                created_at
            FROM rwa_storage_history
            WHERE user_id = ?
            ORDER BY id DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('storage_history_add')) {
    function storage_history_add(int $userId, string $type, string $token, string $amount, array $meta = []): void
    {
        $pdo = storage_db();
        $metaJson = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        try {
            $stmt = $pdo->prepare("
                INSERT INTO rwa_storage_history (
                    user_id,
                    type,
                    token,
                    amount,
                    meta_json
                ) VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                trim($type),
                trim($token) !== '' ? trim($token) : 'SYSTEM',
                storage_decimal_string($amount, 6),
                $metaJson,
            ]);
        } catch (\Throwable $e) {
            error_log('[storage_history_add] ' . $e->getMessage());
        }
    }
}

if (!function_exists('storage_history_record')) {
    function storage_history_record(int $userId, string $type, string $token, string $amount, array $meta = []): void
    {
        storage_history_add($userId, $type, $token, $amount, $meta);
    }
}

/* -------------------------------------------------
 | TON / live sync helpers
 * ------------------------------------------------- */

if (!function_exists('storage_ton_treasury_address')) {
    function storage_ton_treasury_address(): string
    {
        $addr = trim(storage_env('TON_TREASURY', storage_env('TON_TREASURY_ADDRESS', '')));
        return $addr !== '' ? $addr : STORAGE_TREASURY_TON;
    }
}

if (!function_exists('storage_ton_balance_decimal')) {
    function storage_ton_balance_decimal(string $address): string
    {
        $res = poado_toncenter_get_address_balance($address);

        if (!poado_toncenter_is_ok($res)) {
            return '0.000000';
        }

        $raw = (string)($res['result'] ?? '0');
        return poado_toncenter_to_decimal($raw, 9, 6);
    }
}

if (!function_exists('storage_token_registry_all')) {
    function storage_token_registry_all(): array
    {
        $cached = storage_runtime_cache_get('registry', 'all');
        if (is_array($cached)) {
            return $cached;
        }

        $all = [];
        if (function_exists('poado_token_registry_all')) {
            try {
                $all = poado_token_registry_all();
            } catch (\Throwable $e) {
                $all = [];
            }
        }

        return storage_runtime_cache_set('registry', 'all', is_array($all) ? $all : []);
    }
}

if (!function_exists('storage_sync_all_token_balances_live')) {
    function storage_sync_all_token_balances_live(array $user): array
    {
        $userId = (int)($user['id'] ?? 0);
        $owner  = trim((string)($user['wallet_address'] ?? ''));

        if ($userId <= 0) {
            return ['ok' => false, 'error' => 'AUTH_REQUIRED'];
        }

        $existing = storage_balance_row($userId);

        if ($owner === '') {
            return ['ok' => false, 'error' => 'TON_NOT_BOUND', 'balances' => $existing];
        }

        $registry = storage_token_registry_all();
        $apiKey   = trim(storage_env('TONCENTER_API_KEY', ''));

        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'TONCENTER_API_KEY_MISSING', 'balances' => $existing];
        }

        $map = [
            'EMA' => ['col' => 'onchain_ema', 'default_decimals' => 9],
            'EMX' => ['col' => 'onchain_emx', 'default_decimals' => 9],
            'EMS' => ['col' => 'fuel_ems', 'default_decimals' => 9],
            'WEMS' => ['col' => 'onchain_wems', 'default_decimals' => 9],
            'USDT_TON' => ['col' => 'fuel_usdt_ton', 'default_decimals' => 6],
        ];

        $next = [
            'onchain_ema'   => storage_balance_scale_value((string)($existing['onchain_ema'] ?? '0')),
            'onchain_emx'   => storage_balance_scale_value((string)($existing['onchain_emx'] ?? '0')),
            'fuel_ems'      => storage_balance_scale_value((string)($existing['fuel_ems'] ?? '0')),
            'onchain_wems'  => storage_balance_scale_value((string)($existing['onchain_wems'] ?? '0')),
            'fuel_usdt_ton' => storage_balance_scale_value((string)($existing['fuel_usdt_ton'] ?? '0')),
            'fuel_ton_gas'  => storage_balance_scale_value((string)($existing['fuel_ton_gas'] ?? '0')),
        ];

        $ownerCanon = storage_addr_canon($owner);
        $debug = [];

        foreach ($map as $tokenKey => $cfg) {
            $master = trim((string)($registry[$tokenKey]['master_raw'] ?? ''));
            if ($master === '') {
                $next[$cfg['col']] = '0.000000';
                $debug[$tokenKey] = [
                    'ok' => false,
                    'error' => 'MASTER_MISSING',
                ];
                continue;
            }

            $decimals = (int)($registry[$tokenKey]['decimals'] ?? $cfg['default_decimals']);
            if ($decimals < 0) {
                $decimals = $cfg['default_decimals'];
            }

            $masterCanon = storage_addr_canon($master);
            $walletUrl = storage_toncenter_base()
                . '/jetton/wallets?owner_address=' . rawurlencode($owner)
                . '&jetton_address=' . rawurlencode($master)
                . '&limit=20';

            try {
                $walletJson = storage_toncenter_get_json($walletUrl);
                $rows = $walletJson['jetton_wallets'] ?? $walletJson['result'] ?? [];
                if (!is_array($rows)) {
                    $rows = [];
                }

                $best = null;
                $bestScore = -1;

                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $rowOwner = trim((string)(
                        $row['owner'] ??
                        $row['owner_address'] ??
                        $row['owner_wallet'] ??
                        ($row['account']['owner'] ?? '') ??
                        ''
                    ));

                    $rowMaster = trim((string)(
                        $row['jetton'] ??
                        $row['jetton_master'] ??
                        $row['jetton_address'] ??
                        ($row['account']['jetton'] ?? '') ??
                        ''
                    ));

                    $rowWallet = trim((string)(
                        $row['address'] ??
                        $row['wallet_address'] ??
                        $row['jetton_wallet'] ??
                        ''
                    ));

                    $rowBalance = trim((string)($row['balance'] ?? '0'));

                    $score = 0;

                    if ($rowOwner !== '' && storage_addr_canon($rowOwner) === $ownerCanon) {
                        $score += 100;
                    }
                    if ($rowMaster !== '' && storage_addr_canon($rowMaster) === $masterCanon) {
                        $score += 100;
                    }
                    if ($rowWallet !== '') {
                        $score += 10;
                    }
                    if ($rowBalance !== '' && preg_match('/^\d+$/', $rowBalance)) {
                        $score += 5;
                        if ($rowBalance !== '0') {
                            $score += 1;
                        }
                    }

                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = [
                            'wallet_address' => $rowWallet,
                            'owner' => $rowOwner,
                            'master' => $rowMaster,
                            'balance_raw' => (preg_match('/^\d+$/', $rowBalance) ? $rowBalance : '0'),
                            'score' => $score,
                        ];
                    }
                }

                if (
                    is_array($best)
                    && storage_addr_canon((string)$best['owner']) === $ownerCanon
                    && storage_addr_canon((string)$best['master']) === $masterCanon
                ) {
                    $next[$cfg['col']] = poado_toncenter_to_decimal((string)$best['balance_raw'], $decimals, 6);
                    $debug[$tokenKey] = [
                        'ok' => true,
                        'source' => 'jetton_wallets_exact_match',
                        'wallet_address' => (string)$best['wallet_address'],
                        'balance_raw' => (string)$best['balance_raw'],
                        'decimals' => $decimals,
                    ];
                } else {
                    $next[$cfg['col']] = '0.000000';
                    $debug[$tokenKey] = [
                        'ok' => true,
                        'source' => 'jetton_wallets_no_exact_match_zeroed',
                        'wallet_address' => is_array($best) ? (string)$best['wallet_address'] : '',
                        'balance_raw' => is_array($best) ? (string)$best['balance_raw'] : '0',
                        'decimals' => $decimals,
                    ];
                }
            } catch (\Throwable $e) {
                $next[$cfg['col']] = storage_balance_scale_value((string)($existing[$cfg['col']] ?? '0'));
                $debug[$tokenKey] = [
                    'ok' => false,
                    'error' => $e->getMessage(),
                    'source' => 'existing_fallback',
                ];
            }
        }

        try {
            $next['fuel_ton_gas'] = storage_decimal_string(storage_ton_balance_decimal($owner), 6);
            $debug['TON'] = [
                'ok' => true,
                'source' => 'get_address_balance',
                'balance' => $next['fuel_ton_gas'],
            ];
        } catch (\Throwable $e) {
            $next['fuel_ton_gas'] = storage_balance_scale_value((string)($existing['fuel_ton_gas'] ?? '0'));
            $debug['TON'] = [
                'ok' => false,
                'error' => $e->getMessage(),
                'source' => 'existing_fallback',
            ];
        }

        $pdo = storage_db();
        $stmt = $pdo->prepare("
            UPDATE rwa_storage_balances
            SET
                onchain_ema = ?,
                onchain_emx = ?,
                fuel_ems = ?,
                onchain_wems = ?,
                fuel_usdt_ton = ?,
                fuel_ton_gas = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE user_id = ?
        ");
        $stmt->execute([
            $next['onchain_ema'],
            $next['onchain_emx'],
            $next['fuel_ems'],
            $next['onchain_wems'],
            $next['fuel_usdt_ton'],
            $next['fuel_ton_gas'],
            $userId,
        ]);

        storage_balance_touch_cache($userId);

        return [
            'ok' => true,
            'owner' => $owner,
            'balances' => storage_balance_row($userId),
            'debug' => $debug,
        ];
    }
}

if (!function_exists('storage_sync_onchain_balances')) {
    function storage_sync_onchain_balances(array $user): array
    {
        $userId = (int)($user['id'] ?? 0);
        $address = storage_user_bound_ton_address($user);

        if ($userId <= 0) {
            return ['ok' => false, 'error' => 'AUTH_REQUIRED'];
        }

        $existing = storage_balance_row($userId);

        if ($address === '') {
            return [
                'ok' => false,
                'error' => 'TON_NOT_BOUND',
                'address' => '',
                'balances' => $existing,
                'sync' => [
                    'address' => '',
                    'synced_at' => (string)($existing['updated_at'] ?? ''),
                    'live_ok' => false,
                    'source' => 'stored_only',
                ],
            ];
        }

        $live = storage_sync_all_token_balances_live($user);
        if (($live['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'error' => (string)($live['error'] ?? 'LIVE_SYNC_FAILED'),
                'address' => $address,
                'balances' => is_array($live['balances'] ?? null) ? $live['balances'] : $existing,
                'sync' => [
                    'address' => $address,
                    'synced_at' => (string)(($existing['updated_at'] ?? '') ?: gmdate('c')),
                    'live_ok' => false,
                    'source' => 'last_known_good',
                ],
            ];
        }

        return [
            'ok' => true,
            'address' => $address,
            'balances' => storage_balance_row($userId),
            'sync' => [
                'address' => $address,
                'synced_at' => gmdate('c'),
                'live_ok' => true,
                'source' => 'live_sync',
            ],
        ];
    }
}

/* -------------------------------------------------
 | Activation verifier master
 | FINAL RULE:
 |   if jetton + amount + ref match => ACCEPT
 * ------------------------------------------------- */

if (!function_exists('storage_activation_token')) {
    function storage_activation_token(): string
    {
        return 'EMX';
    }
}

if (!function_exists('storage_activation_decimals')) {
    function storage_activation_decimals(): int
    {
        $cached = storage_runtime_cache_get('activation', 'decimals');
        if (is_int($cached) && $cached > 0) {
            return $cached;
        }

        $all = storage_token_registry_all();
        $dec = (int)($all['EMX']['decimals'] ?? 9);
        if ($dec <= 0) {
            $dec = STORAGE_EMX_DECIMALS;
        }

        return (int)storage_runtime_cache_set('activation', 'decimals', $dec);
    }
}

if (!function_exists('storage_activation_required_emx')) {
    function storage_activation_required_emx(): string
    {
        return '100.000000';
    }
}

if (!function_exists('storage_activation_required_units')) {
    function storage_activation_required_units(): string
    {
        return STORAGE_ACTIVATE_UNITS;
    }
}

if (!function_exists('storage_activation_emx_master')) {
    function storage_activation_emx_master(): string
    {
        $cached = storage_runtime_cache_get('activation', 'emx_master');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $all = storage_token_registry_all();
        $m = trim((string)($all['EMX']['master_raw'] ?? ''));
        if ($m === '') {
            $m = STORAGE_EMX_MASTER_RAW;
        }

        return (string)storage_runtime_cache_set('activation', 'emx_master', $m);
    }
}

if (!function_exists('storage_addr_canon')) {
    function storage_addr_canon(string $v): string
    {
        $v = strtolower(trim($v));
        $v = preg_replace('/^0:/', '', $v);
        $v = preg_replace('/^eq/', '', $v);
        $v = preg_replace('/^uq/', '', $v);
        return (string)$v;
    }
}

if (!function_exists('storage_toncenter_base')) {
    function storage_toncenter_base(): string
    {
        $cached = storage_runtime_cache_get('toncenter', 'base');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        return (string)storage_runtime_cache_set('toncenter', 'base', rtrim(storage_env('TONCENTER_BASE', 'https://toncenter.com/api/v3'), '/'));
    }
}

if (!function_exists('storage_toncenter_api_key')) {
    function storage_toncenter_api_key(): string
    {
        $cached = storage_runtime_cache_get('toncenter', 'api_key');
        if (is_string($cached)) {
            return $cached;
        }
        return (string)storage_runtime_cache_set('toncenter', 'api_key', trim(storage_env('TONCENTER_API_KEY', '')));
    }
}

if (!function_exists('storage_toncenter_get_json')) {
    function storage_toncenter_get_json(string $url): array
    {
        $headers = [];
        $apiKey = storage_toncenter_api_key();
        if ($apiKey !== '') {
            $headers[] = 'X-API-Key: ' . $apiKey;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $res = curl_exec($ch);
        $err = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($res === false) {
            throw new \RuntimeException('TONCENTER_CURL_ERROR: ' . $err);
        }

        $json = json_decode($res, true);
        if (!is_array($json)) {
            throw new \RuntimeException('TONCENTER_INVALID_JSON');
        }

        if ($http >= 400) {
            throw new \RuntimeException('TONCENTER_HTTP_' . $http);
        }

        return $json;
    }
}

if (!function_exists('storage_payload_decode_maybe')) {
    function storage_payload_decode_maybe(string $v): string
    {
        $v = trim($v);
        if ($v === '' || !preg_match('/^[A-Za-z0-9+\/=_-]+$/', $v)) {
            return '';
        }

        $norm = str_replace(['-', '_'], ['+', '/'], $v);
        $pad = strlen($norm) % 4;
        if ($pad > 0) {
            $norm .= str_repeat('=', 4 - $pad);
        }

        $bin = base64_decode($norm, true);
        if ($bin === false || $bin === '') {
            return '';
        }

        return @mb_convert_encoding($bin, 'UTF-8', 'UTF-8') ?: '';
    }
}

if (!function_exists('storage_extract_payload_texts')) {
    function storage_extract_payload_texts(array $tx): array
    {
        $raws = [];
        foreach ([
            'decoded_forward_payload',
            'forward_payload',
            'comment',
            'text',
            'msg_data_text',
            'decoded_comment',
        ] as $k) {
            if (isset($tx[$k]) && $tx[$k] !== null && trim((string)$tx[$k]) !== '') {
                $raws[] = (string)$tx[$k];
            }
        }

        $decoded = [];
        foreach ($raws as $raw) {
            $maybe = storage_payload_decode_maybe($raw);
            if ($maybe !== '') {
                $decoded[] = $maybe;
            }
        }

        return array_values(array_unique(array_merge($raws, $decoded)));
    }
}

if (!function_exists('storage_payload_contains_ref')) {
    function storage_payload_contains_ref(array $tx, string $ref): bool
    {
        $needle = strtolower(trim($ref));
        if ($needle === '') {
            return false;
        }

        foreach (storage_extract_payload_texts($tx) as $txt) {
            if (strpos(strtolower($txt), $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('storage_activation_prepare')) {
    function storage_activation_prepare(int $userId, string $tonAddress): array
    {
        $pdo = storage_db();
        $ref = 'ACT-' . gmdate('YmdHis') . '-' . strtoupper(substr(md5($userId . '|' . $tonAddress . '|' . microtime(true)), 0, 8));

        $stmt = $pdo->prepare("
            UPDATE rwa_storage_cards
            SET
                bound_ton_address = ?,
                activation_ref = ?,
                activation_tx_hash = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE user_id = ?
        ");
        $stmt->execute([$tonAddress, $ref, $userId]);
        storage_runtime_cache_set('card_row', (string)$userId, null);

        return [
            'activation_ref' => $ref,
            'status' => 'prepared',
            'verified' => false,
            'is_active' => false,
            'token' => storage_activation_token(),
            'decimals' => storage_activation_decimals(),
            'required_emx' => storage_activation_required_emx(),
            'required_units' => storage_activation_required_units(),
            'wallet_address' => $tonAddress,
            'receiver_wallet' => storage_ton_treasury_address(),
            'jetton_master' => storage_activation_emx_master(),
            'created_at_utc' => gmdate('c'),
            'verified_at_utc' => '',
            'tx_hash' => '',
        ];
    }
}

if (!function_exists('storage_activation_load_open_ref')) {
    function storage_activation_load_open_ref(int $userId): ?array
    {
        $card = storage_card_row($userId);
        $ref = trim((string)($card['activation_ref'] ?? ''));
        if ($ref === '') {
            return null;
        }

        return [
            'activation_ref' => $ref,
            'status' => storage_card_is_active($card) ? 'active' : 'prepared',
            'verified' => storage_card_is_active($card),
            'is_active' => storage_card_is_active($card),
            'token' => storage_activation_token(),
            'decimals' => storage_activation_decimals(),
            'required_emx' => storage_activation_required_emx(),
            'required_units' => storage_activation_required_units(),
            'wallet_address' => (string)($card['bound_ton_address'] ?? ''),
            'receiver_wallet' => storage_ton_treasury_address(),
            'jetton_master' => storage_activation_emx_master(),
            'created_at_utc' => (string)($card['updated_at'] ?? ''),
            'verified_at_utc' => (string)($card['activated_at'] ?? ''),
            'tx_hash' => (string)($card['activation_tx_hash'] ?? ''),
        ];
    }
}

if (!function_exists('storage_activation_get')) {
    function storage_activation_get(int $userId): ?array
    {
        return storage_activation_load_open_ref($userId);
    }
}

if (!function_exists('storage_activation_tx_find_by_ref')) {
    function storage_activation_tx_find_by_ref(\PDO $pdo, string $activationRef): ?array
    {
        $cacheKey = 'ref:' . $activationRef;
        $cached = storage_runtime_cache_get('activation_tx', $cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        if ($cached === false) {
            return null;
        }

        $st = $pdo->prepare("SELECT * FROM rwa_storage_activation_txs WHERE activation_ref = ? LIMIT 1");
        $st->execute([$activationRef]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            storage_runtime_cache_set('activation_tx', $cacheKey, $row);
            return $row;
        }
        storage_runtime_cache_set('activation_tx', $cacheKey, false);
        return null;
    }
}

if (!function_exists('storage_activation_tx_find_by_hash')) {
    function storage_activation_tx_find_by_hash(\PDO $pdo, string $txHash): ?array
    {
        $cacheKey = 'hash:' . $txHash;
        $cached = storage_runtime_cache_get('activation_tx', $cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        if ($cached === false) {
            return null;
        }

        $st = $pdo->prepare("SELECT * FROM rwa_storage_activation_txs WHERE tx_hash = ? LIMIT 1");
        $st->execute([$txHash]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            storage_runtime_cache_set('activation_tx', $cacheKey, $row);
            return $row;
        }
        storage_runtime_cache_set('activation_tx', $cacheKey, false);
        return null;
    }
}

if (!function_exists('storage_activation_tx_insert_verified')) {
    function storage_activation_tx_insert_verified(\PDO $pdo, array $v): void
    {
        $st = $pdo->prepare("
            INSERT INTO rwa_storage_activation_txs
            (user_id, activation_ref, tx_hash, amount_units, jetton_master_raw, source_raw, destination_raw, payload_text, status)
            VALUES
            (:user_id, :activation_ref, :tx_hash, :amount_units, :jetton_master_raw, :source_raw, :destination_raw, :payload_text, 'verified')
        ");
        $st->execute([
            ':user_id'           => (int)$v['user_id'],
            ':activation_ref'    => (string)$v['activation_ref'],
            ':tx_hash'           => (string)$v['tx_hash'],
            ':amount_units'      => (string)$v['amount_units'],
            ':jetton_master_raw' => (string)$v['jetton_master_raw'],
            ':source_raw'        => (string)($v['source_raw'] ?? ''),
            ':destination_raw'   => (string)($v['destination_raw'] ?? ''),
            ':payload_text'      => (string)($v['payload_text'] ?? ''),
        ]);

        storage_runtime_cache_set('activation_tx', 'ref:' . (string)$v['activation_ref'], null);
        storage_runtime_cache_set('activation_tx', 'hash:' . (string)$v['tx_hash'], null);
    }
}

if (!function_exists('storage_activation_result')) {
    function storage_activation_result(
        bool $verified,
        string $mode,
        string $status,
        bool $reloadAllowed,
        ?string $error = null,
        ?string $txHash = null,
        ?string $activatedAt = null,
        array $extra = []
    ): array {
        return array_merge([
            'ok' => true,
            'verified' => $verified,
            'verify_mode' => $mode,
            'status' => $status,
            'is_active' => ($status === 'active'),
            'reload_allowed' => $reloadAllowed,
            'tx_hash' => $txHash,
            'activated_at' => $activatedAt,
            'error' => $error,
        ], $extra);
    }
}

if (!function_exists('storage_verify_emx_activation_auto')) {
    function storage_verify_emx_activation_auto(int $userId, string $activationRef, array $opts = []): array
    {
        $pdo = storage_db();
        $user = storage_require_user();
        $card = storage_card_row($userId);

        try {
            $from = storage_require_bound_ton_address($user);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'code' => 'TON_NOT_BOUND',
                'message' => $e->getMessage(),
                'reload_enabled' => false,
            ];
        }

        if (trim((string)($card['card_number'] ?? '')) === '') {
            return [
                'ok' => false,
                'code' => 'CARD_NOT_BOUND',
                'message' => 'Card not bound',
                'reload_enabled' => false,
            ];
        }

        if (storage_card_is_active($card)) {
            return [
                'ok' => true,
                'code' => 'ALREADY_ACTIVE',
                'message' => 'Card already active',
                'activation_ref' => $activationRef,
                'tx_hash' => (string)($card['activation_tx_hash'] ?? ''),
                'reload_enabled' => true,
            ];
        }

        if (trim((string)($card['activation_ref'] ?? '')) !== $activationRef) {
            return [
                'ok' => false,
                'code' => 'ACTIVATION_REF_MISMATCH',
                'message' => 'Activation ref mismatch',
                'reload_enabled' => false,
            ];
        }

        if ($existingRef = storage_activation_tx_find_by_ref($pdo, $activationRef)) {
            storage_mark_card_active_for_user($userId, $activationRef, (string)$existingRef['tx_hash']);
            return [
                'ok' => true,
                'code' => 'ALREADY_VERIFIED',
                'message' => 'Activation already verified',
                'activation_ref' => $activationRef,
                'tx_hash' => (string)$existingRef['tx_hash'],
                'reload_enabled' => true,
            ];
        }

        $txHint = trim((string)($opts['tx_hint'] ?? ''));
        $url =
            storage_toncenter_base() . '/jetton/transfers'
            . '?owner_address=' . rawurlencode($from)
            . '&direction=out'
            . '&jetton_master=' . rawurlencode(storage_activation_emx_master())
            . '&limit=100';

        $json = storage_toncenter_get_json($url);
        $list = $json['jetton_transfers'] ?? $json['result'] ?? [];
        if (!is_array($list)) {
            $list = [];
        }

        $debug = [];
        $requiredMasterCanon = storage_addr_canon(storage_activation_emx_master());
        $requiredUnits = storage_activation_required_units();

        foreach ($list as $tx) {
            $txHash = trim((string)($tx['transaction_hash'] ?? $tx['hash'] ?? ''));
            $jetton = trim((string)($tx['jetton_master'] ?? $tx['jetton'] ?? $tx['jetton_address'] ?? ''));
            $amount = trim((string)($tx['amount'] ?? ''));
            $source = trim((string)($tx['source'] ?? $tx['sender'] ?? $tx['from'] ?? ''));
            $dest   = trim((string)($tx['destination'] ?? $tx['recipient'] ?? $tx['to'] ?? ''));
            $payloadText = implode(' | ', storage_extract_payload_texts($tx));

            $matchJetton = storage_addr_canon($jetton) === $requiredMasterCanon;
            $matchAmount = $amount === $requiredUnits;
            $matchRef    = storage_payload_contains_ref($tx, $activationRef);
            $matchTxHint = $txHint === '' ? true : ($txHash === $txHint);

            $verdict = [
                'tx_hash' => $txHash,
                'source_raw' => $source,
                'destination_raw' => $dest,
                'jetton_raw' => $jetton,
                'amount_units' => $amount,
                'payload_text' => $payloadText,
                'match_jetton' => $matchJetton,
                'match_amount' => $matchAmount,
                'match_ref' => $matchRef,
                'match_tx_hint' => $matchTxHint,
            ];

            if ($matchJetton || $matchAmount || $matchRef) {
                $debug[] = $verdict;
            }

            if (!$matchTxHint) {
                continue;
            }

            if ($matchJetton && $matchAmount && $matchRef) {
                if ($existingHash = storage_activation_tx_find_by_hash($pdo, $txHash)) {
                    storage_mark_card_active_for_user($userId, $activationRef, (string)$existingHash['tx_hash']);
                    return [
                        'ok' => true,
                        'code' => 'ALREADY_VERIFIED',
                        'message' => 'Transaction already consumed',
                        'activation_ref' => $activationRef,
                        'tx_hash' => $txHash,
                        'reload_enabled' => true,
                    ];
                }

                $verified = [
                    'user_id' => $userId,
                    'activation_ref' => $activationRef,
                    'tx_hash' => $txHash,
                    'amount_units' => $amount,
                    'jetton_master_raw' => storage_activation_emx_master(),
                    'source_raw' => $source,
                    'destination_raw' => $dest,
                    'payload_text' => $payloadText,
                ];

                $startedTxn = false;
                if (!$pdo->inTransaction()) {
                    $pdo->beginTransaction();
                    $startedTxn = true;
                }

                try {
                    storage_activation_tx_insert_verified($pdo, $verified);
                    storage_mark_card_active_for_user($userId, $activationRef, $txHash);
                    if ($startedTxn) {
                        $pdo->commit();
                    }
                } catch (\Throwable $e) {
                    if ($startedTxn && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }

                storage_history_record($userId, 'activate_card', storage_activation_token(), storage_activation_required_emx(), [
                    'activation_ref' => $activationRef,
                    'tx_hash' => $txHash,
                    'verified' => true,
                    'status' => 'activated',
                    'source_raw' => $source,
                    'destination_raw' => $dest,
                    'required_units' => storage_activation_required_units(),
                    'jetton_master' => storage_activation_emx_master(),
                    'payload_text' => $payloadText,
                ]);

                return [
                    'ok' => true,
                    'code' => 'ACTIVATION_CONFIRMED',
                    'message' => 'Activation verified',
                    'activation_ref' => $activationRef,
                    'tx_hash' => $txHash,
                    'reload_enabled' => true,
                    'debug' => $debug,
                ];
            }
        }

        return [
            'ok' => false,
            'code' => 'NO_MATCH',
            'message' => 'No matching activation transfer found',
            'activation_ref' => $activationRef,
            'reload_enabled' => false,
            'debug' => $debug,
        ];
    }
}

/* -------------------------------------------------
 | Activation reward helpers (v7.4b add-on)
 * ------------------------------------------------- */

if (!function_exists('storage_activation_ema_price_snapshot')) {
    function storage_activation_ema_price_snapshot(): string
    {
        $cached = storage_runtime_cache_get('activation_reward', 'ema_price');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $fallback = '0.115898';
        $path = $_SERVER['DOCUMENT_ROOT'] . '/rwa/api/global/ema-price.php';

        try {
            if (is_file($path)) {
                require_once $path;

                if (function_exists('poado_ema_price_now')) {
                    $v = poado_ema_price_now();
                    $price = is_array($v) ? ($v['price'] ?? null) : $v;
                    if (is_numeric((string)$price) && (float)$price > 0) {
                        return (string)storage_runtime_cache_set(
                            'activation_reward',
                            'ema_price',
                            number_format((float)$price, 6, '.', '')
                        );
                    }
                }

                if (function_exists('poado_ema_price_config')) {
                    $cfg = poado_ema_price_config();

                    $startPrice = (float)($cfg['start_price'] ?? 0.1);
                    $endPrice   = (float)($cfg['end_price'] ?? 100.0);
                    $startDate  = (string)($cfg['start_date'] ?? '2026-01-01 00:00:00 UTC');
                    $endDate    = (string)($cfg['end_date'] ?? '2036-01-01 00:00:00 UTC');

                    $startTs = strtotime($startDate);
                    $endTs   = strtotime($endDate);
                    $nowTs   = time();

                    if ($startTs && $endTs && $endTs > $startTs && $startPrice > 0 && $endPrice > 0) {
                        if ($nowTs <= $startTs) {
                            return (string)storage_runtime_cache_set('activation_reward', 'ema_price', number_format($startPrice, 6, '.', ''));
                        }
                        if ($nowTs >= $endTs) {
                            return (string)storage_runtime_cache_set('activation_reward', 'ema_price', number_format($endPrice, 6, '.', ''));
                        }

                        $ratio = ($nowTs - $startTs) / ($endTs - $startTs);
                        $price = $startPrice * pow(($endPrice / $startPrice), $ratio);

                        if ($price > 0) {
                            return (string)storage_runtime_cache_set('activation_reward', 'ema_price', number_format($price, 6, '.', ''));
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        return (string)storage_runtime_cache_set('activation_reward', 'ema_price', $fallback);
    }
}

if (!function_exists('storage_activation_reward_amount')) {
    function storage_activation_reward_amount(string $price): string
    {
        $p = (float)$price;
        if ($p <= 0) {
            throw new \RuntimeException('INVALID_EMA_PRICE');
        }

        $reward = 100 / $p;
        return number_format($reward, STORAGE_ACTIVATION_REWARD_SCALE, '.', '');
    }
}

if (!function_exists('storage_activation_reward_exists')) {
    function storage_activation_reward_exists(\PDO $pdo, string $activationRef): bool
    {
        $activationRef = trim($activationRef);
        if ($activationRef === '') {
            return false;
        }

        $cacheKey = $activationRef;
        $cached = storage_runtime_cache_get('activation_reward_exists', $cacheKey);
        if ($cached !== null) {
            return (bool)$cached;
        }

        $sql = "
            SELECT 1
            FROM rwa_storage_history
            WHERE type = 'activate_card_reward'
              AND (
                    (JSON_VALID(meta_json) AND JSON_UNQUOTE(JSON_EXTRACT(meta_json, '$.activation_ref')) = :ref1)
                 OR meta_json LIKE :ref2
              )
            LIMIT 1
        ";

        $st = $pdo->prepare($sql);
        $st->execute([
            ':ref1' => $activationRef,
            ':ref2' => '%"activation_ref":"' . str_replace(['%', '_'], ['\\%', '\\_'], $activationRef) . '"%',
        ]);

        $exists = (bool)$st->fetchColumn();
        storage_runtime_cache_set('activation_reward_exists', $cacheKey, $exists);
        return $exists;
    }
}

if (!function_exists('storage_activation_credit_ema_reward')) {
    function storage_activation_credit_ema_reward(int $userId, string $activationRef, string $txHash = ''): array
    {
        if ($userId <= 0) {
            throw new \RuntimeException('INVALID_USER_ID');
        }

        $activationRef = trim($activationRef);
        if ($activationRef === '') {
            throw new \RuntimeException('ACTIVATION_REF_REQUIRED');
        }

        $pdo = storage_db();

        $emaPrice = storage_activation_ema_price_snapshot();
        $emaReward = storage_activation_reward_amount($emaPrice);

        if (storage_activation_reward_exists($pdo, $activationRef)) {
            return [
                'credited' => false,
                'already_rewarded' => true,
                'ema_price_snapshot' => $emaPrice,
                'ema_reward' => $emaReward,
                'reward_token' => 'EMA',
                'reward_status' => 'already_rewarded',
            ];
        }

        $meta = [
            'source' => 'storage_activation_v7_4b',
            'activation_ref' => $activationRef,
            'tx_hash' => $txHash,
            'ema_price_snapshot' => $emaPrice,
            'ema_reward' => $emaReward,
            'reward_token' => 'EMA',
            'reward_status' => 'credited_to_unclaim_ema',
        ];

        $startedTxn = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTxn = true;
        }

        try {
            $pdo->prepare("
                INSERT INTO rwa_storage_balances (user_id, unclaim_ema)
                VALUES (:user_id, 0)
                ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)
            ")->execute([
                ':user_id' => $userId,
            ]);

            $pdo->prepare("
                UPDATE rwa_storage_balances
                SET unclaim_ema = COALESCE(unclaim_ema, 0) + :reward
                WHERE user_id = :user_id
                LIMIT 1
            ")->execute([
                ':reward'  => $emaReward,
                ':user_id' => $userId,
            ]);

            storage_balance_touch_cache($userId);

            $pdo->prepare("
                INSERT INTO rwa_storage_history
                    (user_id, type, token, amount, meta_json, created_at)
                VALUES
                    (:user_id, 'activate_card_reward', 'EMA', :amount, :meta_json, NOW())
            ")->execute([
                ':user_id'   => $userId,
                ':amount'    => $emaReward,
                ':meta_json' => json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);

            storage_runtime_cache_set('activation_reward_exists', $activationRef, true);

            if ($startedTxn && $pdo->inTransaction()) {
                $pdo->commit();
            }

            return [
                'credited' => true,
                'already_rewarded' => false,
                'ema_price_snapshot' => $emaPrice,
                'ema_reward' => $emaReward,
                'reward_token' => 'EMA',
                'reward_status' => 'credited_to_unclaim_ema',
            ];
        } catch (\Throwable $e) {
            if ($startedTxn && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('storage_activation_success_payload')) {
    function storage_activation_success_payload(int $userId, string $activationRef, string $txHash = ''): array
    {
        $reward = storage_activation_credit_ema_reward($userId, $activationRef, $txHash);

        return [
            'ema_price_snapshot' => $reward['ema_price_snapshot'],
            'ema_reward' => $reward['ema_reward'],
            'reward_token' => $reward['reward_token'],
            'reward_status' => $reward['reward_status'],
            'already_rewarded' => !empty($reward['already_rewarded']),
        ];
    }
}

/* -------------------------------------------------
 | Prepare / wrapper helpers
 * ------------------------------------------------- */

if (!function_exists('storage_make_activation_ref')) {
    function storage_make_activation_ref(int $userId, string $tonAddress = ''): string
    {
        return 'ACT-' . gmdate('YmdHis') . '-' . strtoupper(substr(md5($userId . '|' . $tonAddress . '|' . microtime(true)), 0, 8));
    }
}

if (!function_exists('storage_build_ton_transfer_uri')) {
    function storage_build_ton_transfer_uri(string $amountUnits, string $text): string
    {
        $params = [
            'jetton' => storage_activation_emx_master(),
            'amount' => $amountUnits,
            'text'   => $text,
        ];
        return 'ton://transfer/' . storage_ton_treasury_address() . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}

if (!function_exists('storage_activation_prepare_payload')) {
    function storage_activation_prepare_payload(int $userId): array
    {
        $user = storage_require_user();
        $tonAddress = storage_require_bound_ton_address($user);
        $card = storage_require_bound_card($userId);

        if (storage_card_is_active($card)) {
            storage_api_fail('ALREADY_ACTIVE', [
                'card_number' => (string)($card['card_number'] ?? ''),
            ], 409);
        }

        $payload = storage_activation_prepare($userId, $tonAddress);
        $ref = (string)$payload['activation_ref'];

        $emaPriceSnapshot = '';
        $emaReward = '';
        try {
            $emaPriceSnapshot = storage_activation_ema_price_snapshot();
            $emaReward = storage_activation_reward_amount($emaPriceSnapshot);
        } catch (\Throwable $e) {
        }

        return [
            'status' => 'ACTIVATION_PREPARED',
            'user_id' => $userId,
            'card_number' => (string)($card['card_number'] ?? ''),
            'activation_ref' => $ref,
            'amount_emx' => STORAGE_ACTIVATE_EMX,
            'amount_units' => STORAGE_ACTIVATE_UNITS,
            'jetton_master' => storage_activation_emx_master(),
            'treasury' => storage_ton_treasury_address(),
            'text' => $ref,
            'ton_transfer_uri' => storage_build_ton_transfer_uri(STORAGE_ACTIVATE_UNITS, $ref),
            'wallet_address' => $tonAddress,
            'ema_price_snapshot' => $emaPriceSnapshot,
            'ema_reward' => $emaReward,
            'reward_token' => 'EMA',
            'reward_status' => 'preview_only_until_confirmed',
        ];
    }
}

if (!function_exists('storage_prepare_reload_response')) {
    function storage_prepare_reload_response(int $userId): array
    {
        $card = storage_require_bound_card($userId);

        if (!storage_card_is_active($card)) {
            storage_api_fail('CARD_NOT_ACTIVE', [
                'card_number' => (string)($card['card_number'] ?? ''),
            ], 409);
        }

        return [
            'status' => 'RELOAD_PREPARED',
            'user_id' => $userId,
            'card_number' => (string)($card['card_number'] ?? ''),
            'message' => 'Reload is enabled for active card.',
        ];
    }
}

/* -------------------------------------------------
 | Overview payload helper
 * ------------------------------------------------- */

if (!function_exists('storage_overview_payload')) {
    function storage_overview_payload(array $user, bool $withSync = true): array
    {
        $userId = (int)($user['id'] ?? 0);

        $sync = null;
        if ($withSync && storage_user_bound_ton_address($user) !== '') {
            $synced = storage_sync_onchain_balances($user);
            if (($synced['ok'] ?? false) === true) {
                $sync = $synced['sync'] ?? null;
            } else {
                $sync = [
                    'address' => storage_user_bound_ton_address($user),
                    'live_ok' => false,
                    'error' => (string)($synced['error'] ?? ''),
                    'synced_at' => (string)(($synced['sync']['synced_at'] ?? '') ?: gmdate('c')),
                    'source' => (string)($synced['sync']['source'] ?? 'last_known_good'),
                ];
            }
        }

        return [
            'user' => [
                'id' => $userId,
                'wallet' => (string)($user['wallet'] ?? ''),
                'wallet_address' => (string)($user['wallet_address'] ?? ''),
                'nickname' => (string)($user['nickname'] ?? ''),
                'email' => (string)($user['email'] ?? ''),
                'email_verified_at' => (string)($user['email_verified_at'] ?? ''),
            ],
            'wallet_address' => (string)($user['wallet_address'] ?? ''),
            'address' => (string)($user['wallet_address'] ?? ''),
            'card' => storage_card_row($userId),
            'balances' => storage_balance_row($userId),
            'activation' => storage_activation_get($userId),
            'sync' => $sync,
        ];
    }
}
