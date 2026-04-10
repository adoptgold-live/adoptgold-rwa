<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/profile/bind-ton.php
 * AdoptGold / POAdo — Canonical Profile TON Bind API
 * Version: v2.0.0-locked-20260318
 *
 * Global lock:
 * - keep old canonical profile API path
 * - use /rwa/inc/core/bootstrap.php
 * - use /rwa/inc/core/session-user.php canonical auth/session resolver
 * - allow bind / rebind for current authenticated user
 * - reject wallet already bound to another user
 * - require TON proof payload presence
 * - if session nonce exists, payload must match it
 * - write canonical session shape through session_attach_user()
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

header('Content-Type: application/json; charset=utf-8');

function out_ok(array $data = [], int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        array_merge(['ok' => true, 'ts' => time()], $data),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function out_err(string $error, int $status = 400, array $extra = []): never
{
    http_response_code($status);
    echo json_encode(
        array_merge(['ok' => false, 'error' => $error, 'ts' => time()], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function rwa_pdo(): PDO
{
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }

    if (function_exists('db_connect')) {
        db_connect();
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            return $GLOBALS['pdo'];
        }
    }

    if (function_exists('rwa_db')) {
        $pdo = rwa_db();
        if ($pdo instanceof PDO) {
            return $pdo;
        }
    }

    throw new RuntimeException('Standalone RWA DB handle unavailable.');
}

function fetch_one(PDO $pdo, string $sql, array $params = []): ?array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function exec_stmt(PDO $pdo, string $sql, array $params = []): int
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

function get_json_input(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function get_current_user_id(): int
{
    if (function_exists('session_user_id')) {
        $id = (int) session_user_id();
        if ($id > 0) {
            return $id;
        }
    }

    if (function_exists('session_user')) {
        $u = session_user();
        if (is_array($u) && !empty($u['id'])) {
            return (int) $u['id'];
        }
    }

    $paths = [
        ['rwa_user', 'id'],
        ['user', 'id'],
        ['user_id'],
        ['uid'],
    ];

    foreach ($paths as $p) {
        if (count($p) === 2) {
            if (!empty($_SESSION[$p[0]][$p[1]])) {
                return (int) $_SESSION[$p[0]][$p[1]];
            }
        } else {
            if (!empty($_SESSION[$p[0]])) {
                return (int) $_SESSION[$p[0]];
            }
        }
    }

    return 0;
}

function normalize_wallet(string $wallet): string
{
    return trim($wallet);
}

function is_probably_ton_address(string $wallet): bool
{
    return (bool) (
        preg_match('/^(EQ|UQ|kQ|0Q)[A-Za-z0-9_-]{40,}$/', $wallet) ||
        preg_match('/^[0-9a-fA-F]{64}$/', $wallet) ||
        preg_match('/^-?\d+:[0-9a-fA-F]{64}$/', $wallet)
    );
}

function ton_proof_payload(array $proof): string
{
    $payload = $proof['payload'] ?? '';
    if (is_array($payload)) {
        return '';
    }
    return trim((string) $payload);
}

function ton_proof_timestamp(array $proof): int
{
    return (int) ($proof['timestamp'] ?? 0);
}

function ton_proof_present(array $proof): bool
{
    return isset($proof['proof']) || isset($proof['signature']) || isset($proof['timestamp']) || isset($proof['payload']);
}

function read_expected_nonce_from_session(): string
{
    $candidates = [
        $_SESSION['rwa_ton_nonce'] ?? null,
        $_SESSION['rwa_ton_payload'] ?? null,
        $_SESSION['ton_nonce'] ?? null,
        $_SESSION['ton_payload'] ?? null,
        $_SESSION['rwa']['ton_nonce'] ?? null,
        $_SESSION['rwa']['ton_payload'] ?? null,
    ];

    foreach ($candidates as $v) {
        $s = trim((string) $v);
        if ($s !== '') {
            return $s;
        }
    }

    return '';
}

function consume_known_ton_nonce(): void
{
    unset(
        $_SESSION['rwa_ton_nonce'],
        $_SESSION['rwa_ton_payload'],
        $_SESSION['ton_nonce'],
        $_SESSION['ton_payload']
    );

    if (isset($_SESSION['rwa']) && is_array($_SESSION['rwa'])) {
        unset($_SESSION['rwa']['ton_nonce'], $_SESSION['rwa']['ton_payload']);
    }
}

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        out_err('METHOD_NOT_ALLOWED', 405);
    }

    $userId = get_current_user_id();
    if ($userId <= 0) {
        out_err('AUTH_REQUIRED', 401);
    }

    $pdo = rwa_pdo();
    $in = get_json_input();

    $wallet = normalize_wallet((string) ($in['wallet_address'] ?? ''));
    $tonProof = is_array($in['ton_proof'] ?? null) ? $in['ton_proof'] : [];

    if ($wallet === '') {
        out_err('WALLET_ADDRESS_REQUIRED', 422);
    }

    if (!is_probably_ton_address($wallet)) {
        out_err('INVALID_TON_WALLET_ADDRESS', 422);
    }

    if (!ton_proof_present($tonProof)) {
        out_err('TON_PROOF_REQUIRED', 422);
    }

    $proofPayload = ton_proof_payload($tonProof);
    $expectedNonce = read_expected_nonce_from_session();

    if ($expectedNonce !== '') {
        if ($proofPayload === '') {
            out_err('TON_PROOF_PAYLOAD_REQUIRED', 422);
        }
        if (!hash_equals($expectedNonce, $proofPayload)) {
            out_err('TON_PROOF_PAYLOAD_MISMATCH', 409);
        }
    }

    $proofTs = ton_proof_timestamp($tonProof);
    if ($proofTs > 0) {
        $now = time();
        if ($proofTs < ($now - 900) || $proofTs > ($now + 300)) {
            out_err('TON_PROOF_TIMESTAMP_INVALID', 409);
        }
    }

    $user = fetch_one(
        $pdo,
        "SELECT
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
         WHERE id = :id
         LIMIT 1",
        [':id' => $userId]
    );

    if (!$user) {
        out_err('USER_NOT_FOUND', 404);
    }

    $existingOwner = fetch_one(
        $pdo,
        "SELECT id
         FROM users
         WHERE wallet_address = :wallet
           AND id <> :id
         LIMIT 1",
        [
            ':wallet' => $wallet,
            ':id' => $userId,
        ]
    );

    if ($existingOwner) {
        out_err('TON_WALLET_ALREADY_BOUND_TO_ANOTHER_ACCOUNT', 409);
    }

    $oldWallet = trim((string) ($user['wallet_address'] ?? ''));
    $isRebind = ($oldWallet !== '' && $oldWallet !== $wallet);

    $newWalletField = trim((string) ($user['wallet'] ?? ''));
    if ($newWalletField === '') {
        $newWalletField = 'ton:' . $wallet;
    }

    $pdo->beginTransaction();

    exec_stmt(
        $pdo,
        "UPDATE users
            SET wallet_address = :wallet_address,
                wallet = :wallet,
                updated_at = CURRENT_TIMESTAMP
          WHERE id = :id
          LIMIT 1",
        [
            ':wallet_address' => $wallet,
            ':wallet' => $newWalletField,
            ':id' => $userId,
        ]
    );

    $updatedUser = fetch_one(
        $pdo,
        "SELECT
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
         WHERE id = :id
         LIMIT 1",
        [':id' => $userId]
    );

    if (!$updatedUser) {
        throw new RuntimeException('UPDATED_USER_NOT_FOUND');
    }

    $pdo->commit();

    if (function_exists('session_attach_user')) {
        session_attach_user($updatedUser);
    } else {
        if (!isset($_SESSION['rwa_user']) || !is_array($_SESSION['rwa_user'])) {
            $_SESSION['rwa_user'] = [];
        }
        if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
            $_SESSION['user'] = [];
        }

        $_SESSION['rwa_user']['id'] = (int) $updatedUser['id'];
        $_SESSION['rwa_user']['wallet_address'] = (string) $updatedUser['wallet_address'];
        $_SESSION['rwa_user']['wallet'] = (string) $updatedUser['wallet'];
        $_SESSION['rwa_user']['nickname'] = (string) ($updatedUser['nickname'] ?? '');
        $_SESSION['rwa_user']['email'] = (string) ($updatedUser['email'] ?? '');

        $_SESSION['user']['id'] = (int) $updatedUser['id'];
        $_SESSION['user']['wallet_address'] = (string) $updatedUser['wallet_address'];
        $_SESSION['user']['wallet'] = (string) $updatedUser['wallet'];
        $_SESSION['user']['nickname'] = (string) ($updatedUser['nickname'] ?? '');
        $_SESSION['user']['email'] = (string) ($updatedUser['email'] ?? '');

        $_SESSION['user_id'] = (int) $updatedUser['id'];
        $_SESSION['uid'] = (int) $updatedUser['id'];
        $_SESSION['wallet_address'] = (string) $updatedUser['wallet_address'];
        $_SESSION['wallet'] = (string) $updatedUser['wallet'];
    }

    consume_known_ton_nonce();

    out_ok([
        'message' => $isRebind ? 'TON wallet rebound successfully.' : 'TON wallet bound successfully.',
        'wallet_address' => (string) $updatedUser['wallet_address'],
        'old_wallet_address' => $oldWallet,
        'rebound' => $isRebind,
        'user_id' => (int) $updatedUser['id'],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    out_err('FAILED_TO_BIND_TON_WALLET', 500, [
        'debug' => [
            'message' => $e->getMessage(),
        ]
    ]);
}