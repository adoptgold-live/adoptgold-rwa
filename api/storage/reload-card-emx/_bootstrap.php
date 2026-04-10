<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/storage/reload-card-emx/_bootstrap.php
 * Storage Master v7.8
 * FINAL-LOCK-13-RWAE
 *
 * Unified canonical backend for:
 * - /rwa/api/storage/reload-card-emx.php
 * - /rwa/api/storage/reload-card-emx-verify.php
 *
 * FINAL-LOCK-13-RWAE:
 * - keeps existing standalone RWA include structure
 * - keeps shared /rwa/inc/core/onchain-verify.php verification flow
 * - locked verify rule: token + amount + ref => ACCEPT
 * - destination not required
 * - no localhost Node verifier dependency
 * - exact live DB schema safe
 * - confirmed reload credits rwa_storage_balances.card_balance_rwa
 * - card_balance_rwa now represents RWA€ display value
 * - conversion direction locked: EMX -> RWA€
 * - formula: amount_rwae = amount_emx * emx_to_rwae
 * - rates sourced from /rwa/api/global/rates.php
 * - prepare freezes eur_usd / emx_to_rwae / amount_rwae into meta_json
 * - verify credits frozen amount_rwae, not raw EMX
 * - ledger unit changed from RWA$ to RWA€
 * - self-heals already-confirmed legacy rows with missing ledger application
 */

require_once __DIR__ . '/../../../inc/core/bootstrap.php';
require_once __DIR__ . '/../../../inc/core/session-user.php';
require_once __DIR__ . '/../../../inc/core/onchain-verify.php';

if (is_file(__DIR__ . '/../../../inc/core/csrf.php')) {
    require_once __DIR__ . '/../../../inc/core/csrf.php';
}
if (is_file(__DIR__ . '/../../../inc/core/json.php')) {
    require_once __DIR__ . '/../../../inc/core/json.php';
}
if (is_file(__DIR__ . '/../../../inc/core/error.php')) {
    require_once __DIR__ . '/../../../inc/core/error.php';
}

if (!defined('STORAGE_RELOAD_EMX_BOOTSTRAP_LOADED')) {
    define('STORAGE_RELOAD_EMX_BOOTSTRAP_LOADED', true);
}
if (!defined('STORAGE_RELOAD_EMX_VERSION')) {
    define('STORAGE_RELOAD_EMX_VERSION', 'FINAL-LOCK-13-RWAE');
}
if (!defined('STORAGE_RELOAD_EMX_FILE')) {
    define('STORAGE_RELOAD_EMX_FILE', '/var/www/html/public/rwa/api/storage/reload-card-emx/_bootstrap.php');
}

/* --------------------------------------------------------------------------
 * Locked constants
 * -------------------------------------------------------------------------- */
const RELOAD_EMX_STATUS_PENDING   = 'PENDING';
const RELOAD_EMX_STATUS_CONFIRMED = 'CONFIRMED';
const RELOAD_EMX_TOKEN            = 'EMX';
const RELOAD_EMX_DECIMALS         = 9;
const RELOAD_EMX_REQUIRED_CONFIRMATIONS = 1;

/* --------------------------------------------------------------------------
 * Basic response helpers
 * -------------------------------------------------------------------------- */
if (!function_exists('reload_emx_json')) {
    function reload_emx_json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('reload_emx_ok')) {
    function reload_emx_ok(array $data = [], int $status = 200): never
    {
        reload_emx_json(array_merge([
            'ok'       => true,
            '_version' => STORAGE_RELOAD_EMX_VERSION,
            '_file'    => STORAGE_RELOAD_EMX_FILE,
        ], $data), $status);
    }
}

if (!function_exists('reload_emx_fail')) {
    function reload_emx_fail(string $error, int $status = 400, array $extra = []): never
    {
        reload_emx_json(array_merge([
            'ok'       => false,
            'error'    => $error,
            'message'  => $error,
            '_version' => STORAGE_RELOAD_EMX_VERSION,
            '_file'    => STORAGE_RELOAD_EMX_FILE,
        ], $extra), $status);
    }
}

/* --------------------------------------------------------------------------
 * PDO compatibility
 * -------------------------------------------------------------------------- */
if (!function_exists('reload_emx_pdo')) {
    function reload_emx_pdo(): PDO
    {
        if (($GLOBALS['pdo'] ?? null) instanceof PDO) {
            return $GLOBALS['pdo'];
        }

        if (function_exists('poado_pdo')) {
            $pdo = poado_pdo();
            if ($pdo instanceof PDO) {
                $GLOBALS['pdo'] = $pdo;
                return $pdo;
            }
        }

        if (function_exists('rwa_db')) {
            $pdo = rwa_db();
            if ($pdo instanceof PDO) {
                $GLOBALS['pdo'] = $pdo;
                return $pdo;
            }
        }

        if (function_exists('db_connect')) {
            $pdo = db_connect();
            if ($pdo instanceof PDO) {
                $GLOBALS['pdo'] = $pdo;
                return $pdo;
            }
            if (($GLOBALS['pdo'] ?? null) instanceof PDO) {
                return $GLOBALS['pdo'];
            }
        }

        throw new RuntimeException('PDO_NOT_AVAILABLE');
    }
}

/* --------------------------------------------------------------------------
 * Request / input helpers
 * -------------------------------------------------------------------------- */
if (!function_exists('reload_emx_request_data')) {
    function reload_emx_request_data(): array
    {
        static $data = null;
        if (is_array($data)) {
            return $data;
        }

        $data = $_POST;

        $raw = file_get_contents('php://input');
        if (is_string($raw) && $raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $data = array_merge($data, $json);
            }
        }

        return $data;
    }
}

