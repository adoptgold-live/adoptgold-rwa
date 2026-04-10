<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/storage/activate-card/_bootstrap.php
 * Storage Master v7.7 — Activate Card
 * FINAL-LOCK-1
 *
 * Locked flow:
 * - exact 100 EMX
 * - ACT ref required in payload text
 * - destination NOT required
 * - shared verifier = /rwa/inc/core/onchain-verify.php
 * - activation success credits free EMA reward to rwa_storage_balances.unclaim_ema
 *
 * Root entry:
 *   require __DIR__ . '/activate-card/_bootstrap.php';
 *   storage_activate_handle();
 */

require_once dirname(__DIR__, 3) . '/inc/core/bootstrap.php';
require_once dirname(__DIR__, 3) . '/inc/core/session-user.php';
require_once dirname(__DIR__, 3) . '/inc/core/csrf.php';
require_once dirname(__DIR__, 3) . '/inc/core/onchain-verify.php';

if (!defined('STORAGE_ACTIVATE_VERSION')) {
    define('STORAGE_ACTIVATE_VERSION', 'FINAL-LOCK-1');
}

if (!defined('STORAGE_ACTIVATE_FILE')) {
    define('STORAGE_ACTIVATE_FILE', __FILE__);
}

if (!function_exists('storage_activate_handle')) {
    function storage_activate_handle(): void
    {
        try {
            $mode = storage_activate_request_mode();

            switch ($mode) {
                case 'prepare':
                    storage_activate_require_post();
                    storage_activate_require_auth();
                    storage_activate_validate_csrf();
                    storage_activate_prepare();
                    return;

                case 'verify':
                    storage_activate_require_post();
                    storage_activate_require_auth();
                    storage_activate_validate_csrf();
                    storage_activate_verify();
                    return;

                case 'confirm':
                    storage_activate_require_post();
                    storage_activate_require_auth();
                    storage_activate_validate_csrf();
                    storage_activate_confirm();
                    return;

                default:
                    storage_activate_json_response([
                        'ok'       => false,
                        'code'     => 'INVALID_MODE',
                        'message'  => 'Supported modes: prepare, verify, confirm',
                        '_version' => STORAGE_ACTIVATE_VERSION,
                        '_file'    => STORAGE_ACTIVATE_FILE,
                    ], 400);
                    return;
            }
        } catch (Throwable $e) {
            storage_activate_json_response([
                'ok'       => false,
                'code'     => 'ACTIVATE_INTERNAL_ERROR',
                'message'  => $e->getMessage(),
                '_version' => STORAGE_ACTIVATE_VERSION,
                '_file'    => STORAGE_ACTIVATE_FILE,
            ], 500);
        }
    }
}

/* ==========================================================================
 * Request / response
 * ========================================================================== */

if (!function_exists('storage_activate_json_input')) {
    function storage_activate_json_input(): array
    {
        static $cached = null;
        if (is_array($cached)) {
            return $cached;
        }

        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            $cached = [];
            return $cached;
        }

        $json = json_decode($raw, true);
        $cached = is_array($json) ? $json : [];
        return $cached;
    }
}

if (!function_exists('storage_activate_input')) {
    function storage_activate_input(string $key, $default = null)
    {
        $json = storage_activate_json_input();
        if (array_key_exists($key, $json)) {
            return $json[$key];
        }
        if (array_key_exists($key, $_POST)) {
            return $_POST[$key];
        }
        if (array_key_exists($key, $_GET)) {
            return $_GET[$key];
        }
        return $default;
    }
}

if (!function_exists('storage_activate_request_mode')) {
    function storage_activate_request_mode(): string
    {
        $v = strtolower(trim((string)(
            storage_activate_input('mode',
            storage_activate_input('action',
            storage_activate_input('step', '')))
        ));

        return $v;
    }
}

if (!function_exists('storage_activate_require_post')) {
    function storage_activate_require_post(): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'POST') {
            storage_activate_json_response([
                'ok'       => false,
                'code'     => 'METHOD_NOT_ALLOWED',
                'message'  => 'POST required',
                '_version' => STORAGE_ACTIVATE_VERSION,
                '_file'    => STORAGE_ACTIVATE_FILE,
            ], 405);
        }
    }
}

