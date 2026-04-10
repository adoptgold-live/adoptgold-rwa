<?php
// /rwa/api/mining/activate.php
// v1.0.20260304-rwa-mining-activate
declare(strict_types=1);

require __DIR__ . '/_lib.php';

$userId = poado_uid();
$wallet = poado_wallet();

$tier_code = trim((string)($_POST['tier_code'] ?? ''));
$tx_hash   = trim((string)($_POST['tx_hash'] ?? ''));

if ($tier_code === '') poado_fail('BAD_REQUEST','tier_code required');
if ($tx_hash === '' || strlen($tx_hash) < 10) poado_fail('BAD_REQUEST','tx_hash required');

try {
  // must exist + active
  $st = $pdo->prepare("SELECT tier_code, multiplier, daily_cap_wems, adoptee_bind_cap, is_active
                       FROM miner_tiers WHERE tier_code=? LIMIT 1");
  $st->execute([$tier_code]);
  $t = $st->fetch(PDO::FETCH_ASSOC);
  if (!$t || (int)($t['is_active'] ?? 0) !== 1) {
    poado_fail('BAD_TIER','Tier not active/unknown.');
  }

  // upsert user_miner_tier (NO new table)
  // NOTE: paid_emx column name stays, but we treat as “paid_amount” placeholder (EMA).
  $mul = (float)($t['multiplier'] ?? 1);
  $bc  = (int)($t['adoptee_bind_cap'] ?? 0);

  $exists = $pdo->prepare("SELECT user_id FROM user_miner_tier WHERE user_id=? LIMIT 1");
  $exists->execute([$userId]);
  $has = $exists->fetch(PDO::FETCH_ASSOC);

  if ($has) {
    $pdo->prepare("UPDATE user_miner_tier
                   SET tier_code=?, tx_hash=?, mining_multiplier=?, bind_limit=?, activated_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP()
                   WHERE user_id=?")
        ->execute([$tier_code, $tx_hash, $mul, $bc, $userId]);
  } else {
    $pdo->prepare("INSERT INTO user_miner_tier
      (user_id, tier_code, paid_emx, mining_multiplier, bind_limit, tx_hash, activated_at, updated_at)
      VALUES (?,?,0,?,?,?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
      ->execute([$userId, $tier_code, $mul, $bc, $tx_hash]);
  }

  // IMPORTANT: do NOT set user_miner_tier_wallet here (that would make it instantly active without verification)
  // Admin or later on-chain verifier updates wallet tier.

  poado_json([
    'ok'=>true,
    'message'=>'Activation request recorded. Pending verification/approval.',
    'tier_code'=>$tier_code,
    'tx_hash'=>$tx_hash,
    'ts_utc'=>poado_now_utc_dt()->format(DateTime::ATOM),
  ]);

} catch (Throwable $e) {
  poado_fail('SERVER_ERROR','activate failed',['detail'=>$e->getMessage()]);
}