if (!function_exists('reload_emx_post')) {
    function reload_emx_post(string $key, mixed $default = null): mixed
    {
        $data = reload_emx_request_data();
        return array_key_exists($key, $data) ? $data[$key] : $default;
    }
}

if (!function_exists('reload_emx_str')) {
    function reload_emx_str(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_string($v)) {
            return trim($v);
        }
        if (is_scalar($v)) {
            return trim((string)$v);
        }
        return '';
    }
}

if (!function_exists('reload_emx_digits_only')) {
    function reload_emx_digits_only(string $v): string
    {
        return preg_replace('/\D+/', '', $v) ?? '';
    }
}

/* --------------------------------------------------------------------------
 * Guards
 * -------------------------------------------------------------------------- */
if (!function_exists('reload_emx_require_post')) {
    function reload_emx_require_post(): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'POST') {
            reload_emx_fail('METHOD_NOT_ALLOWED', 405);
        }
    }
}

if (!function_exists('reload_emx_session_user')) {
    function reload_emx_session_user(): ?array
    {
        if (function_exists('rwa_session_user')) {
            $u = rwa_session_user();
            if (is_array($u) && $u) {
                return $u;
            }
        }

        if (function_exists('session_user')) {
            $u = session_user();
            if (is_array($u) && $u) {
                return $u;
            }
        }

        foreach (['rwa_user', 'user', 'session_user'] as $k) {
            if (!empty($_SESSION[$k]) && is_array($_SESSION[$k])) {
                return $_SESSION[$k];
            }
        }

        return null;
    }
}

if (!function_exists('reload_emx_require_auth')) {
    function reload_emx_require_auth(): array
    {
        $user = reload_emx_session_user();
        if (!$user) {
            reload_emx_fail('AUTH_REQUIRED', 401);
        }

        $userId = (int)($user['id'] ?? $user['user_id'] ?? 0);
        if ($userId <= 0) {
            reload_emx_fail('AUTH_REQUIRED', 401);
        }

        return $user;
    }
}

if (!function_exists('reload_emx_require_csrf')) {
    function reload_emx_require_csrf(): void
    {
        $token = reload_emx_str(
            reload_emx_post('csrf',
                reload_emx_post('_csrf',
                    reload_emx_post('csrf_token',
                        reload_emx_post('token', '')
                    )
                )
            )
        );

        $csrfOk = false;

        if (function_exists('csrf_check')) {
            foreach (['storage_reload_card_emx', 'reload_card_emx', 'storage_reload'] as $scope) {
                try {
                    $r = csrf_check($scope, $token);
                    if ($r !== false) {
                        $csrfOk = true;
                        break;
                    }
                } catch (Throwable $e) {
                }
            }
        }

        if (!$csrfOk && function_exists('csrf_verify')) {
            try {
                $csrfOk = csrf_verify($token) ? true : false;
            } catch (Throwable $e) {
                $csrfOk = false;
            }
        }

        if (!$csrfOk) {
            $sessionCandidates = [
                $_SESSION['csrf'] ?? null,
                $_SESSION['csrf_token'] ?? null,
                $_SESSION['reload_csrf'] ?? null,
                $_SESSION['storage_reload_csrf'] ?? null,
            ];
            foreach ($sessionCandidates as $candidate) {
                if (is_string($candidate) && $candidate !== '' && $token !== '' && hash_equals($candidate, $token)) {
                    $csrfOk = true;
                    break;
                }
            }
        }

        if (!$csrfOk) {
            reload_emx_fail('CSRF_INVALID', 403);
        }
    }
}

/* --------------------------------------------------------------------------
 * Env / config helpers
 * -------------------------------------------------------------------------- */
if (!function_exists('reload_emx_env')) {
    function reload_emx_env(string $key, string $default = ''): string
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($v === false || $v === null) {
            return $default;
        }
        return trim((string)$v);
    }
}

if (!function_exists('reload_emx_locked_treasury')) {
    function reload_emx_locked_treasury(): string
    {
        $v = reload_emx_env('TON_TREASURY_ADDRESS', '');
        if ($v !== '') {
            return $v;
        }

        return 'UQBdYfGArtoCUmBs5TjYQtfPFfQuGC2Ydbj2pQr3zIlNrDta';
    }
}

if (!function_exists('reload_emx_locked_jetton_master')) {
    function reload_emx_locked_jetton_master(): string
    {
        $v = reload_emx_env('EMX_JETTON_MASTER_RAW', '');
        if ($v !== '') {
            return $v;
        }

        $v = reload_emx_env('EMX_JETTON_MASTER', '');
        if ($v !== '') {
            return $v;
        }

        return '0:63d3319c1cebcde48b013ff040006e4d462b806bf48b06efb18ec267ec078ce2';
    }
}

/* --------------------------------------------------------------------------
 * Address / compare helpers
 * -------------------------------------------------------------------------- */
if (!function_exists('reload_emx_norm')) {
    function reload_emx_norm(string $v): string
    {
        return strtolower(trim($v));
    }
}

if (!function_exists('reload_emx_hash_norm')) {
    function reload_emx_hash_norm(string $hash): string
    {
        $hash = trim($hash);
        if ($hash === '') {
            return '';
        }
        $hash = strtolower($hash);
        if (!str_starts_with($hash, '0x')) {
            $hash = '0x' . $hash;
        }
        return $hash;
    }
}

/* --------------------------------------------------------------------------
 * Formatting helpers
 * -------------------------------------------------------------------------- */
