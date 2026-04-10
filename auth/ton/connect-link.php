<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/auth/ton/connect-link.php
 * Optional parity helper with dashboard connect-link.php
 */

require __DIR__ . '/../../../dashboard/inc/bootstrap.php';
require __DIR__ . '/../../../dashboard/inc/json.php';
require __DIR__ . '/../../../dashboard/inc/qr.php';

@ini_set('display_errors','0');
@ini_set('display_startup_errors','0');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

db_connect();
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) json_fail('DB unavailable', 500);

function env_first(array $keys): string {
    foreach ($keys as $k) {
        $v = '';
        try { $v = trim((string)poado_env($k)); } catch (Throwable $e) { $v = ''; }
        if ($v !== '') return $v;
    }
    return '';
}

$template = env_first(['TONCONNECT_LINK_TEMPLATE','TONCONNECT_DEEPLINK_TEMPLATE']);

$payload = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
$expiresSec = 180;
$expiresAt = date('Y-m-d H:i:s', time() + $expiresSec);

$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

$st = $pdo->prepare("
  INSERT INTO poado_ton_nonces (payload, purpose, issued_ip, issued_ua, issued_at, expires_at)
  VALUES (:p, 'login', :ip, :ua, NOW(), :exp)
");
$st->execute([':p'=>$payload, ':ip'=>$ip, ':ua'=>$ua, ':exp'=>$expiresAt]);

if ($template === '' || strpos($template, '{payload}') === false) {
    json_fail('TonConnect template missing. Set TONCONNECT_LINK_TEMPLATE with {payload}.', 400);
}

$tc = str_replace('{payload}', rawurlencode($payload), $template);

try {
    $qr = poado_qr_svg_data_uri($tc, 320, 10);
} catch (Throwable $e) {
    json_fail('QR generation failed', 500);
}

json_ok([
    'payload'    => $payload,
    'expires_in' => $expiresSec,
    'tc'         => $tc,
    'qr_data_uri'=> $qr
]);