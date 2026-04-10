<?php
// /var/www/html/public/rwa/api/me.php
// RWA Standalone "me" endpoint (NO /dashboard redirect EVER)
// v1.0.20260304

declare(strict_types=1);

// ---- LOCKED include pattern (do not change) ----
$DASH_ROOT = realpath(__DIR__ . '/../../dashboard');
if ($DASH_ROOT === false) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'error'=>'dashboard root not found'], JSON_UNESCAPED_SLASHES);
  exit;
}
require $DASH_ROOT . '/inc/bootstrap.php';
require $DASH_ROOT . '/inc/session-user.php';
// ----------------------------------------------

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function jexit(array $out, int $code = 200): never {
  http_response_code($code);
  echo json_encode($out, JSON_UNESCAPED_SLASHES);
  exit;
}

$wallet = '';
if (function_exists('get_wallet_session')) {
  $sess = get_wallet_session();
  if (is_array($sess)) {
    $wallet = (string)($sess['wallet'] ?? $sess['wallet_address'] ?? '');
  }
}
$wallet = trim($wallet);

if ($wallet === '') {
  jexit([
    'ok' => false,
    'error' => 'No session',
    'wallet' => '',
  ], 401);
}

// DB handle (bootstrap sets db_connect + $GLOBALS['pdo'] in your stack)
if (function_exists('db_connect')) {
  db_connect();
}
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
  jexit([
    'ok' => false,
    'error' => 'DB not available',
  ], 500);
}

$mode = 'unknown';
if (str_starts_with($wallet, 'tg:')) $mode = 'telegram';
elseif (preg_match('/^(UQ|EQ)[A-Za-z0-9_\-]{20,}$/', $wallet)) $mode = 'ton';

$user = null;
$userId = null;

// Resolve user_id
try {
  if ($mode === 'telegram') {
    $tgId = substr($wallet, 3);
    $tgId = trim($tgId);

    // identity_type is varchar(8) in your DB; use "telegram" exactly per your locked model
    $st = $pdo->prepare("SELECT user_id FROM poado_identity_links WHERE identity_type='telegram' AND identity_key=? AND is_active=1 LIMIT 1");
    $st->execute([$tgId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row && isset($row['user_id'])) {
      $userId = (int)$row['user_id'];
    }
  } else {
    // TON (or other) uses users.wallet as the primary identity string
    $st = $pdo->prepare("SELECT id FROM users WHERE wallet=? LIMIT 1");
    $st->execute([$wallet]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row && isset($row['id'])) {
      $userId = (int)$row['id'];
    }
  }

  if ($userId !== null && $userId > 0) {
    $st = $pdo->prepare("SELECT id, wallet, nickname, email, mobile_e164, role, is_active, is_fully_verified, is_senior, created_at, updated_at FROM users WHERE id=? LIMIT 1");
    $st->execute([$userId]);
    $user = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
} catch (Throwable $e) {
  jexit([
    'ok' => false,
    'error' => 'Resolve user failed',
  ], 500);
}

// Cert visibility locks (show only these)
$SHOW_GENESIS = ['GREEN', 'GOLD'];
$SHOW_SECONDARY = ['RLIFE', 'RPROP', 'RTRIP'];

$certs = [
  'total' => 0,
  'visible_total' => 0,
  'by_type' => [],
  'visible_by_type' => [],
  'visible_rules' => [
    'genesis_show' => $SHOW_GENESIS,
    'secondary_show' => $SHOW_SECONDARY,
  ],
];

if ($userId !== null && $userId > 0) {
  try {
    // Total certs (all types)
    $st = $pdo->prepare("SELECT COUNT(*) AS c FROM poado_rwa_certs WHERE owner_user_id=?");
    $st->execute([$userId]);
    $certs['total'] = (int)($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

    // Breakdown
    $st = $pdo->prepare("SELECT rwa_type, COUNT(*) AS c FROM poado_rwa_certs WHERE owner_user_id=? GROUP BY rwa_type");
    $st->execute([$userId]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $t = strtoupper((string)($r['rwa_type'] ?? ''));
      $c = (int)($r['c'] ?? 0);
      if ($t !== '') $certs['by_type'][$t] = $c;

      // Visible filter
      if (in_array($t, $SHOW_GENESIS, true) || in_array($t, $SHOW_SECONDARY, true)) {
        $certs['visible_by_type'][$t] = $c;
        $certs['visible_total'] += $c;
      }
    }
  } catch (Throwable $e) {
    // keep certs empty but still return session/user
  }
}

jexit([
  'ok' => true,
  'mode' => $mode,
  'wallet' => $wallet,
  'user_id' => $userId,
  'user' => $user,
  'certs' => $certs,
], 200);