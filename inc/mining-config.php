<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/inc/mining-config.php
 * POAdo wEMS WEB3 GOLD Mining v3
 * Latest canonical mining config
 *
 * LOCKED RULES
 * - mining is off-chain ledger
 * - on-chain is claim only
 * - claim currently frozen
 * - boost multiplier uses on-chain EMA$ only
 * - mined wEMS must reconcile to rwa_storage_balances.unclaim_wems
 * - base mining rate = 0.33 wEMS per 10 seconds
 * - reset cycle = UTC 00:00
 * - system daily cap = 1,000,000 wEMS
 * - storage formula:
 *   unclaim_wems =
 *   (total_mined_wems + total_binding_wems + total_node_bonus_wems)
 *   - total_claimed_wems
 */

if (defined('POADO_MINING_CONFIG_LOADED')) {
    return;
}
define('POADO_MINING_CONFIG_LOADED', true);

/* =========================================================
 * CORE RUNTIME
 * ========================================================= */
const POADO_MINING_VERSION                    = 'v3.0.20260326';
const POADO_MINING_RUNTIME_MODE               = 'offchain_ledger_only';
const POADO_MINING_CLAIM_MODE                 = 'frozen';
const POADO_MINING_STORAGE_SYNC_ENABLED       = true;
const POADO_MINING_STORAGE_TABLE              = 'rwa_storage_balances';
const POADO_MINING_STORAGE_UNCLAIM_COLUMN     = 'unclaim_wems';
const POADO_MINING_RECONCILE_FORMULA          = 'gross_minus_claimed';
const POADO_MINING_LEDGER_DECIMALS            = 8;
const POADO_MINING_INTERNAL_SCALE             = 100000000; // legacy-compatible int scale

/* =========================================================
 * TICK / CLOCK
 * ========================================================= */
const POADO_MINING_TICK_SECONDS               = 10;
const POADO_MINING_BASE_RATE_WEMS_PER_TICK    = 0.33;
const POADO_MINING_RESET_TZ                   = 'UTC';
const POADO_MINING_RESET_TIME                 = '00:00:00';
const POADO_MINING_DAY_SECONDS                = 86400;
const POADO_MINING_ELAPSED_CAP_SECONDS        = 300; // anti-cheat cap for long gaps
const POADO_MINING_ELAPSED_MIN_SECONDS        = 8;   // anti-spam threshold
const POADO_MINING_HEARTBEAT_EXPECT_SECONDS   = 10;

/* =========================================================
 * GLOBAL NETWORK CAP
 * ========================================================= */
const POADO_MINING_SYSTEM_DAILY_CAP_WEMS      = 1000000.0;

/* =========================================================
 * BOOST / TIER SOURCE
 * ========================================================= */
const POADO_MINING_BOOST_SOURCE               = 'onchain_ema_only';
const POADO_MINING_ALLOW_OFFCHAIN_EMA_BOOST   = false;

/* =========================================================
 * BINDING / NODE POOL
 * ========================================================= */
const POADO_MINING_BINDING_COMMISSION_RATE    = 0.01; // 1%
const POADO_MINING_NODE_POOL_NODES_PCT        = 0.5;  // display / settlement pct
const POADO_MINING_NODE_POOL_SUPER_PCT        = 3.0;

/* =========================================================
 * PROFILE / TABLE NAMES
 * ========================================================= */
const POADO_MINING_TABLE_PROFILES             = 'poado_miner_profiles';
const POADO_MINING_TABLE_LEDGER               = 'poado_mining_ledger';
const POADO_MINING_TABLE_DAILY_STATS          = 'poado_mining_daily_stats';
const POADO_MINING_TABLE_BINDINGS             = 'poado_adopter_bindings';
const POADO_MINING_TABLE_BINDING_DAILY        = 'poado_binding_commission_daily';
const POADO_MINING_TABLE_NODE_POOL_DAILY      = 'poado_node_pool_daily';
const POADO_MINING_TABLE_EMA_STAKES           = 'poado_ema_stake_records';
const POADO_MINING_TABLE_CLAIMS               = 'poado_wems_claim_requests';
const POADO_MINING_TABLE_LEGACY_LOG           = 'wems_mining_log';

/* =========================================================
 * TIER CODES / LABELS / RULES
 * Locked business rules
 * ========================================================= */
const POADO_MINING_TIERS = [
    'free' => [
        'label'            => 'Free Miner',
        'multiplier'       => 1.0,
        'daily_cap_wems'   => 100.0,
        'binding_cap'      => 0,
        'ema_min'          => 0.0,
        'node_reward_pct'  => 0.0,
        'kyc_fallback'     => false,
    ],
    'verified' => [
        'label'            => 'Verified Miner',
        'multiplier'       => 2.0,
        'daily_cap_wems'   => 300.0,
        'binding_cap'      => 10,
        'ema_min'          => 0.0,
        'node_reward_pct'  => 0.0,
        'kyc_fallback'     => true,
    ],
    'sub' => [
        'label'            => 'Sub Miner',
        'multiplier'       => 3.0,
        'daily_cap_wems'   => 500.0,
        'binding_cap'      => 100,
        'ema_min'          => 100.0,
        'node_reward_pct'  => 0.0,
        'kyc_fallback'     => false,
    ],
    'core' => [
        'label'            => 'Core Miner',
        'multiplier'       => 5.0,
        'daily_cap_wems'   => 1000.0,
        'binding_cap'      => 300,
        'ema_min'          => 1000.0,
        'node_reward_pct'  => 0.0,
        'kyc_fallback'     => false,
    ],
    'nodes' => [
        'label'            => 'Nodes Miner',
        'multiplier'       => 10.0,
        'daily_cap_wems'   => 3000.0,
        'binding_cap'      => 1000,
        'ema_min'          => 5000.0,
        'node_reward_pct'  => 0.5,
        'kyc_fallback'     => false,
    ],
    'super_node' => [
        'label'            => 'Super Node Miner',
        'multiplier'       => 30.0,
        'daily_cap_wems'   => 10000.0,
        'binding_cap'      => 3000,
        'ema_min'          => 100000.0,
        'node_reward_pct'  => 3.0,
        'kyc_fallback'     => false,
    ],
];