if (!function_exists('reload_emx_decimal_to_units')) {
    function reload_emx_decimal_to_units(string $amount, int $decimals = RELOAD_EMX_DECIMALS): string
    {
        $amount = trim($amount);
        if ($amount === '' || !preg_match('/^\d+(?:\.\d+)?$/', $amount)) {
            return '0';
        }

        $parts = explode('.', $amount, 2);
        $int = $parts[0];
        $frac = $parts[1] ?? '';
        $frac = substr(str_pad($frac, $decimals, '0'), 0, $decimals);

        $units = ltrim($int . $frac, '0');
        return $units === '' ? '0' : $units;
    }
}

if (!function_exists('reload_emx_units_to_decimal')) {
    function reload_emx_units_to_decimal(string $units, int $decimals = RELOAD_EMX_DECIMALS): string
    {
        $units = reload_emx_digits_only($units);
        if ($units === '') {
            return '0';
        }

        if ($decimals <= 0) {
            return $units;
        }

        if (strlen($units) <= $decimals) {
            $units = str_pad($units, $decimals + 1, '0', STR_PAD_LEFT);
        }

        $int = substr($units, 0, -$decimals);
        $frac = substr($units, -$decimals);
        $frac = rtrim($frac, '0');

        return $frac === '' ? $int : ($int . '.' . $frac);
    }
}

if (!function_exists('reload_emx_decimal_4')) {
    function reload_emx_decimal_4(string $v): string
    {
        $v = trim($v);
        if ($v === '' || !preg_match('/^\d+(?:\.\d+)?$/', $v)) {
            return '0.0000';
        }

        if (function_exists('bcadd')) {
            return bcadd($v, '0', 4);
        }

        return number_format((float)$v, 4, '.', '');
    }
}

if (!function_exists('reload_emx_decimal_6')) {
    function reload_emx_decimal_6(string $v): string
    {
        $v = trim($v);
        if ($v === '' || !preg_match('/^\d+(?:\.\d+)?$/', $v)) {
            return '0.000000';
        }

        if (function_exists('bcadd')) {
            return bcadd($v, '0', 6);
        }

        return number_format((float)$v, 6, '.', '');
    }
}

if (!function_exists('reload_emx_decimal_12')) {
    function reload_emx_decimal_12(string $v): string
    {
        $v = trim($v);
        if ($v === '' || !preg_match('/^\d+(?:\.\d+)?$/', $v)) {
            return '0.000000000000';
        }

        if (function_exists('bcadd')) {
            return bcadd($v, '0', 12);
        }

        return number_format((float)$v, 12, '.', '');
    }
}

if (!function_exists('reload_emx_now_utc')) {
    function reload_emx_now_utc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}

if (!function_exists('reload_emx_make_ref')) {
    function reload_emx_make_ref(): string
    {
        return 'RLD-' . gmdate('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }
}

/* --------------------------------------------------------------------------
 * Rate helpers
 * -------------------------------------------------------------------------- */
if (!function_exists('reload_emx_rates_endpoint')) {
    function reload_emx_rates_endpoint(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
        $host = $_SERVER['HTTP_HOST'] ?? 'adoptgold.app';
        return $scheme . '://' . $host . '/rwa/api/global/rates.php';
    }
}

if (!function_exists('reload_emx_fetch_json_url')) {
    function reload_emx_fetch_json_url(string $url, int $timeout = 8): ?array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'User-Agent: AdoptGold-ReloadEMX/1.0',
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $raw = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (is_string($raw) && $raw !== '' && $code >= 200 && $code < 300) {
                $j = json_decode($raw, true);
                return is_array($j) ? $j : null;
            }
        }

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => $timeout,
                'header'  => "Accept: application/json\r\nUser-Agent: AdoptGold-ReloadEMX/1.0\r\n",
            ],
            'ssl'  => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $j = json_decode($raw, true);
        return is_array($j) ? $j : null;
    }
}

if (!function_exists('reload_emx_live_rates')) {
    function reload_emx_live_rates(): array
    {
        $fallback = [
            'eur_usd'      => '1.080000',
            'emx_to_rwae'  => '0.925926',
            'rwae_to_emx'  => '1.080000',
            'source'       => 'hard_fallback',
            'mode'         => 'fallback',
        ];

        $json = reload_emx_fetch_json_url(reload_emx_rates_endpoint());
        if (!is_array($json) || ($json['ok'] ?? false) !== true) {
            return $fallback;
        }

        $eurUsd = reload_emx_str($json['eur_usd'] ?? '');
        $emxToRwae = reload_emx_str($json['emx_to_rwae'] ?? '');
        $rwaeToEmx = reload_emx_str($json['rwae_to_emx'] ?? '');

        if ($eurUsd === '' || !preg_match('/^\d+(?:\.\d+)?$/', $eurUsd) || (float)$eurUsd <= 0) {
            return $fallback;
        }

        if ($emxToRwae === '' || !preg_match('/^\d+(?:\.\d+)?$/', $emxToRwae) || (float)$emxToRwae <= 0) {
            if (function_exists('bcdiv')) {
                $emxToRwae = bcdiv('1', $eurUsd, 12);
            } else {
                $emxToRwae = number_format(1 / (float)$eurUsd, 12, '.', '');
            }
        }

        if ($rwaeToEmx === '' || !preg_match('/^\d+(?:\.\d+)?$/', $rwaeToEmx) || (float)$rwaeToEmx <= 0) {
            $rwaeToEmx = $eurUsd;
        }

        return [
            'eur_usd'     => reload_emx_decimal_6($eurUsd),
            'emx_to_rwae' => reload_emx_decimal_12($emxToRwae),
            'rwae_to_emx' => reload_emx_decimal_6($rwaeToEmx),
            'source'      => reload_emx_str($json['source'] ?? 'rates_api'),
            'mode'        => reload_emx_str($json['mode'] ?? 'live'),
        ];
    }
}