if (!function_exists('storage_activate_json_response')) {
    function storage_activate_json_response(array $payload, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

/* ==========================================================================
 * Auth / csrf
 * ========================================================================== */

if (!function_exists('storage_activate_session_user')) {
    function storage_activate_session_user(): array
    {
        $user = null;

        if (function_exists('get_wallet_session')) {
            $session = get_wallet_session();

            if (is_array($session)) {
                $user = [
                    'id'             => (int)($session['id'] ?? 0),
                    'wallet'         => trim((string)($session['wallet'] ?? '')),
                    'wallet_address' => trim((string)($session['wallet_address'] ?? $session['ton_address'] ?? '')),
                    'nickname'       => trim((string)($session['nickname'] ?? '')),
                    'email'          => trim((string)($session['email'] ?? '')),
                ];
            } elseif (is_string($session) && trim($session) !== '') {
                $user = [
                    'id'             => (int)($_SESSION['user_id'] ?? 0),
                    'wallet'         => trim($session),
                    'wallet_address' => trim((string)($_SESSION['wallet_address'] ?? $_SESSION['ton_address'] ?? '')),
                    'nickname'       => trim((string)($_SESSION['nickname'] ?? '')),
                    'email'          => trim((string)($_SESSION['email'] ?? '')),
                ];
            }
        }

        if (!is_array($user)) {
            $user = [
                'id'             => (int)($_SESSION['user_id'] ?? 0),
                'wallet'         => trim((string)($_SESSION['wallet'] ?? $_SESSION['wallet_session'] ?? '')),
                'wallet_address' => trim((string)($_SESSION['wallet_address'] ?? $_SESSION['ton_address'] ?? '')),
                'nickname'       => trim((string)($_SESSION['nickname'] ?? '')),
                'email'          => trim((string)($_SESSION['email'] ?? '')),
            ];
        }

        if ($user['id'] <= 0 && $user['wallet'] !== '') {
            $pdo = storage_activate_pdo();
            $sql = "SELECT id, wallet, wallet_address, nickname, email
                    FROM users
                    WHERE wallet = :wallet
                    LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->execute([':wallet' => $user['wallet']]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $user = [
                    'id'             => (int)($row['id'] ?? 0),
                    'wallet'         => trim((string)($row['wallet'] ?? $user['wallet'])),
                    'wallet_address' => trim((string)($row['wallet_address'] ?? $user['wallet_address'])),
                    'nickname'       => trim((string)($row['nickname'] ?? $user['nickname'])),
                    'email'          => trim((string)($row['email'] ?? $user['email'])),
                ];
            }
        }

        return $user;
    }
}

if (!function_exists('storage_activate_require_auth')) {
    function storage_activate_require_auth(): array
    {
        $user = storage_activate_session_user();
        if (($user['id'] ?? 0) <= 0) {
            storage_activate_json_response([
                'ok'       => false,
                'code'     => 'AUTH_REQUIRED',
                'message'  => 'Login required',
                '_version' => STORAGE_ACTIVATE_VERSION,
                '_file'    => STORAGE_ACTIVATE_FILE,
            ], 401);
        }

        if (trim((string)($user['wallet_address'] ?? '')) === '') {
            storage_activate_json_response([
                'ok'       => false,
                'code'     => 'TON_ADDRESS_REQUIRED',
                'message'  => 'Bound TON address required',
                '_version' => STORAGE_ACTIVATE_VERSION,
                '_file'    => STORAGE_ACTIVATE_FILE,
            ], 400);
        }

        return $user;
    }
}

if (!function_exists('storage_activate_validate_csrf')) {
    function storage_activate_validate_csrf(): void
    {
        $token = trim((string)(
            storage_activate_input('csrf_token',
            storage_activate_input('_csrf',
            storage_activate_input('csrf', '')))
        ));

        $ok = false;

        if (function_exists('csrf_validate')) {
            $ok = (bool)csrf_validate($token);
        } elseif (function_exists('verify_csrf_token')) {
            $ok = (bool)verify_csrf_token($token);
        } elseif (function_exists('poado_csrf_validate')) {
            $ok = (bool)poado_csrf_validate($token);
        } else {
            $sessionToken = (string)($_SESSION['csrf_token'] ?? $_SESSION['csrf'] ?? '');
            $ok = ($token !== '' && $sessionToken !== '' && hash_equals($sessionToken, $token));
        }

        if (!$ok) {
            storage_activate_json_response([
                'ok'       => false,
                'code'     => 'CSRF_INVALID',
                'message'  => 'Invalid CSRF token',
                '_version' => STORAGE_ACTIVATE_VERSION,
                '_file'    => STORAGE_ACTIVATE_FILE,
            ], 419);
        }
    }
}

/* ==========================================================================
 * DB / schema helpers
 * ========================================================================== */

if (!function_exists('storage_activate_pdo')) {
    function storage_activate_pdo(): PDO
    {
        if (function_exists('db_connect')) {
            db_connect();
        }

        if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
            throw new RuntimeException('PDO_NOT_AVAILABLE');
        }

        /** @var PDO $pdo */
        $pdo = $GLOBALS['pdo'];
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }
}

if (!function_exists('storage_activate_table_exists')) {
    function storage_activate_table_exists(PDO $pdo, string $table): bool
    {
        static $cache = [];

        $key = $table;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $sql = "SELECT COUNT(*) FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_name = :table";
        $st = $pdo->prepare($sql);
        $st->execute([':table' => $table]);
        $exists = ((int)$st->fetchColumn() > 0);
        $cache[$key] = $exists;

        return $exists;
    }
}

if (!function_exists('storage_activate_column_exists')) {
    function storage_activate_column_exists(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];

        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $sql = "SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = :table
                  AND column_name = :column";
        $st = $pdo->prepare($sql);
        $st->execute([
            ':table' => $table,
            ':column' => $column,
        ]);

        $exists = ((int)$st->fetchColumn() > 0);
        $cache[$key] = $exists;

        return $exists;
    }
}

