<?php
declare(strict_types=1);

/**
 * /dashboard/inc/mining.php  (UNIVERSAL + LOCKED)
 *
 * PURPOSE
 *  - Mining engine used by mining-tester.php (and future mining page)
 *  - Wallet-only identity (NO user_id anywhere)
 *  - Compatible with BOTH:
 *      A) New schema: system_daily_caps(cap_date, system_cap_wems, mined_wems_today)
 *      B) Legacy schema: system_daily_caps(day_utc, wems_minted, updated_at_utc, ...)
 *
 * LOCKED RULES (PROJECT) *  - Session enforced via get_wallet_session() in pages
 *  - ✅ Enforce wallet session in pages via: $wallet = get_wallet_session();
 *  - ✅ DB via db_connect(); then $pdo = $GLOBALS['pdo'];
 *  - ❌ NEVER call db function; ❌ NEVER PDO direct instantiation(...) *  - All paths use __DIR__
 */
// Block direct web access: this file is a LIBRARY and must be included by other scripts.
// If someone tries to call /dashboard/inc/mining.php directly, forbid it.
if (PHP_SAPI !== 'cli') {
    $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
    if ($script && realpath($script) === realpath(__FILE__)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Forbidden";
        exit;
    }
}



require_once __DIR__ . '/bootstrap.php';

const POADO_VAULT_WALLET = '0x0000000000000000000000000000000000000000';

