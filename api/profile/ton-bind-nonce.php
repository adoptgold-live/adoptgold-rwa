<?php
// /var/www/html/public/rwa/api/profile/ton-bind-nonce.php
// v1.0.20260314-rwa-profile-ton-bind-nonce
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

if (function_exists('json_headers')) {
    json_headers();
} else {
    header('Content-Type: application/json; charset=utf-8');
}

function out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $msg, int $code = 400, array $extra = []): void {
    out(array_merge(['ok' => false, 'error' => $msg], $extra), $code);
}

function ok(array $data = [], int $code = 200): void {
    out(array_merge(['ok' => true], $data), $code);
}

function request_post(string $key, string $default = ''): string {
    if (isset($_POST[$key])) return trim((string)$_POST[$key]);
    $raw = file_get_contents('php://input');
    if ($raw) {
        $js = json_decode($raw, true);
        if (is_array($js) && array_key_exists($key, $js)) {
            return trim((string)$js[$key]);
        }
    }
    return $default;
}

function valid_csrf(string $token): bool {
    if ($token === '') return false;

    if (function_exists('csrf_check')) {
        try {
            return (bool)csrf_check('rwa_profile_save', $token);
        } catch (Throwable $e) {
        }
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    $sess = (string)($_SESSION['csrf_token_rwa_profile'] ?? '');
    return $sess !== '' && hash_equals($sess, $token);
}

function log_api_error_safe(
    PDO $pdo,
    string $message,
    string $errorCode,
    array $context = [],
    int $userId = 0,
    string $wallet = ''
): void {
    try {
        $sql = "INSERT INTO poado_api_errors
            (module, endpoint, error_code, severity, wallet, role, is_admin, booking_uid, deal_uid, level, category, message, context_json, public_hint, ip, user_agent, ref_id, created_at)
            VALUES
            (:module, :endpoint, :error_code, :severity, :wallet, :role, :is_admin, :booking_uid, :deal_uid, :level, :category, :message, :context_json, :public_hint, :ip, :user_agent, :ref_id, NOW())";
        $st = $pdo->prepare($sql);
        $st->execute([
            ':module'       => 'rwa_profile',
            ':endpoint'     => '/rwa/api/profile/ton-bind-nonce.php',
            ':error_code'   => $errorCode,
            ':severity'     => 'error',
            ':wallet'       => $wallet,
            ':role'         => '',
            ':is_admin'     => 0,
            ':booking_uid'  => '',
            ':deal_uid'     => '',
            ':level'        => 'error',
            ':category'     => 'ton_binding',
            ':message'      => $message,
            ':context_json' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':public_hint'  => 'Unable to start TON binding',
            ':ip'           => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            ':user_agent'   => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ':ref_id'       => 'u' . $userId . '-' . date('YmdHis'),
        ]);
    } catch (Throwable $e) {
    }
}

$user = function_exists('session_user') ? session_user() : [];
$userId = (int)($user['id'] ?? 0);
if ($userId <= 0) {
    fail('Login required', 401);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    fail('Method not allowed', 405);
}

$csrfToken = request_post('csrf_token');
if ($method === 'GET') {
    $csrfToken = (string)($_GET['csrf_token'] ?? $csrfToken);
}
if (!valid_csrf($csrfToken)) {
    fail('Invalid CSRF token', 419);
}

$pdo = null;
if (function_exists('rwa_db')) {
    $pdo = rwa_db();
} elseif (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
    $pdo = $GLOBALS['pdo'];
}
if (!$pdo instanceof PDO) {
    fail('DB not ready', 500);
}

$domain = (string)parse_url((string)($_ENV['APP_URL'] ?? 'https://adoptgold.app'), PHP_URL_HOST);
if ($domain === '') {
    $domain = (string)($_SERVER['HTTP_HOST'] ?? 'adoptgold.app');
}
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
$origin = $scheme . '://' . $domain;

$nonce = bin2hex(random_bytes(16));
$payload = 'bind_ton|' . $userId . '|' . $nonce . '|' . time();
$issuedAtTs = time();
$expiresAtTs = $issuedAtTs + 300; // 5 minutes

try {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    $_SESSION['rwa_ton_bind'] = [
        'user_id'     => $userId,
        'nonce'       => $nonce,
        'payload'     => $payload,
        'issued_at'   => $issuedAtTs,
        'expires_at'  => $expiresAtTs,
        'used'        => 0,
        'purpose'     => 'bind_ton',
        'ip'          => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'user_agent'  => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ];

    $tonconnectManifest = (string)($_ENV['TONCONNECT_MANIFEST_URL'] ?? '');
    if ($tonconnectManifest === '') {
        $tonconnectManifest = $origin . '/tonconnect-manifest.json';
    }

    ok([
        'msg' => 'TON bind nonce ready',
        'purpose' => 'bind_ton',
        'nonce' => $nonce,
        'payload' => $payload,
        'issued_at' => gmdate('c', $issuedAtTs),
        'expires_at' => gmdate('c', $expiresAtTs),
        'expires_in' => max(0, $expiresAtTs - time()),
        'domain' => $domain,
        'origin' => $origin,
        'tonconnect_manifest_url' => $tonconnectManifest,
        'session_user_id' => $userId,
        'already_bound' => trim((string)($user['wallet_address'] ?? '')) !== '',
    ]);
} catch (Throwable $e) {
    log_api_error_safe(
        $pdo,
        $e->getMessage(),
        'RWA_TON_BIND_NONCE_FAIL',
        ['user_id' => $userId],
        $userId,
        trim((string)($user['wallet_address'] ?? ''))
    );
    fail('Unable to prepare TON bind request', 500);
}