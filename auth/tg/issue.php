<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';

if (!function_exists('tg_issue_json')) {
    function tg_issue_json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('tg_issue_now_ts')) {
    function tg_issue_now_ts(): int
    {
        return time();
    }
}

if (!function_exists('tg_issue_pdo')) {
    function tg_issue_pdo(): PDO
    {
        global $pdo, $db, $conn;

        if (isset($pdo) && $pdo instanceof PDO) {
            return $pdo;
        }

        if (isset($db) && $db instanceof PDO) {
            return $db;
        }

        if (isset($conn) && $conn instanceof PDO) {
            return $conn;
        }

        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            return $GLOBALS['pdo'];
        }

        if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) {
            return $GLOBALS['db'];
        }

        if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof PDO) {
            return $GLOBALS['conn'];
        }

        if (function_exists('db')) {
            $x = db();
            if ($x instanceof PDO) {
                return $x;
            }
        }

        if (function_exists('pdo')) {
            $x = pdo();
            if ($x instanceof PDO) {
                return $x;
            }
        }

        if (function_exists('get_pdo')) {
            $x = get_pdo();
            if ($x instanceof PDO) {
                return $x;
            }
        }

        if (function_exists('get_db')) {
            $x = get_db();
            if ($x instanceof PDO) {
                return $x;
            }
        }

        throw new RuntimeException('PDO_NOT_READY');
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        tg_issue_json([
            'ok' => false,
            'error' => 'METHOD_NOT_ALLOWED',
            'ts' => tg_issue_now_ts(),
            'next_url' => null,
        ], 405);
    }

    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $tgId = trim((string)($data['tg_id'] ?? ''));
    if ($tgId === '' || !preg_match('/^\d{5,20}$/', $tgId)) {
        tg_issue_json([
            'ok' => false,
            'error' => 'TG_ID_MISSING',
            'ts' => tg_issue_now_ts(),
            'next_url' => null,
        ], 422);
    }

    $headerSecret = (string)($_SERVER['HTTP_X_TG_BOT_SECRET'] ?? '');
    $envSecret = (string)(
        $_ENV['TG_BOT_SECRET']
        ?? getenv('TG_BOT_SECRET')
        ?? ''
    );

    if ($envSecret !== '') {
        if ($headerSecret === '' || !hash_equals($envSecret, $headerSecret)) {
            tg_issue_json([
                'ok' => false,
                'error' => 'FORBIDDEN',
                'ts' => tg_issue_now_ts(),
                'next_url' => null,
            ], 403);
        }
    }

    $pdo = tg_issue_pdo();

    $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $pinHash = password_hash($pin, PASSWORD_BCRYPT);
    if ($pinHash === false) {
        throw new RuntimeException('PIN_HASH_FAILED');
    }

    $purpose = 'login';
    $issuedIp = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    $issuedUa = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $expiresIn = 180;

    $issuedAt = date('Y-m-d H:i:s');
    $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);

    $stmt = $pdo->prepare("
        INSERT INTO poado_tg_login_tokens
        (
            tg_id,
            token,
            purpose,
            issued_ip,
            issued_ua,
            issued_at,
            expires_at,
            used_at,
            used_ip,
            used_ua
        ) VALUES (
            :tg_id,
            :token,
            :purpose,
            :issued_ip,
            :issued_ua,
            :issued_at,
            :expires_at,
            NULL,
            NULL,
            NULL
        )
    ");

    $stmt->execute([
        ':tg_id' => $tgId,
        ':token' => $pinHash,
        ':purpose' => $purpose,
        ':issued_ip' => $issuedIp,
        ':issued_ua' => $issuedUa,
        ':issued_at' => $issuedAt,
        ':expires_at' => $expiresAt,
    ]);

    tg_issue_json([
        'ok' => true,
        'token' => $pin,
        'pin' => $pin,
        'expires_in' => $expiresIn,
        'ts' => tg_issue_now_ts(),
        'next_url' => null,
    ]);
} catch (Throwable $e) {
    tg_issue_json([
        'ok' => false,
        'error' => 'TG_PIN_ISSUE_FAILED',
        'message' => $e->getMessage(),
        'ts' => tg_issue_now_ts(),
        'next_url' => null,
    ], 500);
}