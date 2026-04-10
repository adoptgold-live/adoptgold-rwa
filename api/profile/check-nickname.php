<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

function resp(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pick_lang(): string
{
    $lang = strtolower((string)($_GET['lang'] ?? $_POST['lang'] ?? ''));
    if (in_array($lang, ['zh', 'zh-cn', 'cn', 'sc'], true)) return 'zh';

    $header = strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    if (strpos($header, 'zh') !== false) return 'zh';

    return 'en';
}

function msg(string $key, string $lang): string
{
    $m = [
        'en' => [
            'login_required' => 'Login required.',
            'nickname_required' => 'Nickname is required.',
            'nickname_too_long' => 'Nickname too long.',
            'nickname_bad_chars' => 'Nickname contains unsupported characters.',
            'nickname_available' => 'Nickname available.',
            'nickname_used' => 'Nickname already used. Please choose another.',
            'nickname_check_failed' => 'Nickname check failed.',
            'db_unavailable' => 'Database unavailable.',
        ],
        'zh' => [
            'login_required' => '请先登录。',
            'nickname_required' => '请输入昵称。',
            'nickname_too_long' => '昵称太长。',
            'nickname_bad_chars' => '昵称包含不支持的字符。',
            'nickname_available' => '昵称可用。',
            'nickname_used' => '昵称已被使用，请更换。',
            'nickname_check_failed' => '昵称检查失败。',
            'db_unavailable' => '数据库不可用。',
        ],
    ];
    return $m[$lang][$key] ?? $m['en'][$key] ?? $key;
}

function rwa_pdo(): ?PDO
{
    global $pdo, $db, $conn;

    if (isset($pdo) && $pdo instanceof PDO) return $pdo;
    if (isset($db) && $db instanceof PDO) return $db;
    if (isset($conn) && $conn instanceof PDO) return $conn;

    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
    if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) return $GLOBALS['db'];
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof PDO) return $GLOBALS['conn'];

    foreach (['db','pdo','get_pdo','get_db','db_pdo','bootstrap_db','bootstrap_pdo'] as $fn) {
        if (function_exists($fn)) {
            try {
                $obj = $fn();
                if ($obj instanceof PDO) return $obj;
            } catch (Throwable $e) {}
        }
    }

    return null;
}

try {
    $lang = pick_lang();

    if (!function_exists('session_user_id') || (int)session_user_id() <= 0) {
        resp([
            'ok' => false,
            'available' => false,
            'lang' => $lang,
            'message_key' => 'login_required',
            'message' => msg('login_required', $lang),
        ], 401);
    }

    $nickname = trim((string)($_GET['nickname'] ?? ''));
    $userId = (int)session_user_id();

    if ($nickname === '') {
        resp([
            'ok' => true,
            'available' => false,
            'lang' => $lang,
            'message_key' => 'nickname_required',
            'message' => msg('nickname_required', $lang),
        ]);
    }

    if (mb_strlen($nickname) > 80) {
        resp([
            'ok' => true,
            'available' => false,
            'lang' => $lang,
            'message_key' => 'nickname_too_long',
            'message' => msg('nickname_too_long', $lang),
        ]);
    }

    if (!preg_match('/^[A-Za-z0-9_\-\.@#\x{4e00}-\x{9fff}\s]+$/u', $nickname)) {
        resp([
            'ok' => true,
            'available' => false,
            'lang' => $lang,
            'message_key' => 'nickname_bad_chars',
            'message' => msg('nickname_bad_chars', $lang),
        ]);
    }

    $pdo = rwa_pdo();
    if (!$pdo) {
        throw new RuntimeException(msg('db_unavailable', $lang));
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM users
        WHERE nickname = :nickname
          AND id != :uid
    ");
    $stmt->execute([
        ':nickname' => $nickname,
        ':uid' => $userId,
    ]);

    $count = (int)$stmt->fetchColumn();
    $available = ($count === 0);

    resp([
        'ok' => true,
        'available' => $available,
        'lang' => $lang,
        'message_key' => $available ? 'nickname_available' : 'nickname_used',
        'message' => $available ? msg('nickname_available', $lang) : msg('nickname_used', $lang),
        'nickname' => $nickname,
        'count' => $count,
    ]);

} catch (Throwable $e) {
    $lang = isset($lang) ? $lang : pick_lang();

    resp([
        'ok' => false,
        'available' => false,
        'lang' => $lang,
        'message_key' => 'nickname_check_failed',
        'message' => msg('nickname_check_failed', $lang),
        'error' => $e->getMessage(),
    ], 500);
}