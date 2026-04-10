<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/storage/tester/bal-tester.php
 * Version: v1.0.20260406-bal-tester
 *
 * Standalone Storage balance tester
 * - LEFT  = balance.php-style payload
 * - RIGHT = overview.php-style payload
 * - No wallet session required
 * - No topbar/footer
 * - Includes /dashboard/inc/env.php only
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/env.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/token-registry.php';

date_default_timezone_set('Asia/Kuala_Lumpur');

function bt_env(string $key, string $default = ''): string
{
    $v = getenv($key);
    if (is_string($v) && $v !== '') return $v;
    if (isset($_ENV[$key]) && is_scalar($_ENV[$key]) && (string)$_ENV[$key] !== '') return (string)$_ENV[$key];
    if (isset($_SERVER[$key]) && is_scalar($_SERVER[$key]) && (string)$_SERVER[$key] !== '') return (string)$_SERVER[$key];
    return $default;
}

function bt_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $host = bt_env('DB_HOST', '127.0.0.1');
    $name = bt_env('DB_NAME', 'wems_db');
    $user = bt_env('DB_USER', 'root');
    $pass = bt_env('DB_PASS', '');
    $charset = bt_env('DB_CHARSET', 'utf8mb4');

    $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function bt_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function bt_num($v, int $scale = 6): string
{
    $n = (float)$v;
    return number_format($n, $scale, '.', '');
}

function bt_addr_canon(string $v): string
{
    $v = strtolower(trim($v));
    $v = preg_replace('/^0:/', '', $v);
    $v = preg_replace('/^eq/', '', $v);
    $v = preg_replace('/^uq/', '', $v);
    return (string)$v;
}

function bt_toncenter_base(): string
{
    return rtrim(bt_env('TONCENTER_BASE', 'https://toncenter.com/api/v3'), '/');
}

function bt_toncenter_key(): string
{
    return trim(bt_env('TONCENTER_API_KEY', ''));
}

function bt_toncenter_get_json(string $url): array
{
    $headers = [];
    $apiKey = bt_toncenter_key();
    if ($apiKey !== '') {
        $headers[] = 'X-API-Key: ' . $apiKey;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $res = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($res === false) {
        throw new RuntimeException('CURL_ERROR: ' . $err);
    }

    $json = json_decode($res, true);
    if (!is_array($json)) {
        throw new RuntimeException('INVALID_JSON');
    }

    if ($http >= 400) {
        throw new RuntimeException('HTTP_' . $http);
    }

    return $json;
}

function bt_to_decimal(string $raw, int $decimals, int $scale = 6): string
{
    $raw = trim($raw);
    if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) {
        return number_format(0, $scale, '.', '');
    }

    $neg = false;
    if ($raw[0] === '-') {
        $neg = true;
        $raw = substr($raw, 1);
    }

    $raw = ltrim($raw, '0');
    if ($raw === '') $raw = '0';

    if ($decimals <= 0) {
        $out = $raw;
    } else {
        $raw = str_pad($raw, $decimals + 1, '0', STR_PAD_LEFT);
        $int = substr($raw, 0, -$decimals);
        $frac = substr($raw, -$decimals);
        $out = $int . '.' . $frac;
    }

    $n = (float)$out;
    if ($neg) $n *= -1;
    return number_format($n, $scale, '.', '');
}

function bt_registry(): array
{
    $all = function_exists('poado_token_registry_all') ? poado_token_registry_all() : [];

    return [
        'EMA' => [
            'master_raw' => trim((string)($all['EMA']['master_raw'] ?? '')),
            'decimals' => (int)($all['EMA']['decimals'] ?? 9),
            'col' => 'onchain_ema',
        ],
        'EMX' => [
            'master_raw' => trim((string)($all['EMX']['master_raw'] ?? '')),
            'decimals' => (int)($all['EMX']['decimals'] ?? 9),
            'col' => 'onchain_emx',
        ],
        'EMS' => [
            'master_raw' => trim((string)($all['EMS']['master_raw'] ?? '')),
            'decimals' => (int)($all['EMS']['decimals'] ?? 9),
            'col' => 'fuel_ems',
        ],
        'WEMS' => [
            'master_raw' => trim((string)($all['WEMS']['master_raw'] ?? '')),
            'decimals' => (int)($all['WEMS']['decimals'] ?? 9),
            'col' => 'onchain_wems',
        ],
        'USDT_TON' => [
            'master_raw' => trim((string)($all['USDT_TON']['master_raw'] ?? '')),
            'decimals' => (int)($all['USDT_TON']['decimals'] ?? 6),
            'col' => 'fuel_usdt_ton',
        ],
    ];
}

