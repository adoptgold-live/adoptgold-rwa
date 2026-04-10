<?php
// /var/www/html/public/rwa/auth/ton/api/verify.php
// RWA Standalone TON login verify endpoint
// v1.0.20260304
//
// LOCKS:
// - Session wallet must be created ONLY here (verify endpoint)
// - RWA must NEVER redirect to /dashboard/*
// - DB is wems_db, use existing tables only (users, poado_identity_links)
// - cookie path "/"

declare(strict_types=1);

require __DIR__ . '/../../../../dashboard/inc/bootstrap.php';
require __DIR__ . '/../../../../dashboard/inc/error.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function out(array $j, int $code = 200): never {
  http_response_code($code);
  echo json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

// Read JSON
$raw = file_get_contents('php://input');
$in = json_decode($raw ?: '[]', true);
if (!is_array($in)) $in = [];

$ton_addr = $in['ton_address'] ?? '';
if (!is_string($ton_addr)) $ton_addr = '';
$ton_addr = trim($ton_addr);

$payload = $in['payload'] ?? '';
if (!is_string($payload)) $payload = '';
$payload = trim($payload);

// Basic input validation
if ($ton_addr === '' || strlen($ton_addr) > 128) {
  out(['ok'=>false,'error'=>'INVALID_TON_ADDRESS'], 400);
}
if ($payload === '' || strlen($payload) > 256) {
  out(['ok'=>false,'error'=>'INVALID_PAYLOAD'], 400);
}

/**
 * Nonce/payload check (session-based)
 * NOTE: This does NOT cryptographically verify ton_proof signature.
 * It matches your current dashboard success pattern, but adds replay protection via nonce.
 * If you later want full ton_proof verification, we extend here without DB changes.
 */
$exp = isset($_SESSION['rwa_ton_nonce_exp']) ? (int)$_SESSION['rwa_ton_nonce_exp'] : 0;
$nonce = isset($_SESSION['rwa_ton_nonce']) ? (string)$_SESSION['rwa_ton_nonce'] : '';

if ($nonce === '' || $exp <= 0 || time() > $exp) {
  out(['ok'=>false,'error'=>'NONCE_EXPIRED'], 400);
}
if (!hash_equals($nonce, $payload)) {
  out(['ok'=>false,'error'=>'NONCE_MISMATCH'], 400);
}

// Consume nonce (one-time)
unset($_SESSION['rwa_ton_nonce'], $_SESSION['rwa_ton_nonce_exp'], $_SESSION['rwa_ton_nonce_issued_at']);

// DB handle
if (!isset($pdo) || !($pdo instanceof PDO)) {
  out(['ok'=>false,'error'=>'DB_NOT_READY'], 500);
}

try {
  $pdo->beginTransaction();

  // 1) Resolve identity link: identity_type='ton', identity_key=<ton_addr>
  $st = $pdo->prepare("
    SELECT user_id
    FROM poado_identity_links
    WHERE identity_type='ton'
      AND identity_key=?
      AND is_active=1
    LIMIT 1
  ");
  $st->execute([$ton_addr]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  $user_id = 0;
  if ($row && isset($row['user_id'])) {
    $user_id = (int)$row['user_id'];
  }

  // 2) If no link, create/find user by wallet="ton:<addr>"
  $wallet_key = 'ton:' . $ton_addr;

  if ($user_id <= 0) {
    // Try existing user by wallet
    $st2 = $pdo->prepare("SELECT id FROM users WHERE wallet=? LIMIT 1");
    $st2->execute([$wallet_key]);
    $u = $st2->fetch(PDO::FETCH_ASSOC);

    if ($u && isset($u['id'])) {
      $user_id = (int)$u['id'];
    } else {
      // Create a minimal user row (silent signup)
      $st3 = $pdo->prepare("
        INSERT INTO users (wallet, is_registered, role, is_active, created_at, updated_at)
        VALUES (?, 0, 'rwa', 1, CURRENT_TIMESTAMP(), CURRENT_TIMESTAMP())
      ");
      $st3->execute([$wallet_key]);
      $user_id = (int)$pdo->lastInsertId();
    }

    // Create identity link
    $st4 = $pdo->prepare("
      INSERT INTO poado_identity_links
        (user_id, identity_type, identity_key, is_primary, is_active, created_at, updated_at)
      VALUES
        (?, 'ton', ?, 1, 1, CURRENT_TIMESTAMP(), CURRENT_TIMESTAMP())
      ON DUPLICATE KEY UPDATE
        user_id=VALUES(user_id),
        is_active=1,
        updated_at=CURRENT_TIMESTAMP()
    ");
    $st4->execute([$user_id, $ton_addr]);
  }

  $pdo->commit();

} catch (Throwable $e) {
  try { $pdo->rollBack(); } catch (Throwable $e2) {}
  try { poado_error('rwa', 'ton_verify_db', 'rwa/auth/ton/api/verify.php', 'DB error'); } catch (Throwable $e3) {}
  out(['ok'=>false,'error'=>'DB_ERROR'], 500);
}

// 3) Create session wallet ONLY here (locked rule)
$_SESSION['wallet'] = 'ton:' . $ton_addr;
$_SESSION['auth_method'] = 'ton';
$_SESSION['wallet_chain'] = 'ton';
$_SESSION['wallet_provider'] = 'ton';
$_SESSION['ton_address'] = $ton_addr;
$_SESSION['user_id'] = $user_id;
if (!isset($_SESSION['login_ts'])) $_SESSION['login_ts'] = time();

// RWA-only next route
$next = '/rwa/health/index.php';

out([
  'ok' => true,
  'wallet' => $_SESSION['wallet'],
  'user_id' => $user_id,
  'next' => $next,
]);