if (!function_exists('reload_emx_calc_amount_rwae')) {
    function reload_emx_calc_amount_rwae(string $amountEmx, string $emxToRwae): string
    {
        $amountEmx = reload_emx_str($amountEmx);
        $emxToRwae = reload_emx_str($emxToRwae);

        if ($amountEmx === '' || $emxToRwae === '' || !preg_match('/^\d+(?:\.\d+)?$/', $amountEmx) || !preg_match('/^\d+(?:\.\d+)?$/', $emxToRwae)) {
            return '0.000000';
        }

        if (function_exists('bcmul')) {
            return bcmul($amountEmx, $emxToRwae, 6);
        }

        return number_format(((float)$amountEmx) * ((float)$emxToRwae), 6, '.', '');
    }
}

/* --------------------------------------------------------------------------
 * Storage card / user helpers
 * -------------------------------------------------------------------------- */
if (!function_exists('reload_emx_user_id')) {
    function reload_emx_user_id(array $user): int
    {
        return (int)($user['id'] ?? $user['user_id'] ?? 0);
    }
}

if (!function_exists('reload_emx_user_wallet_address')) {
    function reload_emx_user_wallet_address(array $user): string
    {
        return reload_emx_str(
            $user['wallet_address']
            ?? $user['ton_address']
            ?? $user['bound_ton_address']
            ?? ''
        );
    }
}