function bt_user_find(?int $id, string $walletAddress): ?array
{
    $pdo = bt_db();

    if ($id && $id > 0) {
        $st = $pdo->prepare("
            SELECT id, wallet, wallet_address, nickname, email, email_verified_at, role, is_active
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) return $row;
    }

    if ($walletAddress !== '') {
        $st = $pdo->prepare("
            SELECT id, wallet, wallet_address, nickname, email, email_verified_at, role, is_active
            FROM users
            WHERE wallet_address = ?
            LIMIT 1
        ");
        $st->execute([$walletAddress]);
        $row = $st->fetch();
        if ($row) return $row;
    }

    return null;
}

function bt_card_row(int $userId): array
{
    $pdo = bt_db();
    $st = $pdo->prepare("
        SELECT
            user_id,
            bound_ton_address,
            activation_ref,
            activation_tx_hash,
            card_number,
            card_hash,
            card_last4,
            card_masked,
            is_active,
            activated_at,
            created_at,
            updated_at
        FROM rwa_storage_cards
        WHERE user_id = ?
        LIMIT 1
    ");
    $st->execute([$userId]);
    $row = $st->fetch();

    if (!$row) {
        return [
            'user_id' => $userId,
            'bound_ton_address' => '',
            'activation_ref' => '',
            'activation_tx_hash' => '',
            'card_number' => '',
            'card_hash' => '',
            'card_last4' => '',
            'card_masked' => '',
            'status' => 'none',
            'locked' => 0,
            'is_active' => false,
            'activated_at' => '',
            'created_at' => '',
            'updated_at' => '',
        ];
    }

    $isActive = (int)($row['is_active'] ?? 0) === 1;
    $cardNumber = trim((string)($row['card_number'] ?? ''));

    return [
        'user_id' => $userId,
        'bound_ton_address' => (string)($row['bound_ton_address'] ?? ''),
        'activation_ref' => (string)($row['activation_ref'] ?? ''),
        'activation_tx_hash' => (string)($row['activation_tx_hash'] ?? ''),
        'card_number' => $cardNumber,
        'card_hash' => (string)($row['card_hash'] ?? ''),
        'card_last4' => (string)($row['card_last4'] ?? ''),
        'card_masked' => (string)($row['card_masked'] ?? ''),
        'status' => $isActive ? 'active' : ($cardNumber !== '' ? 'draft' : 'none'),
        'locked' => $isActive ? 1 : 0,
        'is_active' => $isActive,
        'activated_at' => (string)($row['activated_at'] ?? ''),
        'created_at' => (string)($row['created_at'] ?? ''),
        'updated_at' => (string)($row['updated_at'] ?? ''),
    ];
}

function bt_balance_row(int $userId): array
{
    $pdo = bt_db();
    $st = $pdo->prepare("
        SELECT
            user_id,
            card_balance_rwa,
            onchain_emx,
            onchain_ema,
            onchain_wems,
            unclaim_ema,
            unclaim_wems,
            unclaim_gold_packet_usdt,
            unclaim_tips_emx,
            fuel_usdt_ton,
            fuel_ems,
            fuel_ton_gas,
            created_at,
            updated_at
        FROM rwa_storage_balances
        WHERE user_id = ?
        LIMIT 1
    ");
    $st->execute([$userId]);
    $row = $st->fetch();

    if ($row) return $row;

    return [
        'user_id' => $userId,
        'card_balance_rwa' => '0.000000',
        'onchain_emx' => '0.000000',
        'onchain_ema' => '0.000000',
        'onchain_wems' => '0.000000',
        'unclaim_ema' => '0.000000',
        'unclaim_wems' => '0.000000',
        'unclaim_gold_packet_usdt' => '0.000000',
        'unclaim_tips_emx' => '0.000000',
        'fuel_usdt_ton' => '0.000000',
        'fuel_ems' => '0.000000',
        'fuel_ton_gas' => '0.000000',
        'created_at' => '',
        'updated_at' => '',
    ];
}

function bt_activation_row(int $userId): ?array
{
    $card = bt_card_row($userId);
    $ref = trim((string)($card['activation_ref'] ?? ''));
    if ($ref === '') return null;

    return [
        'activation_ref' => $ref,
        'status' => !empty($card['is_active']) ? 'active' : 'prepared',
        'verified' => !empty($card['is_active']),
        'is_active' => !empty($card['is_active']),
        'wallet_address' => (string)($card['bound_ton_address'] ?? ''),
        'tx_hash' => (string)($card['activation_tx_hash'] ?? ''),
        'verified_at_utc' => (string)($card['activated_at'] ?? ''),
    ];
}

function bt_ton_balance(string $owner): string
{
    $url = bt_toncenter_base() . '/account?address=' . rawurlencode($owner);
    $json = bt_toncenter_get_json($url);

    $raw = (string)(
        $json['balance'] ??
        ($json['account']['balance'] ?? '0') ??
        '0'
    );

    return bt_to_decimal($raw, 9, 6);
}

function bt_live_balances(string $owner): array
{
    $registry = bt_registry();
    $ownerCanon = bt_addr_canon($owner);

    $out = [
        'onchain_ema' => '0.000000',
        'onchain_emx' => '0.000000',
        'fuel_ems' => '0.000000',
        'onchain_wems' => '0.000000',
        'fuel_usdt_ton' => '0.000000',
        'fuel_ton_gas' => '0.000000',
    ];

    $debug = [];

    foreach ($registry as $tokenKey => $cfg) {
        $master = trim((string)($cfg['master_raw'] ?? ''));
        $col = (string)$cfg['col'];
        $decimals = (int)($cfg['decimals'] ?? 9);

        if ($master === '') {
            $debug[$tokenKey] = ['ok' => false, 'error' => 'MASTER_MISSING'];
            continue;
        }

        $masterCanon = bt_addr_canon($master);

        try {
            $url = bt_toncenter_base()
                . '/jetton/wallets?owner_address=' . rawurlencode($owner)
                . '&jetton_address=' . rawurlencode($master)
                . '&limit=20';

            $json = bt_toncenter_get_json($url);
            $rows = $json['jetton_wallets'] ?? $json['result'] ?? [];
            if (!is_array($rows)) $rows = [];

            $best = null;
            $bestScore = -1;

            foreach ($rows as $row) {
                if (!is_array($row)) continue;

                $rowOwner = trim((string)(
                    $row['owner'] ??
                    $row['owner_address'] ??
                    $row['owner_wallet'] ??
                    ($row['account']['owner'] ?? '') ??
                    ''
                ));

                $rowMaster = trim((string)(
                    $row['jetton'] ??
                    $row['jetton_master'] ??
                    $row['jetton_address'] ??
                    ($row['account']['jetton'] ?? '') ??
                    ''
                ));

                $rowWallet = trim((string)(
                    $row['address'] ??
                    $row['wallet_address'] ??
                    $row['jetton_wallet'] ??
                    ''
                ));

                $rowBalance = trim((string)($row['balance'] ?? '0'));

                $score = 0;
                if ($rowOwner !== '' && bt_addr_canon($rowOwner) === $ownerCanon) $score += 100;
                if ($rowMaster !== '' && bt_addr_canon($rowMaster) === $masterCanon) $score += 100;
                if ($rowWallet !== '') $score += 10;
                if ($rowBalance !== '' && preg_match('/^\d+$/', $rowBalance)) {
                    $score += 5;
                    if ($rowBalance !== '0') $score += 1;
                }

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = [
                        'owner' => $rowOwner,
                        'master' => $rowMaster,
                        'wallet_address' => $rowWallet,
                        'balance_raw' => preg_match('/^\d+$/', $rowBalance) ? $rowBalance : '0',
                        'score' => $score,
                    ];
                }
            }

            if (
                is_array($best) &&
                bt_addr_canon((string)$best['owner']) === $ownerCanon &&
                bt_addr_canon((string)$best['master']) === $masterCanon
            ) {
                $out[$col] = bt_to_decimal((string)$best['balance_raw'], $decimals, 6);
                $debug[$tokenKey] = [
                    'ok' => true,
                    'wallet_address' => (string)$best['wallet_address'],
                    'balance_raw' => (string)$best['balance_raw'],
                    'balance_dec' => $out[$col],
                    'source' => 'exact_match',
                ];
            } else {
                $out[$col] = '0.000000';
                $debug[$tokenKey] = [
                    'ok' => true,
                    'wallet_address' => is_array($best) ? (string)$best['wallet_address'] : '',
                    'balance_raw' => is_array($best) ? (string)$best['balance_raw'] : '0',
                    'balance_dec' => '0.000000',
                    'source' => 'no_exact_match_zeroed',
                ];
            }
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $rateLimited = (strpos($msg, 'HTTP_429') !== false);

            $debug[$tokenKey] = [
                'ok' => false,
                'error' => $rateLimited ? 'RATE_LIMITED' : $msg,
                'rate_limited' => $rateLimited,
            ];
        }
    }

    try {
        $out['fuel_ton_gas'] = bt_ton_balance($owner);
        $debug['TON'] = [
            'ok' => true,
            'balance_dec' => $out['fuel_ton_gas'],
            'source' => 'account_balance',
        ];
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $rateLimited = (strpos($msg, 'HTTP_429') !== false);

        $debug['TON'] = [
            'ok' => false,
            'error' => $rateLimited ? 'RATE_LIMITED' : $msg,
            'rate_limited' => $rateLimited,
        ];
    }

    return ['balances' => $out, 'debug' => $debug];
}

function bt_balance_payload(array $user, array $live): array
{
    $userId = (int)$user['id'];
    $stored = bt_balance_row($userId);
    $balances = array_merge($stored, ($live['balances'] ?? []));

    $card = bt_card_row($userId);

    return [
        'message' => 'BALANCE_OK',
        'address' => (string)($user['wallet_address'] ?? ''),
        'sync' => [
            'address' => (string)($user['wallet_address'] ?? ''),
            'synced_at' => gmdate('c'),
            'live_ok' => true,
            'source' => 'bal-tester-live-shared',
        ],
        'card' => [
            'number' => (string)($card['card_number'] ?? ''),
            'card_number' => (string)($card['card_number'] ?? ''),
            'status' => (string)($card['status'] ?? 'none'),
            'locked' => (int)($card['locked'] ?? 0),
            'active' => (bool)($card['is_active'] ?? false),
            'is_active' => (bool)($card['is_active'] ?? false),
            'balance_rwa' => (string)($balances['card_balance_rwa'] ?? '0.000000'),
        ],
        'tokens' => [
            'EMA' => [
                'on_chain' => (string)($balances['onchain_ema'] ?? '0.000000'),
                'available' => (string)($balances['unclaim_ema'] ?? '0.000000'),
            ],
            'EMX' => [
                'on_chain' => (string)($balances['onchain_emx'] ?? '0.000000'),
                'available' => (string)($balances['unclaim_tips_emx'] ?? '0.000000'),
            ],
            'EMS' => [
                'on_chain' => (string)($balances['fuel_ems'] ?? '0.000000'),
                'available' => '0.000000',
            ],
            'WEMS' => [
                'on_chain' => (string)($balances['onchain_wems'] ?? '0.000000'),
                'available' => (string)($balances['unclaim_wems'] ?? '0.000000'),
            ],
            'USDT_TON' => [
                'on_chain' => (string)($balances['fuel_usdt_ton'] ?? '0.000000'),
                'available' => (string)($balances['unclaim_gold_packet_usdt'] ?? '0.000000'),
            ],
            'TON' => [
                'on_chain' => (string)($balances['fuel_ton_gas'] ?? '0.000000'),
                'available' => '0.000000',
            ],
        ],
        '_debug' => ($live['debug'] ?? []),
    ];
}

function bt_overview_payload(array $user, array $live): array
{
    $userId = (int)$user['id'];
    $stored = bt_balance_row($userId);
    $balances = array_merge($stored, ($live['balances'] ?? []));

    return [
        'user' => [
            'id' => $userId,
            'wallet' => (string)($user['wallet'] ?? ''),
            'wallet_address' => (string)($user['wallet_address'] ?? ''),
            'nickname' => (string)($user['nickname'] ?? ''),
            'email' => (string)($user['email'] ?? ''),
            'email_verified_at' => (string)($user['email_verified_at'] ?? ''),
        ],
        'wallet_address' => (string)($user['wallet_address'] ?? ''),
        'address' => (string)($user['wallet_address'] ?? ''),
        'card' => bt_card_row($userId),
        'balances' => $balances,
        'activation' => bt_activation_row($userId),
        'sync' => [
            'address' => (string)($user['wallet_address'] ?? ''),
            'synced_at' => gmdate('c'),
            'live_ok' => true,
            'source' => 'bal-tester-live-shared',
        ],
        '_debug' => ($live['debug'] ?? []),
    ];
}

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$walletAddress = trim((string)($_GET['wallet_address'] ?? ''));

$user = null;
$error = '';
$left = null;
$right = null;

if ($userId > 0 || $walletAddress !== '') {
    try {
        $user = bt_user_find($userId > 0 ? $userId : null, $walletAddress);
        if (!$user) {
            $error = 'User not found.';
        } elseif (trim((string)($user['wallet_address'] ?? '')) === '') {
            $error = 'User found, but wallet_address is empty.';
        } else {
            $live = bt_live_balances((string)$user['wallet_address']);
            $left = bt_balance_payload($user, $live);
            $right = bt_overview_payload($user, $live);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$compareRows = [];
if (is_array($left) && is_array($right)) {
    $compareRows = [
        ['EMX', $left['tokens']['EMX']['on_chain'] ?? '0.000000', $right['balances']['onchain_emx'] ?? '0.000000'],
        ['EMA', $left['tokens']['EMA']['on_chain'] ?? '0.000000', $right['balances']['onchain_ema'] ?? '0.000000'],
        ['EMS', $left['tokens']['EMS']['on_chain'] ?? '0.000000', $right['balances']['fuel_ems'] ?? '0.000000'],
        ['WEMS', $left['tokens']['WEMS']['on_chain'] ?? '0.000000', $right['balances']['onchain_wems'] ?? '0.000000'],
        ['USDT_TON', $left['tokens']['USDT_TON']['on_chain'] ?? '0.000000', $right['balances']['fuel_usdt_ton'] ?? '0.000000'],
        ['TON', $left['tokens']['TON']['on_chain'] ?? '0.000000', $right['balances']['fuel_ton_gas'] ?? '0.000000'],
        ['UNCLAIM_EMA', $left['tokens']['EMA']['available'] ?? '0.000000', $right['balances']['unclaim_ema'] ?? '0.000000'],
        ['UNCLAIM_WEMS', $left['tokens']['WEMS']['available'] ?? '0.000000', $right['balances']['unclaim_wems'] ?? '0.000000'],
        ['UNCLAIM_PACKET', $left['tokens']['USDT_TON']['available'] ?? '0.000000', $right['balances']['unclaim_gold_packet_usdt'] ?? '0.000000'],
        ['UNCLAIM_TIPS', $left['tokens']['EMX']['available'] ?? '0.000000', $right['balances']['unclaim_tips_emx'] ?? '0.000000'],
        ['CARD_RWA', $left['card']['balance_rwa'] ?? '0.000000', $right['balances']['card_balance_rwa'] ?? '0.000000'],
    ];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>bal-tester</title>
<style>
:root{
  --bg:#07110d;
  --panel:#0d1b15;
  --line:#1c3f31;
  --text:#dfffe8;
  --muted:#8fb7a0;
  --good:#5bff3c;
  --warn:#ffd166;
  --bad:#ff6b6b;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font:14px/1.45 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
.wrap{max-width:1600px;margin:0 auto;padding:16px}
h1,h2,h3{margin:0 0 10px}
.small{color:var(--muted);font-size:12px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media (max-width: 960px){.grid{grid-template-columns:1fr}}
.card{background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:16px;box-shadow:0 0 0 1px rgba(91,255,60,.05) inset}
.form{display:grid;grid-template-columns:160px 1fr 120px 1fr auto;gap:10px;align-items:center}
@media (max-width: 960px){.form{grid-template-columns:1fr}}
label{color:var(--muted)}
input{
  width:100%;background:#08140f;color:var(--text);border:1px solid var(--line);
  border-radius:10px;padding:10px 12px;outline:none
}
button{
  background:#103221;color:var(--good);border:1px solid #246f42;border-radius:10px;
  padding:10px 14px;cursor:pointer;font-weight:700
}
button:hover{filter:brightness(1.08)}
.err{color:var(--bad);font-weight:700}
.ok{color:var(--good);font-weight:700}
.warn{color:var(--warn);font-weight:700}
pre{
  margin:0;white-space:pre-wrap;word-break:break-word;
  background:#07110d;border:1px solid var(--line);border-radius:12px;
  padding:12px;overflow:auto;max-height:720px
}
table{width:100%;border-collapse:collapse}
th,td{border-bottom:1px solid var(--line);padding:10px 8px;text-align:left;vertical-align:top}
th{color:var(--muted);font-weight:700}
.diff-same{color:var(--good);font-weight:700}
.diff-bad{color:var(--bad);font-weight:700}
.kv{display:grid;grid-template-columns:180px 1fr;gap:8px 12px}
.hr{height:1px;background:var(--line);margin:14px 0}
</style>
</head>
<body>
<div class="wrap">
  <div class="card" style="margin-bottom:16px">
    <h1>bal-tester</h1>
    <div class="small">Storage standalone compare tester · left = balance style · right = overview style</div>
    <div class="hr"></div>

    <form method="get" class="form">
      <label for="user_id">user_id</label>
      <input id="user_id" name="user_id" value="<?= bt_h((string)$userId) ?>" placeholder="example: 123">

      <label for="wallet_address">wallet_address</label>
      <input id="wallet_address" name="wallet_address" value="<?= bt_h($walletAddress) ?>" placeholder="example: UQ...">

      <button type="submit">Run Compare</button>
    </form>

    <?php if ($error !== ''): ?>
      <div style="margin-top:12px" class="err"><?= bt_h($error) ?></div>
    <?php endif; ?>

    <?php if ($user): ?>
      <div class="hr"></div>
      <div class="kv">
        <div class="small">User ID</div><div><?= bt_h((string)$user['id']) ?></div>
        <div class="small">Nickname</div><div><?= bt_h((string)$user['nickname']) ?></div>
        <div class="small">Wallet</div><div><?= bt_h((string)$user['wallet']) ?></div>
        <div class="small">Wallet Address</div><div><?= bt_h((string)$user['wallet_address']) ?></div>
        <div class="small">Email</div><div><?= bt_h((string)$user['email']) ?></div>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($left && $right): ?>
    <div class="card" style="margin-bottom:16px">
      <h2>Quick Compare</h2>
      <table>
        <thead>
          <tr>
            <th>Field</th>
            <th>Left: balance style</th>
            <th>Right: overview style</th>
            <th>Match</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($compareRows as $row):
          $same = ((string)$row[1] === (string)$row[2]);
        ?>
          <tr>
            <td><?= bt_h($row[0]) ?></td>
            <td><?= bt_h((string)$row[1]) ?></td>
            <td><?= bt_h((string)$row[2]) ?></td>
            <td class="<?= $same ? 'diff-same' : 'diff-bad' ?>"><?= $same ? 'SAME' : 'DIFF' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="grid">
      <div class="card">
        <h2>Left — balance.php style</h2>
        <div class="small">Reconstructed balance response</div>
        <div class="hr"></div>
        <pre><?= bt_h(json_encode($left, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
      </div>

      <div class="card">
        <h2>Right — overview.php style</h2>
        <div class="small">Reconstructed overview response</div>
        <div class="hr"></div>
        <pre><?= bt_h(json_encode($right, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
      </div>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
