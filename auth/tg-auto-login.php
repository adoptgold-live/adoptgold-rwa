<?php
declare(strict_types=1);

require $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/bootstrap.php';
require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/session-user.php';

if (function_exists('load_env')) {
    load_env('/var/www/secure/.env');
}

$secret = (string) getenv('TG_BOT_SECRET');

function tg_b64url_decode(string $data): string|false {
    $remainder = strlen($data) % 4;
    if ($remainder) $data .= str_repeat('=', 4 - $remainder);
    return base64_decode(strtr($data, '-_', '+/'));
}
function tg_hmac_hex(string $data, string $secret): string {
    return hash_hmac('sha256', $data, $secret);
}
function tg_fail(int $code, string $msg): never {
    http_response_code($code);
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>Telegram Auto Login</title>';
    echo '<style>body{margin:0;background:#07090d;color:#d7ffe0;font-family:ui-monospace,monospace;padding:24px}.card{max-width:760px;margin:40px auto;padding:20px;border:1px solid rgba(80,255,120,.25);border-radius:16px;background:rgba(255,255,255,.03)}a{color:#8fffb0;text-decoration:none}</style>';
    echo '</head><body><div class="card"><h2>Telegram Auto Login</h2><p>'.htmlspecialchars($msg, ENT_QUOTES, 'UTF-8').'</p><p><a href="/rwa/tg-login.php">Go to TG Login / 前往 TG 登录</a></p></div></body></html>';
    exit;
}

if ($secret === '') tg_fail(500, 'Server secret missing / 服务器密钥缺失');

$t = trim((string)($_GET['t'] ?? ''));
if ($t === '') tg_fail(400, 'Missing token / 缺少令牌');

$parts = explode('.', $t, 2);
if (count($parts) !== 2) tg_fail(400, 'Invalid token format / 令牌格式无效');

[$payloadB64, $sig] = $parts;

if (!hash_equals(tg_hmac_hex($payloadB64, $secret), $sig)) {
    tg_fail(403, 'Invalid signature / 签名无效');
}

$payloadJson = tg_b64url_decode($payloadB64);
$payload = json_decode((string)$payloadJson, true);

if (!is_array($payload)) tg_fail(400, 'Bad payload / 载荷错误');

$purpose = (string)($payload['purpose'] ?? '');
$tgId    = trim((string)($payload['tg_id'] ?? ''));
$ts      = (int)($payload['ts'] ?? 0);
$ttl     = (int)($payload['ttl'] ?? 120);
$nonce   = trim((string)($payload['nonce'] ?? ''));

if ($purpose !== 'tg_auto_login') tg_fail(400, 'Invalid purpose / 用途无效');
if ($tgId === '' || $ts <= 0 || $ttl <= 0 || $nonce === '') tg_fail(400, 'Missing token fields / 令牌字段缺失');
if (time() > ($ts + $ttl)) tg_fail(410, 'Token expired / 令牌已过期');

$db = $GLOBALS['pdo'] ?? null;
if (!$db instanceof PDO) tg_fail(500, 'Database unavailable / 数据库不可用');

$stmt = $db->prepare("
    SELECT wallet
    FROM poado_identity_links
    WHERE identity_type = 'tg'
      AND identity_key = :tg
      AND is_active = 1
    ORDER BY id DESC
    LIMIT 1
");
$stmt->execute([':tg' => $tgId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || empty($row['wallet'])) {
    tg_fail(403, 'Telegram is not bound to wallet / Telegram 未绑定钱包');
}

$wallet = trim((string)$row['wallet']);
if ($wallet === '') tg_fail(403, 'Linked wallet empty / 绑定钱包为空');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$_SESSION['wallet'] = $wallet;
$_SESSION['wallet_address'] = $wallet;
$_SESSION['login_method'] = 'telegram_auto_login';
$_SESSION['tg_id'] = $tgId;
$_SESSION['is_logged_in'] = 1;
$_SESSION['logged_in_at'] = time();

header('Location: /rwa/login-select.php', true, 302);
exit;
