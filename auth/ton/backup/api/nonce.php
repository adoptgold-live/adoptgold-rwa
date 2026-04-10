<?php
// /var/www/html/public/rwa/auth/ton/api/nonce.php
// RWA Standalone TON login: nonce (ton_proof payload)
// v1.0.20260304
declare(strict_types=1);

// If you have a RWA bootstrap, use it; otherwise keep this minimal and safe.
// IMPORTANT: This endpoint MUST NOT set $_SESSION['wallet'].
$bootstrap = __DIR__ . '/../../../inc/bootstrap.php';
if (is_file($bootstrap)) {
  require $bootstrap;
} else {
  // Minimal safe session start (cookie path "/" is locked globally)
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function json_out(array $a, int $code = 200): never {
  http_response_code($code);
  echo json_encode($a, JSON_UNESCAPED_SLASHES);
  exit;
}

try {
  // 24 bytes => ~32 chars base64url (compact, safe)
  $payload = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
} catch (Throwable $e) {
  json_out(['ok' => false, 'error' => 'nonce_gen_failed'], 500);
}

$expiresSec = 180;
$now = time();

// Store ONLY nonce metadata in session (wallet must be set only in verify.php)
$_SESSION['rwa_ton_nonce'] = $payload;
$_SESSION['rwa_ton_nonce_exp'] = $now + $expiresSec;
$_SESSION['rwa_ton_nonce_issued_at'] = $now;

// Optional trace fields (safe)
$_SESSION['rwa_ton_nonce_ip'] = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
$_SESSION['rwa_ton_nonce_ua'] = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

json_out([
  'ok' => true,
  'payload' => $payload,
  'expires_in' => $expiresSec,
]);