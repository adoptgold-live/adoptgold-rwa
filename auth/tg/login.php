<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';

if (!function_exists('tg_login_json')) {
    function tg_login_json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('tg_login_ts')) {
    function tg_login_ts(): int
    {
        return time();
    }
}

if (!function_exists('tg_login_next_url')) {
    function tg_login_next_url(string $url): string
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^/[\w\-/?.=&%]*$#', $url)) {
            return '/rwa/login-select.php';
        }
        return $url;
    }
}

if (!function_exists('tg_login_db')) {
    function tg_login_db(): PDO
    {
        global $pdo, $db, $conn;

        if (isset($pdo) && $pdo instanceof PDO) return $pdo;
        if (isset($db) && $db instanceof PDO) return $db;
        if (isset($conn) && $conn instanceof PDO) return $conn;
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
        if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) return $GLOBALS['db'];
        if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof PDO) return $GLOBALS['conn'];

        if (function_exists('rwa_db')) {
            $x = rwa_db();
            if ($x instanceof PDO) return $x;
        }
        if (function_exists('db_connect')) {
            $x = db_connect();
            if ($x instanceof PDO) return $x;
        }
        if (function_exists('get_pdo')) {
            $x = get_pdo();
            if ($x instanceof PDO) return $x;
        }
        if (function_exists('get_db')) {
            $x = get_db();
            if ($x instanceof PDO) return $x;
        }
        if (function_exists('pdo')) {
            $x = pdo();
            if ($x instanceof PDO) return $x;
        }

        throw new RuntimeException('PDO_NOT_READY');
    }
}

if (!function_exists('tg_build_user_wallet')) {
    function tg_build_user_wallet(string $tgId): string
    {
        return 'tg:' . $tgId;
    }
}

if (!function_exists('tg_load_candidate_tokens')) {
    function tg_load_candidate_tokens(PDO $pdo): array
    {
        $sql = "
            SELECT id, tg_id, token, purpose, issued_at, expires_at, used_at
            FROM poado_tg_login_tokens
            WHERE purpose = 'login'
              AND used_at IS NULL
              AND expires_at > NOW()
            ORDER BY id DESC
            LIMIT 50
        ";
        $stmt = $pdo->query($sql);
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }
}

