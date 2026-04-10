<?php
/**
 * /var/www/html/public/dashboard/inc/token-rewards.php
 *
 * POAdo Token + Rewards Registry (Canonical)
 * v1.2.20260304
 *
 * Locks implemented:
 * - Chain ID indicator: 7709304653
 * - Treasury/Vault addresses (TON)
 * - Royalty split + weights
 * - RWA mint pricing rules:
 *   - Genesis certs: mint ONLY with wEMS
 *   - Secondary certs: mint ONLY with EMA$, fixed 100 EMA$ each (RLIFE/RPROP/RTRIP)
 *
 * Safe to include from any module (no session, no DB, no output).
 */

declare(strict_types=1);

/** ---------------------------
 *  0) Chain indicator (UI + logic)
 *  --------------------------- */
const POADO_CHAIN_ID = '7709304653';

/** ---------------------------
 *  1) TON vaults / system wallets (ENV first, fallback to locked defaults)
 *  --------------------------- */
function poado_env(string $key, string $fallback = ''): string {
    $v = getenv($key);
    if ($v === false) return $fallback;
    $v = trim((string)$v);
    return $v !== '' ? $v : $fallback;
}

function poado_vaults(): array {
    return [
        // Treasury is Admin + Platform
        'TON_TREASURY_ADDRESS' => poado_env('TON_TREASURY_ADDRESS', 'UQBdYfGArtoCUmBs5TjYQtfPFfQuGC2Ydbj2pQr3zIlNrDta'),
        'ALL_CLAIM_VAULT'      => poado_env('ALL_CLAIM_VAULT',      'UQDQFyFgdZ5HuT8DjXsS8YPpYd59o2DlFo1kVDhBdrsHqoIw'),
        'GOLD_PACKET_VAULT'    => poado_env('GOLD_PACKET_VAULT',    'UQBD5dcSiKJmekRpGuPehUL3udEJpT2Gz9Rnpw2Zq1YFaJ8v'),
        'REWARDS_POOL'         => poado_env('REWARDS_POOL',         'UQDbVZIewSbi7RM7Xei470WwCfk7-stDox3wXc7RBUh79KaT'),
        'MINING_POOL'          => poado_env('MINING_POOL',          'UQAAG89NPqwS2Hz9AzLLEb4IUAaq5UeVaXCUJeVADVQZq-nw'),
    ];
}

/** ---------------------------
 *  2) Token registry (TON Jettons)
 *  - All token 9 decimals except USDT-TON (6)
 *  --------------------------- */
function poado_tokens(): array {
    return [
        'WEMS' => [
            'symbol'        => 'wEMS',
            'name'          => 'Web3 Gold Mining Rewards Token',
            'decimals'      => 9,
            'jetton_master' => poado_env('WEMS_JETTON_MASTER', 'EQA8dAgNtnsfGF0M-MJfnqii5AhxcRe73M8nCkkxuq85Tr-Q'),
            'max_supply'    => '10000000000', // reference supply (token decimals applied elsewhere)
            'icon'          => '/metadata/wems.png',
        ],
        'EMA' => [
            'symbol'        => 'EMA$',
            'name'          => 'eMoney RWA Adoption Token',
            'decimals'      => 9,
            'jetton_master' => poado_env('EMA_JETTON_MASTER', 'EQDK-bRI706S1cIIoLhTrTf-e8pL2TpOD5rcP3OaxYyzs74b'),
            'max_supply'    => '2100000000',
            'icon'          => '/metadata/ema.png',
        ],
        'EMX' => [
            'symbol'        => 'EMX',
            'name'          => 'eMoney XAU Gold RWA Stable Token',
            'decimals'      => 9,
            'jetton_master' => poado_env('EMX_JETTON_MASTER', 'EQBj0zGcHOvN5IsBP_BAAG5NRiuAa_SLBu-xjsJn7AeM4nQy'),
            'max_supply'    => '100000000',
            'icon'          => '/metadata/emx.png',
        ],
        'EMS' => [
            'symbol'        => 'EMS',
            'name'          => 'eMoney Solvency RWA Fuel Token',
            'decimals'      => 9,
            'jetton_master' => poado_env('EMS_JETTON_MASTER', 'EQCpJURzB4DJcL1keSRF8u5J5SmakM-_FaftTAyXRrVnnNmD'),
            'max_supply'    => '50000000',
            'icon'          => '/metadata/ems.png',
        ],
        'USDT' => [
            'symbol'        => 'USDT',
            'name'          => 'Tether USD (TON)',
            'decimals'      => 6, // USDT-TON 6 decimals
            'jetton_master' => poado_env('USDT_JETTON_MASTER', 'EQCxE6mUtQJKFnGfaROTKOt1lZbDiiX1kCixRv7Nw2Id_sDs'),
            'max_supply'    => '', // not fixed here
            'icon'          => '/metadata/usdt.png', // optional (if you add later)
            'notes'         => 'Gold Packet Vault pay in USDT-TON',
        ],
    ];
}

