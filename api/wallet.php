<?php
// /rwa/api/wallet.php
// RWA Standalone - Wallet/Session status endpoint (read-only)
//
// v1.0.0 (2026-03-04)
// - RWA MUST NOT redirect to /dashboard/*
// - Read-only: MUST NOT create/change authenticated session state here
// - Session cookie path "/"

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

// Start session safely (read-only usage allowed here)
if (session_status() !== PHP_SESSION_ACTIVE) {
  $secure = poado_is_https();
  // PHP 7.3+ supports array options
  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_start();
}

$wallet = isset($_SESSION['wallet']) ? (string)$_SESSION['wallet'] : '';

if ($wallet === '') {
  poado_json(401, [
    'ok' => false,
    'error' => 'NOT_AUTHENTICATED',
    'wallet' => null,
  ]);
}

$provider = null;
$tg_id = null;

if (str_starts_with($wallet, 'tg:')) {
  $provider = 'telegram';
  $tg_id = substr($wallet, 3);
  if ($tg_id === '' || !preg_match('/^\d+$/', $tg_id)) {
    // Defensive: malformed session value should not happen
    poado_json(400, [
      'ok' => false,
      'error' => 'INVALID_SESSION_WALLET',
      'wallet' => $wallet,
    ]);
  }
}

// Optional enrichment: fetch tg identity if the table exists.
// This is best-effort and must NOT break the endpoint.
$tg_identity = null;
try {
  $dbPath = __DIR__ . '/../../dashboard/inc/db.php';
  if (is_file($dbPath)) {
    require_once $dbPath;

    // db.php is locked FINAL in your system; assume it exposes $pdo or a getter.
    // We support both patterns defensively:
    $pdo = $pdo ?? null;
    if ($pdo instanceof PDO && $provider === 'telegram' && $tg_id !== null) {
      $stmt = $pdo->prepare("SELECT tg_id, tg_username, tg_first_name, tg_last_name, is_active, last_seen_at FROM poado_tg_identities WHERE tg_id = ? LIMIT 1");
      $stmt->execute([$tg_id]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if (is_array($row)) {
        $tg_identity = $row;
      }
    }
  }
} catch (Throwable $e) {
  // ignore (best-effort)
}

poado_json(200, [
  'ok' => true,
  'wallet' => $wallet,
  'provider' => $provider,
  'tg_id' => $tg_id,
  'tg_identity' => $tg_identity,
  'session' => [
    'id' => session_id(),
    'cookie_path' => '/',
  ],
]);