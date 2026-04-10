<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/storage/commit/_bootstrap.php
 * Storage Master v7.8b
 * FINAL-LOCK-4
 *
 * Locked rules:
 * - single endpoint: /rwa/api/storage/commit.php
 * - actions: prepare | verify
 * - token + amount + ref => ACCEPT
 * - destination not required
 * - EMX only
 * - commit_ref = CMT-...
 * - ledger applied guaranteed
 * - self-heal for already confirmed rows missing ledger
 * - EMA public master endpoint path locked as /rwa/api/global/ema-price.php
 * - internal commit/cron must read EMA price from formula helper function, not JSON output
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

if (!defined('STORAGE_COMMIT_BOOTSTRAP_LOADED')) {
    define('STORAGE_COMMIT_BOOTSTRAP_LOADED', true);
}
if (!defined('STORAGE_COMMIT_VERSION')) {
    define('STORAGE_COMMIT_VERSION', 'FINAL-LOCK-4');
}
if (!defined('STORAGE_COMMIT_FILE')) {
    define('STORAGE_COMMIT_FILE', '/var/www/html/public/rwa/api/storage/commit/_bootstrap.php');
}

const COMMIT_TOKEN = 'EMX';
const COMMIT_DECIMALS = 9;
const COMMIT_STATUS_PENDING = 'PENDING';
const COMMIT_STATUS_CONFIRMED = 'CONFIRMED';
const COMMIT_REQUIRED_CONFIRMATIONS = 1;

/* -------------------------------------------------------------------------- */
/* response helpers */
/* -------------------------------------------------------------------------- */
if (!function_exists('commit_json')) {
    function commit_json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('commit_ok')) {
    function commit_ok(array $data = [], int $status = 200): never
    {
        commit_json(array_merge([
            'ok' => true,
            '_version' => STORAGE_COMMIT_VERSION,
            '_file' => STORAGE_COMMIT_FILE,
        ], $data), $status);
    }
}

if (!function_exists('commit_fail')) {
    function commit_fail(string $error, int $status = 400, array $extra = []): never
    {
        commit_json(array_merge([
            'ok' => false,
            'error' => $error,
            'message' => $error,
            '_version' => STORAGE_COMMIT_VERSION,
            '_file' => STORAGE_COMMIT_FILE,
        ], $extra), $status);
    }
}

/* -------------------------------------------------------------------------- */
/* request helpers */
/* -------------------------------------------------------------------------- */
if (!function_exists('commit_request_data')) {
    function commit_request_data(): array
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

if (!function_exists('commit_post')) {
    function commit_post(string $key, mixed $default = null): mixed
    {
        $data = commit_request_data();
        return array_key_exists($key, $data) ? $data[$key] : $default;
    }
}

if (!function_exists('commit_str')) {
    function commit_str(mixed $v): string
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

if (!function_exists('commit_digits_only')) {
    function commit_digits_only(string $v): string
    {
        return preg_replace('/\D+/', '', $v) ?? '';
    }
}

/* -------------------------------------------------------------------------- */
/* guards */
/* -------------------------------------------------------------------------- */
if (!function_exists('commit_require_post')) {
    function commit_require_post(): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'POST') {
            commit_fail('METHOD_NOT_ALLOWED', 405);
        }
    }
}