if (!function_exists('storage_activate_activation_table')) {
    function storage_activate_activation_table(): string
    {
        $table = trim((string)(
            $_ENV['STORAGE_ACTIVATE_TABLE']
            ?? $_SERVER['STORAGE_ACTIVATE_TABLE']
            ?? getenv('STORAGE_ACTIVATE_TABLE')
            ?? 'poado_storage_activations'
        ));

        return $table !== '' ? $table : 'poado_storage_activations';
    }
}

if (!function_exists('storage_activate_balance_table')) {
    function storage_activate_balance_table(): string
    {
        return 'rwa_storage_balances';
    }
}

if (!function_exists('storage_activate_history_table')) {
    function storage_activate_history_table(): string
    {
        return 'rwa_storage_history';
    }
}

/* ==========================================================================
 * Core config / refs
 * ========================================================================== */

if (!function_exists('storage_activate_token_key')) {
    function storage_activate_token_key(): string
    {
        return 'EMX';
    }
}

if (!function_exists('storage_activate_required_amount_display')) {
    function storage_activate_required_amount_display(): string
    {
        return '100.000000000';
    }
}

if (!function_exists('storage_activate_required_amount_units')) {
    function storage_activate_required_amount_units(): string
    {
        return '100000000000';
    }
}

if (!function_exists('storage_activate_jetton_master')) {
    function storage_activate_jetton_master(): string
    {
        $resolved = rwa_onchain_resolve_token(['token_key' => storage_activate_token_key()]);
        return trim((string)($resolved['jetton_master'] ?? ''));
    }
}

if (!function_exists('storage_activate_treasury_address')) {
    function storage_activate_treasury_address(): string
    {
        return trim((string)(
            $_ENV['RWA_TREASURY_TON_ADDRESS']
            ?? $_ENV['TON_TREASURY_ADDRESS']
            ?? $_SERVER['RWA_TREASURY_TON_ADDRESS']
            ?? $_SERVER['TON_TREASURY_ADDRESS']
            ?? getenv('RWA_TREASURY_TON_ADDRESS')
            ?? getenv('TON_TREASURY_ADDRESS')
            ?? ''
        ));
    }
}

if (!function_exists('storage_activate_make_ref')) {
    function storage_activate_make_ref(): string
    {
        try {
            $rand = strtoupper(bin2hex(random_bytes(4)));
        } catch (Throwable $e) {
            $rand = strtoupper(substr(md5((string)microtime(true)), 0, 8));
        }

        return 'ACT-' . gmdate('YmdHis') . '-' . $rand;
    }
}

if (!function_exists('storage_activate_normalize_card_number')) {
    function storage_activate_normalize_card_number(string $v): string
    {
        return preg_replace('/\D+/', '', $v) ?? '';
    }
}

if (!function_exists('storage_activate_require_card_number')) {
    function storage_activate_require_card_number(): string
    {
        $cardNumber = storage_activate_normalize_card_number((string)storage_activate_input('card_number', ''));
        if (!preg_match('/^\d{16}$/', $cardNumber)) {
            storage_activate_json_response([
                'ok'       => false,
                'code'     => 'CARD_NUMBER_INVALID',
                'message'  => '16-digit card number required',
                '_version' => STORAGE_ACTIVATE_VERSION,
                '_file'    => STORAGE_ACTIVATE_FILE,
            ], 400);
        }

        return $cardNumber;
    }
}

/* ==========================================================================
 * Activation ledger helpers
 * ========================================================================== */

if (!function_exists('storage_activate_require_activation_table')) {
    function storage_activate_require_activation_table(PDO $pdo): string
    {
        $table = storage_activate_activation_table();
        if (!storage_activate_table_exists($pdo, $table)) {
            storage_activate_json_response([
                'ok'       => false,
                'code'     => 'ACTIVATION_TABLE_MISSING',
                'message'  => 'Activation ledger table missing: ' . $table,
                '_version' => STORAGE_ACTIVATE_VERSION,
                '_file'    => STORAGE_ACTIVATE_FILE,
            ], 500);
        }
        return $table;
    }
}

