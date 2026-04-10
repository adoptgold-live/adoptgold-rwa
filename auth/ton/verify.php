<?php
declare(strict_types=1);

/**
 * AdoptGold RWA
 * TON Verify
 * File: /var/www/html/public/rwa/auth/ton/verify.php
 * Version: v1.0.20260315-final-fix
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

header('Content-Type: application/json; charset=utf-8');

function json_exit(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function req_json(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST ?: [];
}

function s(array $src, string $key, int $max = 4096): string
{
    $v = isset($src[$key]) ? trim((string)$src[$key]) : '';
    if ($max > 0 && mb_strlen($v) > $max) {
        $v = mb_substr($v, 0, $max);
    }
    return $v;
}

function arr($v): array
{
    return is_array($v) ? $v : [];
}

function ton_session_nonce(): string
{
    $keys = [
        'rwa_ton_proof_nonce',
        'ton_proof_nonce',
        'rwa_ton_nonce',
        'ton_nonce',
        'ton_login_nonce',
        'tonconnect_nonce',
        'ton_nonce_payload',
    ];

    foreach ($keys as $k) {
        if (!empty($_SESSION[$k]) && is_string($_SESSION[$k])) {
            return trim($_SESSION[$k]);
        }
    }
    return '';
}

function clear_ton_session_nonce(): void
{
    $keys = [
        'rwa_ton_proof_nonce',
        'rwa_ton_proof_nonce_created_at',
        'rwa_ton_proof_nonce_expires_at',
        'ton_proof_nonce',
        'rwa_ton_nonce',
        'ton_nonce',
        'ton_login_nonce',
        'tonconnect_nonce',
        'ton_nonce_payload',
    ];

    foreach ($keys as $k) {
        unset($_SESSION[$k]);
    }
}

function normalize_ton_address(string $addr): string
{
    return trim($addr);
}

function ton_address_shape_ok(string $addr): bool
{
    if ($addr === '') return false;

    if (preg_match('/^[0-9a-fA-F]{1,4}:[0-9a-fA-F]{64}$/', $addr)) {
        return true;
    }

    if (preg_match('/^[UEk0][A-Za-z0-9_-]{30,120}$/', $addr)) {
        return true;
    }

    return false;
}

function extract_proof_payload(array $proof): string
{
    $paths = [
        $proof['payload'] ?? null,
        $proof['proof']['payload'] ?? null,
        $proof['tonProof']['payload'] ?? null,
    ];

    foreach ($paths as $v) {
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }
    }
    return '';
}

function extract_proof_domain(array $proof): string
{
    $paths = [
        $proof['domain']['value'] ?? null,
        $proof['proof']['domain']['value'] ?? null,
        $proof['tonProof']['domain']['value'] ?? null,
    ];

    foreach ($paths as $v) {
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }
    }
    return '';
}

function extract_proof_timestamp(array $proof): int
{
    $paths = [
        $proof['timestamp'] ?? null,
        $proof['proof']['timestamp'] ?? null,
        $proof['tonProof']['timestamp'] ?? null,
    ];

    foreach ($paths as $v) {
        if (is_numeric($v)) {
            return (int)$v;
        }
    }
    return 0;
}

function fresh_user_by_id(PDO $db, int $id): ?array
{
    $st = $db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function refresh_session_user(array $user): void
{
    if (function_exists('session_user_set')) {
        @session_user_set($user);
    }

    $_SESSION['session_user'] = $user;
    $_SESSION['user'] = $user;
    $_SESSION['rwa_user'] = $user;
}

function create_new_user_with_ton(PDO $db, string $tonAddress): int
{
    $nickname = 'TON User ' . substr(md5($tonAddress . '|' . microtime(true)), 0, 8);

    $st = $db->prepare("
        INSERT INTO users
            (wallet, is_registered, nickname, is_active, wallet_address, created_at, updated_at)
        VALUES
            ('', 0, ?, 1, ?, NOW(), NOW())
    ");
    $st->execute([$nickname, $tonAddress]);

    return (int)$db->lastInsertId();
}

try {
    if (!function_exists('db')) {
        throw new RuntimeException('db() not available from bootstrap.');
    }

    $db = db();
    if (!$db instanceof PDO) {
        throw new RuntimeException('Database connection unavailable.');
    }

    $in = req_json();
    $tonAddress = normalize_ton_address(s($in, 'ton_address', 255));
    $proof = arr($in['proof'] ?? null);

    if ($tonAddress === '') {
        json_exit([
            'ok' => false,
            'error' => 'missing_address',
            'message' => 'missing_address'
        ], 422);
    }

    if (!ton_address_shape_ok($tonAddress)) {
        json_exit([
            'ok' => false,
            'error' => 'invalid_address',
            'message' => 'invalid_address'
        ], 422);
    }

    if (empty($proof)) {
        json_exit([
            'ok' => false,
            'error' => 'missing_proof',
            'message' => 'missing_proof'
        ], 422);
    }

    $sessionNonce = ton_session_nonce();
    if ($sessionNonce === '') {
        json_exit([
            'ok' => false,
            'error' => 'missing_nonce',
            'message' => 'missing_nonce'
        ], 422);
    }

    $proofPayload = extract_proof_payload($proof);
    if ($proofPayload === '') {
        json_exit([
            'ok' => false,
            'error' => 'missing_payload',
            'message' => 'missing_payload'
        ], 422);
    }

    if (!hash_equals($sessionNonce, $proofPayload)) {
        json_exit([
            'ok' => false,
            'error' => 'nonce_mismatch',
            'message' => 'nonce_mismatch'
        ], 422);
    }

    $proofDomain = strtolower(extract_proof_domain($proof));
    if ($proofDomain !== '' && $proofDomain !== 'adoptgold.app') {
        json_exit([
            'ok' => false,
            'error' => 'domain_mismatch',
            'message' => 'domain_mismatch'
        ], 422);
    }

    $proofTs = extract_proof_timestamp($proof);
    if ($proofTs > 0 && abs(time() - $proofTs) > 900) {
        json_exit([
            'ok' => false,
            'error' => 'proof_expired',
            'message' => 'proof_expired'
        ], 422);
    }

    $db->beginTransaction();

    // First try active ton identity link
    $st = $db->prepare("
        SELECT user_id
        FROM poado_identity_links
        WHERE identity_type = 'ton'
          AND identity_key = ?
          AND is_active = 1
        LIMIT 1
        FOR UPDATE
    ");
    $st->execute([$tonAddress]);
    $link = $st->fetch(PDO::FETCH_ASSOC);

    $userId = 0;

    if ($link && !empty($link['user_id'])) {
        $userId = (int)$link['user_id'];
    } else {
        // Fallback: existing users.wallet_address
        $st = $db->prepare("
            SELECT id
            FROM users
            WHERE wallet_address = ?
            LIMIT 1
            FOR UPDATE
        ");
        $st->execute([$tonAddress]);
        $user = $st->fetch(PDO::FETCH_ASSOC);

        if ($user && !empty($user['id'])) {
            $userId = (int)$user['id'];
        } else {
            $userId = create_new_user_with_ton($db, $tonAddress);
        }
    }

    // Ensure wallet is stored on user
    $st = $db->prepare("
        UPDATE users
        SET wallet_address = ?, updated_at = NOW()
        WHERE id = ?
        LIMIT 1
    ");
    $st->execute([$tonAddress, $userId]);

    // Disable any other ton links for this user
    $st = $db->prepare("
        UPDATE poado_identity_links
        SET is_active = 0
        WHERE user_id = ?
          AND identity_type = 'ton'
          AND identity_key <> ?
          AND is_active = 1
    ");
    $st->execute([$userId, $tonAddress]);

    // Ensure active ton identity link exists
    $st = $db->prepare("
        SELECT id
        FROM poado_identity_links
        WHERE user_id = ?
          AND identity_type = 'ton'
          AND identity_key = ?
        LIMIT 1
        FOR UPDATE
    ");
    $st->execute([$userId, $tonAddress]);
    $ownLink = $st->fetch(PDO::FETCH_ASSOC);

    if ($ownLink && !empty($ownLink['id'])) {
        $st = $db->prepare("
            UPDATE poado_identity_links
            SET is_active = 1, last_login_at = NOW()
            WHERE id = ?
            LIMIT 1
        ");
        $st->execute([(int)$ownLink['id']]);
    } else {
        $st = $db->prepare("
            INSERT INTO poado_identity_links
                (user_id, identity_type, identity_key, created_at, last_login_at, is_active)
            VALUES
                (?, 'ton', ?, NOW(), NOW(), 1)
        ");
        $st->execute([$userId, $tonAddress]);
    }

    $fresh = fresh_user_by_id($db, $userId);
    if (!$fresh) {
        throw new RuntimeException('user_refresh_failed');
    }

    clear_ton_session_nonce();
    refresh_session_user($fresh);

    $db->commit();

    json_exit([
        'ok' => true,
        'message' => 'TON login success.',
        'next' => '/rwa/login-select.php',
        'user_id' => $userId,
        'wallet_address' => $tonAddress
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    json_exit([
        'ok' => false,
        'error' => 'verify_failed',
        'message' => $e->getMessage()
    ], 500);
}
