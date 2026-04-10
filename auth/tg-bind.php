<?php
declare(strict_types=1);

require $_SERVER['DOCUMENT_ROOT'].'/dashboard/inc/bootstrap.php';
require $_SERVER['DOCUMENT_ROOT'].'/dashboard/inc/session-user.php';
require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-session.php';

if (function_exists('load_env')) {
    load_env('/var/www/secure/.env');
}

$secret = (string) getenv('TG_BOT_SECRET');

function b64url_decode_bind(string $data): string|false {
    $remainder = strlen($data) % 4;
    if ($remainder) $data .= str_repeat('=', 4 - $remainder);
    return base64_decode(strtr($data, '-_', '+/'));
}
function hmac_hex_bind(string $data, string $secret): string {
    return hash_hmac('sha256', $data, $secret);
}
function bind_fail(string $msg): never {
    http_response_code(400);
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TG Bind</title></head><body style="background:#07090d;color:#d7ffe0;font-family:monospace;padding:24px">';
    echo '<h2>Telegram Bind</h2><p>'.htmlspecialchars($msg, ENT_QUOTES, 'UTF-8').'</p><p><a href="/rwa/" style="color:#8fffb0">Back / 返回</a></p></body></html>';
    exit;
}

$t = trim((string)($_GET['t'] ?? ''));
if ($t === '') bind_fail('Missing token / 缺少令牌');

$parts = explode('.', $t, 2);
if (count($parts) !== 2) bind_fail('Invalid token / 令牌无效');

[$payloadB64, $sig] = $parts;

if (!hash_equals(hmac_hex_bind($payloadB64, $secret), $sig)) {
    bind_fail('Invalid signature / 签名无效');
}

$payload = json_decode((string)b64url_decode_bind($payloadB64), true);
if (!is_array($payload)) bind_fail('Invalid payload / 载荷无效');

$tgId = trim((string)($payload['tg_id'] ?? ''));
$ts   = (int)($payload['ts'] ?? 0);
$ttl  = (int)($payload['ttl'] ?? 300);

if ($tgId === '' || $ts <= 0 || $ttl <= 0) bind_fail('Invalid fields / 字段无效');
if (time() > ($ts + $ttl)) bind_fail('Token expired / 令牌过期');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$wallet = trim((string)($_SESSION['wallet'] ?? $_SESSION['wallet_address'] ?? ''));
if ($wallet === '') bind_fail('Please login wallet first / 请先登录钱包');

$db = $GLOBALS['pdo'] ?? null;
if (!$db instanceof PDO) bind_fail('Database unavailable / 数据库不可用');

/*
Expected canonical table:
poado_identity_links
identity_type, identity_key, wallet, is_active, created_at, updated_at
*/
$sql = "
    INSERT INTO poado_identity_links
    (identity_type, identity_key, wallet, is_active, created_at, updated_at)
    VALUES
    ('tg', :tg, :wallet, 1, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
    wallet = VALUES(wallet),
    is_active = 1,
    updated_at = NOW()
";
$stmt = $db->prepare($sql);
$stmt->execute([
    ':tg' => $tgId,
    ':wallet' => $wallet,
]);

echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TG Bind Success</title></head><body style="background:#07090d;color:#d7ffe0;font-family:monospace;padding:24px">';
echo '<h2>Telegram successfully bound / Telegram 绑定成功</h2>';
echo '<p>TG ID: '.htmlspecialchars($tgId, ENT_QUOTES, 'UTF-8').'</p>';
echo '<p>Wallet: '.htmlspecialchars($wallet, ENT_QUOTES, 'UTF-8').'</p>';
echo '<p><a href="/rwa/login-select.php" style="color:#8fffb0">Continue / 继续</a></p>';
echo '</body></html>';