if (!function_exists('storage_activate_insert_prepare_row')) {
    function storage_activate_insert_prepare_row(PDO $pdo, array $user, string $cardNumber, string $activationRef): void
    {
        $table = storage_activate_require_activation_table($pdo);

        $columns = [];
        $values = [];
        $params = [];

        $map = [
            'user_id'         => (int)$user['id'],
            'wallet'          => (string)$user['wallet'],
            'wallet_address'  => (string)$user['wallet_address'],
            'card_number'     => $cardNumber,
            'activation_ref'  => $activationRef,
            'token_key'       => storage_activate_token_key(),
            'jetton_master'   => storage_activate_jetton_master(),
            'amount_display'  => storage_activate_required_amount_display(),
            'amount_units'    => storage_activate_required_amount_units(),
            'status'          => 'PENDING',
            'reward_token'    => 'EMA',
            'reward_status'   => 'pending',
            'created_at'      => gmdate('Y-m-d H:i:s'),
            'updated_at'      => gmdate('Y-m-d H:i:s'),
            'prepared_at'     => gmdate('Y-m-d H:i:s'),
        ];

        foreach ($map as $column => $value) {
            if (!storage_activate_column_exists($pdo, $table, $column)) {
                continue;
            }
            $columns[] = $column;
            $values[] = ':' . $column;
            $params[':' . $column] = $value;
        }

        if (!$columns) {
            storage_activate_json_response([
                'ok'       => false,
                'code'     => 'ACTIVATION_TABLE_SCHEMA_INVALID',
                'message'  => 'Activation ledger table has no expected columns',
                '_version' => STORAGE_ACTIVATE_VERSION,
                '_file'    => STORAGE_ACTIVATE_FILE,
            ], 500);
        }

        $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', $columns) . ')
                VALUES (' . implode(', ', $values) . ')';
        $st = $pdo->prepare($sql);
        $st->execute($params);
    }
}

if (!function_exists('storage_activate_find_by_ref')) {
    function storage_activate_find_by_ref(PDO $pdo, string $activationRef): ?array
    {
        $table = storage_activate_require_activation_table($pdo);

        if (!storage_activate_column_exists($pdo, $table, 'activation_ref')) {
            storage_activate_json_response([
                'ok'       => false,
                'code'     => 'ACTIVATION_REF_COLUMN_MISSING',
                'message'  => 'activation_ref column missing in ' . $table,
                '_version' => STORAGE_ACTIVATE_VERSION,
                '_file'    => STORAGE_ACTIVATE_FILE,
            ], 500);
        }

        $sql = 'SELECT * FROM `' . $table . '` WHERE activation_ref = :ref LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute([':ref' => $activationRef]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('storage_activate_update_row')) {
    function storage_activate_update_row(PDO $pdo, string $activationRef, array $changes): void
    {
        $table = storage_activate_require_activation_table($pdo);

        $sets = [];
        $params = [':activation_ref' => $activationRef];

        foreach ($changes as $column => $value) {
            if ($column === 'activation_ref') {
                continue;
            }
            if (!storage_activate_column_exists($pdo, $table, $column)) {
                continue;
            }
            $sets[] = '`' . $column . '` = :' . $column;
            $params[':' . $column] = $value;
        }

        if (!$sets) {
            return;
        }

        $sql = 'UPDATE `' . $table . '`
                SET ' . implode(', ', $sets) . '
                WHERE activation_ref = :activation_ref
                LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute($params);
    }
}

/* ==========================================================================
 * Reward / balances / history
 * ========================================================================== */

if (!function_exists('storage_activate_fetch_ema_price')) {
    function storage_activate_fetch_ema_price(): string
    {
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        $urls = [];
        if ($host !== '') {
            $urls[] = $scheme . '://' . $host . '/rwa/api/global/ema-price.php';
        }
        $urls[] = 'http://127.0.0.1/rwa/api/global/ema-price.php';

        foreach ($urls as $url) {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 6,
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);

            $raw = @file_get_contents($url, false, $ctx);
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }

            $json = json_decode($raw, true);
            if (!is_array($json)) {
                continue;
            }

            $candidates = [
                $json['price'] ?? null,
                $json['ema_price'] ?? null,
                $json['data']['price'] ?? null,
                $json['data']['ema_price'] ?? null,
            ];

            foreach ($candidates as $v) {
                if (is_numeric($v) && (float)$v > 0) {
                    return number_format((float)$v, 9, '.', '');
                }
            }
        }

        storage_activate_json_response([
            'ok'       => false,
            'code'     => 'EMA_PRICE_UNAVAILABLE',
            'message'  => 'Unable to load EMA price from /rwa/api/global/ema-price.php',
            '_version' => STORAGE_ACTIVATE_VERSION,
            '_file'    => STORAGE_ACTIVATE_FILE,
        ], 500);
    }
}

