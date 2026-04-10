<?php
declare(strict_types=1);

/**
 * /rwa/cron/mining-snapshot.php
 *
 * Purpose:
 * - Freeze previous UTC day mining stats
 * - Aggregate from poado_mining_ledger
 * - Write into poado_mining_daily_stats
 *
 * Safe:
 * - idempotent
 * - positional params (no HY093)
 * - CLI-safe include (__DIR__)
 */

require_once __DIR__ . '/../inc/core/bootstrap.php';

date_default_timezone_set('UTC');

db_connect();
$pdo = $GLOBALS['pdo'] ?? null;

if (!$pdo) {
    echo "[ERR] DB connection failed\n";
    exit(1);
}

// previous UTC day
$statDate = date('Y-m-d', strtotime('yesterday'));

try {

    $sql = "
    INSERT INTO poado_mining_daily_stats (
        stat_date,
        user_id,
        wallet,
        miner_tier,
        multiplier,
        daily_cap_wems,
        mined_wems,
        binding_wems,
        node_bonus_wems,
        heartbeat_count,
        active_seconds
    )
    SELECT
        ? AS stat_date,
        mp.user_id,
        mp.wallet,
        mp.miner_tier,
        mp.multiplier,
        mp.daily_cap_wems,

        COALESCE(SUM(
            CASE WHEN ml.entry_type = 'mining_tick'
            THEN ml.amount_wems ELSE 0 END
        ), 0) AS mined_wems,

        COALESCE(SUM(
            CASE WHEN ml.entry_type = 'binding_commission'
            THEN ml.amount_wems ELSE 0 END
        ), 0) AS binding_wems,

        COALESCE(SUM(
            CASE WHEN ml.entry_type = 'node_pool_bonus'
            THEN ml.amount_wems ELSE 0 END
        ), 0) AS node_bonus_wems,

        0 AS heartbeat_count,
        0 AS active_seconds

    FROM poado_miner_profiles mp

    LEFT JOIN poado_mining_ledger ml
      ON ml.user_id = mp.user_id
     AND ml.wallet = mp.wallet
     AND ml.ref_date = ?

    GROUP BY
        mp.user_id,
        mp.wallet,
        mp.miner_tier,
        mp.multiplier,
        mp.daily_cap_wems

    ON DUPLICATE KEY UPDATE
        miner_tier = VALUES(miner_tier),
        multiplier = VALUES(multiplier),
        daily_cap_wems = VALUES(daily_cap_wems),
        mined_wems = VALUES(mined_wems),
        binding_wems = VALUES(binding_wems),
        node_bonus_wems = VALUES(node_bonus_wems)
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$statDate, $statDate]);

    $rows = $stmt->rowCount();

    echo "[" . date('c') . "] mining-snapshot done stat_date={$statDate} rows={$rows}\n";

} catch (Throwable $e) {

    echo "[" . date('c') . "] mining-snapshot ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