if (!function_exists('reload_emx_load_storage_card')) {
    function reload_emx_load_storage_card(PDO $pdo, int $userId): ?array
    {
        $sql = "SELECT user_id, bound_ton_address, card_number, is_active
                FROM rwa_storage_cards
                WHERE user_id = :uid
                LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([':uid' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}

/* --------------------------------------------------------------------------
 * DB helpers
 * -------------------------------------------------------------------------- */
if (!function_exists('reload_emx_find_row_by_ref')) {
    function reload_emx_find_row_by_ref(PDO $pdo, string $reloadRef): ?array
    {
        $sql = "SELECT *
                FROM poado_storage_reloads
                WHERE reload_ref = :ref
                LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([':ref' => $reloadRef]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('reload_emx_insert_prepare_row')) {
    function reload_emx_insert_prepare_row(
        PDO $pdo,
        int $userId,
        string $reloadRef,
        string $cardNumber,
        string $walletAddress,
        string $treasuryAddress,
        string $amountEmx,
        string $amountUnits,
        string $jettonMaster,
        array $rates,
        string $amountRwae
    ): void {
        $sql = "INSERT INTO poado_storage_reloads
                (
                    reload_ref,
                    user_id,
                    card_number,
                    wallet_address,
                    treasury_address,
                    token,
                    amount_emx,
                    amount_units,
                    status,
                    tx_hash,
                    verify_source,
                    confirmations,
                    meta_json,
                    prepared_at,
                    created_at,
                    updated_at
                )
                VALUES
                (
                    :reload_ref,
                    :user_id,
                    :card_number,
                    :wallet_address,
                    :treasury_address,
                    :token,
                    :amount_emx,
                    :amount_units,
                    :status,
                    :tx_hash,
                    :verify_source,
                    :confirmations,
                    :meta_json,
                    :prepared_at,
                    :created_at,
                    :updated_at
                )";

        $now = reload_emx_now_utc();

        $st = $pdo->prepare($sql);
        $st->execute([
            ':reload_ref'       => $reloadRef,
            ':user_id'          => $userId,
            ':card_number'      => $cardNumber,
            ':wallet_address'   => $walletAddress,
            ':treasury_address' => $treasuryAddress,
            ':token'            => RELOAD_EMX_TOKEN,
            ':amount_emx'       => reload_emx_decimal_6($amountEmx),
            ':amount_units'     => $amountUnits,
            ':status'           => RELOAD_EMX_STATUS_PENDING,
            ':tx_hash'          => '',
            ':verify_source'    => 'prepare',
            ':confirmations'    => 0,
            ':meta_json'        => json_encode([
                'flow'             => 'reload_card_emx',
                'token_key'        => RELOAD_EMX_TOKEN,
                'jetton_master'    => $jettonMaster,
                'prepared_via'     => 'php',
                'version'          => STORAGE_RELOAD_EMX_VERSION,
                'ledger_applied'   => false,
                'eur_usd'          => reload_emx_decimal_6((string)($rates['eur_usd'] ?? '1.080000')),
                'emx_to_rwae'      => reload_emx_decimal_12((string)($rates['emx_to_rwae'] ?? '0.925926')),
                'rwae_to_emx'      => reload_emx_decimal_6((string)($rates['rwae_to_emx'] ?? '1.080000')),
                'amount_rwae'      => reload_emx_decimal_6($amountRwae),
                'rate_source'      => reload_emx_str($rates['source'] ?? 'hard_fallback'),
                'rate_mode'        => reload_emx_str($rates['mode'] ?? 'fallback'),
                'display_unit'     => 'RWA€',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':prepared_at'      => $now,
            ':created_at'       => $now,
            ':updated_at'       => $now,
        ]);
    }
}

if (!function_exists('reload_emx_confirm_row')) {
    function reload_emx_confirm_row(PDO $pdo, int $id, string $txHash, int $confirmations, array $meta): void
    {
        $sql = "UPDATE poado_storage_reloads
                SET status = :status,
                    tx_hash = :tx_hash,
                    verify_source = :verify_source,
                    confirmations = :confirmations,
                    meta_json = :meta_json,
                    confirmed_at = :confirmed_at,
                    updated_at = :updated_at
                WHERE id = :id
                LIMIT 1";

        $now = reload_emx_now_utc();

        $st = $pdo->prepare($sql);
        $st->execute([
            ':status'         => RELOAD_EMX_STATUS_CONFIRMED,
            ':tx_hash'        => $txHash,
            ':verify_source'  => 'toncenter_v3_php',
            ':confirmations'  => $confirmations,
            ':meta_json'      => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':confirmed_at'   => $now,
            ':updated_at'     => $now,
            ':id'             => $id,
        ]);
    }
}

if (!function_exists('reload_emx_credit_card_balance')) {
    function reload_emx_credit_card_balance(PDO $pdo, int $userId, string $amountRwae): void
    {
        $amountRwae = reload_emx_decimal_4($amountRwae);

        $sql = "
            INSERT INTO rwa_storage_balances
                (user_id, card_balance_rwa, created_at, updated_at)
            VALUES
                (:user_id, :card_balance_rwa, :created_at, :updated_at)
            ON DUPLICATE KEY UPDATE
                card_balance_rwa = COALESCE(card_balance_rwa, 0.0000) + VALUES(card_balance_rwa),
                updated_at = VALUES(updated_at)
        ";

        $now = reload_emx_now_utc();

        $st = $pdo->prepare($sql);
        $st->execute([
            ':user_id'          => $userId,
            ':card_balance_rwa' => $amountRwae,
            ':created_at'       => $now,
            ':updated_at'       => $now,
        ]);
    }
}

if (!function_exists('reload_emx_decode_meta')) {
    function reload_emx_decode_meta(array $row): array
    {
        $metaJson = reload_emx_str($row['meta_json'] ?? '');
        if ($metaJson === '') {
            return [];
        }

        $decoded = json_decode($metaJson, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('reload_emx_row_amount_rwae')) {
    function reload_emx_row_amount_rwae(array $row): string
    {
        $meta = reload_emx_decode_meta($row);
        $amountRwae = reload_emx_str($meta['amount_rwae'] ?? '');
        if ($amountRwae !== '' && preg_match('/^\d+(?:\.\d+)?$/', $amountRwae)) {
            return reload_emx_decimal_6($amountRwae);
        }

        $amountEmx = reload_emx_decimal_6(reload_emx_str($row['amount_emx'] ?? '0'));
        $emxToRwae = reload_emx_str($meta['emx_to_rwae'] ?? '');
        if ($emxToRwae !== '' && preg_match('/^\d+(?:\.\d+)?$/', $emxToRwae)) {
            return reload_emx_decimal_6(reload_emx_calc_amount_rwae($amountEmx, $emxToRwae));
        }

        return reload_emx_decimal_6($amountEmx);
    }
}

if (!function_exists('reload_emx_mark_ledger_applied')) {
    function reload_emx_mark_ledger_applied(PDO $pdo, int $id, array $meta, string $amountRwae, string $source = 'verify_handler'): void
    {
        $meta['ledger_applied'] = true;
        $meta['ledger_applied_at'] = reload_emx_now_utc();
        $meta['ledger_credit'] = reload_emx_decimal_4($amountRwae);
        $meta['ledger_unit'] = 'RWA€';
        $meta['ledger_source'] = $source;

        $st = $pdo->prepare("
            UPDATE poado_storage_reloads
            SET meta_json = :meta_json,
                updated_at = :updated_at
            WHERE id = :id
            LIMIT 1
        ");
        $st->execute([
            ':meta_json'  => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':updated_at' => reload_emx_now_utc(),
            ':id'         => $id,
        ]);
    }
}

if (!function_exists('reload_emx_repair_confirmed_credit_if_missing')) {
    function reload_emx_repair_confirmed_credit_if_missing(PDO $pdo, int $userId, array $row): bool
    {
        $meta = reload_emx_decode_meta($row);
        if ((bool)($meta['ledger_applied'] ?? false) === true) {
            return false;
        }

        $amountRwae = reload_emx_decimal_4(reload_emx_row_amount_rwae($row));
        if ($amountRwae === '0.0000') {
            return false;
        }

        reload_emx_credit_card_balance($pdo, $userId, $amountRwae);
        reload_emx_mark_ledger_applied($pdo, (int)$row['id'], $meta, $amountRwae, 'already_confirmed_repair');

        return true;
    }
}

if (!function_exists('reload_emx_history_record')) {
    function reload_emx_history_record(int $userId, string $amountEmx, string $amountRwae, string $reloadRef, string $txHash): void
    {
        $meta = [
            'reload_ref'      => $reloadRef,
            'tx_hash'         => $txHash,
            'status'          => 'confirmed',
            'source'          => 'reload_card_emx_verify',
            'paid_emx'        => reload_emx_decimal_6($amountEmx),
            'credited_rwae'   => reload_emx_decimal_4($amountRwae),
            'ledger_credit'   => reload_emx_decimal_4($amountRwae),
            'ledger_unit'     => 'RWA€',
        ];

        if (function_exists('storage_history_record')) {
            try {
                storage_history_record($userId, 'reload_confirm', 'EMX', reload_emx_decimal_6($amountEmx), $meta);
            } catch (Throwable $e) {
            }
            return;
        }

        if (function_exists('storage_history_add')) {
            try {
                storage_history_add(reload_emx_pdo(), [
                    'user_id' => $userId,
                    'event'   => 'reload_confirm',
                    'token'   => 'EMX',
                    'amount'  => reload_emx_decimal_6($amountEmx),
                    'meta'    => $meta,
                ]);
            } catch (Throwable $e) {
            }
        }
    }
}

/* --------------------------------------------------------------------------
 * Prepare handler
 * -------------------------------------------------------------------------- */
if (!function_exists('reload_emx_handle_prepare')) {
    function reload_emx_handle_prepare(): never
    {
        reload_emx_require_post();
        $user = reload_emx_require_auth();
        reload_emx_require_csrf();

        $pdo = reload_emx_pdo();
        $userId = reload_emx_user_id($user);
        if ($userId <= 0) {
            reload_emx_fail('AUTH_REQUIRED', 401);
        }

        $amountEmx = reload_emx_str(reload_emx_post('amount_emx', ''));
        if ($amountEmx === '') {
            $amountEmx = reload_emx_str(reload_emx_post('amount', ''));
        }
        if ($amountEmx === '') {
            reload_emx_fail('AMOUNT_REQUIRED', 422);
        }
        if (!preg_match('/^\d+(?:\.\d+)?$/', $amountEmx)) {
            reload_emx_fail('AMOUNT_INVALID', 422);
        }

        $amountUnits = reload_emx_str(reload_emx_post('amount_units', ''));
        if ($amountUnits === '') {
            $amountUnits = reload_emx_decimal_to_units($amountEmx, RELOAD_EMX_DECIMALS);
        } else {
            $amountUnits = reload_emx_digits_only($amountUnits);
        }

        if ($amountUnits === '' || $amountUnits === '0') {
            reload_emx_fail('AMOUNT_INVALID', 422);
        }

        $walletAddress = reload_emx_str(reload_emx_post('wallet_address', ''));
        if ($walletAddress === '') {
            $walletAddress = reload_emx_user_wallet_address($user);
        }
        if ($walletAddress === '') {
            $cardTmp = reload_emx_load_storage_card($pdo, $userId);
            $walletAddress = reload_emx_str($cardTmp['bound_ton_address'] ?? '');
        }

        if ($walletAddress === '') {
            reload_emx_fail('BOUND_WALLET_REQUIRED', 422);
        }

        $card = reload_emx_load_storage_card($pdo, $userId);
        if (!$card) {
            reload_emx_fail('CARD_NOT_BOUND', 422);
        }

        $isActive = (int)($card['is_active'] ?? 0);
        if ($isActive !== 1) {
            reload_emx_fail('CARD_NOT_ACTIVE_RELOAD_BLOCKED', 422);
        }

        $cardNumber = reload_emx_str($card['card_number'] ?? '');
        if ($cardNumber === '') {
            reload_emx_fail('CARD_NUMBER_REQUIRED', 422);
        }

        $treasuryAddress = reload_emx_locked_treasury();
        $jettonMaster = reload_emx_locked_jetton_master();

        $rates = reload_emx_live_rates();
        $amountRwae = reload_emx_calc_amount_rwae($amountEmx, (string)$rates['emx_to_rwae']);

        try {
            $pdo->beginTransaction();

            $reloadRef = reload_emx_make_ref();
            reload_emx_insert_prepare_row(
                $pdo,
                $userId,
                $reloadRef,
                $cardNumber,
                $walletAddress,
                $treasuryAddress,
                $amountEmx,
                $amountUnits,
                $jettonMaster,
                $rates,
                $amountRwae
            );

            $pdo->commit();

            $deeplink = 'ton://transfer/' . rawurlencode($treasuryAddress)
                . '?jetton=' . rawurlencode($jettonMaster)
                . '&amount=' . rawurlencode($amountUnits)
                . '&text=' . rawurlencode($reloadRef);

            reload_emx_ok([
                'status'            => 'RELOAD_PREPARED',
                'message'           => 'RELOAD_PREPARED',
                'user_id'           => $userId,
                'reload_ref'        => $reloadRef,
                'amount_emx'        => reload_emx_decimal_6($amountEmx),
                'amount_units'      => $amountUnits,
                'amount_rwae'       => reload_emx_decimal_6($amountRwae),
                'eur_usd'           => reload_emx_decimal_6((string)$rates['eur_usd']),
                'emx_to_rwae'       => reload_emx_decimal_12((string)$rates['emx_to_rwae']),
                'rwae_to_emx'       => reload_emx_decimal_6((string)$rates['rwae_to_emx']),
                'card_balance_unit' => 'RWA€',
                'rate_source'       => reload_emx_str($rates['source']),
                'rate_mode'         => reload_emx_str($rates['mode']),
                'treasury_address'  => $treasuryAddress,
                'jetton_master'     => $jettonMaster,
                'deeplink'          => $deeplink,
                'qr_text'           => $deeplink,
            ], 200);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            reload_emx_fail('RELOAD_PREPARE_FAILED', 500, [
                'detail' => $e->getMessage(),
            ]);
        }
    }
}

/* --------------------------------------------------------------------------
 * Verify handler
 * -------------------------------------------------------------------------- */
if (!function_exists('reload_emx_handle_verify')) {
    function reload_emx_handle_verify(): never
    {
        reload_emx_require_post();
        $user = reload_emx_require_auth();
        reload_emx_require_csrf();

        $pdo = reload_emx_pdo();
        $userId = reload_emx_user_id($user);

        $reloadRef = reload_emx_str(reload_emx_post('reload_ref', ''));
        if ($reloadRef === '') {
            reload_emx_fail('RELOAD_REF_REQUIRED', 422);
        }

        $row = reload_emx_find_row_by_ref($pdo, $reloadRef);
        if (!$row) {
            reload_emx_fail('RELOAD_REF_NOT_FOUND', 404);
        }

        $rowUserId = (int)($row['user_id'] ?? 0);
        if ($rowUserId !== $userId) {
            reload_emx_fail('RELOAD_NOT_OWNED', 403);
        }

        $status = strtoupper(reload_emx_str($row['status'] ?? ''));
        if ($status === RELOAD_EMX_STATUS_CONFIRMED) {
            $alreadyAmount = reload_emx_decimal_4(reload_emx_row_amount_rwae($row));
            $ledgerRepaired = false;

            try {
                $pdo->beginTransaction();

                $fresh = reload_emx_find_row_by_ref($pdo, $reloadRef);
                if (!$fresh) {
                    throw new RuntimeException('RELOAD_REF_NOT_FOUND_REPAIR');
                }

                $ledgerRepaired = reload_emx_repair_confirmed_credit_if_missing($pdo, $userId, $fresh);

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }

            reload_emx_ok([
                'status'            => 'ALREADY_CONFIRMED',
                'message'           => 'ALREADY_CONFIRMED',
                'reload_ref'        => $reloadRef,
                'tx_hash'           => reload_emx_str($row['tx_hash'] ?? ''),
                'confirmations'     => (int)($row['confirmations'] ?? 0),
                'verify_source'     => reload_emx_str($row['verify_source'] ?? 'toncenter_v3_php'),
                'confirmed_at'      => reload_emx_str($row['confirmed_at'] ?? ''),
                'user_id'           => $userId,
                'ledger_applied'    => true,
                'ledger_repaired'   => $ledgerRepaired,
                'card_balance_add'  => $alreadyAmount,
                'card_balance_unit' => 'RWA€',
            ], 200);
        }

        $expectedWallet = reload_emx_str($row['wallet_address'] ?? '');
        $expectedTreasury = reload_emx_str($row['treasury_address'] ?? reload_emx_locked_treasury());
        $expectedAmountUnits = reload_emx_digits_only(reload_emx_str($row['amount_units'] ?? ''));
        $expectedJettonMaster = reload_emx_locked_jetton_master();

        if ($expectedWallet === '') {
            reload_emx_fail('EXPECTED_WALLET_MISSING', 500);
        }
        if ($expectedAmountUnits === '' || $expectedAmountUnits === '0') {
            reload_emx_fail('EXPECTED_AMOUNT_MISSING', 500);
        }

        $providedTxHash = reload_emx_hash_norm(reload_emx_str(reload_emx_post('tx_hash', '')));

        try {
            $verify = rwa_onchain_verify_jetton_transfer([
                'owner_address' => $expectedWallet,
                'token_key'     => RELOAD_EMX_TOKEN,
                'jetton_master' => $expectedJettonMaster,
                'amount_units'  => $expectedAmountUnits,
                'ref'           => $reloadRef,
                'tx_hint'       => $providedTxHash,
                'limit'         => 100,
            ]);
        } catch (Throwable $e) {
            reload_emx_fail('TONCENTER_UNREACHABLE', 502, [
                'detail' => $e->getMessage(),
            ]);
        }

        if (($verify['ok'] ?? false) !== true) {
            reload_emx_fail('TRANSFER_NOT_FOUND', 404, [
                'reload_ref'       => $reloadRef,
                'expected_wallet'  => $expectedWallet,
                'expected_amount'  => $expectedAmountUnits,
                'jetton_master'    => $expectedJettonMaster,
                'treasury_address' => $expectedTreasury,
                'verify_code'      => (string)($verify['code'] ?? 'NO_MATCH'),
                'debug'            => $verify['debug'] ?? [],
            ]);
        }

        $matchedTxHash = reload_emx_hash_norm((string)($verify['tx_hash'] ?? ''));
        if ($providedTxHash !== '' && $matchedTxHash !== '' && !hash_equals($providedTxHash, $matchedTxHash)) {
            reload_emx_fail('TX_HASH_MISMATCH', 409, [
                'provided_tx_hash' => $providedTxHash,
                'matched_tx_hash'  => $matchedTxHash,
            ]);
        }

        $confirmations = (int)($verify['confirmations'] ?? RELOAD_EMX_REQUIRED_CONFIRMATIONS);
        if ($confirmations <= 0) {
            $confirmations = RELOAD_EMX_REQUIRED_CONFIRMATIONS;
        }

        if ($confirmations < RELOAD_EMX_REQUIRED_CONFIRMATIONS) {
            reload_emx_fail('INSUFFICIENT_CONFIRMATIONS', 409, [
                'confirmations'          => $confirmations,
                'required_confirmations' => RELOAD_EMX_REQUIRED_CONFIRMATIONS,
                'reload_ref'             => $reloadRef,
                'tx_hash'                => $matchedTxHash,
            ]);
        }

        $metaPrepared = reload_emx_decode_meta($row);
        $confirmedAmountEmx = reload_emx_decimal_6(
            reload_emx_str($row['amount_emx'] ?? reload_emx_units_to_decimal($expectedAmountUnits))
        );
        $frozenAmountRwae = reload_emx_decimal_6(
            reload_emx_str($metaPrepared['amount_rwae'] ?? reload_emx_calc_amount_rwae(
                $confirmedAmountEmx,
                reload_emx_str($metaPrepared['emx_to_rwae'] ?? '0.925926')
            ))
        );

        $meta = [
            'flow'             => 'reload_card_emx',
            'verified_via'     => 'toncenter_v3_php',
            'version'          => STORAGE_RELOAD_EMX_VERSION,
            'reload_ref'       => $reloadRef,
            'wallet_address'   => $expectedWallet,
            'treasury_address' => $expectedTreasury,
            'jetton_master'    => $expectedJettonMaster,
            'token_key'        => (string)($verify['token_key'] ?? RELOAD_EMX_TOKEN),
            'amount_units'     => $expectedAmountUnits,
            'amount_emx'       => $confirmedAmountEmx,
            'amount_rwae'      => $frozenAmountRwae,
            'eur_usd'          => reload_emx_decimal_6(reload_emx_str($metaPrepared['eur_usd'] ?? '1.080000')),
            'emx_to_rwae'      => reload_emx_decimal_12(reload_emx_str($metaPrepared['emx_to_rwae'] ?? '0.925926')),
            'rwae_to_emx'      => reload_emx_decimal_6(reload_emx_str($metaPrepared['rwae_to_emx'] ?? '1.080000')),
            'rate_source'      => reload_emx_str($metaPrepared['rate_source'] ?? 'hard_fallback'),
            'rate_mode'        => reload_emx_str($metaPrepared['rate_mode'] ?? 'fallback'),
            'tx_hash'          => $matchedTxHash,
            'confirmations'    => $confirmations,
            'source_raw'       => (string)($verify['source_raw'] ?? ''),
            'destination_raw'  => (string)($verify['destination_raw'] ?? ''),
            'payload_text'     => (string)($verify['payload_text'] ?? ''),
            'match_jetton'     => (bool)($verify['match_jetton'] ?? false),
            'match_amount'     => (bool)($verify['match_amount'] ?? false),
            'match_ref'        => (bool)($verify['match_ref'] ?? false),
            'match_tx_hint'    => (bool)($verify['match_tx_hint'] ?? true),
            'source_checked'   => (bool)($verify['source_checked'] ?? false),
            'source_matched'   => (bool)($verify['source_matched'] ?? false),
            'treasury_checked' => (bool)($verify['treasury_checked'] ?? false),
            'treasury_matched' => (bool)($verify['treasury_matched'] ?? false),
            'verified_at'      => reload_emx_now_utc(),
            'raw_transfer'     => $verify['raw_transfer'] ?? null,
            'ledger_applied'   => true,
            'ledger_applied_at'=> reload_emx_now_utc(),
            'ledger_credit'    => reload_emx_decimal_4($frozenAmountRwae),
            'ledger_unit'      => 'RWA€',
            'ledger_source'    => 'verify_handler',
        ];

        try {
            $pdo->beginTransaction();

            $fresh = reload_emx_find_row_by_ref($pdo, $reloadRef);
            if (!$fresh) {
                throw new RuntimeException('RELOAD_REF_NOT_FOUND_AFTER_LOCK');
            }

            $freshStatus = strtoupper(reload_emx_str($fresh['status'] ?? ''));
            if ($freshStatus === RELOAD_EMX_STATUS_CONFIRMED) {
                $ledgerRepaired = reload_emx_repair_confirmed_credit_if_missing($pdo, $userId, $fresh);

                $pdo->commit();

                reload_emx_ok([
                    'status'            => 'ALREADY_CONFIRMED',
                    'message'           => 'ALREADY_CONFIRMED',
                    'reload_ref'        => $reloadRef,
                    'tx_hash'           => reload_emx_str($fresh['tx_hash'] ?? $matchedTxHash),
                    'confirmations'     => (int)($fresh['confirmations'] ?? $confirmations),
                    'verify_source'     => reload_emx_str($fresh['verify_source'] ?? 'toncenter_v3_php'),
                    'confirmed_at'      => reload_emx_str($fresh['confirmed_at'] ?? ''),
                    'user_id'           => $userId,
                    'ledger_applied'    => true,
                    'ledger_repaired'   => $ledgerRepaired,
                    'card_balance_add'  => reload_emx_decimal_4(reload_emx_row_amount_rwae($fresh)),
                    'card_balance_unit' => 'RWA€',
                ], 200);
            }

            reload_emx_confirm_row(
                $pdo,
                (int)$fresh['id'],
                $matchedTxHash,
                $confirmations,
                $meta
            );

            reload_emx_credit_card_balance(
                $pdo,
                $userId,
                $frozenAmountRwae
            );

            reload_emx_mark_ledger_applied(
                $pdo,
                (int)$fresh['id'],
                $meta,
                $frozenAmountRwae,
                'verify_handler'
            );

            $pdo->commit();

            reload_emx_history_record(
                $userId,
                $confirmedAmountEmx,
                $frozenAmountRwae,
                $reloadRef,
                $matchedTxHash
            );
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            reload_emx_fail('VERIFY_CONFIRM_FAILED', 500, [
                'detail' => $e->getMessage(),
            ]);
        }

        if (function_exists('storage_sync_all_token_balances_live')) {
            try {
                storage_sync_all_token_balances_live($userId);
            } catch (Throwable $e) {
            }
        }

        $creditedAmount = reload_emx_decimal_4($frozenAmountRwae);

        reload_emx_ok([
            'status'            => 'CONFIRMED',
            'message'           => 'RELOAD_CONFIRMED',
            'reload_ref'        => $reloadRef,
            'user_id'           => $userId,
            'tx_hash'           => $matchedTxHash,
            'confirmations'     => $confirmations,
            'verify_source'     => 'toncenter_v3_php',
            'amount_emx'        => $confirmedAmountEmx,
            'amount_rwae'       => reload_emx_decimal_6($frozenAmountRwae),
            'amount_units'      => $expectedAmountUnits,
            'wallet_address'    => $expectedWallet,
            'treasury_address'  => $expectedTreasury,
            'jetton_master'     => $expectedJettonMaster,
            'ledger_applied'    => true,
            'card_balance_add'  => $creditedAmount,
            'card_balance_unit' => 'RWA€',
        ], 200);
    }
}

/* --------------------------------------------------------------------------
 * Thin controller auto-dispatch support
 * -------------------------------------------------------------------------- */
if (!function_exists('reload_emx_dispatch_from_script')) {
    function reload_emx_dispatch_from_script(): void
    {
        $script = strtolower((string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
        if ($script === '') {
            return;
        }

        if (str_ends_with($script, '/reload-card-emx.php')) {
            reload_emx_handle_prepare();
        }

        if (str_ends_with($script, '/reload-card-emx-verify.php')) {
            reload_emx_handle_verify();
        }
    }
}

reload_emx_dispatch_from_script();