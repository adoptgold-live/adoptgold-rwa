<?php
// /rwa/auth/ton/api/reset.php
// RWA Standalone: Reset TON session (server-side) + clear wallet session
// v1.0.20260304
//
// Locks:
// - Use bootstrap + csrf_check() pattern only
// - No redirects to /dashboard/*
// - JSON response only

declare(strict_types=1);

require_once __DIR__ . '/../../../../dashboard/inc/bootstrap.php';
require_once __DIR__ . '/../../../../dashboard/inc/error.php';

header('Content-Type: application/json; charset=utf-8');

function out(array $j, int $code = 200): void {
  http_response_code($code);
  echo json_encode($j, JSON_UNESCAPED_SLASHES);
  exit;
}

// Read JSON body
$raw = file_get_contents('php://input');
$body = [];
if (is_string($raw) && $raw !== '') {
  $tmp = json_decode($raw, true);
  if (is_array($tmp)) $body = $tmp;
}
$token = isset($body['csrf']) ? (string)$body['csrf'] : '';

// Locked CSRF validation pattern
$csrf_ok = true;
try {
  $r = csrf_check('ton_reset', $token);
  if ($r === false) $csrf_ok = false;
} catch (Throwable $e) {
  $csrf_ok = false;
}

if (!$csrf_ok) {
  // Use global API error logger
  try {
    poado_error('rwa', 'csrf_failed', 'rwa/auth/ton/api/reset.php', 'Invalid security token. Please refresh and try again.');
  } catch (Throwable $e) {}
  out(['ok' => false, 'error' => 'csrf_failed'], 400);
}

// Clear known session keys (safe even if not set)
try {
  if (isset($_SESSION) && is_array($_SESSION)) {
    unset(
      $_SESSION['wallet'],
      $_SESSION['wallet_address'],
      $_SESSION['role'],
      $_SESSION['user_id'],
      $_SESSION['is_admin'],
      $_SESSION['is_senior'],
      $_SESSION['login_method'],
      $_SESSION['tg_id'],
      $_SESSION['ton_addr'],
      $_SESSION['ton_wallet']
    );
  }
} catch (Throwable $e) {}

// Full session reset (best-effort)
try {
  if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = [];

    $params = session_get_cookie_params();
    $cookieName = session_name();

    // Enforce path "/" for project-wide session cookie scope
    setcookie($cookieName, '', [
      'expires'  => time() - 3600,
      'path'     => '/',
      'domain'   => $params['domain'] ?? '',
      'secure'   => (bool)($params['secure'] ?? true),
      'httponly' => (bool)($params['httponly'] ?? true),
      'samesite' => $params['samesite'] ?? 'Lax',
    ]);

    @session_destroy();
    @session_write_close();
  }
} catch (Throwable $e) {}

// Start a fresh session id (optional but helps prevent “sticky” state)
try {
  @session_start();
  @session_regenerate_id(true);
} catch (Throwable $e) {}

out(['ok' => true]);
