<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cron/mining-node-pool.php
 * Daily node/super-node pool settlement
 * - nodes pool = 0.5%
 * - super_node pool = 3%
 * - distribution = pro-rata by active confirmed staked EMA within same exact tier
 */

require_once __DIR__ . '/../inc/core/bootstrap.php';

date_default_timezone_set('UTC');

function out(string $msg): void
{
    echo '[' . gmdate('c') . '] ' . $msg . PHP_EOL;
}

db_connect();
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    throw new RuntimeException('DB connection unavailable');
}

$statDate = gmdate('Y-m-d', strtotime('-1 day'));

$st = $pdo->prepare("
    SELECT COALESCE(SUM(mined_wems), 0)
    FROM poado_mining_daily_stats
    WHERE stat_date = ?
");
$st->execute([$statDate]);
$totalNetworkMined = round((float)$st->fetchColumn(), 9);

if ($totalNetworkMined <= 0) {
    out('mining-node-pool skipped stat_date=' . $statDate . ' total_network_mined=0');
    exit(0);
}

$pools = [
    'nodes' => 0.005,
    'super_node' => 0.03,
];

foreach ($pools as $tier => $percent) {
    $poolTotal = round($totalNetworkMined * $percent, 9);

    $sql = "
    SELECT
        mp.user_id,
        mp.wallet,
        mp.ema_staked_active
    FROM poado_miner_profiles mp
    WHERE mp.miner_tier = :tier
      AND mp.tier_status = 'active'
      AND mp.ema_staked_active > 0
      AND EXISTS (
          SELECT 1
          FROM poado_ema_stake_records es
          WHERE es.user_id = mp.user_id
            AND es.wallet = mp.wallet
            AND es.verify_status = 'confirmed'
            AND es.lock_expires_at > UTC_TIMESTAMP()
      )
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':tier' => $tier]);
    $miners = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$miners || $poolTotal <= 0) {
        out('node-pool tier=' . $tier . ' skipped eligible=0 pool=' . $poolTotal);
        continue;
    }

    $totalActiveStaked = 0.0;
    foreach ($miners as $m) {
        $totalActiveStaked += (float)$m['ema_staked_active'];
    }
    $totalActiveStaked = round($totalActiveStaked, 9);

    if ($totalActiveStaked <= 0) {
        out('node-pool tier=' . $tier . ' skipped total_active_staked=0');
        continue;
    }

    $paid = 0;
    foreach ($miners as $m) {
        $userId = (int)$m['user_id'];
        $wallet = (string)$m['wallet'];
        $userStaked = round((float)$m['ema_staked_active'], 9);
        $reward = round($poolTotal * ($userStaked / $totalActiveStaked), 9);

        if ($reward <= 0) {
            continue;
        }

        $pdo->beginTransaction();
        try {
            $chk = $pdo->prepare("
                SELECT id
                FROM poado_node_pool_daily
                WHERE stat_date = ? AND pool_tier = ? AND wallet = ?
                LIMIT 1
            ");
            $chk->execute([$statDate, $tier, $wallet]);
            if ($chk->fetchColumn()) {
                $pdo->rollBack();
                continue;
            }

            $insDaily = $pdo->prepare("
                INSERT INTO poado_node_pool_daily (
                    stat_date,
                    pool_tier,
                    total_network_mined_wems,
                    pool_percent,
                    pool_total_wems,
                    total_active_staked_ema,
                    wallet,
                    user_id,
                    user_staked_ema,
                    reward_wems
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insDaily->execute([
                $statDate,
                $tier,
                $totalNetworkMined,
                $percent,
                $poolTotal,
                $totalActiveStaked,
                $wallet,
                $userId,
                $userStaked,
                $reward
            ]);

            $insLedger = $pdo->prepare("
                INSERT INTO poado_mining_ledger (
                    user_id,
                    wallet,
                    entry_type,
                    amount_wems,
                    ref_code,
                    ref_date,
                    meta
                ) VALUES (?, ?, 'node_pool_bonus', ?, ?, ?, ?)
            ");
            $insLedger->execute([
                $userId,
                $wallet,
                $reward,
                strtoupper($tier) . '-POOL-' . str_replace('-', '', $statDate) . '-' . substr(md5($wallet), 0, 8),
                $statDate,
                json_encode([
                    'pool_tier' => $tier,
                    'pool_percent' => $percent,
                    'pool_total_wems' => $poolTotal,
                    'user_staked_ema' => $userStaked,
                    'total_active_staked_ema' => $totalActiveStaked,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ]);

            $upd = $pdo->prepare("
                UPDATE poado_miner_profiles
                SET
                  today_node_bonus_wems = today_node_bonus_wems + ?,
                  total_node_bonus_wems = total_node_bonus_wems + ?
                WHERE user_id = ? AND wallet = ?
            ");
            $upd->execute([$reward, $reward, $userId, $wallet]);

            $pdo->commit();
            $paid++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            out('node-pool error tier=' . $tier . ' wallet=' . $wallet . ' err=' . $e->getMessage());
        }
    }

    out('node-pool tier=' . $tier . ' stat_date=' . $statDate . ' total_network=' . $totalNetworkMined . ' pool_total=' . $poolTotal . ' paid=' . $paid);
}
