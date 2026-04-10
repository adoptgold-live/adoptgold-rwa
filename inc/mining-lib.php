<?php
declare(strict_types=1);

/**
 * /rwa/inc/mining-lib.php
 *
 * Master Mining Engine Core
 * - Tier resolution (EMA on-chain only)
 * - Mining calculation
 * - Storage sync bridge
 */

require_once __DIR__ . '/ema-stake-lib.php';

if (defined('POADO_MINING_LIB_LOADED')) {
    return;
}
define('POADO_MINING_LIB_LOADED', true);

/**
 * Tier configuration (LOCKED)
 */
function poado_tier_map(): array
{
    return [
        'free' => ['multiplier'=>1,'cap'=>100,'bc'=>0,'ema'=>0],
        'verified' => ['multiplier'=>2,'cap'=>300,'bc'=>10,'ema'=>0],
        'sub' => ['multiplier'=>3,'cap'=>500,'bc'=>100,'ema'=>100],
        'core' => ['multiplier'=>5,'cap'=>1000,'bc'=>300,'ema'=>1000],
        'nodes' => ['multiplier'=>10,'cap'=>3000,'bc'=>1000,'ema'=>5000],
        'super_node' => ['multiplier'=>30,'cap'=>10000,'bc'=>3000,'ema'=>100000],
    ];
}

/**
 * Resolve EMA stake → tier (ON-CHAIN ONLY)
 */
function poado_get_active_ema(PDO $pdo, int $userId): float
{
    $st = $pdo->prepare("
        SELECT staked_amount_ema
        FROM poado_ema_stake_records
        WHERE user_id = ?
        AND verify_status = 'confirmed'
        AND lock_expires_at > NOW()
        ORDER BY staked_amount_ema DESC
        LIMIT 1
    ");
    $st->execute([$userId]);
    return (float)($st->fetchColumn() ?: 0);
}

/**
 * Resolve tier (STRICT RULE)
 */
function poado_resolve_tier(PDO $pdo, int $userId): array
{
    $tiers = poado_tier_map();

    // KYC check
    $st = $pdo->prepare("SELECT is_fully_verified FROM users WHERE id=? LIMIT 1");
    $st->execute([$userId]);
    $kyc = (int)($st->fetchColumn() ?: 0);

    $ema = poado_get_active_ema($pdo, $userId);

    // determine highest eligible EMA tier
    if ($ema >= 100000) $tier = 'super_node';
    elseif ($ema >= 5000) $tier = 'nodes';
    elseif ($ema >= 1000) $tier = 'core';
    elseif ($ema >= 100) $tier = 'sub';
    else {
        // fallback
        $tier = ($kyc === 1) ? 'verified' : 'free';
    }

    $cfg = $tiers[$tier];

    return [
        'tier' => $tier,
        'multiplier' => $cfg['multiplier'],
        'daily_cap' => $cfg['cap'],
        'binding_cap' => $cfg['bc'],
        'ema' => $ema
    ];
}

/**
 * Apply tier to miner profile
 */
function poado_apply_tier(PDO $pdo, int $userId, string $wallet): array
{
    $r = poado_resolve_tier($pdo, $userId);

    $st = $pdo->prepare("
        UPDATE poado_miner_profiles
        SET
            miner_tier = ?,
            multiplier = ?,
            daily_cap_wems = ?,
            binding_cap = ?,
            ema_staked_active = ?
        WHERE user_id = ?
    ");
    $st->execute([
        $r['tier'],
        $r['multiplier'],
        $r['daily_cap'],
        $r['binding_cap'],
        $r['ema'],
        $userId
    ]);

    return $r;
}

/**
 * Mining calculation
 */
function poado_calculate_mining(float $elapsedSec, float $multiplier, float $remainingCap): float
{
    if ($elapsedSec < 8) return 0;

    $base = ($elapsedSec / 10) * 0.33;
    $amount = $base * $multiplier;

    return round(min($amount, $remainingCap), 9);
}

/**
 * Ledger write
 */
function poado_mining_credit(PDO $pdo, int $userId, string $wallet, float $amount): void
{
    if ($amount <= 0) return;

    $pdo->prepare("
        INSERT INTO poado_mining_ledger
        (user_id, wallet, entry_type, amount_wems, ref_date)
        VALUES (?, ?, 'mining_tick', ?, CURDATE())
    ")->execute([$userId, $wallet, $amount]);

    $pdo->prepare("
        UPDATE poado_miner_profiles
        SET
            today_mined_wems = today_mined_wems + ?,
            total_mined_wems = total_mined_wems + ?
        WHERE user_id = ?
    ")->execute([$amount, $amount, $userId]);
}

/**
 * 🔥 STORAGE SYNC (MASTER LOCK)
 */
function poado_sync_storage(PDO $pdo, int $userId): void
{
    $st = $pdo->prepare("
        SELECT
            total_mined_wems,
            total_binding_wems,
            total_node_bonus_wems,
            total_claimed_wems
        FROM poado_miner_profiles
        WHERE user_id = ?
        LIMIT 1
    ");
    $st->execute([$userId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);

    if (!$r) return;

    $total =
        (float)$r['total_mined_wems']
      + (float)$r['total_binding_wems']
      + (float)$r['total_node_bonus_wems'];

    $claimed = (float)$r['total_claimed_wems'];

    $unclaim = max(0, $total - $claimed);

    $pdo->prepare("
        INSERT INTO rwa_storage_balances
        (user_id, unclaim_wems, updated_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            unclaim_wems = VALUES(unclaim_wems),
            updated_at = NOW()
    ")->execute([$userId, $unclaim]);
}

/**
 * Main mining tick handler
 */
function poado_mining_tick(PDO $pdo, int $userId, string $wallet, float $elapsedSec): float
{
    // resolve + apply tier
    $tier = poado_apply_tier($pdo, $userId, $wallet);

    // get remaining cap
    $st = $pdo->prepare("
        SELECT today_mined_wems, daily_cap_wems
        FROM poado_miner_profiles
        WHERE user_id = ?
    ");
    $st->execute([$userId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);

    $remaining = max(0,
        (float)$p['daily_cap_wems'] - (float)$p['today_mined_wems']
    );

    // calc mining
    $amount = poado_calculate_mining(
        $elapsedSec,
        (float)$tier['multiplier'],
        $remaining
    );

    if ($amount > 0) {
        poado_mining_credit($pdo, $userId, $wallet, $amount);
        poado_sync_storage($pdo, $userId);
    }

    return $amount;
}
