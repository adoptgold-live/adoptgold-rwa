<?php
/**
 * POAdo Rewards Engine — v1.2 (LOCKED)
 * File: /dashboard/inc/poado-rewards.php
 *
 * Requires:
 * - db_connect() already exists in bootstrap.php
 * - $GLOBALS['pdo']
 */

function poado_now_utc_iso(): string {
  return gmdate('c');
}

function poado_reward_amount(string $code): int {
  // LOCKED v1.2
  $map = [
    'A1' => 50,   // adoptee create booking (Cal success)
    'A2' => 300,  // adoptee success deal (paid)
    'A3' => 50,   // adoptee fail deal
    'B1' => 100,  // ace accept booking <= 1h
    'B2' => 300,  // ace success deal (paid)
    'B3' => 50,   // ace fail deal
    'B4' => 100,  // ace bind 1 adoptee
    'B5' => 100,  // ace first contact <= 6h
    'B6' => 300,  // ace cross-region close
  ];
  return $map[$code] ?? 0;
}

/**
 * Idempotent reward post.
 * action_key must be globally unique per action.
 */
function poado_reward_post(array $args): array {
  db_connect();
  $pdo = $GLOBALS['pdo'];

  $code = (string)($args['action_code'] ?? '');
  $amount = (int)($args['amount_wems'] ?? poado_reward_amount($code));
  $wallet = (string)($args['wallet'] ?? '');
  $role = (string)($args['role'] ?? '');
  $actionKey = (string)($args['action_key'] ?? '');
  $refType = (string)($args['ref_type'] ?? null);
  $refUid = (string)($args['ref_uid'] ?? null);
  $meta = $args['meta'] ?? null;

  if ($code === '' || $wallet === '' || $role === '' || $actionKey === '' || $amount <= 0) {
    return ['ok' => false, 'msg' => 'Invalid reward args.'];
  }

  try {
    $stmt = $pdo->prepare("
      INSERT INTO poado_wems_rewards
        (action_code, action_key, wallet, role, amount_wems, ref_type, ref_uid, status, meta, created_at)
      VALUES
        (:code, :akey, :wallet, :role, :amt, :rtype, :ruid, 'posted', :meta, NOW())
    ");
    $stmt->execute([
      ':code' => $code,
      ':akey' => $actionKey,
      ':wallet' => $wallet,
      ':role' => $role,
      ':amt' => $amount,
      ':rtype' => $refType ?: null,
      ':ruid' => $refUid ?: null,
      ':meta' => $meta !== null ? json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
    ]);
  } catch (Throwable $e) {
    // Duplicate key => already posted
    if (stripos($e->getMessage(), 'Duplicate') !== false) {
      return ['ok' => true, 'posted' => false, 'msg' => 'Already rewarded (idempotent).'];
    }
    return ['ok' => false, 'msg' => 'Reward insert failed.', 'error' => $e->getMessage()];
  }

  // Optional balance sync (safe best-effort; won’t break if table differs)
  try {
    // If you already have token_balances schema with (wallet, token_symbol, balance)
    $stmt2 = $pdo->prepare("
      INSERT INTO token_balances (wallet, token_symbol, balance, updated_at)
      VALUES (:w, 'wEMS', :amt, NOW())
      ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance), updated_at = NOW()
    ");
    $stmt2->execute([':w' => $wallet, ':amt' => $amount]);
  } catch (Throwable $e) {
    // ignore (ledger is source of truth)
  }

  return ['ok' => true, 'posted' => true, 'msg' => 'Reward posted.', 'amount' => $amount];
}

/**
 * Allocate ACE by priority: area -> state -> default senior ACE.
 * Returns: ['ace_wallet'=>..., 'matched'=>'area|state|default', 'primary_area'=>..., 'primary_state'=>...]
 */
function poado_allocate_ace(string $countryCode, string $state, string $area): array {
  db_connect();
  $pdo = $GLOBALS['pdo'];

  $countryCode = strtolower(trim($countryCode));
  $state = trim($state);
  $area = trim($area);

  // helper: fetch default senior from config
  $default = ['ace_wallet' => '', 'matched' => 'default', 'primary_area' => '', 'primary_state' => ''];
  try {
    $stmt = $pdo->prepare("SELECT `value` FROM poado_config WHERE `key`='default_senior_ace_wallet' LIMIT 1");
    $stmt->execute();
    $defaultWallet = (string)($stmt->fetchColumn() ?: '');
    $default['ace_wallet'] = $defaultWallet;
  } catch (Throwable $e) {}

  // 1) AREA match
  if ($area !== '') {
    try {
      $stmt = $pdo->prepare("
        SELECT ace_wallet, area, state
        FROM poado_ace_alloc_rules
        WHERE is_active=1
          AND area IS NOT NULL
          AND LOWER(TRIM(area)) = LOWER(TRIM(:area))
        ORDER BY priority ASC, id ASC
        LIMIT 1
      ");
      $stmt->execute([':area' => $area]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($row && !empty($row['ace_wallet'])) {
        return [
          'ace_wallet' => (string)$row['ace_wallet'],
          'matched' => 'area',
          'primary_area' => (string)($row['area'] ?? ''),
          'primary_state' => (string)($row['state'] ?? ''),
        ];
      }
    } catch (Throwable $e) {}
  }

  // 2) STATE match
  if ($state !== '') {
    try {
      $stmt = $pdo->prepare("
        SELECT ace_wallet, area, state
        FROM poado_ace_alloc_rules
        WHERE is_active=1
          AND state IS NOT NULL
          AND LOWER(TRIM(state)) = LOWER(TRIM(:state))
        ORDER BY priority ASC, id ASC
        LIMIT 1
      ");
      $stmt->execute([':state' => $state]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($row && !empty($row['ace_wallet'])) {
        return [
          'ace_wallet' => (string)$row['ace_wallet'],
          'matched' => 'state',
          'primary_area' => (string)($row['area'] ?? ''),
          'primary_state' => (string)($row['state'] ?? ''),
        ];
      }
    } catch (Throwable $e) {}
  }

  // 3) DEFAULT
  return $default;
}