if (!function_exists('poado_mining_settle_tick')) {

  /** Internal: wallet format */
  function poado__is_wallet(string $w): bool {
    return (bool)preg_match('/^0x[a-f0-9]{40}$/', $w);
  }

  /** Internal: safe get setting_value */
  function poado__get_setting(PDO $pdo, string $key, string $default): string {
    try {
      $st = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key=? LIMIT 1');
      $st->execute([$key]);
      $v = $st->fetchColumn();
      if ($v === false || $v === null || $v === '') return $default;
      return (string)$v;
    } catch (Throwable $e) {
      return $default;
    }
  }

  /** Internal: UTC+8 day window + dateKey (YYYY-MM-DD) */
  function poado__utc8_window(): array {
    // UTC+8 day boundary. Using Kuala Lumpur as requested earlier.
    $tz = new DateTimeZone('Asia/Kuala_Lumpur');
    $now = new DateTime('now', $tz);
    $start = (clone $now)->setTime(0, 0, 0);
    $end = (clone $start)->modify('+1 day');

    $startUtc = (clone $start)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $endUtc   = (clone $end)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $dateKey  = $start->format('Y-m-d'); // UTC+8 date key
    return [$startUtc, $endUtc, $dateKey];
  }

  /**
   * Internal: detect system_daily_caps columns and return a capability map.
   * Returns:
   *  [
   *    'mode' => 'new'|'legacy'|'none',
   *    'date_col' => 'cap_date'|'day_utc'|null,
   *    'cap_col'  => 'system_cap_wems'|null,
   *    'mined_col'=> 'mined_wems_today'|'wems_minted'|null
   *  ]
   */
  function poado__syscaps_schema(PDO $pdo): array {
    try {
      $st = $pdo->query("SHOW COLUMNS FROM system_daily_caps");
      $cols = [];
      while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($r['Field'])) $cols[strtolower((string)$r['Field'])] = true;
      }

      $hasCapDate    = isset($cols['cap_date']);
      $hasDayUtc     = isset($cols['day_utc']);
      $hasCapWems    = isset($cols['system_cap_wems']);
      $hasMinedToday = isset($cols['mined_wems_today']);
      $hasWemsMinted = isset($cols['wems_minted']);

      // "Hybrid" is common in this project: day_utc is PK (NOT NULL) + cap_date UNIQUE + mined_wems_today + system_cap_wems
      if ($hasCapDate && $hasCapWems && $hasMinedToday) {
        return [
          'mode' => 'new',
          'date_col' => 'cap_date',
          'cap_col' => 'system_cap_wems',
          'mined_col' => 'mined_wems_today',
          'has_day_utc' => $hasDayUtc,
        ];
      }

      if ($hasDayUtc && $hasWemsMinted) {
        // legacy: mined stored in wems_minted; system cap comes from system_settings (default)
        return [
          'mode' => 'legacy',
          'date_col' => 'day_utc',
          'cap_col' => ($hasCapWems ? 'system_cap_wems' : null),
          'mined_col' => 'wems_minted',
          'has_day_utc' => true,
        ];
      }

      return ['mode'=>'none','date_col'=>null,'cap_col'=>null,'mined_col'=>null,'has_day_utc'=>false];
    } catch (Throwable $e) {
      return ['mode'=>'none','date_col'=>null,'cap_col'=>null,'mined_col'=>null,'has_day_utc'=>false];
    }
  }


  /**
   * Internal: ensure today's row exists and read (system_cap, system_mined_today) in a schema-compatible way.
   * Always returns numeric values; never throws (unless schema totally missing).
   */
  function poado__syscaps_get(PDO $pdo, string $dateKey, float $sysCapDefault): array {
    $sch = poado__syscaps_schema($pdo);

    if ($sch['mode'] === 'new') {
      // Ensure today's row exists. If day_utc is PK (hybrid schema), we MUST provide it.
      if (!empty($sch['has_day_utc'])) {
        $pdo->prepare("
          INSERT INTO system_daily_caps (cap_date, day_utc, system_cap_wems, mined_wems_today)
          VALUES (?, ?, ?, 0)
          ON DUPLICATE KEY UPDATE system_cap_wems = system_cap_wems
        ")->execute([$dateKey, $dateKey, $sysCapDefault]);
      } else {
        $pdo->prepare("
          INSERT INTO system_daily_caps (cap_date, system_cap_wems, mined_wems_today)
          VALUES (?, ?, 0)
          ON DUPLICATE KEY UPDATE system_cap_wems = system_cap_wems
        ")->execute([$dateKey, $sysCapDefault]);
      }

      $st = $pdo->prepare("SELECT system_cap_wems, mined_wems_today FROM system_daily_caps WHERE cap_date=? LIMIT 1");
      $st->execute([$dateKey]);
      $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
      $cap = (float)($row['system_cap_wems'] ?? $sysCapDefault);
      $mined = (float)($row['mined_wems_today'] ?? 0);
      return [$cap, $mined];
    }

    if ($sch['mode'] === 'legacy') {
      // Ensure row exists keyed by day_utc
      $pdo->prepare("
        INSERT INTO system_daily_caps (day_utc, wems_minted)
        VALUES (?, 0)
        ON DUPLICATE KEY UPDATE wems_minted = wems_minted
      ")->execute([$dateKey]);

      $st = $pdo->prepare("SELECT wems_minted FROM system_daily_caps WHERE day_utc=? LIMIT 1");
      $st->execute([$dateKey]);
      $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
      $mined = (float)($row['wems_minted'] ?? 0);
      return [$sysCapDefault, $mined];
    }

    // Schema missing — return safe defaults
    return [$sysCapDefault, 0.0];
  }

    if ($sch['mode'] === 'legacy') {
      // Ensure row exists keyed by day_utc
      $pdo->prepare("
        INSERT INTO system_daily_caps (day_utc, wems_minted)
        VALUES (?, 0)
        ON DUPLICATE KEY UPDATE wems_minted = wems_minted
      ")->execute([$dateKey]);

      // Some installs may also have system_cap_wems added later; if present, read it, else use default.
      if ($sch['cap_col'] === 'system_cap_wems') {
        $st = $pdo->prepare("SELECT system_cap_wems, wems_minted FROM system_daily_caps WHERE day_utc=? LIMIT 1");
        $st->execute([$dateKey]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $cap = isset($row['system_cap_wems']) ? (float)$row['system_cap_wems'] : $sysCapDefault;
        $mined = isset($row['wems_minted']) ? (float)$row['wems_minted'] : 0.0;
        return [$cap, $mined, $sch];
      } else {
        $st = $pdo->prepare("SELECT wems_minted FROM system_daily_caps WHERE day_utc=? LIMIT 1");
        $st->execute([$dateKey]);
        $mined = (float)($st->fetchColumn() ?: 0);
        return [$sysCapDefault, $mined, $sch];
      }
    }

    throw new RuntimeException('Schema not ready: system_daily_caps missing/unknown');
  }

  /**
   * Internal: increment system mined for today in schema-compatible way.
   */
  function poado__syscaps_add_mined(PDO $pdo, string $dateKey, float $delta, array $sch): void {
    if ($delta <= 0) return;

    if (($sch['mode'] ?? '') === 'new') {
      $pdo->prepare("UPDATE system_daily_caps SET mined_wems_today = mined_wems_today + ? WHERE cap_date=?")
        ->execute([$delta, $dateKey]);
      return;
    }

    if (($sch['mode'] ?? '') === 'legacy') {
      $pdo->prepare("UPDATE system_daily_caps SET wems_minted = wems_minted + ? WHERE day_utc=?")
        ->execute([$delta, $dateKey]);
      return;
    }
  }

  /**
   * Settle ONE mining tick for a wallet.
   * Returns array for UI JSON panel.
   */
  function poado_mining_settle_tick(string $wallet, string $event_id, ?float $baseRate = null): array {
    // DB (locked)
    db_connect();
    $pdo = $GLOBALS['pdo'];

    $wallet = strtolower(trim($wallet));
    $event_id = trim($event_id);

    if (!poado__is_wallet($wallet)) {
      return ['ok' => false, 'code' => 'bad_wallet', 'message' => 'Invalid wallet format'];
    }
    if ($event_id === '' || strlen($event_id) > 64) {
      return ['ok' => false, 'code' => 'bad_event', 'message' => 'Invalid event id'];
    }

    // Base rate (default from system_settings: base_rate_wems_per_10s)
    if ($baseRate === null || $baseRate <= 0) {
      $baseRate = (float)poado__get_setting($pdo, 'base_rate_wems_per_10s', '0.33');
      if ($baseRate <= 0) $baseRate = 0.33;
    }

    $vaultWallet = strtolower(trim(poado__get_setting($pdo, 'emx_vault_wallet', POADO_VAULT_WALLET)));
    if (!poado__is_wallet($vaultWallet)) $vaultWallet = POADO_VAULT_WALLET;

    // Locked economics / settings
    $burnRate    = (float)poado__get_setting($pdo, 'ema_burn_rate', '0.001');      // 0.1%
    $adopterRate = (float)poado__get_setting($pdo, 'adopter_reward_rate', '0.01'); // 1%
    $sysCapDef   = (float)poado__get_setting($pdo, 'system_daily_cap_wems', '1000000');
    if ($sysCapDef <= 0) $sysCapDef = 1000000;

    [$utcStart, $utcEnd, $dateKey] = poado__utc8_window();

    try {
      $pdo->beginTransaction();

      // Idempotent: event unique
      $st = $pdo->prepare('SELECT 1 FROM mining_events_wallet WHERE mining_event_id=? LIMIT 1');
      $st->execute([$event_id]);
      if ($st->fetchColumn()) {
        $pdo->rollBack();
        return ['ok' => true, 'code' => 'duplicate', 'message' => 'Already settled'];
      }

      // Tier by wallet
      $st = $pdo->prepare('SELECT tier_code FROM user_miner_tier_wallet WHERE wallet=? LIMIT 1');
      $st->execute([$wallet]);
      $tierCode = (string)($st->fetchColumn() ?: 'S10');

      $stTier = $pdo->prepare('SELECT multiplier, daily_cap_wems, adoptee_bind_cap FROM miner_tiers WHERE tier_code=? LIMIT 1');
      $stTier->execute([$tierCode]);
      $tier = $stTier->fetch(PDO::FETCH_ASSOC);
      if (!$tier) {
        $tierCode = 'S10';
        $stTier->execute([$tierCode]);
        $tier = $stTier->fetch(PDO::FETCH_ASSOC);
      }
      if (!$tier) {
        throw new RuntimeException('Schema not ready: miner_tiers missing');
      }

      $multiplier = (float)$tier['multiplier'];
      $dailyCap   = (float)$tier['daily_cap_wems'];
      $bindCap    = $tier['adoptee_bind_cap']; // may be null

      if ($multiplier <= 0) throw new RuntimeException('Invalid multiplier');
      if ($dailyCap <= 0) throw new RuntimeException('Invalid daily cap');

      // User mined today (mining reason only)
      $st = $pdo->prepare("
        SELECT COALESCE(SUM(amount),0)
        FROM token_ledger
        WHERE token='wEMS' AND wallet=? AND direction='credit' AND reason='mining'
          AND created_at>=? AND created_at<?
      ");
      $st->execute([$wallet, $utcStart, $utcEnd]);
      $userToday = (float)$st->fetchColumn();

      // System cap + mined today (schema-adaptive)
      [$sysCap, $sysToday, $sysSch] = poado__syscaps_get($pdo, $dateKey, $sysCapDef);

      // Compute
      $wemsRaw    = $baseRate * $multiplier;
      $remainUser = max(0.0, $dailyCap - $userToday);
      $remainSys  = max(0.0, $sysCap - $sysToday);
      $wemsFinal  = min($wemsRaw, $remainUser, $remainSys);

      // Record event first
      $pdo->prepare('INSERT INTO mining_events_wallet (mining_event_id, wallet, wems_amount) VALUES (?,?,?)')
        ->execute([$event_id, $wallet, $wemsFinal]);

      if ($wemsFinal <= 0) {
        $pdo->commit();
        return [
          'ok' => true,
          'code' => 'cap_reached',
          'wallet' => $wallet,
          'event_id' => $event_id,
          'tier_code' => $tierCode,
          'multiplier' => number_format($multiplier, 2, '.', ''),
          'daily_cap_units' => (int)$dailyCap,
          'max_nodes' => ($bindCap === null ? null : (int)$bindCap),
          'user_mined_today' => $userToday,
          'system_cap_units' => $sysCap,
          'system_mined_today' => $sysToday,
          'wems_raw' => $wemsRaw,
          'wems_final' => 0,
          'message' => 'Daily cap reached (user or system).'
        ];
      }

      // Ledger writes
      $pdo->prepare("
        INSERT INTO token_ledger (token, wallet, direction, amount, reason, ref_id, meta)
        VALUES ('wEMS', ?, 'credit', ?, 'mining', ?, NULL)
      ")->execute([$wallet, $wemsFinal, $event_id]);

      // Each mined 1 wEMS => mint 1 EMX to Vault (SYSTEM wallet)
      $pdo->prepare("
        INSERT INTO token_ledger (token, wallet, direction, amount, reason, ref_id, meta)
        VALUES ('EMX', ?, 'credit', ?, 'vault_mint', ?, NULL)
      ")->execute([$vaultWallet, $wemsFinal, $event_id]);

      // POAdo burn 0.1% of EMA (SYSTEM wallet)
      $emaBurn = $wemsFinal * $burnRate;
      if ($emaBurn > 0) {
        $pdo->prepare("
          INSERT INTO token_ledger (token, wallet, direction, amount, reason, ref_id, meta)
          VALUES ('EMA', ?, 'debit', ?, 'poado_burn', ?, NULL)
        ")->execute([$vaultWallet, $emaBurn, $event_id]);
      }

      // Adopter reward (+1% of adoptee mined) if bound
      $st = $pdo->prepare("
        SELECT adopter_wallet
        FROM adoptee_binding_wallet
        WHERE adoptee_wallet=? AND status='active'
        LIMIT 1
      ");
      $st->execute([$wallet]);
      $adopterWallet = $st->fetchColumn();
      $adopterWallet = $adopterWallet ? strtolower((string)$adopterWallet) : '';

      $adopterReward = 0.0;
      if ($adopterWallet !== '' && poado__is_wallet($adopterWallet) && $adopterRate > 0) {
        $adopterReward = $wemsFinal * $adopterRate;
        if ($adopterReward > 0) {
          $pdo->prepare("
            INSERT INTO token_ledger (token, wallet, direction, amount, reason, ref_id, meta)
            VALUES ('wEMS', ?, 'credit', ?, 'adopter_reward', ?, NULL)
          ")->execute([$adopterWallet, $adopterReward, $event_id]);
        }
      }

      // Update system mined today
      poado__syscaps_add_mined($pdo, $dateKey, $wemsFinal, $sysSch);

      $pdo->commit();

      return [
        'ok' => true,
        'code' => 'mined',
        'wallet' => $wallet,
        'event_id' => $event_id,
        'tier_code' => $tierCode,
        'multiplier' => number_format($multiplier, 2, '.', ''),
        'daily_cap_units' => (int)$dailyCap,
        'max_nodes' => ($bindCap === null ? null : (int)$bindCap),
        'user_mined_today' => $userToday,
        'system_cap_units' => $sysCap,
        'system_mined_today' => $sysToday,
        'wems_raw' => $wemsRaw,
        'wems_final' => $wemsFinal,
        'ema_burn' => $emaBurn,
        'adopter_wallet' => ($adopterWallet !== '' ? $adopterWallet : null),
        'adopter_reward' => $adopterReward,
        'syscaps_mode' => $sysSch['mode'] ?? null,
        'message' => 'Mining tick settled.'
      ];

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      return [
        'ok' => false,
        'code' => 'error',
        'message' => $e->getMessage()
      ];
    }
  }