<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cron/mining-binding-commission.php
 * Daily binding commission settlement at UTC 00:05
 * Rules:
 * - 1% of adoptee mined_wems
 * - extra reward, not capped by daily own mining cap
 * - payout only if adopter currently has binding_cap > 0
 * - canonical binding table = poado_adopter_bindings
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

$sql = "
SELECT
    b.adopter_wallet,
    b.adoptee_wallet,
    ds.mined_wems AS adoptee_mined_wems,
    mp.user_id AS adopter_user_id,
    mp.miner_tier AS adopter_tier,
    mp.binding_cap
FROM poado_adopter_bindings b
JOIN poado_mining_daily_stats ds
  ON ds.wallet = b.adoptee_wallet
 AND ds.stat_date = :stat_date
JOIN poado_miner_profiles mp
  ON mp.wallet = b.adopter_wallet
WHERE COALESCE(b.is_active, 1) = 1
";

$st = $pdo->prepare($sql);
$st->execute([':stat_date' => $statDate]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$done = 0;
$skipped = 0;

foreach ($rows as $row) {
    $adopterWallet = (string)$row['adopter_wallet'];
    $adopteeWallet = (string)$row['adoptee_wallet'];
    $adopterUserId = (int)$row['adopter_user_id'];
    $adopterTier   = (string)$row['adopter_tier'];
    $bindingCap    = (int)$row['binding_cap'];
    $adopteeMined  = round((float)$row['adoptee_mined_wems'], 9);

    if ($bindingCap <= 0 || $adopteeMined <= 0) {
        $skipped++;
        continue;
    }

    $commission = round($adopteeMined * 0.01, 9);
    if ($commission <= 0) {
        $skipped++;
        continue;
    }

    $pdo->beginTransaction();
    try {
        $chk = $pdo->prepare("
            SELECT id
            FROM poado_binding_commission_daily
            WHERE stat_date = ? AND adopter_wallet = ? AND adoptee_wallet = ?
            LIMIT 1
        ");
        $chk->execute([$statDate, $adopterWallet, $adopteeWallet]);
        if ($chk->fetchColumn()) {
            $pdo->rollBack();
            $skipped++;
            continue;
        }

        $insDaily = $pdo->prepare("
            INSERT INTO poado_binding_commission_daily (
                stat_date,
                adopter_wallet,
                adoptee_wallet,
                adoptee_mined_wems,
                commission_rate,
                commission_wems,
                adopter_tier
            ) VALUES (?, ?, ?, ?, 0.010000, ?, ?)
        ");
        $insDaily->execute([
            $statDate,
            $adopterWallet,
            $adopteeWallet,
            $adopteeMined,
            $commission,
            $adopterTier
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
            ) VALUES (?, ?, 'binding_commission', ?, ?, ?, ?)
        ");
        $insLedger->execute([
            $adopterUserId,
            $adopterWallet,
            $commission,
            'BC-' . str_replace('-', '', $statDate) . '-' . substr(md5($adopterWallet . '|' . $adopteeWallet), 0, 10),
            $statDate,
            json_encode([
                'adoptee_wallet' => $adopteeWallet,
                'adoptee_mined_wems' => $adopteeMined,
                'commission_rate' => 0.01,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);

        $upd = $pdo->prepare("
            UPDATE poado_miner_profiles
            SET
              today_binding_wems = today_binding_wems + ?,
              total_binding_wems = total_binding_wems + ?
            WHERE user_id = ? AND wallet = ?
        ");
        $upd->execute([$commission, $commission, $adopterUserId, $adopterWallet]);

        $pdo->commit();
        $done++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        out('binding error adopter=' . $adopterWallet . ' adoptee=' . $adopteeWallet . ' err=' . $e->getMessage());
    }
}

out('mining-binding-commission done stat_date=' . $statDate . ' paid=' . $done . ' skipped=' . $skipped);