if (!function_exists('tg_find_or_create_identity')) {
    function tg_find_or_create_identity(PDO $pdo, string $tgId): array
    {
        $stmt = $pdo->prepare("
            SELECT *
            FROM poado_tg_identities
            WHERE tg_id = :tg_id
            LIMIT 1
        ");
        $stmt->execute([':tg_id' => $tgId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $upd = $pdo->prepare("
                UPDATE poado_tg_identities
                SET is_active = 1,
                    updated_at = NOW(),
                    last_seen_at = NOW()
                WHERE id = :id
            ");
            $upd->execute([':id' => $row['id']]);

            $stmt->execute([':tg_id' => $tgId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('TG_IDENTITY_RELOAD_FAILED');
            }
            return $row;
        }

        $ins = $pdo->prepare("
            INSERT INTO poado_tg_identities
            (tg_id, tg_username, tg_first_name, tg_last_name, is_active, created_at, updated_at, last_seen_at)
            VALUES
            (:tg_id, '', '', '', 1, NOW(), NOW(), NOW())
        ");
        $ins->execute([':tg_id' => $tgId]);

        $stmt->execute([':tg_id' => $tgId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('TG_IDENTITY_CREATE_FAILED');
        }
        return $row;
    }
}

if (!function_exists('tg_find_linked_user')) {
    function tg_find_linked_user(PDO $pdo, string $tgId): ?array
    {
        $stmt = $pdo->prepare("
            SELECT u.*
            FROM poado_identity_links l
            INNER JOIN users u ON u.id = l.user_id
            WHERE l.identity_type = 'tg'
              AND l.identity_key = :identity_key
              AND l.is_active = 1
            ORDER BY l.id DESC
            LIMIT 1
        ");
        $stmt->execute([':identity_key' => $tgId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('tg_create_user')) {
    function tg_create_user(PDO $pdo, string $tgId): array
    {
        $wallet = tg_build_user_wallet($tgId);
        $nickname = 'TG_' . substr(sha1($tgId . '|' . microtime(true)), 0, 8);

        $stmt = $pdo->prepare("
            INSERT INTO users
            (wallet, nickname, role, is_active, is_fully_verified, created_at, updated_at)
            VALUES
            (:wallet, :nickname, 'adoptee', 1, 0, NOW(), NOW())
        ");
        $stmt->execute([
            ':wallet' => $wallet,
            ':nickname' => $nickname,
        ]);

        $userId = (int)$pdo->lastInsertId();
        if ($userId <= 0) {
            throw new RuntimeException('USER_CREATE_FAILED');
        }

        $reload = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $reload->execute([':id' => $userId]);
        $user = $reload->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new RuntimeException('USER_RELOAD_FAILED');
        }

        return $user;
    }
}

if (!function_exists('tg_ensure_identity_link')) {
    function tg_ensure_identity_link(PDO $pdo, int $userId, string $tgId): void
    {
        $chk = $pdo->prepare("
            SELECT id
            FROM poado_identity_links
            WHERE user_id = :user_id
              AND identity_type = 'tg'
              AND identity_key = :identity_key
            LIMIT 1
        ");
        $chk->execute([
            ':user_id' => $userId,
            ':identity_key' => $tgId,
        ]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $upd = $pdo->prepare("
                UPDATE poado_identity_links
                SET is_active = 1,
                    last_login_at = NOW()
                WHERE id = :id
            ");
            $upd->execute([':id' => $row['id']]);
            return;
        }

        $ins = $pdo->prepare("
            INSERT INTO poado_identity_links
            (user_id, identity_type, identity_key, created_at, last_login_at, is_active)
            VALUES
            (:user_id, 'tg', :identity_key, NOW(), NOW(), 1)
        ");
        $ins->execute([
            ':user_id' => $userId,
            ':identity_key' => $tgId,
        ]);
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        tg_login_json([
            'ok' => false,
            'error' => 'METHOD_NOT_ALLOWED',
            'ts' => tg_login_ts(),
            'next_url' => null,
        ], 405);
    }

    $pdo = tg_login_db();

    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $pin = trim((string)($data['pin'] ?? ''));
    if ($pin === '' || !preg_match('/^\d{6}$/', $pin)) {
        tg_login_json([
            'ok' => false,
            'error' => 'INVALID_PIN',
            'ts' => tg_login_ts(),
            'next_url' => null,
        ], 422);
    }

    $nextUrl = tg_login_next_url((string)($data['next_url'] ?? '/rwa/login-select.php'));

    $candidates = tg_load_candidate_tokens($pdo);
    if (!$candidates) {
        tg_login_json([
            'ok' => false,
            'error' => 'PIN_NOT_FOUND',
            'ts' => tg_login_ts(),
            'next_url' => null,
        ], 401);
    }

    $matched = null;
    foreach ($candidates as $row) {
        $hash = (string)($row['token'] ?? '');
        if ($hash !== '' && password_verify($pin, $hash)) {
            $matched = $row;
            break;
        }
    }

    if (!$matched) {
        tg_login_json([
            'ok' => false,
            'error' => 'INVALID_OR_EXPIRED_PIN',
            'ts' => tg_login_ts(),
            'next_url' => null,
        ], 401);
    }

    $clientIp = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    $clientUa = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $pdo->beginTransaction();

    $lock = $pdo->prepare("
        SELECT id, tg_id, token, purpose, issued_at, expires_at, used_at
        FROM poado_tg_login_tokens
        WHERE id = :id
        FOR UPDATE
    ");
    $lock->execute([':id' => $matched['id']]);
    $locked = $lock->fetch(PDO::FETCH_ASSOC);

    if (!$locked) {
        $pdo->rollBack();
        tg_login_json([
            'ok' => false,
            'error' => 'PIN_NOT_FOUND',
            'ts' => tg_login_ts(),
            'next_url' => null,
        ], 401);
    }

    if ((string)($locked['used_at'] ?? '') !== '') {
        $pdo->rollBack();
        tg_login_json([
            'ok' => false,
            'error' => 'INVALID_OR_EXPIRED_PIN',
            'ts' => tg_login_ts(),
            'next_url' => null,
        ], 401);
    }

    if (!password_verify($pin, (string)$locked['token'])) {
        $pdo->rollBack();
        tg_login_json([
            'ok' => false,
            'error' => 'INVALID_OR_EXPIRED_PIN',
            'ts' => tg_login_ts(),
            'next_url' => null,
        ], 401);
    }

    if (strtotime((string)$locked['expires_at']) <= time()) {
        $pdo->rollBack();
        tg_login_json([
            'ok' => false,
            'error' => 'INVALID_OR_EXPIRED_PIN',
            'ts' => tg_login_ts(),
            'next_url' => null,
        ], 401);
    }

    $consume = $pdo->prepare("
        UPDATE poado_tg_login_tokens
        SET used_at = NOW(),
            used_ip = :used_ip,
            used_ua = :used_ua
        WHERE id = :id
          AND used_at IS NULL
          AND expires_at > NOW()
    ");
    $consume->execute([
        ':used_ip' => $clientIp,
        ':used_ua' => $clientUa,
        ':id' => $locked['id'],
    ]);

    if ($consume->rowCount() !== 1) {
        $pdo->rollBack();
        tg_login_json([
            'ok' => false,
            'error' => 'TG_LOGIN_FAILED',
            'ts' => tg_login_ts(),
            'next_url' => null,
        ], 409);
    }

    $tgId = trim((string)($locked['tg_id'] ?? ''));
    if ($tgId === '') {
        $pdo->rollBack();
        tg_login_json([
            'ok' => false,
            'error' => 'TG_ID_MISSING',
            'ts' => tg_login_ts(),
            'next_url' => null,
        ], 422);
    }

    tg_find_or_create_identity($pdo, $tgId);

    $user = tg_find_linked_user($pdo, $tgId);
    if (!$user) {
        $user = tg_create_user($pdo, $tgId);
    }

    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        $pdo->rollBack();
        tg_login_json([
            'ok' => false,
            'error' => 'TG_LOGIN_FAILED',
            'ts' => tg_login_ts(),
            'next_url' => null,
        ], 500);
    }

    tg_ensure_identity_link($pdo, $userId, $tgId);

    $reloadUser = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $reloadUser->execute([':id' => $userId]);
    $user = $reloadUser->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $pdo->rollBack();
        tg_login_json([
            'ok' => false,
            'error' => 'TG_LOGIN_FAILED',
            'ts' => tg_login_ts(),
            'next_url' => null,
        ], 500);
    }

    $pdo->commit();

    session_attach_user($user);
    $_SESSION['auth_method'] = 'TG_PIN';

    tg_login_json([
        'ok' => true,
        'message' => 'LOGIN_OK',
        'next_url' => $nextUrl,
        'user_id' => (int)$user['id'],
        'auth_method' => 'TG_PIN',
        'ts' => tg_login_ts(),
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    tg_login_json([
        'ok' => false,
        'error' => 'TG_LOGIN_FAILED',
        'message' => $e->getMessage(),
        'ts' => tg_login_ts(),
        'next_url' => null,
    ], 500);
}