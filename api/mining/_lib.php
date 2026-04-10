<?php
// /rwa/api/mining/_lib.php
// v1.0.20260304-rwa-mining-lib-db-aligned
declare(strict_types=1);

require __DIR__ . '/../../inc/rwa-session.php';
require __DIR__ . '/../../../dashboard/inc/session-user.php';
require __DIR__ . '/../../../dashboard/inc/bootstrap.php';

// LOCK: all future endpoints must include these infra helpers
require __DIR__ . '/../../../dashboard/inc/validators.php';
require __DIR__ . '/../../../dashboard/inc/guards.php';
require __DIR__ . '/../../../dashboard/inc/json.php';
require __DIR__ . '/../../../dashboard/inc/error.php';

db_connect();
$pdo = $GLOBALS['pdo'];

const TICK_SECONDS = 10;
const BASE_RATE_WEMS_PER_TICK = 0.33;

// ledger scaling: wEMS has 8-decimal internal precision for log rows
const WEMS_LOG_SCALE = 100000000; // 1e8

function poado_now_utc_dt(): DateTime {
  return new DateTime('now', new DateTimeZone('UTC'));
}
function poado_day_key_utc(): string {
  return poado_now_utc_dt()->format('Y-m-d'); // DATE
}
function poado_json(array $a): void {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($a, JSON_UNESCAPED_SLASHES);
  exit;
}
function poado_fail(string $code, string $msg, array $extra = []): void {
  poado_json(array_merge([
    'ok' => false,
    'error' => $code,
    'message' => $msg,
    'ts_utc' => poado_now_utc_dt()->format(DateTime::ATOM),
    'day_key_utc' => poado_day_key_utc(),
  ], $extra));
}
function poado_uid(): int {
  $v = $_SESSION['user_id'] ?? $_SESSION['uid'] ?? null;
  $n = (int)$v;
  if ($n <= 0) poado_fail('NO_SESSION', 'Login required (missing user_id).');
  return $n;
}
function poado_wallet(): string {
  $w = $_SESSION['wallet'] ?? $_SESSION['user_wallet'] ?? $_SESSION['poado_wallet'] ?? null;
  $w = $w ? trim((string)$w) : '';
  if ($w === '') poado_fail('NO_SESSION', 'Login required (missing wallet).');
  return $w;
}

function locked_tier_fallback(string $tierCode): array {
  // If miner_tiers does not contain your code, we fall back to locked global tiers.
  // tier_code from DB can be arbitrary; we map conservatively.
  $c = strtolower(trim($tierCode));
  if (str_contains($c, '10000') || str_contains($c, 'sn10000')) return ['tier'=>'super','multiplier'=>30.0,'cap'=>10000,'bc'=>1000,'node'=>3.0];
  if (str_contains($c, '5000')) return ['tier'=>'nodes','multiplier'=>10.0,'cap'=>3000,'bc'=>300,'node'=>0.5];
  if (str_contains($c, '1000')) return ['tier'=>'core','multiplier'=>5.0,'cap'=>1000,'bc'=>100,'node'=>0.0];
  if (str_contains($c, '500') || str_contains($c, 'l500')) return ['tier'=>'sub','multiplier'=>3.0,'cap'=>500,'bc'=>10,'node'=>0.0];
  if (str_contains($c, '100') || str_contains($c, 'm100')) return ['tier'=>'verified','multiplier'=>2.0,'cap'=>300,'bc'=>0,'node'=>0.0];
  return ['tier'=>'free','multiplier'=>1.0,'cap'=>100,'bc'=>0,'node'=>0.0];
}