if (!function_exists('storage_activate_credit_reward')) {
    function storage_activate_credit_reward(PDO $pdo, array $user, string $activationRef, string $cardNumber): array
    {
        $balanceTable = storage_activate_balance_table();
        if (!storage_activate_table_exists($pdo, $balanceTable)) {
            storage_activate_json_response([
                'ok'       => false,
                'code'     => 'BALANCE_TABLE_MISSING',
                'message'  => 'Balance table missing: ' . $balanceTable,
                '_version' => STORAGE_ACTIVATE_VERSION,
                '_file'    => STORAGE_ACTIVATE_FILE,
            ], 500);
        }

        foreach (['user_id', 'unclaim_ema'] as $requiredColumn) {
            if (!storage_activate_column_exists($pdo, $balanceTable, $requiredColumn)) {
                storage_activate_json_response([
                    'ok'       => false,
                    'code'     => 'BALANCE_SCHEMA_INVALID',
                    'message'  => 'Missing balance column: ' . $requiredColumn,
                    '_version' => STORAGE_ACTIVATE_VERSION,
                    '_file'    => STORAGE_ACTIVATE_FILE,
                ], 500);
            }
        }

        $emaPrice = storage_activate_fetch_ema_price();
        $emaReward = bcdiv('100', $emaPrice, 9);

        $sql = "SELECT * FROM `{$balanceTable}` WHERE user_id = :user_id LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([':user_id' => (int)$user['id']]);
        $balance = $st->fetch(PDO::FETCH_ASSOC);

        if (!is_array($balance)) {
            $insertCols = ['user_id', 'unclaim_ema'];
            $insertVals = [':user_id', ':unclaim_ema'];
            $params = [
                ':user_id' => (int)$user['id'],
                ':unclaim_ema' => $emaReward,
            ];

            if (storage_activate_column_exists($pdo, $balanceTable, 'created_at')) {
                $insertCols[] = 'created_at';
                $insertVals[] = ':created_at';
                $params[':created_at'] = gmdate('Y-m-d H:i:s');
            }
            if (storage_activate_column_exists($pdo, $balanceTable, 'updated_at')) {
                $insertCols[] = 'updated_at';
                $insertVals[] = ':updated_at';
                $params[':updated_at'] = gmdate('Y-m-d H:i:s');
            }
            if (storage_activate_column_exists($pdo, $balanceTable, 'card_number')) {
                $insertCols[] = 'card_number';
                $insertVals[] = ':card_number';
                $params[':card_number'] = $cardNumber;
            }
            if (storage_activate_column_exists($pdo, $balanceTable, 'is_card_active')) {
                $insertCols[] = 'is_card_active';
                $insertVals[] = ':is_card_active';
                $params[':is_card_active'] = 1;
            } elseif (storage_activate_column_exists($pdo, $balanceTable, 'card_active')) {
                $insertCols[] = 'card_active';
                $insertVals[] = ':card_active';
                $params[':card_active'] = 1;
            }

            $sql = 'INSERT INTO `' . $balanceTable . '` (' . implode(', ', $insertCols) . ')
                    VALUES (' . implode(', ', $insertVals) . ')';
            $st = $pdo->prepare($sql);
            $st->execute($params);
        } else {
            $sets = ['`unclaim_ema` = :unclaim_ema'];
            $params = [
                ':user_id' => (int)$user['id'],
                ':unclaim_ema' => bcadd((string)($balance['unclaim_ema'] ?? '0'), $emaReward, 9),
            ];

            if (storage_activate_column_exists($pdo, $balanceTable, 'updated_at')) {
                $sets[] = '`updated_at` = :updated_at';
                $params[':updated_at'] = gmdate('Y-m-d H:i:s');
            }
            if (storage_activate_column_exists($pdo, $balanceTable, 'card_number')) {
                $sets[] = '`card_number` = :card_number';
                $params[':card_number'] = $cardNumber;
            }
            if (storage_activate_column_exists($pdo, $balanceTable, 'is_card_active')) {
                $sets[] = '`is_card_active` = :is_card_active';
                $params[':is_card_active'] = 1;
            } elseif (storage_activate_column_exists($pdo, $balanceTable, 'card_active')) {
                $sets[] = '`card_active` = :card_active';
                $params[':card_active'] = 1;
            }

            $sql = 'UPDATE `' . $balanceTable . '`
                    SET ' . implode(', ', $sets) . '
                    WHERE user_id = :user_id
                    LIMIT 1';
            $st = $pdo->prepare($sql);
            $st->execute($params);
        }

        storage_activate_write_history($pdo, [
            'user_id'        => (int)$user['id'],
            'card_number'    => $cardNumber,
            'event'          => 'activate_confirm',
            'token'          => 'SYSTEM',
            'amount'         => '0.000000',
            'flow_ref'       => $activationRef,
            'status'         => 'CONFIRMED',
            'message'        => 'Activate Card reward credited',
            'meta'           => json_encode([
                'activation_ref' => $activationRef,
                'reward_token'   => 'EMA',
                'ema_price'      => $emaPrice,
                'ema_reward'     => $emaReward,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return [
            'ema_price_snapshot' => $emaPrice,
            'ema_reward'         => $emaReward,
            'reward_token'       => 'EMA',
            'reward_status'      => 'credited_to_unclaim_ema',
        ];
    }
}

if (!function_exists('storage_activate_write_history')) {
    function storage_activate_write_history(PDO $pdo, array $payload): void
    {
        $table = storage_activate_history_table();
        if (!storage_activate_table_exists($pdo, $table)) {
            return;
        }

        $map = [
            'user_id'     => (int)($payload['user_id'] ?? 0),
            'card_number' => (string)($payload['card_number'] ?? ''),
            'event'       => (string)($payload['event'] ?? ''),
            'type'        => (string)($payload['event'] ?? ''),
            'token'       => (string)($payload['token'] ?? 'SYSTEM'),
            'amount'      => (string)($payload['amount'] ?? '0.000000'),
            'flow_ref'    => (string)($payload['flow_ref'] ?? ''),
            'status'      => (string)($payload['status'] ?? ''),
            'message'     => (string)($payload['message'] ?? ''),
            'meta'        => (string)($payload['meta'] ?? ''),
            'created_at'  => gmdate('Y-m-d H:i:s'),
            'updated_at'  => gmdate('Y-m-d H:i:s'),
        ];

        $columns = [];
        $values = [];
        $params = [];

        foreach ($map as $column => $value) {
            if (!storage_activate_column_exists($pdo, $table, $column)) {
                continue;
            }
            $columns[] = $column;
            $values[] = ':' . $column;
            $params[':' . $column] = $value;
        }

        if (!$columns) {
            return;
        }

        $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', $columns) . ')
                VALUES (' . implode(', ', $values) . ')';
        $st = $pdo->prepare($sql);
        $st->execute($params);
    }
}

/* ==========================================================================
 * Business handlers
 * ========================================================================== */

if (!function_exists('storage_activate_prepare')) {
    function storage_activate_prepare(): void
    {
        $user = storage_activate_require_auth();
        $cardNumber = storage_activate_require_card_number();
        $pdo = storage_activate_pdo();

        $activationRef = storage_activate_make_ref();
        $treasuryAddress = storage_activate_treasury_address();
        if ($treasuryAddress === '') {
            storage_activate_json_response([
                'ok'       => false,
                'code'     => 'TREASURY_ADDRESS_MISSING',
                'message'  => 'Treasury TON address is not configured',
                '_version' => STORAGE_ACTIVATE_VERSION,
                '_file'    => STORAGE_ACTIVATE_FILE,
            ], 500);
        }

        $pdo->beginTransaction();
        try {
            storage_activate_insert_prepare_row($pdo, $user, $cardNumber, $activationRef);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $jettonMaster = storage_activate_jetton_master();
        $amountUnits = storage_activate_required_amount_units();

        $deeplink = 'ton://transfer/' . rawurlencode($treasuryAddress)
            . '?jetton=' . rawurlencode($jettonMaster)
            . '&amount=' . rawurlencode($amountUnits)
            . '&text=' . rawurlencode($activationRef);

        storage_activate_json_response([
            'ok'                      => true,
            'code'                    => 'ACTIVATION_PREPARED',
            'activation_ref'          => $activationRef,
            'card_number'             => $cardNumber,
            'token_key'               => storage_activate_token_key(),
            'jetton_master_raw'       => $jettonMaster,
            'required_amount_display' => storage_activate_required_amount_display(),
            'required_amount_units'   => $amountUnits,
            'treasury_address'        => $treasuryAddress,
            'deeplink'                => $deeplink,
            'ton_transfer_uri'        => $deeplink,
            'qr_text'                 => $deeplink,
            'memo_text'               => $activationRef,
            'reward_token'            => 'EMA',
            'reward_formula'          => '100 / current EMA price',
            '_version'                => STORAGE_ACTIVATE_VERSION,
            '_file'                   => STORAGE_ACTIVATE_FILE,
        ]);
    }
}

if (!function_exists('storage_activate_verify')) {
    function storage_activate_verify(): void
    {
        $user = storage_activate_require_auth();
        $activationRef = trim((string)storage_activate_input('activation_ref', ''));
        if ($activationRef === '') {
            storage_activate_json_response([
                'ok'       => false,
                'code'     => 'ACTIVATION_REF_REQUIRED',
                'message'  => 'activation_ref is required',
                '_version' => STORAGE_ACTIVATE_VERSION,
                '_file'    => STORAGE_ACTIVATE_FILE,
            ], 400);
        }

        $pdo = storage_activate_pdo();
        $row = storage_activate_find_by_ref($pdo, $activationRef);
        if (!is_array($row)) {
            storage_activate_json_response([
                'ok'       => false,
                'code'     => 'ACTIVATION_REF_NOT_FOUND',
                'message'  => 'Activation reference not found',
                '_version' => STORAGE_ACTIVATE_VERSION,
                '_file'    => STORAGE_ACTIVATE_FILE,
            ], 404);
        }

        if ((int)($row['user_id'] ?? 0) !== (int)$user['id']) {
            storage_activate_json_response([
                'ok'       => false,
                'code'     => 'ACTIVATION_FORBIDDEN',
                'message'  => 'Activation ref does not belong to current user',
                '_version' => STORAGE_ACTIVATE_VERSION,
                '_file'    => STORAGE_ACTIVATE_FILE,
            ], 403);
        }

        $verify = rwa_onchain_verify_jetton_transfer([
            'token_key'         => storage_activate_token_key(),
            'jetton_master'     => storage_activate_jetton_master(),
            'owner_address'     => (string)$user['wallet_address'],
            'amount_units'      => storage_activate_required_amount_units(),
            'ref'               => $activationRef,
            'tx_hash'           => trim((string)storage_activate_input('tx_hash', '')),
            'min_confirmations' => (int)storage_activate_input('min_confirmations', 0),
            'lookback_seconds'  => (int)storage_activate_input('lookback_seconds', 86400 * 7),
            'limit'             => (int)storage_activate_input('limit', 120),
        ]);

        if (!empty($verify['ok'])) {
            storage_activate_update_row($pdo, $activationRef, [
                'status'        => (string)($verify['status'] ?? 'CONFIRMED'),
                'tx_hash'       => (string)($verify['tx_hash'] ?? ''),
                'payload_text'  => (string)($verify['payload_text'] ?? ''),
                'verified_at'   => gmdate('Y-m-d H:i:s'),
                'updated_at'    => gmdate('Y-m-d H:i:s'),
            ]);
        }

        $verify['activation_ref'] = $activationRef;
        $verify['card_number'] = (string)($row['card_number'] ?? '');
        $verify['_version'] = STORAGE_ACTIVATE_VERSION;
        $verify['_file'] = STORAGE_ACTIVATE_FILE;

        storage_activate_json_response($verify, !empty($verify['ok']) ? 200 : 400);
    }
}

if (!function_exists('storage_activate_confirm')) {
    function storage_activate_confirm(): void
    {
        $user = storage_activate_require_auth();
        $activationRef = trim((string)storage_activate_input('activation_ref', ''));
        if ($activationRef === '') {
            storage_activate_json_response([
                'ok'       => false,
                'code'     => 'ACTIVATION_REF_REQUIRED',
                'message'  => 'activation_ref is required',
                '_version' => STORAGE_ACTIVATE_VERSION,
                '_file'    => STORAGE_ACTIVATE_FILE,
            ], 400);
        }

        $pdo = storage_activate_pdo();
        $row = storage_activate_find_by_ref($pdo, $activationRef);
        if (!is_array($row)) {
            storage_activate_json_response([
                'ok'       => false,
                'code'     => 'ACTIVATION_REF_NOT_FOUND',
                'message'  => 'Activation reference not found',
                '_version' => STORAGE_ACTIVATE_VERSION,
                '_file'    => STORAGE_ACTIVATE_FILE,
            ], 404);
        }

        if ((int)($row['user_id'] ?? 0) !== (int)$user['id']) {
            storage_activate_json_response([
                'ok'       => false,
                'code'     => 'ACTIVATION_FORBIDDEN',
                'message'  => 'Activation ref does not belong to current user',
                '_version' => STORAGE_ACTIVATE_VERSION,
                '_file'    => STORAGE_ACTIVATE_FILE,
            ], 403);
        }

        if (
            strtoupper(trim((string)($row['status'] ?? ''))) === 'CONFIRMED' &&
            strtolower(trim((string)($row['reward_status'] ?? ''))) === 'credited_to_unclaim_ema'
        ) {
            storage_activate_json_response([
                'ok'                 => true,
                'code'               => 'ALREADY_CONFIRMED',
                'status'             => 'CONFIRMED',
                'activation_ref'     => $activationRef,
                'tx_hash'            => (string)($row['tx_hash'] ?? ''),
                'ema_price_snapshot' => (string)($row['ema_price_snapshot'] ?? ''),
                'ema_reward'         => (string)($row['ema_reward'] ?? ''),
                'reward_token'       => (string)($row['reward_token'] ?? 'EMA'),
                'reward_status'      => (string)($row['reward_status'] ?? 'credited_to_unclaim_ema'),
                '_version'           => STORAGE_ACTIVATE_VERSION,
                '_file'              => STORAGE_ACTIVATE_FILE,
            ]);
        }

        $verify = rwa_onchain_verify_jetton_transfer([
            'token_key'         => storage_activate_token_key(),
            'jetton_master'     => storage_activate_jetton_master(),
            'owner_address'     => (string)$user['wallet_address'],
            'amount_units'      => storage_activate_required_amount_units(),
            'ref'               => $activationRef,
            'tx_hash'           => trim((string)storage_activate_input('tx_hash', '')),
            'min_confirmations' => (int)storage_activate_input('min_confirmations', 0),
            'lookback_seconds'  => (int)storage_activate_input('lookback_seconds', 86400 * 7),
            'limit'             => (int)storage_activate_input('limit', 120),
        ]);

        if (empty($verify['ok']) || empty($verify['verified'])) {
            storage_activate_json_response([
                'ok'           => false,
                'code'         => (string)($verify['code'] ?? 'VERIFY_FAILED'),
                'status'       => (string)($verify['status'] ?? 'VERIFY_FAILED'),
                'message'      => (string)($verify['message'] ?? 'Verification failed'),
                'verify'       => $verify,
                '_version'     => STORAGE_ACTIVATE_VERSION,
                '_file'        => STORAGE_ACTIVATE_FILE,
            ], 400);
        }

        $cardNumber = storage_activate_normalize_card_number((string)($row['card_number'] ?? ''));
        if (!preg_match('/^\d{16}$/', $cardNumber)) {
            storage_activate_json_response([
                'ok'       => false,
                'code'     => 'CARD_NUMBER_INVALID',
                'message'  => 'Stored card number is invalid',
                '_version' => STORAGE_ACTIVATE_VERSION,
                '_file'    => STORAGE_ACTIVATE_FILE,
            ], 500);
        }

        $pdo->beginTransaction();
        try {
            $reward = storage_activate_credit_reward($pdo, $user, $activationRef, $cardNumber);

            storage_activate_update_row($pdo, $activationRef, [
                'status'             => 'CONFIRMED',
                'tx_hash'            => (string)($verify['tx_hash'] ?? ''),
                'payload_text'       => (string)($verify['payload_text'] ?? ''),
                'verified_at'        => gmdate('Y-m-d H:i:s'),
                'activated_at'       => gmdate('Y-m-d H:i:s'),
                'updated_at'         => gmdate('Y-m-d H:i:s'),
                'ema_price_snapshot' => (string)$reward['ema_price_snapshot'],
                'ema_reward'         => (string)$reward['ema_reward'],
                'reward_token'       => (string)$reward['reward_token'],
                'reward_status'      => (string)$reward['reward_status'],
            ]);

            $pdo->commit();

            storage_activate_json_response([
                'ok'                 => true,
                'code'               => 'ACTIVATION_CONFIRMED',
                'status'             => 'CONFIRMED',
                'activation_ref'     => $activationRef,
                'card_number'        => $cardNumber,
                'token_key'          => storage_activate_token_key(),
                'required_amount_display' => storage_activate_required_amount_display(),
                'required_amount_units'   => storage_activate_required_amount_units(),
                'tx_hash'            => (string)($verify['tx_hash'] ?? ''),
                'payload_text'       => (string)($verify['payload_text'] ?? ''),
                'ema_price_snapshot' => (string)$reward['ema_price_snapshot'],
                'ema_reward'         => (string)$reward['ema_reward'],
                'reward_token'       => (string)$reward['reward_token'],
                'reward_status'      => (string)$reward['reward_status'],
                'verify_source'      => (string)($verify['verify_source'] ?? 'toncenter_v3_php'),
                'verify_mode'        => (string)($verify['verify_mode'] ?? ''),
                '_version'           => STORAGE_ACTIVATE_VERSION,
                '_file'              => STORAGE_ACTIVATE_FILE,
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}