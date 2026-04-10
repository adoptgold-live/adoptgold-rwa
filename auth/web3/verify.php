<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

header('Content-Type: application/json; charset=utf-8');

function web3_ok(array $data = [], int $status = 200): never {
    http_response_code($status);
    echo json_encode(array_merge([
        'ok' => true,
        'ts' => time(),
    ], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function web3_err(string $error, int $status = 400, array $extra = []): never {
    http_response_code($status);
    echo json_encode(array_merge([
        'ok' => false,
        'error' => $error,
        'ts' => time(),
        'next_url' => null,
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function web3_pdo(): PDO {
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
    db_connect();
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
    throw new RuntimeException('DB_UNAVAILABLE');
}

function web3_json_input(): array {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function web3_fetch_one(PDO $pdo, string $sql, array $params = []): ?array {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function web3_exec(PDO $pdo, string $sql, array $params = []): int {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

function web3_normalize_wallet(string $wallet): string {
    $wallet = strtolower(trim($wallet));
    if (!preg_match('/^0x[a-f0-9]{40}$/', $wallet)) {
        web3_err('INVALID_WALLET', 422, ['message' => 'verify failed']);
    }
    return $wallet;
}

function web3_safe_next(?string $next): string {
    if (function_exists('poado_safe_next')) {
        return poado_safe_next($next, '/rwa/login-select.php');
    }
    return '/rwa/login-select.php';
}

/**
 * Replace this with your installed signature verification library.
 * It must recover signer address from signature + message and compare to $wallet.
 */
function web3_verify_signature_or_fail(array $in, string $wallet): void {
    $signature = trim((string)($in['signature'] ?? ''));
    $message = trim((string)($in['message'] ?? ''));
    $nonce = trim((string)($in['nonce'] ?? ''));

    if ($signature === '' || $message === '' || $nonce === '') {
        web3_err('VERIFY_FAILED', 401, ['message' => 'verify failed']);
    }

    $sessionNonce = trim((string)($_SESSION['web3_nonce'] ?? ''));
    if ($sessionNonce === '' || !hash_equals($sessionNonce, $nonce)) {
        web3_err('NONCE_MISMATCH', 409, ['message' => 'verify failed']);
    }

    // TODO: plug real EVM signature recovery here.
    // Example expected outcome:
    // $recovered = strtolower(recover_address_from_signature($message, $signature));
    // if ($recovered !== $wallet) web3_err('VERIFY_FAILED', 401, ['message' => 'verify failed']);

    if (stripos($message, $nonce) === false) {
        web3_err('VERIFY_FAILED', 401, ['message' => 'verify failed']);
    }
}

function web3_load_user(PDO $pdo, string $wallet): ?array {
    return web3_fetch_one($pdo, "SELECT * FROM users WHERE wallet = :wallet LIMIT 1", [
        ':wallet' => $wallet,
    ]);
}

function web3_create_user(PDO $pdo, string $wallet): array {
    $nickname = 'Web3_' . substr($wallet, 2, 8);

    web3_exec($pdo, "
        INSERT INTO users (
            wallet,
            is_registered,
            nickname,
            role,
            is_active,
            is_fully_verified,
            is_senior,
            created_at,
            updated_at
        ) VALUES (
            :wallet,
            0,
            :nickname,
            'adoptee',
            1,
            0,
            0,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
        )
    ", [
        ':wallet' => $wallet,
        ':nickname' => $nickname,
    ]);

    $id = (int)$pdo->lastInsertId();
    if ($id <= 0) throw new RuntimeException('WEB3_USER_CREATE_FAILED');

    $user = web3_fetch_one($pdo, "SELECT * FROM users WHERE id = :id LIMIT 1", [':id' => $id]);
    if (!$user) throw new RuntimeException('WEB3_USER_RELOAD_FAILED');

    return $user;
}

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        web3_err('METHOD_NOT_ALLOWED', 405);
    }

    $in = web3_json_input();
    $wallet = web3_normalize_wallet((string)($in['wallet'] ?? ''));
    $next = web3_safe_next($_SESSION['web3_next_url'] ?? '/rwa/login-select.php');

    web3_verify_signature_or_fail($in, $wallet);

    $pdo = web3_pdo();
    $user = web3_load_user($pdo, $wallet);

    if (!$user) {
        $pdo->beginTransaction();
        try {
            $user = web3_create_user($pdo, $wallet);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    session_attach_user($user);
    $_SESSION['auth_method'] = 'WEB3';
    unset($_SESSION['web3_nonce'], $_SESSION['web3_next_url']);

    web3_ok([
        'message' => 'VERIFY_OK',
        'next_url' => $next,
        'user_id' => (int)($user['id'] ?? 0),
        'auth_method' => 'WEB3',
    ]);
} catch (Throwable $e) {
    web3_err('WEB3_VERIFY_FAILED', 500, ['message' => 'verify failed']);
}