if (!function_exists('commit_session_user')) {
    function commit_session_user(): ?array
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

if (!function_exists('commit_require_auth')) {
    function commit_require_auth(): array
    {
        $user = commit_session_user();
        if (!$user) {
            commit_fail('AUTH_REQUIRED', 401);
        }

        $uid = (int)($user['id'] ?? $user['user_id'] ?? 0);
        if ($uid <= 0) {
            commit_fail('AUTH_REQUIRED', 401);
        }

        return $user;
    }
}

if (!function_exists('commit_require_csrf')) {
    function commit_require_csrf(): void
    {
        $token = commit_str(
            commit_post(
                'csrf',
                commit_post(
                    '_csrf',
                    commit_post(
                        'csrf_token',
                        commit_post('token', '')
                    )
                )
            )
        );

        $csrfOk = false;

        if (function_exists('csrf_check')) {
            foreach (['storage_commit_emx', 'storage_commit', 'commit_emx'] as $scope) {
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
                $_SESSION['commit_csrf'] ?? null,
                $_SESSION['storage_commit_csrf'] ?? null,
            ];
            foreach ($sessionCandidates as $candidate) {
                if (is_string($candidate) && $candidate !== '' && $token !== '' && hash_equals($candidate, $token)) {
                    $csrfOk = true;
                    break;
                }
            }
        }

        if (!$csrfOk) {
            commit_fail('CSRF_INVALID', 403);
        }
    }
}

/* -------------------------------------------------------------------------- */
/* env/helpers */
/* -------------------------------------------------------------------------- */
if (!function_exists('commit_env')) {
    function commit_env(string $key, string $default = ''): string
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($v === false || $v === null) {
            return $default;
        }
        return trim((string)$v);
    }
}

if (!function_exists('commit_treasury')) {
    function commit_treasury(): string
    {
        $v = commit_env('TON_TREASURY_ADDRESS', '');
        if ($v !== '') {
            return $v;
        }
        return 'UQBdYfGArtoCUmBs5TjYQtfPFfQuGC2Ydbj2pQr3zIlNrDta';
    }
}

if (!function_exists('commit_jetton_master')) {
    function commit_jetton_master(): string
    {
        $v = commit_env('EMX_JETTON_MASTER_RAW', '');
        if ($v !== '') {
            return $v;
        }
        $v = commit_env('EMX_JETTON_MASTER', '');
        if ($v !== '') {
            return $v;
        }
        return '0:63d3319c1cebcde48b013ff040006e4d462b806bf48b06efb18ec267ec078ce2';
    }
}

if (!function_exists('commit_now')) {
    function commit_now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}