/** ---------------------------
 *  3) RWA cert types (Green/Gold + Secondary only for now)
 *  - Secondary: RLIFE-EMA, RPROP-EMA, RTRIP-EMA
 *  --------------------------- */
function poado_rwa_cert_types(): array {
    return [
        // Genesis (mint with wEMS only)
        'RCO2C-EMA' => [
            'family'      => 'green',
            'label'       => 'Green Cert',
            'mint_token'  => 'WEMS',
            'mint_price'  => 1000,     // 1000 wEMS
            'weight'      => 1,
        ],
        'RK92-EMA' => [
            'family'      => 'gold',
            'label'       => 'Gold Cert',
            'mint_token'  => 'WEMS',
            'mint_price'  => 50000,    // 50,000 wEMS
            'weight'      => 5,
        ],

        // Secondary (mint with EMA$ only, fixed 100 EMA$ each)
        'RLIFE-EMA' => [
            'family'      => 'secondary',
            'label'       => 'Secondary · Health (RLIFE)',
            'mint_token'  => 'EMA',
            'mint_price'  => 100,      // 100 EMA$
            'weight'      => 10,
        ],
        'RPROP-EMA' => [
            'family'      => 'secondary',
            'label'       => 'Secondary · Property (RPROP)',
            'mint_token'  => 'EMA',
            'mint_price'  => 100,      // 100 EMA$
            'weight'      => 10,
        ],
        'RTRIP-EMA' => [
            'family'      => 'secondary',
            'label'       => 'Secondary · Travel (RTRIP)',
            'mint_token'  => 'EMA',
            'mint_price'  => 100,      // 100 EMA$
            'weight'      => 10,
        ],
    ];
}

/** ---------------------------
 *  4) Royalty + rewards distribution rules (global)
 *  --------------------------- */
function poado_royalty_rules(): array {
    return [
        // Getgems Royalty 25% split
        'getgems_royalty_total_pct' => 25,

        // Of the 25% royalty:
        'split' => [
            // 5% to treasury
            'treasury_pct' => 5,

            // 15% to rewards pool weighted by cert weight (secondary=10,gold=5,black=3,blue=2,green=1)
            // Note: only types enabled in poado_rwa_cert_types() are currently minted/used.
            'rewards_pool_pct' => 15,

            // 5% gold packet for Gold Cert holders only (weighted by Gold cert weight only)
            'gold_packet_pct' => 5,

            // Remaining 75% is minter/seller one-time take (outside this module’s internal distribution)
            'seller_take_pct' => 75,
        ],

        'claim_policy' => [
            'only_full_kyc_can_claim' => true,
            'claims_processed_by_system' => true,
        ],
    ];
}

/** ---------------------------
 *  5) Convenience helpers
 *  --------------------------- */
function poado_chain_badge(): array {
    return [
        'chain_id' => POADO_CHAIN_ID,
        'label'    => 'Chain ID: ' . POADO_CHAIN_ID,
        'led'      => 'green', // UI hint
    ];
}

function poado_cert_weight(string $rso_code): int {
    $m = poado_rwa_cert_types();
    return isset($m[$rso_code]) ? (int)$m[$rso_code]['weight'] : 0;
}

function poado_cert_mint_price(string $rso_code): array {
    $m = poado_rwa_cert_types();
    if (!isset($m[$rso_code])) return ['ok' => false, 'reason' => 'unknown_cert_type'];

    return [
        'ok'        => true,
        'rso_code'  => $rso_code,
        'token'     => (string)$m[$rso_code]['mint_token'],  // WEMS or EMA
        'price'     => (int)$m[$rso_code]['mint_price'],     // integer units in token main units (not nano)
        'family'    => (string)$m[$rso_code]['family'],
    ];
}