/* =========================================================
 * STAKE BONUS
 * Locked rule:
 * +1x multiplier and +100 wEMS/day cap per extra 1000 EMA
 * above tier minimum, tiers sub/core/nodes/super_node only
 * final multiplier hard-cap 100x
 * ========================================================= */
const POADO_MINING_STAKE_BONUS_ENABLED        = true;
const POADO_MINING_STAKE_BONUS_STEP_EMA       = 1000.0;
const POADO_MINING_STAKE_BONUS_MULTIPLIER_ADD = 1.0;
const POADO_MINING_STAKE_BONUS_DAILY_CAP_ADD  = 100.0;
const POADO_MINING_STAKE_BONUS_ELIGIBLE_TIERS = ['sub', 'core', 'nodes', 'super_node'];
const POADO_MINING_FINAL_MULTIPLIER_HARD_CAP  = 100.0;

/* =========================================================
 * HELPERS
 * ========================================================= */
function poado_mining_tiers(): array
{
    return POADO_MINING_TIERS;
}

function poado_mining_tier_exists(string $tier): bool
{
    return isset(POADO_MINING_TIERS[$tier]);
}

function poado_mining_tier_rule(string $tier): array
{
    return POADO_MINING_TIERS[$tier] ?? POADO_MINING_TIERS['free'];
}

function poado_mining_tier_label(string $tier): string
{
    return (string)(poado_mining_tier_rule($tier)['label'] ?? 'Free Miner');
}

function poado_mining_tier_multiplier(string $tier): float
{
    return (float)(poado_mining_tier_rule($tier)['multiplier'] ?? 1.0);
}

function poado_mining_tier_daily_cap(string $tier): float
{
    return (float)(poado_mining_tier_rule($tier)['daily_cap_wems'] ?? 100.0);
}

function poado_mining_tier_binding_cap(string $tier): int
{
    return (int)(poado_mining_tier_rule($tier)['binding_cap'] ?? 0);
}

function poado_mining_tier_ema_min(string $tier): float
{
    return (float)(poado_mining_tier_rule($tier)['ema_min'] ?? 0.0);
}

function poado_mining_tier_node_reward_pct(string $tier): float
{
    return (float)(poado_mining_tier_rule($tier)['node_reward_pct'] ?? 0.0);
}

function poado_mining_rate_per_tick(float $multiplier): float
{
    $m = max(0.0, min((float)$multiplier, POADO_MINING_FINAL_MULTIPLIER_HARD_CAP));
    return round(POADO_MINING_BASE_RATE_WEMS_PER_TICK * $m, 8);
}

function poado_mining_is_stake_bonus_tier(string $tier): bool
{
    return in_array($tier, POADO_MINING_STAKE_BONUS_ELIGIBLE_TIERS, true);
}

function poado_mining_extra_ema_steps(string $tier, float $emaActive): int
{
    if (!POADO_MINING_STAKE_BONUS_ENABLED || !poado_mining_is_stake_bonus_tier($tier)) {
        return 0;
    }

    $baseMin = poado_mining_tier_ema_min($tier);
    if ($emaActive <= $baseMin) {
        return 0;
    }

    $extra = $emaActive - $baseMin;
    return (int)floor($extra / POADO_MINING_STAKE_BONUS_STEP_EMA);
}

function poado_mining_effective_multiplier(string $tier, float $emaActive = 0.0): float
{
    $base = poado_mining_tier_multiplier($tier);
    $steps = poado_mining_extra_ema_steps($tier, $emaActive);
    $effective = $base + ($steps * POADO_MINING_STAKE_BONUS_MULTIPLIER_ADD);
    return min($effective, POADO_MINING_FINAL_MULTIPLIER_HARD_CAP);
}

function poado_mining_effective_daily_cap(string $tier, float $emaActive = 0.0): float
{
    $base = poado_mining_tier_daily_cap($tier);
    $steps = poado_mining_extra_ema_steps($tier, $emaActive);
    return $base + ($steps * POADO_MINING_STAKE_BONUS_DAILY_CAP_ADD);
}

function poado_mining_storage_unclaimed(
    float $totalMinedWems,
    float $totalBindingWems,
    float $totalNodeBonusWems,
    float $totalClaimedWems
): float {
    $gross = $totalMinedWems + $totalBindingWems + $totalNodeBonusWems;
    return max(0.0, $gross - $totalClaimedWems);
}

function poado_mining_runtime_defaults(): array
{
    return [
        'is_mining'            => 0,
        'battery_pct'          => 0.0,
        'started_at'           => 0,
        'started_wallet'       => '',
        'last_tick_request_at' => 0,
        'last_heartbeat_at'    => 0,
        'stopped_at'           => 0,
    ];
}

function poado_mining_now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function poado_mining_today_utc(): string
{
    return gmdate('Y-m-d');
}
