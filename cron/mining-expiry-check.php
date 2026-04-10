<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cron/mining-expiry-check.php
 */

require_once __DIR__ . '/../inc/core/bootstrap.php';
require_once __DIR__ . '/../inc/mining-lib.php';

date_default_timezone_set('UTC');

function out(string $msg): void
{
    echo '[' . gmdate('c') . '] ' . $msg . PHP_EOL;
}

function fallbackTierForUser(PDO $pdo, int $userId): array
{
    $st = $pdo->prepare("SELECT is_fully_verified FROM users WHERE id = ? LIMIT 1");
    $st->execute([$userId]);
    $kyc = (int)($st->fetchColumn() ?: 0);

    if ($kyc === 1) {
        return [
            'miner_tier' => 'verified',
            'multiplier' => 2.0,
            'daily_cap_wems' => 300.0,
            'binding_cap' => 10,
        ];
    }

    return [
        'miner_tier' => 'free',
        'multiplier' => 1.0,
        'daily_cap_wems' => 100.0,
        'binding_cap' => 0,
    ];
}

db_connect();
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    throw new RuntimeException('DB connection unavailable');
}

$expiredCount = 0;
$reactivatedCount = 0;

$selExpired = $pdo->query("
    SELECT DISTINCT user_id, wallet
    FROM poado_ema_stake_records
    WHERE verify_status = 'confirmed'
      AND lock_expires_at <= UTC_TIMESTAMP()
");
$expiredRows = $selExpired->fetchAll(PDO::FETCH_ASSOC) ?: [];

foreach ($expiredRows as $row) {
    $userId = (int)$row['user_id'];
    $wallet = (string)$row['wallet'];

    $pdo->beginTransaction();
    try {
        $updStake = $pdo->prepare("
            UPDATE poado_ema_stake_records
            SET verify_status = 'expired'
            WHERE user_id = ?
              AND wallet = ?
              AND verify_status = 'confirmed'
              AND lock_expires_at <= UTC_TIMESTAMP()
        ");
        $updStake->execute([$userId, $wallet]);

        $fb = fallbackTierForUser($pdo, $userId);

        $updProfile = $pdo->prepare("
            UPDATE poado_miner_profiles
            SET
              miner_tier = ?,
              tier_status = 'pending_ema',
              multiplier = ?,
              daily_cap_wems = ?,
              binding_cap = ?,
              ema_staked_active = 0,
              ema_stake_expires_at = NULL
            WHERE user_id = ? AND wallet = ?
        ");
        $updProfile->execute([
            $fb['miner_tier'],
            $fb['multiplier'],
            $fb['daily_cap_wems'],
            $fb['binding_cap'],
            $userId,
            $wallet
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
            ) VALUES (?, ?, 'tier_fallback', 0, ?, ?, ?)
        ");
        $insLedger->execute([
            $userId,
            $wallet,
            'FALLBACK-' . gmdate('YmdHis') . '-' . substr(md5($wallet), 0, 8),
            gmdate('Y-m-d'),
            json_encode([
                'reason' => 'ema_stake_expired',
                'fallback_tier' => $fb['miner_tier'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);

        $pdo->commit();
        $expiredCount++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        out('expiry error wallet=' . $wallet . ' err=' . $e->getMessage());
    }
}

$selReactivate = $pdo->query("
    SELECT DISTINCT mp.user_id, mp.wallet
    FROM poado_miner_profiles mp
    WHERE mp.tier_status = 'pending_ema'
      AND EXISTS (
          SELECT 1
          FROM poado_ema_stake_records es
          WHERE es.user_id = mp.user_id
            AND es.wallet = mp.wallet
            AND es.verify_status = 'confirmed'
            AND es.lock_expires_at > UTC_TIMESTAMP()
      )
");
$reactivateRows = $selReactivate->fetchAll(PDO::FETCH_ASSOC) ?: [];

foreach ($reactivateRows as $row) {
    $userId = (int)$row['user_id'];
    $wallet = (string)$row['wallet'];

    $pdo->beginTransaction();
    try {
        $resolved = poado_resolve_tier($pdo, $userId, $wallet);

        $upd = $pdo->prepare("
            UPDATE poado_miner_profiles
            SET tier_status = 'active'
            WHERE user_id = ? AND wallet = ?
        ");
        $upd->execute([$userId, $wallet]);

        $insLedger = $pdo->prepare("
            INSERT INTO poado_mining_ledger (
                user_id,
                wallet,
                entry_type,
                amount_wems,
                ref_code,
                ref_date,
                meta
            ) VALUES (?, ?, 'tier_reactivation', 0, ?, ?, ?)
        ");
        $insLedger->execute([
            $userId,
            $wallet,
            'REACT-' . gmdate('YmdHis') . '-' . substr(md5($wallet), 0, 8),
            gmdate('Y-m-d'),
            json_encode([
                'reactivated_tier' => (string)($resolved['tier'] ?? $resolved['miner_tier'] ?? 'free'),
                'ema_staked_active' => (float)($resolved['ema'] ?? $resolved['ema_staked_active'] ?? 0),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);

        $pdo->commit();
        $reactivatedCount++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        out('reactivation error wallet=' . $wallet . ' err=' . $e->getMessage());
    }
}

out('mining-expiry-check done expired=' . $expiredCount . ' reactivated=' . $reactivatedCount);
