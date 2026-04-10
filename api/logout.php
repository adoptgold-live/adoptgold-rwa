<?php
// /rwa/api/logout.php
// RWA Standalone - Logout endpoint
//
// v1.0.0 (2026-03-04)
// - MUST NOT redirect to /dashboard/*
// - Clears session and cookie with path "/"

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function poado_json(int $status, array $payload): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

function poado_is_https(): bool {
  if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
  if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') return true;
  return false;
}

// Start session (allowed: this endpoint changes auth state by clearing it)
if (session_status() !== PHP_SESSION_ACTIVE) {
  $secure = poado_is_https();
  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_start();
}

$was = isset($_SESSION['wallet']) ? (string)$_SESSION['wallet'] : null;

// Clear session data
$_SESSION = [];
if (isset($_COOKIE[session_name()])) {
  $secure = poado_is_https();
  setcookie(session_name(), '', [
    'expires' => time() - 3600,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
}

try {
  session_regenerate_id(true);
} catch (Throwable $e) {
  // ignore
}

try {
  session_destroy();
} catch (Throwable $e) {
  // ignore
}

poado_json(200, [
  'ok' => true,
  'logged_out' => true,
  'previous_wallet' => $was,
]);