function resolve_user_tier(PDO $pdo, int $userId, string $wallet): array {
  // 1) wallet mapping
  $tierCode = null;

  $st = $pdo->prepare("SELECT tier_code FROM user_miner_tier_wallet WHERE wallet=? LIMIT 1");
  $st->execute([$wallet]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  if ($r && !empty($r['tier_code'])) $tierCode = (string)$r['tier_code'];

  // 2) fallback by user_id
  if ($tierCode === null) {
    $st = $pdo->prepare("SELECT tier_code FROM user_miner_tier WHERE user_id=? LIMIT 1");
    $st->execute([$userId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r && !empty($r['tier_code'])) $tierCode = (string)$r['tier_code'];
  }

  if ($tierCode === null) $tierCode = 'free';

  // 3) miner_tiers config lookup
  $st = $pdo->prepare("SELECT tier_code, multiplier, daily_cap_wems, adoptee_bind_cap, is_active
                       FROM miner_tiers
                       WHERE tier_code=? LIMIT 1");
  $st->execute([$tierCode]);
  $t = $st->fetch(PDO::FETCH_ASSOC);

  if ($t && (int)($t['is_active'] ?? 1) === 1) {
    $mul = (float)($t['multiplier'] ?? 1.0);
    $cap = (int)($t['daily_cap_wems'] ?? 100);
    $bc  = (int)($t['adoptee_bind_cap'] ?? 0);

    // Node pool info is not in miner_tiers → infer from cap/mul (safe default 0)
    $node = 0.0;
    if ($cap >= 10000 || $mul >= 30) $node = 3.0;
    else if ($cap >= 3000 || $mul >= 10) $node = 0.5;

    // tier label for UI (locked names)
    $label = 'free';
    if ($mul >= 30 || $cap >= 10000) $label = 'super';
    else if ($mul >= 10 || $cap >= 3000) $label = 'nodes';
    else if ($mul >= 5 || $cap >= 1000) $label = 'core';
    else if ($mul >= 3 || $cap >= 500) $label = 'sub';
    else if ($mul >= 2 || $cap >= 300) $label = 'verified';

    return ['tier_code'=>$tierCode,'tier'=>$label,'multiplier'=>$mul,'cap'=>$cap,'bc'=>$bc,'node'=>$node];
  }

  $fb = locked_tier_fallback($tierCode);
  return ['tier_code'=>$tierCode,'tier'=>$fb['tier'],'multiplier'=>$fb['multiplier'],'cap'=>$fb['cap'],'bc'=>$fb['bc'],'node'=>$fb['node']];
}

function ensure_mining_state(PDO $pdo, int $userId): array {
  $today = poado_day_key_utc();
  $now = poado_now_utc_dt()->format('Y-m-d H:i:s');

  $st = $pdo->prepare("SELECT user_id, mining_on, last_tick_utc, earned_today, day_key_utc, updated_at
                       FROM wems_mining_state WHERE user_id=? LIMIT 1");
  $st->execute([$userId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    $ins = $pdo->prepare("INSERT INTO wems_mining_state
      (user_id, mining_on, last_tick_utc, earned_today, day_key_utc, updated_at)
      VALUES (?,0,NULL,0,?,?)");
    $ins->execute([$userId, $today, $now]);

    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
  }

  // daily rollover at UTC 00:00
  if (($row['day_key_utc'] ?? '') !== $today) {
    $upd = $pdo->prepare("UPDATE wems_mining_state
      SET mining_on=0, last_tick_utc=NULL, earned_today=0, day_key_utc=?, updated_at=?
      WHERE user_id=?");
    $upd->execute([$today, $now, $userId]);

    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
  }

  return $row ?: [
    'user_id'=>$userId,'mining_on'=>0,'last_tick_utc'=>null,'earned_today'=>'0','day_key_utc'=>$today,'updated_at'=>$now
  ];
}

function battery_pct(?string $lastTickUtc, bool $isMining): int {
  if (!$isMining) return 0;
  if (!$lastTickUtc) return 0;

  $lt = DateTime::createFromFormat('Y-m-d H:i:s', $lastTickUtc, new DateTimeZone('UTC'));
  if (!$lt) return 0;
  $now = poado_now_utc_dt();
  $elapsed = max(0, $now->getTimestamp() - $lt->getTimestamp());
  $pct = (int)round(min(100, ($elapsed / TICK_SECONDS) * 100));
  return $pct;
}

function mirror_poado_mining_state(PDO $pdo, string $wallet, string $state): void {
  // Optional mirror: RUNNING/STOPPED for wallet-centric flows
  $now = poado_now_utc_dt()->format('Y-m-d H:i:s');
  $st = $pdo->prepare("SELECT wallet FROM poado_mining_state WHERE wallet=? LIMIT 1");
  $st->execute([$wallet]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  if ($r) {
    $pdo->prepare("UPDATE poado_mining_state SET state=?, updated_at=? WHERE wallet=?")
        ->execute([$state, $now, $wallet]);
  } else {
    $pdo->prepare("INSERT INTO poado_mining_state (wallet, state, updated_at) VALUES (?,?,?)")
        ->execute([$wallet, $state, $now]);
  }
}

function log_mining(PDO $pdo, int $userId, float $mintedWems): void {
  if ($mintedWems <= 0) return;
  $scaled = (int)round($mintedWems * WEMS_LOG_SCALE);
  if ($scaled <= 0) return;

  $pdo->prepare("INSERT INTO wems_mining_log (user_id, amount, reason, created_at)
                 VALUES (?,?, 'mining', UTC_TIMESTAMP())")
      ->execute([$userId, $scaled]);
}