if (!function_exists('commit_ref_make')) {
    function commit_ref_make(): string
    {
        return 'CMT-' . gmdate('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }
}

if (!function_exists('commit_decimal_to_units')) {
    function commit_decimal_to_units(string $amount, int $decimals = COMMIT_DECIMALS): string
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

if (!function_exists('commit_units_to_decimal')) {
    function commit_units_to_decimal(string $units, int $decimals = COMMIT_DECIMALS): string
    {
        $units = commit_digits_only($units);
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

if (!function_exists('commit_decimal_6')) {
    function commit_decimal_6(string $v): string
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

/* -------------------------------------------------------------------------- */
/* db */
/* -------------------------------------------------------------------------- */
if (!function_exists('commit_pdo')) {
    function commit_pdo(): PDO
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

if (!function_exists('commit_find_row')) {
    function commit_find_row(PDO $pdo, string $commitRef): ?array
    {
        $st = $pdo->prepare("
            SELECT *
            FROM poado_storage_commits
            WHERE commit_ref = :ref
            LIMIT 1
        ");
        $st->execute([':ref' => $commitRef]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('commit_insert_prepare_row')) {
    function commit_insert_prepare_row(
        PDO $pdo,
        int $uid,
        string $commitRef,
        string $wallet,
        string $treasury,
        string $amountEmx,
        string $amountUnits,
        string $jettonMaster
    ): void {
        $st = $pdo->prepare("
            INSERT INTO poado_storage_commits
            (
                commit_ref,
                user_id,
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
                :commit_ref,
                :user_id,
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
            )
        ");

        $now = commit_now();

        $st->execute([
            ':commit_ref' => $commitRef,
            ':user_id' => $uid,
            ':wallet_address' => $wallet,
            ':treasury_address' => $treasury,
            ':token' => COMMIT_TOKEN,
            ':amount_emx' => commit_decimal_6($amountEmx),
            ':amount_units' => $amountUnits,
            ':status' => COMMIT_STATUS_PENDING,
            ':tx_hash' => '',
            ':verify_source' => 'prepare',
            ':confirmations' => 0,
            ':meta_json' => json_encode([
                'flow' => 'storage_commit',
                'token_key' => COMMIT_TOKEN,
                'jetton_master' => $jettonMaster,
                'prepared_via' => 'php',
                'version' => STORAGE_COMMIT_VERSION,
                'ledger_applied' => false,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':prepared_at' => $now,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
}

if (!function_exists('commit_mark_confirmed')) {
    function commit_mark_confirmed(PDO $pdo, int $id, string $txHash, int $confirmations, array $meta): void
    {
        $st = $pdo->prepare("
            UPDATE poado_storage_commits
            SET
                status = :status,
                tx_hash = :tx_hash,
                verify_source = :verify_source,
                confirmations = :confirmations,
                meta_json = :meta_json,
                confirmed_at = :confirmed_at,
                updated_at = :updated_at
            WHERE id = :id
            LIMIT 1
        ");

        $now = commit_now();

        $st->execute([
            ':status' => COMMIT_STATUS_CONFIRMED,
            ':tx_hash' => $txHash,
            ':verify_source' => 'toncenter_v3_php',
            ':confirmations' => $confirmations,
            ':meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':confirmed_at' => $now,
            ':updated_at' => $now,
            ':id' => $id,
        ]);
    }
}

if (!function_exists('commit_decode_meta')) {
    function commit_decode_meta(array $row): array
    {
        $metaJson = commit_str($row['meta_json'] ?? '');
        if ($metaJson === '') {
            return [];
        }
        $decoded = json_decode($metaJson, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('commit_mark_ledger_applied')) {
    function commit_mark_ledger_applied(PDO $pdo, int $id, array $meta, string $amountEmx, string $emaPrice, string $emaReward, string $source = 'verify_handler'): void
    {
        $meta['ledger_applied'] = true;
        $meta['ledger_applied_at'] = commit_now();
        $meta['ledger_source'] = $source;
        $meta['commit_amount_emx'] = commit_decimal_6($amountEmx);
        $meta['ema_price_snapshot'] = $emaPrice;
        $meta['ema_reward'] = $emaReward;

        $st = $pdo->prepare("
            UPDATE poado_storage_commits
            SET meta_json = :meta_json,
                updated_at = :updated_at
            WHERE id = :id
            LIMIT 1
        ");
        $st->execute([
            ':meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':updated_at' => commit_now(),
            ':id' => $id,
        ]);
    }
}

/* -------------------------------------------------------------------------- */
/* ema price + ledger */
/* -------------------------------------------------------------------------- */
if (!function_exists('commit_read_ema_price')) {
    function commit_read_ema_price(): string
    {
        $path = __DIR__ . '/../../global/ema-price.php';
        if (!is_file($path)) {
            throw new RuntimeException('EMA_PRICE_API_MISSING');
        }

        require_once $path;

        if (!function_exists('poado_ema_price_now')) {
            throw new RuntimeException('EMA_PRICE_FUNCTION_MISSING');
        }

        $price = trim((string) poado_ema_price_now());

        if ($price === '' || !preg_match('/^\d+(?:\.\d+)?$/', $price)) {
            throw new RuntimeException('EMA_PRICE_INVALID');
        }

        if ((float) $price <= 0) {
            throw new RuntimeException('EMA_PRICE_ZERO');
        }

        return $price;
    }
}

if (!function_exists('commit_calc_ema_reward')) {
    function commit_calc_ema_reward(string $amountEmx, string $emaPrice): string
    {
        $amount = (float)$amountEmx;
        $price = (float)$emaPrice;
        if ($price <= 0) {
            throw new RuntimeException('EMA_PRICE_ZERO');
        }

        $reward = $amount / $price;

        if (function_exists('bcdiv')) {
            return bcdiv((string)$amountEmx, (string)$emaPrice, 6);
        }

        return number_format($reward, 6, '.', '');
    }
}

if (!function_exists('commit_apply_ledger')) {
    function commit_apply_ledger(PDO $pdo, int $uid, array $row): array
    {
        $amountEmx = commit_decimal_6(commit_str($row['amount_emx'] ?? '0'));
        $emaPrice = commit_read_ema_price();
        $emaReward = commit_calc_ema_reward($amountEmx, $emaPrice);

        $st = $pdo->prepare("
            INSERT INTO rwa_storage_balances
                (user_id, unclaim_ema, created_at, updated_at)
            VALUES
                (:user_id, :unclaim_ema, :created_at, :updated_at)
            ON DUPLICATE KEY UPDATE
                unclaim_ema = COALESCE(unclaim_ema, 0.0000) + VALUES(unclaim_ema),
                updated_at = VALUES(updated_at)
        ");

        $now = commit_now();

        $st->execute([
            ':user_id' => $uid,
            ':unclaim_ema' => $emaReward,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return [
            'amount_emx' => $amountEmx,
            'ema_price' => $emaPrice,
            'ema_reward' => $emaReward,
        ];
    }
}

if (!function_exists('commit_repair_confirmed_ledger_if_missing')) {
    function commit_repair_confirmed_ledger_if_missing(PDO $pdo, int $uid, array $row): array
    {
        $meta = commit_decode_meta($row);
        if ((bool)($meta['ledger_applied'] ?? false) === true) {
            return [
                'repaired' => false,
                'ema_price' => (string)($meta['ema_price_snapshot'] ?? ''),
                'ema_reward' => (string)($meta['ema_reward'] ?? ''),
            ];
        }

        $ledger = commit_apply_ledger($pdo, $uid, $row);
        commit_mark_ledger_applied(
            $pdo,
            (int)$row['id'],
            $meta,
            $ledger['amount_emx'],
            $ledger['ema_price'],
            $ledger['ema_reward'],
            'already_confirmed_repair'
        );

        return [
            'repaired' => true,
            'ema_price' => $ledger['ema_price'],
            'ema_reward' => $ledger['ema_reward'],
        ];
    }
}

/* -------------------------------------------------------------------------- */
/* handlers */
/* -------------------------------------------------------------------------- */
if (!function_exists('storage_commit_handle')) {
    function storage_commit_handle(): never
    {
        commit_require_post();
        commit_require_csrf();

        $action = commit_str(commit_post('action', ''));
        if ($action === 'prepare') {
            storage_commit_prepare();
        }
        if ($action === 'verify') {
            storage_commit_verify();
        }

        commit_fail('INVALID_ACTION', 422);
    }
}

if (!function_exists('storage_commit_prepare')) {
    function storage_commit_prepare(): never
    {
        $pdo = commit_pdo();
        $user = commit_require_auth();

        $uid = (int)($user['id'] ?? $user['user_id'] ?? 0);
        if ($uid <= 0) {
            commit_fail('AUTH_REQUIRED', 401);
        }

        $amount = commit_str(commit_post('amount', ''));
        if ($amount === '' || !preg_match('/^\d+(?:\.\d+)?$/', $amount)) {
            commit_fail('INVALID_AMOUNT', 422);
        }

        $amountUnits = commit_decimal_to_units($amount, COMMIT_DECIMALS);
        if ($amountUnits === '' || $amountUnits === '0') {
            commit_fail('INVALID_AMOUNT', 422);
        }

        $wallet = commit_str(
            $user['wallet_address']
            ?? $user['ton_address']
            ?? $user['bound_ton_address']
            ?? ''
        );
        if ($wallet === '') {
            commit_fail('WALLET_REQUIRED', 422);
        }

        $treasury = commit_treasury();
        $jetton = commit_jetton_master();

        try {
            $pdo->beginTransaction();

            $ref = commit_ref_make();

            commit_insert_prepare_row(
                $pdo,
                $uid,
                $ref,
                $wallet,
                $treasury,
                $amount,
                $amountUnits,
                $jetton
            );

            $pdo->commit();

            $deeplink = 'ton://transfer/' . rawurlencode($treasury)
                . '?jetton=' . rawurlencode($jetton)
                . '&amount=' . rawurlencode($amountUnits)
                . '&text=' . rawurlencode($ref);

            commit_ok([
                'status' => 'COMMIT_PREPARED',
                'message' => 'COMMIT_PREPARED',
                'commit_ref' => $ref,
                'amount_emx' => commit_decimal_6($amount),
                'amount_units' => $amountUnits,
                'token' => COMMIT_TOKEN,
                'jetton_master' => $jetton,
                'wallet_address' => $wallet,
                'treasury_address' => $treasury,
                'deeplink' => $deeplink,
                'qr_text' => $deeplink,
            ], 200);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            commit_fail('COMMIT_PREPARE_FAILED', 500, [
                'detail' => $e->getMessage(),
            ]);
        }
    }
}

if (!function_exists('storage_commit_verify')) {
    function storage_commit_verify(): never
    {
        $pdo = commit_pdo();
        $user = commit_require_auth();
        $uid = (int)($user['id'] ?? $user['user_id'] ?? 0);

        $ref = commit_str(commit_post('commit_ref', ''));
        if ($ref === '') {
            commit_fail('REF_REQUIRED', 422);
        }

        $row = commit_find_row($pdo, $ref);
        if (!$row) {
            commit_fail('NOT_FOUND', 404);
        }

        if ((int)($row['user_id'] ?? 0) !== $uid) {
            commit_fail('FORBIDDEN', 403);
        }

        $status = strtoupper(commit_str($row['status'] ?? ''));
        if ($status === COMMIT_STATUS_CONFIRMED) {
            $ledgerRepaired = false;
            $emaPrice = '';
            $emaReward = '';

            try {
                $pdo->beginTransaction();

                $fresh = commit_find_row($pdo, $ref);
                if (!$fresh) {
                    throw new RuntimeException('COMMIT_NOT_FOUND_REPAIR');
                }

                $repair = commit_repair_confirmed_ledger_if_missing($pdo, $uid, $fresh);
                $ledgerRepaired = (bool)$repair['repaired'];
                $emaPrice = (string)$repair['ema_price'];
                $emaReward = (string)$repair['ema_reward'];

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }

            commit_ok([
                'status' => 'ALREADY_CONFIRMED',
                'message' => 'ALREADY_CONFIRMED',
                'commit_ref' => $ref,
                'tx_hash' => commit_str($row['tx_hash'] ?? ''),
                'amount_emx' => commit_decimal_6(commit_str($row['amount_emx'] ?? '0')),
                'ledger_applied' => true,
                'ledger_repaired' => $ledgerRepaired,
                'ema_price' => $emaPrice,
                'ema_reward' => $emaReward,
            ], 200);
        }

        $providedTxHash = commit_str(commit_post('tx_hash', ''));
        $expectedWallet = commit_str($row['wallet_address'] ?? '');
        $expectedAmountUnits = commit_digits_only(commit_str($row['amount_units'] ?? ''));
        $expectedJetton = commit_jetton_master();

        if ($expectedWallet === '') {
            commit_fail('EXPECTED_WALLET_MISSING', 500);
        }
        if ($expectedAmountUnits === '' || $expectedAmountUnits === '0') {
            commit_fail('EXPECTED_AMOUNT_MISSING', 500);
        }

        try {
            $verify = rwa_onchain_verify_jetton_transfer([
                'owner_address' => $expectedWallet,
                'token_key' => COMMIT_TOKEN,
                'jetton_master' => $expectedJetton,
                'amount_units' => $expectedAmountUnits,
                'ref' => $ref,
                'tx_hint' => $providedTxHash,
                'limit' => 100,
            ]);
        } catch (Throwable $e) {
            commit_fail('TONCENTER_UNREACHABLE', 502, [
                'detail' => $e->getMessage(),
            ]);
        }

        if (($verify['ok'] ?? false) !== true) {
            commit_fail('TRANSFER_NOT_FOUND', 404, [
                'commit_ref' => $ref,
                'expected_wallet' => $expectedWallet,
                'expected_amount' => $expectedAmountUnits,
                'jetton_master' => $expectedJetton,
                'verify_code' => (string)($verify['code'] ?? 'NO_MATCH'),
                'debug' => $verify['debug'] ?? [],
            ]);
        }

        $txHash = commit_str($verify['tx_hash'] ?? '');
        $confirmations = (int)($verify['confirmations'] ?? COMMIT_REQUIRED_CONFIRMATIONS);
        if ($confirmations <= 0) {
            $confirmations = COMMIT_REQUIRED_CONFIRMATIONS;
        }
        if ($confirmations < COMMIT_REQUIRED_CONFIRMATIONS) {
            commit_fail('INSUFFICIENT_CONFIRMATIONS', 409, [
                'confirmations' => $confirmations,
                'required_confirmations' => COMMIT_REQUIRED_CONFIRMATIONS,
                'commit_ref' => $ref,
                'tx_hash' => $txHash,
            ]);
        }

        try {
            $pdo->beginTransaction();

            $fresh = commit_find_row($pdo, $ref);
            if (!$fresh) {
                throw new RuntimeException('COMMIT_NOT_FOUND_AFTER_LOCK');
            }

            $freshStatus = strtoupper(commit_str($fresh['status'] ?? ''));
            if ($freshStatus === COMMIT_STATUS_CONFIRMED) {
                $repair = commit_repair_confirmed_ledger_if_missing($pdo, $uid, $fresh);

                $pdo->commit();

                commit_ok([
                    'status' => 'ALREADY_CONFIRMED',
                    'message' => 'ALREADY_CONFIRMED',
                    'commit_ref' => $ref,
                    'tx_hash' => commit_str($fresh['tx_hash'] ?? $txHash),
                    'amount_emx' => commit_decimal_6(commit_str($fresh['amount_emx'] ?? '0')),
                    'ledger_applied' => true,
                    'ledger_repaired' => (bool)$repair['repaired'],
                    'ema_price' => (string)$repair['ema_price'],
                    'ema_reward' => (string)$repair['ema_reward'],
                ], 200);
            }

            $ledger = commit_apply_ledger($pdo, $uid, $fresh);

            $meta = [
                'flow' => 'storage_commit',
                'verified_via' => 'toncenter_v3_php',
                'version' => STORAGE_COMMIT_VERSION,
                'commit_ref' => $ref,
                'wallet_address' => $expectedWallet,
                'treasury_address' => commit_str($fresh['treasury_address'] ?? ''),
                'jetton_master' => $expectedJetton,
                'token_key' => (string)($verify['token_key'] ?? COMMIT_TOKEN),
                'amount_units' => $expectedAmountUnits,
                'amount_emx' => $ledger['amount_emx'],
                'tx_hash' => $txHash,
                'confirmations' => $confirmations,
                'payload_text' => (string)($verify['payload_text'] ?? ''),
                'match_jetton' => (bool)($verify['match_jetton'] ?? false),
                'match_amount' => (bool)($verify['match_amount'] ?? false),
                'match_ref' => (bool)($verify['match_ref'] ?? false),
                'verified_at' => commit_now(),
                'raw_transfer' => $verify['raw_transfer'] ?? null,
                'ledger_applied' => true,
                'ledger_applied_at' => commit_now(),
                'ledger_source' => 'verify_handler',
                'ema_price_snapshot' => $ledger['ema_price'],
                'ema_reward' => $ledger['ema_reward'],
            ];

            commit_mark_confirmed(
                $pdo,
                (int)$fresh['id'],
                $txHash,
                $confirmations,
                $meta
            );

            $pdo->commit();

            commit_ok([
                'status' => 'CONFIRMED',
                'message' => 'CONFIRMED',
                'commit_ref' => $ref,
                'tx_hash' => $txHash,
                'amount_emx' => $ledger['amount_emx'],
                'amount_units' => $expectedAmountUnits,
                'verify_source' => 'toncenter_v3_php',
                'confirmations' => $confirmations,
                'ledger_applied' => true,
                'ema_price' => $ledger['ema_price'],
                'ema_reward' => $ledger['ema_reward'],
            ], 200);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            commit_fail('VERIFY_CONFIRM_FAILED', 500, [
                'detail' => $e->getMessage(),
            ]);
        }
    }
}