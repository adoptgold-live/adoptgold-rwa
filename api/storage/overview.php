<?php
declare(strict_types=1);

/**
 * /rwa/api/storage/overview.php
 *
 * VERSION: FINAL-LOCK-OVERVIEW-UNIFIED-v1.0.20260406
 *
 * PURPOSE:
 * - Single UI-facing balance helper for Storage + Cert
 * - Merges:
 *      - base overview payload
 *      - on-chain (balance.php logic via payload)
 *      - reserve-aware (balances.php logic via payload)
 *
 * DO NOT:
 * - remove balance.php / balances.php (backend engines)
 * - break response shape (locked contract)
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/api/storage/_bootstrap.php';

if (storage_request_method() !== 'GET') {
    storage_api_fail('METHOD_NOT_ALLOWED', ['message'=>'GET required'], 405);
}

storage_assert_ready();

$user = storage_require_user();
$userId = (int)($user['id'] ?? 0);

if ($userId <= 0) {
    storage_api_fail('AUTH_REQUIRED', ['message'=>'Login required'], 401);
}

/* -----------------------------
 * ENV + HTTP HELPERS
 * ----------------------------- */

function ov_env(string $k, string $d=''): string {
    $v = getenv($k);
    if (is_string($v) && trim($v)!=='') return trim($v);
    if (!empty($_ENV[$k])) return trim((string)$_ENV[$k]);
    if (!empty($_SERVER[$k])) return trim((string)$_SERVER[$k]);
    return $d;
}

function ov_http_json(string $url, array $headers=[]): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>12,
        CURLOPT_HTTPHEADER=>$headers
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($raw) || $code>=400) return null;
    $j = json_decode($raw,true);
    return is_array($j)?$j:null;
}

/* -----------------------------
 * TON LIVE BALANCE (override)
 * ----------------------------- */

function ov_live_ton(string $addr): ?string {
    $addr = trim($addr);
    if ($addr==='') return null;

    $base = rtrim(ov_env('TONCENTER_BASE','https://toncenter.com/api/v3'),'/');
    $apiKey = ov_env('TONCENTER_API_KEY','');

    $headers = ['Accept: application/json'];
    if ($apiKey!=='') $headers[]='X-API-Key: '.$apiKey;

    $url = $base.'/account?address='.rawurlencode($addr);
    $j = ov_http_json($url,$headers);

    $raw = null;
    if (is_array($j)) {
        if (isset($j['balance'])) $raw = (string)$j['balance'];
        elseif (isset($j['accounts'][0]['balance'])) $raw = (string)$j['accounts'][0]['balance'];
    }

    if (!$raw || !preg_match('/^\d+$/',$raw)) return null;

    $raw = ltrim($raw,'0');
    if ($raw==='') $raw='0';

    if (strlen($raw)<=9) {
        $whole='0';
        $frac=str_pad($raw,9,'0',STR_PAD_LEFT);
    } else {
        $whole=substr($raw,0,-9);
        $frac=substr($raw,-9);
    }

    $frac=rtrim($frac,'0');
    if ($frac==='') return $whole.'.000000';

    return $whole.'.'.str_pad(substr($frac,0,6),6,'0');
}

/* -----------------------------
 * LOAD BASE PAYLOAD
 * ----------------------------- */

$payload = storage_overview_payload($user,true);

$card     = is_array($payload['card']??null) ? $payload['card'] : storage_card_row($userId);
$balances = is_array($payload['balances']??null) ? $payload['balances'] : storage_balance_row($userId);
$sync     = is_array($payload['sync']??null) ? $payload['sync'] : [];

$isActive = storage_card_is_active($card);
$editable = storage_card_is_editable($card);
$reload   = storage_reload_allowed($card);

/* -----------------------------
 * ACTIVATION
 * ----------------------------- */

$act = storage_activation_load_open_ref($userId);

$activation = [
    'activation_ref'     => (string)($act['activation_ref'] ?? ''),
    'tx_hash'            => (string)($act['tx_hash'] ?? ''),
    'verified'           => (bool)($act['verified'] ?? false),
    'is_active'          => (bool)($act['is_active'] ?? false),
    'ema_price_snapshot' => '',
    'ema_reward'         => '',
    'reward_token'       => 'EMA',
    'reward_status'      => '',
    'success_summary'    => '',
];

/* -----------------------------
 * CLAIMABLE (SAFE EMPTY)
 * ----------------------------- */

$claimable = [
    'claim_ema'         => null,
    'claim_wems'        => null,
    'claim_usdt_ton'    => null,
    'claim_emx_tips'    => null,
    'fuel_ems'          => null,
];

/* -----------------------------
 * WALLET + LIVE TON
 * ----------------------------- */

$wallet = (string)($user['wallet_address'] ?? '');

$liveTon = ov_live_ton($wallet);
if ($liveTon !== null) {
    $balances['fuel_ton_gas'] = $liveTon;
    $sync['live_ton_gas'] = $liveTon;
    $sync['live_ton_source'] = 'toncenter_v3';
}

/* -----------------------------
 * FINAL NORMALIZED BALANCES
 * ----------------------------- */

function b($arr,$k){
    return (string)($arr[$k] ?? '0.000000');
}

$balancesFinal = [
    'card_balance_rwa'         => b($balances,'card_balance_rwa'),
    'onchain_emx'              => b($balances,'onchain_emx'),
    'onchain_ema'              => b($balances,'onchain_ema'),
    'onchain_wems'             => b($balances,'onchain_wems'),
    'unclaim_ema'              => b($balances,'unclaim_ema'),
    'unclaim_wems'             => b($balances,'unclaim_wems'),
    'unclaim_gold_packet_usdt' => b($balances,'unclaim_gold_packet_usdt'),
    'unclaim_tips_emx'         => b($balances,'unclaim_tips_emx'),
    'fuel_usdt_ton'            => b($balances,'fuel_usdt_ton'),
    'fuel_ems'                 => b($balances,'fuel_ems'),
    'fuel_ton_gas'             => b($balances,'fuel_ton_gas'),
];

/* -----------------------------
 * RESPONSE
 * ----------------------------- */

storage_api_ok([
    'ok' => true,
    'message' => 'OVERVIEW_OK',

    'wallet_address' => $wallet,
    'address'        => $wallet,

    'balances' => $balancesFinal,

    'card' => [
        'card_number'    => (string)($card['card_number'] ?? ''),
        'status'         => (string)($card['status'] ?? ($isActive?'active':'none')),
        'locked'         => $isActive ? 1 : 0,
        'is_active'      => $isActive,
        'editable'       => $editable,
        'reload_allowed' => $reload
    ],

    'activation' => $activation,
    'claimable'  => $claimable,

    'sync' => [
        'source' => 'overview',
        'onchain_source' => 'balance.php',
        'claimable_source' => 'balances.php',
        'updated_at' => date('Y-m-d H:i:s'),
    ]

],200);
